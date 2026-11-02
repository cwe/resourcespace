<?php

/**
 * do_search() keyword field restrictions: fields excluded by the excludefieldsfromkeywordsearch hook and fields
 * hidden from the user must both be applied to every keyword union, with matching placeholders and parameters.
 *
 * Regression: the wildcard union previously overwrote the exclusion clause instead of appending to it while
 * still merging both sets of parameters, producing a statement that could not be prepared.
 */

command_line_only();

$saved_000706 = [
    "userpermissions"         => $userpermissions,
    "hidden_fields_cache"     => $hidden_fields_cache ?? null,
    "plugins"                 => $plugins,
    "hook_cache"              => $hook_cache ?? null,
    "wildcard_always_applied" => $wildcard_always_applied,
];

$wildcard_always_applied = false;

$field_706        = create_resource_type_field("Test 706 text", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "test706text", true);
$field_706_hidden = create_resource_type_field("Test 706 hidden", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "test706hidden", true);
$GLOBALS["test_000706_excluded_field"] = create_resource_type_field("Test 706 excluded", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "test706excluded", true);

$resource_706 = create_resource(1, 0);
update_field($resource_706, $field_706, "alpha beta");

/**
 * Test plugin hook: exclude one field from keyword searches. Inert once this test has torn down, as later tests may
 * enable the testframework plugin too.
 *
 * @return array|null
 */
function HookTestframeworkAllExcludefieldsfromkeywordsearch()
{
    return isset($GLOBALS["test_000706_excluded_field"]) ? [$GLOBALS["test_000706_excluded_field"]] : null;
}

$plugins[] = "testframework";
unset($GLOBALS["hook_cache"]);

$userpermissions = ["s", "f*", "f-" . $field_706_hidden];
$hidden_fields_cache = null;

$cases_000706 = [
    "wildcard keyword"       => "alp*",
    "short wildcard keyword" => "a*",   // too short for the stop word pattern: no stop word lookup must run
    "standard keyword"       => "alpha",
    "quoted phrase"          => "\"alpha beta\"",
];

$errors_000706 = [];
foreach ($cases_000706 as $name => $search) {
    $sql = do_search($search, '', 'relevance', '0', -1, 'desc', false, 0, false, false, '', false, false, false, false, true);
    if (!($sql instanceof PreparedStatementQuery)) {
        $errors_000706[] = "$name: expected a PreparedStatementQuery, got " . gettype($sql);
        continue;
    }
    $placeholders = substr_count($sql->sql, "?");
    if ($placeholders * 2 !== count($sql->parameters)) {
        $errors_000706[] = "$name: $placeholders placeholders but " . count($sql->parameters) . " parameter entries";
    }
    foreach (["excluded" => $GLOBALS["test_000706_excluded_field"], "hidden" => $field_706_hidden] as $label => $field) {
        if (!in_array($field, $sql->parameters)) {
            $errors_000706[] = "$name: $label field is not bound in the query";
        }
    }
}

// Tear down
delete_resource($resource_706);
delete_resource_type_field($field_706);
delete_resource_type_field($field_706_hidden);
delete_resource_type_field($GLOBALS["test_000706_excluded_field"]);
$userpermissions = $saved_000706["userpermissions"];
$hidden_fields_cache = $saved_000706["hidden_fields_cache"];
$plugins = $saved_000706["plugins"];
$wildcard_always_applied = $saved_000706["wildcard_always_applied"];
unset($GLOBALS["hook_cache"], $GLOBALS["test_000706_excluded_field"]);
if ($saved_000706["hook_cache"] !== null) {
    $hook_cache = $saved_000706["hook_cache"];
}

if (count($errors_000706) > 0) {
    echo implode("; ", $errors_000706) . " ";
    return false;
}
return true;
