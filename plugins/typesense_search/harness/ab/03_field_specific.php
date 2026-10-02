<?php
// field:value searches: text, fixed-list, numeric, dates, the simple-search date dropdowns, !empty, full text.
require __DIR__ . '/fixture.php';
fx_index();

echo "\n################ A/B: FIELD-SPECIFIC ################\n";
ab('B1 text field:value', 'title:launch');
ab('B2 quoted field phrase', '"title:launch party"');
ab('B3 quoted field single word', '"title:launch"');
ab('B4 field value + omit', 'title:launch, -ship');
ab('B5 field OR values', 'title:book;ship');
ab('B6 field value with separator', 'caption:foo-bar');
ab('B7 field wildcard', 'title:book*');
ab('B8 multi-line field', 'caption:harbour');
ab('B9 html field, word after tag', 'notes:hello');
ab('B10 html field, plain word', 'notes:galleries');
ab('B11 partial-index field, fragment', 'originalfilename:sculpt');
ab('B12 partial-index field, whole filename', 'originalfilename:dog_photo-1.jpg');
ab('B13 two words in one field', 'title:red, title:car');
ab('B14 field + free text', 'title:launch book');
ab('B15 field value is a prefix of the stored word', 'title:laun');
ab('B16 field without view access', 'secret:plans');
ab('B17 inactive field', 'oldfield:legacy');
ab('B18 unknown field name', 'nosuchfield:sunset');

echo "\n---- fixed-list fields\n";
ab('B20 dropdown value = option', 'country:france');
ab('B21 dropdown OR', 'country:france;spain');
ab('B22 dropdown option with a space, quoted', '"country:united kingdom"');
ab('B23 dropdown word that is not a whole option', 'country:united');
ab('B24 dropdown partial + keyword', 'country:united car');
ab('B25 dropdown wildcard', 'country:fran*');
ab('B26 dropdown OR, none are options', 'country:atlantis;narnia');
ab('B27 dynamic keyword field, one word of an option', 'keywords:modern');
ab('B28 dynamic keyword field, option', 'keywords:sculpture');
ab('B29 category tree child option by name', 'subject:birds');
ab('B30 category tree root option by name', 'subject:animals');
ab('B31 translated option by name', 'country:germany');

echo "\n---- numeric\n";
ab('B40 numeric field plain value', 'price:100');
ab('B41 numrange', 'price:numrange10|100');
ab('B42 numrange min only (exact)', 'price:numrange50|');
ab('B43 numrange decimals', 'price:numrange12|13');
ab('B44 numrange including 0 (non-numeric value "abc")', 'price:numrangeneg5|5');

echo "\n---- dates\n";
ab('B50 date year', 'date:2024');
ab('B51 date year-month', 'date:2024-05');
ab('B52 date full', 'date:2024-05-17');
ab('B53 date range within a year', 'date:rangestart2024-01-01end2024-12-31');
ab('B54 date range start only', 'date:rangestart2023-01-01');
ab('B55 date range end only', 'date:rangeend2021-12-31');
ab('B56 date range, end = February without day', 'date:rangestart2024-01-01end2024-02-31');
ab('B57 date range, end = April without day', 'date:rangestart2023-01-01end2023-04-31');
ab('B58 date-range field exact year', 'eventdates:2024');
ab('B59 date-range field range overlap', 'eventdates:rangestart2024-03-05end2024-03-20');
ab('B60 date-range field range before', 'eventdates:rangestart2024-01-01end2024-02-01');
ab('B61 expiry field', 'expiry:2025');

echo "\n---- simple search date dropdowns\n";
ab('B70 basicyear', 'basicyear:2024');
ab('B71 basicyear + basicmonth', 'basicyear:2024, basicmonth:05');
ab('B72 basicyear + month + day', 'basicyear:2024, basicmonth:05, basicday:17');
ab('B73 basicmonth only', 'basicmonth:02');
ab('B74 keyword + basicyear', 'car, basicyear:2024');

echo "\n---- !empty / full text\n";
ab('B80 !empty', '!empty18');
ab('B81 full text', '"' . FULLTEXT_SEARCH_PREFIX . ':zeppelin"');
