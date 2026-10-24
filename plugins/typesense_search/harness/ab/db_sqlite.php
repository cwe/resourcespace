<?php

// The SQLite database driver (the default): core's SQL runs on an in-memory SQLite database built from dbstruct/ with
// the MySQL-only constructs translated. Required by boot.php at global scope; db_mysql.php is the other driver.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ---------------------------------------------------------------------------------------------
// Pieces of include/database_functions.php that do not touch the connection, copied verbatim
// ---------------------------------------------------------------------------------------------
$db_src = file_get_contents($ROOT . '/include/database_functions.php');

function harness_extract(string $src, string $signature): string
{
    $start = strpos($src, $signature);
    if ($start === false) {
        throw new RuntimeException('not found: ' . $signature);
    }
    $open = strpos($src, '{', $start);
    $depth = 0;
    for ($i = $open; $i < strlen($src); $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    throw new RuntimeException('unbalanced: ' . $signature);
}

$parts = "<?php\n";
foreach (
    array(
    'final class PreparedStatementQuery',
    'function sql_limit(',
    'function sql_limit_with_total_count(',
    'function ps_param_insert(',
    'function ps_param_fill(',
    'function columns_in(',
    ) as $sig
) {
    $parts .= harness_extract($db_src, $sig) . "\n\n";
}
// columns_in() locates dbstruct/ relative to its own file.
$parts = str_replace('dirname(__DIR__)', var_export($ROOT, true), $parts);
file_put_contents($SCRATCH . '/db_real_parts.php', $parts);
require $SCRATCH . '/db_real_parts.php';

// ---------------------------------------------------------------------------------------------
// SQLite standing in for MySQL
// ---------------------------------------------------------------------------------------------
// In memory unless HARNESS_DB names a file: a second process then boots on the same file (A6's two-writer case)
// and finds the tables there, so every CREATE is IF NOT EXISTS and a locked file is waited for.
$DB = new SQLite3(getenv('HARNESS_DB') ?: ':memory:');
$DB->enableExceptions(true);
$DB->busyTimeout(5000);
$DB->exec("ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA");
$DB->exec("CREATE TABLE INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD (value TEXT)");
foreach (array('a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','the','www') as $w) {
    $DB->exec("INSERT INTO INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD VALUES ('" . $w . "')");
}

$DB->createFunction('now', function () {
    return date('Y-m-d H:i:s');
}, 0);
$DB->createFunction('curdate', function () {
    return date('Y-m-d');
}, 0);
$DB->createFunction('rs_days_ago', function ($n) {
    return date('Y-m-d 00:00:00', strtotime('today -' . (int)$n . ' days'));
}, 1);
$DB->createFunction('LEAST', function (...$a) {
    return in_array(null, $a, true) ? null : min($a);
}, -1);
$DB->createFunction('CONCAT_WS', function ($sep, ...$a) {
    return implode($sep, array_filter($a, function ($x) {
        return $x !== null;
    }));
}, -1);
$DB->createFunction('CONCAT', function (...$a) {
    return in_array(null, $a, true) ? null : implode('', $a);
}, -1);
$DB->createFunction('UNIX_TIMESTAMP', function ($d = null) {
    return $d === null ? null : strtotime($d);
}, 1);
$DB->createFunction('LEFT', function ($s, $n) {
    return $s === null ? null : mb_substr((string)$s, 0, (int)$n);
}, 2);
$DB->createFunction('VERSION', function () {
    return '8.0.36';
}, 0);
$DB->createFunction('RAND', function () {
    return mt_rand() / mt_getrandmax();
}, 0);
// X REGEXP Y calls regexp(Y, X). MySQL's default collations are case-insensitive. EMULATION of RLIKE.
$DB->createFunction('regexp', function ($pattern, $value) {
    return $value === null ? null : (int)(preg_match('~' . str_replace('~', '\~', (string)$pattern) . '~iu', (string)$value) === 1);
}, 2);
$DB->createAggregate('BIT_OR', function ($ctx, $row, $v) {
    return ((int)$ctx) | ((int)$v);
}, function ($ctx) {
    return (int)$ctx;
}, 1);

/**
 * Emulation of MySQL InnoDB "MATCH(name) AGAINST (expr IN BOOLEAN MODE)" for the expressions core builds, as H9
 * measured InnoDB 8.4 with the default settings: words of three characters or more are indexed, the stop words are
 * not; a term is optional, +required or -excluded; "a phrase" is compared against the text with its stop words (and
 * without the words under three characters, on both sides); (a group) is one item, its terms optional unless
 * marked; a trailing * makes a prefix (the rest of a word after a * starts a new term); + and - start a term even
 * in the middle of a word; a required term that is a stop word or too short can never match; a stop word that is
 * optional or excluded is ignored. THIS IS AN EMULATION: H9's MySQL mode is the proof.
 */
function harness_ft_match($text, $expr)
{
    if ($text === null) {
        return 0;
    }
    $split = function ($string) {
        return preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower((string)$string), -1, PREG_SPLIT_NO_EMPTY);
    };
    $seq = array_values(array_filter($split($text), function ($w) {
        return mb_strlen($w) >= 3;
    }));
    $words = array_values(array_filter($seq, function ($w) {
        return !in_array($w, HARNESS_FT_STOPWORDS, true);
    }));
    $at = 0;
    $items = harness_ft_parse(trim((string)$expr, "'"), $at, $split);
    return (int)(harness_ft_eval($items, $seq, $words) === true);
}

const HARNESS_FT_STOPWORDS = array('a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in','is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und','www');

/** The items of a boolean-mode expression from $at on, up to the closing parenthesis of the group they are in. */
function harness_ft_parse(string $expr, int &$at, callable $split): array
{
    $items = array();
    $length = strlen($expr);
    $op = '';
    while ($at < $length) {
        $c = $expr[$at];
        if (ctype_space($c)) {
            $at++;
            $op = '';
        } elseif ($c === ')') {
            $at++;
            break;
        } elseif ($c === '+' || $c === '-') {
            $op = $c;
            $at++;
        } elseif ($c === '<' || $c === '>' || $c === '~') {
            $at++;
        } elseif ($c === '(') {
            $at++;
            $items[] = array('op' => $op, 'group' => harness_ft_parse($expr, $at, $split));
            $op = '';
        } elseif ($c === '"') {
            $close = strpos($expr, '"', $at + 1);
            $close = $close === false ? $length : $close;
            $items[] = array('op' => $op, 'phrase' => $split(substr($expr, $at + 1, $close - $at - 1)), 'prefix' => false);
            $at = $close + 1;
            $op = '';
        } else {
            $start = $at;
            while ($at < $length && !ctype_space($expr[$at]) && strpos('()"+-<>~', $expr[$at]) === false) {
                $at++;
                if ($expr[$at - 1] === '*') {
                    break;
                }
            }
            $word = substr($expr, $start, $at - $start);
            $prefix = substr($word, -1) === '*';
            $parts = $split($word);
            if (count($parts) > 0) {
                $items[] = array('op' => $op, 'phrase' => $parts, 'prefix' => $prefix);
            }
            $op = '';
        }
    }
    return $items;
}

/** One level of a parsed expression against a text: true, false, or null when every item was ignorable. */
function harness_ft_eval(array $items, array $seq, array $words): ?bool
{
    $required = false;
    $optional = false;
    $considered = 0;
    foreach ($items as $item) {
        if (isset($item['group'])) {
            $hit = harness_ft_eval($item['group'], $seq, $words);
        } elseif (count($item['phrase']) === 1) {
            $w = $item['phrase'][0];
            if ($item['prefix']) {
                $hit = false;
                foreach ($words as $candidate) {
                    $hit = $hit || strpos($candidate, $w) === 0;
                }
            } else {
                $hit = mb_strlen($w) < 3 || in_array($w, HARNESS_FT_STOPWORDS, true) ? null : in_array($w, $words, true);
            }
        } else {
            $phrase = array_values(array_filter($item['phrase'], function ($w) {
                return mb_strlen($w) >= 3;
            }));
            $hit = null;
            if (count($phrase) > 0) {
                $hit = strpos(' ' . implode(' ', $seq) . ' ', ' ' . implode(' ', $phrase) . ' ') !== false;
            }
        }
        if ($hit === null) {
            if ($item['op'] === '+') {
                return false;
            }
            continue;
        }
        $considered++;
        if ($item['op'] === '+') {
            $required = true;
            if (!$hit) {
                return false;
            }
        } elseif ($item['op'] === '-') {
            if ($hit) {
                return false;
            }
        } else {
            $optional = $optional || $hit;
        }
    }
    if ($considered === 0) {
        return null;
    }
    return $required || $optional;
}
$DB->createFunction('ft_match', 'harness_ft_match', 2);

// Tables from core's dbstruct/ and the plugin's, as CheckDBStruct() creates them: the PRI columns form the primary
// key (an auto_increment column alone, else the composite key of a link or state table), defaults are kept.
$table_files = array_merge(
    glob($ROOT . '/dbstruct/table_*.txt') ?: array(),
    glob($ROOT . '/plugins/typesense_search/dbstruct/table_*.txt') ?: array()
);
foreach ($table_files as $file) {
    $table = substr(basename($file, '.txt'), 6);
    $cols = array();
    $primary = array();
    $auto_increment = false;
    foreach (explode("\n", trim(file_get_contents($file))) as $line) {
        $c = str_getcsv($line, ',', '"', '\\');
        if (count($c) < 2 || $c[0] === '') {
            continue;
        }
        // MySQL's default collations compare text case-insensitively; NOCASE does so for ASCII (accents are not emulated).
        $type = stripos($c[1], 'int') !== false ? 'INTEGER'
            : (preg_match('/float|double|decimal/i', $c[1]) ? 'REAL' : 'TEXT COLLATE NOCASE');
        $def = '`' . $c[0] . '` ' . $type;
        if (($c[3] ?? '') === 'PRI' && stripos($c[5] ?? '', 'auto_increment') !== false) {
            $def .= ' PRIMARY KEY AUTOINCREMENT';
            $auto_increment = true;
        } else {
            if (($c[3] ?? '') === 'PRI') {
                $primary[] = '`' . $c[0] . '`';
            }
            if (isset($c[4]) && $c[4] !== '' && strtoupper($c[4]) !== 'NULL') {
                $def .= ' DEFAULT ' . (is_numeric($c[4]) || strtoupper($c[4]) === 'CURRENT_TIMESTAMP'
                    ? $c[4] : "'" . SQLite3::escapeString($c[4]) . "'");
            }
        }
        $cols[] = $def;
    }
    if (count($primary) > 0 && !$auto_increment) {
        $cols[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
    }
    if ($table === 'resource') {
        // The field<n> copy columns core adds for the title, date and sort fields.
        foreach (array(3, 8, 12, 18, 51, 54) as $f) {
            $cols[] = '`field' . $f . '` TEXT';
        }
    }
    $DB->exec('CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $cols) . ')');
}

/** Strip the parentheses MySQL allows around a UNIONed SELECT. */
function harness_unparen_union(string $sql): string
{
    $offset = 0;
    while (($pos = stripos($sql, 'UNION (', $offset)) !== false) {
        $open = $pos + 6;
        $depth = 0;
        for ($i = $open; $i < strlen($sql); $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    $sql = substr($sql, 0, $open) . ' ' . substr($sql, $open + 1, $i - $open - 1) . ' ' . substr($sql, $i + 1);
                    break;
                }
            }
        }
        $offset = $pos + 6;
    }
    return $sql;
}

/** MySQL to SQLite. Each emulated construct is recorded so the case can be marked. */
function harness_translate(string $sql): string
{
    global $HARNESS;

    $sql = preg_replace('/MATCH\s*\(\s*(?:[a-z_]+\.)?name\s*\)\s*AGAINST\s*\(\s*\?\s*IN BOOLEAN MODE\s*\)/i', 'ft_match(name, ?)', $sql, -1, $ft);
    if ($ft > 0) {
        $HARNESS['emulated']['full text'] = true;
    }
    $sql = preg_replace('/\bRLIKE\b/i', 'REGEXP', $sql, -1, $rlike);
    if ($rlike > 0) {
        $HARNESS['emulated']['RLIKE'] = true;
    }
    $sql = str_ireplace('(curdate() - interval ? DAY)', 'rs_days_ago(?)', $sql);
    // MySQL compares a string column with a numeric parameter numerically.
    $sql = preg_replace('/\b(rnn\d+)\.name\s*(>=|<=|=)\s*\?/', 'CAST($1.name AS REAL) $2 ?', $sql);
    $sql = preg_replace('/\bIF\(/', 'iif(', $sql);
    $sql = harness_unparen_union($sql);
    // MySQL "#" comments (search_public_collections()).
    $sql = preg_replace('/#[^\n]*/', '', $sql);
    // !last: MySQL resolves the inner "ORDER BY ref" to the selected r.ref; SQLite calls it ambiguous.
    $sql = str_replace(' ORDER BY ref DESC LIMIT ', ' ORDER BY r.ref DESC LIMIT ', $sql);
    // get_all_resource_types(): unqualified ORDER BY column is ambiguous to SQLite.
    if (stripos($sql, 'FROM resource_type rt') !== false) {
        $sql = preg_replace('/ORDER BY\s+order_by\s*,/i', 'ORDER BY rt.order_by,', $sql);
    }
    // MySQL's insert-or-update (the plugin's state table, A6): SQLite's target-less ON CONFLICT (3.35 or later)
    // fires on the primary key; VALUES(col) in the update list is excluded.col there.
    $upsert = stripos($sql, 'ON DUPLICATE KEY UPDATE');
    if ($upsert !== false) {
        $sql = substr($sql, 0, $upsert) . 'ON CONFLICT DO UPDATE SET'
            . preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', substr($sql, $upsert + 23));
    }
    $sql = preg_replace('/^(\s*)INSERT\s+IGNORE\s+INTO\b/i', '$1INSERT OR IGNORE INTO', $sql);
    return $sql;
}

function harness_run(string $sql, array $parameters): array
{
    global $DB, $HARNESS;

    // get_nodes(): MySQL-only translation expressions in the column list; answer it directly.
    if (preg_match('/FROM node\s+WHERE\s+(resource_type_field=\?|true)(.*?)AND\s+(parent IS NULL|parent = \?|TRUE)\s+ORDER BY/is', $sql, $m)) {
        $vals = array();
        for ($i = 1; $i < count($parameters); $i += 2) {
            $vals[] = $parameters[$i];
        }
        $vals = array_slice($vals, 7); // skip the 7 language parameters
        $where = array();
        $bind = array();
        if (stripos($m[1], 'resource_type_field') === 0) {
            $where[] = 'resource_type_field = ?';
            $bind[] = array_shift($vals);
        }
        if (stripos($m[2], 'LIKE') !== false) {
            $where[] = '`name` LIKE ?';
            $bind[] = array_shift($vals);
        } elseif (preg_match('/`name` = \?/', $m[2])) {
            $where[] = '`name` = ?';
            $bind[] = array_shift($vals);
        }
        if (stripos($m[3], 'IS NULL') !== false) {
            $where[] = 'parent IS NULL';
        } elseif (strpos($m[3], '?') !== false) {
            $where[] = 'parent = ?';
            $bind[] = array_shift($vals);
        }
        $stmt = $DB->prepare('SELECT *, name AS translated_name FROM node' . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY order_by, ref ASC');
        foreach ($bind as $i => $b) {
            $stmt->bindValue($i + 1, $b);
        }
        $res = $stmt->execute();
        $rows = array();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    $translated = harness_translate($sql);
    if ($HARNESS['log_sql']) {
        $HARNESS['sql_log'][] = preg_replace('/\s+/', ' ', $translated);
    }
    try {
        $stmt = $DB->prepare($translated);
        $n = 1;
        for ($i = 0; $i + 1 < count($parameters); $i += 2) {
            $type = $parameters[$i];
            $value = $parameters[$i + 1];
            if ($value === null) {
                $stmt->bindValue($n, null, SQLITE3_NULL);
            } elseif ($type === 'i') {
                $stmt->bindValue($n, (int)$value, SQLITE3_INTEGER);
            } elseif ($type === 'd') {
                $stmt->bindValue($n, (float)$value, SQLITE3_FLOAT);
            } else {
                $stmt->bindValue($n, (string)$value, SQLITE3_TEXT);
            }
            $n++;
        }
        $res = $stmt->execute();
        $rows = array();
        if ($res->numColumns() > 0) {
            while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
        }
        return $rows;
    } catch (\Throwable $e) {
        $HARNESS['sql_errors'][] = $e->getMessage() . ' <<< ' . substr(preg_replace('/\s+/', ' ', $translated), 0, 400);
        return array();
    }
}

function ps_query($sql, array $parameters = array(), $cache = "", $fetchrows = -1, $dbstruct = true, $logthis = 2, $reconnect = true, $fetch_specific_columns = false)
{
    return harness_run((string)$sql, $parameters);
}
function ps_value($query, $parameters, $default, $cache = "")
{
    $rows = harness_run((string)$query, $parameters);
    return (count($rows) === 0 || !array_key_exists('value', $rows[0]) || $rows[0]['value'] === null) ? $default : $rows[0]['value'];
}
function ps_array($query, $parameters = array(), $cache = "")
{
    $out = array();
    foreach (harness_run((string)$query, $parameters) as $row) {
        $out[] = $row['value'];
    }
    return $out;
}
function clear_query_cache($cache)
{
}
function sql_insert_id()
{
    return $GLOBALS['DB']->lastInsertRowID();
}
function db_begin_transaction($name)
{
}
function db_end_transaction($name)
{
}
function db_rollback_transaction($name)
{
}

/** The tables are already built; nothing to connect to. */
function harness_db_ready(): void
{
}
