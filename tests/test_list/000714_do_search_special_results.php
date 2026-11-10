<?php

/**
 * do_search() result handling for special searches, which shape their rows separately from standard searches: a
 * row limit with legacy padding, chunked fetchrows, the reduced refs-only query, disk usage totals and the returned
 * SQL.
 */

command_line_only();

$saved_000714 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$first_714  = create_resource(1, 0);
$second_714 = create_resource(1, 0);
$third_714  = create_resource(1, 0);
$resources_714 = [$first_714, $second_714, $third_714];
ps_query("UPDATE resource SET disk_usage = 100 WHERE ref = ?", ["i", $first_714]);
ps_query("UPDATE resource SET disk_usage = 200 WHERE ref = ?", ["i", $second_714]);
ps_query("UPDATE resource SET disk_usage = 300 WHERE ref = ?", ["i", $third_714]);

/**
 * Run the !list search for this test's resources with the given do_search() options.
 *
 * @param array       $options Options keyed by do_search() parameter name
 * @param string|null $search  Search to run instead of the !list search
 *
 * @return mixed do_search() result
 */
function test_000714_search(array $options, ?string $search = null)
{
    global $resources_714;
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
        "editable_only"          => false,
        "returnsql"              => false,
    ], $options);
    $search ??= "!list" . implode(":", $resources_714);
    return call_user_func_array("do_search", array_merge([$search], array_values($args)));
}

$errors_000714 = [];

// A row limit returns that many rows padded with 0 up to the total
$results = test_000714_search(["fetchrows" => 2]);
$rows = is_array($results) ? array_filter($results, "is_array") : [];
if (!is_array($results) || count($results) !== 3 || count($rows) !== 2 || end($results) !== 0) {
    $errors_000714[] = "row limit: expected 2 rows padded to 3, got " . json_encode($results);
}

// Chunked fetchrows returns the total and the requested slice without padding
$results = test_000714_search(["fetchrows" => [1, 1]]);
if (!isset($results["total"], $results["data"]) || (int) $results["total"] !== 3 || count($results["data"]) !== 1 || (int) $results["data"][0]["ref"] !== $second_714) {
    $errors_000714[] = "chunked fetchrows: expected total 3 with the second resource, got " . json_encode($results);
}

// All rows requested: every row is returned and nothing is padded
$results = test_000714_search([]);
if (!is_array($results) || count($results) !== 3 || count(array_filter($results, "is_array")) !== 3) {
    $errors_000714[] = "all rows: expected 3 rows without padding, got " . json_encode($results);
}

// A special search that matches nothing returns an empty array, or an empty chunk
$results = test_000714_search([], "!list999999999");
if ($results !== []) {
    $errors_000714[] = "no match: expected an empty array, got " . json_encode($results);
}
$results = test_000714_search(["fetchrows" => [0, 10]], "!list999999999");
if (!isset($results["total"], $results["data"]) || (int) $results["total"] !== 0 || $results["data"] !== []) {
    $errors_000714[] = "no match, chunked: expected total 0 with no data, got " . json_encode($results);
}

// Refs only with a row limit: unlike a standard search, the reduced rows are still padded up to the total
$results = test_000714_search(["return_refs_only" => true, "fetchrows" => 2]);
$rows = is_array($results) ? array_filter($results, "is_array") : [];
if (!is_array($results) || count($results) !== 3 || count($rows) !== 2 || end($results) !== 0) {
    $errors_000714[] = "refs only with a row limit: expected 2 rows padded to 3, got " . json_encode($results);
}

// Refs only runs the reduced query: every row has a ref and the display columns are left out
$results = test_000714_search(["return_refs_only" => true]);
$refs_only = is_array($results) && count($results) === 3
    && count(array_filter($results, fn($row) => isset($row["ref"]) && !array_key_exists("file_extension", $row))) === 3;
if (!$refs_only) {
    $errors_000714[] = "refs only: expected 3 reduced rows with a ref, got " . json_encode($results);
}

// Disk usage returns one row totalling the matched resources
$results = test_000714_search(["return_disk_usage" => true]);
if (!is_array($results) || count($results) !== 1 || (int) $results[0]["total_disk_usage"] !== 600 || (int) $results[0]["total_resources"] !== 3) {
    $errors_000714[] = "disk usage: expected 600 bytes over 3 resources, got " . json_encode($results);
}

// The returned SQL is complete, with a parameter for every placeholder
$results = test_000714_search(["returnsql" => true]);
if (
    !($results instanceof PreparedStatementQuery)
    || strpos($results->sql, "ORDER BY") === false
    || strpos($results->sql, "[SELECT_SQL]") !== false
    || substr_count($results->sql, "?") * 2 !== count($results->parameters)
) {
    $errors_000714[] = "returnsql: expected a complete statement with matching parameters, got " . json_encode($results);
}

// Tear down
foreach ($resources_714 as $resource) {
    delete_resource($resource);
}
if ($saved_000714["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000714["resource_deletion_state"];
}
unset($resources_714, $results, $rows);

if (count($errors_000714) > 0) {
    echo "ERROR - " . implode("; ", $errors_000714) . " ";
    return false;
}
return true;
