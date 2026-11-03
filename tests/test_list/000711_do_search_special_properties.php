<?php

/**
 * do_search() "!properties" special search: each property restricts by a resource or preview dimension column,
 * properties combine with AND, unknown properties and orientations are ignored, and a node restriction applies on
 * top of the properties.
 */

command_line_only();

$saved_000711 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$node_711 = set_node(null, $view_title_field, "test711 properties", "", 1000);

// [width, height, file size in MB, extension, has_image, created_by]
$rows_711 = [
    [1000, 2000, 3,   "jpg", 1, $userref],
    [3000, 1500, 10,  "png", 0, $userref],
    [500,  500,  0.5, "tif", 1, 999711],
];
$resources_711 = [];
foreach ($rows_711 as $n => $row) {
    $resources_711[$n] = create_resource(1, 0);
    ps_query(
        "INSERT INTO resource_dimensions (resource, width, height) VALUES (?, ?, ?)",
        ["i", $resources_711[$n], "i", $row[0], "i", $row[1]]
    );
    ps_query(
        "UPDATE resource SET file_size = ?, file_extension = ?, has_image = ?, created_by = ? WHERE ref = ?",
        ["i", (int) ($row[2] * 1024 * 1024), "s", $row[3], "i", $row[4], "i", $row[5], "i", $resources_711[$n]]
    );
}
list($portrait_711, $landscape_711, $square_711) = $resources_711;
add_resource_nodes($portrait_711, [$node_711]);
add_resource_nodes($square_711, [$node_711]);

$use_cases = [
    ["name" => "hmin",                           "search" => "!propertieshmin:1000",            "expected" => [$portrait_711, $landscape_711]],
    ["name" => "hmax",                           "search" => "!propertieshmax:1500",            "expected" => [$landscape_711, $square_711]],
    ["name" => "wmin",                           "search" => "!propertieswmin:1000",            "expected" => [$portrait_711, $landscape_711]],
    ["name" => "wmax",                           "search" => "!propertieswmax:1000",            "expected" => [$portrait_711, $square_711]],
    ["name" => "fmin is in megabytes",           "search" => "!propertiesfmin:2",               "expected" => [$portrait_711, $landscape_711]],
    ["name" => "fmax is in megabytes",           "search" => "!propertiesfmax:5",               "expected" => [$portrait_711, $square_711]],
    ["name" => "fext",                           "search" => "!propertiesfext:jpg",             "expected" => [$portrait_711]],
    ["name" => "fext ignores a leading dot",     "search" => "!propertiesfext:.png",            "expected" => [$landscape_711]],
    ["name" => "fext negated with a minus",      "search" => "!propertiesfext:-jpg",            "expected" => [$landscape_711, $square_711]],
    ["name" => "fext wildcard",                  "search" => "!propertiesfext:t*",              "expected" => [$square_711]],
    ["name" => "pi with a preview",              "search" => "!propertiespi:1",                 "expected" => [$portrait_711, $square_711]],
    ["name" => "pi without a preview",           "search" => "!propertiespi:0",                 "expected" => [$landscape_711]],
    ["name" => "cu contributed by",              "search" => "!propertiescu:999711",            "expected" => [$square_711]],
    ["name" => "orientation portrait",           "search" => "!propertiesorientation:portrait", "expected" => [$portrait_711]],
    ["name" => "orientation landscape",          "search" => "!propertiesorientation:landscape", "expected" => [$landscape_711]],
    ["name" => "orientation square",             "search" => "!propertiesorientation:square",   "expected" => [$square_711]],
    ["name" => "unknown orientation is ignored", "search" => "!propertiesorientation:round",    "expected" => $resources_711],
    ["name" => "unknown property is ignored",    "search" => "!propertiesbogus:1",              "expected" => $resources_711],
    ["name" => "properties combine with AND",    "search" => "!propertieshmin:1000;fext:jpg",   "expected" => [$portrait_711]],
    ["name" => "node restriction applies",       "search" => "!propertiespi:1," . NODE_TOKEN_PREFIX . $node_711,   "expected" => [$portrait_711, $square_711]],
    ["name" => "node restriction can exclude",   "search" => "!propertiesfext:png," . NODE_TOKEN_PREFIX . $node_711, "expected" => []],
];

foreach ($use_cases as $use_case) {
    $results = do_search($use_case["search"], "", "resourceid", "0", -1, "asc", false, DEPRECATED_STARSEARCH, false, false, "", false, false);
    // Only compare the fixtures: other resources may match a property too
    $results = is_array($results) ? array_values(array_intersect(array_column($results, "ref"), $resources_711)) : [];
    $expected = $use_case["expected"];
    sort($results);
    sort($expected);
    if ($results != $expected) {
        echo "ERROR - SUBTEST " . $use_case["name"] . ": expected [" . implode(",", $expected) . "], got [" . implode(",", $results) . "] ";
        return false;
    }
}

// Tear down
foreach ($resources_711 as $resource) {
    ps_query("DELETE FROM resource_dimensions WHERE resource = ?", ["i", $resource]);
    delete_resource($resource);
}
delete_node($node_711);
if ($saved_000711["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000711["resource_deletion_state"];
}
unset($use_cases, $rows_711, $resources_711, $portrait_711, $landscape_711, $square_711, $results, $expected);

return true;
