<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(401);
    exit('Access denied - Command line only!');
}

// typesense_search: the one test core's runner (tests/test.php) finds for the plugin. It runs the harness smoke
// battery from ../harness/ when that directory is present (it is left out of the release) and a local Typesense
// can be reached or started, and passes quietly otherwise. The battery exits 0 green, 1 red, 2 skipped.
$battery = dirname(__DIR__) . '/harness/ab/H1_smoke.php';
if (!is_file($battery)) {
    return true;
}

$output = array();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($battery) . ' 2>&1', $output, $code);
if (defined('RS_TEST_DEBUG') && RS_TEST_DEBUG) {
    echo implode(PHP_EOL, $output) . PHP_EOL;
}
if ($code === 0 || $code === 2) {
    return true;
}

echo PHP_EOL . 'typesense_search smoke battery: ' . (end($output) ?: 'no output') . PHP_EOL;
return false;
