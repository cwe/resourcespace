<?php
// Arguments of special searches when other terms follow them, and collection-gate variants.
require __DIR__ . '/fixture.php';
fx_index();

echo "\n################ R: special-search arguments and the collection gate ################\n";
ab('R1 contributions followed directly by a term with digits', '!contributions1,date:2024, sunset', array('archive' => '0'));
ab('R2 contributions followed directly by a term without digits', '!contributions1,title:sunset, harbour', array('archive' => '0'));
ab('R3 contributions plus keyword (space separated)', '!contributions1 sunset', array('archive' => '0'));
ab('R4 hasdata followed by a term with digits', '!hasdata12,date:2024');
ab('R5 list followed by keyword', '!list1:2:3:8 sunset');
ab('R6 collection followed by keyword', '!collection5 sunset', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('R7 last followed by field term', '!last20,title:sunset');
ab('R8 last followed by field term after a space', '!last20 title:sunset');

echo "\n---- collection gate\n";
ab('R9 another user\'s private collection (baseline)', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
$ignore_collection_access = true;
ab('R11 another user\'s private collection with $ignore_collection_access', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
$ignore_collection_access = false;
ab('R12 collection that does not exist', '!collection999', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('R13 collection 0', '!collection0', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('R14 collection with no number', '!collection', array('order_by' => 'collection', 'sort' => 'ASC'));
$userpermissions[] = 'a';
ab('R15 admin (a) viewing another user\'s private collection', '!collection6', array('order_by' => 'collection', 'sort' => 'ASC'));
ab('R16 admin (a) viewing a collection that does not exist', '!collection999', array('order_by' => 'collection', 'sort' => 'ASC'));
