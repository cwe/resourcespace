<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// H9 battery: the forms the SQLite driver only emulates, run on the fixture under both drivers. Core's refs for each
// form are recorded under MySQL and under SQLite side by side, and the battery fails where they differ unless the
// difference is a documented limit of the emulation. Every case carries the refs MySQL must return, derived by hand
// from the fixture and from InnoDB's rules (principle 11); under SQLite the same case expects what the emulation is
// documented to return. The side-by-side comparison runs in the MySQL mode, which starts a SQLite process for the
// other column; in the default mode the battery checks its own answers against the same table, so that the emulation
// cannot drift. Run it with .\battery.ps1 H9_mysql_parity -Db mysql, or .\run.ps1 -Db mysql.
require __DIR__ . '/boot.php';
require __DIR__ . '/fixture_lib.php';
fx_load('base');
fx_load('h9_parity');

$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$ACCENTS = 'accents are not emulated: SQLite compares text case-insensitively but not accent-insensitively, MySQL\'s collation does both';
$MODE = $HARNESS['mode'];

// InnoDB's default stop-word list (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD, MySQL 8), the shim's list to match.
$STOPWORDS = array('a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of',
    'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'und', 'www');

// label, form, search, refs MySQL returns (hand-derived), why; and where the emulation is documented to differ,
// 'sqlite' => the refs it returns and 'limit' => why. A search in "@FULL_TEXT:..." is the advanced search's full-text
// box ([QUOTES] stands for a quote). The session user sees every fixture resource but 9, 10, 11, 13 and 30.
$CASES = array(
    array('H9.1', 'trailing wildcard', 'sculpt*', array(1, 4),
        'full text +sculpt* over node names: the node "sculpture" on 1 and 4; "yorkshireSculpturePark" is one word starting yorkshire'),
    array('H9.2', 'leading wildcard', '*scape', array(23),
        'LIKE \'%scape\' on the keyword table: "landscape" on 23; 22 holds it in an inactive field'),
    array('H9.3', 'wildcard inside a word', 'sc*pture', array(1, 4, 5, 29),
        'full text +sc*pture: InnoDB reads +sc* as a prefix and pture as an optional term, so every word starting sc: sculpture (1, 4), schedule (5), scene (29)'),
    array('H9.4', 'the same, nothing real after the star', 'sc*zzzz', array(1, 4, 5, 29),
        'proves H9.3 is the prefix alone: what follows the star is not read'),
    array('H9.5', 'two-letter wildcard', 'sc*', array(1, 3, 4, 5, 29),
        'under three characters: LIKE \'sc%\' on the keyword table, which holds the partial-index fragments of field 51, so 3 (yorkshireSculpturePark.pdf) joins'),
    array('H9.6', 'OR of two wildcards with a semicolon', 'land*;sun*', array(1, 8, 12, 23, 36, 37),
        'one token with a semicolon takes the RLIKE branch, prefiltered by MATCH (+land*) (+sun*), two optional groups: words starting land (23) or sun (the sunsets)'),
    array('H9.7', 'the same in capitals', 'LAND*;SUN*', array(1, 8, 12, 23, 36, 37),
        'RLIKE and MATCH are case-insensitive under the _ci collation'),
    array('H9.8', 'full-text box, either of two words', '"@FULL_TEXT:harbour zeppelin"', array(1, 6, 32),
        'no operator: either word; 6 holds zeppelin past the 500th character, which the box reads (it matches node names whole)'),
    array('H9.9', 'full-text box, a quoted phrase', '"@FULL_TEXT:[QUOTES]red car[QUOTES]"', array(1),
        'the words adjacent: 1\'s caption "a red car parked"; 2 has "red sports car" and "car show with red"'),
    array('H9.10', 'full-text box, a phrase with a stop word', '"@FULL_TEXT:[QUOTES]sunset over the harbour[QUOTES]"', array(1),
        'InnoDB compares the phrase with the text, stop words included: 1\'s title'),
    array('H9.11', 'full-text box, a phrase with a short word', '"@FULL_TEXT:[QUOTES]8 mm film reel[QUOTES]"', array(21),
        'words under three characters are left out of both sides: 21\'s caption "8 mm film reel" and its title "Film reel"'),
    array('H9.12', 'full-text box, a stop word only', '"@FULL_TEXT:the"', array(),
        'a stop word is not indexed: nothing'),
    array('H9.13', 'full-text box, a hyphen', '"@FULL_TEXT:foo-bar"', array(),
        'InnoDB reads foo-bar as foo -bar (the hyphen starts an exclusion): every text with foo has bar'),
    array('H9.14', 'full-text box, a required word and an excluded one', '"@FULL_TEXT:+red -sports"', array(1, 2),
        'a node with red and not sports: 1\'s caption and 2\'s caption (2\'s title has sports)'),
    array('H9.15', 'quoted phrase', '"red car"', array(1),
        'core\'s phrase path on the keyword table (adjacent positions): 1\'s caption'),
    array('H9.16', 'quoted phrase with a stop word', '"sunset over the harbour"', array(1),
        'core\'s phrase path: the stop word is not indexed and holds its position'),
    array('H9.17', 'case-insensitive keyword', 'SUNSET', array(1, 8, 12, 36, 37),
        'keyword = ? under a _ci collation'),
    array('H9.18', 'case-insensitive option name', 'country:FRANCE', array(1, 4),
        'name = ? under a _ci collation finds the option France'),
    array('H9.19', 'accented value, unaccented keyword', 'zoe', array(40),
        'the keyword zoë equals zoe in MySQL\'s collation', 'sqlite' => array(), 'limit' => $ACCENTS),
    array('H9.20', 'accented value, accented keyword', "zo\u{eb}", array(40),
        'the same keyword, the same accents'),
    array('H9.21', 'accented value, unaccented prefix', 'cafe*', array(40),
        'full text +cafe* folds the accent', 'sqlite' => array(), 'limit' => $ACCENTS),
    array('H9.22', 'accented value, accented prefix', "caf\u{e9}*", array(40),
        'the same, the same accents'),
    array('H9.23', 'accented fixed-list option, unaccented name', 'country:zurich', array(40),
        'name = ? folds the accent', 'sqlite' => array(), 'limit' => $ACCENTS),
    array('H9.24', 'full-text box, accented value, unaccented word', '"@FULL_TEXT:zoe"', array(40),
        'the full-text index folds the accent', 'sqlite' => array(), 'limit' => $ACCENTS),
    array('H9.25', 'underscore inside a wildcard', 'dog_ph*', array(2),
        'an underscore is a word character: the word dog_photo of 2\'s file name'),
    array('H9.26', 'apostrophe, wildcard', 'brien*', array(25),
        'an apostrophe separates words: O\'Brien is o and brien'),
    array('H9.27', 'wildcard on a stop word', 'the*', array(),
        'a stop word in the probe takes the LIKE branch: no keyword starts with the'),
    array('H9.28', 'wildcard on a non-indexed field', 'zebra*', array(29),
        'full text reads node names, not the keyword index: field 96 is not flagged for indexing'),
    array('H9.29', 'wildcard on an inactive field', 'legac*', array(),
        'the value is in an inactive field, hidden from every union'),
);

/** One case under the driver in use, nothing printed: its sorted refs, total, the emulated forms it used and its SQL errors. */
function h9_record(array $case): array
{
    ob_start();
    $run = ab($case[0], $case[2]);
    $out = ob_get_clean();
    $refs = $run['core']['refs'];
    sort($refs);
    return array(
        'refs' => $refs,
        'total' => $run['core']['total'],
        'emulated' => preg_match('/core side emulated: ([^)]*)\)/', $out, $m) === 1 ? $m[1] : '',
        'errors' => preg_match_all('/core SQL did not run on [^:]*: ([^\n]*)/', $out, $e) > 0 ? $e[1] : array(),
    );
}

// The SQLite process the MySQL mode compares with: the same cases, silently, its answers on one line.
if (in_array('--dump', $argv, true)) {
    $dump = array();
    foreach ($CASES as $case) {
        $dump[$case[0]] = h9_record($case);
    }
    echo 'H9-DUMP ' . json_encode($dump, $J) . "\n";
    exit(0);
}

/** The other driver's answers, from a process that boots the SQLite driver on the same fixture; null when it fails. */
function h9_sqlite_side(): ?array
{
    $process = proc_open(
        array(PHP_BINARY, __FILE__, '--dump'),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        __DIR__,
        array_merge(getenv(), array('HARNESS_DB' => ''))
    );
    if (!is_resource($process)) {
        return null;
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($process);
    if (preg_match('/^H9-DUMP (.*)$/m', $out, $m) !== 1) {
        echo '    the SQLite process gave no answer: ' . substr(trim($out . $err), 0, 600) . "\n";
        return null;
    }
    return json_decode($m[1], true);
}

// ----------------------------------------------------------------------------------------- the full-text settings
if ($MODE === 'mysql') {
    $table = harness_run('SELECT DISTINCT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD', array());
    $words = array_column($table, 'value');
    $listed = $STOPWORDS;
    sort($words);
    sort($listed);
    harness_check(
        'H9.0 the server\'s InnoDB full-text settings are the plan\'s: tokens of 3 or more, the built-in stop words, no stop-word table of its own',
        (int)harness_scalar('SELECT @@innodb_ft_min_token_size') === 3 && (int)harness_scalar('SELECT @@innodb_ft_enable_stopword') === 1
            && in_array(harness_scalar('SELECT @@innodb_ft_server_stopword_table'), array(null, ''), true) && $words === $listed,
        harness_mysql_settings()
    );
} else {
    $words = array_column(harness_run('SELECT DISTINCT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD', array()), 'value');
    $listed = $STOPWORDS;
    sort($words);
    sort($listed);
    $shim = HARNESS_FT_STOPWORDS;
    sort($shim);
    harness_check(
        'H9.0 the emulation\'s stop words and token size are InnoDB\'s defaults (the list the MySQL mode checks the server against)',
        $words === $listed && $shim === $listed,
        count($listed) . ' words'
    );
}

// ----------------------------------------------------------------------------------------- core's answers
echo "\ndriver: " . harness_mode_name() . ($MODE === 'mysql' ? '' : ' (emulates full text and RLIKE; accents are not emulated)') . "\n";
$here = array();
foreach ($CASES as $case) {
    list($label, $form, $search, $truth, $why) = $case;
    $limit = $case['limit'] ?? null;
    $emulated = $MODE === 'sqlite' && $limit !== null;
    // Under a documented limit the answer is not the truth, so it is checked below rather than by the case's expect.
    $run = ab($label, $search, array(), $emulated ? array(
        'note' => $form . '; documented limit of the emulation: ' . $limit . '; MySQL returns ' . json_encode($truth) . ': ' . $why,
    ) : array(
        'expect' => $truth,
        'note' => $form . '; ' . $why,
    ));
    if ($emulated) {
        $refs = $run['core']['refs'];
        sort($refs);
        harness_check($label . ' the emulation returns its documented answer, ' . $form, $refs === $case['sqlite'], json_encode($refs));
    }
    $refs = $run['core']['refs'];
    sort($refs);
    $here[$label] = array('refs' => $refs, 'total' => $run['core']['total']);
}

// ----------------------------------------------------------------------------------------- side by side
if ($MODE !== 'mysql') {
    echo "\nThe side-by-side comparison with MySQL runs in the MySQL mode (.\\run.ps1 -Db mysql); the answers above are checked against the documented ones.\n";
    harness_finish('H9_mysql_parity');
}

$sqlite = h9_sqlite_side();
echo "\nside by side (refs core returns)\n";
echo sprintf("    %-6s %-52s %-22s %-22s %s\n", 'case', 'form', 'MySQL', 'SQLite (emulated)', '');
foreach ($CASES as $case) {
    $label = $case[0];
    $mysql = $here[$label];
    $other = $sqlite[$label] ?? null;
    $limit = $case['limit'] ?? null;
    if ($other === null) {
        harness_check($label . ' parity, ' . $case[1], false, 'no SQLite answer');
        continue;
    }
    $same = $mysql['refs'] === $other['refs'] && (int)$mysql['total'] === (int)$other['total'];
    $agrees_with_limit = $limit !== null && $other['refs'] === $case['sqlite'];
    echo sprintf(
        "    %-6s %-52s %-22s %-22s %s\n",
        $label,
        $case[1],
        json_encode($mysql['refs']),
        json_encode($other['refs']) . ($other['emulated'] !== '' ? ' *' : ''),
        $same ? 'same' : ($agrees_with_limit ? 'DIFFERENT, documented limit' : 'DIFFERENT')
    );
    if ($same && $limit !== null) {
        harness_check($label . ' parity, ' . $case[1], false, 'the documented limit no longer applies: SQLite and MySQL agree (' . json_encode($mysql['refs']) . ')');
    } elseif ($same) {
        harness_check($label . ' parity, ' . $case[1], count($other['errors']) === 0, 'both ' . json_encode($mysql['refs']) . ($other['emulated'] !== '' ? ' (SQLite emulated: ' . $other['emulated'] . ')' : ''));
    } elseif ($agrees_with_limit) {
        harness_check($label . ' parity, ' . $case[1], true, 'MySQL ' . json_encode($mysql['refs']) . ', SQLite ' . json_encode($other['refs']) . ' - documented limit: ' . $limit);
    } else {
        harness_check($label . ' parity, ' . $case[1], false, 'MySQL ' . json_encode($mysql['refs']) . ' (total ' . $mysql['total'] . '), SQLite ' . json_encode($other['refs']) . ' (total ' . $other['total'] . ')');
    }
}
echo "    * the SQLite answer used an emulated form\n";

harness_finish('H9_mysql_parity');
