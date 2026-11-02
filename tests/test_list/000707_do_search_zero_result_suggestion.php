<?php

/**
 * do_search() with keywords that all exist but never occur together: the suggested search drops the least used
 * keyword.
 *
 * Regression: the keyword lookup bound the keyword text as an integer, so the least used keyword of the whole
 * keyword table was chosen and the suggestion usually equalled the original search.
 */

command_line_only();

$saved_000707 = [
    "stemming"                => $stemming,
    "wildcard_always_applied" => $wildcard_always_applied,
    "config_search_for_number" => $config_search_for_number,
];
$stemming = false;
$wildcard_always_applied = false;
$config_search_for_number = false;

$field_707 = create_resource_type_field("Test 707 text", 0, FIELD_TYPE_TEXT_BOX_SINGLE_LINE, "test707text", true);

$resource_707a = create_resource(1, 0);
update_field($resource_707a, $field_707, "snapzeta");
$resource_707b = create_resource(1, 0);
update_field($resource_707b, $field_707, "snapomega");

// Make snapomega the least used keyword
ps_query("UPDATE keyword SET hit_count = 5 WHERE keyword = ?", ["s", "snapzeta"]);
ps_query("UPDATE keyword SET hit_count = 0 WHERE keyword = ?", ["s", "snapomega"]);

$result = do_search("snapzeta snapomega", '', 'relevance', '0', -1, 'desc', false, 0, false, false, '', false, false);

// Tear down
delete_resource($resource_707a);
delete_resource($resource_707b);
delete_resource_type_field($field_707);
$stemming = $saved_000707["stemming"];
$wildcard_always_applied = $saved_000707["wildcard_always_applied"];
$config_search_for_number = $saved_000707["config_search_for_number"];

if ($result !== "snapzeta") {
    echo "expected suggestion 'snapzeta', got " . json_encode($result) . " ";
    return false;
}
return true;
