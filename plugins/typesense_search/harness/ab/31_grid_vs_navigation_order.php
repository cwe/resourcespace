<?php
// Search grid (windowed) vs view-page next/previous (all rows) when the all-rows fetch exceeds $typesense_search_max_rows.
require __DIR__ . '/fixture.php';
for ($i = 0; $i < 620; $i++) {
    $ref = 1000 + $i;
    fx_resource($ref, 1, array('hit_count' => ($i * 37) % 101));
    $node = fx_set($ref, 8, 'bulk item ' . $i . ($i % 5 === 0 ? ' bulk bulk' : ''));
    fx_exec('UPDATE resource_node SET hit_count = ? WHERE resource = ? AND node = ?', array(($i * 53) % 97, $ref, $node));
    fx_set($ref, 12, sprintf('%04d-%02d-%02d', 1990 + ($i % 30), 1 + ($i % 12), 1 + ($i % 28)));
}
fx_index();
$base = array('restypes' => '', 'archive' => '0', 'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '', 'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false);
function run(string $search, array $o, bool $plugin): array {
    global $USE_PLUGIN, $PLUGIN_STATE;
    harness_reset_caches(); $USE_PLUGIN = $plugin; $PLUGIN_STATE = array('hook_reached' => false, 'served' => false);
    list($r) = harness_search($search, $o);
    $USE_PLUGIN = false;
    $rows = is_array($r) ? ($r['data'] ?? $r) : array();
    $refs = array(); foreach ($rows as $row) { if (is_array($row)) { $refs[] = (int)$row['ref']; } }
    return array($refs, $PLUGIN_STATE['served'] ? 'Typesense' : 'MySQL' . (isset($PLUGIN_STATE['reason']) ? ' (fallback)' : ''));
}
foreach (array(array('relevance', 'DESC'), array('date', 'DESC')) as $case) {
    foreach (array(25000, 500) as $max) {
        $typesense_search_max_rows = $max;
        list($grid, $grid_engine) = run('bulk', $base + array('order_by' => $case[0], 'sort' => $case[1], 'fetchrows' => array(0, 48)), true);
        list($nav, $nav_engine) = run('bulk', $base + array('order_by' => $case[0], 'sort' => $case[1], 'fetchrows' => -1), true);
        echo "\norder_by={$case[0]}  \$typesense_search_max_rows=$max\n";
        echo "    search page grid, first 8:        " . json_encode(array_slice($grid, 0, 8)) . "  [$grid_engine]\n";
        echo "    view page next/previous, first 8: " . json_encode(array_slice($nav, 0, 8)) . "  [$nav_engine]\n";
        echo '    => ' . (array_slice($nav, 0, 48) === $grid ? 'consistent' : 'INCONSISTENT: the resource after ' . $grid[0] . ' is ' . $grid[1] . ' in the grid and ' . ($nav[array_search($grid[0], $nav) + 1] ?? 'none') . ' by next/previous') . "\n";
    }
}
