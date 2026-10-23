<?php

/**
 * A/B harness boot.
 *
 * Core side:   ResourceSpace's real do_search() and friends; the SQL they generate runs on an in-memory SQLite
 *              database (tables from dbstruct/) standing in for MySQL.
 * Plugin side: the typesense_search plugin's real code against a private local Typesense, started here when
 *              nothing answers on the harness port and typesense-server is on the PATH.
 *
 * Only the database driver is replaced (ps_query / ps_value / ps_array and helpers). Where SQLite differs from
 * MySQL the SQL is translated (harness_translate()); full text and RLIKE are emulated and every case that used
 * them says so.
 *
 * Environment: HARNESS_ROOT (the checkout under test, default this one), HARNESS_SCRATCH (generated files),
 * HARNESS_TS_HOST / HARNESS_TS_PORT / HARNESS_TS_KEY (the private Typesense), HARNESS_DB (a file for the SQLite
 * database instead of memory, so that a second process can share it).
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
    echo 'SKIPPED: no local Typesense on ' . $HARNESS['ts']['host'] . ':' . $HARNESS['ts']['port']
        . " and typesense-server is not on the PATH\n";
    exit(2);
}

// ---------------------------------------------------------------------------------------------
// Pieces of include/database_functions.php that do not touch the connection, copied verbatim
// ---------------------------------------------------------------------------------------------
$db_src = file_get_contents($ROOT . '/include/database_functions.php');

function harness_extract(string $src, string $signature): string
{
    $start = strpos($src, $signature);
    if ($start === false) {
        throw new RuntimeException('not found: ' . $signature);
    }
    $open = strpos($src, '{', $start);
    $depth = 0;
    for ($i = $open; $i < strlen($src); $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('unbalanced: ' . $signature);
}

$parts = "<?php\n";
foreach (
    array(
    'final class PreparedStatementQuery',
    'function sql_limit(',
    'function sql_limit_with_total_count(',
    'function ps_param_insert(',
    'function ps_param_fill(',
    'function columns_in(',
    ) as $sig
) {
    $parts .= harness_extract($db_src, $sig) . "\n\n";
}
// columns_in() locates dbstruct/ relative to its own file.
$parts = str_replace('dirname(__DIR__)', var_export($ROOT, true), $parts);
file_put_contents($SCRATCH . '/db_real_parts.php', $parts);
require $SCRATCH . '/db_real_parts.php';

// ---------------------------------------------------------------------------------------------
// SQLite standing in for MySQL
// ---------------------------------------------------------------------------------------------
// In memory unless HARNESS_DB names a file: a second process then boots on the same file (A6's two-writer case)
// and finds the tables there, so every CREATE is IF NOT EXISTS and a locked file is waited for.
$DB = new SQLite3(getenv('HARNESS_DB') ?: ':memory:');
$DB->enableExceptions(true);
$DB->busyTimeout(5000);
$DB->exec("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
$DB->exec("CREATE TABLE INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD (value TEXT)");
foreach (array('a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','the','www') as $w) {
    $DB->exec("INSERT INTO INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD VALUES ('" . $w . "')");
}

$DB->createFunction('now', function () {
    return date('Y-m-d H:i:s');
}, 0);
$DB->createFunction('curdate', function () {
    return date('Y-m-d');
}, 0);
$DB->createFunction('rs_days_ago', function ($n) {
    return date('Y-m-d 00:00:00', strtotime('today -' . (int)$n . ' days'));
}, 1);
$DB->createFunction('LEAST', function (...$a) {
    return in_array(null, $a, true) ? null : min($a);
}, -1);
$DB->createFunction('CONCAT_WS', function ($sep, ...$a) {
    return implode($sep, array_filter($a, function ($x) {
        return $x !== null;
    }));
}, -1);
$DB->createFunction('CONCAT', function (...$a) {
    return in_array(null, $a, true) ? null : implode('', $a);
}, -1);
$DB->createFunction('UNIX_TIMESTAMP', function ($d = null) {
    return $d === null ? null : strtotime($d);
}, 1);
$DB->createFunction('LEFT', function ($s, $n) {
    return $s === null ? null : mb_substr((string)$s, 0, (int)$n);
}, 2);
$DB->createFunction('VERSION', function () {
    return '8.0.36';
}, 0);
$DB->createFunction('RAND', function () {
    return mt_rand() / mt_getrandmax();
}, 0);
// X REGEXP Y calls regexp(Y, X). MySQL's default collations are case-insensitive. EMULATION of RLIKE.
$DB->createFunction('regexp', function ($pattern, $value) {
    return $value === null ? null : (int)(preg_match('~' . str_replace('~', '\~', (string)$pattern) . '~iu', (string)$value) === 1);
}, 2);
$DB->createAggregate('BIT_OR', function ($ctx, $row, $v) {
    return ((int)$ctx) | ((int)$v);
}, function ($ctx) {
    return (int)$ctx;
}, 1);

/**
 * Emulation of MySQL InnoDB "MATCH(name) AGAINST (expr IN BOOLEAN MODE)" for the expressions core builds:
 * +word (required), -word (excluded), word (optional), trailing * (prefix), "a phrase", (groups).
 * InnoDB does not index words shorter than 3 characters or its stop words. THIS IS AN EMULATION (H9 is the proof).
 */
function harness_ft_match($text, $expr)
{
    if ($text === null) {
        return 0;
    }
    static $stop = array('a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','www');
    $words = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower((string)$text), -1, PREG_SPLIT_NO_EMPTY);
    $words = array_values(array_filter($words, function ($w) use ($stop) {
        return mb_strlen($w) >= 3 && !in_array($w, $stop, true);
    }));

    $expr = trim((string)$expr, "'");
    preg_match_all('/([+\-]?)("[^"]*"|[^\s()]+)/u', $expr, $m, PREG_SET_ORDER);
    $required_ok = true;
    $optional_hit = false;
    $has_required = false;
    $has_optional = false;
    foreach ($m as $t) {
        $op = $t[1];
        $term = mb_strtolower($t[2]);
        if ($term !== '' && $term[0] === '"') {
            $phrase = preg_split('/[^\p{L}\p{N}_]+/u', trim($term, '"'), -1, PREG_SPLIT_NO_EMPTY);
            $hit = $phrase !== array() && strpos(' ' . implode(' ', $words) . ' ', ' ' . implode(' ', $phrase) . ' ') !== false;
        } else {
            $prefix = substr($term, -1) === '*';
            $term = rtrim($term, '*');
            // InnoDB tokenises the search term too; use its last word part for the match.
            $term_parts = preg_split('/[^\p{L}\p{N}_]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
            $hit = $term_parts !== array();
            foreach ($term_parts as $i => $part) {
                $is_last = $i === count($term_parts) - 1;
                $found = false;
                foreach ($words as $w) {
                    if (($prefix && $is_last) ? strpos($w, $part) === 0 : $w === $part) {
                        $found = true;
                        break;
                    }
                }
                $hit = $hit && $found;
            }
        }
        if ($op === '+') {
            $has_required = true;
            $required_ok = $required_ok && $hit;
        } elseif ($op === '-') {
            if ($hit) {
                return 0;
            }
        } else {
            $has_optional = true;
            $optional_hit = $optional_hit || $hit;
        }
    }
    if ($has_required) {
        return (int)$required_ok;
    }
    return (int)($has_optional && $optional_hit);
}
$DB->createFunction('ft_match', 'harness_ft_match', 2);

// Tables from core's dbstruct/ and the plugin's, as CheckDBStruct() creates them: the PRI columns form the primary
// key (an auto_increment column alone, else the composite key of a link or state table), defaults are kept.
$table_files = array_merge(
    glob($ROOT . '/dbstruct/table_*.txt') ?: array(),
    glob($ROOT . '/plugins/typesense_search/dbstruct/table_*.txt') ?: array()
);
foreach ($table_files as $file) {
    $table = substr(basename($file, '.txt'), 6);
    $cols = array();
    $primary = array();
    $auto_increment = false;
    foreach (explode("\n", trim(file_get_contents($file))) as $line) {
        $c = str_getcsv($line, ',', '"', '\\');
        if (count($c) < 2 || $c[0] === '') {
            continue;
        }
        // MySQL's default collations compare text case-insensitively; NOCASE does so for ASCII (accents are not emulated).
        $type = stripos($c[1], 'int') !== false ? 'INTEGER'
            : (preg_match('/float|double|decimal/i', $c[1]) ? 'REAL' : 'TEXT COLLATE NOCASE');
        $def = '`' . $c[0] . '` ' . $type;
        if (($c[3] ?? '') === 'PRI' && stripos($c[5] ?? '', 'auto_increment') !== false) {
            $def .= ' PRIMARY KEY AUTOINCREMENT';
            $auto_increment = true;
        } else {
            if (($c[3] ?? '') === 'PRI') {
                $primary[] = '`' . $c[0] . '`';
            }
            if (isset($c[4]) && $c[4] !== '' && strtoupper($c[4]) !== 'NULL') {
                $def .= ' DEFAULT ' . (is_numeric($c[4]) || strtoupper($c[4]) === 'CURRENT_TIMESTAMP'
                    ? $c[4] : "'" . SQLite3::escapeString($c[4]) . "'");
            }
        }
        $cols[] = $def;
    }
    if (count($primary) > 0 && !$auto_increment) {
        $cols[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
    }
    if ($table === 'resource') {
        // The field<n> copy columns core adds for the title, date and sort fields.
        foreach (array(3, 8, 12, 18, 51, 54) as $f) {
            $cols[] = '`field' . $f . '` TEXT';
        }
    }
    $DB->exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $cols) . ')');
}

/** Strip the parentheses MySQL allows around a UNIONed SELECT. */
function harness_unparen_union(string $sql): string
{
    $offset = 0;
    while (($pos = stripos($sql, 'UNION (', $offset)) !== false) {
        $open = $pos + 6;
        $depth = 0;
        for ($i = $open; $i < strlen($sql); $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $sql = substr($sql, 0, $open) . ' ' . substr($sql, $open + 1, $i - $open - 1) . ' ' . substr($sql, $i + 1);
                    break;
                }
            }
        }
        $offset = $pos + 6;
    }
    return $sql;
}

/** MySQL to SQLite. Each emulated construct is recorded so the case can be marked. */
function harness_translate(string $sql): string
{
    global $HARNESS;

    $sql = preg_replace('/MATCH\s*\(\s*(?:[a-z_]+\.)?name\s*\)\s*AGAINST\s*\(\s*\?\s*IN BOOLEAN MODE\s*\)/i', 'ft_match(name, ?)', $sql, -1, $ft);
    if ($ft > 0) {
        $HARNESS['emulated']['full text'] = true;
    }
    $sql = preg_replace('/\bRLIKE\b/i', 'REGEXP', $sql, -1, $rlike);
    if ($rlike > 0) {
        $HARNESS['emulated']['RLIKE'] = true;
    }
    $sql = str_ireplace('(curdate() - interval ? DAY)', 'rs_days_ago(?)', $sql);
    // MySQL compares a string column with a numeric parameter numerically.
    $sql = preg_replace('/\b(rnn\d+)\.name\s*(>=|<=|=)\s*\?/', 'CAST($1.name AS REAL) $2 ?', $sql);
    $sql = preg_replace('/\bIF\(/', 'iif(', $sql);
    $sql = harness_unparen_union($sql);
    // MySQL "#" comments (search_public_collections()).
    $sql = preg_replace('/#[^\n]*/', '', $sql);
    // !last: MySQL resolves the inner "ORDER BY ref" to the selected r.ref; SQLite calls it ambiguous.
    $sql = str_replace(' ORDER BY ref DESC LIMIT ', ' ORDER BY r.ref DESC LIMIT ', $sql);
    // get_all_resource_types(): unqualified ORDER BY column is ambiguous to SQLite.
    if (stripos($sql, 'FROM resource_type rt') !== false) {
        $sql = preg_replace('/ORDER BY\s+order_by\s*,/i', 'ORDER BY rt.order_by,', $sql);
    }
    // MySQL's insert-or-update (the plugin's state table, A6): SQLite's target-less ON CONFLICT (3.35 or later)
    // fires on the primary key; VALUES(col) in the update list is excluded.col there.
    $upsert = stripos($sql, 'ON DUPLICATE KEY UPDATE');
    if ($upsert !== false) {
        $sql = substr($sql, 0, $upsert) . 'ON CONFLICT DO UPDATE SET'
            . preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', substr($sql, $upsert + 23));
    }
    $sql = preg_replace('/^(\s*)INSERT\s+IGNORE\s+INTO\b/i', '$1INSERT OR IGNORE INTO', $sql);
    return $sql;
}

function harness_run(string $sql, array $parameters): array
{
    global $DB, $HARNESS;

    // get_nodes(): MySQL-only translation expressions in the column list; answer it directly.
    if (preg_match('/FROM node\s+WHERE\s+(resource_type_field=\?|true)(.*?)AND\s+(parent IS NULL|parent = \?|TRUE)\s+ORDER BY/is', $sql, $m)) {
        $vals = array();
        for ($i = 1; $i < count($parameters); $i += 2) {
            $vals[] = $parameters[$i];
        }
        $vals = array_slice($vals, 7); // skip the 7 language parameters
        $where = array();
        $bind = array();
        if (stripos($m[1], 'resource_type_field') === 0) {
            $where[] = 'resource_type_field = ?';
            $bind[] = array_shift($vals);
        }
        if (stripos($m[2], 'LIKE') !== false) {
            $where[] = '`name` LIKE ?';
            $bind[] = array_shift($vals);
        } elseif (preg_match('/`name` = \?/', $m[2])) {
            $where[] = '`name` = ?';
            $bind[] = array_shift($vals);
        }
        if (stripos($m[3], 'IS NULL') !== false) {
            $where[] = 'parent IS NULL';
        } elseif (strpos($m[3], '?') !== false) {
            $where[] = 'parent = ?';
            $bind[] = array_shift($vals);
        }
        $stmt = $DB->prepare('SELECT *, name AS translated_name FROM node' . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY order_by, ref ASC');
        foreach ($bind as $i => $b) {
            $stmt->bindValue($i + 1, $b);
        }
        $res = $stmt->execute();
        $rows = array();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    $translated = harness_translate($sql);
    if ($HARNESS['log_sql']) {
        $HARNESS['sql_log'][] = preg_replace('/\s+/', ' ', $translated);
    }
    try {
        $stmt = $DB->prepare($translated);
        $n = 1;
        for ($i = 0; $i + 1 < count($parameters); $i += 2) {
            $type = $parameters[$i];
            $value = $parameters[$i + 1];
            if ($value === null) {
                $stmt->bindValue($n, null, SQLITE3_NULL);
            } elseif ($type === 'i') {
                $stmt->bindValue($n, (int)$value, SQLITE3_INTEGER);
            } elseif ($type === 'd') {
                $stmt->bindValue($n, (float)$value, SQLITE3_FLOAT);
            } else {
                $stmt->bindValue($n, (string)$value, SQLITE3_TEXT);
            }
            $n++;
        }
        $res = $stmt->execute();
        $rows = array();
        if ($res->numColumns() > 0) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
        }
        return $rows;
    } catch (\Throwable $e) {
        $HARNESS['sql_errors'][] = $e->getMessage() . ' <<< ' . substr(preg_replace('/\s+/', ' ', $translated), 0, 400);
        return array();
    }
}

function ps_query($sql, array $parameters = array(), $cache = "", $fetchrows = -1, $dbstruct = true, $logthis = 2, $reconnect = true, $fetch_specific_columns = false)
{
    return harness_run((string)$sql, $parameters);
}
function ps_value($query, $parameters, $default, $cache = "")
{
    $rows = harness_run((string)$query, $parameters);
    return (count($rows) === 0 || !array_key_exists('value', $rows[0]) || $rows[0]['value'] === null) ? $default : $rows[0]['value'];
}
function ps_array($query, $parameters = array(), $cache = "")
{
    $out = array();
    foreach (harness_run((string)$query, $parameters) as $row) {
        $out[] = $row['value'];
    }
    return $out;
}
function clear_query_cache($cache)
{
}
function sql_insert_id()
{
    return $GLOBALS['DB']->lastInsertRowID();
}
function db_begin_transaction($name)
{
}
function db_end_transaction($name)
{
}
function db_rollback_transaction($name)
{
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
        echo '    (core SQL did not run on SQLite: ' . substr($err, 0, 260) . ")\n";
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
