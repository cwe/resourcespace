<?php

// A2 battery: the Typesense client. The endpoint rule (plain http for a loopback host only, refused before any
// network call otherwise), the status calls and the version check, the collection and alias calls, imports with
// every result line read and chunked by the options, the GET-or-POST choice by the query string's size against
// the server's cap, the window shapes, 4xx answers handled without touching the breaker, multi_search chunked
// at 50 with each search answered on its own, the key by operation and its redaction from the log (through a
// canned server, stub_typesense.php, which also answers 5xx, 503 and slowly to order), the 503 back-off with
// halved concurrency, the two timeouts, the breaker tripping on connection failures and 5xx and closing after
// the /health probe, and an exception inside the transport mapped to a failed result.
// Tested range recorded here (Q1.9): minimum 30.0, tested majors 30; this battery was proved on 30.2 (2026-10-23).
require __DIR__ . '/boot.php';

$A2_KEY_SEARCH = 'stub-search-key-7f3a';
$A2_KEY_ADMIN = 'stub-admin-key-9c1d';
$A2_STUB = null;

/** A port nothing listens on. */
function a2_free_port(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string)stream_socket_get_name($server, false);
    fclose($server);
    return (int)substr(strrchr($name, ':'), 1);
}

function a2_point(string $protocol, string $host, int $port): void
{
    $GLOBALS['typesense_search_protocol'] = $protocol;
    $GLOBALS['typesense_search_host'] = $host;
    $GLOBALS['typesense_search_port'] = $port;
}

function a2_point_harness(): void
{
    global $HARNESS;
    a2_point('http', $HARNESS['ts']['host'], $HARNESS['ts']['port']);
}

/** The breaker closed with nothing counted, and the import concurrency back at the option. */
function a2_reset(): void
{
    typesense_search_client_breaker_set(array());
    typesense_search_client_import_concurrency((int)typesense_search_option('import_concurrency'));
}

function a2_closed(): bool
{
    return typesense_search_client_breaker_state() === 'closed' && typesense_search_client_breaker_get()['failures'] === 0;
}

function a2_refs(array $result): array
{
    $refs = array();
    foreach ($result['body']['hits'] ?? array() as $hit) {
        $refs[] = (int)$hit['document']['ref'];
    }
    return $refs;
}

function a2_doc(int $ref, string $title = ''): array
{
    return array('id' => (string)$ref, 'ref' => $ref, 'title' => $title === '' ? 'doc ' . $ref . ' harbour' : $title, 'tags' => array('t' . $ref));
}

/** Start the canned server on a free port; null when it will not start. */
function a2_stub_start(): ?array
{
    global $SCRATCH, $A2_STUB;

    $dir = $SCRATCH . '/a2_stub';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    foreach (array('requests.jsonl', 'plan.json', 'server.log') as $file) {
        @unlink($dir . '/' . $file);
    }
    $port = a2_free_port();
    $process = proc_open(
        array(PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/stub_typesense.php'),
        array(0 => array('pipe', 'r'), 1 => array('file', $dir . '/server.log', 'a'), 2 => array('file', $dir . '/server.log', 'a')),
        $pipes,
        $dir,
        array_merge(getenv(), array('STUB_DIR' => $dir))
    );
    if (!is_resource($process)) {
        return null;
    }
    $A2_STUB = array('port' => $port, 'dir' => $dir, 'process' => $process);
    register_shutdown_function('a2_stub_stop');
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return $A2_STUB;
        }
        usleep(100000);
    }
    return null;
}

function a2_stub_stop(): void
{
    global $A2_STUB;
    if ($A2_STUB !== null && is_resource($A2_STUB['process'])) {
        proc_terminate($A2_STUB['process']);
        proc_close($A2_STUB['process']);
    }
    $A2_STUB = null;
}

/** What the stub answers next: one status (400 or more) and one delay (seconds) per request, in order. */
function a2_stub_plan(array $statuses = array(), array $delays = array()): void
{
    global $A2_STUB;
    file_put_contents($A2_STUB['dir'] . '/plan.json', json_encode(array('statuses' => $statuses, 'delays' => $delays)));
}

function a2_stub_clear(): void
{
    global $A2_STUB;
    @unlink($A2_STUB['dir'] . '/requests.jsonl');
}

/** The requests the stub has seen since the last clear, in order. */
function a2_stub_requests(): array
{
    global $A2_STUB;
    $file = $A2_STUB['dir'] . '/requests.jsonl';
    if (!is_file($file)) {
        return array();
    }
    $out = array();
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $out[] = json_decode($line, true);
    }
    return $out;
}

function a2_stub_paths(): array
{
    $paths = array();
    foreach (a2_stub_requests() as $entry) {
        $paths[] = $entry['method'] . ' ' . parse_url($entry['uri'], PHP_URL_PATH);
    }
    return $paths;
}

/** The key an operation on a path must send: the search key for searches, none for /health, the admin key otherwise. */
function a2_expected_key(array $entry): ?string
{
    global $A2_KEY_SEARCH, $A2_KEY_ADMIN;
    $path = (string)parse_url($entry['uri'], PHP_URL_PATH);
    if ($path === '/health') {
        return null;
    }
    return ($path === '/multi_search' || substr($path, -17) === '/documents/search') ? $A2_KEY_SEARCH : $A2_KEY_ADMIN;
}

$saved = array();
foreach (array('host', 'port', 'protocol', 'search_key', 'admin_key', 'search_timeout', 'import_timeout', 'import_max_docs', 'import_max_bytes', 'import_concurrency', 'import_backoff_min', 'import_backoff_max', 'breaker_failures', 'breaker_cooldown') as $name) {
    $saved[$name] = $GLOBALS['typesense_search_' . $name];
}
$typesense_search_breaker_failures = 3;
$typesense_search_breaker_cooldown = 30;
a2_reset();

// ----------------------------------------------------------------------------------------- the endpoint rule (Q12.2)
harness_check('A2.1 http on 127.0.0.1 accepted', typesense_search_client_endpoint_error('http', '127.0.0.1', 8108) === '');
harness_check(
    'A2.2 http on localhost, 127.0.0.2 and [::1] accepted',
    typesense_search_client_endpoint_error('http', 'localhost', 8108) === ''
        && typesense_search_client_endpoint_error('http', '127.0.0.2', 8108) === ''
        && typesense_search_client_endpoint_error('http', '[::1]', 8108) === ''
);
$refused = typesense_search_client_endpoint_error('http', '192.0.2.1', 8108);
harness_check('A2.3 http on a non-loopback host refused', $refused !== '', $refused);
harness_check(
    'A2.4 https on any host accepted (verification is always on in the transport)',
    typesense_search_client_endpoint_error('https', '192.0.2.1', 8108) === ''
        && typesense_search_client_endpoint_error('https', 'search.example.org', 443) === ''
);
$bad = array(
    array('ftp', '127.0.0.1', 8108), array('', '127.0.0.1', 8108), array('http', 'host/with/slash', 8108),
    array('http', 'host with space', 8108), array('http', '', 8108), array('https', 'http://x', 443),
    array('http', '127.0.0.1', 0), array('http', '127.0.0.1', 65536), array('https', '-bad.example', 443),
);
$all_refused = true;
foreach ($bad as $case) {
    $all_refused = $all_refused && typesense_search_client_endpoint_error($case[0], $case[1], $case[2]) !== '';
}
harness_check('A2.5 other protocols, bad hosts and bad ports refused', $all_refused);
a2_point('http', '192.0.2.1', 8108);
$started = microtime(true);
$result = typesense_search_client_search('anything', array('q' => '*'));
$ms = (int)round((microtime(true) - $started) * 1000);
harness_check(
    'A2.6 a plain-http request to a non-loopback host is refused before any network call',
    $result['ok'] === false && $result['failure'] === 'config' && $result['status'] === 0 && $ms < 200
        && strpos($result['error'], 'loopback') !== false,
    $ms . 'ms ' . json_encode($result)
);
harness_check('A2.7 a configuration refusal leaves the breaker closed', a2_closed());
a2_point_harness();

// ----------------------------------------------------------------------------------------- the server (30.x)
list(, $debug) = harness_ts('GET', '/debug');
$version = typesense_search_client_version(true);
harness_check('A2.8 the version is read from /debug', $version !== null && $version === ($debug['version'] ?? null), (string)$version);
$check = typesense_search_client_version_check();
harness_check(
    'A2.9 this server (' . $version . ') is within the tested range: minimum ' . $check['minimum'] . ', tested majors ' . json_encode($check['tested']),
    $check['state'] === 'ok' && $check['ok'] === true
);
$states = array();
foreach (array('29.9', '30.0', '30.2', '30.17', '31.0', 'v1', '') as $v) {
    $c = typesense_search_client_version_check($v);
    $states[$v] = $c['state'] . ($c['ok'] ? '+' : '-');
}
harness_check(
    'A2.10 the check refuses below 30.0, accepts 30.x, warns above the tested majors, reports an unreadable version',
    $states === array('29.9' => 'too_old-', '30.0' => 'ok+', '30.2' => 'ok+', '30.17' => 'ok+', '31.0' => 'untested+', 'v1' => 'unknown-', '' => 'unknown-'),
    json_encode($states)
);
harness_check('A2.11 /health answers without a key', typesense_search_client_health() === true);
$metrics = typesense_search_client_metrics();
harness_check(
    'A2.12 /metrics.json gives the memory figures for the pre-build check',
    $metrics['ok'] && isset($metrics['body']['system_memory_total_bytes'], $metrics['body']['typesense_memory_active_bytes'])
);
$request = array('method' => 'GET', 'url' => 'http://' . $HARNESS['ts']['host'] . ':' . $HARNESS['ts']['port'] . '/health', 'headers' => array(), 'body' => null, 'timeout' => 2.0, 'connect_timeout' => 2.0);
$first = typesense_search_client_curl_send($request);
$second = typesense_search_client_curl_send($request);
harness_check(
    'A2.13 (transport) the keep-alive handle reuses its connection: the second request opens none',
    $first['status'] === 200 && $second['status'] === 200 && $second['connects'] === 0,
    'connects ' . $first['connects'] . ' then ' . $second['connects']
);
$pair = typesense_search_client_curl_send_many(array($request, $request));
harness_check('A2.14 (transport) curl_multi sends several at once and answers in order', count($pair) === 2 && $pair[0]['status'] === 200 && $pair[1]['status'] === 200);

// ----------------------------------------------------------------------------------------- collections
$c = 'harness_a2_client';
typesense_search_client_collection_delete($c);
harness_check('A2.15 collection_exists is false for a missing collection', typesense_search_client_collection_exists($c) === false);
$definition = array('name' => $c, 'fields' => array(
    array('name' => 'ref', 'type' => 'int32', 'sort' => true),
    array('name' => 'title', 'type' => 'string'),
    array('name' => 'tags', 'type' => 'string[]', 'optional' => true),
));
$created = typesense_search_client_collection_create($definition);
harness_check(
    'A2.16 collection_create (the rebuild\'s call) answers 201 with the stored definition',
    $created['ok'] && $created['status'] === 201 && ($created['body']['name'] ?? '') === $c,
    json_encode($created)
);
$got = typesense_search_client_collection_get($c);
harness_check(
    'A2.17 collection_exists is true and collection_get carries the definition and num_documents',
    typesense_search_client_collection_exists($c) === true && $got['ok'] && ($got['body']['num_documents'] ?? -1) === 0
);
$again = typesense_search_client_collection_create($definition);
harness_check(
    'A2.18 creating it again is a 409 client failure with the server\'s message',
    !$again['ok'] && $again['status'] === 409 && $again['failure'] === 'client' && strpos($again['error'], 'already exists') !== false,
    json_encode($again)
);
harness_check('A2.19 a 409 leaves the breaker closed', a2_closed());

// ----------------------------------------------------------------------------------------- import and search
$docs = array();
for ($i = 1; $i <= 5; $i++) {
    $docs[] = a2_doc($i);
}
$import = typesense_search_client_import($c, $docs);
harness_check(
    'A2.20 five documents upserted in one request: imported 5, none failed',
    $import['ok'] && $import['imported'] === 5 && $import['failed'] === array() && $import['requests'] === 1 && $import['status'] === 200,
    json_encode($import)
);
$result = typesense_search_client_search($c, array('q' => '*', 'per_page' => 2, 'page' => 1, 'sort_by' => 'ref:asc'));
harness_check(
    'A2.21 a search goes by GET: found 5, page 1 of 2 holds refs 1 and 2',
    $result['ok'] && $result['route'] === 'get' && ($result['body']['found'] ?? 0) === 5 && a2_refs($result) === array(1, 2),
    json_encode(array_diff_key($result, array('body' => 1))) . ' ' . json_encode(a2_refs($result))
);
$pages = array();
foreach (array(2, 3, 4) as $page) {
    $r = typesense_search_client_search($c, array('q' => '*', 'per_page' => 2, 'page' => $page, 'sort_by' => 'ref:asc'));
    $pages[$page] = ($r['body']['found'] ?? 0) . ':' . json_encode(a2_refs($r));
}
harness_check('A2.22 pages 2, 3 and 4 give refs 3-4, 5 and none, found 5 throughout', $pages === array(2 => '5:[3,4]', 3 => '5:[5]', 4 => '5:[]'), json_encode($pages));
$r = typesense_search_client_search($c, array('q' => '*', 'per_page' => 250));
$r0 = typesense_search_client_search($c, array('q' => '*', 'per_page' => 0));
harness_check(
    'A2.23 per_page 250 is accepted; per_page 0 gives found with no hits (the count-only window)',
    $r['ok'] && count(a2_refs($r)) === 5 && $r0['ok'] && ($r0['body']['found'] ?? 0) === 5 && ($r0['body']['hits'] ?? null) === array()
);
$r = typesense_search_client_search($c, array('q' => '*', 'per_page' => 251));
harness_check(
    'A2.24 per_page 251 is a 422 client failure',
    !$r['ok'] && $r['status'] === 422 && $r['failure'] === 'client' && strpos($r['error'], '250') !== false,
    json_encode(array_diff_key($r, array('body' => 1)))
);
$r = typesense_search_client_search($c, array('q' => '*', 'filter_by' => 'ref:>>1'));
harness_check(
    'A2.25 a bad filter is a 400 client failure with the server\'s message',
    !$r['ok'] && $r['status'] === 400 && $r['failure'] === 'client' && strpos($r['error'], 'Not an int32') !== false,
    json_encode(array_diff_key($r, array('body' => 1)))
);
harness_check('A2.26 4xx answers leave the breaker closed with nothing counted', a2_closed());

// The size rule: the server refuses an encoded query string of 4,000 bytes or more (the request line's path is
// not counted); the client routes anything above its cap of 3,999 through multi_search.
$params = array('q' => '*', 'per_page' => 250, 'sort_by' => 'ref:asc', 'filter_by' => 'ref:>=0');
$pad = typesense_search_client_get_max_bytes() - strlen(typesense_search_client_query_string($params));
$params['filter_by'] = 'ref:>=' . str_repeat('0', $pad) . '0';
$query = typesense_search_client_query_string($params);
$r = typesense_search_client_search($c, $params);
harness_check(
    'A2.27 a query string of ' . typesense_search_client_get_max_bytes() . ' bytes, the longest the server takes, goes by GET',
    strlen($query) === typesense_search_client_get_max_bytes() && $r['ok'] && $r['route'] === 'get' && ($r['body']['found'] ?? 0) === 5,
    strlen($query) . ' bytes ' . json_encode(array_diff_key($r, array('body' => 1)))
);
$params['filter_by'] .= '0';
$query = typesense_search_client_query_string($params);
list($status, $body) = harness_ts('GET', '/collections/' . $c . '/documents/search?' . $query);
harness_check(
    'A2.28 (fact) the server refuses 4,000 bytes by GET with 400 and points at /multi_search',
    strlen($query) === typesense_search_client_get_max_bytes() + 1 && $status === 400 && strpos((string)($body['message'] ?? ''), 'exceeds max allowed length') !== false,
    $status . ' ' . json_encode($body)
);
$r = typesense_search_client_search($c, $params);
harness_check(
    'A2.29 the client sends the same search through multi_search and the result has the GET shape',
    $r['ok'] && $r['route'] === 'post' && $r['status'] === 200 && ($r['body']['found'] ?? 0) === 5 && count(a2_refs($r)) === 5,
    json_encode(array_diff_key($r, array('body' => 1)))
);
$r = typesense_search_client_search($c, array('per_page' => 2, 'page' => 2) + $params);
harness_check('A2.30 a window through the POST route pages as the GET route does', $r['route'] === 'post' && a2_refs($r) === array(3, 4), json_encode(a2_refs($r)));
$r = typesense_search_client_search($c, array('per_page' => 251) + $params);
harness_check(
    'A2.31 per_page 251 through the POST route is the same 422 client failure',
    !$r['ok'] && $r['route'] === 'post' && $r['status'] === 422 && $r['failure'] === 'client' && a2_closed(),
    json_encode(array_diff_key($r, array('body' => 1)))
);

// multi_search: at most 50 per request, each search answered on its own.
$searches = array();
for ($i = 0; $i < 51; $i++) {
    $searches[] = array('collection' => $c, 'q' => '*', 'per_page' => 1, 'page' => ($i % 5) + 1, 'sort_by' => 'ref:asc');
}
$results = typesense_search_client_multi_search($searches);
$in_order = count($results) === 51;
foreach ($results as $i => $one) {
    $in_order = $in_order && $one['ok'] && a2_refs($one) === array(($i % 5) + 1);
}
harness_check('A2.32 51 searches go in two requests of at most 50 and come back in order', $in_order, count($results) . ' results');
list($status, $body) = harness_ts('POST', '/multi_search', array('searches' => $searches));
harness_check(
    'A2.33 (fact) the server refuses 51 searches in one multi_search with 400',
    $status === 400 && strpos((string)($body['message'] ?? ''), 'limit_multi_searches') !== false,
    $status . ' ' . json_encode($body)
);
$results = typesense_search_client_multi_search(array(
    array('collection' => $c, 'q' => '*', 'per_page' => 1),
    array('collection' => 'harness_a2_nope', 'q' => '*'),
    array('collection' => $c, 'q' => '*', 'per_page' => 251),
    array('collection' => $c, 'q' => '*', 'filter_by' => 'ref:>>1'),
    array('collection' => $c, 'q' => '*', 'per_page' => 1),
));
$shape = array();
foreach ($results as $one) {
    $shape[] = ($one['ok'] ? 'ok' : (string)$one['failure']) . ':' . $one['status'];
}
harness_check(
    'A2.34 a search failing inside a 200 (404, 422, 400) fails on its own while its neighbours succeed',
    $shape === array('ok:200', 'client:404', 'client:422', 'client:400', 'ok:200') && a2_closed(),
    json_encode($shape)
);

// Import: every result line read; chunks by the options.
$import = typesense_search_client_import($c, array(a2_doc(6), array('id' => '7', 'ref' => '7', 'title' => 'bad type'), a2_doc(8)));
harness_check(
    'A2.35 a bad type fails its line inside an HTTP 200, reported with index, id, code and error; the others import',
    !$import['ok'] && $import['status'] === 200 && $import['failure'] === 'client' && $import['imported'] === 2
        && $import['failed'] === array(array('index' => 1, 'id' => '7', 'code' => 400, 'error' => 'Field `ref` must be an int32.')),
    json_encode($import)
);
$import = typesense_search_client_import($c, array(array('id' => '6', 'title' => 'renamed'), array('id' => '77', 'title' => 'missing')), 'update');
harness_check(
    'A2.36 an update of a missing id fails its line with 404 inside an HTTP 200',
    $import['imported'] === 1 && count($import['failed']) === 1 && $import['failed'][0]['index'] === 1 && $import['failed'][0]['id'] === '77'
        && $import['failed'][0]['code'] === 404 && strpos($import['failed'][0]['error'], 'Could not find') !== false,
    json_encode($import)
);
harness_check('A2.37 line failures are the caller\'s data, not the server\'s: the breaker is untouched', a2_closed());
$many = array();
for ($i = 10; $i <= 16; $i++) {
    $many[] = a2_doc($i);
}
$typesense_search_import_max_docs = 2;
$import = typesense_search_client_import($c, $many);
harness_check('A2.38 chunks of import_max_docs documents: 7 documents in 4 requests', $import['ok'] && $import['imported'] === 7 && $import['requests'] === 4, json_encode($import));
$typesense_search_import_max_docs = 500;
$typesense_search_import_max_bytes = 1;
$import = typesense_search_client_import($c, $many);
harness_check('A2.39 chunks of import_max_bytes: a document above the cap is a chunk of its own, 7 requests', $import['ok'] && $import['imported'] === 7 && $import['requests'] === 7, json_encode($import));
$typesense_search_import_max_bytes = 10485760;
$typesense_search_import_max_docs = 2;
a2_reset();
typesense_search_client_import_concurrency(3);
$import = typesense_search_client_import($c, $many);
harness_check('A2.40 three requests in flight: the same 4 requests, all imported, concurrency reported', $import['ok'] && $import['imported'] === 7 && $import['requests'] === 4 && $import['concurrency'] === 3, json_encode($import));
$typesense_search_import_max_docs = 500;
a2_reset();
$import = typesense_search_client_import($c, array(array('id' => '90', 'ref' => 90, 'title' => "bad \xB1 utf-8")));
harness_check(
    'A2.41 a document that cannot be encoded is a failed line and no request is sent',
    !$import['ok'] && $import['requests'] === 0 && count($import['failed']) === 1 && $import['failed'][0]['code'] === 0 && strpos($import['failed'][0]['error'], 'json_encode') === 0,
    json_encode($import)
);
$import = typesense_search_client_import($c, array());
harness_check('A2.42 nothing to import is ok with no request', $import['ok'] && $import['requests'] === 0 && $import['imported'] === 0);

$d = typesense_search_client_delete($c, '8');
harness_check('A2.43 delete answers with the id', $d['ok'] && ($d['body']['id'] ?? '') === '8', json_encode($d));
$d = typesense_search_client_delete($c, '8');
harness_check('A2.44 deleting a missing id is a 404 client failure', !$d['ok'] && $d['status'] === 404 && $d['failure'] === 'client', json_encode($d));
$d = typesense_search_client_delete_filter($c, 'ref:>=10');
harness_check('A2.45 delete by filter reports num_deleted', $d['ok'] && ($d['body']['num_deleted'] ?? -1) === 7, json_encode($d));

// Aliases.
$alias = 'harness_a2_alias';
$a = typesense_search_client_alias_upsert($alias, $c);
$g = typesense_search_client_alias_get($alias);
harness_check(
    'A2.46 alias_upsert points the alias at the collection and alias_get reads it back',
    $a['ok'] && ($a['body']['collection_name'] ?? '') === $c && $g['ok'] && ($g['body']['collection_name'] ?? '') === $c,
    json_encode($a) . ' ' . json_encode($g)
);
$r = typesense_search_client_search($alias, array('q' => '*', 'per_page' => 250));
harness_check('A2.47 a search through the alias reaches the collection (6 documents left)', $r['ok'] && ($r['body']['found'] ?? 0) === 6, json_encode(a2_refs($r)));
$d = typesense_search_client_alias_delete($alias);
$g = typesense_search_client_alias_get($alias);
harness_check('A2.48 alias_delete removes it and alias_get is then a 404 client failure', $d['ok'] && !$g['ok'] && $g['status'] === 404 && $g['failure'] === 'client', json_encode($g));

// ----------------------------------------------------------------------------------------- the canned server
$stub = a2_stub_start();
harness_check('A2.49 the canned server starts', $stub !== null, $stub === null ? 'see ' . $SCRATCH . '/a2_stub/server.log' : 'port ' . $stub['port']);
if ($stub === null) {
    a2_point_harness();
    harness_finish('A2_client');
}
a2_point('http', '127.0.0.1', $stub['port']);
$typesense_search_search_key = $A2_KEY_SEARCH;
$typesense_search_admin_key = $A2_KEY_ADMIN;
$typesense_search_import_backoff_min = 0;
$typesense_search_import_backoff_max = 0;
a2_reset();
@unlink($debug_log_location);
a2_stub_clear();
typesense_search_client_search('stubcol', array('q' => '*'));
typesense_search_client_search('stubcol', array('q' => '*', 'filter_by' => str_repeat('ref:>=0 && ', 400) . 'ref:>=0'));
typesense_search_client_multi_search(array(array('collection' => 'stubcol', 'q' => '*'), array('collection' => 'stubcol', 'q' => 'x')));
typesense_search_client_import('stubcol', array(a2_doc(1), a2_doc(2)));
typesense_search_client_delete('stubcol', '1');
typesense_search_client_delete_filter('stubcol', 'ref:=2');
typesense_search_client_collection_get('stubcol');
typesense_search_client_collection_create(array('name' => 'stubcol', 'fields' => array()));
typesense_search_client_collection_delete('stubcol');
typesense_search_client_alias_get('stubalias');
typesense_search_client_alias_upsert('stubalias', 'stubcol');
typesense_search_client_alias_delete('stubalias');
typesense_search_client_version(true);
typesense_search_client_metrics();
typesense_search_client_health();
$seen = a2_stub_requests();
$wrong_key = array();
$leaked = array();
$content_types = array();
foreach ($seen as $entry) {
    $path = (string)parse_url($entry['uri'], PHP_URL_PATH);
    if ($entry['key'] !== a2_expected_key($entry)) {
        $wrong_key[] = $entry['method'] . ' ' . $path . ' sent ' . json_encode($entry['key']);
    }
    foreach (array($A2_KEY_SEARCH, $A2_KEY_ADMIN) as $key) {
        if (strpos($entry['uri'], $key) !== false || strpos((string)$entry['body'], $key) !== false) {
            $leaked[] = $entry['method'] . ' ' . $path;
        }
    }
    if ($entry['body'] !== '') {
        $content_types[$path] = $entry['content_type'];
    }
}
harness_check('A2.50 every operation the contract offers reached the server: ' . count($seen) . ' requests', count($seen) === 15, json_encode(a2_stub_paths()));
harness_check(
    'A2.51 the search key is sent for search and multi_search, the admin key for everything else, no key for /health',
    count($seen) === 15 && count($wrong_key) === 0,
    implode('; ', $wrong_key)
);
harness_check('A2.52 no key appears in any request line or body', count($seen) === 15 && count($leaked) === 0, implode('; ', $leaked));
harness_check(
    'A2.53 JSON bodies go as application/json, the import as text/plain',
    ($content_types['/multi_search'] ?? '') === 'application/json' && ($content_types['/collections/stubcol/documents/import'] ?? '') === 'text/plain',
    json_encode($content_types)
);
$log = is_file($debug_log_location) ? file_get_contents($debug_log_location) : '';
harness_check(
    'A2.54 one debug line per request and neither key anywhere in the log',
    substr_count($log, 'typesense_search client:') === count($seen) && strpos($log, $A2_KEY_SEARCH) === false && strpos($log, $A2_KEY_ADMIN) === false,
    substr_count($log, 'typesense_search client:') . ' lines'
);
harness_check(
    'A2.55 redaction covers a key inside any text and the value of a key header',
    typesense_search_client_redact('sent ' . $A2_KEY_ADMIN . ' as X-TYPESENSE-API-KEY: ' . $A2_KEY_SEARCH . ' or x-typesense-api-key=other', array('other'))
        === 'sent [redacted] as X-TYPESENSE-API-KEY: [redacted] or x-typesense-api-key=[redacted]'
);

// 503: the back-off, the halving, the attempts.
$typesense_search_import_max_docs = 1;
a2_reset();
typesense_search_client_import_concurrency(4);
a2_stub_plan(array(503, 503));
a2_stub_clear();
$six = array();
for ($i = 1; $i <= 6; $i++) {
    $six[] = a2_doc($i);
}
$import = typesense_search_client_import('stubcol', $six);
harness_check(
    'A2.56 two 503s: both chunks sent again after the back-off, concurrency halved from 4 to 2, every document imported',
    $import['ok'] && $import['imported'] === 6 && $import['retries'] === 2 && $import['concurrency'] === 2 && $import['requests'] === 8
        && count(a2_stub_requests()) === 8,
    json_encode($import)
);
harness_check('A2.57 the 503s counted towards the breaker but the successes after them closed the count', a2_closed());
a2_reset();
typesense_search_client_import_concurrency(1);
a2_stub_plan(array(503, 503, 503, 503, 503, 503));
$import = typesense_search_client_import('stubcol', array(a2_doc(1)));
harness_check(
    'A2.58 a chunk still 503 after the fifth attempt is reported failed with 503 for each of its documents',
    !$import['ok'] && $import['requests'] === 5 && $import['retries'] === 4 && $import['status'] === 503 && $import['failure'] === 'server'
        && count($import['failed']) === 1 && $import['failed'][0]['code'] === 503,
    json_encode($import)
);
harness_check('A2.59 five 5xx in a row have opened the breaker', typesense_search_client_breaker_state() === 'open' && typesense_search_client_breaker_get()['trips'] === 1);
a2_stub_plan();
$typesense_search_import_max_docs = 500;
a2_reset();
harness_check('A2.60 the breaker seam: an empty state closes it', a2_closed());

// 5xx trips, 4xx never.
a2_stub_plan(array(500, 502, 500));
$shape = array();
for ($i = 0; $i < 3; $i++) {
    $r = typesense_search_client_search('stubcol', array('q' => '*'));
    $shape[] = (string)$r['failure'] . ':' . $r['status'] . ':' . typesense_search_client_breaker_state();
}
harness_check(
    'A2.61 three 5xx in a row: server failures, the breaker opens on the third (trips 1)',
    $shape === array('server:500:closed', 'server:502:closed', 'server:500:open') && typesense_search_client_breaker_get()['trips'] === 1,
    json_encode($shape)
);
a2_reset();
a2_stub_plan(array(400, 422, 404, 401, 400));
$shape = array();
$r = typesense_search_client_search('stubcol', array('q' => '*'));
$shape[] = (string)$r['failure'] . ':' . $r['status'];
$r = typesense_search_client_multi_search(array(array('collection' => 'stubcol', 'q' => '*')))[0];
$shape[] = (string)$r['failure'] . ':' . $r['status'];
$r = typesense_search_client_collection_get('stubcol');
$shape[] = (string)$r['failure'] . ':' . $r['status'];
$shape[] = json_encode(typesense_search_client_version(true));
$r = typesense_search_client_delete('stubcol', '1');
$shape[] = (string)$r['failure'] . ':' . $r['status'];
harness_check(
    'A2.62 4xx answers never count: five in a row leave the breaker closed with nothing counted',
    $shape === array('client:400', 'client:422', 'client:404', 'null', 'client:400') && a2_closed(),
    json_encode($shape)
);

// The two timeouts.
$typesense_search_search_timeout = 1;
$typesense_search_import_timeout = 8;
a2_stub_plan(array(), array(2.5));
$r = typesense_search_client_search('stubcol', array('q' => '*'));
harness_check(
    'A2.63 the search timeout (1 s) cuts a slow answer: a transport failure after about a second',
    !$r['ok'] && $r['failure'] === 'transport' && $r['status'] === 0 && stripos($r['error'], 'timed out') !== false && $r['time'] >= 900 && $r['time'] < 2000,
    json_encode($r)
);
a2_stub_plan(array(), array(2.5));
$import = typesense_search_client_import('stubcol', array(a2_doc(1)));
harness_check(
    'A2.64 the import timeout (8 s) lets the same slow answer through for an import',
    $import['ok'] && $import['imported'] === 1 && $import['time'] >= 2400,
    json_encode($import)
);
$typesense_search_search_timeout = $saved['search_timeout'];
$typesense_search_import_timeout = $saved['import_timeout'];
a2_reset();

// ----------------------------------------------------------------------------------------- the breaker on a dead port
$dead = a2_free_port();
a2_point('http', '127.0.0.1', $dead);
$r1 = typesense_search_client_search('stubcol', array('q' => '*'));
$r2 = typesense_search_client_search('stubcol', array('q' => '*'));
harness_check(
    'A2.65 two connection failures: transport failures counted, the breaker still closed',
    $r1['failure'] === 'transport' && $r2['failure'] === 'transport' && $r1['status'] === 0
        && typesense_search_client_breaker_state() === 'closed' && typesense_search_client_breaker_get()['failures'] === 2,
    json_encode($r2)
);
$r3 = typesense_search_client_search('stubcol', array('q' => '*'));
$state = typesense_search_client_breaker_get();
harness_check(
    'A2.66 the third opens the breaker: open for the cooldown, one trip, the last error kept',
    $r3['failure'] === 'transport' && typesense_search_client_breaker_state() === 'open' && $state['trips'] === 1
        && $state['open_until'] >= time() + 29 && $state['open_until'] <= time() + 31 && $state['last_error'] !== ''
        && $state['failures'] === 3,
    json_encode($state)
);
a2_point('http', '127.0.0.1', $stub['port']);
a2_stub_clear();
$started = microtime(true);
$r = typesense_search_client_search('stubcol', array('q' => '*'));
$ms = (int)round((microtime(true) - $started) * 1000);
harness_check(
    'A2.67 while open a search is refused at once with no network call',
    !$r['ok'] && $r['failure'] === 'breaker' && $r['status'] === 0 && $ms < 50 && count(a2_stub_requests()) === 0,
    $ms . 'ms ' . json_encode($r)
);
$refused = array(
    typesense_search_client_import('stubcol', array(a2_doc(1)))['failure'],
    typesense_search_client_multi_search(array(array('collection' => 'stubcol', 'q' => '*')))[0]['failure'],
    typesense_search_client_collection_get('stubcol')['failure'],
    typesense_search_client_alias_get('stubalias')['failure'],
    typesense_search_client_metrics()['failure'],
    typesense_search_client_delete('stubcol', '1')['failure'],
    json_encode(typesense_search_client_collection_exists('stubcol')),
);
harness_check(
    'A2.68 every other operation is refused the same way, collection_exists answers null, nothing reaches the server',
    $refused === array('breaker', 'breaker', 'breaker', 'breaker', 'breaker', 'breaker', 'null') && count(a2_stub_requests()) === 0,
    json_encode($refused)
);
harness_check(
    'A2.69 /health still answers while open: the probe path, counted by nothing',
    typesense_search_client_health() === true && a2_stub_paths() === array('GET /health') && typesense_search_client_breaker_state() === 'open'
);
$state = typesense_search_client_breaker_get();
$state['open_until'] = time() - 1;
typesense_search_client_breaker_set($state);
harness_check('A2.70 after the cooldown the state reads half_open', typesense_search_client_breaker_state() === 'half_open');
a2_stub_clear();
$r = typesense_search_client_search('stubcol', array('q' => '*'));
harness_check(
    'A2.71 the next call probes /health first; the probe closes the breaker and the search goes through',
    $r['ok'] && a2_stub_paths() === array('GET /health', 'GET /collections/stubcol/documents/search') && a2_closed(),
    json_encode(a2_stub_paths()) . ' ' . typesense_search_client_breaker_state()
);
$state = typesense_search_client_breaker_get();
$state['open_until'] = time() - 1;
typesense_search_client_breaker_set($state);
a2_stub_plan(array(503));
a2_stub_clear();
$r = typesense_search_client_search('stubcol', array('q' => '*'));
$state = typesense_search_client_breaker_get();
harness_check(
    'A2.72 a failed probe opens the breaker for another cooldown without a new trip, and the call is refused',
    !$r['ok'] && $r['failure'] === 'breaker' && a2_stub_paths() === array('GET /health') && typesense_search_client_breaker_state() === 'open'
        && $state['trips'] === 1 && $state['open_until'] >= time() + 29,
    json_encode($state)
);

// ----------------------------------------------------------------------------------------- exceptions
a2_reset();
$r = typesense_search_client_call(array('method' => 'GET', 'path' => "/hea\0lth", 'role' => 'none', 'timeout' => 'search'));
harness_check(
    'A2.73 an exception inside the transport (curl refusing a NUL byte) is a transport failure that counts; nothing is thrown',
    !$r['ok'] && $r['failure'] === 'transport' && $r['status'] === 0 && strpos($r['error'], 'ValueError') === 0
        && typesense_search_client_breaker_get()['failures'] === 1,
    json_encode($r)
);
a2_reset();
$r = typesense_search_client_call(array('method' => 'POST', 'path' => '/multi_search', 'body' => array('searches' => array(array('q' => "bad \xB1"))), 'role' => 'search', 'timeout' => 'search'));
harness_check(
    'A2.74 a body that cannot be encoded is a client failure before any request',
    !$r['ok'] && $r['failure'] === 'client' && strpos($r['error'], 'could not be encoded') !== false && count(a2_stub_requests()) === 1 && a2_closed(),
    json_encode($r)
);

// ----------------------------------------------------------------------------------------- clean up
a2_stub_stop();
foreach ($saved as $name => $value) {
    $GLOBALS['typesense_search_' . $name] = $value;
}
a2_point_harness();
a2_reset();
typesense_search_client_collection_delete($c);
harness_check('A2.75 back on the harness server with the collection removed', typesense_search_client_collection_exists($c) === false && a2_closed());

harness_finish('A2_client');
