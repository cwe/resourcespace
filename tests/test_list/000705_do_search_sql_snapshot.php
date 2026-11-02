<?php

command_line_only();

/*
 * do_search() SQL snapshot test.
 *
 * Runs a fixed corpus of searches with $returnsql = true for an administrator and for a restricted user,
 * normalises the generated SQL and parameters (fixture IDs become named tokens, whitespace is collapsed),
 * and compares the result with tests/test_data/do_search_sql_snapshot.json.
 *
 * The snapshot is a regression guard for refactoring do_search(): any change to the generated query text
 * or bound parameters fails this test. To accept a deliberate change, regenerate the snapshot with:
 *
 *     RS_TEST_UPDATE_SNAPSHOTS=1 php tests/test.php 705
 *
 * and review the resulting diff in version control.
 */

$snapshot_file = __DIR__ . "/../test_data/do_search_sql_snapshot.json";
$update_snapshot = getenv("RS_TEST_UPDATE_SNAPSHOTS") !== false && getenv("RS_TEST_UPDATE_SNAPSHOTS") !== "";

// ---------------------------------------------------------------------------------------------------------------------
// Save state that the corpus changes
// ---------------------------------------------------------------------------------------------------------------------
$saved_000705 = [
    "userpermissions"                    => $userpermissions,
    "usersearchfilter"                   => $usersearchfilter,
    "usereditfilter"                     => $usereditfilter,
    "userderestrictfilter"               => $userderestrictfilter,
    "stemming"                           => $stemming,
    "wildcard_always_applied"            => $wildcard_always_applied,
    "index_contributed_by"               => $index_contributed_by,
    "config_search_for_number"           => $config_search_for_number,
    "category_tree_search_use_and_logic" => $category_tree_search_use_and_logic,
    "hidden_fields_cache"                => $hidden_fields_cache ?? null,
    // Resource table join configuration: earlier tests add fields to these, which changes the SELECT list
    "sort_fields"                        => $sort_fields,
    "thumbs_display_fields"              => $thumbs_display_fields,
    "list_display_fields"                => $list_display_fields,
    "data_joins"                         => $data_joins,
    "config_sheetlist_fields"            => $config_sheetlist_fields,
    "config_sheetthumb_fields"           => $config_sheetthumb_fields,
    "config_sheetsingle_fields"          => $config_sheetsingle_fields,
    "metadata_template_title_field"      => $metadata_template_title_field ?? null,
    "related_pushed_order_by"            => $related_pushed_order_by ?? null,
];

// Pin the join configuration so the snapshot does not depend on which tests ran earlier
$sort_fields = [];
$thumbs_display_fields = [3];
$list_display_fields = [];
$data_joins = [];
$config_sheetlist_fields = [];
$config_sheetthumb_fields = [];
$config_sheetsingle_fields = [];
$metadata_template_title_field = "";
$related_pushed_order_by = null;

// Pin indexing configuration before creating fixtures: earlier tests leave e.g. $stemming changed, which would alter
// the keywords created by update_field() below
$stemming = false;
$wildcard_always_applied = false;
$index_contributed_by = false;
$config_search_for_number = false;
$category_tree_search_use_and_logic = false;

// ---------------------------------------------------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------------------------------------------------
$snaptext = create_resource_type_field("Snapshot text", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "snaptext", true);
$snaplist = create_resource_type_field("Snapshot list", 0, FIELD_TYPE_CHECK_BOX_LIST, "snaplist", true);
$snapdate = create_resource_type_field("Snapshot date", 0, FIELD_TYPE_DATE, "snapdate", true);
// Short name with a special character: an !empty search naming it must not be treated as a phrase
$snapunder = create_resource_type_field("Snapshot underscore", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "snap_under", true);
// Non-global field linked to resource type 1 only
$snaptyped = create_resource_type_field("Snapshot typed", 1, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "snaptyped", true);
// Two letter resource type name: with $wildcard_always_applied the keyword is too short for a full text search
$snaptype_tv = create_resource_type("tv");

$node_red  = set_node(null, $snaplist, "Red", "", 10);
$node_blue = set_node(null, $snaplist, "Blue", "", 20);

$res_a = create_resource(1, 0);
update_field($res_a, $snaptext, "alpha beta gamma running");
update_field($res_a, $view_title_field, "Snapshot title alpha");
update_field($res_a, $snapdate, "2024-05-17");
add_resource_nodes($res_a, [$node_red]);

$res_b = create_resource(2, 0);
update_field($res_b, $snaptext, "beta delta");
add_resource_nodes($res_b, [$node_blue]);

$snapfilter = save_filter(0, "Snapshot filter", RS_FILTER_ALL);
save_filter_rule("new", $snapfilter, [[RS_FILTER_NODE_IN, [$node_red]]]);

// ---------------------------------------------------------------------------------------------------------------------
// Corpus
// ---------------------------------------------------------------------------------------------------------------------
$admin_permissions = $saved_000705["userpermissions"];
$restricted_permissions = ["s", "f*", "f-" . $snaptext, "X2", "e0"];
$featured_only_permissions = array_merge($restricted_permissions, ["J"]);

$default_args = [
    "restypes"               => "",
    "order_by"               => "relevance",
    "archive"                => "0",
    "fetchrows"              => -1,
    "sort"                   => "desc",
    "access_override"        => false,
    "starsearch"             => DEPRECATED_STARSEARCH,
    "ignore_filters"         => false,
    "return_disk_usage"      => false,
    "recent_search_daylimit" => "",
    "go"                     => false,
    "stats_logging"          => false,
    "return_refs_only"       => false,
    "editable_only"          => false,
    "returnsql"              => true,
    "access"                 => null,
    "smartsearch"            => false,
];

$cases = [
    // --- plain keyword forms -----------------------------------------------------------------------------------------
    ["name" => "empty search",                "search" => ""],
    ["name" => "single keyword",              "search" => "alpha"],
    ["name" => "two keywords",                "search" => "alpha beta"],
    ["name" => "comma separated keywords",    "search" => "alpha, beta"],
    ["name" => "omit keyword",                "search" => "beta -alpha"],
    ["name" => "or keywords",                 "search" => "alpha;delta"],
    ["name" => "unknown keyword suggests",    "search" => "alpha zzqxv"],
    ["name" => "wildcard only",               "search" => "*"],
    ["name" => "trailing wildcard",           "search" => "alp*"],
    ["name" => "leading wildcard",            "search" => "*pha"],
    ["name" => "wildcard with punctuation",   "search" => "al-p*"],
    ["name" => "quoted phrase",               "search" => "\"alpha beta\""],
    ["name" => "quoted phrase omitted",       "search" => "-\"alpha beta\""],
    ["name" => "quoted phrase with noadd",    "search" => "\"alpha and beta\""],
    ["name" => "full text",                   "search" => "\"" . FULLTEXT_SEARCH_PREFIX . "alpha beta\""],
    ["name" => "integer not a resource",      "search" => "999999999"],
    ["name" => "integer matching resource",   "search" => (string) $res_a],
    ["name" => "resource type name",          "search" => "photo"],
    // --- field specific ---------------------------------------------------------------------------------------------
    ["name" => "field specific keyword",      "search" => "snaptext:alpha"],
    ["name" => "field specific quoted",       "search" => "snaptext:\"alpha beta\""],
    ["name" => "field specific or",           "search" => "snaptext:alpha;delta"],
    ["name" => "colon not a field",           "search" => "alpha:beta"],
    ["name" => "fixed list legacy value",     "search" => "snaplist:Red"],
    ["name" => "fixed list legacy or",        "search" => "snaplist:Red;Blue"],
    ["name" => "empty field by ref",          "search" => "!empty" . $snaptext],
    ["name" => "empty field by name",         "search" => "!emptysnaptext"],
    ["name" => "empty field with restypes",   "search" => "!empty" . $snaptext, "args" => ["restypes" => "1,2"]],
    ["name" => "empty field name underscore", "search" => "!emptysnap_under"],
    ["name" => "empty typed field in type",   "search" => "!empty" . $snaptyped, "args" => ["restypes" => "1"]],
    ["name" => "empty typed field no types",  "search" => "!empty" . $snaptyped, "args" => ["restypes" => "2"]],
    ["name" => "numrange",                    "search" => "snaptext:numrange1|100"],
    ["name" => "numrange single",             "search" => "snaptext:numrange5"],
    // --- dates --------------------------------------------------------------------------------------------------------
    ["name" => "date field year",             "search" => "snapdate:2024"],
    ["name" => "date field month",            "search" => "snapdate:2024|05"],
    ["name" => "date range",                  "search" => "snapdate:rangestart2024-01-01end2024-12-31"],
    ["name" => "date range start only",       "search" => "snapdate:rangestart2024-01-01"],
    ["name" => "basic date parts",            "search" => "basicyear:2024 basicmonth:05"],
    // --- nodes --------------------------------------------------------------------------------------------------------
    ["name" => "node",                        "search" => NODE_TOKEN_PREFIX . $node_red],
    ["name" => "node or",                     "search" => NODE_TOKEN_PREFIX . $node_red . NODE_TOKEN_PREFIX . $node_blue],
    ["name" => "node not",                    "search" => "alpha " . NODE_TOKEN_PREFIX . NODE_TOKEN_NOT . $node_blue],
    ["name" => "node and keyword",            "search" => "alpha, " . NODE_TOKEN_PREFIX . $node_red],
    ["name" => "node and logic",              "search" => NODE_TOKEN_PREFIX . $node_red . NODE_TOKEN_PREFIX . $node_blue,
                                               "config" => ["category_tree_search_use_and_logic" => true]],
    // --- options ------------------------------------------------------------------------------------------------------
    ["name" => "order by date asc",           "search" => "alpha", "args" => ["order_by" => "date", "sort" => "asc"]],
    ["name" => "order by title",              "search" => "alpha", "args" => ["order_by" => "title"]],
    ["name" => "order by field",              "search" => "alpha", "args" => ["order_by" => "field" . $view_title_field]],
    ["name" => "invalid sort",                "search" => "alpha", "args" => ["sort" => "sideways"]],
    ["name" => "restypes",                    "search" => "alpha", "args" => ["restypes" => "1,2"]],
    ["name" => "archive states",              "search" => "alpha", "args" => ["archive" => "-1,0,1"]],
    ["name" => "recent days",                 "search" => "alpha", "args" => ["recent_search_daylimit" => "30"]],
    ["name" => "disk usage",                  "search" => "alpha", "args" => ["return_disk_usage" => true]],
    ["name" => "refs only",                   "search" => "alpha", "args" => ["return_refs_only" => true]],
    ["name" => "editable only",               "search" => "alpha", "args" => ["editable_only" => true]],
    ["name" => "access restricted",           "search" => "alpha", "args" => ["access" => 1]],
    ["name" => "access override",             "search" => "alpha", "args" => ["access_override" => true]],
    ["name" => "ignore filters",              "search" => "snapdate:2024", "args" => ["ignore_filters" => true]],
    ["name" => "chunked fetchrows",           "search" => "alpha", "args" => ["fetchrows" => [10, 20]]],
    // --- config -------------------------------------------------------------------------------------------------------
    ["name" => "stemming",                    "search" => "running", "config" => ["stemming" => true]],
    ["name" => "wildcard always applied",     "search" => "alpha", "config" => ["wildcard_always_applied" => true]],
    ["name" => "wildcard always short type",  "search" => "tv",    "config" => ["wildcard_always_applied" => true]],
    ["name" => "contributor indexed",         "search" => "admin", "config" => ["index_contributed_by" => true]],
    ["name" => "search for number",           "search" => (string) $res_a, "config" => ["config_search_for_number" => true]],
    ["name" => "user search filter",          "search" => "alpha", "config" => ["usersearchfilter" => $snapfilter]],
    ["name" => "user edit filter",            "search" => "alpha", "args" => ["editable_only" => true],
                                               "config" => ["usereditfilter" => $snapfilter]],
    // --- special searches ---------------------------------------------------------------------------------------------
    ["name" => "special last",                "search" => "!last10"],
    ["name" => "special list",                "search" => "!list" . $res_a . "," . $res_b],
    ["name" => "special list with node",      "search" => "!list" . $res_a . "," . NODE_TOKEN_PREFIX . $node_red],
    ["name" => "special unused",              "search" => "!unused"],
    ["name" => "special nopreview",           "search" => "!nopreview"],
    ["name" => "special hasdata",             "search" => "!hasdata" . $snaptext],
    ["name" => "special contributions",       "search" => "!contributions" . $userref],
    // --- restricted user ----------------------------------------------------------------------------------------------
    ["name" => "restricted empty search",     "search" => "",               "permissions" => $restricted_permissions],
    ["name" => "restricted keyword",          "search" => "alpha",          "permissions" => $restricted_permissions],
    ["name" => "restricted wildcard",         "search" => "alp*",           "permissions" => $restricted_permissions],
    ["name" => "restricted quoted",           "search" => "\"alpha beta\"", "permissions" => $restricted_permissions],
    ["name" => "restricted hidden field",     "search" => "snaptext:alpha", "permissions" => $restricted_permissions],
    ["name" => "restricted empty hidden",     "search" => "!empty" . $snaptext, "permissions" => $restricted_permissions],
    ["name" => "restricted node",             "search" => NODE_TOKEN_PREFIX . $node_red, "permissions" => $restricted_permissions],
    ["name" => "restricted editable only",    "search" => "alpha",          "permissions" => $restricted_permissions,
                                               "args" => ["editable_only" => true]],
    ["name" => "restricted access override",  "search" => "alpha",          "permissions" => $restricted_permissions,
                                               "args" => ["access_override" => true]],
    ["name" => "restricted derestrict filter", "search" => "alpha",         "permissions" => $restricted_permissions,
                                               "config" => ["userderestrictfilter" => $snapfilter]],
    ["name" => "restricted search filter",    "search" => "alpha",          "permissions" => $restricted_permissions,
                                               "config" => ["usersearchfilter" => $snapfilter]],
    ["name" => "featured only keyword",       "search" => "alpha",          "permissions" => $featured_only_permissions],
    ["name" => "featured only special last",  "search" => "!last10",        "permissions" => $featured_only_permissions],
];

// ---------------------------------------------------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------------------------------------------------
$maps_000705 = [
    "resource_type_field" => array_column(ps_query("SELECT ref, name FROM resource_type_field"), "name", "ref"),
    "node"                => array_column(ps_query("SELECT ref, name FROM node WHERE resource_type_field = ?", ["i", $snaplist]), "name", "ref"),
    "keyword"             => [],   // Filled after the corpus has run as searches can create keywords
    "resource"            => [$res_a => "a", $res_b => "b"],
    "user"                => [$userref => "USER"],
    "usergroup"           => [$usergroup => "GROUP"],
];
$maps_000705["created_by"] = $maps_000705["user"];

/**
 * Map a bound value to a named token when it is a fixture ID.
 *
 * @param string $kind   Map name (resource_type_field, node, keyword, resource, user, usergroup, created_by)
 * @param mixed  $value  Bound value
 * @param array  $maps   Fixture ID maps
 *
 * @return mixed
 */
function test_000705_token(string $kind, $value, array $maps)
{
    $prefix = ["resource_type_field" => "F", "node" => "N", "keyword" => "K", "resource" => "R", "user" => "", "usergroup" => "", "created_by" => ""];
    if (isset($maps[$kind]) && is_numeric($value) && isset($maps[$kind][(int) $value])) {
        $name = $maps[$kind][(int) $value];
        return "{" . ($prefix[$kind] !== "" ? $prefix[$kind] . ":" : "") . $name . "}";
    }
    return $value;
}

/**
 * Normalise a do_search() result into a comparable snapshot entry.
 *
 * Whitespace is collapsed, field columns and fixture IDs are replaced with named tokens so that the snapshot is stable
 * across test databases. Bound parameters are classified by the SQL immediately preceding their placeholder.
 *
 * @param mixed $result  Return value of do_search()
 * @param array $maps    Fixture ID maps
 *
 * @return array
 */
function test_000705_normalise($result, array $maps): array
{
    if (!($result instanceof PreparedStatementQuery)) {
        return ["result" => $result];
    }

    $sql = preg_replace('/\s+/', ' ', trim($result->sql));
    $sql = preg_replace('/\s*,\s*/', ', ', $sql); // Whitespace around commas is not significant
    $sql = preg_replace_callback('/\bfield(\d+)\b/', function ($m) use ($maps) {
        return "field" . test_000705_token("resource_type_field", $m[1], $maps);
    }, $sql);
    $sql = preg_replace_callback("/\(r\.ref='(\d+)'\)/", function ($m) use ($maps) {
        return "(r.ref='" . test_000705_token("resource", $m[1], $maps) . "')";
    }, $sql);
    $sql = preg_replace_callback('/SELECT (\d+) AS resource/', function ($m) use ($maps) {
        return "SELECT " . test_000705_token("resource", $m[1], $maps) . " AS resource";
    }, $sql);

    $chunks = explode("?", $sql);
    $placeholders = count($chunks) - 1;
    $params = $result->parameters;
    $normalised_params = [];
    $before = "";
    for ($i = 0; $i < $placeholders; $i++) {
        $before .= $chunks[$i];
        $type = $params[$i * 2] ?? "MISSING";
        $value = $params[$i * 2 + 1] ?? "MISSING";
        $tail = substr($before, -160);
        $pattern = '/(resource_type_field|usergroup|user|node|keyword|created_by|ref)`?\s*(=|<>|IN|NOT IN)\s*\(?\s*(\?\s*,\s*)*$/i';
        if (preg_match($pattern, $tail, $m)) {
            $kind = strtolower($m[1]) === "ref" ? "resource" : strtolower($m[1]);
            $value = test_000705_token($kind, $value, $maps);
        }
        $normalised_params[] = $type . ":" . (is_scalar($value) || is_null($value) ? var_export($value, true) : json_encode($value));
        $before .= "?";
    }

    $entry = ["sql" => $sql, "params" => $normalised_params];
    if (count($params) !== $placeholders * 2) {
        $entry["parameter_mismatch"] = "placeholders=" . $placeholders . " parameters=" . (count($params) / 2);
    }
    return $entry;
}

// ---------------------------------------------------------------------------------------------------------------------
// Run the corpus
// ---------------------------------------------------------------------------------------------------------------------
$raw_results = [];
foreach ($cases as $case) {
    // Reset to a known state for every case
    $userpermissions = $case["permissions"] ?? $admin_permissions;
    $hidden_fields_cache = null;
    $usersearchfilter = "";
    $usereditfilter = "";
    $userderestrictfilter = "";
    $stemming = false;
    $wildcard_always_applied = false;
    $index_contributed_by = false;
    $config_search_for_number = false;
    $category_tree_search_use_and_logic = false;
    foreach ($case["config"] ?? [] as $config_name => $config_value) {
        $$config_name = $config_value;
    }

    $args = array_merge($default_args, $case["args"] ?? []);
    $raw_results[$case["name"]] = call_user_func_array("do_search", array_merge([$case["search"]], array_values($args)));
}

// Restore state
foreach ($saved_000705 as $saved_name => $saved_value) {
    $$saved_name = $saved_value;
}

$maps_000705["keyword"] = array_column(ps_query("SELECT ref, keyword FROM keyword"), "keyword", "ref");
// A search for a resource ID also creates a keyword whose text is that ID; name it after the resource so the token is stable
foreach ($maps_000705["keyword"] as $keyword_ref => $keyword_text) {
    if (isset($maps_000705["resource"][(int) $keyword_text]) && (string) (int) $keyword_text === (string) $keyword_text) {
        $maps_000705["keyword"][$keyword_ref] = "{R:" . $maps_000705["resource"][(int) $keyword_text] . "}";
    }
}

$actual = [];
foreach ($raw_results as $case_name => $raw_result) {
    $actual[$case_name] = test_000705_normalise($raw_result, $maps_000705);
}

// Tear down the fixtures now that their IDs have been mapped
delete_resource($res_a);
delete_resource($res_b);
delete_filter($snapfilter);
delete_resource_type_field($snaptext);
delete_resource_type_field($snaplist);
delete_resource_type_field($snapdate);
delete_resource_type_field($snapunder);
delete_resource_type_field($snaptyped);
ps_query("DELETE FROM resource_type WHERE ref = ?", ["i", $snaptype_tv]);
clear_query_cache("schema");
clear_restype_cache();

// ---------------------------------------------------------------------------------------------------------------------
// Compare or update
// ---------------------------------------------------------------------------------------------------------------------
if ($update_snapshot) {
    if (!is_dir(dirname($snapshot_file))) {
        mkdir(dirname($snapshot_file), 0755, true);
    }
    file_put_contents($snapshot_file, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    echo "snapshot updated (" . count($actual) . " cases) ";
    return true;
}

if (!file_exists($snapshot_file)) {
    echo "ERROR - snapshot file missing; run with RS_TEST_UPDATE_SNAPSHOTS=1 to create it\n";
    return false;
}

$expected = json_decode(file_get_contents($snapshot_file), true);
if (!is_array($expected)) {
    echo "ERROR - snapshot file is not valid JSON\n";
    return false;
}

$failures = [];
foreach ($actual as $case_name => $actual_entry) {
    if (!isset($expected[$case_name])) {
        $failures[] = "'" . $case_name . "': no snapshot entry (regenerate the snapshot)";
    } elseif ($expected[$case_name] !== $actual_entry) {
        $expected_text = json_encode($expected[$case_name], JSON_UNESCAPED_SLASHES);
        $actual_text = json_encode($actual_entry, JSON_UNESCAPED_SLASHES);
        $diff_at = strspn($expected_text ^ $actual_text, "\0");
        $window_start = max(0, $diff_at - 80);
        $failures[] = "'" . $case_name . "' differs at offset " . $diff_at
            . "\n  expected: ..." . substr($expected_text, $window_start, 240)
            . "\n  actual:   ..." . substr($actual_text, $window_start, 240);
    }
}
foreach (array_diff(array_keys($expected), array_keys($actual)) as $stale) {
    $failures[] = "'" . $stale . "': in snapshot but not in corpus (regenerate the snapshot)";
}

if (count($failures) > 0) {
    echo "ERROR - do_search() SQL differs from snapshot in " . count($failures) . " case(s):\n" . implode("\n", $failures) . "\n";
    return false;
}

return true;
