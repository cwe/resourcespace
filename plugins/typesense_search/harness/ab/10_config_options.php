<?php
// Config options ($index_contributed_by, $wildcard_always_applied, $special_search_honors_restypes) and odd forms.
require __DIR__ . '/fixture.php';
fx_index();

echo "\n---- \$index_contributed_by = true (search by contributor username)\n";
$index_contributed_by = true;
ab('I1 username as keyword', 'other');
$index_contributed_by = false;

echo "\n---- \$wildcard_always_applied = true\n";
$wildcard_always_applied = true;
ab('I2 two words, both prefixes', 'laun part');
ab('I3 field value prefix', 'title:laun');
$wildcard_always_applied = false;

echo "\n---- \$special_search_honors_restypes = true\n";
$special_search_honors_restypes = true;
ab('I4 !last + restypes', '!last5', array('restypes' => '2'));
ab('I5 !list + restypes', '!list1:2:3', array('restypes' => '2'));
$special_search_honors_restypes = false;

echo "\n---- misc\n";
ab('I6 search text containing "integrityfail"', 'integrityfail sunset', array('archive' => '0'));
ab('I7 field search + !empty later', 'title:sunset !empty18');
ab('I8 trailing wildcard on field value, plain word first', 'sunset title:book*');
ab('I9 OR in field where values are words', 'title:ship;book');
ab('I10 date field, partial value prefix', 'date:2024-0');
ab('I11 expired-grant confidential resource by ref', '!resource13');
ab('I12 quoted phrase across punctuation', '"up at em"');
ab('I13 two-word option typed unquoted', 'country:united kingdom');
ab('I14 keyword with trailing comma list', 'sunset, harbour, wall');
