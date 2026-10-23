<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// A4 battery: the dates module. A unit table of every stored form (year, year-month, full date, a time after a full
// date, 00 for an unknown part, a BCE year, non-real dates, junk), the key's integer order against core's string
// order over a grid of values and bounds, the bounds of every catalogue range form (the form's -31, EDTF's -00 and
// -99, the unpadded start), the sort key, the epochs in the zone of the MySQL values (midnight, a DST change, the
// day-limit cut-off), and the produced _q and _k accepted by the local Typesense in collections created from the
// schema module, with the range and value filters the search packages will send.
require __DIR__ . '/boot.php';

/** The expected output of typesense_search_dates_parse(). */
function a4_expect(array $q, ?int $k, string $precision, ?int $year, ?int $month, ?int $day): array
{
    return array('q' => $q, 'k' => $k, 'precision' => $precision, 'year' => $year, 'month' => $month, 'day' => $day);
}

harness_check(
    'A4.1 the loader requires the module',
    function_exists('typesense_search_dates_parse') && function_exists('typesense_search_dates_range_bounds')
        && function_exists('typesense_search_dates_sort_key') && function_exists('typesense_search_dates_timestamp')
        && function_exists('typesense_search_dates_day_limit')
);

// ----------------------------------------------------------------------------------------- parse: every stored form
$none = a4_expect(array(), null, 'none', null, null, null);
$table = array(
    'year' => array('2024', a4_expect(array('2024'), 20240000000000, 'year', 2024, null, null)),
    'year-month' => array('2024-05', a4_expect(array('2024', '2024-05'), 20240500000000, 'month', 2024, 5, null)),
    'full date' => array('2024-05-17', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517000000, 'day', 2024, 5, 17)),
    'full date with a time' => array('2024-05-17 14:30', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517143000, 'day', 2024, 5, 17)),
    'full date with seconds' => array('2024-05-17 14:30:45', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517143045, 'day', 2024, 5, 17)),
    'midnight ties with the bare date' => array('2024-05-17 00:00', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517000000, 'day', 2024, 5, 17)),
    'unknown month and day' => array('2024-00-00', a4_expect(array('2024', '2024-00', '2024-00-00'), 20240000000000, 'year', 2024, null, null)),
    'unknown day' => array('2024-08-00', a4_expect(array('2024', '2024-08', '2024-08-00'), 20240800000000, 'month', 2024, 8, null)),
    'month only' => array('0000-08-00', a4_expect(array('0000', '0000-08', '0000-08-00'), 800000000, 'none', null, 8, null)),
    'day only' => array('0000-00-11', a4_expect(array('0000', '0000-00', '0000-00-11'), 11000000, 'none', null, null, 11)),
    'year and day, no month' => array('2024-00-11', a4_expect(array('2024', '2024-00', '2024-00-11'), 20240011000000, 'year', 2024, null, 11)),
    'non-real day kept, month precision' => array('2024-02-31', a4_expect(array('2024', '2024-02', '2024-02-31'), 20240231000000, 'month', 2024, 2, 31)),
    'leap day' => array('2024-02-29', a4_expect(array('2024', '2024-02', '2024-02-29'), 20240229000000, 'day', 2024, 2, 29)),
    'no leap day' => array('2023-02-29', a4_expect(array('2023', '2023-02', '2023-02-29'), 20230229000000, 'month', 2023, 2, 29)),
    'non-real month kept, year precision' => array('2024-13-05', a4_expect(array('2024', '2024-13', '2024-13-05'), 20241305000000, 'year', 2024, 13, 5)),
    'non-real time kept in the key' => array('2024-05-17 25:61', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517256100, 'day', 2024, 5, 17)),
    'BCE year: representations, no key' => array('-0500-03-15', a4_expect(array('-0500', '-0500-03', '-0500-03-15'), null, 'day', -500, 3, 15)),
    'surrounding space trimmed' => array('  2024-05-17  ', a4_expect(array('2024', '2024-05', '2024-05-17'), 20240517000000, 'day', 2024, 5, 17)),
    'NULL' => array(null, $none),
    'empty' => array('', $none),
    'words' => array('circa 1900', $none),
    'unpadded parts' => array('2024-5-7', $none),
    'bare digits' => array('20240517', $none),
    'T between date and time' => array('2024-05-17T14:30', $none),
    'fractional seconds' => array('2024-05-17 14:30:45.123', $none),
    'time without a full date' => array('2024-05 14:30', $none),
    'five-digit year' => array('12000-01-01', $none),
);
foreach ($table as $what => $case) {
    $actual = typesense_search_dates_parse($case[0]);
    harness_check('A4.2 parse ' . $what . ' ' . json_encode($case[0]), $actual === $case[1], $actual === $case[1] ? '' : 'got ' . json_encode($actual));
}

// ----------------------------------------------------------------------------------------- the key's order is core's string order
// Stored values as the edit form writes them (three groups always, 00 for an unknown part, an optional time) and
// bounds as the search string carries them. MySQL's collations order space, hyphen, colon and digits as ASCII does,
// so strcmp() stands for the comparisons core's joins make: name >= start and name <= "end 23:59:59".
$values = array(
    '0000-08-00', '0000-00-11', '2005-12-31 23:59:59', '2006-01-01', '2019-12-31 23:59:59', '2023-12-31', '2024-00-00',
    '2024-00-15', '2024-01-00', '2024-01-01', '2024-01-01 00:00', '2024-01-15', '2024-02-00', '2024-02-10',
    '2024-02-28 23:30', '2024-02-29', '2024-02-29 23:59:59', '2024-03-01', '2024-05-00', '2024-05-01', '2024-05-17 14:30',
    '2024-05-31 23:30', '2024-06-00', '2024-06-30', '2024-06-30 23:59:59', '2024-07-01', '2024-12-31', '2024-12-31 23:30',
    '2024-12-99', '2025-00-00', '2025-01-01', '2026-01-01 09:00',
);
$starts = array('2024', '2024-05', '2024-00-00', '2024-05-00', '2024-01-01', '2024-05-01', '2026-01-01', '2024-02-00');
$ends = array('2024-02-29', '2024-02-31', '2024-12-99', '2024-12-31', '2024-06-31', '2005-12-31', '2024-05-31', '2024-00-00');
$pairs = 0;
$wrong = array();
foreach ($values as $value) {
    $k = typesense_search_dates_parse($value)['k'];
    foreach ($starts as $start) {
        $pairs++;
        if ((strcmp($value, $start) >= 0) !== ($k >= typesense_search_dates_range_bounds($start, null)['start'])) {
            $wrong[] = $value . ' >= ' . $start;
        }
    }
    foreach ($ends as $end) {
        $pairs++;
        if ((strcmp($value, $end . ' 23:59:59') <= 0) !== ($k <= typesense_search_dates_range_bounds(null, $end)['end'])) {
            $wrong[] = $value . ' <= ' . $end;
        }
    }
}
harness_check('A4.3 integer order of the key equals core\'s string order over ' . $pairs . ' value/bound pairs (the form\'s -31, EDTF\'s -00 and -99, unpadded starts)', count($wrong) === 0, implode('; ', $wrong));

// A short end (a year or a month, which no form produces: core appends " 23:59:59" to it) excludes every stored value of
// that year or month in core, because "-" sorts after " "; the key includes the -00 partial whose digits equal the bound.
$short_wrong = array();
foreach ($values as $value) {
    $k = typesense_search_dates_parse($value)['k'];
    foreach (array('2024', '2024-05', '2024-00') as $end) {
        if ((strcmp($value, $end . ' 23:59:59') <= 0) !== ($k <= typesense_search_dates_range_bounds(null, $end)['end'])) {
            $short_wrong[] = $value . ' <= ' . $end;
        }
    }
}
harness_check('A4.4 a short end differs from core only on the -00 partial with the bound\'s digits', $short_wrong === array('2024-00-00 <= 2024', '2024-00-00 <= 2024-00', '2024-05-00 <= 2024-05'), json_encode($short_wrong));

// A stored value shorter than three groups (API-written only) ties with the -00 bound it is a prefix of.
$bare_wrong = array();
foreach (array('2024', '2024-05', '2024-00') as $value) {
    $k = typesense_search_dates_parse($value)['k'];
    foreach ($starts as $start) {
        if ((strcmp($value, $start) >= 0) !== ($k >= typesense_search_dates_range_bounds($start, null)['start'])) {
            $bare_wrong[] = $value . ' >= ' . $start;
        }
    }
}
harness_check('A4.5 a bare year or month value differs from core only against the -00 start it is a prefix of', $bare_wrong === array('2024 >= 2024-00-00', '2024-05 >= 2024-05-00', '2024-00 >= 2024-00-00'), json_encode($bare_wrong));

// ----------------------------------------------------------------------------------------- range bounds: the catalogue forms
$ranges = array(
    'D5 full dates' => array('2024-01-01', '2024-02-29', array('start' => 20240101000000, 'end' => 20240229235959)),
    'D6 start only' => array('2026-01-01', null, array('start' => 20260101000000, 'end' => null)),
    'D7 end only' => array(null, '2005-12-31', array('start' => null, 'end' => 20051231235959)),
    'D8 EDTF -00 and -99' => array('2024-00-00', '2024-12-99', array('start' => 20240000000000, 'end' => 20241299235959)),
    'D9 the form\'s -31 end' => array('2024-01-01', '2024-02-31', array('start' => 20240101000000, 'end' => 20240231235959)),
    'D19 date-and-time field' => array('2029-01-01', '2029-12-31', array('start' => 20290101000000, 'end' => 20291231235959)),
    'J5 unpadded start year' => array('2024', null, array('start' => 20240000000000, 'end' => null)),
    'J6 start month, end month padded' => array('2024-05', '2024-06-31', array('start' => 20240500000000, 'end' => 20240631235959)),
    'a short end, documented above' => array(null, '2024', array('start' => null, 'end' => 20240000235959)),
    'empty bounds are open' => array('', '', array('start' => null, 'end' => null)),
    'nothing' => array(null, null, array('start' => null, 'end' => null)),
    'unpadded part declines' => array('2024-5-1', null, null),
    'a BCE bound declines' => array('-0500', null, null),
    'a time in a bound declines' => array('2024-01-01 12:00', '2024-01-02', null),
    'the form\'s space-to-dash run-on declines' => array('2024-01-01-12:00', null, null),
    'words decline' => array(null, 'next week', null),
);
foreach ($ranges as $what => $case) {
    $actual = typesense_search_dates_range_bounds($case[0], $case[1]);
    harness_check('A4.6 bounds ' . $what . ' ' . json_encode(array($case[0], $case[1])), $actual === $case[2], $actual === $case[2] ? '' : 'got ' . json_encode($actual));
}

// ----------------------------------------------------------------------------------------- the sort key
harness_check('A4.7 sort key NULL', typesense_search_dates_sort_key(null) === '0:');
harness_check('A4.8 sort key empty', typesense_search_dates_sort_key('') === '1:');
harness_check('A4.9 sort key value', typesense_search_dates_sort_key('2024-05-17') === '2:2024-05-17');
$keys = array_map('typesense_search_dates_sort_key', array('2024-05-17 10:00', null, '2024-05-17', '', '0000-08-00', '-0500-03-15', '2023-12-31'));
sort($keys, SORT_STRING);
harness_check('A4.10 keys sort as the column does: NULL, empty, then the values by string', $keys === array('0:', '1:', '2:-0500-03-15', '2:0000-08-00', '2:2023-12-31', '2:2024-05-17', '2:2024-05-17 10:00'), json_encode($keys));

// ----------------------------------------------------------------------------------------- epochs in the zone of the MySQL values
$zone = date_default_timezone_get();
harness_check('A4.11 the harness runs in UTC, as config.default.php sets it', $zone === 'UTC', $zone);
harness_check('A4.12 UTC midnight', typesense_search_dates_timestamp('2024-05-17 00:00:00') === 1715904000);
harness_check('A4.13 a date alone is its midnight', typesense_search_dates_timestamp('2024-05-17') === 1715904000);
harness_check('A4.14 minutes and seconds', typesense_search_dates_timestamp('2024-05-17 14:30') === 1715956200 && typesense_search_dates_timestamp('2024-05-17 14:30:45') === 1715956245);
foreach (array(null, '', '0000-00-00 00:00:00', '0000-00-00', '2024-02-30 10:00:00', '2024-05-17 25:00:00', '2024-05-17 14:30:60', '2024-05', '2024-05-17T14:30:00', 'now') as $bad) {
    harness_check('A4.15 no epoch for ' . json_encode($bad), typesense_search_dates_timestamp($bad) === null);
}
date_default_timezone_set('Europe/London');
harness_check('A4.16 London: the same midnight string is one hour earlier in BST', typesense_search_dates_timestamp('2024-05-17 00:00:00') === 1715900400);
$before = typesense_search_dates_timestamp('2024-03-31 00:30:00');
$after = typesense_search_dates_timestamp('2024-03-31 02:30:00');
harness_check('A4.17 London spring change: two wall-clock hours apart are one epoch hour apart', $after - $before === 3600, ($after - $before) . 's');
$gap = typesense_search_dates_timestamp('2024-03-31 01:30:00');
harness_check('A4.18 London: a time in the DST gap is moved forward', $gap === $after, (string)$gap);
$ambiguous = typesense_search_dates_timestamp('2024-10-27 01:30:00');
harness_check('A4.19 London autumn change: the ambiguous hour resolves to one of its two instants', in_array($ambiguous, array(1729989000, 1729992600), true), $ambiguous === 1729989000 ? 'first occurrence (BST)' : 'second occurrence (GMT)');
// 2024-04-03 00:30 BST is 2024-04-02 23:30 UTC: the local date decides the day-limit cut-off, as curdate() does.
$now = 1712100600;
harness_check('A4.20 London day limit: midnight seven local days back', typesense_search_dates_day_limit(7, $now) === 1711497600);
harness_check('A4.21 London day limit of 0 is today\'s local midnight', typesense_search_dates_day_limit(0, $now) === 1712098800);
harness_check('A4.22 a resource created at the cut-off midnight is not after it, as core\'s > excludes it', typesense_search_dates_timestamp('2024-03-27 00:00:00') === 1711497600 && typesense_search_dates_timestamp('2024-03-27 00:00:01') > 1711497600);
date_default_timezone_set('Asia/Tokyo');
harness_check('A4.23 Tokyo midnight is nine hours before UTC midnight', typesense_search_dates_timestamp('2024-05-17 00:00:00') === 1715871600);
date_default_timezone_set('UTC');
harness_check('A4.24 UTC spring change: the same two strings are two epoch hours apart', typesense_search_dates_timestamp('2024-03-31 02:30:00') - typesense_search_dates_timestamp('2024-03-31 00:30:00') === 7200);
harness_check('A4.25 UTC day limit from the same instant is a day earlier (the UTC date is still 2 April)', typesense_search_dates_day_limit(7, $now) === 1711411200);
date_default_timezone_set($zone);

// ----------------------------------------------------------------------------------------- accepted by the local Typesense
$generation = typesense_search_schema_generation(1);
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}
$created = true;
foreach ($generation as $definition) {
    list($status, $body) = harness_ts('POST', '/collections', $definition);
    $created = $created && $status === 201;
}
harness_check('A4.26 collections created from the schema module', $created);
$resources = $generation['resources']['name'];

// One document per stored form, every field through this module: _q and _k from parse() (no key: an empty array),
// date_field_sort from the sort key, the epochs from timestamp().
$stored = array(
    1 => '2024-05-17', 2 => '2024-05-17 23:30', 3 => '2024-00-00', 4 => '2024-02-29 23:59:59', 5 => '2024-03-01',
    6 => '2023-12-31', 7 => '0000-08-00', 8 => '', 9 => '-0500-03-15', 10 => '2024-05-00', 11 => '2024-12-31 23:30',
    12 => '2025-01-01', 13 => '2024-01-15', 14 => null, 15 => 'circa 1900',
);
$lines = array();
foreach ($stored as $ref => $value) {
    $parsed = typesense_search_dates_parse($value);
    $lines[] = json_encode(array(
        'id' => (string)$ref, 'ref' => $ref, 'ref_s' => (string)$ref, 'title' => 'Resource ' . $ref, 'resource_type' => 1,
        'archive' => 0, 'access' => 0, 'created_by' => 1,
        'creation_date' => typesense_search_dates_timestamp('2020-01-01 10:00:00'),
        'modified_date' => typesense_search_dates_timestamp('2020-01-02 10:00:00'),
        'date_field_sort' => typesense_search_dates_sort_key($value),
        'nodes' => array(0), 'populated_field_ids' => array(0, 12), 'hit_count' => 0,
        'ur_hits' => 0, 'hits_ur' => 0, 'date_ref' => $ref, 'rating_ur' => 0,
        'field_12_q' => $parsed['q'],
        'field_12_k' => $parsed['k'] === null ? array() : array($parsed['k']),
    ));
}
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', implode("\n", $lines), true);
harness_check('A4.27 every document accepted (_q string arrays, _k int64 arrays, the sort key, the epochs)', $status === 200 && substr_count((string)$body, '"success":true') === count($stored), (string)$body);
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', str_replace('"field_12_k":[]', '"field_12_k":[null]', $lines[7]), true);
harness_check('A4.28 a null inside _k is rejected: a value with no key is left out of the array', strpos((string)$body, '"success":false') !== false, (string)$body);

$search = function (array $params) use ($resources) {
    list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($resources) . '/documents/search?' . http_build_query($params + array('q' => '*', 'include_fields' => 'ref', 'per_page' => 250)));
    $refs = array();
    foreach ($body['hits'] ?? array() as $hit) {
        $refs[] = (int)$hit['document']['ref'];
    }
    return array($status, $refs, (string)($body['message'] ?? ''));
};
$range = function (string $label, ?string $start, ?string $end, array $expect) use ($search) {
    $bounds = typesense_search_dates_range_bounds($start, $end);
    $filter = array();
    if ($bounds['start'] !== null) {
        $filter[] = 'field_12_k:>=' . $bounds['start'];
    }
    if ($bounds['end'] !== null) {
        $filter[] = 'field_12_k:<=' . $bounds['end'];
    }
    list($status, $refs) = $search(array('filter_by' => implode(' && ', $filter)));
    sort($refs);
    harness_check($label, $status === 200 && $refs === $expect, json_encode($refs) . ' for ' . implode(' && ', $filter));
};
// Expected sets derived by hand from $stored with core's string rule: the key never matches the BCE value (9), the
// empty, NULL and junk values (8, 14, 15), and a -00 partial sorts before the first day of its year or month.
$range('A4.29 D5 rangestart2024-01-01end2024-02-29', '2024-01-01', '2024-02-29', array(4, 13));
$range('A4.30 D9 the -31 end brackets the same February', '2024-01-01', '2024-02-31', array(4, 13));
$range('A4.31 D8 EDTF 2024-00-00 .. 2024-12-99 is the year, the -00 partials included', '2024-00-00', '2024-12-99', array(1, 2, 3, 4, 5, 10, 11, 13));
$range('A4.32 J5 rangestart2024 is everything from 2024 on', '2024', null, array(1, 2, 3, 4, 5, 10, 11, 12, 13));
$range('A4.33 J6 rangestart2024-05end2024-06-31 is May and June, the month-only May value included', '2024-05', '2024-06-31', array(1, 2, 10));
$range('A4.34 D7 rangeend2005-12-31 reaches the month-only 0000 value; the BCE value has no key', null, '2005-12-31', array(7));
$range('A4.35 a range ending at a day includes a value timed 23:30 on that day', '2024-05-17', '2024-05-17', array(1, 2));
foreach (array('2024' => array(1, 2, 3, 4, 5, 10, 11, 13), '2024-00' => array(3), '2024-05-17' => array(1, 2), '2024-05-00' => array(10), '0000-08' => array(7), '-0500' => array(9), '2024-05-1' => array()) as $value => $expect) {
    list($status, $refs) = $search(array('filter_by' => 'field_12_q:=' . $value));
    sort($refs);
    harness_check('A4.36 exact match on _q for ' . $value . ' (SD1\'s := filter)', $status === 200 && $refs === $expect, json_encode($refs));
}
// The CE date values sort as MySQL sorts the column. Typesense's string sort is not MySQL's collation, though: it
// ignores punctuation, so the BCE key "2:-0500-03-15" sorts as its digits (after 0000, before 2023) where MySQL puts
// "-" before every digit, and a value in words sorts after every date in both. A difference for D9 to list if a
// system holds BCE dates in $date_field.
list($status, $refs) = $search(array('sort_by' => 'date_field_sort:asc'));
$dates = array_values(array_diff($refs, array(9, 15)));
harness_check('A4.37 date_field_sort ascending: NULL, empty, then the date values in string order (a -00 partial before its first day, a timed value after its day)', $status === 200 && $dates === array(14, 8, 7, 6, 3, 13, 4, 5, 10, 1, 2, 11, 12), json_encode($refs));
harness_check('A4.38 Typesense sorts the BCE key as its digits and words last (not MySQL\'s order for the BCE value)', $status === 200 && array_search(9, $refs, true) === array_search(7, $refs, true) + 1 && end($refs) === 15, json_encode($refs));
list($status, $refs) = $search(array('sort_by' => 'date_field_sort:desc'));
$dates = array_values(array_diff($refs, array(9, 15)));
harness_check('A4.39 date_field_sort descending: the date values first, NULL last', $status === 200 && $dates === array(12, 11, 2, 1, 10, 5, 4, 13, 3, 6, 7, 8, 14), json_encode($refs));

foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}

harness_finish('A4_dates');
