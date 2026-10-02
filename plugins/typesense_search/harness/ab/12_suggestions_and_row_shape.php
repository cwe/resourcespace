<?php
// Zero-result suggestions and the columns of collection result rows.
require __DIR__ . '/fixture.php';
fx_index();
ab('K1 two known words that never co-occur (core suggests dropping one)', 'zeppelin harbour');
ab('K2 one known word, no visible matches', 'classified');
ab('K3 collection rows: extra columns', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC', 'fetchrows' => array(0, 1)));
// Show the row keys each side returns for a collection search.
harness_reset_caches(); $USE_PLUGIN = false;
list($c) = harness_search('!collection5', array('restypes' => '', 'order_by' => 'collection', 'archive' => '0', 'fetchrows' => array(0, 1), 'sort' => 'ASC', 'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '', 'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false));
harness_reset_caches(); $USE_PLUGIN = true;
list($p) = harness_search('!collection5', array('restypes' => '', 'order_by' => 'collection', 'archive' => '0', 'fetchrows' => array(0, 1), 'sort' => 'ASC', 'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '', 'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false));
$USE_PLUGIN = false;
echo 'columns only in core rows:   ' . implode(', ', array_diff(array_keys($c['data'][0]), array_keys($p['data'][0]))) . "\n";
echo 'columns only in plugin rows: ' . implode(', ', array_diff(array_keys($p['data'][0]), array_keys($c['data'][0]))) . "\n";
echo 'score: core=' . json_encode($c['data'][0]['score']) . ' plugin=' . json_encode($p['data'][0]['score']) . "\n";
