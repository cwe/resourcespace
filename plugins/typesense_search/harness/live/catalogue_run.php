<?php
// Send the catalogue (live/catalogue.php) through the ResourceSpace API and record the totals, as the core user and,
// when RS_USER_TS is set, as the plugin user too. Results go to results/live/catalogue.json (merged with earlier
// runs, so partial runs are fine) and ../catalogue_table.php puts them in the markdown table.
//
// Usage: RS_BASE_URL=… RS_USER_CORE=… RS_KEY_CORE=… [RS_USER_TS=… RS_KEY_TS=…] php live/catalogue_run.php [options] [id prefix …]
//   --slow        also run the searches matching the catalogue's slow_pattern (6 to 8 s each in core here)
//   --core-only   ignore RS_USER_TS
//   --dry         print what would be sent and stop
//   A1 F B12 …    only these cases, or whole groups given by their letter
// Optional: RS_PAUSE (seconds between API calls, default 1; a case that took longer than 5 s doubles the pause after it),
// RS_INSECURE=1, RS_SUB_USERREF_CORE / RS_SUB_USERREF_TS / RS_SUB_OWNCOLLECTION_CORE / RS_SUB_OWNCOLLECTION_TS for
// the {userref} and {owncollection} placeholders.
require __DIR__ . '/client.php';

$catalogue = require __DIR__ . '/catalogue.php';
$slow_pattern = $catalogue['slow_pattern'] ?? '';
$options = array_filter(array_slice($argv, 1), fn($a) => substr($a, 0, 2) === '--');
$prefixes = array_values(array_filter(array_slice($argv, 1), fn($a) => substr($a, 0, 2) !== '--'));
$run_slow = in_array('--slow', $options, true);
$dry = in_array('--dry', $options, true);
$users = array('core');
if (!in_array('--core-only', $options, true) && (string)getenv('RS_USER_TS') !== '') {
    $users[] = 'ts';
}
$pause = (float)(getenv('RS_PAUSE') !== false ? getenv('RS_PAUSE') : 1);
$results_file = dirname(__DIR__) . '/results/live/catalogue.json';
$results = file_exists($results_file) ? (json_decode((string)file_get_contents($results_file), true) ?: array()) : array();

/** Replace {userref} and {owncollection} for a user from the environment; null if a value is missing. */
function substitute(string $search, string $who): ?string
{
    return preg_replace_callback('/\{([a-z]+)\}/', function ($m) use ($who, &$missing) {
        $value = getenv('RS_SUB_' . strtoupper($m[1]) . '_' . ($who === 'ts' ? 'TS' : 'CORE'));
        if ($value === false || $value === '') {
            $missing = true;
            return $m[0];
        }
        return $value;
    }, $search);
}

/** Total, first refs and timing from a do_search / search_get_previews response. */
function outcome(array $response, bool $structured): array
{
    list($status, $data, $ms) = $response;
    if ($status !== 200 || !is_array($data)) {
        return array('total' => null, 'rows' => null, 'refs' => array(), 'ms' => $ms, 'error' => 'HTTP ' . $status . ' ' . (is_string($data) ? $data : json_encode($data)));
    }
    $rows = $structured ? ($data['data'] ?? array()) : $data;
    $refs = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['ref'])) {
            $refs[] = (int)$row['ref'];
        }
    }
    return array('total' => $structured ? (int)($data['total'] ?? 0) : null, 'rows' => count($refs), 'refs' => $refs, 'ms' => $ms, 'error' => null);
}

$sent = 0;
$skipped_slow = 0;
foreach ($catalogue as $case) {
    if (!is_array($case) || !isset($case['id'])) {
        continue;
    }
    if (count($prefixes) > 0 && !array_filter($prefixes, fn($p) => $case["id"] === $p || (strlen($p) === 1 && $case["id"][0] === $p))) {
        continue;
    }
    if (($case['api'] ?? true) === false) {
        continue;
    }
    if (!$run_slow && $slow_pattern !== '' && preg_match($slow_pattern, $case['search'])) {
        $skipped_slow++;
        continue;
    }

    $structured = !isset($case['fetchrows']);
    $params = array(
        'restypes' => $case['restypes'] ?? '',
        'order_by' => $case['order_by'] ?? 'relevance',
        'archive' => $case['archive'] ?? '0',
        'fetchrows' => $structured ? '0,' . (int)($case['rows'] ?? 1) : (string)$case['fetchrows'],
        'sort' => $case['sort'] ?? 'desc',
    );
    if (isset($case['offset'])) {
        $params['offset'] = (int)$case['offset'];
    }
    $function = 'do_search';
    if (isset($case['daylimit'])) {
        $function = 'search_get_previews';
        unset($params['offset']);
        $params['recent_search_daylimit'] = (string)$case['daylimit'];
    }

    $record = array('search' => $case['search'], 'params' => $params, 'function' => $function, 'at' => date('c'));
    $line = $case['id'] . '  ' . json_encode($case['search'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $extras = array_diff_assoc($params, array('restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => '0,1', 'sort' => 'desc'));
    if (count($extras) > 0) {
        $line .= '  [' . urldecode(http_build_query($extras, '', ', ')) . ']';
    }
    echo $line . "\n";
    $slowest = 0;
    foreach ($users as $who) {
        $missing = false;
        $search = substitute($case['search'], $who);
        if ($missing) {
            echo '    ' . $who . ': skipped (set RS_SUB_… for the placeholder)' . "\n";
            continue;
        }
        if ($dry) {
            echo '    ' . $who . ': would send ' . $function . ' ' . json_encode(array('search' => $search) + $params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            continue;
        }
        $o = outcome(rs_api($who, $function, array('search' => $search) + $params), $structured);
        $o['search'] = $search;
        $record[$who === 'ts' ? 'plugin' : 'core'] = $o;
        $slowest = max($slowest, $o['ms']);
        echo '    ' . str_pad($who === 'ts' ? 'plugin' : 'core', 7) . ($o['error'] !== null ? $o['error']
            : ($o['total'] !== null ? 'total=' . $o['total'] : 'rows=' . $o['rows']) . (count($o['refs']) > 1 || !$structured ? ' first=' . json_encode($o['refs']) : ''))
            . '  (' . $o['ms'] . " ms)\n";
        usleep((int)($pause * 1000000));
    }
    if ($dry) {
        continue;
    }
    if (isset($record['core'], $record['plugin'])) {
        $same = $record['core']['error'] === null && $record['plugin']['error'] === null
            && $record['core']['total'] === $record['plugin']['total'] && $record['core']['rows'] === $record['plugin']['rows'];
        $record['same'] = $same && (count($record['core']['refs']) <= 1 || $record['core']['refs'] === $record['plugin']['refs']);
        echo '    => ' . ($record['same'] ? 'SAME' : ($same ? 'same total, different first rows' : 'DIFFERENT')) . "\n";
    }
    $results[$case['id']] = $record;
    $sent++;
    // Write after every case so an interrupted run keeps what it measured.
    if (!is_dir(dirname($results_file))) {
        mkdir(dirname($results_file), 0777, true);
    }
    file_put_contents($results_file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    usleep((int)(($slowest > 5000 ? $pause * 2 : $pause) * 1000000));
}
echo "\n" . $sent . ' case' . ($sent === 1 ? '' : 's') . ' sent' . ($skipped_slow > 0 ? ', ' . $skipped_slow . ' slow date searches skipped (add --slow)' : '') . "\n";
