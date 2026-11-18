<?php

/**
 * do_search() "!report" special search for users whose select list has bound parameters.
 *
 * The select list has parameters for a user with the 'g' permission and an 'X' permission, one for each restricted
 * resource type, and for a user whose group has a derestrict filter. Every query the search builds must bind
 * them, including the one that returns no results when the user lacks the 't' permission or the report cannot be
 * shown as resources.
 *
 * Each case checks that the query has as many parameters as placeholders and that the search returns the expected
 * resources.
 */

command_line_only();

$saved_000719 = [
    "userpermissions"      => $userpermissions,
    "userderestrictfilter" => $userderestrictfilter,
];

// Fixtures: a resource of each of two types, a field and node for the derestrict filter, and two reports
$type1_719 = create_resource(1, 0);
$type2_719 = create_resource(2, 0);
$fixtures_719 = [$type1_719, $type2_719];

$field_719 = create_resource_type_field("Report test list", 0, FIELD_TYPE_CHECK_BOX_LIST, "reportlist719", true);
$node_719 = set_node(null, $field_719, "Open", "", 10);
$filter_719 = save_filter(0, "Report test filter", RS_FILTER_ALL);
save_filter_rule("new", $filter_719, [[RS_FILTER_NODE_IN, [$node_719]]]);

ps_query(
    "INSERT INTO report (`name`, `query`, support_non_correlated_sql) VALUES (?, ?, 0)",
    [
        "s", "Test 719 resources",
        "s", "SELECT r.ref AS 'thumbnail' FROM resource AS r WHERE r.ref IN ("
            . (int) $type1_719 . ", " . (int) $type2_719 . ")",
    ]
);
$report_719 = sql_insert_id();
ps_query(
    "INSERT INTO report (`name`, `query`, support_non_correlated_sql) VALUES (?, ?, 0)",
    ["s", "Test 719 no thumbnail", "s", "SELECT ref FROM resource WHERE ref = " . (int) $type1_719]
);
$report_plain_719 = sql_insert_id();
$report_missing_719 = (int) ps_value("SELECT MAX(ref) value FROM report", [], 0) + 1;

// Users whose select list has parameters, and one whose select list has none
$users_719 = [
    "restricted resource type"           => ["permissions" => ["s", "g", "f*", "e0", "X1"], "filter" => ""],
    "derestrict filter"                  => ["permissions" => ["s", "f*", "e0"], "filter" => $filter_719],
    "no parameters in the select list"   => ["permissions" => ["s", "g", "f*", "e0"], "filter" => ""],
];
$searches_719 = [
    ["name" => "no 't' permission",   "report" => $report_719,         "may_report" => false, "expected" => []],
    ["name" => "missing report",      "report" => $report_missing_719, "may_report" => true,  "expected" => []],
    ["name" => "no thumbnail column", "report" => $report_plain_719,   "may_report" => true,  "expected" => []],
    ["name" => "report of resources", "report" => $report_719, "may_report" => true, "expected" => $fixtures_719],
];

$errors_000719 = [];
foreach ($users_719 as $user_name => $user) {
    foreach ($searches_719 as $case) {
        $userpermissions = $case["may_report"] ? array_merge($user["permissions"], ["t"]) : $user["permissions"];
        $userderestrictfilter = $user["filter"];
        $name = $user_name . ", " . $case["name"];
        $search = "!report" . $case["report"];

        $sql = do_search($search, returnsql: true);
        if (!($sql instanceof PreparedStatementQuery)) {
            $errors_000719[] = $name . ": no query returned";
            continue;
        }
        $placeholders = substr_count($sql->sql, "?");
        $parameters = intdiv(count($sql->parameters), 2);
        if ($placeholders !== $parameters) {
            $errors_000719[] = $name . ": " . $placeholders . " placeholders but " . $parameters . " parameters";
            continue;
        }

        $results = do_search($search);
        $found = is_array($results) ? array_map("intval", array_column($results, "ref")) : [];
        sort($found);
        if ($found != $case["expected"]) {
            $errors_000719[] = $name . ": expected [" . implode(",", $case["expected"])
                . "], got [" . implode(",", $found) . "]";
        }
    }
}

// Tear down
$userpermissions = $saved_000719["userpermissions"];
$userderestrictfilter = $saved_000719["userderestrictfilter"];
ps_query("DELETE FROM report WHERE ref IN (?, ?)", ["i", $report_719, "i", $report_plain_719]);
delete_filter($filter_719);
delete_resource_type_field($field_719);
foreach ($fixtures_719 as $resource) {
    delete_resource($resource);
}
unset(
    $saved_000719,
    $users_719,
    $searches_719,
    $fixtures_719,
    $type1_719,
    $type2_719,
    $field_719,
    $node_719,
    $filter_719,
    $report_719,
    $report_plain_719,
    $report_missing_719,
    $results,
    $found,
    $sql
);

if (count($errors_000719) > 0) {
    echo "ERROR - " . implode("; ", $errors_000719) . " ";
    return false;
}
return true;
