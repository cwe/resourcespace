<?php
// Smoke test: what core's keyword index holds for a few nodes, then three simple searches.
require __DIR__ . '/fixture.php';

// What core's keyword index holds for a few nodes.
$rows = harness_run("SELECT n.ref, n.resource_type_field f, substr(n.name,1,40) name, group_concat(k.keyword || '@' || nk.position, ' ') kws FROM node n LEFT JOIN node_keyword nk ON nk.node=n.ref LEFT JOIN keyword k ON k.ref=nk.keyword WHERE n.ref IN (204, 302) OR n.resource_type_field IN (92, 51, 90, 12, 94, 96) GROUP BY n.ref", array());
foreach ($rows as $r) {
    echo sprintf("node %-5s field %-3s %-42s => %s\n", $r['ref'], $r['f'], json_encode($r['name']), $r['kws']);
}
echo 'keywords in table: ' . $DB->querySingle('SELECT COUNT(*) FROM keyword') . ', node_keyword rows: ' . $DB->querySingle('SELECT COUNT(*) FROM node_keyword') . "\n";

fx_index();
if (!empty($HARNESS['sql_errors'])) {
    echo "SQL errors while indexing:\n  " . implode("\n  ", array_unique($HARNESS['sql_errors'])) . "\n";
}

ab('S1', 'sunset');
ab('S2', 'harbour');
ab('S3', '');
