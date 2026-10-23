<?php

/**
 * typesense_search: text values as the keyword representations hold them.
 *
 * Core indexes a value in add_node_keyword_mappings(): each translation of a ~en:A~fr:B value on its own, the tags
 * of a formatted field stripped with <a> kept, the first $node_keyword_index_chars characters, then split_keywords()
 * (the tag parts of a formatted value turned into spaces, cleanse_string(), the partial index). The collection's
 * tokenizer does what cleanse_string() does to case, separators and symbols; this file does the rest, in core's
 * order, calling core's functions where they can be called. Stemming is the collection's (stem iff $stemming).
 */

/**
 * Remove what the tokenizer would keep or glue but cleanse_string() drops or splits on: format characters (a BOM,
 * a zero-width space, a word joiner) go; the soft hyphen, the curly quotes and the en dash core turns into a space
 * through their entity, every kind of white space, and the multi-byte members of $config_separators (which
 * token_separators cannot hold) become one plain space. Case, letters, digits and single-byte punctuation are
 * left to the tokenizer. Invalid UTF-8 is substituted as cleanse_string() substitutes it.
 *
 * @param string|null $value Stored value
 *
 * @return string
 */
function typesense_search_text_clean(?string $value): string
{
    global $config_separators;

    $value = (string)$value;
    if ($value === '') {
        return '';
    }
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = htmlspecialchars_decode(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES);
    }

    // &nbsp; &shy; &lsquo; &rsquo; &ldquo; &rdquo; &ndash; as cleanse_string() replaces them, then the separators
    // the schema drops (typesense_search_schema_token_separators() keeps single-byte ones only).
    $spaces = array("\u{A0}", "\u{AD}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}");
    foreach ((array)$config_separators as $separator) {
        if (strlen($separator) > 1) {
            $spaces[] = $separator;
        }
    }
    $value = str_replace($spaces, ' ', $value);
    $value = (string)preg_replace(array('/\p{Cf}+/u', '/[\s\p{Z}]+/u'), array('', ' '), $value);

    return trim_spaces($value);
}

/**
 * The first site of core's markup handling (add_node_keyword_mappings() on a formatted field): tags removed, <a> kept
 * so that the link's text and URL reach split_keywords(). Core caps the value after this step.
 *
 * @param string $value Value of a formatted (TinyMCE) field
 *
 * @return string
 */
function typesense_search_text_strip_tags(string $value): string
{
    return strip_tags($value, '<a>');
}

/**
 * The second site (split_keywords() indexing a formatted value): entities decoded, the permitted tags and <a href>
 * kept by core's own stripper, then every tag and attribute part turned into a space, which leaves the words of
 * the link's URL as keywords, as in core.
 *
 * @param string $value Value after typesense_search_text_strip_tags() and the cap
 *
 * @return string
 */
function typesense_search_text_strip_tag_parts(string $value): string
{
    global $permitted_html_tags, $permitted_html_attributes;

    $tags = array_merge(array('a'), (array)$permitted_html_tags);
    $attributes = array_merge(array('href'), (array)$permitted_html_attributes);
    $value = (string)strip_tags_and_attributes(html_entity_decode($value), $tags, $attributes);
    foreach ($tags as $tag) {
        $value = str_replace(array('<' . $tag . '>', '<' . $tag, '</' . $tag), ' ', $value);
    }
    foreach ($attributes as $attribute) {
        $value = str_replace($attribute . '=', ' ', $value);
    }

    return str_replace(array('>', '<', '='), ' ', $value);
}

/**
 * Both markup sites together, uncapped: the words of a formatted value as core indexes them.
 *
 * @param string|null $value Value of a formatted (TinyMCE) field
 *
 * @return string
 */
function typesense_search_text_strip_markup(?string $value): string
{
    return typesense_search_text_strip_tag_parts(typesense_search_text_strip_tags((string)$value));
}

/**
 * Every translation of a value, each indexed on its own as core does. Only a value starting with ~ is translated
 * (core's test); empty translations are dropped. A leading ~ with no language part at all is not a translation,
 * so the value is kept whole, as i18n_get_translated() displays it (core indexes nothing for it).
 *
 * @param string|null $value Stored value
 *
 * @return array
 */
function typesense_search_text_translations(?string $value): array
{
    $value = (string)$value;
    if (substr($value, 0, 1) !== '~') {
        return array($value);
    }
    $translations = i18n_get_translations($value);
    if (count($translations) === 0) {
        return array($value);
    }

    return array_values(array_filter($translations, function ($translation) {
        return $translation !== '';
    }));
}

/**
 * The first $node_keyword_index_chars characters, the only ones core turns into keywords.
 *
 * @param string|null $value Value, after the tags of a formatted field are stripped
 *
 * @return string
 */
function typesense_search_text_cap(?string $value): string
{
    global $node_keyword_index_chars;

    return mb_substr((string)$value, 0, (int)$node_keyword_index_chars);
}

/**
 * The keywords of a partially indexed value as core's add_partial_index() produces them through split_keywords():
 * each word and every substring of at least $partial_index_min_word_length characters of it, lower-cased, unique.
 *
 * @param string|null $value Value as typesense_search_text_indexable() returns it
 *
 * @return array
 */
function typesense_search_text_fragments(?string $value): array
{
    $fragments = array();
    foreach (split_keywords((string)$value, true, true) as $keyword) {
        $fragments[] = is_array($keyword) ? $keyword['keyword'] : $keyword;
    }

    return array_values(array_unique(array_filter($fragments, 'strlen')));
}

/**
 * A stored value as the keyword representations hold it: one string per translation, prepared in core's order
 * (tags stripped with <a> kept for a formatted field, the cap, the tag parts, then the characters the tokenizer
 * would not treat as cleanse_string() does). The tokens of each string are the keywords core indexes for it.
 * _ss takes the array; _s and _text take the strings joined by a space; _p takes the fragments of each.
 *
 * @param string|null $value Stored value
 * @param bool        $html  True for a formatted (TinyMCE) field
 *
 * @return array
 */
function typesense_search_text_indexable(?string $value, bool $html = false): array
{
    $strings = array();
    foreach (typesense_search_text_translations($value) as $translation) {
        if ($html) {
            $translation = typesense_search_text_strip_tags($translation);
        }
        $translation = typesense_search_text_cap($translation);
        if ($html) {
            $translation = typesense_search_text_strip_tag_parts($translation);
        }
        $translation = typesense_search_text_clean($translation);
        if ($translation !== '') {
            $strings[] = $translation;
        }
    }

    return $strings;
}
