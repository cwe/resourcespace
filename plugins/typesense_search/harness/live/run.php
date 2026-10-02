<?php
// Live A/B: send each search in cases.php through the ResourceSpace API as two users, one whose group has the
// typesense_search plugin ("plugin") and one whose group does not ("core"), and compare the totals.
//
// Usage: RS_BASE_URL=… RS_USER_TS=… RS_KEY_TS=… RS_USER_CORE=… RS_KEY_CORE=… php live/run.php [case label prefix]
// Optional: RS_PAUSE (seconds between searches, default 2), RS_INSECURE=1 (private TLS certificate).
//
// Kept light on purpose: one search at a time, a pause after every core search, and only the total plus the
// first rows are requested. If the plugin's group is in Typesense-only mode, a search the plugin declines
// comes back empty instead of falling back.
require __DIR__ . '/client.php';

$only = $argv[1] ?? '';
$pause = (float)(getenv('RS_PAUSE') !== false ? getenv('RS_PAUSE') : 2);

/** Total and first refs of a do_search API response, or a description of what came back instead. */
function live_result(array $response): array
{
    list($status, $data, $ms) = $response;
    if ($status !== 200 || !is_array($data)) {
        return array('text' => 'HTTP ' . $status . ' ' . (is_string($data) ? substr($data, 0, 120) : ''), 'total' => null, 'refs' => array(), 'ms' => $ms);
    }
    $rows = $data['data'] ?? $data;
    $refs = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['ref'])) {
            $refs[] = (int)$row['ref'];
        }
    }
    $total = $data['total'] ?? count($rows);

    return array('text' => 'total=' . $total, 'total' => (int)$total, 'refs' => $refs, 'ms' => $ms);
}

foreach (require __DIR__ . '/cases.php' as $case) {
    if (isset($case['heading'])) {
        $heading = $case['heading'];
        continue;
    }
    if ($only !== '' && strpos($case['label'], $only) !== 0) {
        continue;
    }
    if (isset($heading)) {
        echo "\n---- " . $heading . "\n";
        unset($heading);
    }

    $rows = (int)($case['rows'] ?? 1);
    $params = array(
        'search' => $case['search'],
        'restypes' => $case['restypes'] ?? '',
        'order_by' => $case['order_by'] ?? 'relevance',
        'archive' => $case['archive'] ?? '0',
        'fetchrows' => '0,' . $rows,
        'sort' => $case['sort'] ?? 'desc',
    );

    $plugin = live_result(rs_api('ts', 'do_search', $params));
    usleep(300000);
    $core = live_result(rs_api('core', 'do_search', $params));

    $same = $core['total'] !== null && $core['total'] === $plugin['total'];
    $verdict = $core['total'] === null || $plugin['total'] === null ? 'ERROR'
        : (!$same ? 'DIFFERENT'
            : ($rows > 1 && $core['refs'] !== $plugin['refs'] ? 'same total, different first rows' : 'SAME'));

    // 'show' replaces a search string that contains a value taken from the data.
    echo "\n" . $case['label'] . ':  ' . json_encode($case['show'] ?? $case['search'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . (isset($case['order_by']) ? '  [order_by=' . $case['order_by'] . ']' : '') . "\n";
    echo '    core:   ' . $core['text'] . ($rows > 1 ? ' first=' . json_encode($core['refs']) : '') . '  (' . $core['ms'] . " ms)\n";
    echo '    plugin: ' . $plugin['text'] . ($rows > 1 ? ' first=' . json_encode($plugin['refs']) : '') . '  (' . $plugin['ms'] . " ms)\n";
    echo '    => ' . $verdict . (isset($case['note']) ? '   (' . $case['note'] . ')' : '') . "\n";

    usleep((int)($pause * 1000000));
}
