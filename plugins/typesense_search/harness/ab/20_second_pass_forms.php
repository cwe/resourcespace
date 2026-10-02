<?php
// Second-pass battery: forms not exercised in the first pass.
require __DIR__ . '/fixture.php';

fx_resource(40, 1);
fx_set(40, 8, 'Phrase gap');
fx_set(40, 18, 'foo dog bar');                 // a non-stop word between foo and bar

fx_resource(41, 1);
fx_set(41, 8, 'Café Zürich');

fx_resource(42, 1);
fx_set(42, 8, 'Timed');
fx_set(42, 12, '2023-07-04 14:30:00');         // date with a time

fx_resource(43, 1);
fx_set(43, 8, 'Black and white print');

fx_resource(44, 1);
fx_set(44, 8, 'Black or white print');

fx_index();

echo "\n################ N: second pass ################\n";

echo "\n---- caller shapes\n";
ab('N1 CSV export call (smartsearch = null)', 'sunset', array('smartsearch' => null, 'return_refs_only' => true, 'fetchrows' => array(0, 100000), 'order_by' => ''));
ab('N1b same call with smartsearch = false', 'sunset', array('return_refs_only' => true, 'fetchrows' => array(0, 100000), 'order_by' => ''));
ab('N1c daylimit passed as null', 'sunset', array('daylimit' => null));
ab('N1d restypes starting with Global', 'sunset', array('restypes' => 'Global,2'));

echo "\n---- date field values\n";
ab('N2a legacy n placeholders: any year, May', 'date:nnnn|05');
ab('N2b legacy: 2024, any month, day 17', 'date:2024|nn|17');
ab('N2c legacy with dashes', 'date:nnnn-02-10');
ab('N3a year prefix', 'date:202');
ab('N3b month prefix', 'date:2024-0');
ab('N4a EDTF year range (start padded -00, end -99)', 'date:rangestart2024-00-00end2024-12-99');
ab('N4b EDTF month range', 'date:rangestart2024-02-00end2024-02-99');
ab('N4c EDTF start only', 'date:rangestart2024-00-00');
ab('N23a date with time, by day', 'date:2023-07-04');
ab('N23b date with time, by month', 'date:2023-07');
ab('N23c date with time, by year', 'date:2023');
ab('N24 date with time, one-day range', 'date:rangestart2023-07-04end2023-07-04');

echo "\n---- stop words\n";
ab('N5a stop word as a field value', 'title:the');
ab('N5b stop word plus a word in a field', 'title:the, title:red');
ab('N29 search of only a stop word', 'the');
ab('N6a phrase with a stop word inside', '"foo and bar"');
ab('N6b phrase with a different stop word', '"foo the bar"');
ab('N6c phrase black and white', '"black and white"');
ab('N6d phrase black or white', '"black or white"');

echo "\n---- punctuation\n";
ab('N7a punctuated token next to a field term', 'title:launch foo-bar');
ab('N7b punctuated token next to a quoted phrase', '"separate here" foo-bar');
ab('N7c punctuated token, plain two-word search', 'separate foo-bar');
ab('N8a straight apostrophe', "o'brien");
ab('N8b curly apostrophe', "o’brien");
ab('N27 colon that is not a field', 'foo:bar');

echo "\n---- accents (core side on SQLite is NOT representative: MySQL collations fold accents)\n";
ab('N9a unaccented search for accented text', 'cafe');
ab('N9b accented search', 'café');
ab('N9c unaccented umlaut', 'zurich');
ab('N9d field search, accented', 'title:café');

echo "\n---- negatives and wildcards\n";
ab('N10a negative wildcard', 'warehouse -car*');
ab('N10b negative exact', 'warehouse -car');
ab('N37 negative fixed-list option word', 'sunset -france');
ab('N38a free text matching an option', 'france');
ab('N38b free text matching a two-word option', 'modern art');
ab('N38c free text matching a tree child', 'birds');

echo "\n---- numeric\n";
ab('N11a numrange spanning zero (field also holds text)', 'price:numrangeneg5|5');
ab('N11b numrange on a non-text field type', 'caption:numrange1|5');

echo "\n---- field name / value case\n";
ab('N18a upper-case value', 'title:SUNSET');
ab('N18b upper-case field name', 'TITLE:sunset');

echo "\n---- asked of Typesense directly (prefix off)\n";
echo "accents (resource 41 title: Café Zürich)\n";
foreach (array('cafe', 'café', 'CAFÉ', 'zurich', 'zürich', 'zuerich') as $word) {
    harness_probe('q=' . $word, array('q' => $word, 'prefix' => 'false'), 'title,field_18_text');
}
harness_probe('filter title:cafe', array('q' => '*', 'filter_by' => 'field_8_s:cafe'));
harness_probe('filter title:café', array('q' => '*', 'filter_by' => 'field_8_s:café'));
echo "does an excluded last word get prefix-matched?\n";
harness_probe('q=warehouse -car prefix=true', array('q' => 'warehouse -car', 'prefix' => 'true'));
harness_probe('q=warehouse -carpet prefix=true', array('q' => 'warehouse -carpet', 'prefix' => 'true'));
echo "date representations (resource 1 date 2024-05-17)\n";
$doc = typesense_search_request('GET', '/collections/' . harness_collection() . '/documents/1');
echo 'doc 1 date keys: ' . json_encode(array_filter($doc, fn($k) => strpos($k, 'field_12') === 0 || $k === 'date_field_sort', ARRAY_FILTER_USE_KEY)) . "\n";
harness_probe('filter field_12_q:=05', array('q' => '*', 'filter_by' => 'field_12_q:=05'));
harness_probe('filter field_12_q:=17', array('q' => '*', 'filter_by' => 'field_12_q:=17'));
