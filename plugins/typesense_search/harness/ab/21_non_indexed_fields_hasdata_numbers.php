<?php
// Second-pass battery 2: fields not flagged for indexing, !hasdata variants, numbers, Typesense-only mode.
require __DIR__ . '/boot.php';
// Extra fields must exist before the fixture body runs its schema section, so define them after and index later.
require __DIR__ . '/fixture_body.php';

fx_field(97, 'shotdate', FIELD_TYPE_DATE, array('keywords_index' => 0));                                  // date field, not indexed
fx_field(98, 'weight', FIELD_TYPE_TEXT_BOX_SINGLE_LINE, array('keywords_index' => 0, 'field_constraint' => 1)); // numeric, not indexed
harness_reset_caches();

fx_resource(45, 1);
fx_set(45, 8, 'Route 17');
fx_set(45, 97, '2024-06-01');
fx_set(45, 98, '7');

fx_resource(46, 1);
fx_set(46, 8, 'Gate 05');
fx_set(46, 97, '2021-01-20');
fx_set(46, 98, '40');

fx_index();

echo "\n################ O: second pass, battery 2 ################\n";

echo "\n---- field-specific searches on fields not flagged for indexing\n";
ab('O1 date value, non-indexed date field', 'shotdate:2024');
ab('O2 date range, non-indexed date field', 'shotdate:rangestart2024-01-01end2024-12-31');
ab('O3 numrange, non-indexed numeric field', 'weight:numrange1|10');
ab('O4 text value, non-indexed text field', 'altnotes:zebra');

echo "\n---- !hasdata\n";
ab('O5 !hasdata on an inactive field', '!hasdata94');
ab('O6 !hasdata on a field the user cannot view', '!hasdata93');
ab('O7 !hasdata on a non-indexed field', '!hasdata96');
ab('O8 !hasdata on a fixed-list field', '!hasdata3');
ab('O9 !hasdata on the non-indexed date field', '!hasdata97');

echo "\n---- numbers in free text\n";
ab('O10 two-digit number that is also a day of a stored date', '17');
ab('O11 number plus word', 'route 17');
ab('O12 two-digit number that is also a month of stored dates', '05');
ab('O13 decimal number', '12.5');
ab('O14 year as free text', '2024');
ab('O15 full date as free text', '2024-05-17');

echo "\n---- odd single tokens\n";
ab('O16 negative punctuated single token', '-foo-bar');
ab('O17 date field given a bare month', 'date:05');

echo "\n---- Typesense-only mode\n";
$typesense_search_only = true;
ab('O18 vetoed special in Typesense-only mode', '!related1');
ab('O19 vetoed sort in Typesense-only mode', 'sunset', array('order_by' => 'popularity'));
ab('O20 editable-only in Typesense-only mode', 'sunset', array('editable_only' => true));
$typesense_search_only = false;
