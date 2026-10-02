<?php
// Helpers for building a fixture in the A/B harness: rows go into SQLite the way core would write them (with core's
// real keyword indexing), and fx_index() builds the Typesense index with the plugin's real reindex functions.

function fx_exec(string $sql, array $values = array()): void
{
    global $DB;
    $stmt = $DB->prepare($sql);
    foreach (array_values($values) as $i => $v) {
        $stmt->bindValue($i + 1, $v, $v === null ? SQLITE3_NULL : (is_int($v) ? SQLITE3_INTEGER : (is_float($v) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
    }
    $stmt->execute();
}

function fx_field(int $ref, string $name, int $type, array $over = array()): void
{
    $f = $over + array('keywords_index' => 1, 'partial_index' => 0, 'complete_index' => 0, 'active' => 1, 'field_constraint' => 0);
    fx_exec(
        'INSERT INTO resource_type_field (ref, name, title, type, order_by, keywords_index, partial_index, complete_index, active, field_constraint, `global`, advanced_search, simple_search, sort_method, display_as_dropdown, automatic_nodes_ordering)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 0, 0, 0, 0)',
        array($ref, $name, ucfirst($name), $type, $ref * 10, $f['keywords_index'], $f['partial_index'], $f['complete_index'], $f['active'], $f['field_constraint'])
    );
}

/** Create a node the way set_node() does for a new node: insert it, then index it if the field is indexed. */
function fx_node(int $field, string $name, ?int $parent = null, ?int $ref = null): int
{
    global $DB, $FX;
    if ($ref === null) {
        fx_exec('INSERT INTO node (resource_type_field, name, parent, order_by) VALUES (?, ?, ?, ?)', array($field, $name, $parent, 10));
        $ref = $DB->lastInsertRowID();
    } else {
        fx_exec('INSERT INTO node (ref, resource_type_field, name, parent, order_by) VALUES (?, ?, ?, ?, ?)', array($ref, $field, $name, $parent, 10));
    }
    $indexed = (int)$DB->querySingle('SELECT keywords_index FROM resource_type_field WHERE ref = ' . $field);
    if ($indexed === 1) {
        add_node_keyword_mappings(array('ref' => $ref, 'resource_type_field' => $field, 'name' => $name), null);
    }
    return $ref;
}

function fx_tag(int $resource, int $node): void
{
    fx_exec('INSERT INTO resource_node (resource, node, hit_count, new_hit_count) VALUES (?, ?, 0, 0)', array($resource, $node));
}

function fx_resource(int $ref, int $type, array $over = array()): void
{
    $r = $over + array('archive' => 0, 'access' => 0, 'created_by' => 1, 'creation_date' => '2020-01-01 10:00:00', 'modified' => '2020-01-02 10:00:00', 'hit_count' => 0);
    fx_exec(
        'INSERT INTO resource (ref, resource_type, archive, access, created_by, creation_date, modified, hit_count, new_hit_count, has_image, file_extension, user_rating)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, 0)',
        array($ref, $type, $r['archive'], $r['access'], $r['created_by'], $r['creation_date'], $r['modified'], $r['hit_count'], 'jpg')
    );
}

/** Set a text / date value: one new node, linked to the resource, plus the resource-table copy where there is one. */
function fx_set(int $resource, int $field, string $value): int
{
    $node = fx_node($field, $value);
    fx_tag($resource, $node);
    if (in_array($field, array(3, 8, 12, 18, 51), true)) {
        fx_exec('UPDATE resource SET field' . $field . ' = ? WHERE ref = ?', array(mb_substr($value, 0, 200), $resource));
    }
    return $node;
}

function fx_collection(int $ref, string $name, int $user, int $type, array $resources): void
{
    fx_exec('INSERT INTO collection (ref, name, user, type, created, allow_changes) VALUES (?, ?, ?, ?, ?, 0)', array($ref, $name, $user, $type, '2024-01-01 00:00:00'));
    $n = 0;
    foreach ($resources as $r) {
        fx_exec('INSERT INTO collection_resource (collection, resource, date_added, sortorder) VALUES (?, ?, ?, ?)', array($ref, $r, '2024-01-0' . (++$n) . ' 12:00:00', $n * 10));
    }
}

function fx_index(): void
{
    $dropped = typesense_search_drop_collections();
    if (!typesense_search_ensure_collection()) {
        throw new RuntimeException('could not create the Typesense collections');
    }
    $report = array();
    $after = 0;
    do {
        $r = typesense_search_reindex_resources(1000, $after);
        $after = (int)$r['last'];
        $report['resources'] = ($report['resources'] ?? 0) + $r['indexed'];
        $report['failed'] = ($report['failed'] ?? 0) + $r['failed'];
        $report['errors'] = array_merge($report['errors'] ?? array(), array_keys($r['errors']));
    } while (!$r['complete']);
    $c = 0;
    $res = 0;
    do {
        $r = typesense_search_reindex_resource_collection_memberships(1000, $c, $res);
        $c = (int)$r['last_collection'];
        $res = (int)$r['last_resource'];
        $report['memberships'] = ($report['memberships'] ?? 0) + $r['indexed'];
        $report['failed'] += $r['failed'];
        $report['errors'] = array_merge($report['errors'], array_keys($r['errors']));
    } while (!$r['complete']);
    $after = 0;
    do {
        $r = typesense_search_reindex_resource_attributes(500, $after);
        $after = (int)$r['last'];
        $report['attributes'] = ($report['attributes'] ?? 0) + $r['indexed'];
        $report['failed'] += $r['failed'];
        $report['errors'] = array_merge($report['errors'], array_keys($r['errors']));
    } while (!$r['complete']);
    $after = 0;
    do {
        $r = typesense_search_reindex_grants(1000, $after);
        $after = (int)$r['last'];
        $report['grants'] = ($report['grants'] ?? 0) + $r['indexed'];
        $report['failed'] += $r['failed'];
        $report['errors'] = array_merge($report['errors'], array_keys($r['errors']));
    } while (!$r['complete']);
    echo 'Typesense index built by the plugin\'s reindex functions: ' . json_encode($report) . "\n";
}
