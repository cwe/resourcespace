<?php
// Write ../docs/core-search-catalogue.md from live/catalogue.php, adding the totals in results/live/catalogue.json
// when they exist. Usage: php catalogue_table.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$catalogue = require __DIR__ . '/live/catalogue.php';
$results_file = __DIR__ . '/results/live/catalogue.json';
$results = file_exists($results_file) ? (json_decode((string)file_get_contents($results_file), true) ?: array()) : array();
$out = dirname(__DIR__) . '/docs/core-search-catalogue.md';

function cell(string $s): string
{
    return str_replace(array('|', "\n"), array('\\|', ' '), $s);
}

function code(string $s): string
{
    return $s === '' ? '(empty)' : '`' . cell($s) . '`';
}

function where(string $refs): string
{
    $links = array();
    foreach (array_map('trim', explode(',', $refs)) as $ref) {
        if ($ref === '') {
            continue;
        }
        $links[] = '[' . basename($ref) . '](../../../' . $ref . ')';
    }
    return implode(', ', $links);
}

/** The totals cell for one engine: total, rows, error or an empty mark. */
function measured(?array $o): string
{
    if ($o === null) {
        return '–';
    }
    if ($o['error'] !== null) {
        return 'error: ' . cell(mb_substr($o['error'], 0, 60));
    }
    if ($o['total'] === null) {
        return $o['rows'] . ' rows' . (count($o['refs']) ? ' ' . cell(json_encode($o['refs'])) : '');
    }
    $s = number_format((int)$o['total']);
    if (count($o['refs']) > 1) {
        $s .= ' ' . cell(json_encode(array_slice($o['refs'], 0, 5)));
    }
    return $s;
}

$latest = '';
foreach ($results as $r) {
    $latest = max($latest, substr((string)($r['at'] ?? ''), 0, 10));
}

$md = array();
$md[] = '# Core search catalogue';
$md[] = '';
$md[] = 'Every form of search that core\'s `do_search()` accepts, one row each, with an example that uses values from the';
$md[] = 'test database. The list was built from the code at `typesense` c72fe64e (`include/do_search.php`, `do_search_keywords.php`,';
$md[] = '`do_search_nodes.php`, `search_functions.php`, the advanced search form and the search bar). It is for checking through';
$md[] = 'by hand: tick the last column when a row has been confirmed, or correct the row.';
$md[] = '';
$md[] = '- **Search** names the form. **String and parameters** is exactly what is sent to the API (`do_search`, or';
$md[] = '  `search_get_previews` for a day limit); parameters not shown are `restypes=""`, `order_by=relevance`, `archive=0`,';
$md[] = '  `sort=desc`, `fetchrows=0,1`.';
$md[] = '- **What core does** is read from the code; **Where** links to it.';
$md[] = '- **Core** and **Plugin** are the totals from `harness/live/catalogue_run.php` (a user whose group has the plugin';
$md[] = '  off, and one whose group has it in Typesense-only mode, so a plugin 0 can mean "declined"). Five refs are shown when the row';
$md[] = '  checks an order. "–" means not run yet' . ($latest !== '' ? '; the last run was ' . $latest : '') . '. Rows marked n/a cannot be sent through the API.';
$md[] = '- Workflow states 1, 2 and 3 are hidden from both test users (permissions z1, z2, z3), so every total is over the';
$md[] = '  states they can see.';
$md[] = '';
$md[] = 'To rerun: `cd plugins/typesense_search/harness && RS_BASE_URL=… RS_USER_CORE=… RS_KEY_CORE=… RS_USER_TS=… RS_KEY_TS=… php live/catalogue_run.php [--slow] [ids]`,';
$md[] = 'then `php catalogue_table.php` to refresh this file. `live/catalogue_values.php` prints the database values the examples use.';
$md[] = '';
$md[] = '## The test database';
$md[] = '';
$md[] = 'The examples use values that exist on the test database: its field short names, option names, node refs, collection refs';
$md[] = 'and user refs. `live/catalogue_values.php` lists the candidates on any database, so the cases can be rewritten for another one.';
$md[] = '';
$md[] = '## Contents';
$md[] = '';
foreach ($catalogue as $entry) {
    if (is_array($entry) && isset($entry['group'])) {
        $md[] = '- [' . $entry['group'] . '. ' . $entry['title'] . '](#' . strtolower($entry['group']) . '-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($entry['title'])), '-') . ')';
    }
}
$md[] = '';

$header = '| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |';
$rule = '|---|---|---|---|---|---|---|---|';
$count = 0;
foreach ($catalogue as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    if (isset($entry['group'])) {
        $md[] = '## ' . $entry['group'] . '. ' . $entry['title'];
        $md[] = '';
        $md[] = $entry['intro'];
        $md[] = '';
        $md[] = $header;
        $md[] = $rule;
        continue;
    }
    if (!isset($entry['id'])) {
        continue;
    }
    $count++;
    $string = code($entry['search']);
    $params = array();
    foreach (array('restypes', 'archive', 'order_by', 'sort', 'fetchrows', 'offset', 'daylimit') as $p) {
        if (isset($entry[$p]) && !($p === 'order_by' && $entry[$p] === 'relevance' && !isset($entry['rows']))) {
            $params[] = $p . '=' . $entry[$p];
        }
    }
    if (isset($entry['rows']) && (int)$entry['rows'] !== 1) {
        $params[] = 'fetchrows=0,' . (int)$entry['rows'];
    }
    if (count($params) > 0) {
        $string .= ' ' . cell(implode(', ', $params));
    }
    $core = ($entry['api'] ?? true) === false ? 'n/a' : measured($results[$entry['id']]['core'] ?? null);
    $plugin = ($entry['api'] ?? true) === false ? 'n/a' : measured($results[$entry['id']]['plugin'] ?? null);
    $what = cell($entry['core']) . (isset($entry['note']) ? ' ' . cell($entry['note']) : '');
    $md[] = '| ' . $entry['id'] . ' | ' . cell($entry['type']) . ' | ' . $string . ' | ' . $what . ' | ' . where($entry['where']) . ' | ' . $core . ' | ' . $plugin . ' | [ ] |';
}
$md[] = '';
file_put_contents($out, implode("\n", $md) . "\n");
echo $count . ' rows written to ' . $out . "\n";
