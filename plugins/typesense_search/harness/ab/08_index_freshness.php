<?php
// What the index shows after data changes with no reindex, the save hook, and the related-keyword sync.
require __DIR__ . '/fixture.php';
fx_index();

echo "\n################ INDEX FRESHNESS ################\n";
// 1. Edit a resource's title, then fire the plugin's real save hook, and see whether the index follows.
ab('G0 before the edit', 'harbour');
fx_exec("UPDATE node SET name = 'Sunrise over the marina' WHERE ref IN (SELECT rn.node FROM resource_node rn JOIN node n ON n.ref = rn.node WHERE rn.resource = 1 AND n.resource_type_field = 8)");
fx_exec("UPDATE resource SET field8 = 'Sunrise over the marina' WHERE ref = 1");
// core re-indexes the node's keywords when a value changes
$node = harness_run("SELECT n.* FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE rn.resource = 1 AND n.resource_type_field = 8", array())[0];
remove_all_node_keyword_mappings($node['ref']);
add_node_keyword_mappings($node, null);

$ref = 1;
$GLOBALS['ref'] = 1;
@unlink($debug_log_location);
$hook_result = HookTypesense_searchAllAftersaveresourcedata();
echo "plugin save hook called for resource 1; debug log says:\n";
foreach (is_file($debug_log_location) ? file($debug_log_location) : array() as $line) {
    if (strpos($line, 'typesense_search') !== false) {
        echo '    ' . trim(preg_replace('/^\S+ \S+ /', '', $line)) . "\n";
    }
}
$doc = typesense_search_request('GET', '/collections/' . harness_collection() . '/documents/1');
echo 'Typesense document 1 title after the hook: ' . json_encode($doc['title'] ?? null) . "\n";
ab('G1 new title word after edit + save hook', 'marina');
ab('G2 old title word after edit + save hook', 'harbour');

// 2. State changes without a reindex.
fx_exec("UPDATE resource SET archive = 3 WHERE ref = 8");       // deleted (soft)
fx_exec("UPDATE resource SET access = 2 WHERE ref = 12");       // made confidential
fx_exec("DELETE FROM resource WHERE ref = 37");                 // hard delete
fx_resource(50, 1);
fx_set(50, 8, 'Brand new sunset');                              // new upload
ab('G3 after soft-delete of 8, 12 made confidential, 37 hard-deleted, 50 created', 'sunset');
ab('G4 same, count only', 'sunset', array('fetchrows' => array(0, 0)));
fx_exec("DELETE FROM collection_resource WHERE collection = 5 AND resource = 3");
ab('G5 collection after removing resource 3 from it', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'));

echo "\n################ RELATED KEYWORDS SYNC ################\n";
@unlink($debug_log_location);
typesense_search_sync_related_keywords();
foreach (is_file($debug_log_location) ? file($debug_log_location) : array() as $line) {
    if (strpos($line, 'typesense_search_request') !== false) {
        echo '    ' . trim(preg_replace('/^\S+ \S+ /', '', $line)) . "\n";
    }
}
