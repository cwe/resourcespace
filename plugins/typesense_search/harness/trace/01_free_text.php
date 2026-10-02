<?php
require __DIR__ . '/boot.php';

echo "\n################ A. FREE TEXT ################\n";
trace('A1 single keyword', 'sculpture');
trace('A2 two keywords (AND)', 'sculpture landscape');
trace('A3 comma separated', 'sculpture, landscape');
trace('A4 stop word in search', 'the sunset');
trace('A5 resource type name as keyword', 'photo');
trace('A6 number among keywords', 'sunset 2021');
trace('A7 hyphenated single token', 'foo-bar');
trace('A8 hyphenated token among others', 'foo-bar baz');
trace('A9 dotted filename', 'IMG_1234.jpg');
trace('A10 apostrophe', "o'brien");
trace('A11 quoted phrase', '"red car"');
trace('A12 quoted phrase + keyword', '"red car" sunset');
trace('A13 negative keyword', 'sculpture -landscape');
trace('A14 negative only', '-landscape');
trace('A15 negative quoted phrase', 'sculpture -"red car"');
trace('A16 OR group', 'red;green');
trace('A17 trailing wildcard', 'sculpt*');
trace('A18 leading wildcard', '*scape');
trace('A19 middle wildcard', 'land*pe');
trace('A20 two wildcards', 'sam* super*');
trace('A21 wildcard + keyword', 'sculpt* park');
trace('A22 keyword + wildcard last', 'park sculpt*');
trace('A23 wildcard only', '*');
trace('A24 colon, not a field', 'time 10:30');
trace('A25 uppercase', 'SCULPTURE Park');
trace('A26 email-like', 'john.smith@example.com');
trace('A27 special search later in string', 'cat !empty18');
trace('A28 short wildcard', 'ab*');
trace('A29 wildcard with dots', '2010.69*');

$FAKE['unknown_keywords'] = array('zzzunknown');
trace('A30 keyword not in keyword table', 'zzzunknown');
trace('A31 keyword not in table + known', 'sculpture zzzunknown');
$FAKE['unknown_keywords'] = array();

echo "\n---- config: \$wildcard_always_applied = true\n";
$wildcard_always_applied = true;
trace('A32 wildcard_always_applied, two words', 'sculpt park');
trace('A33 wildcard_always_applied, field value', 'title:sculpt');
$wildcard_always_applied = false;

echo "\n---- numeric searches\n";
trace('A34 number, $config_search_for_number=false', '123');
$config_search_for_number = true;
trace('A35 number, $config_search_for_number=true', '123');
trace('A36 decimal number, $config_search_for_number=true', '12.5');
$config_search_for_number = false;

echo "\n---- config: \$index_contributed_by = true\n";
