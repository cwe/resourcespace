<?php

// A canned Typesense for the A2 client battery, run by PHP's built-in server from the battery itself. It answers
// every endpoint the client knows with a minimal body, records each request as one JSON line (method, URI, the
// key header, content type, body) in STUB_DIR/requests.jsonl, and consumes STUB_DIR/plan.json first: its
// "statuses" and "delays" lists each give up one entry per request (an HTTP status of 400 or more to answer with
// instead, a pause in seconds before answering). Never a real server, never real data.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$dir = (string)getenv('STUB_DIR');
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = (string)parse_url($uri, PHP_URL_PATH);
$body = (string)file_get_contents('php://input');

$lock = fopen($dir . '/plan.lock', 'c');
flock($lock, LOCK_EX);
$plan = is_file($dir . '/plan.json') ? json_decode((string)file_get_contents($dir . '/plan.json'), true) : array();
$status = empty($plan['statuses']) ? 0 : (int)array_shift($plan['statuses']);
$delay = empty($plan['delays']) ? 0.0 : (float)array_shift($plan['delays']);
file_put_contents($dir . '/plan.json', json_encode($plan));
file_put_contents($dir . '/requests.jsonl', json_encode(array(
    'method' => $method,
    'uri' => $uri,
    'key' => $_SERVER['HTTP_X_TYPESENSE_API_KEY'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => $body,
)) . "\n", FILE_APPEND);
flock($lock, LOCK_UN);
fclose($lock);

if ($delay > 0) {
    usleep((int)($delay * 1000000));
}
header('Content-Type: application/json');
if ($status >= 400) {
    http_response_code($status);
    echo json_encode(array('message' => 'stub answered ' . $status));
    exit;
}
if ($path === '/health') {
    echo '{"ok":true}';
} elseif ($path === '/debug') {
    echo '{"state":1,"version":"30.2"}';
} elseif ($path === '/metrics.json') {
    echo json_encode(array('system_memory_total_bytes' => '1', 'typesense_memory_active_bytes' => '1'));
} elseif ($path === '/multi_search') {
    $n = count(json_decode($body, true)['searches'] ?? array());
    $one = array('found' => 0, 'hits' => array(), 'page' => 1);
    echo json_encode(array('results' => array_fill(0, max(1, $n), $one)));
} elseif (preg_match('#^/collections/[^/]+/documents/import$#', $path) === 1) {
    header('Content-Type: text/plain');
    echo implode("\n", array_fill(0, max(1, count(explode("\n", trim($body)))), '{"success":true}'));
} elseif (preg_match('#^/collections/[^/]+/documents/search$#', $path) === 1) {
    echo json_encode(array('found' => 0, 'hits' => array(), 'page' => 1));
} elseif (preg_match('#^/collections/[^/]+/documents/([^/]+)$#', $path, $m) === 1) {
    echo json_encode(array('id' => rawurldecode($m[1])));
} elseif (preg_match('#^/collections/[^/]+/documents$#', $path) === 1) {
    echo '{"num_deleted":0}';
} elseif ($path === '/collections' && $method === 'POST') {
    http_response_code(201);
    echo $body;
} elseif (preg_match('#^/collections/([^/]+)$#', $path, $m) === 1) {
    echo json_encode(array('name' => rawurldecode($m[1]), 'fields' => array(), 'num_documents' => 0));
} elseif (preg_match('#^/aliases/([^/]+)$#', $path, $m) === 1) {
    echo json_encode(array('name' => rawurldecode($m[1]), 'collection_name' => 'stub'));
} else {
    http_response_code(404);
    echo '{"message":"Not Found"}';
}
