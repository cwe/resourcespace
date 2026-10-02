<?php
// Combinations of search terms, arguments, special searches and permissions.
//
// The fixture is factorial: 512 resources, one for every mix of nine two-valued attributes, so any AND of
// terms has a known answer. Each case is checked three ways: core against the expected set, the plugin against
// the expected set, and the two against each other. Only terms that agree on their own are combined, so that
// a difference here comes from the combination. Cases are generated with a fixed seed.
//
// HARNESS_COMBO_SCALE (default 1) multiplies the number of sampled cases. HARNESS_COMBO_STEMMING=1 turns $stemming on.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixture_lib.php';

// HARNESS_COMBO_STEMMING=1 runs everything with $stemming on (it must be set before anything is indexed).
if (getenv('HARNESS_COMBO_STEMMING')) {
    $stemming = true;
    include_once $ROOT . '/lib/stemming/en.php';
    echo "\$stemming is ON\n";
}

$SCALE = (float)(getenv('HARNESS_COMBO_SCALE') ?: 1);
mt_srand(20261002);

// ----------------------------------------------------------------------------------------- schema
foreach (array(1 => 'Photo', 2 => 'Document') as $ref => $name) {
    fx_exec('INSERT INTO resource_type (ref, name, order_by) VALUES (?, ?, ?)', array($ref, $name, $ref));
}
fx_field(8, 'title', FIELD_TYPE_TEXT_BOX_SINGLE_LINE);
fx_field(18, 'caption', FIELD_TYPE_TEXT_BOX_MULTI_LINE);
fx_field(12, 'date', FIELD_TYPE_DATE_AND_OPTIONAL_TIME);
fx_field(3, 'country', FIELD_TYPE_DROP_DOWN_LIST);
fx_field(1, 'keywords', FIELD_TYPE_DYNAMIC_KEYWORDS_LIST);
fx_field(90, 'price', FIELD_TYPE_TEXT_BOX_SINGLE_LINE, array('field_constraint' => 1));
fx_field(10, 'credit', FIELD_TYPE_TEXT_BOX_SINGLE_LINE);
foreach (array(1 => array('admin', 1), 5 => array('tester', 3), 9 => array('other', 3)) as $ref => $u) {
    fx_exec('INSERT INTO user (ref, username, fullname, usergroup) VALUES (?, ?, ?, ?)', array($ref, $u[0], ucfirst($u[0]), $u[1]));
}
fx_exec("INSERT INTO usergroup (ref, name) VALUES (1, 'Admins'), (3, 'General users')");
fx_node(3, 'France', null, 201);
fx_node(3, 'Spain', null, 203);
fx_node(1, 'sculpture', null, 301);
fx_node(1, 'landscape', null, 303);
fx_exec("INSERT INTO node (ref, resource_type_field, name, order_by) VALUES (1000, 10, 'placeholder', 10)");
// Search filter 7: the resource must have country France.
fx_exec('INSERT INTO filter (ref, name, filter_condition) VALUES (7, ?, ?)', array('France only', RS_FILTER_ALL));
fx_exec('INSERT INTO filter_rule (ref, filter) VALUES (71, 7)');
fx_exec('INSERT INTO filter_rule_node (filter_rule, node_condition, node) VALUES (71, 1, 201)');

// ----------------------------------------------------------------------------------------- resources
$DIM = array(
    'title' => array('alpha', 'omega'),
    'phrase' => array('red car', 'car red'),
    'place' => array('harbour', 'marina'),
    'country' => array(201, 203),
    'keyword' => array(301, 303),
    'date' => array('2024-05-17', '2023-03-10'),
    'price' => array('50', '500'),
    'type' => array(1, 2),
    'archive' => array(0, 2),
);
$node_cache = array();
$value_node = function (int $field, string $value) use (&$node_cache): int {
    return $node_cache[$field . '|' . $value] ??= fx_node($field, $value);
};

$R = array(); // ref => attributes
$recent_date = date('Y-m-d H:i:s', strtotime('-5 days'));
for ($i = 0; $i < 512; $i++) {
    $ref = 1001 + $i;
    $r = array();
    $bit = 0;
    foreach ($DIM as $name => $values) {
        $r[$name] = ($i >> $bit++) & 1;
    }
    // Attributes that are not dimensions, assigned at random (fixed seed).
    $r['by9'] = mt_rand(0, 2) === 0;
    $r['in5'] = mt_rand(0, 4) === 0;
    $r['in30'] = mt_rand(0, 3) === 0;
    $r['credit'] = mt_rand(0, 1) === 0;
    $r['recent'] = mt_rand(0, 7) === 0;
    $R[$ref] = $r;

    $title = $DIM['title'][$r['title']] . ' piece';
    $caption = $DIM['phrase'][$r['phrase']] . ' beside ' . $DIM['place'][$r['place']];
    $date = $DIM['date'][$r['date']];
    fx_resource($ref, $DIM['type'][$r['type']], array(
        'archive' => $DIM['archive'][$r['archive']],
        'created_by' => $r['by9'] ? 9 : 1,
        'creation_date' => $r['recent'] ? $recent_date : '2020-01-01 10:00:00',
        'modified' => sprintf('2021-%02d-%02d 08:00:00', 1 + ($i % 12), 1 + ($i % 28)),
    ));
    fx_tag($ref, $value_node(8, $title));
    fx_tag($ref, $value_node(18, $caption));
    fx_tag($ref, $value_node(12, $date));
    fx_tag($ref, $value_node(90, $DIM['price'][$r['price']]));
    fx_tag($ref, $DIM['country'][$r['country']]);
    fx_tag($ref, $DIM['keyword'][$r['keyword']]);
    if ($r['credit']) {
        fx_tag($ref, $value_node(10, 'studio'));
    }
    fx_exec('UPDATE resource SET field8 = ?, field18 = ?, field12 = ? WHERE ref = ?', array($title, $caption, $date, $ref));
}
$members = fn(string $flag) => array_keys(array_filter($R, fn($r) => $r[$flag]));
foreach (array(array(5, 'My collection', 5, COLLECTION_TYPE_STANDARD, 'in5'), array(30, 'Featured', 1, COLLECTION_TYPE_FEATURED, 'in30')) as $c) {
    fx_exec('INSERT INTO collection (ref, name, user, type, created, allow_changes) VALUES (?, ?, ?, ?, ?, 0)', array($c[0], $c[1], $c[2], $c[3], '2024-01-01 00:00:00'));
    foreach ($members($c[4]) as $n => $ref) {
        fx_exec('INSERT INTO collection_resource (collection, resource, date_added, sortorder) VALUES (?, ?, ?, ?)',
            array($c[0], $ref, date('Y-m-d H:i:s', strtotime('2024-01-01 12:00:00') + $n * 60), ($n + 1) * 10));
    }
}
if (!empty($HARNESS['sql_errors'])) {
    echo "SQL errors while loading the fixture:\n  " . implode("\n  ", array_unique($HARNESS['sql_errors'])) . "\n";
}
fx_index();

// ----------------------------------------------------------------------------------------- terms
// Each term: the dimension it tests, the text, and the attribute value a matching resource has.
$TERMS = array(
    array('title', 'alpha', 0), array('title', 'alph*', 0), array('title', 'title:alpha', 0), array('title', 'title:ome*', 1), array('title', '-omega', 0),
    array('phrase', '"red car"', 0), array('phrase', '-"red car"', 1), array('phrase', '"car red"', 1),
    array('place', 'harbour', 0), array('place', '-harbour', 1), array('place', 'caption:marina', 1), array('place', 'marin*', 1),
    array('country', 'country:france', 0), array('country', '@@201', 0), array('country', '@@!203', 0), array('country', '@@203', 1), array('country', 'country:spain', 1),
    array('keyword', 'keywords:landscape', 1), array('keyword', '@@301', 0), array('keyword', 'sculpture', 0), array('keyword', '-landscape', 0),
    array('date', 'date:2024', 0), array('date', 'date:2023-03', 1), array('date', 'date:2024-05-17', 0),
    array('date', 'date:rangestart2024-01-01end2024-12-31', 0), array('date', 'date:rangeend2023-12-31', 1),
    array('price', 'price:numrange10|100', 0), array('price', 'price:numrange100|1000', 1), array('price', 'price:numrange50|', 0),
    // A no-op that still adds a clause.
    array(null, 'country:france;spain', null),
);

$DEFAULTS = array(
    'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 2000), 'sort' => 'DESC',
    'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
    'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
);

/**
 * The refs a search should return: every resource passing the terms, the arguments and the scope.
 * $scope: null, or array(kind, value) for a special search or a permission.
 */
function expected(array $terms, array $args, ?array $special = null, ?string $permission = null): array
{
    global $R, $DIM;
    $states = $args['archive'] === '' ? array(0) : array_map('intval', explode(',', $args['archive']));
    $types = $args['restypes'] === '' ? null : array_map('intval', explode(',', $args['restypes']));
    $any_state = $special !== null && in_array($special[0], array('collection', 'list', 'hasdata'), true);

    $refs = array();
    foreach ($R as $ref => $r) {
        if (!$any_state && !in_array($DIM['archive'][$r['archive']], $states, true)) {
            continue;
        }
        if ($types !== null && !in_array($DIM['type'][$r['type']], $types, true)) {
            continue;
        }
        if ($args['daylimit'] !== '' && !$r['recent']) {
            continue;
        }
        foreach ($terms as $t) {
            if ($t[0] !== null && $r[$t[0]] !== $t[2]) {
                continue 2;
            }
        }
        if ($special !== null) {
            $in = match ($special[0]) {
                'collection' => $r['in5'],
                'list' => in_array($ref, $special[1], true),
                'contributions' => $r['by9'],
                'hasdata' => $r['credit'],
                'resource' => $ref === $special[1],
                'last' => true,
            };
            if (!$in) {
                continue;
            }
        }
        if ($permission === 'T2' && $DIM['type'][$r['type']] === 2) {
            continue;
        }
        // J with j*: core requires membership of any collection.
        if ($permission === 'J' && !$r['in30'] && !$r['in5']) {
            continue;
        }
        if ($permission === 'filter' && $r['country'] !== 0) {
            continue;
        }
        $refs[] = $ref;
    }
    if ($special !== null && $special[0] === 'last') {
        rsort($refs);
        $refs = array_slice($refs, 0, $special[1]);
    }
    sort($refs);
    return $refs;
}

/** What a set of resources has in common, to show which term a difference corresponds to. */
function describe(array $refs): string
{
    global $R, $DIM;
    if (count($refs) === 0) {
        return 'none';
    }
    $common = array();
    foreach (array_keys($DIM) as $name) {
        $values = array_unique(array_map(fn($ref) => $R[$ref][$name], $refs));
        if (count($values) === 1) {
            $common[] = $name . '=' . $DIM[$name][reset($values)];
        }
    }
    return count($refs) . ' resources' . (count($common) ? ', all with ' . implode(', ', $common) : ', nothing in common');
}

$STATS = array();

/** Run one case on both sides and record how it compares. */
function combo(string $group, string $search, array $args, array $expected, bool $check_order = false, bool $window = false): void // $window: only part of the result was fetched
{
    global $USE_PLUGIN, $PLUGIN_STATE, $HARNESS, $STATS, $DEFAULTS;
    $o = $args + $DEFAULTS;
    $read = function ($result): array {
        if (!is_array($result)) {
            return array(null, array(), $result);
        }
        $rows = $result['data'] ?? $result;
        $refs = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['ref'])) {
                $refs[] = (int)$row['ref'];
            }
        }
        return array((int)($result['total'] ?? count($rows)), $refs, null);
    };

    $HARNESS['sql_errors'] = array();
    harness_reset_caches();
    $USE_PLUGIN = false;
    list($core_result) = harness_search($search, $o);
    list($core_total, $core_refs, $core_raw) = $read($core_result);
    $sql_errors = $HARNESS['sql_errors'];
    harness_reset_caches();
    $USE_PLUGIN = true;
    $PLUGIN_STATE = array('hook_reached' => false, 'served' => false);
    list($plugin_result) = harness_search($search, $o);
    list($plugin_total, $plugin_refs, $plugin_raw) = $read($plugin_result);
    $USE_PLUGIN = false;

    $s = &$STATS[$group];
    $s ??= array('cases' => 0, 'served' => 0, 'fallback' => 0, 'not_consulted' => 0, 'error' => 0, 'core_ok' => 0, 'plugin_ok' => 0, 'agree' => 0, 'order_checked' => 0, 'order_same' => 0, 'empty' => 0, 'flagged' => 0);
    $s['cases']++;
    $how = !$PLUGIN_STATE['hook_reached'] ? 'not_consulted' : (isset($PLUGIN_STATE['error']) ? 'error' : ($PLUGIN_STATE['served'] ? 'served' : 'fallback'));
    $s[$how]++;

    $sorted = function (array $refs): array {
        sort($refs);
        return $refs;
    };
    // With a window only the totals can be compared with the expected set.
    $core_ok = $window ? $core_total === count($expected) : $sorted($core_refs) === $expected;
    $plugin_ok = $window ? $plugin_total === count($expected) : $sorted($plugin_refs) === $expected;
    $agree = $core_total === $plugin_total && ($check_order ? $core_refs === $plugin_refs : ($window ? true : $sorted($core_refs) === $sorted($plugin_refs)));
    $s['core_ok'] += (int)$core_ok;
    $s['plugin_ok'] += (int)$plugin_ok;
    $s['agree'] += (int)$agree;
    $s['empty'] += (int)(count($expected) === 0);
    if ($check_order) {
        $s['order_checked']++;
        $s['order_same'] += (int)($core_refs === $plugin_refs);
    }

    if ($core_ok && $plugin_ok && $agree) {
        return;
    }
    $s['flagged']++;
    if ($s['flagged'] > 25) {
        return;
    }
    if ($s['flagged'] === 1) {
        echo "\n---- " . $group . "\n";
    }
    $shown_args = array_diff_assoc(array_map('json_encode', $args), array_map('json_encode', $DEFAULTS));
    echo "\n  " . json_encode($search, JSON_UNESCAPED_SLASHES) . (count($shown_args) ? '  [' . implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($shown_args), $shown_args)) . ']' : '') . "\n";
    echo '      expected ' . count($expected) . ' | core ' . ($core_raw !== null ? 'returned ' . json_encode($core_raw) : $core_total) . ($core_ok ? '' : ' (WRONG)')
        . ' | plugin ' . ($how === 'served' ? $plugin_total : $how . (isset($PLUGIN_STATE['error']) ? ' ' . $PLUGIN_STATE['error'] : '') . (isset($PLUGIN_STATE['reason']) ? ' ' . $PLUGIN_STATE['reason'] : ''))
        . ($plugin_ok ? '' : ' (WRONG)') . "\n";
    if (!$window) {
        if (!$core_ok) {
            echo '      core is missing: ' . describe(array_values(array_diff($expected, $core_refs))) . '; core has extra: ' . describe(array_values(array_diff($core_refs, $expected))) . "\n";
        }
        if (!$plugin_ok && $how === 'served') {
            echo '      plugin is missing: ' . describe(array_values(array_diff($expected, $plugin_refs))) . '; plugin has extra: ' . describe(array_values(array_diff($plugin_refs, $expected))) . "\n";
        }
    }
    if ($check_order && $core_refs !== $plugin_refs && $sorted($core_refs) === $sorted($plugin_refs)) {
        echo "      same resources, different order\n";
    }
    foreach (array_unique($sql_errors) as $err) {
        echo '      (core SQL did not run on SQLite: ' . substr($err, 0, 160) . ")\n";
    }
}

/** Pick $n terms from different dimensions, in random order. */
function pick_terms(int $n): array
{
    global $TERMS;
    $by_dim = array();
    foreach ($TERMS as $t) {
        $by_dim[$t[0] ?? 'noop'][] = $t;
    }
    $dims = array_keys($by_dim);
    shuffle($dims);
    $picked = array();
    foreach (array_slice($dims, 0, $n) as $d) {
        $picked[] = $by_dim[$d][mt_rand(0, count($by_dim[$d]) - 1)];
    }
    return $picked;
}

$join = fn(array $terms, string $sep) => implode($sep, array_column($terms, 1));
$started = microtime(true);

// ----------------------------------------------------------------------------------------- 1. single terms
foreach ($TERMS as $t) {
    combo('1 single terms', $t[1], array(), expected(array($t), $DEFAULTS));
}

// ----------------------------------------------------------------------------------------- 2. every ordered pair
foreach ($TERMS as $a) {
    foreach ($TERMS as $b) {
        if ($a === $b) {
            continue;
        }
        combo('2 ordered pairs, comma separated', $a[1] . ', ' . $b[1], array(), expected(array($a, $b), $DEFAULTS));
        if (mt_rand(0, 3) === 0) {
            combo('3 ordered pairs, space separated (sample)', $a[1] . ' ' . $b[1], array(), expected(array($a, $b), $DEFAULTS));
        }
    }
}

// ----------------------------------------------------------------------------------------- 4. three to seven terms
for ($n = 0; $n < 300 * $SCALE; $n++) {
    $terms = pick_terms(mt_rand(3, 7));
    combo('4 three to seven terms', $join($terms, mt_rand(0, 2) === 0 ? ' ' : ', '), array(), expected($terms, $DEFAULTS));
}

// ----------------------------------------------------------------------------------------- 5. terms with arguments
$ORDERS = array(array('relevance', false), array('date', true), array('resourceid', true), array('modified', true));
for ($n = 0; $n < 300 * $SCALE; $n++) {
    $terms = pick_terms(mt_rand(0, 3));
    $order = $ORDERS[mt_rand(0, 3)];
    $window = array(array(0, 2000), array(0, 2000), array(0, 48), array(40, 48))[mt_rand(0, 3)];
    $args = array(
        'restypes' => array('', '1', '2', '1,2')[mt_rand(0, 3)],
        'archive' => array('0', '0,2', '2', '')[mt_rand(0, 3)],
        'order_by' => $order[0],
        'sort' => array('DESC', 'ASC')[mt_rand(0, 1)],
        'daylimit' => mt_rand(0, 4) === 0 ? '30' : '',
        'fetchrows' => $window,
    );
    // With a window only the totals can be checked against the expected set; the order is still compared.
    $windowed = $window[1] !== 2000;
    combo('5 terms with arguments', $join($terms, ', '), $args, expected($terms, $args + $DEFAULTS), $order[1], $windowed);
}

// ----------------------------------------------------------------------------------------- 6. special searches with terms
$list_refs = array_slice(array_keys($R), 100, 60);
$SPECIALS = array(
    array('!collection5', array('collection'), array('order_by' => 'collection', 'sort' => 'ASC')),
    array('!list' . implode(':', $list_refs), array('list', $list_refs), array()),
    array('!contributions9', array('contributions'), array()),
    array('!hasdata10', array('hasdata'), array()),
    array('!last60', array('last', 60), array()),
    array('!resource1100', array('resource', 1100), array()),
);
foreach ($SPECIALS as $sp) {
    combo('6 special searches alone', $sp[0], $sp[2], expected(array(), $sp[2] + $DEFAULTS, $sp[1]), isset($sp[2]['order_by']));
    foreach ($TERMS as $t) {
        // !last takes its number up to the first comma, so a space-separated term makes it 1000 (as core does).
        foreach (array(', ', ' ') as $sep) {
            $scope = $sp[1];
            // (A node token is taken out of the string before that, so it does not change the number.)
            if ($scope[0] === 'last' && $sep === ' ' && strpos($t[1], '@@') !== 0) {
                $scope[1] = 1000;
            }
            combo('7 special search plus one term', $sp[0] . $sep . $t[1], $sp[2], expected(array($t), $sp[2] + $DEFAULTS, $scope), isset($sp[2]['order_by']));
        }
    }
}
for ($n = 0; $n < 150 * $SCALE; $n++) {
    $sp = $SPECIALS[mt_rand(0, count($SPECIALS) - 1)];
    $terms = pick_terms(mt_rand(2, 4));
    $args = $sp[2] + array('archive' => array('0', '0,2', '2')[mt_rand(0, 2)]);
    combo('8 special search plus two to four terms and workflow states', $sp[0] . ', ' . $join($terms, ', '), $args, expected($terms, $args + $DEFAULTS, $sp[1]), isset($sp[2]['order_by']));
}

// ----------------------------------------------------------------------------------------- 9. permissions with terms
$STANDARD = $userpermissions;
foreach (array('T2' => array_merge($STANDARD, array('T2')), 'J' => array('s', 'f*', 'f-93', 'g', 'J', 'j*'), 'filter' => $STANDARD) as $permission => $perms) {
    $userpermissions = $perms;
    $usersearchfilter = $permission === 'filter' ? 7 : '';
    for ($n = 0; $n < 80 * $SCALE; $n++) {
        $terms = pick_terms(mt_rand(0, 4));
        $args = array('archive' => array('0', '0,2')[mt_rand(0, 1)], 'restypes' => array('', '1', '1,2')[mt_rand(0, 2)]);
        combo('9 permissions: ' . array('T2' => 'T2 (type 2 hidden)', 'J' => 'J (featured collections only)', 'filter' => 'search filter (France)')[$permission],
            $join($terms, ', '), $args, expected($terms, $args + $DEFAULTS, null, $permission));
    }
}
$userpermissions = $STANDARD;
$usersearchfilter = '';

// ----------------------------------------------------------------------------------------- 10. OR groups of nodes
$france = array('country', '', 0);
$sculpture = array('keyword', '', 0);
$alpha = array('title', '', 0);
foreach (array(
    array('@@201@@203', array()),
    array('@@201@@203, @@301@@303', array()),
    array('@@201@@203, alpha', array($alpha)),
    array('alpha, @@301@@303, date:2024', array($alpha, array('date', '', 0))),
    array('@@201@@203, @@301', array($sculpture)),
    // The same node alone and inside an OR group.
    array('@@201@@203, @@201', array($france)),
    array('@@201, @@201@@203', array($france)),
    array('@@301, @@301@@303', array($sculpture)),
    array('@@201, @@201', array($france)),
) as $case) {
    combo('10 OR groups of nodes', $case[0], array(), expected($case[1], $DEFAULTS));
}

// ----------------------------------------------------------------------------------------- 11. a word and its own wildcard
echo "\n---- asked of Typesense directly: a word together with a wildcard that only completes to that word\n";
harness_probe('q=alpha alph prefix=true (what the plugin sends for: alpha, alph*)', array('q' => 'alpha alph', 'prefix' => 'true', 'filter_by' => 'archive:=0'));
harness_probe('q=alph prefix=true', array('q' => 'alph', 'prefix' => 'true', 'filter_by' => 'archive:=0'));
harness_probe('q=piece alph prefix=true', array('q' => 'piece alph', 'prefix' => 'true', 'filter_by' => 'archive:=0'));
harness_probe('q=alpha alpha prefix=false', array('q' => 'alpha alpha', 'prefix' => 'false', 'filter_by' => 'archive:=0'));

// ----------------------------------------------------------------------------------------- summary
echo "\n\n################ COMBINATIONS: summary ################\n";
echo sprintf("%-66s %6s %7s %9s %8s %10s %12s %10s %8s\n", 'group', 'cases', 'served', 'fallback', 'core ok', 'plugin ok', 'both agree', 'order', 'flagged');
foreach ($STATS as $group => $s) {
    echo sprintf("%-66s %6d %7d %9d %8d %10d %12d %10s %8d\n", $group, $s['cases'], $s['served'], $s['fallback'] + $s['not_consulted'] + $s['error'], $s['core_ok'], $s['plugin_ok'], $s['agree'],
        $s['order_checked'] ? $s['order_same'] . '/' . $s['order_checked'] : '-', $s['flagged']);
}
echo "\n\"core ok\" / \"plugin ok\": the result equals the expected set. \"both agree\": core and the plugin return the same\n"
    . "resources (and the same order where an order was checked). Cases listed above the summary are the ones where\n"
    . "any of the three failed; at most 25 are printed per group.\n";
echo 'cases whose expected set is empty: ' . array_sum(array_column($STATS, 'empty')) . ' of ' . array_sum(array_column($STATS, 'cases')) . "\n";
