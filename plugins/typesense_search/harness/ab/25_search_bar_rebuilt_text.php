<?php
// Second pass: the text the simple search bar writes back into its box after a node search.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n################ S: forms the search bar itself writes back into the search box ################\n";
ab('S1 node search (as linked from a resource view page)', '@@202');
ab('S2 the text the search bar rebuilds for that node (option name has a space)', '"country:United Kingdom"');
ab('S3 the rebuilt text plus one typed word', '"country:United Kingdom" car');
ab('S4 rebuilt text for an option without a space', 'country:France');
ab('S5 rebuilt text plus a typed word', 'country:France harbour');
ab('S6 rebuilt OR text for two options of a dynamic keywords field', 'keywords:sculpture;landscape');
ab('S7 rebuilt text for a two-word dynamic keyword', '"keywords:modern art"');
ab('S8 rebuilt text for a tree node', 'subject:Birds');
