<?php

/**
 * typesense_search: the plugin's state, in the typesense_search_state table (Q9.11).
 *
 * Written by CLI processes (the rebuild, the worker) and by web requests alike, so it lives in MySQL: never in a
 * file, APCu or core's sysvars. One row per entry per tenant, the tenant being the collection prefix (tenancy.php);
 * a write is one insert-or-update, a counter is incremented in place, structured values are JSON. Every read goes
 * to the table, except that the three entries read on every search (ready, breaker_open_until, bulk_pending) are
 * kept for typesense_search_state_cache_seconds() per process once this process has read or written them.
 * Options are set_plugin_config()'s (options.php); state never goes there.
 */

/**
 * The entries, name => kind (int, bool or json), default and, for the three read on every search, cached.
 *
 * An int entry is a plain integer: a generation number, a schema version, a time in epoch seconds or a counter.
 * The counters (validation_drops, parked_rows, breaker_trips) move with typesense_search_state_increment(), and
 * each one's last occurrence is its row's updated time, typesense_search_state_updated().
 *
 * @return array
 */
function typesense_search_state_entries(): array
{
    return array(
        // Generations (B8, B9): the live generation's schema version and number. building_generation is the build
        // lock: taken with typesense_search_state_set_if_absent() holding generation, started, pid and host,
        // released with typesense_search_state_delete().
        'schema_version' => array('kind' => 'int', 'default' => 0),
        'live_generation' => array('kind' => 'int', 'default' => 0),
        'building_generation' => array('kind' => 'json', 'default' => null),
        // Read on every search (D1): whether a search may be served at all (B8, B9, C4); whether bulk rows are
        // queued, during which MySQL serves (C4, C7); the breaker's open-until time, 0 when closed (A2).
        'ready' => array('kind' => 'bool', 'default' => false, 'cached' => true),
        'bulk_pending' => array('kind' => 'bool', 'default' => false, 'cached' => true),
        'breaker_open_until' => array('kind' => 'int', 'default' => 0, 'cached' => true),
        // Reports (G1): the last rebuild's started, finished, duration, result and counts (B8); the worker's last
        // run time (C4).
        'last_rebuild' => array('kind' => 'json', 'default' => null),
        'last_worker_run' => array('kind' => 'int', 'default' => 0),
        // Snapshots the rebuild stores (B8) and C7 and B9 diff: the index-relevant columns of every field, and the
        // globals whose change forces a rebuild.
        'field_snapshot' => array('kind' => 'json', 'default' => null),
        'config_snapshot' => array('kind' => 'json', 'default' => null),
        // Counters: rows read-time validation dropped (C8), queue rows parked (C4), breaker trips (A2).
        'validation_drops' => array('kind' => 'int', 'default' => 0),
        'parked_rows' => array('kind' => 'int', 'default' => 0),
        'breaker_trips' => array('kind' => 'int', 'default' => 0),
    );
}

/**
 * One entry's definition: kind, default, cached. An unknown name is a programming error.
 *
 * @param string $name Entry name
 *
 * @return array
 */
function typesense_search_state_entry(string $name): array
{
    $entries = typesense_search_state_entries();
    if (!isset($entries[$name])) {
        throw new InvalidArgumentException('typesense_search: unknown state entry "' . $name . '"');
    }

    return $entries[$name] + array('cached' => false);
}

/**
 * How long this process serves a cached entry before reading it again.
 *
 * @return int Seconds
 */
function typesense_search_state_cache_seconds(): int
{
    return 2;
}

/**
 * Read an entry.
 *
 * @param string $name    Entry name
 * @param mixed  $default Returned when nothing is stored; null means the entry's own default
 *
 * @return mixed
 */
function typesense_search_state_get(string $name, $default = null)
{
    $entry = typesense_search_state_entry($name);
    $prefix = typesense_search_tenancy_prefix();
    if ($default === null) {
        $default = $entry['default'];
    }

    $known = $entry['cached'] ? typesense_search_state_cache_read($prefix . '/' . $name) : null;
    if ($known === null) {
        $rows = typesense_search_state_query(
            'SELECT `value` FROM typesense_search_state WHERE prefix = ? AND `name` = ?',
            array('s', $prefix, 's', $name)
        );
        $value = count($rows) === 0 ? null : typesense_search_state_decode((string)$rows[0]['value'], $entry['kind']);
        $known = array('value' => $value, 'missing' => $value === null);
        if ($entry['cached']) {
            typesense_search_state_cache_write($prefix . '/' . $name, $value, $known['missing']);
        }
    }

    return $known['missing'] ? $default : $known['value'];
}

/**
 * Write an entry: one insert-or-update, so concurrent writers leave one row holding the last value written.
 *
 * @param string $name  Entry name
 * @param mixed  $value The new value: an integer for an int entry, a boolean for a bool entry, anything
 *                      json_encode() takes for a json entry
 *
 * @return void
 */
function typesense_search_state_set(string $name, $value): void
{
    $entry = typesense_search_state_entry($name);
    $prefix = typesense_search_tenancy_prefix();
    $value = typesense_search_state_cast($name, $value, $entry['kind']);
    $encoded = typesense_search_state_encode($value);
    typesense_search_state_query(
        'INSERT INTO typesense_search_state (prefix, `name`, `value`, updated) VALUES (?, ?, ?, NOW())'
        . ' ON DUPLICATE KEY UPDATE `value` = ?, updated = NOW()',
        array('s', $prefix, 's', $name, 's', $encoded, 's', $encoded)
    );
    if ($entry['cached']) {
        typesense_search_state_cache_write($prefix . '/' . $name, $value, false);
    }
}

/**
 * Write an entry only where no row exists, and say whether this call wrote it: the build lock. The value must
 * identify its writer (pid, host, time), or two writers of one value would both think they had won.
 *
 * @param string $name  Entry name
 * @param mixed  $value As for typesense_search_state_set()
 *
 * @return bool True when this call stored the value; false when a row was already there
 */
function typesense_search_state_set_if_absent(string $name, $value): bool
{
    $entry = typesense_search_state_entry($name);
    $prefix = typesense_search_tenancy_prefix();
    $encoded = typesense_search_state_encode(typesense_search_state_cast($name, $value, $entry['kind']));
    typesense_search_state_query(
        'INSERT IGNORE INTO typesense_search_state (prefix, `name`, `value`, updated) VALUES (?, ?, ?, NOW())',
        array('s', $prefix, 's', $name, 's', $encoded)
    );
    $rows = typesense_search_state_query(
        'SELECT `value` FROM typesense_search_state WHERE prefix = ? AND `name` = ?',
        array('s', $prefix, 's', $name)
    );
    if ($entry['cached']) {
        typesense_search_state_cache_forget($prefix . '/' . $name);
    }

    return count($rows) === 1 && (string)$rows[0]['value'] === $encoded;
}

/**
 * Add one to an int entry in place, from its default where nothing is stored: one statement, so concurrent
 * writers lose nothing. The row's updated time becomes the counter's last occurrence.
 *
 * @param string $name Entry name
 *
 * @return void
 */
function typesense_search_state_increment(string $name): void
{
    $entry = typesense_search_state_entry($name);
    if ($entry['kind'] !== 'int') {
        throw new InvalidArgumentException('typesense_search: state entry "' . $name . '" is not a counter');
    }
    $prefix = typesense_search_tenancy_prefix();
    typesense_search_state_query(
        'INSERT INTO typesense_search_state (prefix, `name`, `value`, updated) VALUES (?, ?, ?, NOW())'
        . ' ON DUPLICATE KEY UPDATE `value` = CAST(`value` AS SIGNED) + 1, updated = NOW()',
        array('s', $prefix, 's', $name, 's', typesense_search_state_encode($entry['default'] + 1))
    );
    if ($entry['cached']) {
        typesense_search_state_cache_forget($prefix . '/' . $name);
    }
}

/**
 * Remove an entry, so that the next read returns its default. Releasing the build lock is this.
 *
 * @param string $name Entry name
 *
 * @return void
 */
function typesense_search_state_delete(string $name): void
{
    $entry = typesense_search_state_entry($name);
    $prefix = typesense_search_tenancy_prefix();
    typesense_search_state_query(
        'DELETE FROM typesense_search_state WHERE prefix = ? AND `name` = ?',
        array('s', $prefix, 's', $name)
    );
    if ($entry['cached']) {
        typesense_search_state_cache_write($prefix . '/' . $name, null, true);
    }
}

/**
 * When an entry was last written: a counter's last occurrence.
 *
 * @param string $name Entry name
 *
 * @return int|null Epoch seconds; null when nothing is stored
 */
function typesense_search_state_updated(string $name): ?int
{
    typesense_search_state_entry($name);
    $rows = typesense_search_state_query(
        'SELECT UNIX_TIMESTAMP(updated) AS value FROM typesense_search_state WHERE prefix = ? AND `name` = ?',
        array('s', typesense_search_tenancy_prefix(), 's', $name)
    );

    return count($rows) === 0 || $rows[0]['value'] === null ? null : (int)$rows[0]['value'];
}

/**
 * Drop every cached entry, so that each is read again: a long-running process (the worker) calls this each pass.
 *
 * @return void
 */
function typesense_search_state_cache_clear(): void
{
    typesense_search_state_cache(array());
}

/**
 * Run a statement on the primary connection with no query cache: state must never be stale.
 *
 * @param string $sql        SQL with ? placeholders
 * @param array  $parameters Type and value pairs, as ps_query() takes them
 *
 * @return array Rows; empty for a write
 */
function typesense_search_state_query(string $sql, array $parameters): array
{
    // ps_query()'s sixth argument at its default of 2 sends a SELECT to the read-only connection where one is
    // configured, and a replica may lag; 0 keeps it on the primary.
    $rows = ps_query($sql, $parameters, '', -1, true, 0);

    return is_array($rows) ? $rows : array();
}

/**
 * Check a value against its entry's kind: an int entry takes an integer or an integral string, a bool entry a
 * boolean or 0 / 1, a json entry anything.
 *
 * @param string $name  Entry name, for the message
 * @param mixed  $value The value offered
 * @param string $kind  int, bool or json
 *
 * @return mixed The value as its kind
 */
function typesense_search_state_cast(string $name, $value, string $kind)
{
    if ($kind === 'int' && (is_int($value) || (is_numeric($value) && (string)(int)$value === (string)$value))) {
        return (int)$value;
    }
    if ($kind === 'bool' && (is_bool($value) || $value === 0 || $value === 1)) {
        return (bool)$value;
    }
    if ($kind === 'json') {
        return $value;
    }
    throw new InvalidArgumentException(
        'typesense_search: state entry "' . $name . '" takes ' . ($kind === 'int' ? 'an integer' : 'a boolean')
    );
}

/**
 * A value as the table stores it: JSON with non-ASCII escaped, so the column's character set cannot alter it.
 *
 * @param mixed $value Any value json_encode() takes
 *
 * @return string
 */
function typesense_search_state_encode($value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        throw new InvalidArgumentException('typesense_search: state value cannot be stored: ' . json_last_error_msg());
    }

    return $encoded;
}

/**
 * A stored value back to PHP as its kind. Null for a value that is not JSON or not of its kind, which then counts
 * as missing.
 *
 * @param string $text The stored text
 * @param string $kind int, bool or json
 *
 * @return mixed
 */
function typesense_search_state_decode(string $text, string $kind)
{
    $value = json_decode($text, true);
    if ($value === null) {
        if (json_last_error() !== JSON_ERROR_NONE) {
            debug('typesense_search: state value is not JSON: ' . substr($text, 0, 80));
        }
        return null;
    }
    if ($kind === 'int') {
        return is_numeric($value) ? (int)$value : null;
    }
    if ($kind === 'bool') {
        return is_scalar($value) ? (bool)$value : null;
    }

    return $value;
}

/**
 * The per-process cache of the cached entries, "<prefix>/<name>" => value, missing, read (microtime); $set
 * replaces it, null reads it.
 *
 * @param array|null $set The new contents, or null to read
 *
 * @return array
 */
function typesense_search_state_cache(?array $set = null): array
{
    static $cache = array();

    if ($set !== null) {
        $cache = $set;
    }

    return $cache;
}

/**
 * A cached entry read or written by this process within typesense_search_state_cache_seconds().
 *
 * @param string $key "<prefix>/<name>"
 *
 * @return array|null value and missing; null when it must be read
 */
function typesense_search_state_cache_read(string $key): ?array
{
    $cache = typesense_search_state_cache();
    if (!isset($cache[$key]) || microtime(true) - $cache[$key]['read'] >= typesense_search_state_cache_seconds()) {
        return null;
    }

    return $cache[$key];
}

/**
 * Remember an entry as this process has just read or written it.
 *
 * @param string $key     "<prefix>/<name>"
 * @param mixed  $value   Its value
 * @param bool   $missing True when nothing is stored
 *
 * @return void
 */
function typesense_search_state_cache_write(string $key, $value, bool $missing): void
{
    $cache = typesense_search_state_cache();
    $cache[$key] = array('value' => $value, 'missing' => $missing, 'read' => microtime(true));
    typesense_search_state_cache($cache);
}

/**
 * Drop one entry from the cache, so that its next read goes to the table.
 *
 * @param string $key "<prefix>/<name>"
 *
 * @return void
 */
function typesense_search_state_cache_forget(string $key): void
{
    $cache = typesense_search_state_cache();
    unset($cache[$key]);
    typesense_search_state_cache($cache);
}
