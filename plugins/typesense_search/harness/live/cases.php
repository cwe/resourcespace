<?php
// Cases for live/run.php, written for the test system used in the October 2026 review (about 100,000 visible
// resources, $stemming on). Field names, option names and refs belong to that system; change them for another.
// "item" numbers refer to ../../docs/typesense-search-review.md.

return array(
    array('heading' => 'baselines'),
    array('label' => 'L1 empty search', 'search' => '', 'order_by' => 'resourceid'),
    array('label' => 'L2 one keyword', 'search' => 'sculpture'),
    array('label' => 'L3 two keywords', 'search' => 'sculpture landscape'),
    array('label' => 'L4 node search', 'search' => '@@425', 'note' => 'usagerights = Social media'),
    array('label' => 'L5 a sort the plugin declines', 'search' => 'sculpture', 'order_by' => 'popularity', 'note' => 'an empty plugin result here means Typesense-only mode is on'),

    array('heading' => 'date dropdowns and date fields'),
    array('label' => 'L10 simple-search year dropdown', 'search' => 'basicyear:2024', 'note' => 'item 1'),
    array('label' => 'L11 year and month dropdowns', 'search' => 'basicyear:2023, basicmonth:05', 'note' => 'item 1'),
    array('label' => 'L12 date field by year', 'search' => 'date:2024'),
    array('label' => 'L13 date range, full dates', 'search' => 'date:rangestart2024-01-01end2024-02-29'),
    array('label' => 'L14 date range, end month without a day', 'search' => 'date:rangestart2024-01-01end2024-02-31', 'note' => 'item 6'),
    array('label' => 'L15 date field not flagged for indexing', 'search' => 'datephototaken:2024', 'note' => 'item 20'),
    array('label' => 'L16 date-range field, exact year', 'search' => 'daterangetest:2020', 'note' => 'core bug 2'),
    array('label' => 'L17 date-range field, range', 'search' => 'daterangetest:rangestart2020-06-01end2020-12-31'),
    array('label' => 'L18 legacy date syntax: any year, May', 'search' => 'date:nnnn|05', 'note' => 'item 27'),

    array('heading' => 'last word as a prefix, wildcards'),
    array('label' => 'L20 plain word that begins longer words', 'search' => 'land', 'note' => 'item 2'),
    array('label' => 'L21 plain word that begins longer words', 'search' => 'sculpt', 'note' => 'item 2'),
    array('label' => 'L22 wildcard', 'search' => 'sculpt*', 'note' => 'item 18'),
    array('label' => 'L23 wildcard', 'search' => 'land*', 'note' => 'item 18'),
    array('label' => 'L24 wildcard', 'search' => 'photo*', 'note' => 'item 18'),
    array('label' => 'L25 wildcard in a field', 'search' => 'title:sculpt*', 'note' => 'item 18'),
    array('label' => 'L26 leading wildcard', 'search' => '*scape', 'note' => 'item 11'),
    array('label' => 'L27 wildcard with many different completions', 'search' => 'con*', 'note' => 'item 18'),
    array('label' => 'L28 wildcard with many different completions', 'search' => 'gar*', 'note' => 'item 18'),
    array('label' => 'L29 the same in a field', 'search' => 'title:con*', 'note' => 'item 18'),

    array('heading' => 'fixed-list fields'),
    array('label' => 'L30 option by exact name', 'search' => 'orientation:landscape'),
    array('label' => 'L31 one word of an option', 'search' => 'usagerights:social', 'note' => 'item 3'),
    array('label' => 'L32 quoted field search, as the search bar rebuilds it', 'search' => '"usagerights:Social media"', 'note' => 'item 4'),
    array('label' => 'L33 the same plus a typed word', 'search' => '"usagerights:Social media" sculpture', 'note' => 'item 4'),
    array('label' => 'L34 option wildcard', 'search' => 'usagerights:soc*', 'note' => 'item 3'),

    array('heading' => 'stop words, phrases, punctuation'),
    array('label' => 'L40 stop word plus keyword', 'search' => 'the sculpture'),
    array('label' => 'L41 only a stop word', 'search' => 'the', 'note' => 'item 22'),
    array('label' => 'L42 stop word as a field value', 'search' => 'title:the', 'note' => 'item 22'),
    array('label' => 'L43 phrase', 'search' => '"black and white"', 'note' => 'item 21'),
    array('label' => 'L44 phrase with a word the stemmer changes', 'search' => '"sculpture park"', 'note' => '$stemming is on'),
    array('label' => 'L47 quoted single word the stemmer changes', 'search' => '"sculpture"', 'note' => '$stemming is on'),
    array('label' => 'L48 the phrase words unquoted', 'search' => 'sculpture park'),
    array('label' => 'L45 hyphenated single word', 'search' => 'black-and-white', 'note' => 'item 10'),
    array('label' => 'L46 resource type name', 'search' => 'document'),

    array('heading' => 'words that only match joined or split'),
    array('label' => 'L50', 'search' => 'land scape', 'note' => 'item 19'),
    array('label' => 'L51', 'search' => 'sun set', 'note' => 'item 19'),
    array('label' => 'L52', 'search' => 'water fall', 'note' => 'item 19'),

    array('heading' => 'numeric field'),
    array('label' => 'L60 numrange', 'search' => 'numberfield:numrangeneg60|neg40'),
    array('label' => 'L61 numrange, one bound (exact)', 'search' => 'numberfield:numrangeneg50|'),

    array('heading' => 'collections'),
    array('label' => 'L70 public collection, collection order', 'search' => '!collection3808', 'order_by' => 'collection', 'sort' => 'asc', 'rows' => 5),
    array('label' => 'L71 public collection, default (relevance) order as the API sends it', 'search' => '!collection3808', 'rows' => 5),
);
