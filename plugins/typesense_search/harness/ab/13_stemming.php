<?php
// Variant: $stemming = true (as on the test system). Must be set before the fixture is indexed on both sides.
$GLOBALS['HARNESS_STEMMING'] = true;
require __DIR__ . '/boot.php';
$stemming = true;
include_once $ROOT . '/lib/stemming/en.php';
require __DIR__ . '/fixture_body.php';

// Word-form pairs: stored form => searched form.
$pairs = array(
    'foxes' => 'fox', 'dancing' => 'dance', 'sculptures' => 'sculpture', 'photography' => 'photograph', 'photographic' => 'photography',
    'children' => 'child', 'women' => 'woman', 'running' => 'run', 'ran' => 'run', 'organisation' => 'organise', 'organization' => 'organizations',
    'painted' => 'paint', 'paintings' => 'painting', 'painter' => 'paint', 'archival' => 'archive', 'archives' => 'archive',
    'generously' => 'generous', 'university' => 'universe', 'universal' => 'universe', 'news' => 'new', 'glasses' => 'glass',
    'historic' => 'history', 'historical' => 'historic', 'studies' => 'study', 'studying' => 'study', 'flies' => 'fly',
    'mice' => 'mouse', 'geese' => 'goose', 'better' => 'good', 'houses' => 'house', 'housing' => 'house',
    'lighting' => 'light', 'lights' => 'light', 'maker' => 'make', 'making' => 'make', 'exhibited' => 'exhibition', 'exhibitions' => 'exhibition',
    'ceramics' => 'ceramic', 'artistic' => 'artist', 'arts' => 'art',
);
$n = 100;
$map = array();
foreach ($pairs as $stored => $searched) {
    fx_resource($n, 1);
    fx_set($n, 8, 'zq' . $n . ' ' . $stored);
    $map[$n] = array($stored, $searched);
    $n++;
}
fx_resource(70, 1);
fx_set(70, 8, 'Yorkshire sculpture park entrance');
fx_resource(71, 1);
fx_set(71, 8, 'Park sculpture trail');
fx_index();

echo "\n################ A/B WITH \$stemming = true ################\n";
ab('S1 plural of a stored singular', 'cars');
ab('S2 quoted phrase, plural', '"red cars"');
ab('S3 quoted phrase, exact', '"red car"');
ab('S4 field value plural', 'title:launches');
ab('S5 stop word', 'the sunset');
ab('S6 last word prefix of an unrelated stem', 'car');
ab('S7 negative plural', 'launch -ships');

echo "\n---- quoted phrases: Typesense does not stem the words of a quoted phrase, the index holds stems\n";
echo 'stems: sculpture=' . GetStem('sculpture') . ' park=' . GetStem('park') . ' party=' . GetStem('party') . ' sports=' . GetStem('sports') . "\n";
ab('S8 phrase, both words unchanged by the stemmer', '"red car"');
ab('S9 phrase, second word changed by the stemmer (party -> parti)', '"launch party"');
ab('S10 phrase, first word changed (sculpture -> sculptur)', '"sculpture park"');
ab('S11 phrase, middle word changed (sports -> sport)', '"red sports car"');
ab('S12 the same words unquoted', 'sculpture park');
ab('S13 quoted single word changed by the stemmer', '"sculpture"');
ab('S14 negative phrase', 'yorkshire -"sculpture park"');
harness_probe('Typesense directly: q="sculpture park" (as typed)', array('q' => '"sculpture park"', 'prefix' => 'false'));
harness_probe('Typesense directly: q="sculptur park" (stems)', array('q' => '"sculptur park"', 'prefix' => 'false'));

echo "\n---- stemmer agreement: does searching <form B> find the resource that stores <form A>?\n";
$agree = 0;
$rows = array();
foreach ($map as $ref => $p) {
    list($stored, $searched) = $p;
    $HARNESS['sql_errors'] = array();
    harness_reset_caches();
    $USE_PLUGIN = false;
    list($core_result) = harness_search($searched, array('restypes' => '', 'order_by' => 'resourceid', 'archive' => '0', 'fetchrows' => array(0, 500), 'sort' => 'DESC', 'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '', 'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false));
    $core = in_array($ref, harness_refs($core_result)['refs'], true);
    // Plugin side asked directly, with prefix off, so that only stemming is measured.
    $r = typesense_search_request('POST', '/multi_search', false, array('searches' => array(array('collection' => harness_collection(), 'q' => $searched, 'query_by' => 'title', 'prefix' => 'false', 'num_typos' => 0, 'drop_tokens_threshold' => 0, 'filter_by' => 'ref:=' . $ref, 'include_fields' => 'ref'))));
    $ts = (int)($r['results'][0]['found'] ?? 0) > 0;
    $agree += ($core === $ts) ? 1 : 0;
    $rows[] = sprintf('    %-14s searched as %-14s core(RS stem %s / %s)=%s  typesense=%s%s', $stored, $searched, GetStem($stored), GetStem($searched), $core ? 'match' : 'no', $ts ? 'match' : 'no', $core === $ts ? '' : '   <== differs');
}
echo implode("\n", $rows) . "\n";
echo "    agreement: $agree of " . count($map) . "\n";
