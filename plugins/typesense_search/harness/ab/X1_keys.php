<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// X1 battery: the keys (Q12.1, Q12.8, Q12.11) and X3's web-exposure checks (Q12.6). Provisioning with the private
// server's bootstrap key creates the two scoped keys with exactly the decided actions and the prefix scope and stores
// them; the search key is refused on an import, a collection create and another tenant's collection; the admin key is
// refused on GET /keys, GET /aliases and another tenant's collections; a key holding only the status actions reaches no
// collection or alias; the Q12.8 alias cases are run and their verdicts recorded; the bootstrap key is in no option,
// state row, debug line, activity row or page; rotation revokes the old keys. X3: every script, harness entry and the
// tests bridge refuses a web request from PHP's built-in server, the deny .htaccess files exist, and the setup page's
// actions sit behind checkperm('a'), enforcePostRequest() and the form token.
require __DIR__ . '/boot.php';

$X1_PLUGIN = $ROOT . '/plugins/typesense_search';
$X1_BOOT = $HARNESS['ts']['key'];
$X1_SERVER = null;

/** One request through the plugin's client with a one-off key: a tenant key, the bootstrap key or a wrong one. */
function x1_call(string $key, string $method, string $path, $body = null): array
{
    return typesense_search_client_call(array(
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'key' => $key,
        'timeout' => 'search',
        'jsonl' => is_string($body),
    ));
}

function x1_status(string $key, string $method, string $path, $body = null): int
{
    return x1_call($key, $method, $path, $body)['status'];
}

/** The debug log as one string ('' when no line was written). */
function x1_debug_log(): string
{
    global $debug_log_location;
    return is_file($debug_log_location) ? (string)file_get_contents($debug_log_location) : '';
}

/** Every value of the state table and the activity log, as one string for a key search. */
function x1_logged_text(): string
{
    $text = '';
    foreach (ps_query('SELECT name, value FROM typesense_search_state', array()) as $row) {
        $text .= ' ' . $row['name'] . '=' . $row['value'];
    }
    foreach (ps_query("SELECT note, value_old, value_new, value_diff FROM activity_log WHERE remote_table = 'plugins'", array()) as $row) {
        $text .= ' ' . implode(' ', $row);
    }
    return $text;
}

/** The logged text plus the plugins row, which holds the two keys by design and must never hold the bootstrap key. */
function x1_stored_text(): string
{
    return x1_logged_text() . ' ' . json_encode(get_plugin_config('typesense_search'));
}

/** The setup page's HTML through core's generator. */
function x1_page_html(): string
{
    ob_start();
    config_gen_setup_html(typesense_search_option_page_def(), 'typesense_search', null, $GLOBALS['lang']['typesense_search_configuration']);
    return (string)ob_get_clean();
}

/** A port nothing listens on. */
function x1_free_port(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string)stream_socket_get_name($server, false);
    fclose($server);
    return (int)substr(strrchr($name, ':'), 1);
}

/** Serve the plugin directory with PHP's built-in server; null when it will not start. */
function x1_server_start(): ?array
{
    global $SCRATCH, $X1_PLUGIN, $X1_SERVER;

    $dir = $SCRATCH . '/x1_server';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    @unlink($dir . '/server.log');
    $port = x1_free_port();
    $process = proc_open(
        array(PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $X1_PLUGIN),
        array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/server.log', 'a'), 2 => array('file', $dir . '/server.log', 'a')),
        $pipes,
        $dir
    );
    if (!is_resource($process)) {
        return null;
    }
    $X1_SERVER = array('port' => $port, 'process' => $process);
    register_shutdown_function('x1_server_stop');
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return $X1_SERVER;
        }
        usleep(100000);
    }
    return null;
}

function x1_server_stop(): void
{
    global $X1_SERVER;
    if ($X1_SERVER !== null && is_resource($X1_SERVER['process'])) {
        proc_terminate($X1_SERVER['process']);
        proc_close($X1_SERVER['process']);
    }
    $X1_SERVER = null;
}

/** A plain GET of a plugin file through the built-in server: array(status, body). */
function x1_web(string $relative): array
{
    global $X1_SERVER;

    $curl = curl_init('http://127.0.0.1:' . $X1_SERVER['port'] . '/' . ltrim($relative, '/'));
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10));
    $body = curl_exec($curl);
    return array((int)curl_getinfo($curl, CURLINFO_HTTP_CODE), is_string($body) ? $body : '');
}

/** Every .php file under a directory, relative to the plugin, sorted. */
function x1_php_files(string $subdir): array
{
    global $X1_PLUGIN;

    $files = array();
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($X1_PLUGIN . '/' . $subdir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (substr($file->getPathname(), -4) === '.php') {
            $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($X1_PLUGIN) + 1));
        }
    }
    sort($files);
    return $files;
}

/**
 * How a file starts, after the open tag and any comments: 'guard' (if (PHP_SAPI !== 'cli')), 'guard-server' (the
 * stub's cli-server guard), 'boot' (a require of boot.php or fixture.php, which guard first), 'return' (a data file),
 * or the first token's text.
 */
function x1_first_statement(string $path): string
{
    $tokens = token_get_all((string)file_get_contents($path));
    $skip = array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT);
    foreach ($tokens as $i => $token) {
        if (is_array($token) && in_array($token[0], $skip, true)) {
            continue;
        }
        $rest = '';
        for ($j = $i; $j < count($tokens) && strlen($rest) < 120; $j++) {
            $rest .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $rest = preg_replace('/\s+/', ' ', $rest);
        if (strpos($rest, "if (PHP_SAPI !== 'cli')") === 0) {
            return 'guard';
        }
        if (strpos($rest, "if (PHP_SAPI !== 'cli-server')") === 0) {
            return 'guard-server';
        }
        if (preg_match("#^(require|include)(_once)? __DIR__ \. '/(boot|fixture)\.php'#", $rest) === 1) {
            return 'boot';
        }
        if (strpos($rest, 'return array(') === 0) {
            return 'return';
        }
        return substr($rest, 0, 40);
    }
    return '';
}

// ----------------------------------------------------------------------------------------- setup
include $ROOT . '/languages/en.php';
include $X1_PLUGIN . '/languages/en.php';
$usersession = 'harness-session';
$userref = 1;
$A = 'x1a' . bin2hex(random_bytes(3));
$B = 'x1b' . bin2hex(random_bytes(3));
$A_COLL = $A . '_resources_g1';
$A_ALIAS = $A . '_resources';
$B_COLL = $B . '_resources_g1';
$B_ALIAS = $B . '_resources';
$FIELDS = array('fields' => array(array('name' => 'title', 'type' => 'string')));
$typesense_search_prefix = $A;
$typesense_search_search_key = '';
$typesense_search_admin_key = '';
ps_query('DELETE FROM plugins WHERE name = ?', array('s', 'typesense_search'));
ps_query('INSERT INTO plugins (name, inst_version) VALUES (?, ?)', array('s', 'typesense_search', 'i', 1));
typesense_search_option_store(array('typesense_search_prefix' => $A)); // as the first save of the page leaves it (Q9.12)
harness_ts('DELETE', '/collections/' . $B_COLL);
list($status) = harness_ts('POST', '/collections', array('name' => $B_COLL) + $FIELDS);
harness_ts('POST', '/collections/' . $B_COLL . '/documents/import', '{"id":"9","title":"secret of tenant b"}', true);
harness_check('X1.0 tenant B has a collection with a document', $status === 201, $B_COLL);
@unlink($debug_log_location);
typesense_search_client_breaker_set(array());

// ----------------------------------------------------------------------------------------- refusals before any request
$outcome = typesense_search_keys_provision('   ');
harness_check('X1.1 no bootstrap key: refused, nothing stored', $outcome['error'] === 'no_bootstrap' && !$outcome['ok'] && !isset(get_plugin_config('typesense_search')['typesense_search_search_key']));
$typesense_search_admin_key_override = 'host-key';
$outcome = typesense_search_keys_provision($X1_BOOT);
harness_check('X1.2 a key locked in config.php: refused, the host provisions', $outcome['error'] === 'locked' && typesense_search_keys_locked());
unset($typesense_search_admin_key_override);
$typesense_search_prefix = '';
$outcome = typesense_search_keys_provision($X1_BOOT);
harness_check('X1.3 no prefix yet: refused', $outcome['error'] === 'no_prefix' && typesense_search_keys_scope() === '');
$typesense_search_prefix = $A;
harness_check('X1.4 the scope is the prefix as a regular expression', typesense_search_keys_scope() === $A . '_.*');
$outcome = typesense_search_keys_provision('not-the-bootstrap-key');
harness_check(
    'X1.5 a wrong bootstrap key: the server refuses, nothing stored, the wrong key is not in the error',
    !$outcome['ok'] && strpos($outcome['error'], 'HTTP 401') === 0 && strpos($outcome['error'], 'not-the-bootstrap-key') === false
        && !typesense_search_option_key_set('search_key') && !typesense_search_option_key_set('admin_key'),
    $outcome['error']
);

// ----------------------------------------------------------------------------------------- provisioning
$outcome = typesense_search_keys_provision($X1_BOOT);
$first = $outcome['created'];
harness_check(
    'X1.6 provisioning creates two keys and stores them with their ids',
    $outcome['ok'] && ($first['search'] ?? 0) > 0 && ($first['admin'] ?? 0) > 0 && $first['search'] !== $first['admin']
        && typesense_search_option_key_set('search_key') && typesense_search_option_key_set('admin_key')
        && typesense_search_option('search_key_id') === $first['search'] && typesense_search_option('admin_key_id') === $first['admin']
        && $outcome['revoked'] === array() && !$outcome['hand_made'],
    json_encode($outcome)
);
$stored = get_plugin_config('typesense_search');
$SEARCH_KEY = typesense_search_client_key('search');
$ADMIN_KEY = typesense_search_client_key('admin');
harness_check(
    'X1.7 the stored options are the keys the running configuration uses, 32 characters each',
    is_array($stored) && $stored['typesense_search_search_key'] === $SEARCH_KEY && $stored['typesense_search_admin_key'] === $ADMIN_KEY
        && strlen($SEARCH_KEY) === 32 && strlen($ADMIN_KEY) === 32 && $SEARCH_KEY !== $ADMIN_KEY
        && $stored['typesense_search_search_key_id'] === $first['search']
);
list($status, $meta) = harness_ts('GET', '/keys/' . $first['search']);
harness_check(
    'X1.8 the search key on the server: documents:search on <prefix>_.* and nothing else',
    $status === 200 && $meta['actions'] === array('documents:search') && $meta['collections'] === array($A . '_.*')
        && strpos($meta['description'], $A) !== false && !isset($meta['value']),
    json_encode(array($meta['actions'] ?? null, $meta['collections'] ?? null))
);
list($status, $meta) = harness_ts('GET', '/keys/' . $first['admin']);
$admin_actions = array('documents:*', 'collections:*', 'aliases:upsert', 'aliases:get', 'aliases:delete', 'debug:list', 'metrics.json:list');
harness_check(
    'X1.9 the admin key on the server: the Q12.8 and Q12.11 actions exactly, no list on a data endpoint, no keys:*',
    $status === 200 && $meta['actions'] === $admin_actions && $meta['collections'] === array($A . '_.*')
        && count(array_intersect($meta['actions'], array('aliases:list', 'aliases:*', 'keys:*', 'stats.json:list', '*'))) === 0,
    json_encode($meta['actions'] ?? null)
);

// ----------------------------------------------------------------------------------------- the admin key at work, through the client
$result = typesense_search_client_collection_create(array('name' => $A_COLL) + $FIELDS);
harness_check('X1.10 the admin key creates a collection inside the scope', $result['ok'] && $result['status'] === 201, $result['error']);
$import = typesense_search_client_import($A_COLL, array(
    array('id' => '1', 'title' => 'first of tenant a'),
    array('id' => '2', 'title' => 'second of tenant a'),
));
harness_check('X1.11 the admin key imports into it', $import['ok'] && $import['imported'] === 2 && $import['failed'] === array(), json_encode($import));
$result = typesense_search_client_alias_upsert($A_ALIAS, $A_COLL);
harness_check('X1.12 the admin key points the tenant\'s alias at its collection (aliases:upsert)', $result['ok'], $result['error']);
$result = typesense_search_client_alias_get($A_ALIAS);
harness_check('X1.13 and reads it back (aliases:get)', $result['ok'] && ($result['body']['collection_name'] ?? '') === $A_COLL);
$result = typesense_search_client_call(array('method' => 'GET', 'path' => '/collections', 'timeout' => 'search'));
$names = array_map(fn($c) => $c['name'], is_array($result['body']) ? $result['body'] : array());
harness_check(
    'X1.14 GET /collections with the admin key lists this tenant\'s collections only (the server filters by the scope)',
    $result['ok'] && in_array($A_COLL, $names, true) && count(preg_grep('/^' . $A . '_/', $names)) === count($names),
    implode(', ', $names)
);
$version = typesense_search_client_version(true);
$metrics = typesense_search_client_metrics();
harness_check(
    'X1.15 the status actions work with the tenant key: version and memory (Q12.11)',
    is_string($version) && strpos($version, '30.') === 0 && $metrics['ok'] && isset($metrics['body']['system_memory_total_bytes']),
    (string)$version
);

// ----------------------------------------------------------------------------------------- the search key at work and refused
$result = typesense_search_client_search($A_COLL, array('q' => '*'));
harness_check('X1.16 the search key searches the tenant\'s collection', $result['ok'] && ($result['body']['found'] ?? 0) === 2, $result['error']);
$result = typesense_search_client_search($A_ALIAS, array('q' => '*'));
harness_check('X1.17 and through the tenant\'s alias', $result['ok'] && ($result['body']['found'] ?? 0) === 2, $result['error']);
$result = typesense_search_client_multi_search(array(array('collection' => $A_COLL, 'q' => 'second', 'query_by' => 'title')))[0];
harness_check('X1.18 and by multi_search', $result['ok'] && ($result['body']['found'] ?? 0) === 1, $result['error']);
harness_check(
    'X1.19 the search key is refused on an import (401)',
    x1_status($SEARCH_KEY, 'POST', '/collections/' . $A_COLL . '/documents/import?action=upsert', '{"id":"3","title":"x"}') === 401
);
harness_check('X1.20 on a collection create', x1_status($SEARCH_KEY, 'POST', '/collections', array('name' => $A . '_more') + $FIELDS) === 401);
harness_check('X1.21 on a collection get and delete', x1_status($SEARCH_KEY, 'GET', '/collections/' . $A_COLL) === 401 && x1_status($SEARCH_KEY, 'DELETE', '/collections/' . $A_COLL) === 401);
$result = typesense_search_client_search($B_COLL, array('q' => '*'));
harness_check(
    'X1.22 on a search of another prefix\'s collection, through the client (a client failure, never a result)',
    !$result['ok'] && $result['status'] === 401 && $result['failure'] === 'client' && !isset($result['body']['hits'])
);
harness_check('X1.23 on another prefix by multi_search and on a name without the underscore', x1_status($SEARCH_KEY, 'POST', '/multi_search', array('searches' => array(array('collection' => $B_COLL, 'q' => '*')))) === 401 && x1_status($SEARCH_KEY, 'GET', '/collections/' . $A . 'x/documents/search?q=*') === 401);
harness_check('X1.24 on the status and key endpoints', x1_status($SEARCH_KEY, 'GET', '/debug') === 401 && x1_status($SEARCH_KEY, 'GET', '/keys') === 401 && x1_status($SEARCH_KEY, 'GET', '/aliases') === 401);

// ----------------------------------------------------------------------------------------- the admin key refused
harness_check('X1.25 the admin key is refused on GET /keys', x1_status($ADMIN_KEY, 'GET', '/keys') === 401);
harness_check('X1.26 on GET /aliases', x1_status($ADMIN_KEY, 'GET', '/aliases') === 401);
harness_check('X1.27 on creating or deleting a key', x1_status($ADMIN_KEY, 'POST', '/keys', array('actions' => array('*'), 'collections' => array('*'))) === 401 && x1_status($ADMIN_KEY, 'DELETE', '/keys/' . $first['search']) === 401);
harness_check('X1.28 on another prefix\'s collection: get, search, import, delete', x1_status($ADMIN_KEY, 'GET', '/collections/' . $B_COLL) === 401 && x1_status($ADMIN_KEY, 'GET', '/collections/' . $B_COLL . '/documents/search?q=*') === 401 && x1_status($ADMIN_KEY, 'POST', '/collections/' . $B_COLL . '/documents/import', '{"id":"8","title":"x"}') === 401 && x1_status($ADMIN_KEY, 'DELETE', '/collections/' . $B_COLL) === 401);
harness_check('X1.29 on stats.json (never granted)', x1_status($ADMIN_KEY, 'GET', '/stats.json') === 401);

// ----------------------------------------------------------------------------------------- Q12.8: the alias cases, verdicts recorded
list($status) = harness_ts('PUT', '/aliases/' . $B_ALIAS, array('collection_name' => $B_COLL));
$repoint = typesense_search_client_alias_upsert($A_ALIAS, $B_COLL);
$through = typesense_search_client_search($A_ALIAS, array('q' => '*'));
$leaked = $through['ok'] && ($through['body']['found'] ?? 0) === 1 && (($through['body']['hits'][0]['document']['title'] ?? '') === 'secret of tenant b');
harness_check(
    'X1.30 Q12.8 case 1 recorded: A\'s admin key points A\'s alias at B\'s collection: ' . ($repoint['ok'] ? 'ALLOWED' : 'refused ' . $repoint['status'])
        . '; A\'s search key through that alias: ' . ($leaked ? 'B\'s DOCUMENTS RETURNED (narrowed alias permissions are unsafe on a shared server)' : 'refused ' . $through['status']),
    true
);
$by_name = x1_status($ADMIN_KEY, 'PUT', '/aliases/' . $B_ALIAS, array('collection_name' => $A_COLL));
$by_name_delete = x1_status($ADMIN_KEY, 'DELETE', '/aliases/' . $B_ALIAS);
harness_check(
    'X1.31 Q12.8 case 2 recorded: A\'s admin key changing B\'s alias by name: ' . ($by_name === 200 ? 'ALLOWED' : 'refused ' . $by_name)
        . ', deleting it: ' . ($by_name_delete === 200 ? 'ALLOWED' : 'refused ' . $by_name_delete) . '; aliases:list is refused (X1.26), so B\'s name must stay unguessable',
    true
);
harness_ts('PUT', '/aliases/' . $A_ALIAS, array('collection_name' => $A_COLL));
harness_ts('DELETE', '/aliases/' . $B_ALIAS);

// ----------------------------------------------------------------------------------------- a key with the status actions only (Q12.11)
$status_key = x1_call($X1_BOOT, 'POST', '/keys', array('description' => 'x1 status only', 'actions' => array('debug:list', 'metrics.json:list'), 'collections' => array($A . '_.*')));
$STATUS_KEY = $status_key['body']['value'] ?? '';
$STATUS_ID = (int)($status_key['body']['id'] ?? 0);
harness_check('X1.32 a key holding only debug:list and metrics.json:list reads the version and the metrics', $STATUS_KEY !== '' && x1_status($STATUS_KEY, 'GET', '/debug') === 200 && x1_status($STATUS_KEY, 'GET', '/metrics.json') === 200);
$refused = array(
    x1_status($STATUS_KEY, 'GET', '/collections'),
    x1_status($STATUS_KEY, 'GET', '/collections/' . $A_COLL),
    x1_status($STATUS_KEY, 'GET', '/collections/' . $A_COLL . '/documents/search?q=*'),
    x1_status($STATUS_KEY, 'POST', '/multi_search', array('searches' => array(array('collection' => $A_COLL, 'q' => '*')))),
    x1_status($STATUS_KEY, 'GET', '/collections/' . $A_COLL . '/documents/1'),
    x1_status($STATUS_KEY, 'GET', '/aliases'),
    x1_status($STATUS_KEY, 'GET', '/aliases/' . $A_ALIAS),
    x1_status($STATUS_KEY, 'GET', '/stats.json'),
    x1_status($STATUS_KEY, 'GET', '/keys'),
);
harness_check('X1.33 and is refused by every collection, alias, stats and key endpoint', count(array_unique($refused)) === 1 && $refused[0] === 401, implode(',', $refused));
x1_call($X1_BOOT, 'DELETE', '/keys/' . $STATUS_ID);

// ----------------------------------------------------------------------------------------- nothing leaks
$log = x1_debug_log();
harness_check('X1.34 the debug log recorded the provisioning requests', strpos($log, 'POST /keys 201') !== false && strpos($log, 'keys provisioned: search ' . $first['search']) !== false);
harness_check(
    'X1.35 the bootstrap key is in no debug line, no plugin option, no state row, no activity row',
    strpos($log, $X1_BOOT) === false && strpos(x1_stored_text(), $X1_BOOT) === false
);
$globals = '';
foreach ($GLOBALS as $name => $value) {
    if (strpos($name, 'typesense_search_') === 0 && is_scalar($value)) {
        $globals .= ' ' . $name . '=' . $value;
    }
}
harness_check('X1.36 nor in any typesense_search_ global', strpos($globals, $X1_BOOT) === false);
harness_check(
    'X1.37 neither the search key, the admin key, the status key nor the wrong key is in any debug line',
    strpos($log, $SEARCH_KEY) === false && strpos($log, $ADMIN_KEY) === false && strpos($log, $STATUS_KEY) === false && strpos($log, 'not-the-bootstrap-key') === false
);
harness_check('X1.38 every key header mentioned in the log is redacted', preg_match('/x-typesense-api-key\s*[:=]\s*(?!\[redacted\])\S+/i', $log) === 0);
harness_check(
    'X1.39 the activity log holds the configuration change with the keys redacted, not the keys',
    strpos(x1_logged_text(), '[redacted]') !== false && strpos(x1_logged_text(), $SEARCH_KEY) === false
        && strpos(x1_logged_text(), $ADMIN_KEY) === false
);
$html = x1_page_html();
harness_check(
    'X1.40 the setup page shows both keys as set, a blank bootstrap password field and the rotate button, and carries the form token',
    substr_count($html, 'Set. Leave blank to keep the stored key.') === 2
        && preg_match('/name="typesense_search_bootstrap_key"[^>]*type="password"[^>]*value=""/', $html) === 1
        && strpos($html, 'name="typesense_search_keys_provision"') !== false && strpos($html, 'value="Rotate keys"') !== false
        && strpos($html, 'name="CSRFToken"') !== false
);
harness_check(
    'X1.41 no key is anywhere in the page HTML: not the bootstrap, not the stored keys',
    strpos($html, $X1_BOOT) === false && strpos($html, $SEARCH_KEY) === false && strpos($html, $ADMIN_KEY) === false
);
$typesense_search_search_key_override = 'host-search-key';
$typesense_search_admin_key_override = 'host-admin-key';
$html = x1_page_html();
harness_check(
    'X1.42 with the keys locked in config.php the page draws hidden locked rows and no bootstrap field or button',
    substr_count($html, 'Locked in global configuration (hidden)') === 3 && strpos($html, 'host-search-key') === false && strpos($html, 'host-admin-key') === false
        && strpos($html, 'typesense_search_bootstrap_key') === false && strpos($html, 'typesense_search_keys_provision') === false
);
unset($typesense_search_search_key_override, $typesense_search_admin_key_override);
$typesense_search_keys_notice = typesense_search_keys_notice($outcome);
$html = x1_page_html();
harness_check(
    'X1.43 the notice after the action names the outcome and no key',
    strpos($html, 'New search and admin keys were created and stored.') !== false && strpos($html, $SEARCH_KEY) === false
);
unset($typesense_search_keys_notice);

// A page save keeps the keys (a blank key keeps the stored one) and writes nothing sensitive to the logs.
$saved = typesense_search_option_save(get_plugin_config('typesense_search'), array('host' => typesense_search_option('host'), 'search_key' => '', 'admin_key' => ''));
typesense_search_option_store($saved);
harness_check(
    'X1.44 a page save keeps the keys, their ids and the prefix, and the logs still hold no key (set_plugin_config() would have written them)',
    typesense_search_client_key('search') === $SEARCH_KEY && typesense_search_option('search_key_id') === $first['search']
        && strpos(x1_debug_log(), $SEARCH_KEY) === false && strpos(x1_logged_text(), $ADMIN_KEY) === false
        && typesense_search_tenancy_prefix() === $A
        && count(ps_query("SELECT ref FROM activity_log WHERE remote_table = 'plugins'", array())) === 3
);

// ----------------------------------------------------------------------------------------- rotation
$outcome = typesense_search_keys_provision($X1_BOOT);
$second = $outcome['created'];
harness_check(
    'X1.45 rotation creates two new keys and revokes the two old ones by id',
    $outcome['ok'] && $second['search'] !== $first['search'] && $second['admin'] !== $first['admin']
        && $outcome['revoked'] === array($first['search'], $first['admin']) && $outcome['unrevoked'] === array() && !$outcome['hand_made'],
    json_encode($outcome)
);
list($status) = harness_ts('GET', '/keys/' . $first['search']);
list($status2) = harness_ts('GET', '/keys/' . $first['admin']);
harness_check('X1.46 the old keys are gone from the server', $status === 404 && $status2 === 404);
harness_check('X1.47 a request with an old key is refused', x1_status($SEARCH_KEY, 'GET', '/collections/' . $A_COLL . '/documents/search?q=*') === 401 && x1_status($ADMIN_KEY, 'GET', '/collections/' . $A_COLL) === 401);
$result = typesense_search_client_search($A_COLL, array('q' => '*'));
harness_check(
    'X1.48 the new keys are the running configuration and work',
    typesense_search_client_key('search') !== $SEARCH_KEY && $result['ok'] && ($result['body']['found'] ?? 0) === 2
        && typesense_search_option('admin_key_id') === $second['admin']
);
harness_check('X1.49 the notice after a rotation', typesense_search_keys_notice($outcome) === 'New search and admin keys were created and stored. The previous keys were revoked.');
$SEARCH_KEY = typesense_search_client_key('search');
$ADMIN_KEY = typesense_search_client_key('admin');
// A key entered by hand has no id: rotation stores the new pair and says the old one must be removed on the server.
$hand = get_plugin_config('typesense_search');
$hand['typesense_search_search_key_id'] = 0;
typesense_search_option_store($hand);
$outcome = typesense_search_keys_provision($X1_BOOT);
$third = $outcome['created'];
harness_check(
    'X1.50 a previous key without a recorded id is reported, the one with an id revoked',
    $outcome['ok'] && $outcome['hand_made'] && $outcome['revoked'] === array($second['admin'])
        && strpos(typesense_search_keys_notice($outcome), 'has no recorded id') !== false,
    json_encode($outcome)
);
x1_call($X1_BOOT, 'DELETE', '/keys/' . $second['search']);
harness_check('X1.51 the rotated keys are in no debug line either', strpos(x1_debug_log(), $SEARCH_KEY) === false && strpos(x1_debug_log(), $ADMIN_KEY) === false && strpos(x1_debug_log(), typesense_search_client_key('search')) === false);

// ----------------------------------------------------------------------------------------- X3: web exposure
foreach (array('scripts', 'harness') as $dir) {
    $htaccess = $X1_PLUGIN . '/' . $dir . '/.htaccess';
    harness_check('X3.1 ' . $dir . '/.htaccess denies all', is_file($htaccess) && preg_match('/^Deny from all\s*$/m', (string)file_get_contents($htaccess)) === 1);
}
$libraries = array('harness/ab/accepted_divergences.php', 'harness/ab/fixture_lib.php', 'harness/live/cases.php', 'harness/live/catalogue.php');
$files = array_merge(x1_php_files('scripts'), x1_php_files('tests'), x1_php_files('harness'));
$unguarded = array();
foreach ($files as $file) {
    $start = x1_first_statement($X1_PLUGIN . '/' . $file);
    $ok = in_array($start, array('guard', 'boot'), true)
        || ($file === 'harness/ab/stub_typesense.php' && $start === 'guard-server')
        || (in_array($file, $libraries, true) && ($start === 'return' || strpos($start, '$FX_LOADED = array();') === 0));
    if (!$ok) {
        $unguarded[] = $file . ' (' . $start . ')';
    }
}
harness_check('X3.2 every script, harness entry and the bridge starts with the PHP_SAPI guard (or requires the guarded boot first); the data files only return: ' . count($files) . ' files', count($unguarded) === 0, implode('; ', $unguarded));
harness_check('X3.3 the setup page is the one page and its actions are gated', count(x1_php_files('pages')) === 1);
$setup = (string)file_get_contents($X1_PLUGIN . '/pages/setup.php');
$perm = strpos($setup, "if (!checkperm('a'))");
$first_action = strpos($setup, 'getval(');
preg_match_all('/^if \((.*?)\) \{$/ms', $setup, $conditions); // top-level ifs: the action gates
$actions = 0;
$ungated = array();
foreach ($conditions[1] as $condition) {
    if (strpos($condition, 'getval(') !== false) {
        $actions++;
        if (strpos($condition, 'enforcePostRequest(') === false) {
            $ungated[] = preg_replace('/\s+/', ' ', $condition);
        }
    }
}
harness_check(
    'X3.4 setup.php: checkperm(\'a\') before any action, every action (' . $actions . ') behind enforcePostRequest(), the form token from core\'s generator',
    $perm !== false && $first_action !== false && $perm < $first_action && $actions >= 2 && count($ungated) === 0
        && strpos($setup, 'include \'../../../include/authenticate.php\';') !== false,
    implode('; ', $ungated)
);
$server = x1_server_start();
harness_check('X3.5 PHP\'s built-in server serves the plugin directory for the web checks', $server !== null, $server === null ? 'see ' . $SCRATCH . '/x1_server/server.log' : 'port ' . $server['port']);
if ($server !== null) {
    $exposed = array();
    $refused = 0;
    foreach ($files as $file) {
        if ($file === 'harness/ab/stub_typesense.php') {
            continue; // written for the built-in server: its guard admits cli-server and refuses every other SAPI (X3.2)
        }
        list($status, $body) = x1_web($file);
        $library = in_array($file, $libraries, true);
        $ok = in_array($status, array(401, 404), true) || ($library && $status === 200 && trim($body) === '');
        $ok = $ok && stripos($body, '<html') === false && stripos($body, 'error') === false && stripos($body, 'warning') === false;
        if ($ok) {
            $refused++;
        } else {
            $exposed[] = $file . ' -> ' . $status . ' ' . substr(preg_replace('/\s+/', ' ', $body), 0, 60);
        }
    }
    harness_check('X3.6 a web request to each script, harness entry and the bridge is refused (401 or 404, or an empty answer from a data file): ' . $refused . ' of ' . (count($files) - 1), count($exposed) === 0, implode('; ', $exposed));
    list($status, $body) = x1_web('tests/000010_typesense_smoke.php');
    harness_check('X3.7 the tests bridge answers 401 "Command line only" without running the battery', $status === 401 && strpos($body, 'Command line only') !== false && strpos($body, 'RESULT') === false);
    list($status, $body) = x1_web('scripts/reindex.php');
    harness_check('X3.8 scripts/reindex.php answers 401 before any work', $status === 401 && strpos($body, 'Command line only') !== false);
    list($status, $body) = x1_web('harness/ab/boot.php');
    harness_check('X3.9 the harness boot answers 404 with an empty body', $status === 404 && $body === '');
    list($status, $body) = x1_web('harness/ab/expected/A3_schema_v1.json');
    harness_check('X3.10 recorded: a non-PHP harness file is what the .htaccess and the nginx rule are for (the built-in server serves it: ' . $status . ')', true);
    x1_server_stop();
}
harness_check('X3.11 the nginx rule is in the operations page', strpos((string)file_get_contents($X1_PLUGIN . '/docs/operations.md'), 'location ~ ^/plugins/typesense_search/(scripts|harness)') !== false);

// ----------------------------------------------------------------------------------------- cleanup
foreach (array((int)typesense_search_option('search_key_id'), (int)typesense_search_option('admin_key_id')) as $id) {
    harness_ts('DELETE', '/keys/' . $id);
}
harness_ts('DELETE', '/aliases/' . $A_ALIAS);
harness_ts('DELETE', '/aliases/' . $B_ALIAS);
foreach (array($A_COLL, $B_COLL) as $name) {
    harness_ts('DELETE', '/collections/' . $name);
}
list($status, $keys) = harness_ts('GET', '/keys');
$left = array();
foreach ($keys['keys'] ?? array() as $key) {
    if (strpos((string)($key['description'] ?? ''), 'typesense_search ' . $A) === 0 || ($key['description'] ?? '') === 'x1 status only') {
        $left[] = $key['id'];
    }
}
harness_check('X1.52 cleanup: none of this run\'s keys is left on the server', $status === 200 && count($left) === 0, implode(',', $left));
ps_query('DELETE FROM plugins WHERE name = ?', array('s', 'typesense_search'));

harness_finish('X1_keys');
