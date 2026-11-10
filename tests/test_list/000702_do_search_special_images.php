<?php

/**
 * do_search() special searches driven by preview data held on the resource row: !images, !colourkey, !colour, !rgb
 * and !geo.
 *
 * No files are processed; the preview columns are written directly, as the other special search tests do for
 * checksums and file paths.
 */

command_line_only();

$saved_000702 = ["resource_deletion_state" => $resource_deletion_state ?? null, "userpermissions" => $userpermissions];
unset($resource_deletion_state);

$red_702     = create_resource(1, 0);
$blue_702    = create_resource(1, 0);
$noimage_702 = create_resource(1, 0);
// Three resources sharing a colour key prefix that only the base filter tells apart: one active and open, one
// archived and one confidential. Each matches the first of the two !colour patterns.
$open_702         = create_resource(1, 0);
$archived_702     = create_resource(1, 2);
$confidential_702 = create_resource(1, 0);
// Archived, and matching the second !colour pattern (the value at the second character)
$archived_second_702 = create_resource(1, 2);
$fixtures_000702 = [$red_702, $blue_702, $noimage_702, $open_702, $archived_702, $confidential_702, $archived_second_702];

ps_query("UPDATE resource SET colour_key = 'QRAB3' WHERE ref = ?", ["i", $open_702]);
ps_query("UPDATE resource SET colour_key = 'QRCD4' WHERE ref = ?", ["i", $archived_702]);
ps_query("UPDATE resource SET colour_key = 'QREF5', access = 2 WHERE ref = ?", ["i", $confidential_702]);
ps_query("UPDATE resource SET colour_key = 'XQRG6' WHERE ref = ?", ["i", $archived_second_702]);

ps_query(
    "UPDATE resource SET has_image = 1, colour_key = 'KLMN1', image_red = 255, image_green = 0, image_blue = 0, geo_lat = 51.5, geo_long = -0.5 WHERE ref = ?",
    ["i", $red_702]
);
ps_query(
    "UPDATE resource SET has_image = 1, colour_key = 'KLOP2', image_red = 0, image_green = 0, image_blue = 255, geo_lat = 48.8, geo_long = 2.3 WHERE ref = ?",
    ["i", $blue_702]
);
// Inside the geographic box searched below, but without a preview
ps_query(
    "UPDATE resource SET has_image = 0, colour_key = 'ZZZZ9', geo_lat = 51.6, geo_long = -0.4 WHERE ref = ?",
    ["i", $noimage_702]
);

$use_cases = [
    [
        "name"     => "!images returns resources with a preview",
        "search"   => "!images",
        "expected" => [$red_702, $blue_702],
    ],
    [
        "name"     => "!colourkey matches the first four characters of the colour key",
        "search"   => "!colourkeyKLMN",
        "expected" => [$red_702],
    ],
    [
        "name"     => "!colour matches colour keys starting with the value",
        "search"   => "!colourKL",
        "expected" => [$red_702, $blue_702],
    ],
    [
        "name"     => "!colour also matches colour keys with the value at the second character",
        "search"   => "!colourLM",
        "expected" => [$red_702],
    ],
    [
        "name"     => "!colour leaves out an archived resource, whichever pattern its colour key matches",
        "search"   => "!colourQR",
        "expected" => [$open_702, $confidential_702],
    ],
    [
        "name"        => "!colour leaves out a confidential resource for a user without the v permission",
        "search"      => "!colourQR",
        "permissions" => ["s", "g", "f*"],
        "expected"    => [$open_702],
    ],
    [
        "name"     => "!rgb orders resources with a preview by colour distance",
        "search"   => "!rgb:250,10,10",
        "expected" => [$red_702, $blue_702],
        "ordered"  => true,
    ],
    [
        "name"     => "!geo returns resources inside the bounding box",
        // Bottom-left 51,-1 to top-right 52,0: "m" encodes a minus sign and "p" a decimal point
        "search"   => "!geo51bm1t52b0",
        "expected" => [$red_702, $noimage_702],
    ],
];

foreach ($use_cases as $use_case) {
    $userpermissions = $use_case["permissions"] ?? $saved_000702["userpermissions"];
    $results = do_search(
        $use_case["search"],
        "",
        "resourceid",
        "0",
        -1,
        "asc",
        false,
        DEPRECATED_STARSEARCH,
        false,
        false,
        "",
        false,
        false
    );
    $userpermissions = $saved_000702["userpermissions"];
    // Only compare the fixtures: other tests may leave resources with previews
    $results = is_array($results) ? array_column($results, "ref") : [];
    $results = array_values(array_intersect($results, $fixtures_000702));
    if (empty($use_case["ordered"])) {
        sort($results);
        sort($use_case["expected"]);
    }
    if ($results != $use_case["expected"]) {
        echo "ERROR - SUBTEST " . $use_case["name"] . ": expected [" . implode(",", $use_case["expected"]) . "], got [" . implode(",", $results) . "] ";
        return false;
    }
}

// Tear down
foreach ($fixtures_000702 as $resource) {
    delete_resource($resource);
}
if ($saved_000702["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000702["resource_deletion_state"];
}
unset($use_cases, $fixtures_000702, $red_702, $blue_702, $noimage_702, $results);
unset($open_702, $archived_702, $confidential_702, $archived_second_702);

return true;
