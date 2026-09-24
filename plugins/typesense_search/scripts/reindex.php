<?php

/**
 * CLI script to reindex existing resources into Typesense.
 *
 * Usage: php reindex.php [batch size] [start after resource ref]
 *
 * Runs four passes: resources, collection memberships, resource attributes and access grants. Each
 * pass reports its progress, with an estimate of the time left, and every document Typesense
 * rejects, grouped by the reason Typesense gave. The run ends with a summary of each pass and a
 * comparison of the documents in each Typesense collection with the MySQL rows they come from.
 *
 * Exit status: 0 if every document was indexed, 1 if the Typesense collections couldn't be set up,
 * 2 if the reindex finished but some documents failed.
 */

include_once dirname(__DIR__, 3) . '/include/boot.php';
include_once dirname(__DIR__) . '/include/typesense_search_functions.php';

command_line_only();
set_time_limit(0);

$batch_size = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : 10000;
$after = isset($argv[2]) && is_numeric($argv[2]) ? (int)$argv[2] : 0;

// Seconds between progress lines within a pass. A batch with failures is always reported.
$progress_interval = 5;

// The most distinct failure reasons kept per pass; any further reasons are counted together.
$max_reasons = 50;


/**
 * Print a line straight away, so progress shows while a pass runs.
 */
function typesense_reindex_output(string $line = ''): void
{
    echo $line . PHP_EOL;
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}


/**
 * Format a duration, e.g. "42.1s", "3m 05s" or "1h 02m".
 */
function typesense_reindex_duration(float $seconds): string
{
    if ($seconds < 60) {
        return number_format($seconds, 1) . 's';
    }

    $seconds = (int) round($seconds);
    if ($seconds < 3600) {
        return sprintf('%dm %02ds', intdiv($seconds, 60), $seconds % 60);
    }

    return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}


/**
 * Print a progress line for a pass, plus the first failure reason if the latest batch had failures.
 *
 * @param array $pass    The pass definition (see $passes below).
 * @param array $totals  Running totals for the pass.
 * @param float $elapsed Seconds since the pass started.
 * @param array $cursor  The pass's position.
 * @param array $result  Summary of the latest batch.
 */
function typesense_reindex_progress(array $pass, array $totals, float $elapsed, array $cursor, array $result): void
{
    $processed = $totals['processed'];
    $expected = $pass['expected'];
    $rate = $elapsed > 0 ? $processed / $elapsed : 0;

    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_pad($pass['name'], 12)
        . number_format($processed) . ' / ' . number_format($expected);
    if ($expected > 0) {
        $line .= ' (' . number_format(min(100, 100 * $processed / $expected), 1) . '%)';
    }
    $line .= ' | indexed ' . number_format($totals['indexed']) . ', failed ' . number_format($totals['failed'])
        . ' | ' . number_format($rate) . ' ' . $pass['unit'] . '/s';
    if (!$result['complete'] && $rate > 0 && $expected > $processed) {
        $line .= ', ~' . typesense_reindex_duration(($expected - $processed) / $rate) . ' left';
    }
    $line .= ' | ' . $pass['position']($cursor)
        . ' | ' . round(memory_get_usage(true) / 1048576) . ' MB';

    typesense_reindex_output($line);

    if ($result['failed'] > 0) {
        $reason = array_key_first($result['errors'] ?? array());
        typesense_reindex_output(
            '    ' . number_format($result['failed']) . ' failed in this batch'
            . ($reason !== null ? ', e.g. ' . $reason : '')
        );
    }
}


/**
 * Run one pass to completion, reporting progress, and return its totals.
 *
 * @param array $pass              The pass definition (see $passes below).
 * @param int   $progress_interval Seconds between progress lines.
 * @param int   $max_reasons       Most distinct failure reasons to keep.
 *
 * @return array processed, indexed, failed, errors (reason => number of documents) and time.
 */
function typesense_reindex_pass(array $pass, int $progress_interval, int $max_reasons): array
{
    typesense_reindex_output();
    typesense_reindex_output($pass['name'] . ': ' . number_format($pass['expected']) . ' ' . $pass['unit'] . ' to index');

    $totals = array('processed' => 0, 'indexed' => 0, 'failed' => 0, 'errors' => array(), 'time' => 0.0);
    $cursor = $pass['cursor'];
    $start = microtime(true);
    $last_report = $start;
    $last_reported = -1; // processed count shown in the last progress line

    do {
        $result = $pass['run']($cursor);

        $totals['processed'] += $result['processed'] ?? ($result['indexed'] + $result['failed']);
        $totals['indexed'] += $result['indexed'];
        $totals['failed'] += $result['failed'];
        foreach ($result['errors'] ?? array() as $reason => $count) {
            if (!isset($totals['errors'][$reason]) && count($totals['errors']) >= $max_reasons) {
                $reason = 'Other reasons';
            }
            $totals['errors'][$reason] = ($totals['errors'][$reason] ?? 0) + $count;
        }

        // Report any failures at once; otherwise report progress every $progress_interval seconds and
        // at the end, skipping a line that would repeat the last one (e.g. a final, empty batch).
        $now = microtime(true);
        $progressed = $totals['processed'] !== $last_reported;
        if (
            $result['failed'] > 0
            || ($progressed && ($result['complete'] || $now - $last_report >= $progress_interval))
        ) {
            typesense_reindex_progress($pass, $totals, $now - $start, $cursor, $result);
            $last_report = $now;
            $last_reported = $totals['processed'];
        }
    } while (!$result['complete']);

    $totals['time'] = microtime(true) - $start;
    $rate = $totals['time'] > 0 ? $totals['processed'] / $totals['time'] : 0;

    typesense_reindex_output(
        $pass['name'] . ' done in ' . typesense_reindex_duration($totals['time'])
        . ': ' . number_format($totals['processed']) . ' ' . $pass['unit'] . ' processed, '
        . number_format($totals['indexed']) . ' documents indexed, ' . number_format($totals['failed']) . ' failed'
        . ' (' . number_format($rate) . ' ' . $pass['unit'] . '/s)'
    );

    if ($totals['failed'] > 0) {
        arsort($totals['errors']);
        typesense_reindex_output('  Failures by reason:');
        foreach ($totals['errors'] as $reason => $count) {
            typesense_reindex_output('    ' . str_pad(number_format($count), 10, ' ', STR_PAD_LEFT) . '  ' . $reason);
        }
    }

    return $totals;
}


$overall_start = microtime(true);

if (!typesense_search_ensure_collection()) {
    typesense_reindex_output('Failed to ensure Typesense collection exists.');
    exit(1);
}

// Sync the related keywords.
typesense_search_sync_related_keywords();

typesense_reindex_output('Starting Typesense reindex | Batch size: ' . $batch_size . ' | Starting after ref: ' . $after);

// Each pass: 'expected' is the number of MySQL rows it will read (for progress), 'run' indexes the
// next batch from the cursor and moves the cursor on, and 'position' describes the cursor.
$passes = array(
    array(
        'name' => 'Resources',
        'unit' => 'resources',
        'expected' => (int) ps_value('SELECT COUNT(*) value FROM resource WHERE ref > ?', array('i', $after), 0),
        'cursor' => array('after' => $after),
        'run' => function (array &$cursor) use ($batch_size): array {
            $result = typesense_search_reindex_resources($batch_size, $cursor['after']);
            $cursor['after'] = (int) $result['last'];
            return $result;
        },
        'position' => function (array $cursor): string {
            return 'last ref ' . $cursor['after'];
        },
    ),
    array(
        'name' => 'Memberships',
        'unit' => 'memberships',
        'expected' => (int) ps_value('SELECT COUNT(*) value FROM collection_resource cr INNER JOIN collection c ON cr.collection = c.ref', array(), 0),
        'cursor' => array('collection' => 0, 'resource' => 0),
        'run' => function (array &$cursor) use ($batch_size): array {
            $result = typesense_search_reindex_resource_collection_memberships($batch_size, $cursor['collection'], $cursor['resource']);
            $cursor['collection'] = (int) $result['last_collection'];
            $cursor['resource'] = (int) $result['last_resource'];
            return $result;
        },
        'position' => function (array $cursor): string {
            return 'last collection ' . $cursor['collection'] . ', resource ' . $cursor['resource'];
        },
    ),
    array(
        'name' => 'Attributes',
        'unit' => 'resources',
        'expected' => (int) ps_value('SELECT COUNT(*) value FROM resource WHERE ref > 0', array(), 0),
        'cursor' => array('after' => 0),
        'run' => function (array &$cursor): array {
            // 500 resources per SQL batch; the import auto-chunks to keep each POST bounded.
            $result = typesense_search_reindex_resource_attributes(500, $cursor['after']);
            $cursor['after'] = (int) $result['last'];
            return $result;
        },
        'position' => function (array $cursor): string {
            return 'last ref ' . $cursor['after'];
        },
    ),
    array(
        'name' => 'Grants',
        'unit' => 'grants',
        'expected' => (int) ps_value('SELECT COUNT(*) value FROM resource_custom_access WHERE access <> 2 AND resource > 0', array(), 0),
        'cursor' => array('after' => 0),
        'run' => function (array &$cursor): array {
            // All the grants of up to 1,000 resources per batch.
            $result = typesense_search_reindex_grants(1000, $cursor['after']);
            $cursor['after'] = (int) $result['last'];
            return $result;
        },
        'position' => function (array $cursor): string {
            return 'last resource ' . $cursor['after'];
        },
    ),
);

$results = array();
foreach ($passes as $pass) {
    $results[$pass['name']] = typesense_reindex_pass($pass, $progress_interval, $max_reasons);
}

typesense_reindex_output();
typesense_reindex_output('Summary');
typesense_reindex_output(sprintf('  %-12s %12s %12s %10s %10s %16s', 'Pass', 'Processed', 'Indexed', 'Failed', 'Time', 'Rate'));
foreach ($passes as $pass) {
    $totals = $results[$pass['name']];
    $rate = $totals['time'] > 0 ? $totals['processed'] / $totals['time'] : 0;
    typesense_reindex_output(sprintf(
        '  %-12s %12s %12s %10s %10s %16s',
        $pass['name'],
        number_format($totals['processed']),
        number_format($totals['indexed']),
        number_format($totals['failed']),
        typesense_reindex_duration($totals['time']),
        number_format($rate) . '/s'
    ));
}

// Compare what each Typesense collection now holds with the rows it is built from. The reindex adds
// and updates documents but never deletes them, so a document whose row has gone (a deleted
// resource, a removed collection member, a revoked grant) is still there. The MySQL counts are
// distinct document ids, and can drift if the system was in use during the reindex.
typesense_reindex_output();
typesense_reindex_output('Typesense documents compared with MySQL rows');
$comparisons = array(
    'resources' => 'SELECT COUNT(*) value FROM resource WHERE ref > 0',
    'resource_collection_memberships' => 'SELECT COUNT(DISTINCT cr.collection, cr.resource) value FROM collection_resource cr INNER JOIN collection c ON cr.collection = c.ref',
    'resource_access_grants' => "SELECT COUNT(DISTINCT resource, IF(IFNULL(user, 0) > 0, CONCAT('u', user), CONCAT('g', IFNULL(usergroup, 0)))) value FROM resource_custom_access WHERE access <> 2 AND resource > 0",
);
foreach ($comparisons as $suffix => $sql) {
    $collection = $typesense_search_collection_prefix . $suffix;
    $info = typesense_search_request('GET', '/collections/' . rawurlencode($collection));
    $line = '  ' . str_pad($collection, 40);

    if (!is_array($info) || !isset($info['num_documents'])) {
        typesense_reindex_output($line . 'could not read the collection');
        continue;
    }

    $in_typesense = (int) $info['num_documents'];
    $in_mysql = (int) ps_value($sql, array(), 0);
    $line .= str_pad(number_format($in_typesense), 12, ' ', STR_PAD_LEFT) . ' documents, MySQL ' . number_format($in_mysql);

    $difference = $in_typesense - $in_mysql;
    if ($difference < 0) {
        $line .= ' | ' . number_format(-$difference) . ' fewer than MySQL, e.g. failed imports';
    } elseif ($difference > 0) {
        $line .= ' | ' . number_format($difference) . ' more than MySQL, e.g. stale documents (the reindex never deletes)';
    }
    typesense_reindex_output($line);
}

$total_failed = array_sum(array_column($results, 'failed'));
typesense_reindex_output();
typesense_reindex_output(
    'Reindex complete in ' . typesense_reindex_duration(microtime(true) - $overall_start)
    . ' | ' . ($total_failed === 0 ? 'No documents failed' : number_format($total_failed) . ' documents failed - see the reasons above')
    . ' | Peak memory: ' . round(memory_get_peak_usage(true) / 1048576) . ' MB'
);

exit($total_failed === 0 ? 0 : 2);
