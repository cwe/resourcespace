<?php
// $index_contributed_by with a username that also exists as a keyword.
require __DIR__ . '/fixture.php';
fx_set(8, 18, 'photo by admin staff');   // so "admin" exists as a keyword
fx_index();
$index_contributed_by = true;
ab('I1 contributor username as keyword', 'admin');
$index_contributed_by = false;
ab('I1b same search, config off', 'admin');
print_r(array_slice(array_unique($HARNESS['sql_errors']), 0, 3));
