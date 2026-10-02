<?php
// Second pass: the CSV export call shape with the plugin's master toggle off.
require __DIR__ . '/fixture.php';
fx_index();
$typesense_search_enabled = false;
ab('W1 CSV export call with the plugin toggle off ($typesense_search_enabled = false)', 'sunset', array('smartsearch' => null, 'return_refs_only' => true, 'fetchrows' => array(0, 100000), 'order_by' => ''));
ab('W2 ordinary search with the toggle off', 'sunset');
