<?php

/**
 * do_search() editable-only searches must exclude each configured additional archive state that the user cannot
 * edit, and only those.
 *
 * Regression: the loop over $additional_archive_states in search_filter() tested and blocked the counter left over
 * from the preceding loop (always 4) instead of the configured state.
 */

command_line_only();

$saved_000708 = [
    "userpermissions"           => $userpermissions,
    "additional_archive_states" => $additional_archive_states,
    "usereditfilter"            => $usereditfilter,
];
$usereditfilter = "";

/**
 * Return the values bound to the "archive NOT IN (...)" clause of an editable-only search.
 *
 * @param string $search Search string
 *
 * @return array|null Bound values, or null when the clause is absent
 */
function test_000708_blocked_states(string $search): ?array
{
    $query = do_search($search, "", "relevance", "0", -1, "desc", false, DEPRECATED_STARSEARCH, false, false, "", false, false, false, true, true);
    if (!($query instanceof PreparedStatementQuery)) {
        return null;
    }
    $clause_at = strpos($query->sql, "(archive NOT IN (?");
    if ($clause_at === false) {
        return null;
    }
    $open = $clause_at + strlen("(archive NOT IN (");
    $close = strpos($query->sql, ")", $open);
    $first = substr_count(substr($query->sql, 0, $open), "?");
    $count = substr_count(substr($query->sql, $open, $close - $open), "?");
    $values = [];
    for ($i = $first; $i < $first + $count; $i++) {
        $values[] = (int) $query->parameters[$i * 2 + 1];
    }
    return $values;
}

$additional_archive_states = [5, 6];
$userpermissions = ["s", "e-2", "e-1", "e0", "e1", "e2", "e3"];
$blocked = test_000708_blocked_states("");
if ($blocked !== [5, 6]) {
    echo "ERROR - expected states 5 and 6 to be excluded from editable-only search, got " . json_encode($blocked) . "\n";
    return false;
}

$userpermissions[] = "e5";
$blocked = test_000708_blocked_states("");
if ($blocked !== [6]) {
    echo "ERROR - expected only state 6 to be excluded once e5 is granted, got " . json_encode($blocked) . "\n";
    return false;
}

$userpermissions[] = "e6";
$blocked = test_000708_blocked_states("");
if ($blocked !== null) {
    echo "ERROR - expected no excluded states once every state is editable, got " . json_encode($blocked) . "\n";
    return false;
}

foreach ($saved_000708 as $saved_name => $saved_value) {
    $$saved_name = $saved_value;
}

return true;
