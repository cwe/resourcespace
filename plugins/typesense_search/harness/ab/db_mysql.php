<?php

// The MySQL database driver (HARNESS_DB=mysql, H9): core's own database functions and SQL against a real MySQL 8,
// the tables made by core's check_db_structs() from dbstruct/ (core's and the plugin's). Nothing is translated or
// emulated, so no case is marked "core side emulated". Required by boot.php at global scope; db_sqlite.php is the
// default driver.
//
// The database is dropped and recreated at every boot, so each battery starts from the same empty tables; a second
// process that must share a battery's database (the A6 writers) sets HARNESS_MYSQL_KEEP=1 and finds it as it is.
//
// Environment (defaults reach the compose file's mysql service on its published port from the host; the php container
// gets the service name from compose.yaml): HARNESS_MYSQL_HOST (127.0.0.1), HARNESS_MYSQL_PORT (13306),
// HARNESS_MYSQL_USER, HARNESS_MYSQL_PASSWORD, HARNESS_MYSQL_DATABASE (all rs_harness, the throwaway defaults of
// the compose file).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------------------------
// Core's include/database_functions.php, loaded whole
// ---------------------------------------------------------------------------------------------
// The one file is read, three things are changed and it is loaded from the scratch directory: its own location
// (__DIR__, __FILE__) becomes the checkout's, every statement is offered to harness_sql_seen() (the statement log),
// and a statement that fails after core's retry with check_db_structs() is handed to harness_sql_failed() (the
// error capture) instead of errorhandler() and exit(). Everything else, ps_query's binding and fetching included,
// is core's.
$db_src = str_replace("\r\n", "\n", file_get_contents($ROOT . '/include/database_functions.php')); // a Windows checkout has CRLF
$db_src = str_replace('dirname(__DIR__)', var_export($ROOT, true), $db_src);
$db_src = str_replace(array('__DIR__', '__FILE__'), array(var_export($ROOT . '/include', true), var_export($ROOT . '/include/database_functions.php', true)), $db_src);
$db_src = str_replace("    \$error = null;\n\n    // Check cache for this query", "    \$error = null;\n    harness_sql_seen(\$sql);\n\n    // Check cache for this query", $db_src, $logged);
$db_src = str_replace('errorhandler(E_ERROR, $error, $errorfile, $errorline);', 'return harness_sql_failed($sql, $error);', $db_src, $captured);
if ($logged !== 1 || $captured !== 2) {
    throw new RuntimeException('include/database_functions.php has changed: the harness patch points were found ' . $logged . ' and ' . $captured . ' times');
}
file_put_contents($SCRATCH . '/db_real.php', $db_src);
require $SCRATCH . '/db_real.php';

/** Offered every statement by the patched ps_query(): kept while a battery logs SQL. */
function harness_sql_seen(string $sql): void
{
    global $HARNESS;

    if ($HARNESS['log_sql']) {
        $HARNESS['sql_log'][] = preg_replace('/\s+/', ' ', $sql);
    }
}

/** A statement MySQL refused even after check_db_structs(): recorded as the SQLite driver records it, no rows. */
function harness_sql_failed(string $sql, string $error): array
{
    global $HARNESS;

    global $prepared_statement_cache;

    // A statement that would not prepare stays cached as false, which the next call with it would try to bind.
    unset($prepared_statement_cache[$sql]);
    $sql = preg_replace('/\s+/', ' ', $sql);
    $error = preg_replace('/\s+/', ' ', $error);
    $HARNESS['sql_errors'][] = $error . (strpos($error, $sql) === false ? ' <<< ' . substr($sql, 0, 400) : '');
    return array();
}

/** One statement with core's type and value pairs, as the SQLite driver runs it: the rows, or none on an error. */
function harness_run(string $sql, array $parameters): array
{
    $rows = ps_query($sql, $parameters, '', -1, true, 0);
    return is_array($rows) ? $rows : array();
}

/** The MySQL server and the settings the full-text and collation behaviour depends on, for the battery's first line. */
function harness_mysql_settings(): string
{
    $rows = ps_query(
        "SELECT @@version AS version, @@innodb_ft_min_token_size AS min_token, @@innodb_ft_max_token_size AS max_token, @@innodb_ft_enable_stopword AS stopword,
            @@innodb_ft_server_stopword_table AS stopword_table, @@collation_database AS collation, @@character_set_connection AS connection_charset,
            @@collation_connection AS connection_collation, (SELECT COUNT(*) FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD) AS stopwords"
    );
    $s = $rows[0] ?? array();
    return 'MySQL ' . ($s['version'] ?? '?') . ': innodb_ft_min_token_size=' . ($s['min_token'] ?? '?') . ' innodb_ft_max_token_size=' . ($s['max_token'] ?? '?')
        . ' innodb_ft_enable_stopword=' . ($s['stopword'] ?? '?') . ' stopword_table=' . json_encode($s['stopword_table'] ?? null)
        . ' (default list: ' . ($s['stopwords'] ?? '?') . ' words) database collation=' . ($s['collation'] ?? '?')
        . ' connection=' . ($s['connection_charset'] ?? '?') . '/' . ($s['connection_collation'] ?? '?');
}

/**
 * Called by boot.php once core is loaded and the session context is set: empty the database (unless a second process
 * shares it), connect with core's sql_connect() and have core's check_db_structs() build the tables. A server that
 * cannot be reached skips the battery (exit 2) as a missing Typesense does.
 */
function harness_db_ready(): void
{
    global $HARNESS, $mysql_server, $mysql_server_port, $mysql_username, $mysql_password, $mysql_db, $plugins, $storagedir, $query_cache_enabled;

    // Set here, not at load: core's config.default.php assigns these when it is included.
    $mysql_server = getenv('HARNESS_MYSQL_HOST') ?: '127.0.0.1';
    $mysql_server_port = (int)(getenv('HARNESS_MYSQL_PORT') ?: 13306);
    $mysql_username = getenv('HARNESS_MYSQL_USER') ?: 'rs_harness';
    $mysql_password = getenv('HARNESS_MYSQL_PASSWORD') ?: 'rs_harness';
    $mysql_db = getenv('HARNESS_MYSQL_DATABASE') ?: 'rs_harness';
    $query_cache_enabled = false; // a cache file from another battery's database would answer for this one
    if (!is_dir($storagedir . '/tmp')) {
        mkdir($storagedir . '/tmp', 0777, true); // process locks (check_db_structs()), temporary files
    }
    $keep = getenv('HARNESS_MYSQL_KEEP') === '1';
    try {
        if (!$keep) {
            $admin = new mysqli($mysql_server, $mysql_username, $mysql_password, '', $mysql_server_port);
            $admin->query('DROP DATABASE IF EXISTS `' . $mysql_db . '`');
            $admin->query('CREATE DATABASE `' . $mysql_db . '`');
            $admin->close();
        }
        sql_connect();
    } catch (\Throwable $e) {
        echo 'SKIPPED: no MySQL on ' . $mysql_server . ':' . $mysql_server_port . ' (' . $e->getMessage() . ")\n";
        exit(2);
    }
    if (!$keep) {
        // The second pass adds the field<n> copy columns of the resource table, which core only adds to a table
        // that already exists.
        $plugins = array('typesense_search');
        check_db_structs();
        check_db_structs();
        $plugins = array();
        // check_db_structs() also loads the initial rows of a new installation (dbstruct/data_*.txt: the default
        // fields, resource types, nodes...). The fixture is the whole content and the SQLite driver starts empty.
        foreach (glob($GLOBALS['ROOT'] . '/dbstruct/data_*.txt') ?: array() as $file) {
            ps_query('TRUNCATE TABLE `' . substr(basename($file, '.txt'), 5) . '`');
        }
        // The fixture also writes the field<n> copies of the fields that core's default configuration does not join,
        // and the SQLite driver has columns for them.
        $existing = array_column(ps_query('SHOW COLUMNS FROM resource'), 'Field');
        foreach (array(3, 8, 12, 18, 51, 54) as $field) {
            if (!in_array('field' . $field, $existing, true)) {
                ps_query('ALTER TABLE resource ADD COLUMN field' . $field . ' VARCHAR(' . (int)$GLOBALS['resource_field_column_limit'] . ')');
            }
        }
        if (!empty($HARNESS['sql_errors'])) {
            echo "SQL errors while building the tables:\n  " . implode("\n  ", array_unique($HARNESS['sql_errors'])) . "\n";
            $HARNESS['sql_errors'] = array();
        }
        echo 'driver: ' . harness_mysql_settings() . "\n"; // not from a second process: A6 reads its first line
    }
}
