<?php
// Fields with active = 0. Written to check a change that is not in the reviewed commit: run it with
// HARNESS_ROOT pointing at a checkout that has the change to see the difference.
require __DIR__ . '/fixture.php';
fx_index();
echo 'code under test: ' . (getenv('HARNESS_ROOT') ? 'the HARNESS_ROOT checkout' : 'this checkout') . "\n";
ab('B17 inactive field', 'oldfield:legacy');
ab('A36 word in inactive field, free text', 'legacy');
ab('B1 text field:value', 'title:launch');
ab('negative on a word only in an inactive field', 'interview -legacy');
$doc = typesense_search_request('GET', '/collections/' . harness_collection() . '/documents/22');
echo 'document 22 fields: ' . implode(', ', array_keys($doc)) . "\n";
