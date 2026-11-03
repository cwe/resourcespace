<?php

/**
 * do_search() special searches driven by a single resource column or relation: !integrityfail, !locked, !nopreview,
 * !hasdata, !contributions (with and without $open_access_for_contributor) and !last with its count, ordering,
 * archive filter and non-integer fallback.
 */

command_line_only();

$saved_000712 = [
    "resource_deletion_state"     => $resource_deletion_state ?? null,
    "open_access_for_contributor" => $open_access_for_contributor,
];
unset($resource_deletion_state);
$open_access_for_contributor = false;

$field_712 = create_resource_type_field("Test 712 data", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "test712data", true);

// Created in this order so that !last sees them as the newest resources
$integrity_712 = create_resource(1, 0);
$nofile_712    = create_resource(1, 0);
$locked_712    = create_resource(1, 0);
$plain_712     = create_resource(1, 0);
$data_712      = create_resource(1, 0);
$archived_712  = create_resource(1, 2);
$active_712 = [$integrity_712, $nofile_712, $locked_712, $plain_712, $data_712];
$fixtures_712 = array_merge($active_712, [$archived_712]);

ps_query("UPDATE resource SET has_image = 1, created_by = ? WHERE ref IN (?, ?, ?, ?, ?)", ["i", $userref, "i", $integrity_712, "i", $nofile_712, "i", $locked_712, "i", $data_712, "i", $archived_712]);
ps_query("UPDATE resource SET integrity_fail = 1, no_file = 0 WHERE ref = ?", ["i", $integrity_712]);
// A missing file is flagged separately and is not an integrity failure
ps_query("UPDATE resource SET integrity_fail = 1, no_file = 1 WHERE ref = ?", ["i", $nofile_712]);
ps_query("UPDATE resource SET lock_user = ? WHERE ref = ?", ["i", $userref, "i", $locked_712]);
ps_query("UPDATE resource SET has_image = 0, created_by = 999712 WHERE ref = ?", ["i", $plain_712]);
update_field($data_712, $field_712, "test712 data");

/**
 * Run a search for this test, returning the fixture references in result order.
 *
 * @param string $search   Search string
 * @param string $archive  Archive states to search
 * @param string $order_by Ordering
 * @param string $sort     Sort direction
 *
 * @return array
 */
function test_000712_search(string $search, string $archive = "0", string $order_by = "relevance", string $sort = "desc"): array
{
    global $fixtures_712;
    $results = do_search($search, "", $order_by, $archive, -1, $sort, false, DEPRECATED_STARSEARCH, false, false, "", false, false);
    return is_array($results) ? array_values(array_intersect(array_column($results, "ref"), $fixtures_712)) : [];
}

$use_cases = [
    ["name" => "!integrityfail excludes missing files",   "search" => "!integrityfail",                "expected" => [$integrity_712]],
    ["name" => "!locked",                                 "search" => "!locked",                       "expected" => [$locked_712]],
    ["name" => "!nopreview",                              "search" => "!nopreview",                    "expected" => [$plain_712]],
    ["name" => "!hasdata for a field",                    "search" => "!hasdata" . $field_712,         "expected" => [$data_712]],
    ["name" => "!hasdata for an unused field",            "search" => "!hasdata0",                     "expected" => []],
    ["name" => "!contributions applies the archive filter", "search" => "!contributions" . $userref,   "expected" => [$integrity_712, $nofile_712, $locked_712, $data_712]],
    ["name" => "!contributions for another user",         "search" => "!contributions999712",          "expected" => [$plain_712]],
    ["name" => "!contributions with open access",         "search" => "!contributions" . $userref,     "expected" => [$integrity_712, $nofile_712, $locked_712, $data_712],
                                                          "config" => ["open_access_for_contributor" => true]],
    // !last reads the direction from the relevance ORDER BY and only recognises an upper case DESC, as the search page sends
    ["name" => "!last newest first",                      "search" => "!last5", "sort" => "DESC",      "expected" => [$data_712, $plain_712, $locked_712, $nofile_712, $integrity_712], "ordered" => true],
    ["name" => "!last ascending",                         "search" => "!last5", "sort" => "asc",       "expected" => [$integrity_712, $nofile_712, $locked_712, $plain_712, $data_712], "ordered" => true],
    ["name" => "!last count",                             "search" => "!last1",                        "expected" => [$data_712]],
    ["name" => "!last counts after the archive filter",   "search" => "!last5", "archive" => "0,2", "sort" => "DESC", "expected" => [$archived_712, $data_712, $plain_712, $locked_712, $nofile_712], "ordered" => true],
    ["name" => "!last falls back to 1000",                "search" => "!lastabc",                      "expected" => $active_712],
];

foreach ($use_cases as $use_case) {
    foreach ($use_case["config"] ?? [] as $config_name => $config_value) {
        $$config_name = $config_value;
    }
    $results = test_000712_search($use_case["search"], $use_case["archive"] ?? "0", "relevance", $use_case["sort"] ?? "desc");
    $open_access_for_contributor = false;
    $expected = $use_case["expected"];
    if (empty($use_case["ordered"])) {
        sort($results);
        sort($expected);
    }
    if ($results != $expected) {
        echo "ERROR - SUBTEST " . $use_case["name"] . ": expected [" . implode(",", $expected) . "], got [" . implode(",", $results) . "] ";
        return false;
    }
}

// Tear down
ps_query("UPDATE resource SET lock_user = 0 WHERE ref = ?", ["i", $locked_712]);
foreach ($fixtures_712 as $resource) {
    delete_resource($resource);
}
delete_resource_type_field($field_712);
$open_access_for_contributor = $saved_000712["open_access_for_contributor"];
if ($saved_000712["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000712["resource_deletion_state"];
}
unset($use_cases, $fixtures_712, $active_712, $results, $expected);

return true;
