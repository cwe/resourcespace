<?php

/**
 * do_search() named orderings handled by set_search_order_by(): resourceid, rating, popularity, status, extension,
 * file_path, modified, colour, resourcetype, date, country, random and an unknown value falling back to relevance.
 *
 * Four resources share a title node so a node search isolates them; the columns each ordering reads are written
 * directly so every ordering is decided by a different column.
 */

command_line_only();

$saved_000703 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$type_a_703 = create_resource_type("Test 703 type A");
$type_b_703 = create_resource_type("Test 703 type B");
ps_query("UPDATE resource_type SET order_by = 2 WHERE ref = ?", ["i", $type_a_703]);
ps_query("UPDATE resource_type SET order_by = 1 WHERE ref = ?", ["i", $type_b_703]);
clear_restype_cache();

$title_node_703 = set_node(null, $view_title_field, "test703 order by", "", 1000);

// [resource type, archive state, rating, user_rating, extension, path, has_image, red, green, blue, modified, date, country]
$rows_703 = [
    [$type_a_703,  0, 2, 30, "png", "c.png", 1, 10,  0,  0, "2021-03-03 00:00:00", "2022-01-03", "Chile"],
    [$type_b_703,  1, 4, 10, "gif", "a.gif", 1,  0, 10,  0, "2021-01-01 00:00:00", "2022-01-01", "Albania"],
    [$type_a_703,  2, 1, 20, "tif", "d.tif", 0,  0,  0,  0, "2021-04-04 00:00:00", "2022-01-04", "Denmark"],
    [$type_b_703, -1, 3, 40, "jpg", "b.jpg", 1,  0,  0, 10, "2021-02-02 00:00:00", "2022-01-02", "Brazil"],
];
$resources_703 = [];
foreach ($rows_703 as $n => $row) {
    $resources_703[$n] = create_resource($row[0], $row[1]);
    add_resource_nodes($resources_703[$n], [$title_node_703]);
    update_field($resources_703[$n], $date_field, $row[11]);
    if (in_array(3, get_resource_table_joins())) {
        update_field($resources_703[$n], 3, $row[12]);
    }
    // Last so that the modified timestamp is not touched again
    ps_query(
        "UPDATE resource SET rating = ?, user_rating = ?, file_extension = ?, file_path = ?, has_image = ?, image_red = ?, image_green = ?, image_blue = ?, modified = ? WHERE ref = ?",
        ["i", $row[2], "i", $row[3], "s", $row[4], "s", $row[5], "i", $row[6], "i", $row[7], "i", $row[8], "i", $row[9], "s", $row[10], "i", $resources_703[$n]]
    );
}
list($r0, $r1, $r2, $r3) = $resources_703;

$test_cases = [
    "resourceid asc"   => ["order_by" => "resourceid",   "sort" => "asc",  "expected" => [$r0, $r1, $r2, $r3]],
    "resourceid desc"  => ["order_by" => "resourceid",   "sort" => "desc", "expected" => [$r3, $r2, $r1, $r0]],
    "rating asc"       => ["order_by" => "rating",       "sort" => "asc",  "expected" => [$r2, $r0, $r3, $r1]],
    "popularity desc"  => ["order_by" => "popularity",   "sort" => "desc", "expected" => [$r3, $r0, $r2, $r1]],
    "status asc"       => ["order_by" => "status",       "sort" => "asc",  "expected" => [$r3, $r0, $r1, $r2]],
    "extension asc"    => ["order_by" => "extension",    "sort" => "asc",  "expected" => [$r1, $r3, $r0, $r2]],
    "file_path asc"    => ["order_by" => "file_path",    "sort" => "asc",  "expected" => [$r1, $r3, $r0, $r2]],
    "modified asc"     => ["order_by" => "modified",     "sort" => "asc",  "expected" => [$r1, $r3, $r0, $r2]],
    // has_image first, then blue, green and red
    "colour asc"       => ["order_by" => "colour",       "sort" => "asc",  "expected" => [$r2, $r0, $r1, $r3]],
    // resource_type.order_by first, then the resource type reference
    "resourcetype asc" => ["order_by" => "resourcetype", "sort" => "asc",  "expected" => [$r1, $r3, $r0, $r2]],
    "date asc"         => ["order_by" => "date",         "sort" => "asc",  "expected" => [$r1, $r3, $r0, $r2]],
    "random"           => ["order_by" => "random",       "sort" => "asc",  "expected" => [$r0, $r1, $r2, $r3], "any_order" => true],
    "unknown value"    => ["order_by" => "sideways",     "sort" => "asc",  "expected" => [$r0, $r1, $r2, $r3], "any_order" => true],
];
if (in_array(3, get_resource_table_joins())) {
    $test_cases["country asc"] = ["order_by" => "country", "sort" => "asc", "expected" => [$r1, $r3, $r0, $r2]];
    // All titles are equal, so the country decides
    $test_cases["titleandcountry asc"] = ["order_by" => "titleandcountry", "sort" => "asc", "expected" => [$r1, $r3, $r0, $r2]];
}

foreach ($test_cases as $test => $case) {
    $results = do_search("@@" . $title_node_703, "", $case["order_by"], "-1,0,1,2", -1, $case["sort"], false, DEPRECATED_STARSEARCH, false, false, "", false, false);
    $results = is_array($results) ? array_values(array_intersect(array_column($results, "ref"), $resources_703)) : [];
    $expected = $case["expected"];
    if (!empty($case["any_order"])) {
        sort($results);
        sort($expected);
    }
    if ($results != $expected) {
        echo "ERROR - SUBTEST $test: expected [" . implode(",", $expected) . "], got [" . implode(",", $results) . "] ";
        return false;
    }
}

// Tear down
foreach ($resources_703 as $resource) {
    delete_resource($resource);
}
delete_node($title_node_703);
ps_query("DELETE FROM resource_type WHERE ref IN (?, ?)", ["i", $type_a_703, "i", $type_b_703]);
clear_restype_cache();
if ($saved_000703["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000703["resource_deletion_state"];
}
unset($test_cases, $rows_703, $resources_703, $r0, $r1, $r2, $r3, $results, $expected);

return true;
