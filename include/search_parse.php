<?php

/**
 * Search string parsing for do_search().
 *
 * The search string is split into its parts (node tokens, special search identifier, keywords) before any SQL
 * is built. See search_context.php for the caller context and search_query.php for query assembly.
 */

/**
 * The search string as understood by do_search(). Readonly: keyword matching copies $keywords and $node_bucket to
 * the SearchQuery and adds to them there.
 *
 * A special search ("!" prefix, other than "!empty") keeps its identifier in $search for search_special(); only
 * the text after the identifier is split into $keywords.
 */
final class ParsedSearch
{
    /**
     * @param string $search              Search string, trimmed, with node tokens removed and a wildcard-only
     *                                    search converted to ""
     * @param array  $keywords            Keywords to match, after the dosearchmodifykeywords hook
     * @param array  $node_bucket         Node restrictions as [AND group] => [node refs to OR]
     * @param array  $node_bucket_not     Node refs whose resources are excluded
     * @param bool   $matches_resource_id True when the search is a single integer that is an existing resource ref
     */
    public function __construct(
        public readonly string $search,
        public readonly array $keywords,
        public readonly array $node_bucket,
        public readonly array $node_bucket_not,
        public readonly bool $matches_resource_id,
    ) {
    }
}

/**
 * Parse a search string into a ParsedSearch.
 *
 * Node tokens (@@123, @@!123) are removed from the string and placed in the node buckets, subject to field view
 * access. A special search identifier is separated from its parameters. The remaining text is split into
 * keywords, which plugins may change through the dosearchmodifykeywords hook.
 *
 * @param string $search Search string as provided by the caller
 *
 * @return ParsedSearch
 */
function search_parse(string $search): ParsedSearch
{
    if (!is_not_wildcard_only($search)) {
        $search = '';
        debug('do_search(): User searched only for "*". Converting this into an empty search instead.');
    }

    // IMPORTANT!
    // add to this array in the format [AND group]=array(<list of nodes> to OR)
    $node_bucket = array();
    // add to this normal array to exclude nodes from entire search
    $node_bucket_not = array();
    // Take the current search URL and extract any nodes (putting into buckets) removing terms from $search
    resolve_given_nodes($search, $node_bucket, $node_bucket_not);

    $matches_resource_id = false;
    // Used to check if ok to skip keyword due to a match with resource type/resource ID
    if (is_int_loose($search)) {
        // Resource ID is no longer indexed, if search is just for a single integer then include this
        $matches_resource_id = ps_value("SELECT COUNT(*) AS value FROM resource WHERE ref = ?", ["i",$search], 0) != 0;
    }

    # Extract search parameters and split to keywords.
    $is_special = substr($search, 0, 1) == "!" && substr($search, 0, 6) != "!empty";
    $search_params = $search;
    if ($is_special) {
        # Special search, discard the special search identifier when splitting keywords and extract the search parameters
        $s = strpos($search, " ");
        if ($s === false) {
            $search_params = ""; # No search specified
        } else {
            $search_params = substr($search, $s + 1); # Extract search params
        }
    }

    if ($search_params != "") {
        if (preg_match('/^[^\\s]+$/', $search)) {
            $keywords = [$search_params];
        } else {
            $keywords = split_keywords($search_params, false, false, false, false, true);
        }
    } else {
        $keywords = array();
    }

    $search = trim($search);
    $keywords = array_values(array_filter(array_unique($keywords), 'is_not_wildcard_only'));

    $modified_keywords = hook('dosearchmodifykeywords', '', array($keywords, $search));
    if ($modified_keywords) {
        $keywords = $modified_keywords;
    }

    return new ParsedSearch(
        $search,
        $keywords,
        $node_bucket,
        $node_bucket_not,
        $matches_resource_id
    );
}

// Take the current search URL and extract any nodes (putting into buckets) removing terms from $search
//
// Currently supports:
// @@!<node id> (NOT)
// @@<node id>@@<node id> (OR)
function resolve_given_nodes(&$search, &$node_bucket, &$node_bucket_not)
{

    // extract all of the words, a word being a bunch of tokens with optional NOTs
    if (preg_match_all('/(' . NODE_TOKEN_PREFIX . NODE_TOKEN_NOT . '*\d+)+/', $search, $words) === false || count($words[0]) == 0) {
        return;
    }

    // remove each word from the search string and split it into tokens
    $word_tokens = [];
    foreach ($words[0] as $word) {
        $search = preg_replace('/(, )?' . preg_quote($word, '/') . '\b/', '', $search); // remove the entire word and comma separator if found from the search string
        $search = trim(trim($search), ',');

        preg_match_all('/' . NODE_TOKEN_PREFIX . '(' . NODE_TOKEN_NOT . '*)(\d+)/', $word, $tokens);
        $word_tokens[] = $tokens;
    }

    // Field of each word's first node, looked up in one query; the user must be able to view that field
    $first_nodes = array_map(fn($tokens) => (int)$tokens[2][0], $word_tokens);
    $node_fields = array_column(
        ps_query("SELECT ref, resource_type_field FROM node WHERE ref IN (" . ps_param_insert(count($first_nodes)) . ")", ps_param_fill($first_nodes, "i")),
        "resource_type_field",
        "ref"
    );

    foreach ($word_tokens as $tokens) {
        if (!metadata_field_view_access((int)($node_fields[(int)$tokens[2][0]] ?? 0))) {
            continue;
        }

        if (count($tokens[1]) == 1 && $tokens[1][0] == NODE_TOKEN_NOT) {      // you are currently only allowed NOT condition for a single token within a single word
            $node_bucket_not[] = $tokens[2][0];       // add the node number to the node_bucket_not
            continue;
        }

        $node_bucket[] = $tokens[2];
    }
}
