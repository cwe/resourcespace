<?php
// Wildcard completeness (Typesense max_candidates) and split/join fallback.
require __DIR__ . '/fixture.php';

// 40 resources, each with a different word starting "zeb".
$words = array();
for ($i = 0; $i < 40; $i++) {
    $w = 'zeb' . chr(97 + intdiv($i, 26)) . chr(97 + ($i % 26)) . 'x';
    $words[] = $w;
    fx_resource(200 + $i, 1);
    fx_set(200 + $i, 8, 'Specimen ' . $w);
}
// The same word in many resources plus a few rare completions.
for ($i = 0; $i < 12; $i++) {
    fx_resource(300 + $i, 1);
    fx_set(300 + $i, 8, 'Harbourside walk ' . $i);
}
fx_resource(320, 1); fx_set(320, 8, 'Harbourmaster office');
fx_resource(321, 1); fx_set(321, 8, 'Harbours of Europe');
fx_resource(322, 1); fx_set(322, 8, 'Harbourfront festival');
fx_resource(323, 1); fx_set(323, 8, 'Harbouring doubts');
fx_resource(324, 1); fx_set(324, 8, 'Harbourview hotel');

// split / join fallback
fx_resource(330, 1); fx_set(330, 8, 'Sun dial');
fx_resource(331, 1); fx_set(331, 8, 'Set piece');
fx_resource(332, 1, array('archive' => 2)); fx_set(332, 8, 'foobar');   // archived: keyword exists, resource not visible
fx_resource(333, 1); fx_set(333, 8, 'Basket ball court');
fx_resource(334, 1, array('archive' => 2)); fx_set(334, 8, 'basketball');
fx_index();

echo "\n################ Q: wildcard completeness and split/join ################\n";
ab('Q1 wildcard with 40 different completions', 'zeb*', array('order_by' => 'resourceid'));
ab('Q2 wildcard in a field', 'title:zeb*', array('order_by' => 'resourceid'));
ab('Q3 wildcard, one common and several rare completions', 'harbour*', array('order_by' => 'resourceid'));
ab('Q4 plain word that is a prefix of many (default prefix)', 'harbour', array('order_by' => 'resourceid'));
ab('Q5 wildcard plus another word', 'specimen zeb*', array('order_by' => 'resourceid'));
$wildcard_always_applied = true;
ab('Q6 $wildcard_always_applied', 'zeb', array('order_by' => 'resourceid'));
$wildcard_always_applied = false;
echo "\n---- split / join of words when nothing matches\n";
ab('Q7 two words that only exist joined', 'sun set', array('order_by' => 'resourceid'));
ab('Q8 one word that only exists split (visible) ', 'foobar', array('order_by' => 'resourceid'));
ab('Q9 basketball vs basket ball', 'basketball', array('order_by' => 'resourceid'));

echo "\n---- the same asked of Typesense directly: which request parameters lift the limits\n";
harness_probe('q=zeb prefix=true (the plugin\'s wildcard form)', array('q' => 'zeb', 'prefix' => 'true'));
harness_probe('q=zeb prefix=true max_candidates=4', array('q' => 'zeb', 'prefix' => 'true', 'max_candidates' => 4));
harness_probe('q=zeb prefix=true max_candidates=100', array('q' => 'zeb', 'prefix' => 'true', 'max_candidates' => 100));
harness_probe('q=zeb prefix=true max_candidates=10000', array('q' => 'zeb', 'prefix' => 'true', 'max_candidates' => 10000));
harness_probe('q=zeb prefix=true exhaustive_search=true', array('q' => 'zeb', 'prefix' => 'true', 'exhaustive_search' => true));
harness_probe('q=specimen zeb prefix=true', array('q' => 'specimen zeb', 'prefix' => 'true'));
harness_probe('q=specimen zeb prefix=true exhaustive_search=true', array('q' => 'specimen zeb', 'prefix' => 'true', 'exhaustive_search' => true));
harness_probe('filter field_8_s:zeb*', array('q' => '*', 'filter_by' => 'field_8_s:zeb*'));
harness_probe('filter field_8_s:zeb* max_filter_by_candidates=100', array('q' => '*', 'filter_by' => 'field_8_s:zeb*', 'max_filter_by_candidates' => 100));
harness_probe('filter field_8_s:zeb* max_filter_by_candidates=10000', array('q' => '*', 'filter_by' => 'field_8_s:zeb*', 'max_filter_by_candidates' => 10000));
harness_probe('q=sun set (default split_join_tokens)', array('q' => 'sun set', 'prefix' => 'false', 'filter_by' => 'archive:=0'));
harness_probe('q=sun set split_join_tokens=off', array('q' => 'sun set', 'prefix' => 'false', 'filter_by' => 'archive:=0', 'split_join_tokens' => 'off'));
harness_probe('q=basketball (default split_join_tokens)', array('q' => 'basketball', 'prefix' => 'false', 'filter_by' => 'archive:=0'));
harness_probe('q=basketball split_join_tokens=off', array('q' => 'basketball', 'prefix' => 'false', 'filter_by' => 'archive:=0', 'split_join_tokens' => 'off'));
