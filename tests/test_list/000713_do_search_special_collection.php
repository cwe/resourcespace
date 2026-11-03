<?php

/**
 * do_search() "!collection" special search: the collection's resources in every archive state with their collection
 * columns, the collection ordering, $collections_omit_archived, a node restriction, and access for a user who is not
 * an administrator.
 */

command_line_only();

$saved_000713 = [
    "resource_deletion_state" => $resource_deletion_state ?? null,
    "userpermissions"         => $userpermissions,
    "hidden_fields_cache"     => $hidden_fields_cache ?? null,
    "collections_omit_archived" => $collections_omit_archived,
];
unset($resource_deletion_state);
$collections_omit_archived = false;

$node_713 = set_node(null, $view_title_field, "test713 collection", "", 1000);

$first_713    = create_resource(1, 0);
$second_713   = create_resource(1, 0);
$outside_713  = create_resource(1, 0);
$archived_713 = create_resource(1, 2);
$fixtures_713 = [$first_713, $second_713, $outside_713, $archived_713];
add_resource_nodes($first_713, [$node_713]);

$collection_713 = create_collection($userref, "Test 713 collection");
add_resource_to_collection($first_713, $collection_713, false, "", "", null, null, "", 2);
add_resource_to_collection($second_713, $collection_713, false, "", "", null, null, "", 1);
add_resource_to_collection($archived_713, $collection_713, false, "", "", null, null, "", 3);
// Owned by another user and not public
$other_collection_713 = create_collection(999713, "Test 713 other user's collection");
add_resource_to_collection($outside_713, $other_collection_713);

/**
 * Run a collection search for this test, returning the fixture references in result order.
 *
 * @param string $search   Search string
 * @param string $archive  Archive states to search
 * @param string $order_by Ordering
 * @param string $sort     Sort direction
 *
 * @return array
 */
function test_000713_search(string $search, string $archive = "0", string $order_by = "relevance", string $sort = "desc"): array
{
    global $fixtures_713;
    $results = do_search($search, "", $order_by, $archive, -1, $sort, false, DEPRECATED_STARSEARCH, false, false, "", false, false);
    return is_array($results) ? array_values(array_intersect(array_column($results, "ref"), $fixtures_713)) : [];
}

$errors_000713 = [];

// The collection's resources in every archive state: the archive filter does not apply to a collection
$results = test_000713_search("!collection" . $collection_713);
sort($results);
if ($results != [$first_713, $second_713, $archived_713]) {
    $errors_000713[] = "collection: expected all three members, got [" . implode(",", $results) . "]";
}

// Rows carry the collection columns
$rows = do_search("!collection" . $collection_713, "", "relevance", "0", -1, "desc", false, DEPRECATED_STARSEARCH, false, false, "", false, false);
$row = is_array($rows) && count($rows) > 0 ? $rows[0] : [];
if (!array_key_exists("date_added", $row) || !array_key_exists("comment", $row)) {
    $errors_000713[] = "collection columns: expected date_added and comment, got " . implode(",", array_keys($row));
}

// The collection ordering follows the sort order given when the resources were added
$results = test_000713_search("!collection" . $collection_713, "0", "collection", "asc");
if ($results != [$second_713, $first_713, $archived_713]) {
    $errors_000713[] = "collection order asc: expected second, first, archived, got [" . implode(",", $results) . "]";
}
$results = test_000713_search("!collection" . $collection_713, "0", "collection", "desc");
if ($results != [$archived_713, $first_713, $second_713]) {
    $errors_000713[] = "collection order desc: expected archived, first, second, got [" . implode(",", $results) . "]";
}

// $collections_omit_archived hides archived resources only from users who cannot edit them; an administrator can
$collections_omit_archived = true;
$results = test_000713_search("!collection" . $collection_713);
sort($results);
if ($results != [$first_713, $second_713, $archived_713]) {
    $errors_000713[] = "omit archived as an administrator: expected all three members, got [" . implode(",", $results) . "]";
}
$userpermissions = ["s"];
$hidden_fields_cache = null;
$results = test_000713_search("!collection" . $collection_713);
sort($results);
if ($results != [$first_713, $second_713]) {
    $errors_000713[] = "omit archived as a user: expected the two active resources, got [" . implode(",", $results) . "]";
}
$userpermissions = $saved_000713["userpermissions"];
$hidden_fields_cache = $saved_000713["hidden_fields_cache"];
$collections_omit_archived = false;

// A node restriction applies
$results = test_000713_search("!collection" . $collection_713 . "," . NODE_TOKEN_PREFIX . $node_713);
if ($results != [$first_713]) {
    $errors_000713[] = "collection with node: expected only the first resource, got [" . implode(",", $results) . "]";
}

// A user who is not an administrator sees their own collection but not another user's private collection
$userpermissions = ["s"];
$hidden_fields_cache = null;
$results = test_000713_search("!collection" . $collection_713);
sort($results);
if ($results != [$first_713, $second_713, $archived_713]) {
    $errors_000713[] = "own collection as a user: expected all three members, got [" . implode(",", $results) . "]";
}
$results = test_000713_search("!collection" . $other_collection_713);
if ($results != []) {
    $errors_000713[] = "another user's collection: expected no results, got [" . implode(",", $results) . "]";
}
$userpermissions = $saved_000713["userpermissions"];
$hidden_fields_cache = $saved_000713["hidden_fields_cache"];

// Tear down
delete_collection($collection_713);
delete_collection($other_collection_713);
foreach ($fixtures_713 as $resource) {
    delete_resource($resource);
}
delete_node($node_713);
$collections_omit_archived = $saved_000713["collections_omit_archived"];
if ($saved_000713["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000713["resource_deletion_state"];
}
unset($fixtures_713, $results, $rows, $row);

if (count($errors_000713) > 0) {
    echo "ERROR - " . implode("; ", $errors_000713) . " ";
    return false;
}
return true;
