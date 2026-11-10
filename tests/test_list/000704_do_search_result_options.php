<?php

/**
 * do_search() options that shape the returned rows rather than the match: a row limit with legacy padding, chunked
 * fetchrows, refs only, disk usage totals, the recent days limit and the archive state list.
 */

command_line_only();

$saved_000704 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$title_node_704 = set_node(null, $view_title_field, "test704 result options", "", 1000);

$recent_a_704 = create_resource(1, 0);
$recent_b_704 = create_resource(1, 0);
$old_704      = create_resource(1, 0);
$pending_704  = create_resource(1, 1);
$resources_704 = [$recent_a_704, $recent_b_704, $old_704, $pending_704];
foreach ($resources_704 as $resource) {
    add_resource_nodes($resource, [$title_node_704]);
}
ps_query("UPDATE resource SET disk_usage = 100 WHERE ref = ?", ["i", $recent_a_704]);
ps_query("UPDATE resource SET disk_usage = 200 WHERE ref = ?", ["i", $recent_b_704]);
ps_query("UPDATE resource SET disk_usage = 300, creation_date = (NOW() - INTERVAL 40 DAY) WHERE ref = ?", ["i", $old_704]);

/**
 * Run the node search for this test's resources with the given do_search() options.
 *
 * @param array $options Options keyed by do_search() parameter name
 *
 * @return mixed do_search() result
 */
function test_000704_search(array $options)
{
    global $title_node_704;
    $args = array_merge([
        "restypes"               => "",
        "order_by"               => "resourceid",
        "archive"                => "0",
        "fetchrows"              => -1,
        "sort"                   => "asc",
        "access_override"        => false,
        "starsearch"             => DEPRECATED_STARSEARCH,
        "ignore_filters"         => false,
        "return_disk_usage"      => false,
        "recent_search_daylimit" => "",
        "go"                     => false,
        "stats_logging"          => false,
        "return_refs_only"       => false,
    ], $options);
    return call_user_func_array("do_search", array_merge(["@@" . $title_node_704], array_values($args)));
}

$errors_000704 = [];

// A row limit returns that many rows padded with 0 up to the total
$results = test_000704_search(["fetchrows" => 2]);
$rows = array_filter($results, "is_array");
if (count($results) !== 3 || count($rows) !== 2 || end($results) !== 0) {
    $errors_000704[] = "row limit: expected 2 rows padded to 3, got " . count($rows) . " rows in " . count($results) . " entries";
}

// Chunked fetchrows returns the total and the requested slice without padding
$results = test_000704_search(["fetchrows" => [1, 1]]);
if (!isset($results["total"], $results["data"]) || (int) $results["total"] !== 3 || count($results["data"]) !== 1 || (int) $results["data"][0]["ref"] !== $recent_b_704) {
    $errors_000704[] = "chunked fetchrows: expected total 3 with the second resource, got " . json_encode($results);
}

// Refs only returns rows holding nothing but the reference
$results = test_000704_search(["return_refs_only" => true]);
$refs_only = is_array($results) && count($results) === 3 && count(array_filter($results, fn($row) => array_keys($row) === ["ref"])) === 3;
if (!$refs_only) {
    $errors_000704[] = "refs only: expected 3 rows with only a ref, got " . json_encode($results);
}

// Refs only with a row limit returns just that many references: unlike a special search, nothing is padded
$results = test_000704_search(["return_refs_only" => true, "fetchrows" => 2]);
$refs_only = is_array($results) && count($results) === 2 && count(array_filter($results, fn($row) => is_array($row) && array_keys($row) === ["ref"])) === 2;
if (!$refs_only) {
    $errors_000704[] = "refs only with a row limit: expected 2 rows with only a ref, got " . json_encode($results);
}

// A search that matches nothing returns an empty array, with or without refs only
$results = test_000704_search(["archive" => "2"]);
if ($results !== []) {
    $errors_000704[] = "no match: expected an empty array, got " . json_encode($results);
}
$results = test_000704_search(["archive" => "2", "return_refs_only" => true]);
if ($results !== []) {
    $errors_000704[] = "no match, refs only: expected an empty array, got " . json_encode($results);
}

// Disk usage returns one row totalling the matched resources
$results = test_000704_search(["return_disk_usage" => true]);
if (!is_array($results) || count($results) !== 1 || (int) $results[0]["total_disk_usage"] !== 600 || (int) $results[0]["total_resources"] !== 3) {
    $errors_000704[] = "disk usage: expected 600 bytes over 3 resources, got " . json_encode($results);
}

// The recent days limit drops resources created before the window
$results = array_column(test_000704_search(["recent_search_daylimit" => "30"]), "ref");
if ($results != [$recent_a_704, $recent_b_704]) {
    $errors_000704[] = "recent days: expected the two recent resources, got [" . implode(",", $results) . "]";
}

// The archive list adds the pending resource
$results = array_column(test_000704_search(["archive" => "0,1"]), "ref");
if ($results != [$recent_a_704, $recent_b_704, $old_704, $pending_704]) {
    $errors_000704[] = "archive list: expected all four resources, got [" . implode(",", $results) . "]";
}

// Tear down
foreach ($resources_704 as $resource) {
    delete_resource($resource);
}
delete_node($title_node_704);
if ($saved_000704["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000704["resource_deletion_state"];
}

if (count($errors_000704) > 0) {
    echo "ERROR - " . implode("; ", $errors_000704) . " ";
    return false;
}
return true;
