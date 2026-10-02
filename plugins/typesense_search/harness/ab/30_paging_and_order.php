<?php
// Paging and ordering at scale: 620 extra resources matching one keyword.
require __DIR__ . '/fixture.php';

$base = 1000;
for ($i = 0; $i < 620; $i++) {
    $ref = $base + $i;
    $mod = sprintf('2021-%02d-%02d 08:00:00', 1 + ($i % 12), 1 + ($i % 28));
    fx_resource($ref, 1 + ($i % 3), array('modified' => $mod, 'creation_date' => '2021-01-01 00:00:00'));
    fx_set($ref, 8, 'bulk item ' . $i);
    if ($i % 10 === 0) {
        // no date at all
    } elseif ($i % 2 === 0) {
        fx_set($ref, 12, '2022-08-15');                               // many ties
    } elseif ($i % 7 === 0) {
        fx_set($ref, 12, (string)(1990 + ($i % 30)));                   // year only
    } else {
        fx_set($ref, 12, sprintf('%04d-%02d-%02d', 1990 + ($i % 30), 1 + ($i % 12), 1 + ($i % 28)));
    }
}
fx_index();

function cmp(string $label, string $search, array $opt): void
{
    global $USE_PLUGIN, $PLUGIN_STATE, $HARNESS;
    $o = $opt + array(
        'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 200), 'sort' => 'DESC',
        'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
        'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
    );
    $shape = function ($r) {
        if (!is_array($r)) { return array('kind' => 'non-array ' . json_encode($r), 'total' => null, 'rows' => array(), 'pad' => 0); }
        if (isset($r['data'])) { $rows = $r['data']; $kind = 'structured'; $total = $r['total']; }
        else { $rows = $r; $kind = 'legacy'; $total = count($r); }
        $refs = array(); $pad = 0;
        foreach ($rows as $row) { if (is_array($row)) { $refs[] = (int)$row['ref']; } else { $pad++; } }
        return array('kind' => $kind, 'total' => $total, 'rows' => $refs, 'pad' => $pad);
    };
    harness_reset_caches(); $USE_PLUGIN = false;
    list($c) = harness_search($search, $o); $c = $shape($c);
    harness_reset_caches(); $USE_PLUGIN = true; $PLUGIN_STATE = array('hook_reached' => false, 'served' => false);
    $t = microtime(true);
    list($p) = harness_search($search, $o); $p = $shape($p);
    $ms = round((microtime(true) - $t) * 1000);
    $USE_PLUGIN = false;
    $how = !$PLUGIN_STATE['hook_reached'] ? 'not consulted' : (isset($PLUGIN_STATE['error']) ? 'ERROR ' . $PLUGIN_STATE['error'] : ($PLUGIN_STATE['served'] ? 'served' : 'fell back (' . ($PLUGIN_STATE['reason'] ?? '?') . ')'));
    $first = null;
    $n = max(count($c['rows']), count($p['rows']));
    for ($i = 0; $i < $n; $i++) { if (($c['rows'][$i] ?? null) !== ($p['rows'][$i] ?? null)) { $first = $i; break; } }
    $a = $c['rows']; $b = $p['rows']; sort($a); sort($b);
    echo "\n$label: " . json_encode($search) . ' ' . json_encode(array_intersect_key($opt, array_flip(array('order_by', 'sort', 'fetchrows', 'return_refs_only')))) . "\n";
    echo "    core:   {$c['kind']} total={$c['total']} rows=" . count($c['rows']) . " padding={$c['pad']}\n";
    echo "    plugin: $how {$p['kind']} total={$p['total']} rows=" . count($p['rows']) . " padding={$p['pad']}  ({$ms} ms)\n";
    echo '    => ' . ($c['kind'] === $p['kind'] && $c['total'] === $p['total'] && $c['pad'] === $p['pad'] && $first === null ? 'IDENTICAL (same rows, same order)' : ($a === $b ? 'same rows, order differs from position ' . $first . ' (core ' . json_encode(array_slice($c['rows'], $first, 5)) . ' plugin ' . json_encode(array_slice($p['rows'], $first, 5)) . ')' : 'DIFFERENT ROWS (first difference at position ' . json_encode($first) . ': core ' . json_encode(array_slice($c['rows'], (int)$first, 5)) . ' plugin ' . json_encode(array_slice($p['rows'], (int)$first, 5)) . ')')) . "\n";
}

echo "\n################ BIG: paging and order ################\n";
foreach (array('DESC', 'ASC') as $sort) {
    cmp('B1 date sort, first page', 'bulk', array('order_by' => 'date', 'sort' => $sort, 'fetchrows' => array(0, 48)));
    cmp('B2 date sort, page starting at 240 (crosses the 250 boundary)', 'bulk', array('order_by' => 'date', 'sort' => $sort, 'fetchrows' => array(240, 48)));
    cmp('B3 date sort, window of 400 from 100', 'bulk', array('order_by' => 'date', 'sort' => $sort, 'fetchrows' => array(100, 400)));
    cmp('B4 date sort, last partial page', 'bulk', array('order_by' => 'date', 'sort' => $sort, 'fetchrows' => array(600, 48)));
    cmp('B5 date sort, all rows (-1)', 'bulk', array('order_by' => 'date', 'sort' => $sort, 'fetchrows' => -1));
    cmp('B6 modified sort, all rows', 'bulk', array('order_by' => 'modified', 'sort' => $sort, 'fetchrows' => -1));
    cmp('B7 resource id sort, all rows', 'bulk', array('order_by' => 'resourceid', 'sort' => $sort, 'fetchrows' => -1));
}
cmp('B8 integer fetchrows 300 (legacy padded array)', 'bulk', array('order_by' => 'date', 'fetchrows' => 300));
cmp('B9 integer fetchrows 1 (count idiom used by CSV export)', 'bulk', array('order_by' => 'date', 'fetchrows' => 1));
cmp('B10 offset past the end', 'bulk', array('order_by' => 'date', 'fetchrows' => array(5000, 48)));
cmp('B11 refs only, all rows in one window', 'bulk', array('order_by' => 'date', 'fetchrows' => array(0, 100000), 'return_refs_only' => true));
cmp('B12 refs only, integer -1', 'bulk', array('order_by' => 'resourceid', 'fetchrows' => -1, 'return_refs_only' => true));
cmp('B13 empty search, date sort, all rows', '', array('order_by' => 'date', 'fetchrows' => -1));
cmp('B14 [0,-1] window (save search to collection)', 'bulk', array('order_by' => 'resourceid', 'fetchrows' => array(0, -1)));
cmp('B15 fetchrows 0', 'bulk', array('order_by' => 'resourceid', 'fetchrows' => 0));
cmp('B16 window size 0', 'bulk', array('order_by' => 'resourceid', 'fetchrows' => array(0, 0)));
cmp('B17 !last500 date sort', '!last500', array('order_by' => 'date', 'fetchrows' => array(0, 600)));
cmp('B18 !list of 300 refs', '!list' . implode(':', range(1000, 1299)), array('order_by' => 'resourceid', 'fetchrows' => array(0, 600)));
$typesense_search_max_rows = 500;
cmp('B19 all rows with $typesense_search_max_rows = 500', 'bulk', array('order_by' => 'date', 'fetchrows' => -1));
