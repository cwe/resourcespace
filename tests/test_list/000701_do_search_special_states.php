<?php

/**
 * do_search() special searches that select resources by workflow state or reference: !archivepending, !userpending,
 * !resource and !listall.
 *
 * !archivepending, !userpending and !listall return resources whatever archive states were passed to do_search();
 * !resource applies them like a standard search.
 */

command_line_only();

$saved_000701 = ["resource_deletion_state" => $resource_deletion_state ?? null];
unset($resource_deletion_state);

$fixtures_000701 = [
    "active"          => create_resource(1, 0),
    "pending_review"  => create_resource(1, -1),
    "pending_archive" => create_resource(1, 1),
    "archived"        => create_resource(1, 2),
];
$active_701   = $fixtures_000701["active"];
$archived_701 = $fixtures_000701["archived"];

$use_cases = [
    [
        "name"     => "!archivepending returns resources awaiting archive",
        "search"   => "!archivepending",
        "archive"  => "0",
        "expected" => [$fixtures_000701["pending_archive"]],
    ],
    [
        "name"     => "!userpending returns resources awaiting review",
        "search"   => "!userpending",
        "archive"  => "0",
        "expected" => [$fixtures_000701["pending_review"]],
    ],
    [
        "name"     => "!resource returns the one active resource",
        "search"   => "!resource" . $active_701,
        "archive"  => "0",
        "expected" => [$active_701],
    ],
    [
        "name"     => "!resource applies the archive state filter",
        "search"   => "!resource" . $archived_701,
        "archive"  => "0",
        "expected" => [],
    ],
    [
        "name"     => "!resource finds an archived resource when that state is searched",
        "search"   => "!resource" . $archived_701,
        "archive"  => "2",
        "expected" => [$archived_701],
    ],
    [
        "name"     => "!listall returns listed resources in every state",
        "search"   => "!listall" . $active_701 . ":" . $archived_701 . ":" . $fixtures_000701["pending_review"],
        "archive"  => "0",
        "expected" => [$active_701, $archived_701, $fixtures_000701["pending_review"]],
    ],
    [
        "name"     => "!listall with no valid reference returns nothing",
        "search"   => "!listallabc",
        "archive"  => "0",
        "expected" => [],
    ],
];

foreach ($use_cases as $use_case) {
    $results = do_search(
        $use_case["search"],
        "",
        "resourceid",
        $use_case["archive"],
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
    // Only compare the fixtures: other tests may leave resources in these states
    $results = is_array($results) ? array_column($results, "ref") : [];
    $results = array_values(array_intersect($results, $fixtures_000701));
    sort($results);
    sort($use_case["expected"]);
    if ($results != $use_case["expected"]) {
        echo "ERROR - SUBTEST " . $use_case["name"] . ": expected [" . implode(",", $use_case["expected"]) . "], got [" . implode(",", $results) . "] ";
        return false;
    }
}

// Tear down
foreach ($fixtures_000701 as $resource) {
    delete_resource($resource);
}
if ($saved_000701["resource_deletion_state"] !== null) {
    $resource_deletion_state = $saved_000701["resource_deletion_state"];
}
unset($use_cases, $fixtures_000701, $active_701, $archived_701, $results);

return true;
