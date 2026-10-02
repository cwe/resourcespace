<?php
// Read-only survey of a ResourceSpace database for the things that decide how much each search divergence
// matters on that system: which fields are indexed, where long / HTML / translated values are, how the
// plugin is configured. Only SELECT statements; one of them scans the node table.
//
// Usage: RS_DB_HOST=… RS_DB_PORT=… RS_DB_USER=… RS_DB_PASS=… RS_DB_NAME=… php live/db_survey.php [--fields]
// --fields also lists every metadata field (names are that system's own vocabulary).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$env = array();
foreach (array('HOST', 'PORT', 'USER', 'PASS', 'NAME') as $part) {
    $env[$part] = getenv('RS_DB_' . $part);
    if ($env[$part] === false || $env[$part] === '') {
        fwrite(STDERR, "Set RS_DB_HOST, RS_DB_PORT, RS_DB_USER, RS_DB_PASS and RS_DB_NAME in the environment.\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
if (!@$db->real_connect($env['HOST'], $env['USER'], $env['PASS'], $env['NAME'], (int)$env['PORT'])) {
    fwrite(STDERR, 'Could not connect: ' . mysqli_connect_error() . "\n");
    exit(1);
}
$db->set_charset('utf8mb4');
// Stop any statement that runs long (MySQL and MariaDB spell this differently; one of them will take).
@$db->query('SET SESSION MAX_EXECUTION_TIME = 30000');
@$db->query('SET SESSION max_statement_time = 30');

function rows(mysqli $db, string $sql): array
{
    $started = microtime(true);
    $result = $db->query($sql);
    if ($result === false) {
        echo '    (query failed: ' . $db->error . ")\n";
        return array();
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $GLOBALS['last_ms'] = (int)round((microtime(true) - $started) * 1000);
    return $rows;
}

$TYPE = array(0 => 'text', 1 => 'text (multi-line)', 2 => 'checkbox list', 3 => 'dropdown', 4 => 'date and time', 5 => 'text (large)',
    6 => 'expiry date', 7 => 'category tree', 8 => 'formatted text', 9 => 'dynamic keywords', 10 => 'date', 12 => 'radio buttons',
    13 => 'warning message', 14 => 'date range');

echo 'server: ' . $db->server_info . "\n";

echo "\n== resources by workflow state\n";
foreach (rows($db, 'SELECT archive, COUNT(*) c FROM resource WHERE ref > 0 GROUP BY archive ORDER BY archive') as $r) {
    echo sprintf("    state %-3s %s\n", $r['archive'], number_format((int)$r['c']));
}

$fields = rows($db, 'SELECT ref, name, type, keywords_index, partial_index, complete_index, advanced_search, simple_search, active, field_constraint, display_as_dropdown FROM resource_type_field ORDER BY ref');
echo "\n== metadata fields: " . count($fields) . "\n";
$by_type = array();
foreach ($fields as $f) {
    $by_type[$f['type']] = ($by_type[$f['type']] ?? 0) + 1;
}
ksort($by_type);
foreach ($by_type as $type => $count) {
    echo sprintf("    %-20s %d\n", $TYPE[$type] ?? ('type ' . $type), $count);
}
$count = function (callable $test) use ($fields): int {
    return count(array_filter($fields, $test));
};
echo '    inactive: ' . $count(fn($f) => (int)$f['active'] === 0)
    . ', on the simple search bar: ' . $count(fn($f) => (int)$f['simple_search'] === 1 && (int)$f['active'] === 1)
    . ', partially indexed: ' . $count(fn($f) => (int)$f['partial_index'] === 1)
    . ', not flagged for indexing: ' . $count(fn($f) => (int)$f['keywords_index'] === 0 && (int)$f['active'] === 1) . "\n";

echo "\n== date and numeric fields that are not flagged for indexing (review E3)\n";
$hits = array_filter($fields, fn($f) => (int)$f['keywords_index'] === 0 && (in_array((int)$f['type'], array(4, 6, 10, 14), true) || (int)$f['field_constraint'] === 1));
foreach ($hits as $f) {
    echo sprintf("    %-5s %-26s %-14s advanced search: %s  simple search: %s  active: %s\n", $f['ref'], $f['name'], $TYPE[$f['type']] ?? $f['type'],
        (int)$f['advanced_search'] ? 'yes' : 'no', (int)$f['simple_search'] ? 'yes' : 'no', (int)$f['active'] ? 'yes' : 'no');
}
echo count($hits) === 0 ? "    none\n" : '';

echo "\n== fixed-list fields on the simple search bar shown as text (dynamic keywords) or tree\n";
foreach (array_filter($fields, fn($f) => (int)$f['simple_search'] === 1 && (int)$f['active'] === 1) as $f) {
    echo sprintf("    %-5s %-26s %s\n", $f['ref'], $f['name'], $TYPE[$f['type']] ?? $f['type']);
}

if (in_array('--fields', $argv, true)) {
    echo "\n== every field\n";
    foreach ($fields as $f) {
        echo sprintf("    %-5s %-28s %-18s index=%s partial=%s advanced=%s simple=%s active=%s numeric=%s\n", $f['ref'], $f['name'], $TYPE[$f['type']] ?? $f['type'],
            $f['keywords_index'], $f['partial_index'], $f['advanced_search'], $f['simple_search'], $f['active'], (int)$f['field_constraint']);
    }
}

echo "\n== values per field that the two engines index differently (one scan of the node table)\n";
$field_by_ref = array_column($fields, null, 'ref');
$scan = rows($db, "SELECT resource_type_field f, COUNT(*) total,
        SUM(name LIKE '~__:%') translated,
        SUM(CHAR_LENGTH(name) > 500) long_values,
        SUM(name LIKE '<%>') html_like
    FROM node GROUP BY resource_type_field");
echo '    (' . $GLOBALS['last_ms'] . " ms)\n";
$totals = array('total' => 0, 'translated' => 0, 'long_values' => 0, 'html_like' => 0);
$long_indexed = 0;
$html_indexed = 0;
$translated_fields = array();
foreach ($scan as $r) {
    foreach ($totals as $k => $v) {
        $totals[$k] += (int)$r[$k];
    }
    $f = $field_by_ref[$r['f']] ?? null;
    $indexed = $f !== null && (int)$f['keywords_index'] === 1 && (int)$f['active'] === 1;
    if ($indexed) {
        $long_indexed += (int)$r['long_values'];
        $html_indexed += (int)$r['html_like'];
    }
    if ((int)$r['translated'] > 0) {
        $translated_fields[] = ($TYPE[$f['type'] ?? -1] ?? '?') . ' field with ' . (int)$r['translated'];
    }
}
echo '    values in total: ' . number_format($totals['total']) . "\n";
echo '    longer than 500 characters: ' . number_format($totals['long_values']) . ' (' . number_format($long_indexed) . " in indexed fields; core indexes only the first 500 characters, review E10)\n";
echo '    starting with < and ending with >: ' . number_format($totals['html_like']) . ' (' . number_format($html_indexed) . " in indexed fields; core strips the tags, review E8)\n";
echo '    in translation syntax (~en:…): ' . number_format($totals['translated']) . (count($translated_fields) ? ' (' . implode('; ', $translated_fields) . ')' : '') . " (review E9)\n";
echo '    formatted-text fields: ' . $count(fn($f) => (int)$f['type'] === 8) . "\n";

echo "\n== words in the keyword table: ";
echo number_format((int)(rows($db, 'SELECT COUNT(*) c FROM keyword')[0]['c'] ?? 0)) . "\n";

echo "\n== related keywords defined: ";
echo number_format((int)(rows($db, 'SELECT COUNT(*) c FROM keyword_related')[0]['c'] ?? 0)) . " (review B12: not synced to Typesense)\n";

echo "\n== collections\n";
foreach (rows($db, 'SELECT type, COUNT(*) c, SUM(savedsearch IS NOT NULL AND savedsearch > 0) smart FROM collection GROUP BY type ORDER BY type') as $r) {
    echo sprintf("    type %-2s %-8s smart: %s\n", $r['type'], number_format((int)$r['c']), (int)$r['smart']);
}

echo "\n== the plugin\n";
foreach (rows($db, "SELECT name, inst_version, enabled_groups, config_json FROM plugins WHERE name = 'typesense_search'") as $r) {
    echo '    installed version ' . $r['inst_version'] . ', groups: ' . ($r['enabled_groups'] === null || $r['enabled_groups'] === '' ? 'all' : $r['enabled_groups']) . "\n";
    $config = json_decode((string)$r['config_json'], true);
    foreach (array('typesense_search_enabled', 'typesense_search_only', 'typesense_search_max_rows', 'typesense_search_filter_max_ops', 'typesense_search_show_indicator') as $k) {
        if (is_array($config) && array_key_exists($k, $config)) {
            echo '    ' . $k . ' = ' . json_encode($config[$k]) . "\n";
        }
    }
    if (is_array($config) && !empty($config['typesense_search_global_filter'])) {
        echo "    typesense_search_global_filter is set\n";
    }
}

// Group config can hold other settings and secrets, so only these plugin settings are picked out and printed.
echo "\n== user groups that override a plugin setting\n";
$overrides = 0;
foreach (rows($db, "SELECT ref, config_options FROM usergroup WHERE config_options LIKE '%typesense_search%' ORDER BY ref") as $r) {
    preg_match_all('/\\$typesense_search_(enabled|only|max_rows|filter_max_ops|global_filter)\s*=\s*([^;]*);/', (string)$r['config_options'], $m, PREG_SET_ORDER);
    foreach ($m as $setting) {
        $overrides++;
        echo '    group ' . $r['ref'] . ': $typesense_search_' . $setting[1] . ' = ' . ($setting[1] === 'global_filter' ? '(set)' : trim($setting[2])) . "\n";
    }
}
echo $overrides === 0 ? "    none\n" : '';
echo '    groups in total: ' . (rows($db, 'SELECT COUNT(*) c FROM usergroup')[0]['c'] ?? '?') . "\n";

echo "\n== users with a search filter: ";
$r = rows($db, 'SELECT (SELECT COUNT(*) FROM usergroup WHERE search_filter_id > 0) g, (SELECT COUNT(*) FROM user WHERE search_filter_o_id > 0) u');
echo ($r[0]['g'] ?? '?') . ' groups, ' . ($r[0]['u'] ?? '?') . " users with an override (review E2: their per-resource access is decided by a search)\n";
