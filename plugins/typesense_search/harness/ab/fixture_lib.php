<?php

// Helpers for building a fixture in the A/B harness: rows go into the database (SQLite, or MySQL under
// HARNESS_DB=mysql) the way core would write them (with core's real keyword indexing). Fixtures are files under
// fixtures/, loaded by name with fx_load(); a battery loads the base fixture and only the additions it needs (Q10.13).

$FX_LOADED = array();

/** Load a fixture file once: fixtures/<name>.php. */
function fx_load(string $name): void
{
    global $FX_LOADED;
    if (isset($FX_LOADED[$name])) {
        return;
    }
    $file = __DIR__ . '/fixtures/' . $name . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('no fixture named ' . $name);
    }
    $FX_LOADED[$name] = true;
    require $file;
}

function fx_exec(string $sql, array $values = array()): void
{
    global $DB, $HARNESS;
    if ($HARNESS['mode'] === 'mysql') {
        $parameters = array();
        foreach (array_values($values) as $v) {
            $parameters[] = is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
            $parameters[] = $v;
        }
        harness_run($sql, $parameters);
        return;
    }
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
    global $DATE_FIELD_TYPES;
    if ($ref === null) {
        fx_exec('INSERT INTO node (resource_type_field, name, parent, order_by) VALUES (?, ?, ?, ?)', array($field, $name, $parent, 10));
        $ref = sql_insert_id();
    } else {
        fx_exec('INSERT INTO node (ref, resource_type_field, name, parent, order_by) VALUES (?, ?, ?, ?, ?)', array($ref, $field, $name, $parent, 10));
    }
    $row = harness_run('SELECT keywords_index, type FROM resource_type_field WHERE ref = ?', array('i', $field))[0] ?? array();
    if ((int)($row['keywords_index'] ?? 0) === 1) {
        $is_date = in_array((int)$row["type"], $DATE_FIELD_TYPES);
        $is_html = (int)$row["type"] === FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE;
        add_node_keyword_mappings(array("ref" => $ref, "resource_type_field" => $field, "name" => $name), null, $is_date, $is_html);
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

/** Report SQL errors collected while a fixture loaded, then forget them. */
function fx_report_errors(string $what): void
{
    global $HARNESS;
    if (!empty($HARNESS['sql_errors'])) {
        echo 'SQL errors while ' . $what . ":\n  " . implode("\n  ", array_unique($HARNESS['sql_errors'])) . "\n";
        $HARNESS['sql_errors'] = array();
    }
}

/**
 * Build the Typesense index of the fixture with the plugin's generation rebuild (B8). Seam: until
 * typesense_search_rebuild_generation() exists the plugin side has no index and this says so.
 */
function fx_index(): bool
{
    if (!function_exists('typesense_search_rebuild_generation')) {
        echo "index: not built - the generation rebuild (B8, typesense_search_rebuild_generation) does not exist yet\n";
        return false;
    }
    $report = typesense_search_rebuild_generation();
    echo 'index: built by the plugin\'s generation rebuild: ' . json_encode($report) . "\n";
    return true;
}
