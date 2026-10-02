<?php
// Does the candidate cap ever drop an exact match for a plain (non-wildcard) word?
require __DIR__ . '/fixture.php';
for ($i = 0; $i < 60; $i++) {
    $w = 'zeb' . chr(97 + intdiv($i, 26)) . chr(97 + ($i % 26)) . 'x';
    // rarer exact word, far more frequent longer words
    for ($j = 0; $j < 3; $j++) {
        $ref = 2000 + $i * 3 + $j;
        fx_resource($ref, 1);
        fx_set($ref, 8, 'Specimen ' . $w . ' copy ' . $j);
    }
}
fx_resource(5000, 1); fx_set(5000, 8, 'Lonely zeb');          // the exact word, once
fx_resource(5001, 1); fx_set(5001, 18, 'A zeb in a caption');  // the exact word in another field
fx_index();
echo "\n################ U: exact word vs many longer words ################\n";
$r = ab('U1 plain word, 60 longer words share its prefix', 'zeb', array('order_by' => 'resourceid', 'fetchrows' => array(0, 500)));
echo '    exact-word resources in plugin result: ' . json_encode(array_values(array_intersect(array(5000, 5001), $r['plugin']['refs']))) . "\n";
$r = ab('U2 two plain words', 'lonely zeb', array('order_by' => 'resourceid'));
$r = ab('U3 plain word first, prefix applies to the other word', 'zeb lonely', array('order_by' => 'resourceid'));
$r = ab('U4 field search for the exact word', 'title:zeb', array('order_by' => 'resourceid'));
$r = ab('U5 wildcard', 'zeb*', array('order_by' => 'resourceid', 'fetchrows' => array(0, 500)));
echo '    plugin returned ' . count($r['plugin']['refs']) . ' of ' . count($r['core']['refs']) . "\n";
