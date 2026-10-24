<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// A6 battery: the plugin state store. The table as core's dbstruct creates it, set and get with their types, the JSON
// round trip, one row per entry per tenant, prefix isolation, ready false on a fresh table, the in-place counter from
// two processes sharing one database and from interleaved writers, the build lock, the per-process cache serving
// another writer's value no longer than its TTL, and the SQL each operation sends.
require __DIR__ . '/boot.php';

$A6_PLUGIN = $ROOT . '/plugins/typesense_search';
$A6_TIMES = 250; // increments per writer in the two-process case

/** The class of the exception $fn throws, or '' when it returns. */
function a6_throws(callable $fn): string
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return get_class($e);
    }
    return '';
}

/** Rows in the state table for one tenant and entry, read directly. */
function a6_rows(string $prefix, string $name): int
{
    return (int)harness_scalar('SELECT COUNT(*) AS n FROM typesense_search_state WHERE prefix = ? AND name = ?', array('s', $prefix, 's', $name));
}

/** Another writer: a statement on the table that bypasses the module and its cache. */
function a6_other_writer(string $sql): void
{
    harness_run($sql, array());
}

/** The statements the module sent while $fn ran, whitespace collapsed. */
function a6_sql(callable $fn): array
{
    global $HARNESS;
    $HARNESS['sql_log'] = array();
    $HARNESS['log_sql'] = true;
    $fn();
    $HARNESS['log_sql'] = false;
    return $HARNESS['sql_log'];
}

/** Read one line from a pipe, waiting at most $seconds; '' when nothing came. */
function a6_read_line($pipe, int $seconds): string
{
    $deadline = microtime(true) + $seconds;
    $line = '';
    while (microtime(true) < $deadline) {
        $read = array($pipe);
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 1) > 0) {
            $chunk = fgets($pipe);
            if ($chunk === false) {
                break;
            }
            $line .= $chunk;
            if (substr($line, -1) === "\n") {
                break;
            }
        }
    }
    return trim($line);
}

/** Start a writer process on the shared database (a file under SQLite, this battery's own database under MySQL) and wait for its READY; null when it does not get there. */
function a6_writer_start(string $file, string $name, int $times): ?array
{
    global $SCRATCH, $HARNESS;

    $process = proc_open(
        array(PHP_BINARY, __DIR__ . '/state_writer.php', $name, (string)$times),
        array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', $SCRATCH . '/a6_writer.log', 'a')),
        $pipes,
        __DIR__,
        array_merge(getenv(), $HARNESS['mode'] === 'mysql' ? array('HARNESS_DB' => 'mysql', 'HARNESS_MYSQL_KEEP' => '1') : array('HARNESS_DB' => $file))
    );
    if (!is_resource($process)) {
        return null;
    }
    $ready = a6_read_line($pipes[1], 60);
    if ($ready !== 'READY') {
        proc_terminate($process);
        proc_close($process);
        echo '    writer did not get ready: ' . $ready . "\n";
        return null;
    }
    return array('process' => $process, 'pipes' => $pipes);
}

// ----------------------------------------------------------------------------------------- the table
$columns = array();
$f = fopen($A6_PLUGIN . '/dbstruct/table_typesense_search_state.txt', 'r');
while (($col = fgetcsv($f, 5000, ',', '"', '\\')) !== false) {
    $columns[$col[0]] = $col;
}
fclose($f);
harness_check(
    'A6.1 the dbstruct file as CheckDBStruct() reads it: prefix, name, value, updated; prefix and name the primary key',
    array_keys($columns) === array('prefix', 'name', 'value', 'updated')
        && $columns['prefix'][3] === 'PRI' && $columns['name'][3] === 'PRI'
        && $columns['value'][3] === '' && $columns['updated'][3] === ''
        && $columns['prefix'][5] === '' && $columns['name'][5] === ''
        && count($columns['prefix']) === 6
);
$pk = array();
if ($HARNESS['mode'] === 'mysql') {
    foreach (harness_run('SHOW COLUMNS FROM typesense_search_state', array()) as $info) {
        $pk[$info['Field']] = 0;
    }
    foreach (harness_run("SHOW INDEX FROM typesense_search_state WHERE Key_name = 'PRIMARY'", array()) as $info) {
        $pk[$info['Column_name']] = (int)$info['Seq_in_index'];
    }
} else {
    foreach (harness_run('PRAGMA table_info(typesense_search_state)', array()) as $info) {
        $pk[$info['name']] = (int)$info['pk'];
    }
}
harness_check(
    'A6.2 the harness built the table from the plugin\'s dbstruct with that composite key',
    $pk === array('prefix' => 1, 'name' => 2, 'value' => 0, 'updated' => 0),
    json_encode($pk)
);
$loader = file_get_contents($A6_PLUGIN . '/include/typesense_search_functions.php');
harness_check(
    'A6.3 the loader requires state.php after tenancy.php, which it reads the prefix through',
    strpos($loader, "'/state.php'") !== false && strpos($loader, "'/state.php'") > strpos($loader, "'/tenancy.php'")
);
// The module's code with its comments removed: the docblocks name what it must not touch.
$code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($A6_PLUGIN . '/include/state.php'));
harness_check(
    'A6.4 state never touches plugin configuration, sysvars or the query cache',
    preg_match('/plugin_config|sysvar|clear_query_cache/i', $code) === 0
        && preg_match('/ps_query\(\$sql, \$parameters, \'\'/', $code) === 1
);
$entries = typesense_search_state_entries();
$named = array(
    'schema_version', 'live_generation', 'building_generation', 'ready', 'bulk_pending', 'last_rebuild', 'last_worker_run',
    'breaker_open_until', 'field_snapshot', 'config_snapshot', 'validation_drops', 'parked_rows', 'breaker_trips',
);
$cached = array();
foreach ($entries as $name => $entry) {
    if (!empty($entry['cached'])) {
        $cached[] = $name;
    }
}
harness_check(
    'A6.5 the documented list carries every entry Q9.11 names; exactly the three read on every search are cached',
    count(array_diff($named, array_keys($entries))) === 0 && $cached === array('ready', 'bulk_pending', 'breaker_open_until'),
    implode(', ', array_diff($named, array_keys($entries)))
);

// ----------------------------------------------------------------------------------------- a fresh table
harness_check(
    'A6.6 a fresh table: ready false, bulk_pending false, breaker closed, no generation, no rebuild',
    typesense_search_state_get('ready') === false && typesense_search_state_get('bulk_pending') === false
        && typesense_search_state_get('breaker_open_until') === 0 && typesense_search_state_get('live_generation') === 0
        && typesense_search_state_get('schema_version') === 0 && typesense_search_state_get('last_rebuild') === null
        && typesense_search_state_get('building_generation') === null
);
harness_check(
    'A6.7 a caller\'s default is honoured where nothing is stored',
    typesense_search_state_get('last_rebuild', array('result' => 'none')) === array('result' => 'none')
        && typesense_search_state_get('live_generation', 9) === 9
);
harness_check('A6.8 updated is null where nothing is stored', typesense_search_state_updated('last_rebuild') === null);
harness_check(
    'A6.9 an unknown entry is a programming error, on read and on write',
    a6_throws(function () {
        typesense_search_state_get('redy');
    }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_set('redy', true);
        }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_increment('drops');
        }) === 'InvalidArgumentException'
);

// ----------------------------------------------------------------------------------------- set and get
typesense_search_state_set('live_generation', 3);
$first = typesense_search_state_get('live_generation');
typesense_search_state_set('live_generation', '4');
harness_check('A6.10 an int entry round-trips as an int, an integral string included', $first === 3 && typesense_search_state_get('live_generation') === 4);
typesense_search_state_set('ready', true);
$on = typesense_search_state_get('ready');
typesense_search_state_set('ready', 0);
typesense_search_state_set('bulk_pending', 1);
harness_check(
    'A6.11 a bool entry round-trips as a bool, 0 and 1 included',
    $on === true && typesense_search_state_get('ready') === false && typesense_search_state_get('bulk_pending') === true
);
typesense_search_state_set('ready', false);
harness_check('A6.12 a stored false is not missing: the caller\'s default does not replace it', typesense_search_state_get('ready', true) === false);
harness_check(
    'A6.13 a value of the wrong kind is refused',
    a6_throws(function () {
        typesense_search_state_set('ready', 'yes');
    }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_set('live_generation', 1.5);
        }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_set('live_generation', '7a');
        }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_set('last_worker_run', null);
        }) === 'InvalidArgumentException'
);
$rebuild = array(
    'started' => 1761200000, 'finished' => 1761200612.5, 'duration' => 612.0, 'result' => 'ok',
    'counts' => array('resources' => 34, 'memberships' => 0, 'grants' => 2),
    'note' => "caf\u{e9} \"quoted\" / slash \\ backslash", 'verified' => true, 'error' => null, 'list' => array(1, 2, 3),
);
typesense_search_state_set('last_rebuild', $rebuild);
$back = typesense_search_state_get('last_rebuild');
harness_check(
    'A6.14 a JSON round trip keeps nesting, ints, a float with a zero fraction, strings, booleans and null',
    $back === $rebuild,
    $back === $rebuild ? '' : json_encode($back)
);
$stored = harness_scalar("SELECT value FROM typesense_search_state WHERE prefix = 'harness' AND name = 'last_rebuild'");
harness_check(
    'A6.15 the stored text is ASCII-only JSON, so the column\'s character set cannot alter it',
    is_string($stored) && preg_match('/^[\x20-\x7e]*$/', $stored) === 1 && strpos($stored, 'caf' . chr(92) . 'u00e9') !== false,
    (string)$stored
);
typesense_search_state_set('config_snapshot', array('view_title_field' => "a\xffb"));
harness_check(
    'A6.16 invalid UTF-8 in a value is substituted rather than failing the write',
    typesense_search_state_get('config_snapshot') === array('view_title_field' => "a\u{fffd}b")
);
harness_check(
    'A6.17 repeated writes are one insert-or-update: one row per entry',
    a6_rows('harness', 'live_generation') === 1 && a6_rows('harness', 'ready') === 1
        && (int)harness_scalar("SELECT COUNT(*) FROM typesense_search_state WHERE prefix = 'harness'") === 5
);
a6_other_writer("UPDATE typesense_search_state SET updated = '2000-01-01 00:00:00' WHERE prefix = 'harness' AND name = 'live_generation'");
$old = typesense_search_state_updated('live_generation');
typesense_search_state_set('live_generation', 4);
$new = typesense_search_state_updated('live_generation');
harness_check(
    'A6.18 updated is the time of the last write',
    $old === strtotime('2000-01-01 00:00:00') && $new !== null && abs($new - time()) <= 5,
    $old . ' -> ' . $new
);
typesense_search_state_delete('live_generation');
$errors_before = count($HARNESS['sql_errors']);
typesense_search_state_delete('live_generation');
harness_check(
    'A6.19 delete removes the row, so the default is back; deleting again is nothing',
    typesense_search_state_get('live_generation') === 0 && a6_rows('harness', 'live_generation') === 0
        && count($HARNESS['sql_errors']) === $errors_before
);

// ----------------------------------------------------------------------------------------- prefix isolation
$typesense_search_prefix = 'tenant_a';
typesense_search_state_set('live_generation', 3);
typesense_search_state_set('ready', true);
$typesense_search_prefix = 'tenant_b';
$b_generation = typesense_search_state_get('live_generation');
$b_ready = typesense_search_state_get('ready');
typesense_search_state_set('live_generation', 5);
typesense_search_state_set('ready', false);
$typesense_search_prefix = 'tenant_a';
harness_check(
    'A6.20 two tenants on one table never see each other\'s rows',
    $b_generation === 0 && $b_ready === false && typesense_search_state_get('live_generation') === 3
        && typesense_search_state_get('ready') === true
        && a6_rows('tenant_a', 'live_generation') === 1 && a6_rows('tenant_b', 'live_generation') === 1
);
$typesense_search_prefix = 'tenant_b';
harness_check(
    'A6.21 the cache is per tenant too: tenant_b\'s ready, read within the TTL, is its own',
    typesense_search_state_get('ready') === false && typesense_search_state_get('live_generation') === 5
);
$typesense_search_prefix = '';
$unset_ready = typesense_search_state_get('ready');
typesense_search_state_set('ready', true);
$typesense_search_prefix = 'harness';
harness_check(
    'A6.22 before the first setup-page save the prefix is empty and state is keyed under it',
    $unset_ready === false && a6_rows('', 'ready') === 1 && typesense_search_state_get('ready') === false
);

// ----------------------------------------------------------------------------------------- counters
typesense_search_state_increment('validation_drops');
$one = typesense_search_state_get('validation_drops');
typesense_search_state_increment('validation_drops');
harness_check('A6.23 a counter starts at its default and moves by one', $one === 1 && typesense_search_state_get('validation_drops') === 2);
$sent = a6_sql(function () {
    typesense_search_state_increment('validation_drops');
});
// The SQLite driver logs what it ran (the upsert translated); the MySQL driver logs what the module sent.
$upsert = $HARNESS['mode'] === 'mysql' ? 'ON DUPLICATE KEY UPDATE' : 'ON CONFLICT DO UPDATE SET';
harness_check(
    'A6.24 an increment is one statement with no read: INSERT ... ON CONFLICT DO UPDATE SET value = CAST(value AS SIGNED) + 1',
    count($sent) === 1 && preg_match('/^INSERT INTO typesense_search_state .* ' . $upsert . ' `value` = CAST\(`value` AS SIGNED\) \+ 1, updated = NOW\(\)$/', $sent[0]) === 1
        && stripos($sent[0], 'SELECT') === false,
    implode(' | ', $sent)
);
typesense_search_state_increment('parked_rows');
a6_other_writer("UPDATE typesense_search_state SET value = CAST(value AS SIGNED) + 1 WHERE prefix = 'harness' AND name = 'parked_rows'");
typesense_search_state_increment('parked_rows');
harness_check('A6.25 an increment interleaved with another writer\'s loses nothing: a counter is never cached', typesense_search_state_get('parked_rows') === 3);
a6_other_writer("UPDATE typesense_search_state SET updated = '2000-01-01 00:00:00' WHERE prefix = 'harness' AND name = 'parked_rows'");
typesense_search_state_increment('parked_rows');
$last = typesense_search_state_updated('parked_rows');
harness_check('A6.26 the counter\'s last occurrence is its row\'s updated time', $last !== null && abs($last - time()) <= 5, (string)$last);
harness_check(
    'A6.27 only an int entry can be incremented',
    a6_throws(function () {
        typesense_search_state_increment('ready');
    }) === 'InvalidArgumentException'
        && a6_throws(function () {
            typesense_search_state_increment('last_rebuild');
        }) === 'InvalidArgumentException'
);

// Two processes, each running the plugin's increment on one shared file database, told to start together.
$file = $SCRATCH . '/a6_state.sqlite';
foreach (array('', '-journal', '-wal', '-shm') as $suffix) {
    @unlink($file . $suffix);
}
@unlink($SCRATCH . '/a6_writer.log');
if ($HARNESS['mode'] === 'mysql') {
    // The writers share this battery's database, not a file of their own: start the counter from nothing.
    typesense_search_state_delete('validation_drops');
}
$writers = array();
$first = a6_writer_start($file, 'validation_drops', $A6_TIMES);
if ($first !== null) {
    $writers[] = $first;
    $second = a6_writer_start($file, 'validation_drops', $A6_TIMES);
    if ($second !== null) {
        $writers[] = $second;
    }
}
$reports = array();
if (count($writers) === 2) {
    foreach ($writers as $writer) {
        fwrite($writer['pipes'][0], "GO\n");
        fflush($writer['pipes'][0]);
    }
    foreach ($writers as $writer) {
        $reports[] = a6_read_line($writer['pipes'][1], 120);
    }
}
foreach ($writers as $writer) {
    fclose($writer['pipes'][0]);
    fclose($writer['pipes'][1]);
    proc_close($writer['process']);
}
$shared_count = null;
if ($HARNESS['mode'] === 'mysql') {
    $shared_count = harness_scalar("SELECT value FROM typesense_search_state WHERE prefix = 'harness' AND name = 'validation_drops'");
} elseif (is_file($file)) {
    $shared = new SQLite3($file, SQLITE3_OPEN_READONLY);
    $shared_count = $shared->querySingle("SELECT value FROM typesense_search_state WHERE prefix = 'harness' AND name = 'validation_drops'");
    $shared->close();
}
harness_check(
    'A6.28 two processes incrementing one counter ' . $A6_TIMES . ' times each on a shared database leave 2 x ' . $A6_TIMES . ', with no SQL error in either',
    count($reports) === 2 && $reports[0] === 'DONE ' . $A6_TIMES . ' errors=0' && $reports[1] === 'DONE ' . $A6_TIMES . ' errors=0'
        && (int)$shared_count === 2 * $A6_TIMES,
    'writers: ' . json_encode($reports) . ' count: ' . json_encode($shared_count) . (count($writers) < 2 ? ' see ' . $SCRATCH . '/a6_writer.log' : '')
);
foreach (array('', '-journal', '-wal', '-shm') as $suffix) {
    @unlink($file . $suffix);
}

// ----------------------------------------------------------------------------------------- the build lock
$lock = array('generation' => 2, 'started' => time(), 'pid' => getmypid(), 'host' => 'this');
$taken = typesense_search_state_set_if_absent('building_generation', $lock);
harness_check('A6.29 the lock is taken where no row exists and the value is stored', $taken === true && typesense_search_state_get('building_generation') === $lock);
$rival = array('generation' => 2, 'started' => time(), 'pid' => getmypid() + 1, 'host' => 'other');
$sent = a6_sql(function () use ($rival, &$taken_again) {
    $taken_again = typesense_search_state_set_if_absent('building_generation', $rival);
});
harness_check(
    'A6.30 a second taker is refused and the first holder\'s value stays: INSERT IGNORE then a read, no UPDATE',
    $taken_again === false && typesense_search_state_get('building_generation') === $lock
        && count($sent) === 2 && stripos($sent[0], $HARNESS['mode'] === 'mysql' ? 'INSERT IGNORE INTO typesense_search_state' : 'INSERT OR IGNORE INTO typesense_search_state') === 0
        && stripos($sent[1], 'SELECT') === 0,
    implode(' | ', $sent)
);
typesense_search_state_delete('building_generation');
harness_check('A6.31 after the holder deletes the lock the next taker gets it', typesense_search_state_set_if_absent('building_generation', $rival) === true);
typesense_search_state_delete('building_generation');

// ----------------------------------------------------------------------------------------- the cache
$ttl = typesense_search_state_cache_seconds();
harness_check('A6.32 the cache lives a few seconds', $ttl >= 1 && $ttl <= 5, $ttl . ' s');
typesense_search_state_cache_clear();
$first_read = a6_sql(function () {
    typesense_search_state_get('ready');
});
$second_read = a6_sql(function () {
    typesense_search_state_get('ready');
    typesense_search_state_get('breaker_open_until');
    typesense_search_state_get('breaker_open_until');
});
$plain_reads = a6_sql(function () {
    typesense_search_state_get('live_generation');
    typesense_search_state_get('live_generation');
});
harness_check(
    'A6.33 a cached entry is read once per TTL, a plain entry on every call',
    count($first_read) === 1 && count($second_read) === 1 && count($plain_reads) === 2,
    count($first_read) . ', ' . count($second_read) . ', ' . count($plain_reads)
);
typesense_search_state_set('ready', true);
a6_other_writer("UPDATE typesense_search_state SET value = 'false' WHERE prefix = 'harness' AND name = 'ready'");
$stale = typesense_search_state_get('ready');
usleep($ttl * 1000000 + 200000);
$fresh = typesense_search_state_get('ready');
harness_check(
    'A6.34 another writer\'s value is served no later than the TTL: stale within it, fresh after it',
    $stale === true && $fresh === false
);
typesense_search_state_set('bulk_pending', true);
a6_other_writer("UPDATE typesense_search_state SET value = 'false' WHERE prefix = 'harness' AND name = 'bulk_pending'");
$before_clear = typesense_search_state_get('bulk_pending');
typesense_search_state_cache_clear();
harness_check(
    'A6.35 cache_clear() makes the next read go to the table at once',
    $before_clear === true && typesense_search_state_get('bulk_pending') === false
);
typesense_search_state_set('ready', true);
typesense_search_state_set('ready', false);
$own = typesense_search_state_get('ready');
typesense_search_state_set('breaker_open_until', time() + 30);
typesense_search_state_delete('breaker_open_until');
harness_check(
    'A6.36 this process\'s own writes and deletes are seen at once, within the TTL',
    $own === false && typesense_search_state_get('breaker_open_until') === 0
);
a6_other_writer("DELETE FROM typesense_search_state WHERE prefix = 'harness' AND name = 'ready'");
typesense_search_state_cache_clear();
harness_check(
    'A6.37 a row another writer removed reads as the default once the cache is cleared',
    typesense_search_state_get('ready') === false && a6_rows('harness', 'ready') === 0
);
harness_check('A6.38 no statement failed on ' . harness_mode_name() . ' during the battery', count($HARNESS['sql_errors']) === 0, implode(' || ', $HARNESS['sql_errors']));

harness_finish('A6_state');
