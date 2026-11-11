<?php

/**
 * do_search() on a date range field searched with a single date, month or year: a resource is found when its range
 * contains the date, or overlaps the month or year.
 *
 * A date range is stored as two nodes, its start and its end, named YYYY-MM-DD with 00 for a part that was not
 * given. search_keyword_date() joins the nodes of the field twice and asks for one on or after the start of the
 * searched period and one on or before its end.
 */

command_line_only();

$saved_000716 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$field_716 = create_resource_type_field("Test 716 range", 0, FIELD_TYPE_DATE_RANGE, "test716range", true);
$may_716   = create_resource(1, 0);
$year_716  = create_resource(1, 0);
$open_716  = create_resource(1, 0);
$none_716  = create_resource(1, 0);
$fixtures_716 = [$may_716, $year_716, $open_716, $none_716];

$errors_000716 = [];
if (
    update_field($may_716, $field_716, "2024-05-01/2024-05-31") !== true
    || update_field($year_716, $field_716, "2023-01-01/2023-12-31") !== true
    // A start given as a year only is stored as 2022-00-00
    || update_field($open_716, $field_716, "2022/2022-06-30") !== true
) {
    $errors_000716[] = "could not save the date ranges";
}

$cases_716 = [
    ["name" => "a date inside a range", "search" => "test716range:2024|05|17", "expected" => [$may_716]],
    ["name" => "the first day of a range", "search" => "test716range:2024|05|01", "expected" => [$may_716]],
    ["name" => "the last day of a range", "search" => "test716range:2024|05|31", "expected" => [$may_716]],
    ["name" => "the day before a range", "search" => "test716range:2024|04|30", "expected" => []],
    ["name" => "the day after a range", "search" => "test716range:2024|06|01", "expected" => []],
    ["name" => "a date inside the other range", "search" => "test716range:2023|07|04", "expected" => [$year_716]],
    ["name" => "a date between the two ranges", "search" => "test716range:2024|02|10", "expected" => []],
    ["name" => "a date written with hyphens, as the search form sends it",
        "search" => "test716range:2024-05-17", "expected" => [$may_716]],
    ["name" => "a date inside a range whose start is a year only", "search" => "test716range:2022|03|10",
        "expected" => [$open_716]],
    ["name" => "a date after a range whose start is a year only", "search" => "test716range:2022|07|01",
        "expected" => []],

    // A month or a year finds every range that overlaps it, as the search form can send either
    ["name" => "the month a range covers", "search" => "test716range:2024-05", "expected" => [$may_716]],
    ["name" => "the month before a range", "search" => "test716range:2024-04", "expected" => []],
    ["name" => "the month after a range", "search" => "test716range:2024-06", "expected" => []],
    ["name" => "a month inside a longer range", "search" => "test716range:2023-07", "expected" => [$year_716]],
    ["name" => "the last month of a range", "search" => "test716range:2023-12", "expected" => [$year_716]],
    ["name" => "a month written with a vertical bar", "search" => "test716range:2024|05", "expected" => [$may_716]],
    ["name" => "the year a range covers", "search" => "test716range:2023", "expected" => [$year_716]],
    ["name" => "the year a shorter range falls in", "search" => "test716range:2024", "expected" => [$may_716]],
    ["name" => "the year of a range whose start is a year only", "search" => "test716range:2022",
        "expected" => [$open_716]],
    ["name" => "a year with no range", "search" => "test716range:2021", "expected" => []],
];

foreach ($cases_716 as $case) {
    $results = do_search(
        $case["search"],
        "",
        "resourceid",
        "0",
        -1,
        "asc",
        false,
        DEPRECATED_STARSEARCH,
        false,
        false,
        "",
        false,
        false
    );
    $results = is_array($results) ? array_column($results, "ref") : [];
    $results = array_values(array_intersect($results, $fixtures_716));
    if ($results != $case["expected"]) {
        $errors_000716[] = $case["name"] . ": expected [" . implode(",", $case["expected"]) . "], got ["
            . implode(",", $results) . "]";
    }
}

// Tear down
foreach ($fixtures_716 as $resource) {
    delete_resource($resource);
}
delete_resource_type_field($field_716);
if ($saved_000716["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000716["resource_deletion_state"];
}
unset($cases_716, $fixtures_716, $results);

if (count($errors_000716) > 0) {
    echo "ERROR - " . implode("; ", $errors_000716) . " ";
    return false;
}
return true;
