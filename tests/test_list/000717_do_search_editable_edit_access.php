<?php

/**
 * do_search() editable-only searches must agree with get_edit_access() about resources in an archive state the
 * user has no 'e' permission for.
 *
 * Such a resource can still be edited by its contributor when $edit_access_for_contributor is set, and by anyone
 * holding an 'ert' permission for its resource type. Either reason is enough.
 *
 * Each case searches the archived state as a user who may edit only active resources, and compares the result
 * with the expected resources and with get_edit_access() for every fixture.
 */

command_line_only();

$saved_000717 = [
    "userpermissions"             => $userpermissions,
    "edit_access_for_contributor" => $edit_access_for_contributor,
    "usereditfilter"              => $usereditfilter,
    "resource_deletion_state"     => $resource_deletion_state ?? null,
];
unset($resource_deletion_state);
$usereditfilter = "";
$other_user_717 = 999717;

// Archived resources of two types, contributed by the user and by somebody else
$own_type1_717   = create_resource(1, 2);
$other_type1_717 = create_resource(1, 2);
$own_type2_717   = create_resource(2, 2);
$other_type2_717 = create_resource(2, 2);
$fixtures_717 = [$own_type1_717, $other_type1_717, $own_type2_717, $other_type2_717];
ps_query("UPDATE resource SET created_by = ? WHERE ref IN (?, ?)", ["i", $userref, "i", $own_type1_717, "i", $own_type2_717]);
ps_query(
    "UPDATE resource SET created_by = ? WHERE ref IN (?, ?)",
    ["i", $other_user_717, "i", $other_type1_717, "i", $other_type2_717]
);

$cases_717 = [
    ["name" => "no exception", "contributor" => false, "permissions" => [], "expected" => []],
    ["name" => "contributor", "contributor" => true, "permissions" => [],
        "expected" => [$own_type1_717, $own_type2_717]],
    ["name" => "ert for type 1", "contributor" => false, "permissions" => ["ert1"],
        "expected" => [$own_type1_717, $other_type1_717]],
    ["name" => "contributor and ert for type 1", "contributor" => true, "permissions" => ["ert1"],
        "expected" => [$own_type1_717, $other_type1_717, $own_type2_717]],
    ["name" => "contributor and ert for type 2", "contributor" => true, "permissions" => ["ert2"],
        "expected" => [$own_type1_717, $own_type2_717, $other_type2_717]],
    ["name" => "contributor and ert for both types", "contributor" => true, "permissions" => ["ert1", "ert2"],
        "expected" => $fixtures_717],
];

$errors_000717 = [];
if (in_array(false, $fixtures_717, true)) {
    $errors_000717[] = "could not create the fixtures";
    $cases_717 = [];
}
foreach ($cases_717 as $case) {
    $userpermissions = array_merge(["s", "e0"], $case["permissions"]);
    $edit_access_for_contributor = $case["contributor"];

    $results = do_search("", "", "resourceid", "2", -1, "asc", false, DEPRECATED_STARSEARCH, false, false, "", false, false, false, true);
    $results = is_array($results) ? array_column($results, "ref") : [];
    $results = array_values(array_intersect($results, $fixtures_717));
    if ($results != $case["expected"]) {
        $errors_000717[] = $case["name"] . ": expected the search to return [" . implode(",", $case["expected"])
            . "], got [" . implode(",", $results) . "]";
    }

    $editable = [];
    foreach ($fixtures_717 as $resource) {
        if (get_edit_access($resource)) {
            $editable[] = $resource;
        }
    }
    if ($editable != $case["expected"]) {
        $errors_000717[] = $case["name"] . ": expected get_edit_access() to allow [" . implode(",", $case["expected"])
            . "], got [" . implode(",", $editable) . "]";
    }
}

// Tear down
$userpermissions = $saved_000717["userpermissions"];
$edit_access_for_contributor = $saved_000717["edit_access_for_contributor"];
$usereditfilter = $saved_000717["usereditfilter"];
foreach ($fixtures_717 as $resource) {
    if ($resource !== false) {
        delete_resource($resource);
    }
}
if ($saved_000717["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000717["resource_deletion_state"];
}
unset($cases_717, $fixtures_717, $results, $editable);

if (count($errors_000717) > 0) {
    echo "ERROR - " . implode("; ", $errors_000717) . " ";
    return false;
}
return true;
