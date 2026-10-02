<?php
// Second pass: edge forms (!empty placement, odd punctuation, wildcards) and Typesense being unreachable.
require __DIR__ . '/fixture.php';
fx_index();
echo "\n################ P: second pass, battery 3 ################\n";
ab('P1 !empty after a keyword', 'sunset, !empty18');
ab('P2 !empty before a keyword', '!empty18, sunset');
ab('P3 !empty alone', '!empty18');
ab('P4 special search not at the start', 'sunset !last10');
ab('P5 collection with the default (relevance) order, as the API calls it', '!collection5');
ab('P5b collection, relevance ASC', '!collection5', array('sort' => 'ASC'));
ab('P9 fixed-list value given in another language', 'country:allemagne');
ab('P10 !resource with trailing keywords containing digits', '!resource8 sunset 5');
ab('P11 keyword then node then keyword', 'sunset @@201 harbour');
ab('P12 search ending in a comma and spaces', 'sunset, ');
ab('P13 double wildcard in one word', 'sun**');
ab('P14 wildcard only', '*');
ab('P15 quoted single word', '"sunset"');
ab('P16 unbalanced quote', '"sunset harbour');
ab('P17 field value with trailing wildcard on a date', 'date:2024*');
ab('P18 expiry date field by year', 'expiry:2025');
ab('P19 field search plus negative word', 'title:sunset -harbour');
ab('P20 same word twice', 'sunset sunset');
echo "\n---- Typesense unreachable\n";
$typesense_search_port = 1; // nothing listens here
ab('P6 Typesense down', 'sunset');
$typesense_search_only = true;
ab('P7 Typesense down, Typesense-only mode', 'sunset');
