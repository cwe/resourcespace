<?php
// Core only (plugin off). SQLite stands in for MySQL.
require __DIR__ . '/fixture.php';

$DEF = array('restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 200), 'sort' => 'DESC',
    'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
    'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false);

function core(string $search, array $opt = array()) {
    global $DEF, $USE_PLUGIN;
    harness_reset_caches();
    $USE_PLUGIN = false;
    list($r) = harness_search($search, $opt + $DEF);
    return $r;
}
function show(string $label, $r): void {
    if (!is_array($r)) { echo str_pad($label, 58) . json_encode($r) . "\n"; return; }
    $refs = array();
    foreach ($r['data'] as $row) { $refs[] = $row['ref']; }
    echo str_pad($label, 58) . 'total=' . $r['total'] . ' refs=' . json_encode($refs) . "\n";
}

echo "===== BUG 1: !last with a node search =====\n";
show('@@201 (France)', core('@@201'));
show('!last10', core('!last10'));
show('!last10, @@201', core('!last10, @@201'));
show('@@201@@203 (France or Spain)', core('@@201@@203'));
show('!last10, @@201@@203', core('!last10, @@201@@203'));
show('@@203 @@301 (Spain and sculpture: nothing)', core('@@203 @@301'));
$r = core('!last10, @@203 @@301');
show('!last10, @@203 @@301', $r);
echo '   first row of that result: ' . json_encode(array_intersect_key($r['data'][0] ?? array(), array_flip(array('ref', 'resource_type', 'archive', 'total_hit_count', 'score')))) . "\n";

$q = core('!last10, @@201', array('returnsql' => true));
$sql = preg_replace('/\s+/', ' ', $q->sql);
$sql = fake_shorten($sql);
echo "\nSQL core builds for  !last10, @@201 :\n" . wordwrap($sql, 150, "\n") . "\n";

$q = core('@@201', array('returnsql' => true));
echo "\nSQL core builds for  @@201  (no !last):\n" . wordwrap(fake_shorten(preg_replace('/\s+/', ' ', $q->sql)), 150, "\n") . "\n";

function fake_shorten(string $sql): string {
    $sql = preg_replace('/r\.ref, r\.resource_type, r\.has_image.*?rty\.order_by ,/', 'r.ref, …columns…,', $sql);
    $sql = str_replace(" LEFT OUTER JOIN resource_custom_access rca2 ON r.ref=rca2.resource AND rca2.user = ? AND (rca2.user_expires IS null or rca2.user_expires>now()) AND rca2.access<>2 LEFT OUTER JOIN resource_custom_access rca ON r.ref=rca.resource AND rca.usergroup = ? AND rca.access<>2", ' …access joins…', $sql);
    $sql = str_replace(" JOIN resource_type AS rty ON r.resource_type = rty.ref", '', $sql);
    $sql = preg_replace('/WHERE \(r\.access<>.*?r\.access=3\)/', 'WHERE …standard filter…', $sql);
    return $sql;
}

echo "\n===== BUG 2: exact value on a date-range field =====\n";
echo "fixture: resource 14 has eventdates 2024-03-01 to 2024-03-10\n";
show('eventdates:2024-03-05 (inside the range)', core('eventdates:2024-03-05'));
show('eventdates:2024-03-01 (the start date)', core('eventdates:2024-03-01'));
show('eventdates:2024', core('eventdates:2024'));

$q = core('eventdates:2024-03-05', array('returnsql' => true));
$sql = preg_replace('/\s+/', ' ', $q->sql);
preg_match('/JOIN resource_node drrn1s.*?<= \?/', $sql, $m);
echo "\njoin core builds:\n" . wordwrap($m[0], 150, "\n") . "\n";
$p = $q->parameters;
// locate the four join parameters: i,91,i,91,s,val,s,val
for ($i = 0; $i + 7 < count($p); $i += 2) {
    if ($p[$i + 1] == 91 && $p[$i + 3] == 91 && $p[$i + 5] === '2024-03-05') { break; }
}
echo 'parameters core binds to those four "?", in order: ' . json_encode(array_slice($p, $i, 8)) . "\n";

foreach (array('2024-03-05', '2024-03-01', '2024-03-20', '2024') as $val) {
    $q = core('eventdates:' . $val, array('returnsql' => true));
    $p = $q->parameters;
    for ($i = 0; $i + 7 < count($p); $i += 2) {
        if ($p[$i + 1] == 91 && $p[$i + 3] == 91 && $p[$i + 5] === $val) { break; }
    }
    $fixed = $p;
    // intended order: field, value, field, value
    $fixed[$i + 2] = 's'; $fixed[$i + 3] = $val; $fixed[$i + 4] = 'i'; $fixed[$i + 5] = 91;
    $rows = harness_run($q->sql, $fixed);
    echo str_pad('same SQL, parameters in the intended order, value ' . $val, 62) . 'refs=' . json_encode(array_column($rows, 'ref')) . "\n";
}
if ($HARNESS['sql_errors']) { print_r(array_unique($HARNESS['sql_errors'])); }
