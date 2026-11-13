<?php

/**
 * do_search() "!collection": the list of collections a user may search without an external share key.
 *
 * search_special_collection_listed() tests one collection against the rules of that list without building it. The
 * rules belong to the functions that return the lists: get_user_collections(), search_public_collections(),
 * get_requests(), get_research_requests(), get_session_collections() and get_all_featured_collections().
 *
 * For users with different permissions and settings, and for every collection of a fixture, this test checks that
 * the answer is the expected one and that it is the answer the list functions give, see
 * test_000718_listed_by_lists(). A change to one of the list functions that the single collection test does not
 * follow fails here.
 */

command_line_only();

$saved_000718 = [
    "userpermissions"                   => $userpermissions,
    "usergroup"                         => $usergroup,
    "anonymous_login"                   => $anonymous_login ?? null,
    "anonymous_user_session_collection" => $anonymous_user_session_collection,
    "rs_session"                        => $rs_session ?? null,
    "themes_in_my_collections"          => $themes_in_my_collections,
    "public_collections_confine_group"  => $public_collections_confine_group,
    "upload_share_active"               => $upload_share_active ?? null,
    "k"                                 => $k ?? null,
];
$k = "";

/**
 * The list as the search built it before it tested the one collection: every collection the list functions return
 * for the current user, then a membership test.
 *
 * @param int $collection Collection ID
 *
 * @return bool
 */
function test_000718_listed_by_lists(int $collection): bool
{
    global $userref, $USER_SELECTION_COLLECTION;

    if (upload_share_active() !== false) {
        $valid = get_session_collections(get_rs_session_id(), $userref);
    } else {
        $valid = array_merge(
            array_column(get_user_collections($userref, "", "name", "ASC", -1, false), "ref"),
            [$USER_SELECTION_COLLECTION],
            array_column(search_public_collections("", "name", "ASC", true, false, false), "ref")
        );
        if (checkperm("R")) {
            include_once __DIR__ . "/../../include/request_functions.php";
            $valid = array_merge(
                $valid,
                array_column(get_requests(), "collection"),
                ps_array("SELECT ref value FROM collection WHERE user = -2")
            );
        }
        if (checkperm("r")) {
            include_once __DIR__ . "/../../include/research_functions.php";
            $valid = array_merge($valid, array_column(get_research_requests(), "collection"));
        }
    }
    $valid[] = 0 - $userref;

    return in_array($collection, $valid)
        || (
            in_array($collection, array_column(get_all_featured_collections(), "ref"))
            && featured_collection_check_access_control($collection)
        );
}

/**
 * Create a collection for this test.
 *
 * @param int|null $owner   Owner, null for none
 * @param int      $type    Collection type
 * @param array    $options "session": session ID, "parent": parent featured collection, "resource": true to put a
 *                          resource in it
 *
 * @return int Collection ID
 */
function test_000718_collection(?int $owner, int $type, array $options = []): int
{
    ps_query(
        "INSERT INTO collection (name, `user`, created, `type`, public, session_id, parent)
         VALUES ('Test 718', ?, NOW(), ?, ?, ?, ?)",
        [
            "i", $owner,
            "i", $type,
            "i", (int) in_array($type, [COLLECTION_TYPE_PUBLIC, COLLECTION_TYPE_FEATURED]),
            "i", $options["session"] ?? null,
            "i", $options["parent"] ?? null,
        ]
    );
    $collection = sql_insert_id();
    if (!empty($options["resource"])) {
        ps_query("INSERT INTO collection_resource (collection, resource) VALUES (?, 900718)", ["i", $collection]);
    }
    return $collection;
}

// User groups around the user's own: a parent, a sibling and a child, and one that is unrelated
$groups_718 = [];
foreach (["parent" => 0, "own" => "parent", "sibling" => "parent", "child" => "own", "other" => 0] as $name => $parent) {
    ps_query(
        "INSERT INTO usergroup (name, parent) VALUES (?, ?)",
        ["s", "Test 718 " . $name, "i", is_string($parent) ? $groups_718[$parent] : $parent]
    );
    $groups_718[$name] = sql_insert_id();
}
$usergroup = $groups_718["own"];

// An owner in each group, and one that is not a user
$owners_718 = ["none" => 999718];
foreach ($groups_718 as $name => $group) {
    ps_query(
        "INSERT INTO user (username, usergroup, approved) VALUES (?, ?, 1)",
        ["s", "test_000718_" . $name, "i", $group]
    );
    $owners_718[$name] = sql_insert_id();
}
$other_718 = $owners_718["other"];
$session_718 = 71801;

$standard = COLLECTION_TYPE_STANDARD;
$in_session_718 = ["session" => $session_718];
$featured = COLLECTION_TYPE_FEATURED;
$public = COLLECTION_TYPE_PUBLIC;
$c718 = [
    "own"                       => test_000718_collection($userref, $standard),
    "own public"                => test_000718_collection($userref, $public),
    "own request"               => test_000718_collection($userref, COLLECTION_TYPE_REQUEST),
    "own upload"                => test_000718_collection($userref, COLLECTION_TYPE_UPLOAD),
    "own featured"              => test_000718_collection($userref, $featured, ["resource" => true]),
    "own featured, empty"       => test_000718_collection($userref, $featured),
    "own, session"              => test_000718_collection($userref, $standard, $in_session_718),
    "own, another session"      => test_000718_collection($userref, $standard, ["session" => $session_718 + 1]),
    "own upload, session"       => test_000718_collection($userref, COLLECTION_TYPE_UPLOAD, $in_session_718),
    "own share upload, session" => test_000718_collection($userref, COLLECTION_TYPE_SHARE_UPLOAD, $in_session_718),
    "another user's, session"   => test_000718_collection($other_718, $standard, $in_session_718),
    "private"                   => test_000718_collection($other_718, $standard),
    "shared with user"          => test_000718_collection($other_718, $standard),
    "shared with group"         => test_000718_collection($other_718, $standard),
    "shared with another group" => test_000718_collection($other_718, $standard),
    "shared upload"             => test_000718_collection($other_718, COLLECTION_TYPE_UPLOAD),
    "shared featured"           => test_000718_collection($other_718, $featured, ["resource" => true]),
    "shared, no owner"          => test_000718_collection(null, $standard),
    "public, own group"         => test_000718_collection($owners_718["own"], $public),
    "public, child group"       => test_000718_collection($owners_718["child"], $public),
    "public, parent group"      => test_000718_collection($owners_718["parent"], $public),
    "public, sibling group"     => test_000718_collection($owners_718["sibling"], $public),
    "public, other group"       => test_000718_collection($owners_718["other"], $public),
    "public, owner not a user"  => test_000718_collection($owners_718["none"], $public),
    "featured category"         => test_000718_collection($other_718, $featured),
    "externally requested"      => test_000718_collection(-2, $standard),
    "requested, unassigned"     => test_000718_collection($other_718, $standard),
    "requested, assigned to user"    => test_000718_collection($other_718, $standard),
    "requested, assigned to another" => test_000718_collection($other_718, $standard),
    "research"                  => test_000718_collection($other_718, $standard),
    "selection"                 => (int) $USER_SELECTION_COLLECTION,
    "upload review"             => 0 - $userref,
    "another user's upload review" => 0 - $other_718,
    "does not exist"            => 999718999,
];
$c718["featured"] = test_000718_collection(
    $other_718,
    $featured,
    ["resource" => true, "parent" => $c718["featured category"]]
);

foreach (["shared with user", "shared upload", "shared featured", "shared, no owner"] as $name) {
    ps_query(
        "INSERT INTO user_collection (user, collection, request_feedback) VALUES (?, ?, 0)",
        ["i", $userref, "i", $c718[$name]]
    );
}
foreach (["shared with group" => "own", "shared with another group" => "other"] as $name => $group) {
    ps_query(
        "INSERT INTO usergroup_collection (usergroup, collection, request_feedback) VALUES (?, ?, 0)",
        ["i", $groups_718[$group], "i", $c718[$name]]
    );
}
$requests_718 = [
    "requested, unassigned"          => null,
    "requested, assigned to user"    => $userref,
    "requested, assigned to another" => $other_718,
];
foreach ($requests_718 as $name => $assigned_to) {
    ps_query(
        "INSERT INTO request (user, collection, created, request_mode, status, assigned_to)
         VALUES (?, ?, NOW(), 1, 0, ?)",
        ["i", $other_718, "i", $c718[$name], "i", $assigned_to]
    );
}
ps_query(
    "INSERT INTO research_request (name, created, user, status, collection) VALUES ('Test 718', NOW(), ?, 0, ?)",
    ["i", $other_718, "i", $c718["research"]]
);
clear_query_cache("featured_collections");

// Listed for every user outside an upload share
$always_718 = [
    "own", "own public", "own request", "own, session", "own, another session",
    "shared with user", "shared with group",
    "public, own group", "public, child group", "public, parent group", "public, sibling group",
    "public, other group", "public, owner not a user",
    "selection", "upload review",
];
$featured_718 = ["own featured", "own featured, empty", "shared featured", "featured category", "featured"];
$requests_all_718 = array_merge(["externally requested"], array_keys($requests_718));

// Each case sets the permissions the user holds besides 's' and the globals named, and gives the listed collections
$cases_718 = [
    ["name" => "no further permission", "listed" => $always_718],
    ["name" => "R", "permissions" => ["R"], "listed" => array_merge($always_718, $requests_all_718)],
    ["name" => "R and Ra", "permissions" => ["R", "Ra"], "listed" => array_merge($always_718, $requests_all_718)],
    ["name" => "R and Rb", "permissions" => ["R", "Rb"],
        "listed" => array_merge($always_718, ["externally requested", "requested, assigned to user"])],
    ["name" => "R, Ra and Rb", "permissions" => ["R", "Ra", "Rb"],
        "listed" => array_merge($always_718, $requests_all_718)],
    ["name" => "Ra without R", "permissions" => ["Ra"], "listed" => $always_718],
    ["name" => "r", "permissions" => ["r"], "listed" => array_merge($always_718, ["research"])],
    ["name" => "j*", "permissions" => ["j*"], "listed" => array_merge($always_718, $featured_718)],
    ["name" => "j of the featured collection", "permissions" => ["j" . $c718["featured"]],
        "listed" => array_merge($always_718, ["featured"])],
    ["name" => "j of the featured category", "permissions" => ["j" . $c718["featured category"]],
        "listed" => array_merge($always_718, ["featured category", "featured"])],
    // The -j permission closes the category itself. A collection inside it stays open: j* answers first
    ["name" => "j* and -j of the featured category", "permissions" => ["j*", "-j" . $c718["featured category"]],
        "listed" => array_merge($always_718, ["own featured", "own featured, empty", "shared featured", "featured"])],
    // Featured collections in My Collections: those the user owns or was given, but not empty ones
    ["name" => "\$themes_in_my_collections", "globals" => ["themes_in_my_collections" => true],
        "listed" => array_merge($always_718, ["own featured", "shared featured"])],
    ["name" => "\$public_collections_confine_group", "globals" => ["public_collections_confine_group" => true],
        "listed" => array_diff($always_718, ["public, other group", "public, owner not a user"])],
    // The anonymous user owns only the collections of the session. A public one is listed as a public collection
    ["name" => "anonymous user with session collections",
        "globals" => [
            "anonymous_login" => $username,
            "anonymous_user_session_collection" => true,
            "rs_session" => $session_718,
        ],
        "listed" => array_diff($always_718, ["own", "own request", "own, another session"])],
    ["name" => "upload share",
        "globals" => ["upload_share_active" => $c718["own share upload, session"], "rs_session" => $session_718],
        "listed" => ["own, session", "own upload, session", "own share upload, session", "upload review"]],
    ["name" => "upload share and j*", "permissions" => ["j*"],
        "globals" => ["upload_share_active" => $c718["own share upload, session"], "rs_session" => $session_718],
        "listed" => array_merge(
            ["own, session", "own upload, session", "own share upload, session", "upload review"],
            $featured_718
        )],
    ["name" => "every permission and setting", "permissions" => ["R", "r", "j*"],
        "globals" => ["themes_in_my_collections" => true, "public_collections_confine_group" => true],
        "listed" => array_merge(
            array_diff($always_718, ["public, other group", "public, owner not a user"]),
            $requests_all_718,
            ["research"],
            $featured_718
        )],
];

$errors_000718 = [];
foreach ($cases_718 as $case) {
    $userpermissions = array_merge(["s"], $case["permissions"] ?? []);
    $themes_in_my_collections = $case["globals"]["themes_in_my_collections"] ?? false;
    $public_collections_confine_group = $case["globals"]["public_collections_confine_group"] ?? false;
    $anonymous_user_session_collection = $case["globals"]["anonymous_user_session_collection"] ?? false;
    $rs_session = $case["globals"]["rs_session"] ?? "";
    unset($anonymous_login, $upload_share_active);
    if (isset($case["globals"]["anonymous_login"])) {
        $anonymous_login = $case["globals"]["anonymous_login"];
    }
    if (isset($case["globals"]["upload_share_active"])) {
        $upload_share_active = $case["globals"]["upload_share_active"];
    }

    $listed = [];
    foreach ($c718 as $name => $collection) {
        $by_lists = test_000718_listed_by_lists($collection);
        if (search_special_collection_listed($collection) !== $by_lists) {
            $errors_000718[] = $case["name"] . ", " . $name . ": the list functions say "
                . ($by_lists ? "listed" : "not listed");
        }
        if ($by_lists) {
            $listed[] = $name;
        }
    }
    $expected = array_values($case["listed"]);
    sort($expected);
    sort($listed);
    if ($listed !== $expected) {
        $errors_000718[] = $case["name"] . ": expected [" . implode("; ", $expected) . "], the list functions give ["
            . implode("; ", $listed) . "]";
    }
}

// Tear down
ps_query("DELETE FROM research_request WHERE name = 'Test 718'");
ps_query("DELETE FROM request WHERE user = ?", ["i", $other_718]);
ps_query("DELETE uc FROM user_collection uc JOIN collection c ON c.ref = uc.collection WHERE c.name = 'Test 718'");
ps_query("DELETE gc FROM usergroup_collection gc JOIN collection c ON c.ref = gc.collection WHERE c.name = 'Test 718'");
ps_query("DELETE cr FROM collection_resource cr JOIN collection c ON c.ref = cr.collection WHERE c.name = 'Test 718'");
ps_query("DELETE FROM collection WHERE name = 'Test 718'");
ps_query("DELETE FROM user WHERE username LIKE 'test\\_000718\\_%'");
ps_query("DELETE FROM usergroup WHERE name LIKE 'Test 718 %'");
clear_query_cache("featured_collections");
clear_query_cache("usergroup");
clear_query_cache("collection_access" . $userref);

$userpermissions = $saved_000718["userpermissions"];
$usergroup = $saved_000718["usergroup"];
$anonymous_user_session_collection = $saved_000718["anonymous_user_session_collection"];
$themes_in_my_collections = $saved_000718["themes_in_my_collections"];
$public_collections_confine_group = $saved_000718["public_collections_confine_group"];
unset($anonymous_login, $upload_share_active, $rs_session, $k);
foreach (["anonymous_login", "upload_share_active", "rs_session", "k"] as $name) {
    if ($saved_000718[$name] !== null) {
        $$name = $saved_000718[$name];
    }
}
unset($GLOBALS["get_collection_cache"]);
unset($cases_718, $c718, $groups_718, $owners_718, $requests_718, $always_718, $featured_718, $requests_all_718);
unset($in_session_718);

if (count($errors_000718) > 0) {
    echo "ERROR - " . implode("; ", $errors_000718) . " ";
    return false;
}
return true;
