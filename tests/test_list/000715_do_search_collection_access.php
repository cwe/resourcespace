<?php

/**
 * do_search() "!collection" special search: who may search which collection, and the smart collection refresh.
 *
 * search_special_collection() decides access before it builds the query. A user with the 'a' permission may search
 * any collection. Anyone else needs the collection to be one they own, one shared with them or their group, a
 * public collection, a featured collection their 'j' permissions open, a requested collection when they have the
 * 'R' permission, or any collection at all when $ignore_collection_access is set or an external share key is in
 * use. The collection must then also pass collection_readable(). A denied search returns no results.
 *
 * Each case below runs one search as an administrator or as a user holding only the 's' permission plus the
 * permissions the case names. The cases pin the current behaviour, including the two marked as surprising.
 */

command_line_only();

$saved_000715 = [
    "resource_deletion_state"  => $resource_deletion_state ?? null,
    "userpermissions"          => $userpermissions,
    "hidden_fields_cache"      => $hidden_fields_cache ?? null,
    "k"                        => $k ?? null,
    "ignore_collection_access" => $ignore_collection_access,
    "upload_share_active"      => $upload_share_active ?? null,
    "allow_smart_collections"  => $allow_smart_collections,
    "smart_collections_async"  => $smart_collections_async,
    "smartsearch_ref_cache"    => $GLOBALS["smartsearch_ref_cache"] ?? null,
];
unset($resource_deletion_state);

$other_user_715 = 999715;

/**
 * Create a resource and a collection holding it.
 *
 * @param int   $owner  User the collection belongs to
 * @param string $name  Collection name
 * @param bool  $public Create a public collection
 * @param array $extra  Extra collection columns, see create_collection()
 *
 * @return array{0: int, 1: int} The collection and the resource in it
 */
function test_000715_collection(int $owner, string $name, bool $public = false, array $extra = []): array
{
    $resource = create_resource(1, 0);
    $collection = create_collection($owner, "Test 715 " . $name, 0, 0, 0, $public, $extra);
    add_resource_to_collection($resource, $collection);
    return [$collection, $resource];
}

[$own_col, $own_res]           = test_000715_collection($userref, "own");
[$private_col, $private_res]   = test_000715_collection($other_user_715, "private");
[$public_col, $public_res]     = test_000715_collection($other_user_715, "public", true);
[$shared_col, $shared_res]     = test_000715_collection($other_user_715, "shared with the user");
[$group_col, $group_res]       = test_000715_collection($other_user_715, "shared with the group");
[$featured_col, $featured_res] = test_000715_collection($other_user_715, "featured", false, ["type" => COLLECTION_TYPE_FEATURED]);
[$request_col, $request_res]   = test_000715_collection(-2, "externally requested");
[$research_col, $research_res] = test_000715_collection($other_user_715, "research");
[$smart_col, $smart_stale_res] = test_000715_collection($userref, "smart");

ps_query("INSERT INTO user_collection (user, collection, request_feedback) VALUES (?, ?, 0)", ["i", $userref, "i", $shared_col]);
ps_query("INSERT INTO usergroup_collection (usergroup, collection, request_feedback) VALUES (?, ?, 0)", ["i", $usergroup, "i", $group_col]);
ps_query(
    "INSERT INTO research_request (name, created, user, status, collection) VALUES ('Test 715 research', NOW(), ?, 0, ?)",
    ["i", $other_user_715, "i", $research_col]
);
$research_request_715 = sql_insert_id();

// The upload review collection of a user has the negated user reference, as pages/upload_batch.php creates it
$upload_col_715 = 0 - $userref;
$upload_col_created_715 = get_collection($upload_col_715) === false;
if ($upload_col_created_715) {
    create_collection($userref, "New uploads", 1, 1, $upload_col_715);
}
$upload_res_715 = create_resource(1, 0);
ps_query("INSERT INTO collection_resource (collection, resource) VALUES (?, ?)", ["i", $upload_col_715, "i", $upload_res_715]);

// A smart collection whose saved search matches one resource that is not yet a member
$smart_node_715 = set_node(null, $view_title_field, "test715 smart collection", "", 1000);
$smart_match_res = create_resource(1, 0);
add_resource_nodes($smart_match_res, [$smart_node_715]);
ps_query(
    "INSERT INTO collection_savedsearch (collection, search, restypes, starsearch, archive, created, result_limit) VALUES (?, ?, '', 0, '0', NOW(), 0)",
    ["i", $smart_col, "s", NODE_TOKEN_PREFIX . $smart_node_715]
);
$smart_search_715 = sql_insert_id();
ps_query("UPDATE collection SET savedsearch = ? WHERE ref = ?", ["i", $smart_search_715, "i", $smart_col]);

$fixtures_715 = [
    $own_res, $private_res, $public_res, $shared_res, $group_res, $featured_res, $request_res, $research_res,
    $smart_stale_res, $smart_match_res, $upload_res_715,
];
clear_query_cache("featured_collections");

/**
 * Run a collection search for this test and return the fixture references found, in ascending order.
 *
 * @param int|string $collection        Collection reference
 * @param bool       $return_disk_usage Ask for the disk usage total instead of rows
 *
 * @return array|string Fixture references, or a description of an unexpected result
 */
function test_000715_search($collection, bool $return_disk_usage = false)
{
    global $fixtures_715;
    unset($GLOBALS["get_collection_cache"], $GLOBALS["collection_readable_key_check_cache"]);
    $results = do_search(
        "!collection" . $collection,
        "",
        "resourceid",
        "0",
        -1,
        "asc",
        false,
        DEPRECATED_STARSEARCH,
        false,
        $return_disk_usage,
        "",
        false,
        false
    );
    if ($return_disk_usage) {
        return is_array($results) ? (int) ($results[0]["total_resources"] ?? -1) : "not an array";
    }
    if (!is_array($results)) {
        return "not an array: " . json_encode($results);
    }
    return array_values(array_intersect(array_column($results, "ref"), $fixtures_715));
}

// "admin" runs with the administrator's permissions; otherwise the user holds 's' plus "permissions".
// "globals" sets $k, $ignore_collection_access or $upload_share_active for the case.
$cases_715 = [
    ["name" => "administrator, another user's private collection", "admin" => true,
        "collection" => $private_col, "expected" => [$private_res]],
    ["name" => "administrator, a collection that does not exist", "admin" => true,
        "collection" => 999715999, "expected" => []],

    ["name" => "own collection", "collection" => $own_col, "expected" => [$own_res]],
    ["name" => "another user's private collection", "collection" => $private_col, "expected" => []],
    ["name" => "a collection that does not exist", "collection" => 999715999, "expected" => []],
    ["name" => "another user's public collection", "collection" => $public_col, "expected" => [$public_res]],
    ["name" => "a collection shared with the user", "collection" => $shared_col, "expected" => [$shared_res]],
    ["name" => "a collection shared with the user's group", "collection" => $group_col, "expected" => [$group_res]],

    ["name" => "featured collection without a j permission", "collection" => $featured_col, "expected" => []],
    ["name" => "featured collection with j*", "permissions" => ["j*"],
        "collection" => $featured_col, "expected" => [$featured_res]],
    ["name" => "featured collection with its own j permission", "permissions" => ["j" . $featured_col],
        "collection" => $featured_col, "expected" => [$featured_res]],
    ["name" => "featured collection denied by -j", "permissions" => ["j*", "-j" . $featured_col],
        "collection" => $featured_col, "expected" => []],

    ["name" => "requested collection without the R permission", "collection" => $request_col, "expected" => []],
    ["name" => "requested collection with the R permission", "permissions" => ["R"],
        "collection" => $request_col, "expected" => [$request_res]],
    ["name" => "research collection without the r permission", "collection" => $research_col, "expected" => []],
    // Surprising: the 'r' permission puts a research collection on the list of collections the user may search,
    // but collection_readable() then refuses it, so 'r' opens it only together with a permission that makes it
    // readable. 'h' makes any collection readable, yet does not put a collection on the list by itself.
    ["name" => "research collection with the r permission", "permissions" => ["r"],
        "collection" => $research_col, "expected" => []],
    ["name" => "research collection with the r and h permissions", "permissions" => ["r", "h"],
        "collection" => $research_col, "expected" => [$research_res]],
    ["name" => "private collection with the h permission", "permissions" => ["h"],
        "collection" => $private_col, "expected" => []],

    ["name" => "private collection with \$ignore_collection_access", "globals" => ["ignore_collection_access" => true],
        "collection" => $private_col, "expected" => [$private_res]],
    // Surprising: the search only tests that a share key is present. This key does not exist, yet it opens the
    // collection. Validating the key is left to the page, see check_access_key_collection() in pages/search.php.
    ["name" => "private collection with a share key that does not exist", "globals" => ["k" => "test715key"],
        "collection" => $private_col, "expected" => [$private_res]],
    ["name" => "private collection during an upload share", "globals" => ["k" => "test715key", "upload_share_active" => 1],
        "collection" => $private_col, "expected" => []],

    ["name" => "administrator, own upload review collection", "admin" => true,
        "collection" => $upload_col_715, "expected" => [$upload_res_715]],
    ["name" => "own upload review collection", "collection" => $upload_col_715, "expected" => [$upload_res_715]],
    ["name" => "another user's upload review collection", "collection" => 0 - $other_user_715, "expected" => []],
];

$errors_000715 = [];
foreach ($cases_715 as $case) {
    $userpermissions = !empty($case["admin"])
        ? $saved_000715["userpermissions"]
        : array_merge(["s"], $case["permissions"] ?? []);
    $hidden_fields_cache = null;
    $k = $case["globals"]["k"] ?? "";
    $ignore_collection_access = $case["globals"]["ignore_collection_access"] ?? false;
    unset($upload_share_active);
    if (isset($case["globals"]["upload_share_active"])) {
        $upload_share_active = $case["globals"]["upload_share_active"];
    }

    $results = test_000715_search($case["collection"]);
    if ($results != $case["expected"]) {
        $errors_000715[] = $case["name"] . ": expected [" . implode(",", $case["expected"]) . "], got "
            . (is_array($results) ? "[" . implode(",", $results) . "]" : $results);
    }
}
$userpermissions = $saved_000715["userpermissions"];
$hidden_fields_cache = $saved_000715["hidden_fields_cache"];
$k = "";
$ignore_collection_access = false;
unset($upload_share_active);

// A smart collection is refreshed from its saved search before it is listed: the matching resource joins and the
// stale member leaves. Nothing is refreshed when only the disk usage is asked for, or when smart collections are off.
$smart_collections_async = false;
$allow_smart_collections = false;
$GLOBALS["smartsearch_ref_cache"] = [];
$results = test_000715_search($smart_col);
if ($results != [$smart_stale_res]) {
    $errors_000715[] = "smart collection with smart collections off: expected the stale member, got " . json_encode($results);
}
$allow_smart_collections = true;
$GLOBALS["smartsearch_ref_cache"] = [];
$results = test_000715_search($smart_col, true);
$members = ps_array("SELECT resource value FROM collection_resource WHERE collection = ?", ["i", $smart_col]);
if ($members != [$smart_stale_res]) {
    $errors_000715[] = "smart collection disk usage: expected no refresh, members are [" . implode(",", $members) . "]";
}
$results = test_000715_search($smart_col);
if ($results != [$smart_match_res]) {
    $errors_000715[] = "smart collection: expected only the resource matching the saved search, got " . json_encode($results);
}

// Tear down
ps_query("DELETE FROM collection_savedsearch WHERE ref = ?", ["i", $smart_search_715]);
ps_query("DELETE FROM research_request WHERE ref = ?", ["i", $research_request_715]);
ps_query("DELETE FROM user_collection WHERE collection = ?", ["i", $shared_col]);
ps_query("DELETE FROM usergroup_collection WHERE collection = ?", ["i", $group_col]);
ps_query("DELETE FROM collection_resource WHERE collection = ? AND resource = ?", ["i", $upload_col_715, "i", $upload_res_715]);
if ($upload_col_created_715) {
    delete_collection($upload_col_715);
}
foreach ([$own_col, $private_col, $public_col, $shared_col, $group_col, $featured_col, $request_col, $research_col, $smart_col] as $collection) {
    delete_collection($collection);
}
foreach ($fixtures_715 as $resource) {
    delete_resource($resource);
}
delete_node($smart_node_715);
clear_query_cache("featured_collections");

$allow_smart_collections = $saved_000715["allow_smart_collections"];
$smart_collections_async = $saved_000715["smart_collections_async"];
$ignore_collection_access = $saved_000715["ignore_collection_access"];
$k = $saved_000715["k"];
if ($saved_000715["upload_share_active"] !== null) {
    $upload_share_active = $saved_000715["upload_share_active"];
}
if ($saved_000715["smartsearch_ref_cache"] !== null) {
    $GLOBALS["smartsearch_ref_cache"] = $saved_000715["smartsearch_ref_cache"];
} else {
    unset($GLOBALS["smartsearch_ref_cache"]);
}
if ($saved_000715["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000715["resource_deletion_state"];
}
unset($GLOBALS["get_collection_cache"], $GLOBALS["collection_readable_key_check_cache"]);
unset($cases_715, $fixtures_715, $results, $members);

if (count($errors_000715) > 0) {
    echo "ERROR - " . implode("; ", $errors_000715) . " ";
    return false;
}
return true;
