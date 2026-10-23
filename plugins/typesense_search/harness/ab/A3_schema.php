<?php

// A3 battery: the schema module. The version, the separators, the names, a snapshot of the three definitions
// against the schema register (expected/A3_schema_v1.json), the collections created from scratch on the local
// Typesense, a sample document for every field pattern, and the server facts the design rests on, verified on 30.x.
require __DIR__ . '/boot.php';

$J = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

/** A definition with its field list keyed by name, for order-free comparison and readable diffs. */
function a3_keyed(array $definition): array
{
    $fields = array();
    foreach ($definition['fields'] as $field) {
        ksort($field);
        $fields[$field['name']] = $field;
    }
    unset($definition['fields']);
    ksort($definition);
    $definition['fields'] = $fields;
    return $definition;
}

function a3_diff(array $expected, array $actual): string
{
    global $J;
    $out = array();
    foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $key) {
        if (!array_key_exists($key, $expected)) {
            $out[] = 'unexpected ' . $key;
        } elseif (!array_key_exists($key, $actual)) {
            $out[] = 'missing ' . $key;
        } elseif ($expected[$key] !== $actual[$key]) {
            $out[] = $key . ': expected ' . json_encode($expected[$key]) . ' got ' . json_encode($actual[$key]);
        }
    }
    return implode('; ', $out);
}

// ----------------------------------------------------------------------------------------- version, separators, names
harness_check('A3.1 schema version is 1', typesense_search_schema_version() === 1);

$separators = typesense_search_schema_token_separators();
$expected_separators = array('/', '_', '.', ';', '-', '(', ')', "'", '"', '\\', '?', ',', ':');
harness_check('A3.2 token separators = $config_separators + , : single-byte, no space', $separators === $expected_separators, json_encode($separators));
harness_check('A3.3 multi-byte separators dropped', !in_array('’', $separators, true) && !in_array('“', $separators, true));

harness_check('A3.4 generation name', typesense_search_tenancy_name('resources', 3) === 'harness_resources_g3');
harness_check('A3.5 alias name', typesense_search_tenancy_name('memberships') === 'harness_memberships');
harness_check('A3.6 synonym set name', typesense_search_tenancy_name('related') === 'harness_related');
harness_check('A3.7 aliases of a generation', typesense_search_schema_aliases(2) === array(
    'harness_resources' => 'harness_resources_g2',
    'harness_memberships' => 'harness_memberships_g2',
    'harness_grants' => 'harness_grants_g2',
));
foreach (array('unknown kind' => array('things', null), 'generation on the synonym set' => array('related', 1), 'generation 0' => array('resources', 0)) as $what => $args) {
    try {
        typesense_search_tenancy_name($args[0], $args[1]);
        harness_check('A3.8 ' . $what . ' refused', false);
    } catch (InvalidArgumentException $e) {
        harness_check('A3.8 ' . $what . ' refused', true);
    }
}
$saved_prefix = $typesense_search_prefix;
foreach (array('', 'bad-prefix', 'spaces here', str_repeat('x', 33)) as $bad) {
    $typesense_search_prefix = $bad;
    try {
        typesense_search_tenancy_name('resources');
        harness_check('A3.9 prefix ' . json_encode($bad) . ' refused', false);
    } catch (InvalidArgumentException $e) {
        harness_check('A3.9 prefix ' . json_encode($bad) . ' refused', true);
    }
}
$typesense_search_prefix = $saved_prefix;
$typesense_search_prefix_override = 'override1';
harness_check('A3.10 config.php override wins', typesense_search_tenancy_name('grants', 1) === 'override1_grants_g1');
unset($typesense_search_prefix_override);

// ----------------------------------------------------------------------------------------- snapshot against the register
$snapshot = json_decode(file_get_contents(__DIR__ . '/expected/A3_schema_v1.json'), true);
$stemming = false;
$generation = typesense_search_schema_generation(1);
foreach ($generation as $kind => $definition) {
    $expected = a3_keyed($snapshot[$kind]);
    $actual = a3_keyed($definition);
    $same = $expected === $actual;
    harness_check('A3.11 ' . $kind . ' definition equals the register snapshot ($stemming off)', $same, $same ? '' : a3_diff($expected['fields'], $actual['fields']) . ' ' . a3_diff(array_diff_key($expected, array('fields' => 1)), array_diff_key($actual, array('fields' => 1))));
}
$stemming = true;
$stemmed = typesense_search_schema_resources(1);
$stem_flags = array();
foreach ($stemmed['fields'] as $field) {
    $stem_flags[$field['name']] = $field['stem'] ?? false;
}
harness_check('A3.12 $stemming on stems title, _s, _text and _ss only', $stem_flags === array_merge(array_fill_keys(array_keys($stem_flags), false), array('title' => true, 'field_.*_s' => true, 'field_.*_text' => true, 'field_.*_ss' => true)), json_encode(array_keys(array_filter($stem_flags))));
$stemming = false;

$patterns = array();
foreach (typesense_search_schema_field_patterns() as $pattern) {
    $patterns[] = substr($pattern['name'], strlen('field_.*'));
}
harness_check('A3.13 version-1 representations are exactly _s _text _ss _q _f _k _p', $patterns === array('_s', '_text', '_ss', '_q', '_f', '_k', '_p'), json_encode($patterns));
$resource_names = array_column($generation['resources']['fields'], 'name');
harness_check('A3.14 hit-count cluster and the four packed sort keys present', count(array_diff(array('hit_count', 'user_rating', 'rating', 'ur_hits', 'hits_ur', 'date_ref', 'rating_ur'), $resource_names)) === 0);
harness_check('A3.15 nothing from the dropped set (_ts, _range_start, _range_end, created_date, _q on text)', count(preg_grep('/_ts$|_range_|^created_date$/', $resource_names)) === 0);
harness_check('A3.16 references bind to the generation\'s resources collection', $generation['memberships']['fields'][0]['reference'] === 'harness_resources_g1.id' && $generation['grants']['fields'][0]['reference'] === 'harness_resources_g1.id');
harness_check('A3.17 metadata carries generation and schema version', $generation['resources']['metadata'] === array('generation' => 1, 'schema_version' => 1));

// ----------------------------------------------------------------------------------------- created from scratch on the local Typesense
$aliases = typesense_search_schema_aliases(1);
foreach ($aliases as $alias => $collection) {
    harness_ts('DELETE', '/aliases/' . rawurlencode($alias));
}
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}
$stored = array();
foreach ($generation as $kind => $definition) {
    list($status, $body) = harness_ts('POST', '/collections', $definition);
    harness_check('A3.18 ' . $kind . ' created', $status === 201, $status === 201 ? $definition['name'] : 'HTTP ' . $status . ' ' . json_encode($body));
    $stored[$kind] = is_array($body) ? $body : array();
}
// What the server stored equals what was asked for, in every flag the definition names.
foreach ($generation as $kind => $definition) {
    $server = array();
    foreach ($stored[$kind]['fields'] ?? array() as $field) {
        $server[$field['name']] = $field;
    }
    $problems = array();
    foreach ($definition['fields'] as $field) {
        if (!isset($server[$field['name']])) {
            $problems[] = $field['name'] . ' missing';
            continue;
        }
        foreach ($field as $flag => $value) {
            if (($server[$field['name']][$flag] ?? null) !== $value) {
                $problems[] = $field['name'] . '.' . $flag . '=' . json_encode($server[$field['name']][$flag] ?? null);
            }
        }
    }
    if (isset($definition['token_separators']) && ($stored[$kind]['token_separators'] ?? null) !== $definition['token_separators']) {
        $problems[] = 'token_separators ' . json_encode($stored[$kind]['token_separators'] ?? null);
    }
    if (($stored[$kind]['metadata'] ?? null) !== $definition['metadata']) {
        $problems[] = 'metadata ' . json_encode($stored[$kind]['metadata'] ?? null);
    }
    harness_check('A3.19 ' . $kind . ' stored as defined', count($problems) === 0, implode('; ', $problems));
}

// ----------------------------------------------------------------------------------------- a sample document per field pattern
$resources = $generation['resources']['name'];
$memberships = $generation['memberships']['name'];
$grants = $generation['grants']['name'];
$base = array(
    'ref' => 1, 'ref_s' => '1', 'title' => 'Sunset over the harbour', 'resource_type' => 1, 'archive' => 0, 'access' => 0,
    'created_by' => 1, 'creation_date' => 1577872800, 'modified_date' => 1577959200, 'date_field_sort' => '2:2024-05-17',
    'nodes' => array(0, 201, 301), 'populated_field_ids' => array(0, 8, 18, 12, 3, 1), 'hit_count' => 5, 'user_rating' => 4, 'rating' => 0,
    'ur_hits' => 4 << 32 | 5, 'hits_ur' => 5 << 32 | 4, 'date_ref' => 19860 << 32 | 1, 'rating_ur' => 0 << 32 | 4,
);
$samples = array(
    'every pattern' => $base + array('id' => '1',
        'field_8_s' => 'Sunset over the harbour',                       // _s   single-line text
        'field_18_text' => 'A red car parked by the harbour wall',       // _text multi-line
        'field_3_ss' => array('France', 'Allemagne'),                    // _ss  option names
        'field_12_q' => array('2024', '2024-05', '2024-05-17'),          // _q   date representations
        'field_12_k' => array(20240517000000),                           // _k   fourteen-digit key
        'field_90_q' => array('12.5'),                                   // _q   a number as a word
        'field_90_f' => 12.5,                                            // _f   the number
        'field_51_p' => array('img', 'img_', 'mg_1', '1234', 'jpg'),     // _p   fragments
    ),
    'date range: two keys' => array('id' => '14', 'ref' => 14, 'ref_s' => '14', 'title' => 'Festival', 'date_field_sort' => '2:2024-03-01', 'nodes' => array(0), 'populated_field_ids' => array(0, 8, 91), 'field_91_q' => array('2024', '2024-03', '2024-03-01', '2024-03-10'), 'field_91_k' => array(20240301000000, 20240310000000)) + $base,
    'no metadata, created_by NULL, no ratings' => array('id' => '27', 'ref' => 27, 'ref_s' => '27', 'title' => '', 'date_field_sort' => '0:', 'nodes' => array(0), 'populated_field_ids' => array(0), 'hit_count' => 0, 'ur_hits' => 0, 'hits_ur' => 0, 'date_ref' => 27, 'rating_ur' => 0) + array_diff_key($base, array('created_by' => 1, 'user_rating' => 1, 'rating' => 1)),
    'empty date value, no user rating' => array('id' => '28', 'ref' => 28, 'ref_s' => '28', 'title' => 'Booking form', 'date_field_sort' => '1:', 'field_12_q' => array(), 'field_12_k' => array()) + array_diff_key($base, array('user_rating' => 1)),
    'non-numeric value in a numeric field: _s only' => array('id' => '16', 'ref' => 16, 'ref_s' => '16', 'title' => 'Unpriced', 'date_field_sort' => '2:2025-01-01', 'user_rating' => 2, 'field_90_s' => 'abc') + $base,
);
$lines = array();
foreach ($samples as $doc) {
    $lines[] = json_encode($doc);
}
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', implode("\n", $lines), true);
$results = array_map(function ($line) {
    return json_decode($line, true);
}, explode("\n", trim((string)$body)));
$i = 0;
foreach ($samples as $what => $doc) {
    $ok = $status === 200 && ($results[$i]['success'] ?? false) === true;
    harness_check('A3.20 resource document accepted: ' . $what, $ok, $ok ? '' : json_encode($results[$i] ?? $body));
    $i++;
}
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($memberships) . '/documents/import?action=upsert&dirty_values=reject', implode("\n", array(
    json_encode(array('id' => '5:1', 'resource_id' => '1', 'collection_ref' => 5, 'sortorder' => 10, 'date_added' => 1704110400, 'collection_type' => 0)),
    json_encode(array('id' => '5:14', 'resource_id' => '14', 'collection_ref' => 5, 'sortorder' => -2147483648, 'date_added' => 0, 'collection_type' => 0)),
    json_encode(array('id' => '6:99', 'resource_id' => '99', 'collection_ref' => 6, 'sortorder' => 0, 'date_added' => 0, 'collection_type' => 0)),
)), true);
harness_check('A3.21 membership documents accepted (NULL sortorder as INT32_MIN, a resource not yet indexed)', $status === 200 && substr_count((string)$body, '"success":true') === 3, (string)$body);
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($grants) . '/documents/import?action=upsert&dirty_values=reject', implode("\n", array(
    json_encode(array('id' => '1:-1:3', 'resource_id' => '1', 'user' => -1, 'usergroup' => 3, 'access' => 0, 'expires' => 0)),
    json_encode(array('id' => '1:5:-1', 'resource_id' => '1', 'user' => 5, 'usergroup' => -1, 'access' => 1, 'expires' => 1767225599)),
)), true);
harness_check('A3.22 grant documents accepted (-1 placeholders, expires 0 = never)', $status === 200 && substr_count((string)$body, '"success":true') === 2, (string)$body);

// Array patterns must be arrays; a scalar is rejected (so B2 always emits arrays for _q, _k, _p, _ss).
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', json_encode(array('id' => '2', 'field_90_q' => '42') + $base), true);
harness_check('A3.23 a scalar into an array pattern is rejected', strpos((string)$body, '"success":false') !== false, (string)$body);
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', json_encode(array('id' => '3', 'ref' => '3') + $base), true);
harness_check('A3.24 a numeric string into an int field is rejected under dirty_values=reject', strpos((string)$body, '"success":false') !== false, (string)$body);
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', json_encode(array_diff_key(array('id' => '4') + $base, array('date_field_sort' => 1))), true);
harness_check('A3.25 a document without date_field_sort is rejected (the key is always emitted)', strpos((string)$body, '"success":false') !== false, (string)$body);

// ----------------------------------------------------------------------------------------- server facts (30.x)
$search = function (array $params) use ($resources) {
    list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($resources) . '/documents/search?' . http_build_query($params + array('q' => '*', 'include_fields' => 'ref')));
    $refs = array();
    foreach ($body['hits'] ?? array() as $hit) {
        $refs[] = (int)$hit['document']['ref'];
    }
    return array($status, $refs, $body['message'] ?? '');
};
list($status, $refs) = $search(array('sort_by' => 'ur_hits:desc,date_ref:desc,ref:desc'));
harness_check('A3.26 three sort fields accepted (ties on the packed keys fall to ref)', $status === 200 && $refs === array(28, 16, 14, 1, 27), json_encode($refs));
list($status, $refs, $message) = $search(array('sort_by' => 'ur_hits:desc,date_ref:desc,ref:desc,archive:desc'));
harness_check('A3.27 a fourth sort field is refused (hence the packed keys)', $status === 400, $message);
list($status, $refs) = $search(array('sort_by' => 'date_field_sort:asc'));
harness_check('A3.28 date_field_sort ascending: 0: (NULL) then 1: (empty) then values', $status === 200 && $refs === array(27, 28, 14, 1, 16), json_encode($refs));
list($status, $refs) = $search(array('sort_by' => 'date_field_sort:desc'));
harness_check('A3.29 date_field_sort descending: values first, NULL last, as core sorts the cache column', $status === 200 && $refs === array(16, 1, 14, 28, 27), json_encode($refs));
list($status, $refs, $message) = $search(array('sort_by' => 'user_rating(missing_values:first):desc,ref:desc'));
harness_check('A3.30 missing_values:first exists on 30.x and puts the two unrated documents first', $status === 200 && array_slice($refs, 0, 2) === array(28, 27), $status === 200 ? json_encode($refs) : $message);
list($status, $refs) = $search(array('sort_by' => 'user_rating:asc,ref:desc'));
harness_check('A3.31 a missing numeric value sorts to the end whatever the direction', $status === 200 && array_slice($refs, -2) === array(28, 27), json_encode($refs));
list($status, $refs) = $search(array('filter_by' => 'field_12_k:>=20240101000000 && field_12_k:<=20241231235959'));
harness_check('A3.32 range filter on the int64[] key', $status === 200 && $refs === array(1), json_encode($refs));
list($status, $refs) = $search(array('filter_by' => 'field_91_k:>=20240305000000 && field_91_k:<=20241231235959'));
harness_check('A3.33 the date-range field matches on either key (overlap, SD4)', $status === 200 && $refs === array(14), json_encode($refs));
list($status, $refs) = $search(array('filter_by' => 'nodes:!=201'));
harness_check('A3.34 a negated node filter includes documents holding only the sentinel 0', $status === 200 && !in_array(1, $refs, true) && in_array(27, $refs, true), json_encode($refs));
list($status, $refs) = $search(array('filter_by' => 'nodes:=0 && populated_field_ids:=0', 'per_page' => 250));
harness_check('A3.34b every document carries the sentinel 0 in both arrays (Q1.15)', $status === 200 && count($refs) === 5, json_encode($refs));
list($status, $refs) = $search(array('filter_by' => '$' . $memberships . '(collection_ref:=5)'));
harness_check('A3.35 join through the reference field', $status === 200 && $refs === array(14, 1), json_encode($refs));
list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($resources) . '/documents/search?' . http_build_query(array('q' => 'harbours', 'query_by' => 'field_8_s', 'num_typos' => 0, 'prefix' => 'false', 'include_fields' => 'ref')));
harness_check('A3.36 $stemming off: "harbours" does not match "harbour" (no typos, no prefix)', ($body['found'] ?? -1) === 0, 'found ' . ($body['found'] ?? '?'));
list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($resources) . '/documents/search?' . http_build_query(array('q' => '*', 'per_page' => 251)));
harness_check('A3.37 per_page above 250 is 422', $status === 422, (string)($body['message'] ?? ''));
$bad = $generation['resources'];
$bad['name'] = 'harness_sep_probe';
$bad['token_separators'] = array('-', '’');
list($status, $body) = harness_ts('POST', '/collections', $bad);
harness_check('A3.38 a multi-byte token separator is refused by the server', $status === 400, (string)($body['message'] ?? ''));
harness_ts('DELETE', '/collections/harness_sep_probe');

// ----------------------------------------------------------------------------------------- aliases swapped together, cascade
$ok = true;
foreach ($aliases as $alias => $collection) {
    list($status, $body) = harness_ts('PUT', '/aliases/' . rawurlencode($alias), array('collection_name' => $collection));
    $ok = $ok && $status === 200;
}
harness_check('A3.39 the three aliases point at generation 1', $ok);
list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode(typesense_search_tenancy_name('resources')) . '/documents/search?' . http_build_query(array('q' => '*', 'per_page' => 1)));
harness_check('A3.40 a search through the alias reaches the generation', $status === 200 && ($body['found'] ?? 0) === 5, 'found ' . ($body['found'] ?? '?'));
harness_ts('DELETE', '/collections/' . rawurlencode($resources) . '/documents/1');
list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($memberships) . '/documents/' . rawurlencode('5:1'));
list($status2, $body2) = harness_ts('GET', '/collections/' . rawurlencode($grants) . '/documents/' . rawurlencode('1:-1:3'));
harness_check('A3.41 deleting a resource cascades to its memberships and grants', $status === 404 && $status2 === 404, $status . '/' . $status2);

// ----------------------------------------------------------------------------------------- clean up
foreach ($aliases as $alias => $collection) {
    harness_ts('DELETE', '/aliases/' . rawurlencode($alias));
}
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}

harness_finish('A3_schema');
