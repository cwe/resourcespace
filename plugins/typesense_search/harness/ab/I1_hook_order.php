<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// I1 battery: the core change for the external_search hook. The hook fires before search_special() and the
// standard query, with the filter, join and select already built; whatever a provider returns other than false is
// core's answer (an empty set included) and false leaves the search to core; the access checks that run a search
// go through do_search_access_check() and never reach the hook, so an access decision always comes from the
// database. The hook is answered here by a stub ($HARNESS['hook_stub']) in place of the plugin.
require __DIR__ . '/fixture.php';

$I1_SEARCH = array(
    'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => -1, 'sort' => 'DESC',
    'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
    'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
);
$I1_COLLECTION = array('order_by' => 'collection', 'sort' => 'ASC') + $I1_SEARCH;
$EMPTY = array(); // the stub answer that would hide everything if an access check reached the hook

/** Run $call with the hook answering $stub in place of the plugin: array(its return value, what the hook saw). */
function i1_run(callable $call, $stub): array
{
    global $USE_PLUGIN, $PLUGIN_STATE, $HARNESS;

    harness_reset_caches();
    unset($GLOBALS['resource_access_cache']);
    $HARNESS['sql_errors'] = array();
    $HARNESS['sql_log'] = array();
    $HARNESS['log_sql'] = true;
    $HARNESS['hook_stub'] = $stub;
    $USE_PLUGIN = true;
    $PLUGIN_STATE = array('hook_reached' => false, 'served' => false, 'no_hook' => false, 'outcome' => null, 'reason' => null);
    $result = $call();
    $USE_PLUGIN = false;
    unset($HARNESS['hook_stub']);
    $HARNESS['log_sql'] = false;
    $seen = $PLUGIN_STATE;
    $seen['sql'] = $HARNESS['sql_log'];
    foreach (array_unique($HARNESS['sql_errors']) as $err) {
        echo '    (core SQL did not run on SQLite: ' . substr($err, 0, 200) . ")\n";
    }
    return array($result, $seen);
}

/** One do_search() through the harness runner with the hook answering $stub. */
function i1_search(string $search, array $o, $stub): array
{
    return i1_run(function () use ($search, $o) {
        list($result) = harness_search($search, $o);
        return $result;
    }, $stub);
}

function i1_refs($result): array
{
    return harness_refs($result)['refs'];
}

// ----------------------------------------------------------------------------------------- the order
list($result, $seen) = i1_search('!collection5', $I1_COLLECTION, $EMPTY);
harness_check('I1.1 a special search reaches the hook before search_special()', $seen['hook_reached'] && $seen['args'][0] === '!collection5');
harness_check('I1.2 an empty set from the hook is the answer: core does not fall through to search_special()', $result === array());
list($result, $seen) = i1_search('!collection5', $I1_COLLECTION, false);
harness_check('I1.3 false from the hook leaves the special search to core', $seen['hook_reached'] && i1_refs($result) === array(3, 1, 2), json_encode(i1_refs($result)));
list($result, $seen) = i1_search('sunset', $I1_SEARCH, $EMPTY);
harness_check('I1.4 a standard search reaches the hook and an empty set is the answer', $seen['hook_reached'] && $result === array());
list($result, $seen) = i1_search('sunset', $I1_SEARCH, false);
$refs = i1_refs($result);
sort($refs);
harness_check('I1.5 false from the hook leaves the standard search to core', $refs === array(1, 8, 12, 36, 37), json_encode($refs));
$canned = array(array('ref' => 999, 'resource_type' => 1));
list($result) = i1_search('sunset', $I1_SEARCH, $canned);
harness_check('I1.6 a result set from the hook is returned as it is', $result === $canned);
$structured = array('total' => 0, 'data' => array());
list($result) = i1_search('sunset', array('fetchrows' => array(0, 10)) + $I1_SEARCH, $structured);
harness_check('I1.7 the structured empty set (offset and limit) is returned as it is', $result === $structured);
// log_keyword_usage() is kept: the runner searches without stats logging, so this one is a direct do_search() with it on.
list($result) = i1_run(function () {
    return do_search('sunset', '', 'relevance', '0', -1, 'DESC', false, DEPRECATED_STARSEARCH, false, false, '', false, true);
}, $canned);
$usage = (int)$DB->querySingle("SELECT COUNT(*) FROM daily_stat WHERE activity_type LIKE 'Keyword usage%'");
harness_check('I1.8 keyword usage is still logged when the hook answers', $result === $canned && $usage > 0, $usage . ' daily_stat rows');

// ----------------------------------------------------------------------------------------- what the hook receives
list($result, $seen) = i1_search('sunset harbour', $I1_SEARCH, false);
$a = $seen['args'];
harness_check('I1.9 the hook receives the 21 arguments, the search and its keywords first', count($a) === 21 && $a[0] === 'sunset harbour' && $a[1] === array('sunset', 'harbour'));
harness_check(
    'I1.10 the filter is built: the archive clause and the custom-access clause of a user without v',
    $a[18] instanceof PreparedStatementQuery && stripos($a[18]->sql, 'archive') !== false && strpos($a[18]->sql, 'rca.resource IS null') !== false
);
harness_check(
    'I1.11 the join is built: the resource type join and the custom-access joins',
    $a[19] instanceof PreparedStatementQuery && strpos($a[19]->sql, 'JOIN resource_type AS rty') !== false && strpos($a[19]->sql, 'resource_custom_access rca2') !== false
);
harness_check(
    'I1.12 the select is built: the resource columns, resultant_access and the title field',
    $a[20] instanceof PreparedStatementQuery && strpos($a[20]->sql, 'r.ref, r.resource_type') === 0 && strpos($a[20]->sql, 'resultant_access') !== false && strpos($a[20]->sql, 'r.field8') !== false
);
list($result, $seen) = i1_search('!collection5', $I1_COLLECTION, false);
harness_check(
    'I1.13 for a special search the keywords are empty and order_by is already resolved',
    $seen['args'][1] === array() && strpos($seen['args'][5], 'c.sortorder ASC') === 0,
    json_encode($seen['args'][5])
);
$not_reached = array();
foreach (array('!list1,2', '!last5', '!resource1', '!related1', '!contributions5', '!userpending', '!archivepending', '!empty8', '!collection5 sunset', '!duplicates', '!unused', '!nopreview', '!integrityfail', '!properties', '!hasdata8') as $form) {
    list($result, $seen) = i1_search($form, $I1_SEARCH, $EMPTY);
    if (!$seen['hook_reached'] || $result !== array()) {
        $not_reached[] = $form;
    }
}
harness_check('I1.14 every special form reaches the hook and takes its answer', count($not_reached) === 0, implode(', ', $not_reached));

// ----------------------------------------------------------------------------------------- the access checks
list($result, $seen) = i1_run(function () {
    return do_search_access_check('!collection5', '', 'collection', '0', -1, 'ASC');
}, $EMPTY);
harness_check('I1.15 do_search_access_check() never reaches the hook and returns core\'s answer', !$seen['hook_reached'] && i1_refs($result) === array(3, 1, 2), json_encode(i1_refs($result)));
harness_check('I1.16 the flag is clear again afterwards', empty($GLOBALS['search_access_check']));
list($result, $seen) = i1_search('sunset', $I1_SEARCH, $EMPTY);
harness_check('I1.17 the next ordinary search reaches the hook again', $seen['hook_reached'] && $result === array());
$search_access_check = true;
list($result, $seen) = i1_run(function () {
    return do_search_access_check('!collection5');
}, $EMPTY);
harness_check('I1.18 a nested access check leaves an outer one set', $search_access_check === true && !$seen['hook_reached']);
$search_access_check = false;

// get_resource_access(): its !resource search runs for a user with a search filter. Fixture filter 7 is France only.
$usersearchfilter = 7;
list($access, $seen) = i1_run(function () {
    return get_resource_access(1);
}, $EMPTY);
harness_check('I1.19 get_resource_access() of a resource in the filter: full access, the hook not reached', $access === RESOURCE_ACCESS_FULL && !$seen['hook_reached'], 'access ' . json_encode($access));
$ran = false;
foreach ($seen['sql'] as $statement) {
    if (preg_match('/FROM resource r .*WHERE r\.ref = \?/', $statement) === 1) {
        $ran = true;
    }
}
harness_check('I1.20 its !resource search ran on the database', $ran);
list($access, $seen) = i1_run(function () {
    return get_resource_access(2);
}, $EMPTY);
harness_check('I1.21 the filter is live: a resource outside it is confidential, the hook not reached', $access === RESOURCE_ACCESS_CONFIDENTIAL && !$seen['hook_reached'], 'access ' . json_encode($access));
$usersearchfilter = '';

// The collection gates of the sharing, request and edit paths, on the fixture user's own collection 5 (3, 1, 2; all open).
list($result, $seen) = i1_run(function () {
    return collection_min_access(5);
}, $EMPTY);
harness_check('I1.22 collection_min_access() is open access, the hook not reached', $result === 0 && !$seen['hook_reached'], json_encode($result));
list($result, $seen) = i1_run(function () {
    return collection_max_access(5);
}, $EMPTY);
harness_check('I1.23 collection_max_access() is open access, the hook not reached', $result === 0 && !$seen['hook_reached'], json_encode($result));
list($result, $seen) = i1_run(function () {
    return is_collection_approved(5);
}, $EMPTY);
harness_check('I1.24 is_collection_approved() sees the active state, the hook not reached', $result === array(0) && !$seen['hook_reached'], json_encode($result));
list($result, $seen) = i1_run(function () {
    return allow_multi_edit(5);
}, $EMPTY);
harness_check('I1.25 allow_multi_edit() is false for a user without edit permission, the hook not reached', $result === false && !$seen['hook_reached'], json_encode($result));

// The gate sites in the source, one count per file: the 11.1 change list.
$sites = array(
    'include/resource_functions.php' => 1,
    'include/request_functions.php' => 2,
    'include/collections_functions.php' => 5,
    'include/render_functions.php' => 2,
    'pages/collection_share.php' => 1,
    'pages/edit.php' => 2,
);
$wrong = array();
foreach ($sites as $file => $n) {
    $count = substr_count(file_get_contents($ROOT . '/' . $file), 'do_search_access_check(');
    if ($count !== $n) {
        $wrong[] = $file . ' ' . $count . '/' . $n;
    }
}
harness_check('I1.26 every gate site calls do_search_access_check()', count($wrong) === 0, implode(', ', $wrong));

// ----------------------------------------------------------------------------------------- the smoke battery's F19
$r = ab('F19', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'), array('expect_order' => array(3, 1, 2)));
harness_check('I1.27 F19 is consulted: not run until the plugin has its hook, no longer not consulted', $r['state']['hook_reached']);
harness_check('I1.28 no case was answered before the hook', $HARNESS['tally']['not_consulted'] === 0);

harness_finish('I1_hook_order');
