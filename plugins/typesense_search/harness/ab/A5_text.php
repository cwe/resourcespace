<?php

// A5 battery: the text module. The invisible characters and multi-byte punctuation core's cleanse_string() handles
// and the tokenizer does not (a BOM, a non-breaking space, a word joiner, the soft hyphen, curly quotes), the two
// markup sites with the cap between them (<p>hello, a link whose URL words core indexes), every translation of a
// ~en:A~fr:B value and a phrase spanning the two, the 500-character cap, the fragment sets of the partial index -
// each against core's own function or the keyword table core's add_node_keyword_mappings() wrote for the same value
// in the fixture - and the prepared values of every text node of the fixture indexed into collections created from
// the schema module, where every keyword core holds finds the plugin's document and the glued forms do not.
require __DIR__ . '/fixture.php';

$J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

/** The keywords core indexed for a node: keyword => positions, from the keyword table the fixture wrote. */
function a5_core_keywords(int $node): array
{
    $keywords = array();
    $rows = harness_run('SELECT k.keyword, nk.position FROM node_keyword nk JOIN keyword k ON k.ref = nk.keyword WHERE nk.node = ? ORDER BY nk.position, k.keyword', array('i', $node));
    foreach ($rows as $row) {
        $keywords[(string)$row['keyword']][] = (int)$row['position'];
    }
    return $keywords;
}

/** The node holding a resource's value in a field. */
function a5_node(int $resource, int $field): int
{
    return (int)harness_run('SELECT n.ref AS value FROM node n JOIN resource_node rn ON rn.node = n.ref WHERE rn.resource = ? AND n.resource_type_field = ? ORDER BY n.ref LIMIT 1', array('i', $resource, 'i', $field))[0]['value'];
}

/** Core's splitter on the plugin's prepared text: what the tokenizer will see, less the stop words core never indexes. */
function a5_words(array $strings): array
{
    global $noadd;
    $words = array();
    foreach ($strings as $string) {
        foreach (split_keywords($string, true) as $word) {
            if ($word !== '' && !in_array($word, $noadd, true)) {
                $words[] = $word;
            }
        }
    }
    return array_values(array_unique($words));
}

/** Set equality; a numeric keyword comes back from the keyword table as an integer key, so everything is compared as text. */
function a5_same_set(array $a, array $b): bool
{
    $a = array_values(array_unique(array_map('strval', $a)));
    $b = array_values(array_unique(array_map('strval', $b)));
    sort($a, SORT_STRING);
    sort($b, SORT_STRING);
    return $a === $b;
}

harness_check(
    'A5.1 the loader requires the module',
    function_exists('typesense_search_text_clean') && function_exists('typesense_search_text_strip_markup')
        && function_exists('typesense_search_text_translations') && function_exists('typesense_search_text_cap')
        && function_exists('typesense_search_text_fragments') && function_exists('typesense_search_text_indexable')
);

// ----------------------------------------------------------------------------------------- clean()
$cleaning = array(
    'a BOM' => array("\u{FEFF}France", 'France'),
    'a non-breaking space' => array("United\u{A0}Kingdom", 'United Kingdom'),
    'a word joiner' => array("word\u{2060}joiner", 'wordjoiner'),
    'a zero-width space' => array("zero\u{200B}width", 'zerowidth'),
    'direction marks' => array("a\u{200E}b\u{200F}c\u{202A}d", 'abcd'),
    'a soft hyphen, a space as core\'s &shy; rule' => array("co\u{AD}operation", 'co operation'),
    'curly quotes and an en dash, spaces as core\'s entity rule' => array("don\u{2019}t \u{201C}quoted\u{201D} \u{2018}x\u{2019} a\u{2013}b", 'don t quoted x a b'),
    'CR and LF' => array("line one\r\nline two\nthree", 'line one line two three'),
    'runs of spaces collapse and the ends are trimmed' => array('  a   b  ', 'a b'),
    'case, accents and single-byte punctuation are left to the tokenizer' => array("Côte d'Ivoire (2024).jpg", "Côte d'Ivoire (2024).jpg"),
    'single-byte symbols are left to the tokenizer' => array('rock&roll c++ x#y 50% a=b', 'rock&roll c++ x#y 50% a=b'),
    'an em dash is not a separator and stays' => array("a\u{2014}b", "a\u{2014}b"),
    'NULL' => array(null, ''),
    'empty' => array('', ''),
);
foreach ($cleaning as $what => $case) {
    $actual = typesense_search_text_clean($case[0]);
    harness_check('A5.2 clean ' . $what, $actual === $case[1], $actual === $case[1] ? '' : 'got ' . json_encode($actual, $J));
    if ($case[0] !== null && $case[0] !== '') {
        // Core's own index-time splitter gives the same words from the cleaned value as from the stored one.
        $same = split_keywords($actual, true) === split_keywords($case[0], true);
        harness_check('A5.3 split_keywords() agrees on ' . $what, $same, $same ? '' : json_encode(split_keywords($actual, true), $J) . ' vs ' . json_encode(split_keywords($case[0], true), $J));
        harness_check('A5.4 clean is idempotent on ' . $what, typesense_search_text_clean($actual) === $actual);
    }
}

// Other white space: core keeps a tab or an em space inside the keyword (explode() splits on the plain space only),
// which is incidental; the plugin gives it its sensible meaning, a word break. Recorded as a difference.
foreach (array('a tab' => "tab\tsep", 'an em space' => "a\u{2003}b", 'an ideographic space' => "日本\u{3000}語", 'a line separator' => "a\u{2028}b") as $what => $value) {
    $actual = typesense_search_text_clean($value);
    $expected = preg_replace('/[\s\p{Z}]+/u', ' ', $value);
    $core = cleanse_string($value, false);
    harness_check('A5.5 ' . $what . ' becomes a word break (core keeps it inside the keyword: ' . json_encode($core, $J) . ')', $actual === $expected && strpos($core, ' ') === false, json_encode($actual, $J));
}

// The multi-byte members of $config_separators, which the schema cannot hold, become spaces here; the single-byte
// ones are left to the tokenizer.
$saved_separators = $config_separators;
$config_separators[] = "\u{2014}";
harness_check('A5.6 a multi-byte separator added to $config_separators becomes a space', typesense_search_text_clean("a\u{2014}b/c") === 'a b/c', json_encode(typesense_search_text_clean("a\u{2014}b/c"), $J));
$config_separators = $saved_separators;
harness_check('A5.7 the default multi-byte separators become spaces', typesense_search_text_clean("it\u{2019}s \u{201C}so\u{201D}") === 'it s so');

$invalid = "caf\xE9 au lait";
$actual = typesense_search_text_clean($invalid);
harness_check('A5.8 invalid UTF-8 is substituted so the value can be sent as JSON', mb_check_encoding($actual, 'UTF-8') && json_encode($actual) !== false && $actual === "caf\u{FFFD} au lait", json_encode($actual, $J));

// ----------------------------------------------------------------------------------------- translations()
$translations = array(
    'a translated value' => array('~en:Germany~fr:Allemagne', array('Germany', 'Allemagne')),
    'a plain value' => array('Sunset over the harbour', array('Sunset over the harbour')),
    'a tilde that does not start the value is not a translation (core\'s test)' => array('a~b', array('a~b')),
    'an empty translation is dropped' => array('~en:A~fr:', array('A')),
    'five-character language codes' => array('~en-US:A~fr:B', array('A', 'B')),
    'a part without a language code is dropped, as core drops it' => array('~en:A~:B', array('A')),
    'the first colon splits, later ones stay' => array('~en:Time: 10:30~fr:Heure', array('Time: 10:30', 'Heure')),
    'NULL' => array(null, array('')),
);
foreach ($translations as $what => $case) {
    $actual = typesense_search_text_translations($case[0]);
    harness_check('A5.9 translations ' . $what, $actual === $case[1], $actual === $case[1] ? '' : 'got ' . json_encode($actual, $J));
}
harness_check(
    'A5.10 the translated value gives what core\'s i18n_get_translations() gives',
    typesense_search_text_translations('~en:Germany~fr:Allemagne') === array_values(array_filter(i18n_get_translations('~en:Germany~fr:Allemagne')))
);
// Two documented differences: core's array_filter() also drops a translation "0"; a leading ~ with no language part
// gives core nothing to index (i18n_get_translations() is empty), where the plugin keeps the value whole as
// i18n_get_translated() displays it.
harness_check('A5.11 a translation "0" is kept (core\'s array_filter() drops it)', typesense_search_text_translations('~en:0~fr:zéro') === array('0', 'zéro') && array_values(array_filter(i18n_get_translations('~en:0~fr:zéro'))) === array('zéro'));
harness_check('A5.12 a leading ~ with no language part keeps the value whole (core indexes nothing: i18n_get_translations() gives ' . json_encode(i18n_get_translations('~approx. 1900')) . ')', typesense_search_text_translations('~approx. 1900') === array('~approx. 1900'));

// ----------------------------------------------------------------------------------------- cap()
harness_check('A5.13 the cap keeps the first 500 characters', typesense_search_text_cap(str_repeat('a', 600)) === str_repeat('a', 500));
harness_check('A5.14 the cap counts characters, not bytes', mb_strlen(typesense_search_text_cap(str_repeat('é', 600))) === 500);
harness_check('A5.15 a short value is whole', typesense_search_text_cap('Sunset') === 'Sunset' && typesense_search_text_cap(null) === '');
$saved_cap = $node_keyword_index_chars;
$node_keyword_index_chars = 10;
harness_check('A5.16 $node_keyword_index_chars is read at the call', typesense_search_text_cap('Sunset over the harbour') === 'Sunset ove');
$node_keyword_index_chars = $saved_cap;

// ----------------------------------------------------------------------------------------- fragments() against the fixture's partial index
foreach (array(1 => 'IMG_1234.jpg', 2 => 'dog_photo-1.jpg', 3 => 'yorkshireSculpturePark.pdf') as $resource => $filename) {
    $core = array_keys(a5_core_keywords(a5_node($resource, 51)));
    $plugin = typesense_search_text_fragments($filename);
    harness_check('A5.17 fragments of ' . $filename . ' are core\'s keywords for the node (' . count($core) . ')', a5_same_set($plugin, $core), json_encode(array('missing' => array_values(array_diff($core, $plugin)), 'extra' => array_values(array_diff($plugin, $core))), $J));
}
$plugin = typesense_search_text_fragments('IMG_1234.jpg');
harness_check('A5.18 fragments are the words and every substring of at least 3 characters, lower-cased, unique', $plugin === array('img', '1234', '123', '234', 'jpg'), json_encode($plugin));
harness_check('A5.19 a word shorter than the minimum is kept whole, without fragments', typesense_search_text_fragments('ab 1') === array('ab', '1'));
harness_check('A5.20 fragments do not cross a word boundary', typesense_search_text_fragments('abc def') === array('abc', 'def'));
harness_check('A5.21 ulpture is a fragment of yorkshireSculpturePark.pdf (H1.8)', in_array('ulpture', typesense_search_text_fragments('yorkshireSculpturePark.pdf'), true));
$saved_min = $partial_index_min_word_length;
$partial_index_min_word_length = 5;
$plugin = typesense_search_text_fragments('sculpture');
harness_check('A5.22 $partial_index_min_word_length is read at the call', count(preg_grep('/^.{1,4}$/u', $plugin)) === 0 && in_array('sculp', $plugin, true), json_encode($plugin));
$partial_index_min_word_length = $saved_min;
// Delegation inherits core's byte-length loop in add_partial_index(): a multi-byte word also yields fragments
// shorter than the minimum ("fé"). Not a rule of the plugin's; it goes when core's goes.
$plugin = typesense_search_text_fragments('café');
harness_check('A5.23 a multi-byte word gives exactly core\'s add_partial_index() set, byte-length quirk included', a5_same_set($plugin, array_column(add_partial_index(array('café')), 'keyword')) && in_array('fé', $plugin, true), json_encode($plugin, $J));
harness_check('A5.24 fragments of nothing', typesense_search_text_fragments('') === array() && typesense_search_text_fragments(null) === array());

// ----------------------------------------------------------------------------------------- markup: the two sites against the keyword table
$notes_node = a5_node(4, 92);
$notes = harness_run('SELECT name AS value FROM node WHERE ref = ?', array('i', $notes_node))[0]['value'];
$core = a5_core_keywords($notes_node);
harness_check('A5.25 core indexes the words of the link\'s URL in the fixture notes (' . json_encode($notes, $J) . ')', a5_same_set(array_keys($core), array('hello', 'world', 'https', 'example', 'com', 'gallery', 'art', 'galleries')), json_encode($core));
$prepared = typesense_search_text_indexable($notes, true);
harness_check('A5.26 the prepared notes are one string of the same words, tags and attribute names gone', count($prepared) === 1 && a5_same_set(a5_words($prepared), array_keys($core)) && stripos($prepared[0], 'strong') === false && stripos($prepared[0], 'href') === false, json_encode($prepared, $J));
harness_check('A5.27 <p>hello is hello, not phello', typesense_search_text_indexable('<p>hello', true) === array('hello') && typesense_search_text_strip_markup('<p>hello') === 'hello');
harness_check('A5.28 an entity in a formatted value is decoded, as core decodes it', typesense_search_text_indexable('Tom &amp; Jerry &lt; 3', true) === array('Tom & Jerry 3') && split_keywords('Tom &amp; Jerry &lt; 3', true, false, false, true) === array('tom', 'jerry', '3'));
harness_check('A5.29 a formatted value without tags passes through the sites unchanged', typesense_search_text_indexable('Plain notes', true) === array('Plain notes'));
$link = "<p>See <a href='https://example.com/path/page' title='Title words' class='c' onclick='x()'>the <em>gallery</em></a> &amp; more</p>";
$link_node = fx_node(92, $link);
$core = a5_core_keywords($link_node);
$prepared = typesense_search_text_indexable($link, true);
harness_check('A5.30 a link with attributes: the plugin\'s words are core\'s keywords (href and class values kept, title and onclick dropped)', a5_same_set(a5_words($prepared), array_keys($core)) && in_array('c', array_keys($core), true) && !in_array('title', array_keys($core), true), json_encode(array('core' => array_keys($core), 'plugin' => $prepared), $J));
harness_check('A5.31 strip_markup() alone is both sites uncapped', a5_same_set(a5_words(array(typesense_search_text_strip_markup($link))), array_keys($core)));
// A value wrapped in <...> in a plain field goes through core's html branch and loses every keyword; the plugin
// indexes its word (recorded as a difference).
$wrapped_node = fx_node(8, '<untitled>');
harness_check('A5.32 <untitled> in a plain field: core indexes nothing, the plugin keeps the value for the tokenizer', a5_core_keywords($wrapped_node) === array() && typesense_search_text_indexable('<untitled>') === array('<untitled>'));

// ----------------------------------------------------------------------------------------- the cap against the keyword table
$long_node = a5_node(6, 18);
$core = a5_core_keywords($long_node);
$prepared = typesense_search_text_indexable(harness_run('SELECT name AS value FROM node WHERE ref = ?', array('i', $long_node))[0]['value']);
harness_check('A5.33 the fixture\'s long caption: core has albatross and no zeppelin, the plugin\'s 500 characters the same', isset($core['albatross']) && !isset($core['zeppelin']) && count($prepared) === 1 && mb_strlen($prepared[0]) === 500 && strpos($prepared[0], 'albatross') === 0 && strpos($prepared[0], 'zeppelin') === false);
$filler = implode(' ', array_fill(0, 60, 'filler text'));
$translated_long = '~en:alpha ' . $filler . ' omega~fr:debut ' . $filler . ' fin';
$translated_node = fx_node(8, $translated_long);
$core = a5_core_keywords($translated_node);
$prepared = typesense_search_text_indexable($translated_long);
harness_check('A5.34 the cap applies to each translation: alpha and debut indexed, omega and fin not, in core and in the plugin', isset($core['alpha'], $core['debut']) && !isset($core['omega']) && !isset($core['fin']) && count($prepared) === 2 && mb_strlen($prepared[0]) === 500 && mb_strlen($prepared[1]) === 500 && a5_same_set(a5_words($prepared), array_keys($core)), json_encode(array_keys($core)));
// Core caps after the first markup site and before the second, so the <a href> markup counts toward the 500
// characters: a word that the cap cuts in core is cut here too.
$lead = trim(str_repeat('lorem ipsum ', 38));
$anchor = '<a href="https://example.com/x">link</a>';
$boundary_value = '<p>' . $lead . ' ' . $anchor . ' boundary words beyond</p>';
$site1 = strip_tags($boundary_value, '<a>');
$boundary_node = fx_node(92, $boundary_value);
$core = a5_core_keywords($boundary_node);
$prepared = typesense_search_text_indexable($boundary_value, true);
harness_check(
    'A5.35 the cap sits between the two markup sites: the words after the link fall as core cuts them (site 1 text ' . mb_strlen($site1) . ' chars, cut inside "' . mb_substr($site1, 495, 10) . '")',
    mb_strlen($site1) > 500 && mb_strlen($site1) < 520 && !isset($core['beyond']) && isset($core['link']) && a5_same_set(a5_words($prepared), array_keys($core)),
    json_encode(array('core' => array_values(array_diff(array_keys($core), array('lorem', 'ipsum'))), 'plugin' => array_values(array_diff(a5_words($prepared), array('lorem', 'ipsum')))), $J)
);

// ----------------------------------------------------------------------------------------- a phrase spanning two translations, in core
fx_resource(50, 1);
$phrase_node = fx_set(50, 8, '~en:red car~fr:voiture rouge');
$core = a5_core_keywords($phrase_node);
ksort($core);
harness_check('A5.36 core restarts the positions for each translation', $core === array('car' => array(1), 'red' => array(0), 'rouge' => array(1), 'voiture' => array(0)), json_encode($core));
fx_node(3, "\u{FEFF}Bosnia");
fx_node(3, "United\u{A0}States");
fx_node(3, "word\u{2060}joiner");
fx_node(3, "co\u{AD}operation");
fx_node(3, "don\u{2019}t worry");
fx_report_errors('adding the A5 nodes');
// Core's phrase rule is position + 1 on one node: a phrase inside one translation matches, one spanning the two
// does not - except when the restarted positions happen to line up ("red rouge"), which is incidental.
ab('A5.37', '"red car"', array(), array('expect' => array(1, 50), 'note' => 'inside the English translation, and in the caption of 1'));
ab('A5.38', '"voiture rouge"', array(), array('expect' => array(50), 'note' => 'inside the French translation'));
ab('A5.39', '"car voiture"', array(), array('expect' => array(), 'note' => 'spanning the two translations: positions restart'));
ab('A5.40', '"red rouge"', array(), array('expect' => array(50), 'note' => 'core quirk: red@0 rouge@1 on one node'));

// ----------------------------------------------------------------------------------------- the local Typesense
$generation = typesense_search_schema_generation(1);
foreach (typesense_search_schema_aliases(1) as $alias => $collection) {
    harness_ts('DELETE', '/aliases/' . rawurlencode($alias));
}
foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}
$created = true;
foreach ($generation as $definition) {
    list($status, $body) = harness_ts('POST', '/collections', $definition);
    $created = $created && $status === 201;
}
harness_check('A5.41 collections created from the schema module', $created);
$resources = $generation['resources']['name'];

$base = array(
    'title' => '', 'resource_type' => 1, 'archive' => 0, 'access' => 0, 'creation_date' => 1577872800, 'modified_date' => 1577959200,
    'date_field_sort' => '0:', 'nodes' => array(0), 'populated_field_ids' => array(0), 'hit_count' => 0, 'ur_hits' => 0, 'hits_ur' => 0, 'date_ref' => 0, 'rating_ur' => 0,
);
$text_types = array(FIELD_TYPE_TEXT_BOX_SINGLE_LINE, FIELD_TYPE_TEXT_BOX_MULTI_LINE, FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE, FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE, FIELD_TYPE_WARNING_MESSAGE);
$list_types = array(FIELD_TYPE_CHECK_BOX_LIST, FIELD_TYPE_DROP_DOWN_LIST, FIELD_TYPE_CATEGORY_TREE, FIELD_TYPE_DYNAMIC_KEYWORDS_LIST, FIELD_TYPE_RADIO_BUTTONS);
$fields = array();
foreach (harness_run('SELECT ref, type, partial_index, field_constraint FROM resource_type_field', array()) as $row) {
    $fields[(int)$row['ref']] = $row;
}
// One document per node of a text or fixed-list field: the node's prepared value in the field's pattern. The date
// and numeric fields are A4's and SE1's.
$docs = array();
$pattern = array();
foreach (harness_run('SELECT ref, resource_type_field, name FROM node ORDER BY ref', array()) as $row) {
    $field = $fields[(int)$row['resource_type_field']] ?? null;
    if ($field === null || (int)$field['field_constraint'] === 1 || (!in_array((int)$field['type'], $text_types, true) && !in_array((int)$field['type'], $list_types, true))) {
        continue;
    }
    $node = (int)$row['ref'];
    $type = (int)$field['type'];
    $prepared = typesense_search_text_indexable($row['name'], $type === FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE);
    $doc = array('id' => (string)$node, 'ref' => $node, 'ref_s' => (string)$node) + $base;
    $f = (int)$field['ref'];
    if (in_array($type, $list_types, true)) {
        $doc['field_' . $f . '_ss'] = $prepared;
        $pattern[$node] = 'field_' . $f . '_ss';
    } elseif ($type === FIELD_TYPE_TEXT_BOX_SINGLE_LINE || $type === FIELD_TYPE_WARNING_MESSAGE) {
        $doc['field_' . $f . '_s'] = implode(' ', $prepared);
        $pattern[$node] = 'field_' . $f . '_s';
    } else {
        $doc['field_' . $f . '_text'] = implode(' ', $prepared);
        $pattern[$node] = 'field_' . $f . '_text';
    }
    if ((int)$field['partial_index'] === 1) {
        $fragments = array();
        foreach ($prepared as $string) {
            $fragments = array_merge($fragments, typesense_search_text_fragments($string));
        }
        $doc['field_' . $f . '_p'] = array_values(array_unique($fragments));
        $pattern[$node] = 'field_' . $f . '_p';
    }
    $docs[$node] = $doc;
}
$lines = array();
foreach ($docs as $doc) {
    $lines[] = json_encode($doc, JSON_UNESCAPED_UNICODE);
}
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', implode("\n", $lines), true);
$accepted = substr_count((string)$body, '"success":true');
harness_check('A5.42 every text node of the fixture indexed in its pattern (' . count($docs) . ' documents, _s _text _ss _p)', $status === 200 && $accepted === count($docs), $accepted === count($docs) ? '' : $accepted . ' accepted; ' . substr((string)$body, 0, 300));

$hits = function (string $q, string $field) use ($resources) {
    list($status, $body) = harness_ts('GET', '/collections/' . rawurlencode($resources) . '/documents/search?' . http_build_query(array(
        'q' => $q, 'query_by' => $field, 'num_typos' => 0, 'prefix' => 'false', 'drop_tokens_threshold' => 0, 'split_join_tokens' => 'off', 'per_page' => 250, 'include_fields' => 'ref',
    )));
    $refs = array();
    foreach ($body['hits'] ?? array() as $hit) {
        $refs[] = (int)$hit['document']['ref'];
    }
    return array($status, $refs, (string)($body['message'] ?? ''));
};

// Every keyword core holds for a node finds the plugin's document for that node through the field's pattern.
$checked = 0;
$missed = array();
foreach ($docs as $node => $doc) {
    foreach (array_keys(a5_core_keywords($node)) as $keyword) {
        $checked++;
        list($status, $refs, $message) = $hits($keyword, $pattern[$node]);
        if ($status !== 200 || !in_array($node, $refs, true)) {
            $missed[] = $node . ':' . $pattern[$node] . ':' . $keyword . ($message !== '' ? ' (' . $message . ')' : '');
        }
    }
}
harness_check('A5.43 every keyword core indexed for the fixture\'s text nodes finds the plugin\'s document (' . $checked . ' keywords over ' . count($docs) . ' nodes)', count($missed) === 0, implode('; ', $missed));

// The forms the raw value would have given are not tokens: glued tag names and translation codes, the BOM.
$absent = array(
    array($notes_node, 'phello'), array($notes_node, 'p'), array($notes_node, 'strong'), array($notes_node, 'href'), array($notes_node, 'a'),
    array(204, 'en'), array(204, 'fr'), array(204, 'germanyfr'),
);
$found = array();
foreach ($absent as $case) {
    list($status, $refs) = $hits($case[1], $pattern[$case[0]]);
    if (in_array($case[0], $refs, true)) {
        $found[] = $case[0] . ':' . $case[1];
    }
}
harness_check('A5.44 tag names, attribute names and translation codes are not tokens; <p>hello is not phello', count($found) === 0, implode(', ', $found));

$option = function (string $name) {
    return (int)harness_run('SELECT ref AS value FROM node WHERE resource_type_field = 3 AND name = ?', array('s', $name))[0]['value'];
};
$bom = $option("\u{FEFF}Bosnia");
list($status, $refs) = $hits('bosnia', 'field_3_ss');
list($status2, $refs2) = $hits("\u{FEFF}bosnia", 'field_3_ss');
harness_check('A5.45 P33: the BOM option matches bosnia and not the BOM-prefixed token (the raw value would do the reverse)', in_array($bom, $refs, true) && !in_array($bom, $refs2, true), json_encode(array($refs, $refs2)));
list($status, $refs) = $hits('united', 'field_3_ss');
list($status2, $refs2) = $hits('unitedstates', 'field_3_ss');
harness_check('A5.46 the non-breaking space is a word break: united matches, unitedstates does not', in_array($option("United\u{A0}States"), $refs, true) && count($refs2) === 0);
list($status, $refs) = $hits('wordjoiner', 'field_3_ss');
list($status2, $refs2) = $hits('word', 'field_3_ss');
harness_check('A5.47 the word joiner is removed: wordjoiner matches as one word, as core\'s keyword', in_array($option("word\u{2060}joiner"), $refs, true) && !in_array($option("word\u{2060}joiner"), $refs2, true));
list($status, $refs) = $hits('operation', 'field_3_ss');
list($status2, $refs2) = $hits('cooperation', 'field_3_ss');
harness_check('A5.48 the soft hyphen is a word break as core\'s &shy; rule: operation matches, cooperation does not', in_array($option("co\u{AD}operation"), $refs, true) && count($refs2) === 0);
list($status, $refs) = $hits('don t', 'field_3_ss');
list($status2, $refs2) = $hits('dont', 'field_3_ss');
harness_check('A5.49 the curly apostrophe is a word break as core\'s &rsquo; rule: don t matches, dont does not', in_array($option("don\u{2019}t worry"), $refs, true) && count($refs2) === 0);

// The cap in the index: zeppelin past the 500th character of 6's caption is not a token; 32's title has it.
list($status, $refs) = $hits('zeppelin', 'field_18_text');
list($status2, $refs2) = $hits('zeppelin', 'field_8_s');
harness_check('A5.50 K10: zeppelin past the cap is not a token of 6\'s caption; the title of 32 has it', count($refs) === 0 && $refs2 === array(a5_node(32, 8)), json_encode(array($refs, $refs2)));

// A phrase across the two translations: on the one string _s holds it matches (core: no), on the string[] _ss
// holds it does not (adjacency does not cross array elements); the quirk match "red rouge" happens in neither.
$phrase_doc = array('id' => 'phrase', 'ref' => 999, 'ref_s' => '999', 'field_8_s' => implode(' ', typesense_search_text_indexable('~en:red car~fr:voiture rouge')), 'field_3_ss' => typesense_search_text_indexable('~en:red car~fr:voiture rouge')) + $base;
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', json_encode($phrase_doc), true);
harness_check('A5.51 the translated value as _s (joined) and as _ss (one element per translation) accepted', strpos((string)$body, '"success":true') !== false, (string)$body);
list($status, $s_span) = $hits('"car voiture"', 'field_8_s');
list($status, $ss_span) = $hits('"car voiture"', 'field_3_ss');
list($status, $s_quirk) = $hits('"red rouge"', 'field_8_s');
list($status, $ss_quirk) = $hits('"red rouge"', 'field_3_ss');
list($status, $ss_inside) = $hits('"voiture rouge"', 'field_3_ss');
harness_check('A5.52 a phrase spanning the translations matches the joined string and not the array (Typesense keeps adjacency inside one element)', in_array(999, $s_span, true) && !in_array(999, $ss_span, true) && in_array(999, $ss_inside, true), json_encode(array($s_span, $ss_span, $ss_inside)));
harness_check('A5.53 core\'s incidental "red rouge" match happens in neither shape', !in_array(999, $s_quirk, true) && !in_array(999, $ss_quirk, true));

// One document with every text pattern, built from the fixture's resource 1 and 4 values.
$every = array(
    'id' => 'every', 'ref' => 998, 'ref_s' => '998', 'title' => 'Sunset over the harbour',
    'field_8_s' => implode(' ', typesense_search_text_indexable('Sunset over the harbour')),
    'field_18_text' => implode(' ', typesense_search_text_indexable('A red car parked by the harbour wall at sunset')),
    'field_92_text' => implode(' ', typesense_search_text_indexable($notes, true)),
    'field_3_ss' => typesense_search_text_indexable('~en:Germany~fr:Allemagne'),
    'field_51_p' => typesense_search_text_fragments('IMG_1234.jpg'),
) + $base;
list($status, $body) = harness_ts('POST', '/collections/' . rawurlencode($resources) . '/documents/import?action=upsert&dirty_values=reject', json_encode($every), true);
harness_check('A5.54 one document with every text pattern accepted under dirty_values=reject', $status === 200 && strpos((string)$body, '"success":true') !== false, (string)$body);
list($status, $refs) = $hits('allemagne', 'field_3_ss');
list($status2, $refs2) = $hits('gallery', 'field_92_text');
list($status3, $refs3) = $hits('234', 'field_51_p');
harness_check('A5.55 its translation, URL word and fragment are tokens', in_array(998, $refs, true) && in_array(998, $refs2, true) && in_array(998, $refs3, true));

foreach (array_reverse($generation) as $definition) {
    harness_ts('DELETE', '/collections/' . rawurlencode($definition['name']));
}

harness_finish('A5_text');
