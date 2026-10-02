<?php
// Call one API function on the live system and print the response. For looking up field names, options and
// collections before writing cases.
// Usage: php live/api.php <ts|core> <function> [name=value ...] [--max=<characters to print, default 4000>]
require __DIR__ . '/client.php';

$who = $argv[1] ?? '';
$function = $argv[2] ?? '';
if (!in_array($who, array('ts', 'core'), true) || $function === '') {
    fwrite(STDERR, "Usage: php live/api.php <ts|core> <function> [name=value ...] [--max=N]\n");
    exit(1);
}

$params = array();
$max = 4000;
foreach (array_slice($argv, 3) as $arg) {
    if (strpos($arg, '--max=') === 0) {
        $max = (int)substr($arg, 6);
        continue;
    }
    $parts = explode('=', $arg, 2);
    $params[$parts[0]] = $parts[1] ?? '';
}

list($status, $data, $ms) = rs_api($who, $function, $params);
$text = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "HTTP $status, $ms ms\n" . substr($text, 0, $max) . (strlen($text) > $max ? "\n… (" . strlen($text) . " characters)\n" : "\n");
