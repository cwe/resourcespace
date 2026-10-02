<?php
require __DIR__ . '/boot.php';

echo "\n################ B. FIELD-SPECIFIC ################\n";
echo "\n---- text fields\n";
trace('B1 text field:value', 'title:launch');
trace('B2 quoted field phrase', '"title:launch party"');
trace('B3 quoted field single word', '"title:launch"');
trace('B4 field value + omit', 'title:launch, -ship');
trace('B5 field OR values', 'title:book;ship');
trace('B6 field value with dot', 'title:pumpkin.patch');
trace('B7 field value with hyphens', 'title:up-at-em');
trace('B8 field wildcard', 'title:book*');
trace('B9 multi-line text field', 'caption:harbour');
trace('B10 html text field', 'notes:harbour');
trace('B11 partial-index field', 'originalfilename:sculpture');
trace('B12 partial-index field, filename', 'originalfilename:dog_photo-1.jpg');
trace('B13 negative field', '-title:launch');
trace('B14 two words in one field (adv. search form)', 'title:red, title:car');
trace('B15 field + free text', 'title:launch harbour');

echo "\n---- hidden / inactive / unknown fields\n";
trace('B16 field without view access', 'secret:plans');
trace('B17 inactive field', 'oldfield:plans');
trace('B18 unknown field name', 'nosuchfield:plans');

echo "\n---- fixed-list fields\n";
trace('B20 dropdown value resolves to a node', 'country:france');
trace('B21 dropdown value, case differs', 'country:FRANCE');
trace('B22 dropdown OR values', 'country:france;spain');
trace('B23 dropdown value with space, quoted', '"country:united kingdom"');
trace('B24 dropdown value NOT a node name (partial word)', 'country:united');
trace('B25 dropdown value NOT a node name + keyword', 'country:united sunset');
trace('B26 dropdown wildcard', 'country:fran*');
trace('B27 dropdown OR, none resolve', 'country:atlantis;narnia');
trace('B28 dropdown OR, one resolves', 'country:france;narnia');
trace('B29 dynamic keyword field, typed text', 'keywords:modern');
trace('B30 dynamic keyword field, exact node', 'keywords:sculpture');
trace('B31 category tree value', 'subject:birds');
trace('B32 negative dropdown', '-country:france');

echo "\n---- numeric fields\n";
trace('B40 numeric field plain value', 'price:100');
trace('B41 numrange both', 'price:numrange10|100');
trace('B42 numrange min only', 'price:numrange10|');
trace('B43 numrange max only', 'price:numrange|100');
trace('B44 numrange negative', 'price:numrangeneg5|10');
trace('B45 numrange empty', 'price:numrange|');
trace('B46 numrange on a non-numeric text field', 'title:numrange1|5');
trace('B47 numrange on multi-line field', 'caption:numrange1|5');

echo "\n---- date fields\n";
trace('B50 date year', 'date:2024');
trace('B51 date year-month', 'date:2024-05');
trace('B52 date full', 'date:2024-05-17');
trace('B53 date legacy pipe format', 'date:2024|05');
trace('B54 date range both ends', 'date:rangestart2024-01-01end2024-12-31');
trace('B55 date range start only', 'date:rangestart2024-01-01');
trace('B56 date range end only', 'date:rangeend2024-12-31');
trace('B57 date range, end month Feb with no day (form adds -31)', 'date:rangestart2024-01end2024-02-31');
trace('B58 date range, end 30-day month, no day', 'date:rangestart2024-01-01end2024-04-31');
trace('B59 date range year only', 'date:rangestart2023end2024-12-31');
trace('B60 date range EDTF style end (day 99)', 'date:rangestart2020-00-00end2020-12-99');
trace('B61 date-range FIELD exact value', 'eventdates:2024');
trace('B62 date-range FIELD range', 'eventdates:rangestart2024-01-01end2024-12-31');
trace('B63 expiry date field', 'expiry:2025');
trace('B64 quoted date field', '"date:2024"');

echo "\n---- simple search date dropdowns (basicyear / basicmonth / basicday)\n";
trace('B70 basicyear', 'basicyear:2024');
trace('B71 basicyear + basicmonth', 'basicyear:2024, basicmonth:05');
trace('B72 basicyear + month + day', 'basicyear:2024, basicmonth:05, basicday:17');
trace('B73 basicmonth only', 'basicmonth:05');
trace('B74 keyword + basicyear', 'sculpture, basicyear:2024');
trace('B75 legacy startdate/enddate terms', 'startdate:2024-01-01, enddate:2024-12-31');

echo "\n---- !empty and full text\n";
trace('B80 !empty by ref', '!empty18');
trace('B81 !empty by name', '!emptycaption');
trace('B82 field search + !empty later', 'title:cat !empty18');
trace('B83 full text', '"' . FULLTEXT_SEARCH_PREFIX . ':harbour wall"');
trace('B84 full text + keyword', '"' . FULLTEXT_SEARCH_PREFIX . ':harbour" -boat');
