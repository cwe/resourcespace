<?php
/**
 * Trace harness: runs core's real do_search() and the typesense_search plugin's real query builder, with only
 * the database layer replaced by an in-memory fake. Nothing is sent to MySQL or Typesense: it shows the SQL core
 * builds and the request the plugin would send, not results.
 *
 * Environment: HARNESS_ROOT (code tree under test, default this checkout), HARNESS_SCRATCH (generated files).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL & ~E_DEPRECATED);
define('RS_TEST_MODE', true); // so field permissions are honoured on the command line
ini_set('display_errors', '1');

$ROOT = getenv('HARNESS_ROOT') ?: dirname(__DIR__, 4);
$SCRATCH = getenv('HARNESS_SCRATCH') ?: sys_get_temp_dir() . '/rs_typesense_harness';
if (!is_dir($SCRATCH)) {
    mkdir($SCRATCH, 0700, true);
}

// ---------------------------------------------------------------------------------------------
// Fake schema
// ---------------------------------------------------------------------------------------------
function fake_field(int $ref, string $name, int $type, array $over = array()): array
{
    return array_merge(array(
        'ref' => $ref, 'name' => $name, 'title' => ucfirst($name), 'type' => $type,
        'keywords_index' => 1, 'partial_index' => 0, 'complete_index' => 0, 'active' => 1,
        'field_constraint' => 0, 'global' => 1, 'sort_method' => 0, 'order_by' => $ref * 10,
        'resource_types' => '', 'advanced_search' => 1, 'simple_search' => 0, 'tab' => 0, 'tab_name' => '',
        'display_as_dropdown' => 0, 'automatic_nodes_ordering' => 0, 'display_condition' => '',
    ), $over);
}

$FAKE = array();
$FAKE['fields'] = array(
    8  => fake_field(8, 'title', 0),                                   // single-line text, title field
    18 => fake_field(18, 'caption', 1),                                // multi-line text
    12 => fake_field(12, 'date', 4),                                   // date + optional time ($date_field)
    3  => fake_field(3, 'country', 3),                                 // dropdown (fixed list)
    1  => fake_field(1, 'keywords', 9),                                // dynamic keywords (fixed list)
    73 => fake_field(73, 'subject', 7),                                // category tree (fixed list)
    51 => fake_field(51, 'originalfilename', 0, array('partial_index' => 1)),
    90 => fake_field(90, 'price', 0, array('field_constraint' => 1)),  // numeric text field
    91 => fake_field(91, 'eventdates', 14),                            // date range
    92 => fake_field(92, 'notes', 8),                                  // formatted (HTML) text
    93 => fake_field(93, 'secret', 0),                                 // user has no view access (f-93)
    94 => fake_field(94, 'oldfield', 0, array('active' => 0)),         // inactive
    95 => fake_field(95, 'expiry', 6),                                 // expiry date
);
$FAKE['nodes'] = array(
    201 => array('ref' => 201, 'resource_type_field' => 3, 'name' => 'France', 'parent' => null, 'order_by' => 10),
    202 => array('ref' => 202, 'resource_type_field' => 3, 'name' => 'United Kingdom', 'parent' => null, 'order_by' => 20),
    203 => array('ref' => 203, 'resource_type_field' => 3, 'name' => 'Spain', 'parent' => null, 'order_by' => 30),
    301 => array('ref' => 301, 'resource_type_field' => 1, 'name' => 'sculpture', 'parent' => null, 'order_by' => 10),
    302 => array('ref' => 302, 'resource_type_field' => 1, 'name' => 'modern art', 'parent' => null, 'order_by' => 20),
    401 => array('ref' => 401, 'resource_type_field' => 73, 'name' => 'Animals', 'parent' => null, 'order_by' => 10),
    402 => array('ref' => 402, 'resource_type_field' => 73, 'name' => 'Birds', 'parent' => 401, 'order_by' => 20),
    931 => array('ref' => 931, 'resource_type_field' => 93, 'name' => 'hidden value', 'parent' => null, 'order_by' => 10),
);
$FAKE['restypes'] = array(1 => 'Photo', 2 => 'Document', 3 => 'Video', 4 => 'Audio');
$FAKE['unknown_keywords'] = array();   // keywords that do NOT exist in the keyword table
$FAKE['collections'] = array(
    5   => array('ref' => 5, 'name' => 'My collection', 'user' => 5, 'type' => 0, 'savedsearch' => '', 'parent' => null, 'request_feedback' => 0, 'allow_changes' => 0),
    6   => array('ref' => 6, 'name' => 'Someone else private', 'user' => 9, 'type' => 0, 'savedsearch' => '', 'parent' => null, 'request_feedback' => 0, 'allow_changes' => 0),
    7   => array('ref' => 7, 'name' => 'Selection', 'user' => 5, 'type' => 2, 'savedsearch' => '', 'parent' => null, 'request_feedback' => 0, 'allow_changes' => 0),
    30  => array('ref' => 30, 'name' => 'Featured A', 'user' => 1, 'type' => 3, 'savedsearch' => '', 'parent' => null, 'request_feedback' => 0, 'allow_changes' => 0),
    31  => array('ref' => 31, 'name' => 'Featured B', 'user' => 1, 'type' => 3, 'savedsearch' => '', 'parent' => null, 'request_feedback' => 0, 'allow_changes' => 0),
);
$FAKE['filters'] = array();       // ref => array('ref','name','filter_condition')
$FAKE['filter_rules'] = array();  // filter ref => rows (rule, node_condition, node)
$FAKE['sql_log'] = array();
$FAKE['unknown_sql'] = array();

// ---------------------------------------------------------------------------------------------
// Database layer replacement (instead of include/database_functions.php)
// ---------------------------------------------------------------------------------------------
final class PreparedStatementQuery implements \Stringable
{
    public function __construct(public string $sql = '', public array $parameters = [])
    {
    }
    public function __toString(): string
    {
        return 'SQL: ' . $this->sql . '; Parameters: ' . json_encode($this->parameters);
    }
}

function ps_param_insert($count)
{
    return join(",", array_fill(0, $count, "?"));
}
function ps_param_fill(array $array, string $type): array
{
    $parameters = array();
    foreach ($array as $a) {
        $parameters[] = $type;
        $parameters[] = $a;
    }
    return $parameters;
}
function columns_in($table, $alias = null, $plugin = null, bool $return_list = false)
{
    return ($alias ?? $table) . '.*';
}
function sql_limit($offset, $rows)
{
    return '';
}
function clear_query_cache($cache)
{
}
function sql_insert_id()
{
    return 999999;
}
function db_begin_transaction($name)
{
}
function db_end_transaction($name)
{
}
function sql_limit_with_total_count(PreparedStatementQuery $query, int $rows, int $offset, bool $cachecount = false, ?PreparedStatementQuery $countquery = null)
{
    $GLOBALS['FAKE']['final_sql'] = $query;
    return array('total' => 0, 'data' => array());
}

/** Bound values of a ps_query parameter list (drops the type letters). */
function fake_values(array $params): array
{
    $values = array();
    for ($i = 1; $i < count($params); $i += 2) {
        $values[] = $params[$i];
    }
    return $values;
}

function fake_db(string $sql, array $params): array
{
    global $FAKE;
    $v = fake_values($params);
    $s = preg_replace('/\s+/', ' ', trim($sql));
    $FAKE['sql_log'][] = $s;

    // --- resource types
    if (stripos($s, 'FROM resource_type rt') !== false) {
        $rows = array();
        foreach ($FAKE['restypes'] as $ref => $name) {
            $rows[] = array('ref' => $ref, 'name' => $name, 'order_by' => $ref, 'tab_name' => '', 'resource_type_field' => '', 'push_metadata' => 0, 'colour' => 0, 'icon' => '', 'config_options' => '', 'allowed_extensions' => '', 'tab' => 0);
        }
        return $rows;
    }

    // --- resource_type_field lookups
    if (preg_match('/FROM resource_type_field( rtf| f)? .*WHERE (rtf\.|f\.)?name ?= ?\?/i', $s) || preg_match('/FROM resource_type_field WHERE `?name`? ?= ?\?/i', $s)) {
        $rows = array();
        foreach ($FAKE['fields'] as $f) {
            if ($f['name'] === (string)$v[0]) {
                if (preg_match('/type IN \(([0-9, ]+)\)/i', $s, $m)) {
                    $types = array_map('intval', explode(',', $m[1]));
                    if (!in_array($f['type'], $types, true)) {
                        continue;
                    }
                }
                $rows[] = $f + array('value' => $f['ref']);
            }
        }
        return $rows;
    }
    if (preg_match('/FROM resource_type_field rtf LEFT JOIN resource_type_field_resource_type rtfrt .*WHERE rtf\.ref = \?/i', $s)
        || preg_match('/FROM resource_type_field WHERE ref ?= ?\?/i', $s)) {
        $f = $FAKE['fields'][(int)$v[0]] ?? null;
        return $f === null ? array() : array($f);
    }
    if (preg_match('/select ref,active from resource_type_field where length\(name\)>0/i', $s)) {
        return array_values($FAKE['fields']);
    }
    if (preg_match('/SELECT ref FROM resource_type_field WHERE active = 1 AND \(keywords_index = 1/i', $s)) {
        return array_values(array_filter($FAKE['fields'], function ($f) {
            return $f['active'] == 1 && ($f['keywords_index'] || $f['partial_index'] || $f['complete_index']);
        }));
    }
    if (preg_match('/FROM resource_type_field rtf LEFT JOIN tab t ON t\.ref=rtf\.tab WHERE rtf\.ref IN/i', $s)) {
        $rows = array();
        foreach ($FAKE['fields'] as $f) {
            if (in_array($f['ref'], array_map('intval', $v), true)) {
                $rows[] = $f;
            }
        }
        usort($rows, function ($a, $b) {
            return $a['order_by'] <=> $b['order_by'];
        });
        return $rows;
    }
    if (preg_match('/select name from resource_type_field where type=7 or type=2 or type=3/i', $s)) {
        return array_values(array_filter($FAKE['fields'], function ($f) {
            return in_array($f['type'], array(7, 2, 3), true);
        }));
    }
    if (preg_match('/SELECT name value FROM resource_type_field where type=9/i', $s)) {
        $rows = array();
        foreach ($FAKE['fields'] as $f) {
            if ($f['type'] === 9) {
                $rows[] = array('value' => $f['name']);
            }
        }
        return $rows;
    }
    if (preg_match('/select name value from resource_type_field where ref=\?/i', $s)) {
        $f = $FAKE['fields'][(int)$v[0]] ?? null;
        return $f === null ? array() : array(array('value' => $f['name']));
    }

    // --- resource existence
    if (preg_match('/SELECT COUNT\(\*\) AS value FROM resource WHERE ref = \?/i', $s)) {
        return array(array('value' => ((int)$v[0] > 0 && (int)$v[0] < 1000) ? 1 : 0));
    }

    // --- keywords
    if (preg_match('/SELECT ref value FROM keyword WHERE keyword = \?/i', $s)) {
        $kw = (string)$v[0];
        if (in_array($kw, $FAKE['unknown_keywords'], true)) {
            return array();
        }
        $FAKE['keyword_refs'][$kw] = $FAKE['keyword_refs'][$kw] ?? (1000 + count($FAKE['keyword_refs'] ?? array()));
        return array(array('value' => $FAKE['keyword_refs'][$kw]));
    }
    if (stripos($s, 'FROM keyword_related') !== false || stripos($s, 'INNODB_FT_DEFAULT_STOPWORD') !== false) {
        return array();
    }
    if (preg_match('/FROM keyword WHERE (soundex|keyword LIKE)/i', $s)) {
        return array();
    }
    if (stripos($s, 'insert into keyword') !== false) {
        return array();
    }

    // --- nodes
    if (preg_match('/SELECT resource_type_field value FROM node WHERE ref = \?/i', $s)) {
        $n = $FAKE['nodes'][(int)$v[0]] ?? null;
        return $n === null ? array() : array(array('value' => $n['resource_type_field']));
    }
    if (preg_match('/FROM node WHERE resource_type_field=\?/i', $s)) {
        // get_nodes(): 7 language parameters first, then the field ref.
        $field = (int)$v[7];
        $rows = array();
        foreach ($FAKE['nodes'] as $n) {
            if ($n['resource_type_field'] === $field) {
                $rows[] = $n + array('translated_name' => $n['name']);
            }
        }
        return $rows;
    }
    if (preg_match('/FROM node WHERE ref ?= ?\?/i', $s) || preg_match('/FROM node n? ?WHERE (n\.)?ref ?= ?\?/i', $s)) {
        $n = $FAKE['nodes'][(int)$v[count($v) - 1]] ?? null;
        return $n === null ? array() : array($n + array('translated_name' => $n['name']));
    }

    // --- collections
    if (preg_match('/SELECT type value FROM collection WHERE ref = \?/i', $s)) {
        $c = $FAKE['collections'][(int)$v[0]] ?? null;
        return $c === null ? array() : array(array('value' => $c['type']));
    }
    if (preg_match('/SELECT savedsearch(, `type`)? ?(value)? FROM collection WHERE ref = \?/i', $s)) {
        $c = $FAKE['collections'][(int)$v[0]] ?? null;
        return $c === null ? array() : array(array('value' => $c['savedsearch'], 'savedsearch' => $c['savedsearch'], 'type' => $c['type']));
    }
    if (preg_match('/FROM collection c LEFT OUTER JOIN user u ON u\.ref = c\.user WHERE c\.ref = \?/i', $s)) {
        $c = $FAKE['collections'][(int)$v[0]] ?? null;
        return $c === null ? array() : array($c + array('fullname' => 'x', 'username' => 'x'));
    }
    if (stripos($s, 'SELECT LEFT(VERSION(), 3) AS ver') !== false) {
        return array(array('ver' => '8.0'));
    }
    if (stripos($s, 'WITH RECURSIVE cte') !== false) {
        $rows = array();
        foreach ($FAKE['collections'] as $c) {
            if ($c['type'] === 3) {
                $rows[] = array('ref' => $c['ref'], 'parent' => $c['parent']);
            }
        }
        return $rows;
    }
    if (preg_match('/FROM (user_collection|usergroup_collection|external_access_keys|user u,user_collection|usergroup u,usergroup_collection)/i', $s)) {
        return array();
    }

    // --- filters
    if (preg_match('/SELECT ref, name, filter_condition FROM filter f WHERE ref=\?/i', $s)) {
        $f = $FAKE['filters'][(int)$v[0]] ?? null;
        return $f === null ? array() : array($f);
    }
    if (preg_match('/FROM filter_rule fr LEFT JOIN filter_rule_node frn/i', $s)) {
        return $FAKE['filter_rules'][(int)$v[0]] ?? array();
    }

    // --- users (index_contributed_by)
    if (preg_match('/FROM user u/i', $s)) {
        return array();
    }

    $FAKE['unknown_sql'][] = substr($s, 0, 220);
    return array();
}

function ps_query($sql, array $parameters = array(), $cache = "", $fetchrows = -1, $dbstruct = true, $logthis = 2, $reconnect = true, $fetch_specific_columns = false)
{
    return fake_db((string)$sql, $parameters);
}
function ps_value($query, $parameters, $default, $cache = "")
{
    $rows = fake_db((string)$query, $parameters);
    return count($rows) === 0 || !array_key_exists('value', $rows[0]) ? $default : $rows[0]['value'];
}
function ps_array($query, $parameters = array(), $cache = "")
{
    $out = array();
    foreach (fake_db((string)$query, $parameters) as $row) {
        $out[] = $row['value'] ?? reset($row);
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Real core code
// ---------------------------------------------------------------------------------------------
include $ROOT . '/include/definitions.php';
include $ROOT . '/include/config.default.php';
include_once $ROOT . '/include/general_functions.php';
include_once $ROOT . '/include/debug_functions.php';
include_once $ROOT . '/include/language_functions.php';
include_once $ROOT . '/include/search_functions.php';
include_once $ROOT . '/include/do_search.php';
include_once $ROOT . '/include/node_functions.php';
include_once $ROOT . '/include/resource_functions.php';
include_once $ROOT . '/include/user_functions.php';
include_once $ROOT . '/include/collections_functions.php';
include_once $ROOT . '/include/migration_functions.php';

// Real plugin code (query pipeline only; the hook file is replaced by GlobalHookExternal_search below).
include $ROOT . '/plugins/typesense_search/config/config.php';
include_once $ROOT . '/plugins/typesense_search/include/typesense_search_functions.php';

// ---------------------------------------------------------------------------------------------
// Session context
// ---------------------------------------------------------------------------------------------
$plugins = array();
$pagename = 'search';
$language = 'en';
$lang = array('groupsmart' => 'Group', 'error_search_filter_invalid' => 'invalid filter');
$userref = 5;
$usergroup = 3;
$username = 'tester';
$userdata = array(array('search_filter' => '', 'search_filter_id' => 0, 'search_filter_override' => '', 'search_filter_o_id' => 0, 'edit_filter' => '', 'edit_filter_id' => 0));
$usersearchfilter = '';
$usereditfilter = '';
$userderestrictfilter = '';
$k = '';
$internal_share_access = false;
$storagedir = $SCRATCH . '/storage';
$debug_log = true;
$debug_log_location = $SCRATCH . '/debug_trace.txt';
$typesense_search_collection_prefix = 'ts_';
$USER_SELECTION_COLLECTION = 7;

// Standard user: search, view all fields except 93, view resource types, no "v".
$PERMS_STANDARD = array('s', 'f*', 'f-93', 'g', 'j*');
$PERMS_ADMIN = array('s', 'f*', 'g', 'v', 'a', 'j*', 'e0', 'e1', 'e2', 'e3', 'e-1', 'e-2', 't', 'h');
$userpermissions = $PERMS_STANDARD;

/** Records what core handed to the external_search hook and what the plugin would do with it. */
function GlobalHookExternal_search(
    $search, $keywords, $node_bucket, $node_bucket_not, $restypes, $order_by, $archive, $fetchrows, $sort,
    $access_override, $ignore_filters, $return_disk_usage, $recent_search_daylimit, $return_refs_only,
    $editable_only, $returnsql, $access, $smartsearch, $sql_filter, $sql_join, $select
) {
    global $HOOK;
    $HOOK = array(
        'reached' => true,
        'search' => $search,
        'keywords' => $keywords,
        'node_bucket' => $node_bucket,
        'node_bucket_not' => $node_bucket_not,
        'restypes' => $restypes,
        'order_by' => $order_by,
        'archive' => $archive,
        'core_filter' => $sql_filter->sql,
        'core_join' => $sql_join->sql,
    );

    $ctx = TypesenseSearchContext::fromHookArgs(array(
        'search' => $search, 'keywords' => $keywords, 'node_bucket' => $node_bucket,
        'node_bucket_not' => $node_bucket_not, 'restypes' => $restypes, 'order_by' => $order_by,
        'archive' => $archive, 'fetchrows' => $fetchrows, 'sort' => $sort,
        'access_override' => $access_override, 'ignore_filters' => $ignore_filters,
        'return_disk_usage' => $return_disk_usage, 'recent_search_daylimit' => $recent_search_daylimit,
        'return_refs_only' => $return_refs_only, 'editable_only' => $editable_only,
        'returnsql' => $returnsql, 'access' => $access, 'smartsearch' => $smartsearch, 'select' => $select,
    ));

    if ($ctx->returnsql || $ctx->return_disk_usage || $ctx->editable_only || $ctx->smartsearch) {
        $HOOK['plugin'] = 'CORE (hook guard: returnsql / disk usage / editable_only / smartsearch)';
        return false;
    }

    @unlink($GLOBALS['debug_log_location']);
    try {
        $plan = typesense_search_build_query($ctx);
    } catch (\Throwable $e) {
        $HOOK['plugin'] = 'PHP ERROR: ' . get_class($e) . ': ' . $e->getMessage();
        return false;
    }

    if ($plan === null) {
        $reason = '';
        if (is_file($GLOBALS['debug_log_location'])) {
            foreach (file($GLOBALS['debug_log_location']) as $line) {
                if (strpos($line, 'typesense_search: unsupported') !== false) {
                    $reason = trim(substr($line, strpos($line, 'typesense_search:')));
                }
            }
        }
        $HOOK['plugin'] = 'CORE (veto) - ' . $reason;
        return false;
    }

    $params = $plan->compileParams();
    $HOOK['plugin'] = 'SERVED';
    $HOOK['params'] = $params;
    $HOOK['plan'] = array(
        'offset' => $plan->offset, 'limit' => $plan->limit, 'result_limit' => $plan->result_limit,
        'recent_selection' => $plan->recent_selection, 'open_access' => $plan->open_access,
    );
    // Let core carry on, so its own SQL can be shown beside the plugin's request.
    return false;
}

/** Shorten core SQL: collapse whitespace and replace the boilerplate every search carries. */
function fake_compact(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', $sql);
    $boiler = array(
        " LEFT OUTER JOIN resource_custom_access rca2 ON r.ref=rca2.resource AND rca2.user = ? AND (rca2.user_expires IS null or rca2.user_expires>now()) AND rca2.access<>2 LEFT OUTER JOIN resource_custom_access rca ON r.ref=rca.resource AND rca.usergroup = ? AND rca.access<>2" => ' [rca joins]',
        " JOIN resource_type AS rty ON r.resource_type = rty.ref" => '',
        "(r.access<>'2' OR (r.access=2 AND ((rca.access IS NOT null AND rca.access<>2) OR (rca2.access IS NOT null AND rca2.access<>2))))" => '[access2 rule]',
        "NOT (rca.resource IS null AND r.access=3)" => '[access3 rule]',
        "(((r.archive<>-2 OR r.created_by = ?) AND (r.archive<>-1 OR r.created_by = ?)) )" => '[pending rule]',
    );
    return str_replace(array_keys($boiler), array_values($boiler), $sql);
}

/**
 * Run one search through core + plugin and print a compact trace.
 * Options: every do_search() argument by name, plus 'sql' => true to print core's full SQL.
 */
function trace(string $label, string $search, array $opt = array()): array
{
    global $HOOK, $FAKE;

    // Reset per-request caches.
    foreach (array('hidden_fields_cache', 'visible_indexed_fields_cache', 'fieldinfo_cache', 'datefieldinfo_cache', 'resolve_keyword_cache', 'related_keywords_cache', 'restype_cache', 'CACHE_FC_ACCESS_CONTROL', 'CACHE_FC_PERMS_FILTER_SQL', 'smartsearch_ref_cache', 'get_collection_cache', 'keysearch') as $g) {
        unset($GLOBALS[$g]);
    }
    $FAKE['unknown_sql'] = array();
    $FAKE['final_sql'] = null;
    $FAKE['keyword_refs'] = array();
    $HOOK = array('reached' => false);

    $o = $opt + array(
        'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 48), 'sort' => 'DESC',
        'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
        'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
    );

    ob_start();
    try {
        $result = do_search(
            $search, $o['restypes'], $o['order_by'], $o['archive'], $o['fetchrows'], $o['sort'], $o['access_override'],
            DEPRECATED_STARSEARCH, $o['ignore_filters'], $o['return_disk_usage'], $o['daylimit'], false, false,
            $o['return_refs_only'], $o['editable_only'], $o['returnsql'], $o['access'], $o['smartsearch']
        );
    } catch (\Throwable $e) {
        $result = 'PHP ERROR in core: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $echoed = ob_get_clean();

    $shown = array();
    foreach (array('restypes', 'order_by', 'archive', 'sort', 'fetchrows', 'access_override', 'ignore_filters', 'daylimit', 'access', 'smartsearch', 'editable_only', 'return_refs_only', 'return_disk_usage', 'returnsql') as $key) {
        if (array_key_exists($key, $opt)) {
            $shown[] = $key . '=' . json_encode($opt[$key]);
        }
    }
    $J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    echo "\n=== " . $label . ":  " . json_encode($search, $J) . (count($shown) ? '   [' . implode(', ', $shown) . ']' : '') . "\n";

    if (!$HOOK['reached']) {
        echo "    CORE RETURNED BEFORE THE HOOK: " . json_encode($result, $J) . ($echoed !== '' ? '  (output: ' . trim($echoed) . ')' : '') . "\n";
        return $HOOK;
    }

    echo "    hook   search=" . json_encode($HOOK['search'], $J) . " keywords=" . json_encode($HOOK['keywords'], $J)
        . (count($HOOK['node_bucket']) ? " node_bucket=" . json_encode($HOOK['node_bucket']) : '')
        . (count($HOOK['node_bucket_not']) ? " node_not=" . json_encode($HOOK['node_bucket_not']) : '') . "\n";

    // ---- core summary
    $core_filter = fake_compact($HOOK['core_filter']);
    $core_join = trim(fake_compact($HOOK['core_join']));
    $final = $FAKE['final_sql'] instanceof PreparedStatementQuery ? fake_compact($FAKE['final_sql']->sql) : null;
    $features = array();
    $all = $core_join . ' ' . $core_filter . ' ' . (string)$final;
    foreach (array(
        'position=' => 'PHRASE(adjacent positions, same node)',
        'MATCH(name) AGAINST' => 'FULLTEXT',
        'k.keyword LIKE' => 'LIKE-on-keyword',
        'RLIKE' => 'RLIKE-on-node-name',
        'NOT IN (SELECT `resource` FROM `resource_node` JOIN `node_keyword`' => 'OMIT(keyword in any field)',
        'qfilter' => 'OMIT-PHRASE',
        'rt.name LIKE' => 'UNION all resources of that resource type',
        'AS resource, TRUE AS `keyword_1_found`, 1 AS score)' => 'UNION resource ref',
        'r.created_by IN' => 'UNION contributed-by user',
        'OR nk' => 'OR/related keywords',
        'resource_type_field = ?)' => 'field-restricted',
        '.name like ?' => 'date LIKE',
        'drn' => 'date-range joins',
        'rnn' => 'numrange',
        'JOIN `resource_node` rn' => 'node-bucket joins',
        'NOT EXISTS (SELECT `resource`, node FROM `resource_node`' => 'node NOT',
        'r.ref NOT IN ( SELECT rn.resource' => 'EMPTY-field',
    ) as $needle => $name) {
        if (strpos($all, $needle) !== false) {
            $features[] = $name;
        }
    }
    $unions = substr_count($core_join, 'AS `keyword_1_found`');
    echo "    CORE   " . ($unions > 0 ? $unions . ' keyword union(s); ' : 'no keyword union; ')
        . 'keywords looked up=' . json_encode(array_keys($FAKE['keyword_refs']), $J)
        . (count($features) ? '; ' . implode(', ', $features) : '') . "\n";
    echo "           filter: " . $core_filter . "\n";
    if ($final !== null) {
        if (preg_match('/ORDER BY (.*)$/', $final, $m)) {
            echo "           order:  " . $m[1] . "\n";
        }
    } else {
        echo "           result: " . json_encode($result, $J) . "\n";
    }
    if (!empty($opt['sql'])) {
        echo "           join:   " . $core_join . "\n";
        if ($final !== null) {
            echo "           SQL:    " . preg_replace('/SELECT (DISTINCT )?.*? FROM resource r/', 'SELECT … FROM resource r', $final, 1) . "\n";
        }
    }

    // ---- plugin summary
    echo "    PLUGIN " . $HOOK['plugin'] . "\n";
    if (isset($HOOK['params'])) {
        $p = $HOOK['params'];
        $filter = $p['filter_by'] ?? '';
        $filter = preg_replace('/\(access:!=2 \|\| \$ts_resource_access_grants\(\(user:=5 && \(expires:=0 \|\| expires:>\d+\)\) \|\| usergroup:=3\)\) && \(access:!=3 \|\| \$ts_resource_access_grants\(usergroup:=3\)\)/', '[grants]', $filter);
        $filter = str_replace('(archive:!=[-2,-1] || created_by:=5)', '[pending]', $filter);
        $default_query_by = 'title,ref_s,field_18_text,field_12_q,field_3_ss,field_1_ss,field_73_ss,field_51_s,field_51_p,field_90_q,field_91_q,field_92_text,field_95_q';
        echo "           q=" . json_encode($p['q'], $J) . (isset($p['prefix']) ? ' prefix=' . $p['prefix'] : '')
            . ((($p['query_by'] ?? '') === $default_query_by) ? '' : ' query_by=' . ($p['query_by'] ?? '(none)')) . "\n";
        echo "           filter_by: " . $filter . "\n";
        $plan = array_filter($HOOK['plan'], function ($x) {
            return $x !== null && $x !== false;
        });
        echo "           sort_by: " . ($p['sort_by'] ?? '(default)') . "   " . json_encode($plan) . "\n";
    }
    if (count($FAKE['unknown_sql']) > 0) {
        foreach (array_unique($FAKE['unknown_sql']) as $u) {
            echo "    (unstubbed SQL: " . $u . ")\n";
        }
    }

    return $HOOK;
}
