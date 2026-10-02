<?php
// Direct probes of the private Typesense on the standard fixture: the default prefix behaviour and how
// HTML and translated values were tokenised. Bypasses the plugin's query builder.
require __DIR__ . '/fixture.php';
fx_index();

$QUERY_BY = 'title,field_18_text,field_3_ss,field_1_ss,field_92_text,field_51_s,field_51_p';
$schema = typesense_search_request('GET', '/collections/' . harness_collection());
echo 'token_separators: ' . json_encode($schema['token_separators']) . '  symbols_to_index: ' . json_encode($schema['symbols_to_index']) . "\n";
$doc = typesense_search_request('GET', '/collections/' . harness_collection() . '/documents/7');
echo 'doc 7: ' . json_encode(array_intersect_key($doc, array_flip(array('title', 'field_3_ss', 'nodes'))), JSON_UNESCAPED_UNICODE) . "\n";
$doc = typesense_search_request('GET', '/collections/' . harness_collection() . '/documents/4');
echo 'doc 4: ' . json_encode(array_intersect_key($doc, array_flip(array('title', 'field_92_text', 'field_12_q', 'field_12_range_start'))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n-- default prefix behaviour (the plugin omits the prefix parameter unless a wildcard is used)\n";
harness_probe('q=car (prefix omitted)', array('q' => 'car'), $QUERY_BY);
harness_probe('q=car prefix=false', array('q' => 'car', 'prefix' => 'false'), $QUERY_BY);
harness_probe('q=sun (prefix omitted)', array('q' => 'sun'), $QUERY_BY);
harness_probe('q=sun prefix=false', array('q' => 'sun', 'prefix' => 'false'), $QUERY_BY);
harness_probe('q=red spo (prefix omitted)', array('q' => 'red spo'), $QUERY_BY);
harness_probe('q=spo red (prefix omitted)', array('q' => 'spo red'), $QUERY_BY);
harness_probe('q=sculpt (prefix omitted)', array('q' => 'sculpt'), $QUERY_BY);
harness_probe('q=sculpt prefix=false', array('q' => 'sculpt', 'prefix' => 'false'), $QUERY_BY);

echo "\n-- tokenisation of translated options (~en:Germany~fr:Allemagne) and HTML\n";
foreach (array('germany', 'germanyfr', 'allemagne', 'en', 'hello', 'phello', 'world', 'strongworld', 'strong', 'href', 'galleries', 'p') as $word) {
    harness_probe('q=' . $word . ' prefix=false', array('q' => $word, 'prefix' => 'false'), $QUERY_BY);
}
