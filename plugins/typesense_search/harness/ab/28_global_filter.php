<?php
// Second pass: $typesense_search_global_filter on served and on fallback searches.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n################ V: \$typesense_search_global_filter ################\n";
$typesense_search_global_filter = ' && resource_type:=2';
ab('V1 served search with a global filter (type 2 only)', 'launch');
ab('V2 same search, sort the plugin does not serve', 'launch', array('order_by' => 'popularity'));
ab('V3 same search, special the plugin does not serve', '!nopreview launch');
