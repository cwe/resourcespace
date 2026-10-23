<?php

// H1 smoke battery: the harness boots, the fixture loads the way core writes it, core answers the fixture as
// derived by hand, the private Typesense is up and the schema module creates the collections on it. This is the
// battery the tests/ bridge runs; every session runs it before handing over.
require __DIR__ . '/fixture.php';

// ----------------------------------------------------------------------------------------- environment
list($status, $debug) = harness_ts('GET', '/debug');
harness_check('H1.1 private Typesense answers', $status === 200 && isset($debug['version']), 'version ' . ($debug['version'] ?? '?'));
harness_check('H1.2 Typesense is 30.x', isset($debug['version']) && strpos((string)$debug['version'], '30.') === 0, (string)($debug['version'] ?? '?'));
harness_check('H1.3 harness prefix', typesense_search_tenancy_prefix() === 'harness');

$tables = (int)$DB->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name <> 'sqlite_sequence'");
$dbstruct = count(glob($ROOT . '/dbstruct/table_*.txt'));
harness_check('H1.4 one SQLite table per dbstruct file', $tables === $dbstruct, $tables . ' tables');
harness_check('H1.5 fixture resources', (int)$DB->querySingle('SELECT COUNT(*) FROM resource') === 34);
harness_check('H1.6 fixture fields', (int)$DB->querySingle('SELECT COUNT(*) FROM resource_type_field') === 14);
$keywords = (int)$DB->querySingle('SELECT COUNT(*) FROM keyword');
harness_check('H1.7 core indexed the fixture', $keywords > 100, $keywords . ' keywords, ' . $DB->querySingle('SELECT COUNT(*) FROM node_keyword') . ' node_keyword rows');

// What core's keyword index holds for a few nodes (the fixture must exist in the keyword table, or core never asks the plugin).
foreach (harness_run("SELECT n.ref, n.resource_type_field f, substr(n.name,1,40) name, group_concat(k.keyword || '@' || nk.position, ' ') kws FROM node n LEFT JOIN node_keyword nk ON nk.node=n.ref LEFT JOIN keyword k ON k.ref=nk.keyword WHERE n.ref IN (204, 302) OR n.resource_type_field IN (51, 90, 94, 96) GROUP BY n.ref", array()) as $r) {
    echo sprintf("    node %-5s field %-3s %-42s => %s\n", $r['ref'], $r['f'], json_encode($r['name']), $r['kws']);
}
harness_check('H1.8 partial index built for field 51', (int)$DB->querySingle("SELECT COUNT(*) FROM node_keyword nk JOIN keyword k ON k.ref = nk.keyword WHERE k.keyword = 'ulpture'") === 1);
harness_check('H1.9 non-indexed field 96 has no keywords', (int)$DB->querySingle('SELECT COUNT(*) FROM node_keyword nk JOIN node n ON n.ref = nk.node WHERE n.resource_type_field = 96') === 0);

// ----------------------------------------------------------------------------------------- the index seam
fx_index();

// ----------------------------------------------------------------------------------------- the schema on the server
$generation = typesense_search_schema_generation(1);
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}
$created = true;
foreach ($generation as $kind => $definition) {
    list($status, $body) = harness_ts('POST', '/collections', $definition);
    $created = $created && $status === 201;
    if ($status !== 201) {
        echo '    ' . $kind . ': HTTP ' . $status . ' ' . json_encode($body) . "\n";
    }
}
harness_check('H1.10 the three collections of generation 1 created from the schema module', $created, implode(', ', array_keys($generation)));
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}

// ----------------------------------------------------------------------------------------- core answers the fixture
// Expected sets are derived by hand from the fixture (principle 11): the tester sees archive 0, open and group-granted
// resources; 9 (archive 2), 10 (pending, another user's), 11 and 13 (confidential), 30 (deleted) are out of reach.
$visible = array(1, 2, 3, 4, 5, 6, 7, 8, 12, 14, 15, 16, 18, 19, 21, 22, 23, 24, 25, 26, 28, 29, 31, 32, 33, 34, 35, 36, 37);

ab('A33', '', array('order_by' => 'resourceid', 'sort' => 'ASC'), array('expect_order' => $visible));
ab('A1', 'sunset', array(), array('expect' => array(1, 8, 12, 36, 37)));
ab('A2', 'sunset harbour', array(), array('expect' => array(1)));
ab('A21', 'automobile', array(), array('expect' => array(1, 2), 'note' => 'related keyword automobile -> car'));
ab('B1', 'title:harbour', array(), array('expect' => array(1), 'note' => 'harbour in the caption of 1 only; title restricts to field 8'));
ab('C13', '@@201', array(), array('expect' => array(1, 4), 'note' => 'node France'));
ab('C15', '@@201, @@302', array(), array('expect' => array(4), 'note' => 'France AND modern art'));
ab('D1', 'date:2024', array(), array('expect' => array(1, 2, 4, 14)));
ab('F19', '!collection5', array('order_by' => 'collection', 'sort' => 'ASC'), array('expect_order' => array(3, 1, 2)));

harness_finish('H1_smoke');
