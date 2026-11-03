<?php

/**
 * do_search() plugin hooks. A test plugin changes the keywords, the select list, the editable-only filter, the row
 * limit and the whole result, adds a special search, and is told about returned and empty results.
 *
 * Each hook acts only while its mode is selected, so the functions are inert for any later test that also enables
 * the testframework plugin.
 */

command_line_only();

$saved_000709 = [
    "plugins"                 => $plugins,
    "hook_cache"              => $hook_cache ?? null,
    "resource_deletion_state" => $resource_deletion_state ?? null,
];
unset($resource_deletion_state);

$GLOBALS["test_000709"] = ["mode" => "", "before_return" => null, "zero_results" => false];

/**
 * Test plugin hook: replace a placeholder keyword with an indexed one.
 */
function HookTestframeworkAllDosearchmodifykeywords(array $keywords, string $search)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "keywords") {
        return null;
    }
    return str_replace("zzz709", "alpha709", $keywords);
}

/**
 * Test plugin hook: supply the search result instead of running a query.
 */
function HookTestframeworkAllAlternativeresults($go)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "alternative") {
        return null;
    }
    return [["ref" => -709]];
}

/**
 * Test plugin hook: limit every search to one row.
 */
function HookTestframeworkAllModifyfetchrows($fetchrows)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "fetchrows") {
        return null;
    }
    return 1;
}

/**
 * Test plugin hook: add a column to the select list.
 */
function HookTestframeworkAllModifyselect()
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "select") {
        return null;
    }
    return ", 709 AS hook_column_709";
}

/**
 * Test plugin hook: a "!test709<ref>" special search returning that one resource.
 */
function HookTestframeworkAllAddspecialsearch($search, $select, $sql_join, $sql_filter, $sql)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "special" || substr($search, 0, 8) !== "!test709") {
        return null;
    }
    $select->sql = "DISTINCT r.hit_count score, " . $select->sql;
    return new PreparedStatementQuery(
        "SELECT [SELECT_SQL] FROM resource r " . $sql_join->sql . " WHERE r.ref = ? AND " . $sql_filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL]",
        array_merge($select->parameters, $sql_join->parameters, ["i", (int) substr($search, 8)], $sql_filter->parameters)
    );
}

/**
 * Test plugin hook: restrict editable-only searches to one resource.
 */
function HookTestframeworkAllModifysearcheditable($editable_filter, $userref)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "editable") {
        return null;
    }
    return new PreparedStatementQuery("r.ref = ?", ["i", $GLOBALS["test_000709"]["editable_resource"]]);
}

/**
 * Test plugin hook: record what is about to be returned: the rows, or the structured total/data result.
 */
function HookTestframeworkAllBeforereturnresults($result, $archive)
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "before") {
        return null;
    }
    $GLOBALS["test_000709"]["before_return"] = isset($result["data"]) ? "structured:" . count($result["data"]) : "rows:" . count($result);
    return null;
}

/**
 * Test plugin hook: record that a search found nothing.
 */
function HookTestframeworkAllZero_search_results()
{
    if (($GLOBALS["test_000709"]["mode"] ?? "") !== "zero") {
        return null;
    }
    $GLOBALS["test_000709"]["zero_results"] = true;
    return null;
}

$plugins[] = "testframework";
unset($GLOBALS["hook_cache"]);

$first_709  = create_resource(1, 0);
$second_709 = create_resource(1, 0);
update_field($first_709, $view_title_field, "alpha709 first");
update_field($second_709, $view_title_field, "alpha709 second");
$GLOBALS["test_000709"]["editable_resource"] = $first_709;

/**
 * Run a search for this test as an ordinary call, returning rows ordered by reference.
 *
 * @param string $search        Search string
 * @param bool   $editable_only Only return editable resources
 * @param string $archive       Archive states to search
 *
 * @return mixed do_search() result
 */
function test_000709_search(string $search, bool $editable_only = false, string $archive = "0")
{
    return do_search($search, "", "resourceid", $archive, -1, "asc", false, DEPRECATED_STARSEARCH, false, false, "", false, false, false, $editable_only);
}

$errors_000709 = [];

// dosearchmodifykeywords: the placeholder keyword finds nothing until the hook replaces it
$GLOBALS["test_000709"]["mode"] = "";
$results = test_000709_search("zzz709");
if (is_array($results) && count($results) > 0) {
    $errors_000709[] = "keywords: placeholder keyword matched without the hook";
}
$GLOBALS["test_000709"]["mode"] = "keywords";
$results = array_column(test_000709_search("zzz709"), "ref");
if ($results != [$first_709, $second_709]) {
    $errors_000709[] = "keywords: expected both resources via the replaced keyword, got [" . implode(",", $results) . "]";
}

// alternativeresults: the hook's result is returned as it is
$GLOBALS["test_000709"]["mode"] = "alternative";
$results = test_000709_search("alpha709");
if ($results !== [["ref" => -709]]) {
    $errors_000709[] = "alternative results: expected the hook result, got " . json_encode($results);
}

// modifyfetchrows: the hook's limit applies, with legacy padding to the total
$GLOBALS["test_000709"]["mode"] = "fetchrows";
$results = test_000709_search("alpha709");
if (!is_array($results) || count($results) !== 2 || count(array_filter($results, "is_array")) !== 1) {
    $errors_000709[] = "fetchrows: expected 1 row padded to 2, got " . json_encode($results);
}

// modifyselect: the added column is present on every row
$GLOBALS["test_000709"]["mode"] = "select";
$results = test_000709_search("alpha709");
if (!is_array($results) || count($results) !== 2 || count(array_filter($results, fn($row) => (int) ($row["hook_column_709"] ?? 0) === 709)) !== 2) {
    $errors_000709[] = "select: expected hook_column_709 = 709 on both rows, got " . json_encode(array_column($results, "hook_column_709"));
}

// addspecialsearch: the plugin's special search runs through the standard result handling
$GLOBALS["test_000709"]["mode"] = "special";
$results = array_column(test_000709_search("!test709" . $second_709), "ref");
if ($results != [$second_709]) {
    $errors_000709[] = "special search: expected the second resource, got [" . implode(",", $results) . "]";
}

// modifysearcheditable: the hook's filter replaces the editable-only filter
$GLOBALS["test_000709"]["mode"] = "editable";
$results = array_column(test_000709_search("alpha709", true), "ref");
if ($results != [$first_709]) {
    $errors_000709[] = "editable: expected only the first resource, got [" . implode(",", $results) . "]";
}

// beforereturnresults: called with the rows about to be returned
$GLOBALS["test_000709"]["mode"] = "before";
test_000709_search("alpha709");
if ($GLOBALS["test_000709"]["before_return"] !== "rows:2") {
    $errors_000709[] = "before return results: expected 2 rows, got " . json_encode($GLOBALS["test_000709"]["before_return"]);
}

// A special search calls the hook with the total/data structure from sql_limit_with_total_count() instead of the rows
$GLOBALS["test_000709"]["before_return"] = null;
test_000709_search("!list" . $first_709 . ":" . $second_709);
if ($GLOBALS["test_000709"]["before_return"] !== "structured:2") {
    $errors_000709[] = "before return results (special search): expected the structured result with 2 rows, got " . json_encode($GLOBALS["test_000709"]["before_return"]);
}

// zero_search_results: called when the query ran and matched nothing (an unknown keyword stops before the query)
$GLOBALS["test_000709"]["mode"] = "zero";
test_000709_search("alpha709", false, "2");
if ($GLOBALS["test_000709"]["zero_results"] !== true) {
    $errors_000709[] = "zero results: hook was not called";
}

// Tear down
$GLOBALS["test_000709"]["mode"] = "";
delete_resource($first_709);
delete_resource($second_709);
$plugins = $saved_000709["plugins"];
unset($GLOBALS["hook_cache"], $GLOBALS["test_000709"]);
if ($saved_000709["hook_cache"] !== null) {
    $hook_cache = $saved_000709["hook_cache"];
}
if ($saved_000709["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000709["resource_deletion_state"];
}

if (count($errors_000709) > 0) {
    echo "ERROR - " . implode("; ", $errors_000709) . " ";
    return false;
}
return true;
