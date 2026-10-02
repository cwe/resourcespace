<?php
// get_resource_access() for a user with a search filter (it runs a !resource search).
require __DIR__ . '/fixture.php';
fx_index();

function access_ab(string $label, int $ref): void
{
    global $USE_PLUGIN, $HARNESS;
    $names = array(0 => 'open', 1 => 'restricted', 2 => 'CONFIDENTIAL (denied)', 3 => 'custom');
    $out = array();
    foreach (array(false, true) as $plugin) {
        harness_reset_caches();
        $GLOBALS['resource_access_cache'] = array(); $GLOBALS['get_resource_data_cache'] = array();
        $USE_PLUGIN = $plugin;
        $HARNESS['sql_errors'] = array();
        try {
            $a = get_resource_access($ref);
        } catch (\Throwable $e) {
            $a = 'ERROR ' . $e->getMessage();
        }
        $out[] = ($plugin ? 'plugin' : 'core') . ': ' . ($names[$a] ?? $a);
    }
    $USE_PLUGIN = false;
    echo str_pad($label, 78) . implode('   |   ', $out) . "\n";
    foreach (array_unique($HARNESS['sql_errors']) as $e) {
        echo '      (SQL error: ' . substr($e, 0, 200) . ")\n";
    }
}

echo "\n################ get_resource_access() FOR A USER WITH A SEARCH FILTER ################\n";
echo "(filter 7 = resource must have country France)\n";
$usersearchfilter = 7;
access_ab('resource 1 (France)', 1);
access_ab('resource 2 (United Kingdom)', 2);

// Metadata changes with no reindex.
fx_exec("DELETE FROM resource_node WHERE resource = 1 AND node = 201");   // 1 is no longer France
fx_tag(2, 201);                                                           // 2 is now France
fx_resource(60, 1);
fx_set(60, 8, 'New upload');
fx_tag(60, 201);                                                          // new France resource
access_ab('resource 1 after France is removed from it (no reindex)', 1);
access_ab('resource 2 after France is added to it (no reindex)', 2);
access_ab('resource 60, uploaded after the last reindex, France', 60);
$usersearchfilter = '';
