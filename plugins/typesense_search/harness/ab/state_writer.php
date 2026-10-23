<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// The second writer of the A6 battery's two-process case. Boots the harness on the file database named by
// HARNESS_DB (the tables are already there), says READY, waits for a line on stdin, then increments the counter
// named in the first argument as many times as the second says and reports how many SQL errors it met.
// Usage: HARNESS_DB=<file> php state_writer.php <entry> <times>
require __DIR__ . '/boot.php';

$name = $argv[1] ?? 'validation_drops';
$times = (int)($argv[2] ?? 100);

echo "READY\n";
fgets(STDIN);
for ($i = 0; $i < $times; $i++) {
    typesense_search_state_increment($name);
}
echo 'DONE ' . $times . ' errors=' . count($HARNESS['sql_errors']) . "\n";
