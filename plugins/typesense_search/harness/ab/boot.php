<?php

/**
 * A/B harness boot.
 *
 * Core side:   ResourceSpace's real do_search() and friends, on one of two database drivers:
 *              SQLite (default, db_sqlite.php): an in-memory database built from core's dbstruct/ and the plugin's
 *              stands in for MySQL; only the driver is replaced (ps_query / ps_value / ps_array and helpers), the SQL
 *              is translated where SQLite differs (harness_translate()), and full text and RLIKE are emulated, every
 *              case that used them saying so.
 *              MySQL (HARNESS_DB=mysql, db_mysql.php): core's own driver and SQL against a real MySQL 8, the
 *              database created by core's check_db_structs(); nothing is emulated.
 * Plugin side: the typesense_search plugin's real code against a private local Typesense, started here when
 *              nothing answers on the harness port and typesense-server is on the PATH.
 *
 * Environment: HARNESS_ROOT (the checkout under test, default this one), HARNESS_SCRATCH (generated files),
 * HARNESS_TS_HOST / HARNESS_TS_PORT / HARNESS_TS_KEY (the private Typesense), HARNESS_DB (the driver: "mysql", or a
 * file for the SQLite database instead of memory so that a second process can share it), and for the MySQL driver
 * HARNESS_MYSQL_HOST / _PORT / _USER / _PASSWORD / _DATABASE (see db_mysql.php).
 *
 * Exit codes of a battery: 0 green, 1 a failed case, 2 skipped because no local Typesense could be reached.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');
define('RS_TEST_MODE', true); // so field permissions are honoured on the command line

$ROOT = getenv('HARNESS_ROOT') ?: dirname(__DIR__, 4);
$SCRATCH = getenv('HARNESS_SCRATCH') ?: sys_get_temp_dir() . '/rs_typesense_search_harness';
if (!is_dir($SCRATCH)) {
    mkdir($SCRATCH, 0700, true);
}

$HARNESS = array(
    'mode' => getenv('HARNESS_DB') === 'mysql' ? 'mysql' : 'sqlite',
    'sql_errors' => array(),
    'emulated' => array(),
    'log_sql' => false,
    'sql_log' => array(),
    'tally' => array('pass' => 0, 'fail' => 0, 'served' => 0, 'declined' => 0, 'not_run' => 0, 'not_consulted' => 0),
    'ts' => array(
        'host' => getenv('HARNESS_TS_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('HARNESS_TS_PORT') ?: 18208),
        'key' => getenv('HARNESS_TS_KEY') ?: 'local-harness-key',
    ),
);

// ---------------------------------------------------------------------------------------------
// The private Typesense
// ---------------------------------------------------------------------------------------------

/** One request to the private Typesense: array(HTTP status, decoded JSON or the raw body). */
function harness_ts(string $method, string $path, $body = null, bool $jsonl = false): array
{
    global $HARNESS;

    $curl = curl_init('http://' . $HARNESS['ts']['host'] . ':' . $HARNESS['ts']['port'] . $path);
    curl_setopt_array($curl, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array(
            'X-TYPESENSE-API-KEY: ' . $HARNESS['ts']['key'],
            'Content-Type: ' . ($jsonl ? 'text/plain' : 'application/json'),
        ),
    ));
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonl ? $body : json_encode($body));
    }
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($response === false) {
        return array(0, curl_error($curl));
    }
    $decoded = $jsonl ? null : json_decode($response, true);
    return array($status, $decoded ?? $response);
}

function harness_typesense_healthy(): bool
{
    list($status, $body) = harness_ts('GET', '/health');
    return $status === 200 && is_array($body) && ($body['ok'] ?? false) === true;
}

/** Start a private typesense-server for the harness when nothing answers; leave it running between batteries. */
function harness_typesense_start(): bool
{
    global $HARNESS, $SCRATCH;

    if (harness_typesense_healthy()) {
        return true;
    }
    if ($HARNESS['ts']['host'] !== '127.0.0.1') {
        return false;
    }
    exec('command -v typesense-server 2>/dev/null', $found, $code);
    if ($code !== 0 || count($found) === 0) {
        return false;
    }
    $data = $SCRATCH . '/typesense-data';
    if (!is_dir($data)) {
        mkdir($data, 0700, true);
    }
    $command = sprintf(
        'nohup %s --data-dir=%s --api-key=%s --api-address=127.0.0.1 --api-port=%d --peering-address=127.0.0.1 --peering-port=%d > %s 2>&1 &',
        escapeshellarg($found[0]),
        escapeshellarg($data),
        escapeshellarg($HARNESS['ts']['key']),
        $HARNESS['ts']['port'],
        $HARNESS['ts']['port'] - 1,
        escapeshellarg($SCRATCH . '/typesense.log')
    );
    exec($command);
    for ($i = 0; $i < 40; $i++) {
        usleep(500000);
        if (harness_typesense_healthy()) {
            return true;
        }
    }
    return false;
}

if (!harness_typesense_start()) {
    echo 'SKIPPED: no Typesense on ' . $HARNESS['ts']['host'] . ':' . $HARNESS['ts']['port']
        . ($HARNESS['ts']['host'] === '127.0.0.1' ? ' and typesense-server is not on the PATH' : '') . "\n";
    exit(2);
}

// ---------------------------------------------------------------------------------------------
// The database driver
// ---------------------------------------------------------------------------------------------
require __DIR__ . '/db_' . $HARNESS['mode'] . '.php';

/** The database behind core's SQL, for messages: SQLite or MySQL. */
function harness_mode_name(): string
{
    global $HARNESS;

    return $HARNESS['mode'] === 'mysql' ? 'MySQL' : 'SQLite';
}

/** The first column of the first row of a query (null when there is none), in either mode. */
function harness_scalar(string $sql, array $parameters = array())
{
    $rows = harness_run($sql, $parameters);
    return count($rows) === 0 ? null : reset($rows[0]);
}

// ---------------------------------------------------------------------------------------------
// Real core + plugin code
// ---------------------------------------------------------------------------------------------
$baseurl = 'http://localhost';
$baseurl_short = '/';
include $ROOT . '/include/definitions.php';
include $ROOT . '/include/config.default.php';
foreach (glob($ROOT . '/include/*_functions.php') as $f) {
    if (basename($f) !== 'database_functions.php') {
        include_once $f;
    }
}
include_once $ROOT . '/include/do_search.php';

// The plugin, loaded as core loads it: its configuration, then hooks/all.php (which requires the functions loader).
include $ROOT . '/plugins/typesense_search/config/config.php';
$typesense_search_host = $HARNESS['ts']['host'];
$typesense_search_port = $HARNESS['ts']['port'];
$typesense_search_protocol = 'http';
$typesense_search_search_key = $HARNESS['ts']['key']; // the private server has one bootstrap key; both roles use it
$typesense_search_admin_key = $HARNESS['ts']['key'];
$typesense_search_prefix = 'harness';                 // the harness's own tenant on the shared local server
$typesense_search_enabled = true;
include_once $ROOT . '/plugins/typesense_search/hooks/all.php';

// ---------------------------------------------------------------------------------------------
// Session context
// ---------------------------------------------------------------------------------------------
$plugins = array();   // the plugin is driven through GlobalHookExternal_search below
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
$debug_log_location = $SCRATCH . '/debug.txt';
$USER_SELECTION_COLLECTION = 7;
$userpermissions = array('s', 'f*', 'f-93', 'g', 'j*');

$USE_PLUGIN = false;
$PLUGIN_STATE = array();

harness_db_ready();

/**
 * The plugin's one log line per search, read back from the debug file. Contract with the search hook (D1, G2):
 *   typesense_search: outcome=<served|declined|failed> ... reason=<free text to the end of the line>
 */
function harness_search_log_line(): array
{
    global $debug_log_location;

    $found = array('outcome' => null, 'reason' => null);
    if (!is_file($debug_log_location)) {
        return $found;
    }
    foreach (file($debug_log_location) as $line) {
        if (preg_match('/typesense_search: outcome=(\w+)(?:.*? reason=(.*))?$/', trim($line), $m)) {
            $found = array('outcome' => $m[1], 'reason' => isset($m[2]) ? trim($m[2]) : null);
        }
    }
    return $found;
}

function GlobalHookExternal_search(...$args)
{
    global $USE_PLUGIN, $PLUGIN_STATE, $HARNESS, $debug_log_location;

    if (!$USE_PLUGIN) {
        return false;
    }
    $PLUGIN_STATE['hook_reached'] = true;
    $PLUGIN_STATE['keywords'] = $args[1];
    $PLUGIN_STATE['args'] = $args;
    // A canned answer in place of the plugin, for the batteries that prove core's handling of the hook (I1).
    if (array_key_exists('hook_stub', $HARNESS)) {
        $PLUGIN_STATE['served'] = $HARNESS['hook_stub'] !== false;
        return $HARNESS['hook_stub'];
    }
    if (!function_exists('HookTypesense_searchAllExternal_search')) {
        $PLUGIN_STATE['no_hook'] = true;
        return false;
    }
    @unlink($debug_log_location);
    try {
        $result = HookTypesense_searchAllExternal_search(...$args);
    } catch (\Throwable $e) {
        // Drop the ", called in <path>" tail so that output does not depend on where the harness lives.
        $PLUGIN_STATE['error'] = get_class($e) . ': ' . preg_replace('/, called in .*$/s', '', $e->getMessage());
        return false;
    }
    $PLUGIN_STATE['served'] = $result !== false;
    $PLUGIN_STATE += harness_search_log_line();
    return $result;
}

/** Refs and total of a do_search() result in either shape. */
function harness_refs($result): array
{
    if (!is_array($result)) {
        return array('total' => null, 'refs' => array(), 'raw' => $result);
    }
    $rows = $result['data'] ?? $result;
    $refs = array();
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['ref'])) {
            $refs[] = (int)$row['ref'];
        }
    }
    return array('total' => $result['total'] ?? count($rows), 'refs' => $refs, 'raw' => null);
}

function harness_reset_caches(): void
{
    foreach (array('hidden_fields_cache', 'visible_indexed_fields_cache', 'fieldinfo_cache', 'datefieldinfo_cache', 'resolve_keyword_cache', 'related_keywords_cache', 'restype_cache', 'CACHE_FC_ACCESS_CONTROL', 'CACHE_FC_PERMS_FILTER_SQL', 'smartsearch_ref_cache', 'get_collection_cache', 'keysearch', 'collection_readable_key_check_cache') as $g) {
        unset($GLOBALS[$g]);
    }
}

function harness_search(string $search, array $o)
{
    ob_start();
    try {
        $result = do_search(
            $search,
            $o['restypes'],
            $o['order_by'],
            $o['archive'],
            $o['fetchrows'],
            $o['sort'],
            $o['access_override'],
            DEPRECATED_STARSEARCH,
            $o['ignore_filters'],
            $o['return_disk_usage'],
            $o['daylimit'],
            false,
            false,
            $o['return_refs_only'],
            $o['editable_only'],
            $o['returnsql'],
            $o['access'],
            $o['smartsearch']
        );
    } catch (\Throwable $e) {
        $result = 'PHP ERROR: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    $out = ob_get_clean();
    return array($result, $out);
}

/** The accepted-divergence list, one entry per case label: kind (order | declined | core-bug), reason, decision. */
function harness_accepted(string $label): ?array
{
    static $list = null;
    if ($list === null) {
        $list = require __DIR__ . '/accepted_divergences.php';
    }
    return $list[$label] ?? null;
}

function harness_tally(string $key): void
{
    global $HARNESS;
    $HARNESS['tally'][$key]++;
}

/** A non-search assertion: "CHECK <label>: ok|FAIL <detail>". */
function harness_check(string $label, bool $ok, string $detail = ''): bool
{
    echo 'CHECK ' . $label . ': ' . ($ok ? 'ok' : 'FAIL') . ($detail !== '' ? ' ' . $detail : '') . "\n";
    harness_tally($ok ? 'pass' : 'fail');
    return $ok;
}

/**
 * Run a search with the plugin off (core on SQLite) and on (the plugin's hook), compare, and apply the
 * acceptance rule (Q10.3). $case may carry: expect (the refs core must return, derived by hand), expect_order
 * (the same, in order, for an explicit sort), total (when it differs from count(expect)), security (true:
 * never listed), note.
 */
function ab(string $label, string $search, array $opt = array(), array $case = array()): array
{
    global $USE_PLUGIN, $PLUGIN_STATE, $HARNESS;

    $o = $opt + array(
        'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 200), 'sort' => 'DESC',
        'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
        'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
    );

    $HARNESS['sql_errors'] = array();
    $HARNESS['emulated'] = array();
    harness_reset_caches();
    $USE_PLUGIN = false;
    list($core_result, $core_out) = harness_search($search, $o);
    $core = harness_refs($core_result);
    $core_errors = $HARNESS['sql_errors'];
    $emulated = array_keys($HARNESS['emulated']);

    $HARNESS['sql_errors'] = array();
    harness_reset_caches();
    $USE_PLUGIN = true;
    $PLUGIN_STATE = array('hook_reached' => false, 'served' => false, 'no_hook' => false, 'outcome' => null, 'reason' => null);
    list($plugin_result, $plugin_out) = harness_search($search, $o);
    $plugin = harness_refs($plugin_result);
    $USE_PLUGIN = false;

    $shown = array();
    foreach (array('restypes', 'order_by', 'archive', 'sort', 'fetchrows', 'access_override', 'ignore_filters', 'daylimit', 'access', 'return_refs_only', 'editable_only') as $key) {
        if (array_key_exists($key, $opt)) {
            $shown[] = $key . '=' . json_encode($opt[$key]);
        }
    }
    $J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $fmt = function (array $r) use ($J) {
        if ($r['raw'] !== null || $r['total'] === null) {
            return 'non-array result ' . json_encode($r['raw'], $J);
        }
        $sorted = $r['refs'];
        sort($sorted);
        return 'total=' . $r['total'] . ' refs=' . json_encode($sorted) . ($sorted === $r['refs'] ? '' : '  (order ' . json_encode($r['refs']) . ')');
    };

    // The core side against the hand-derived expectation.
    $problems = array();
    if (isset($case['expect']) || isset($case['expect_order'])) {
        $expected = $case['expect'] ?? $case['expect_order'];
        $expected_total = $case['total'] ?? count($expected);
        $sorted_expected = $expected;
        sort($sorted_expected);
        $sorted_core = $core['refs'];
        sort($sorted_core);
        if ($core['raw'] !== null || $sorted_core !== $sorted_expected || (int)$core['total'] !== $expected_total) {
            $problems[] = 'core differs from the expected set ' . json_encode($sorted_expected) . ' total=' . $expected_total;
        } elseif (isset($case['expect_order']) && $core['refs'] !== $case['expect_order']) {
            $problems[] = 'core order differs from the expected order ' . json_encode($case['expect_order']);
        }
    }

    // The plugin side.
    $explicit_sort = strtolower((string)$o['order_by']) !== 'relevance';
    $keywordless = isset($PLUGIN_STATE['keywords']) && is_array($PLUGIN_STATE['keywords'])
        ? count($PLUGIN_STATE['keywords']) === 0
        : trim(preg_replace('/(^|[\s,])(!|@@)\S*/', '', $search)) === '';
    $order_matters = $explicit_sort || $keywordless;
    $accepted = empty($case['security']) ? harness_accepted($label) : null;
    $listed = '';

    if (!$PLUGIN_STATE['hook_reached']) {
        $how = 'not consulted (core returned before the hook)';
        harness_tally('not_consulted');
    } elseif ($PLUGIN_STATE['no_hook']) {
        $how = 'not run (the plugin has no search hook yet)';
        harness_tally('not_run');
    } elseif (isset($PLUGIN_STATE['error'])) {
        $how = 'PHP ERROR ' . $PLUGIN_STATE['error'];
        $problems[] = 'the plugin threw';
    } elseif ($PLUGIN_STATE['served']) {
        harness_tally('served');
        $how = 'served  ' . $fmt($plugin);
        $a = $core['refs'];
        $b = $plugin['refs'];
        sort($a);
        sort($b);
        $same_set = ($a === $b) && $core['total'] === $plugin['total'] && $core['raw'] === $plugin['raw'];
        if (!$same_set) {
            if ($accepted !== null && $accepted['kind'] === 'core-bug') {
                $listed = 'core-bug, expected to differ: ' . $accepted['reason'];
            } else {
                $problems[] = 'set or total differs from core';
            }
        } elseif ($core['refs'] !== $plugin['refs'] && $order_matters) {
            if ($accepted !== null && $accepted['kind'] === 'order') {
                $listed = 'order difference listed: ' . $accepted['reason'];
            } else {
                $problems[] = 'order differs from core under ' . ($explicit_sort ? 'an explicit sort' : 'keyword-less relevance');
            }
        } elseif ($accepted !== null && $accepted['kind'] === 'core-bug') {
            $problems[] = 'listed as a core bug but the plugin agrees with core: the list is stale';
        }
    } else {
        harness_tally('declined');
        $reason = $PLUGIN_STATE['reason'] ?? '';
        $how = 'declined (' . ($PLUGIN_STATE['outcome'] ?? 'no log line') . ($reason !== '' ? ': ' . $reason : '') . ')';
        if ($accepted !== null && $accepted['kind'] === 'declined' && $accepted['reason'] === $reason) {
            $listed = 'declined as planned: ' . $accepted['decision'];
        } else {
            $problems[] = $accepted === null ? 'declined without a listed reason' : 'declined with a reason other than the listed one';
        }
    }

    $verdict = count($problems) === 0 ? 'PASS' . ($listed !== '' ? ' (' . $listed . ')' : '') : 'FAIL: ' . implode('; ', $problems);
    harness_tally(count($problems) === 0 ? 'pass' : 'fail');

    echo "\n" . $label . ':  ' . json_encode($search, $J) . (count($shown) ? '  [' . implode(', ', $shown) . ']' : '') . "\n";
    echo '    core:   ' . $fmt($core) . (count($emulated) ? '  (core side emulated: ' . implode(', ', $emulated) . ')' : '') . "\n";
    echo '    plugin: ' . $how . "\n";
    echo '    => ' . $verdict . (isset($case['note']) ? '  [' . $case['note'] . ']' : '') . "\n";
    foreach (array_unique($core_errors) as $err) {
        echo '    (core SQL did not run on ' . harness_mode_name() . ': ' . substr($err, 0, 260) . ")\n";
    }
    foreach (array_unique($HARNESS['sql_errors']) as $err) {
        if (!in_array($err, $core_errors, true)) {
            echo '    (SQL error on plugin run: ' . substr($err, 0, 260) . ")\n";
        }
    }

    return array('core' => $core, 'plugin' => $plugin, 'state' => $PLUGIN_STATE, 'verdict' => $verdict);
}

/** Print the battery's result line and exit 0 (green) or 1 (a failure). */
function harness_finish(string $battery): void
{
    global $HARNESS;

    $t = $HARNESS['tally'];
    echo "\nRESULT: " . $battery . ' pass=' . $t['pass'] . ' fail=' . $t['fail']
        . ' served=' . $t['served'] . ' declined=' . $t['declined'] . ' not_run=' . $t['not_run'] . ' not_consulted=' . $t['not_consulted'] . "\n";
    exit($t['fail'] > 0 ? 1 : 0);
}
