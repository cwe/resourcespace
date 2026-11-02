<?php

/**
 * Keyword matching for do_search().
 *
 * search_query_keywords() walks the parsed keywords. Each keyword is classified once by search_keyword_classify()
 * into a Keyword value object and then handled by the function for its kind, which adds a KeywordUnion, a join or
 * a filter condition to the SearchQuery. Keywords that cannot be matched are recorded in SearchQuery::$unresolved
 * so that search_query_unresolved() can offer an alternative search.
 */

/**
 * What a keyword turned out to be once inspected.
 */
enum KeywordKind
{
    /** "[[fulltext]]..." quoted string: InnoDB full text match over node names */
    case Fulltext;
    /** field:value where the field is a date type, or basicyear/basicmonth/basicday from simple search */
    case Date;
    /** field:numrangeMIN|MAX on a text field */
    case NumRange;
    /** field:value resolved to one or more nodes of a fixed list field */
    case FixedList;
    /** "quoted string", optionally field specific: keywords must be adjacent in one node */
    case Phrase;
    /** A keyword, including the -omit, !empty and wildcard forms, optionally field specific */
    case Standard;
    /** Not a keyword itself: the tokens in Keyword::$requeue are processed instead */
    case Requeue;
    /** Nothing to match, for example a "!" prefixed term inside a special search */
    case Ignored;
}

/**
 * A classified keyword.
 */
final class Keyword
{
    /**
     * @param KeywordKind            $kind
     * @param int                    $index           Position in the keyword list, used for suggestions and aliases
     * @param string                 $text            Text to match: the field prefix is removed for field specific
     *                                                keywords; the full text string for Fulltext; the quoted
     *                                                string, including quotes and any "-" prefix, for Phrase
     * @param array|null             $field           ['ref' => int, 'type' => int] when field specific
     * @param string                 $field_name      Field short name when field specific
     * @param array                  $nodes           Node refs for FixedList
     * @param array                  $requeue         Tokens to process instead of this keyword (Requeue only)
     * @param PreparedStatementQuery $non_field_union UNION members matching a Standard keyword against resource ID,
     *                                                resource type name or contributor, appended to the keyword's
     *                                                union; empty when none of them match
     * @param bool                   $basic_date      True for the simple search parts basicyear, basicmonth and
     *                                                basicday, which $field resolves to the configured date field
     */
    public function __construct(
        public readonly KeywordKind $kind,
        public readonly int $index,
        public readonly string $text,
        public readonly ?array $field = null,
        public readonly string $field_name = '',
        public readonly array $nodes = [],
        public readonly array $requeue = [],
        public readonly PreparedStatementQuery $non_field_union = new PreparedStatementQuery(),
        public readonly bool $basic_date = false,
    ) {
    }

    /**
     * Whether the keyword matches a resource ID, resource type name or contributor. Such a keyword is still a
     * valid search when it matches no indexed keyword.
     */
    public function matchesNonField(): bool
    {
        return $this->non_field_union->sql !== '';
    }
}

/**
 * Pipeline step: add keyword matching to the query.
 *
 * Iterates SearchQuery::$keywords by index because keywords may be queued while it runs (a term containing a
 * colon that is not a field name, or a term containing configured separators, is split and its parts queued with
 * SearchQuery::addKeywords()). The index of a keyword identifies it in SearchQuery::$unresolved and in generated
 * SQL aliases.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query under construction; node_bucket receives nodes resolved from fixed list terms
 *
 * @return SearchStop|null A stop when the user may not search a field named in the search, or an !empty search
 *                         names no field
 */
function search_query_keywords(ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): ?SearchStop
{
    for ($n = 0; $n < count($query->keywords); $n++) {
        $keyword = search_keyword_classify($query->keywords[$n], $n, $parsed, $ctx);
        if ($keyword instanceof SearchStop) {
            return $keyword;
        }

        $stop = null;
        switch ($keyword->kind) {
            case KeywordKind::Fulltext:
                search_keyword_fulltext($keyword, $ctx, $query);
                break;
            case KeywordKind::Date:
                search_keyword_date($keyword, $ctx, $query);
                break;
            case KeywordKind::NumRange:
                search_keyword_numrange($keyword, $query);
                break;
            case KeywordKind::FixedList:
                $query->node_bucket[] = $keyword->nodes;
                break;
            case KeywordKind::Phrase:
                search_keyword_phrase($keyword, $ctx, $query);
                break;
            case KeywordKind::Standard:
                $stop = search_keyword_standard($keyword, $parsed, $ctx, $query);
                break;
            case KeywordKind::Requeue:
                $query->addKeywords($keyword->requeue);
                break;
            case KeywordKind::Ignored:
                break;
        }
        if ($stop !== null) {
            return $stop;
        }
    }

    // basicyear/basicmonth/basicday keywords share one join to the configured date field and one filter
    if ($query->basic_date_join !== null) {
        $date_string = sprintf(
            "%s-%s-%s",
            $query->basic_date_parts['year']  ?? '____',
            $query->basic_date_parts['month'] ?? '__',
            $query->basic_date_parts['day']   ?? '__'
        ) . '%';
        $query->andFilter("rdn" . $query->basic_date_join . ".name like ? ", ["s", $date_string]);
    }

    return null;
}

/**
 * Inspect a keyword and decide how it is matched.
 *
 * Handles the fulltext prefix, "field:" resolution (with field view access), the date / numeric range / fixed
 * list special forms, automatic quoting of field specific terms containing special characters, the
 * customsearchkeywordfilter hook and the non-field matches (resource ID, resource type name, contributor).
 *
 * @param string        $raw    Keyword as parsed
 * @param int           $index  Position in the keyword list
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context
 *
 * @return Keyword|SearchStop A stop (do_search() returns false) when the user may not search the named field
 */
function search_keyword_classify(string $raw, int $index, ParsedSearch $parsed, SearchContext $ctx): Keyword|SearchStop
{
    $keyword = preg_replace('/\*+/', '*', $raw);
    debug("do_search(): \$keyword = {$keyword}");
    $quoted_string = (substr($keyword, 0, 1) == "\""  || substr($keyword, 0, 2) == "-\"" ) && substr($keyword, -1, 1) == "\"";

    if ($quoted_string && substr($keyword, 1, strlen(FULLTEXT_SEARCH_PREFIX)) == FULLTEXT_SEARCH_PREFIX) {
        $fulltext_string = str_replace(FULLTEXT_SEARCH_QUOTES_PLACEHOLDER, "\"", substr($keyword, strlen(FULLTEXT_SEARCH_PREFIX) + 2, -1));
        if (strpos($fulltext_string, "@") !== false) {
            // There's an @ character in the fulltext search which InnoDB does not permit, so quote-wrap the search string
            $fulltext_string = "'\"" . $fulltext_string . "\"'";
        }
        return new Keyword(KeywordKind::Fulltext, $index, $fulltext_string);
    }
    // The non-field matches are made against the term as typed, before any field prefix is removed
    $non_field_text = $keyword;

    $kind = null;
    $field = null;
    $field_name = '';
    $keystring = '';
    $nodes = [];
    $basic_date = false;

    if (
        // If quoted string with a field specified we first need to try and resolve it to a node instead of working out keyword positions etc.
        (!$quoted_string || strpos($keyword, ":") !== false)
        && (substr($keyword, 0, 1) != "!" || substr($keyword, 0, 6) == "!empty")
    ) {
        if (strpos($keyword, ":") !== false) {
            [$field_name, $keystring] = explode(":", ($quoted_string ? substr($keyword, 1, -1) : $keyword), 2);
            debug("do_search(): \$fieldname = {$field_name}");
            debug("do_search(): \$keystring = {$keystring}");
            $field = search_keyword_field_info($field_name);
            if ($field === null && in_array($field_name, ['basicyear', 'basicmonth', 'basicday'])) {
                // Date parts from simple search: there is no field of that name, the configured date field is meant
                $field = search_keyword_field_info_by_ref($ctx->date_field);
                $basic_date = $field !== null;
            }
            if ($field === null) {
                // Search may just happen to include a colon - treat the colon as a space and process the parts separately
                return new Keyword(KeywordKind::Requeue, $index, $keyword, requeue: explode(":", $keyword));
            }
            if (!metadata_field_view_access($field['ref'])) {
                // User can't search against a metadata field they don't have access to
                return new SearchStop(false, 'no_field_access');
            }
        }

        if ($field !== null && !$quoted_string && !$ctx->ignore_filters && in_array($field['type'], $ctx->date_field_types)) {
            $kind = KeywordKind::Date;
        } elseif ($field !== null && substr($keystring, 0, 8) == "numrange" && !$quoted_string && !$ctx->ignore_filters && $field['type'] == 0) {
            // Text field numrange search ie mynumberfield:numrange1|1234 indicates that mynumberfield needs a numrange search for 1 to 1234.
            $kind = KeywordKind::NumRange;
        } elseif ($field !== null && !$ctx->ignore_filters && in_array($field['type'], $ctx->fixed_list_field_types)) {
            // Legacy fixed list field search (fieldShortName:keyword): resolve to nodes, as if @@NodeID had been given
            $nodes = search_keyword_fixed_list_nodes($field['ref'], $keystring);
            if ($nodes !== []) {
                $kind = KeywordKind::FixedList;
            }
        } elseif (
            !$quoted_string
            && substr($keyword, 0, 6) != "!empty"
            && preg_match(
                '/^                   # Start of the string
                [^-]                  # Not a negative keyword search
                (?!.*[\*;])           # Negative lookahead: Ensure no asterisk (*) or semi colon exists in the string
                (?=                   # Positive lookahead: Ensure at least one special character exists
                    [^ \t\n]*         # Any number of characters that are not space, tab, or newline
                    [!@#$%^&()_+\-=\[\]{}\'"\\,.<>\/?] # One special character from this set
                )
                [^ \t\n]*             # Match any number of characters that are not space, tab, or newline
                $                     # End of the string
                /x',
                $keyword
            ) === 1
        ) {
            // Quote keywords that contain special characters and no spaces unless a wildcard is present. An !empty
            // search names a field, whose short name may contain such characters, and is never a phrase.
            $keyword = "\"$keyword\"";
            $quoted_string = true;
        }

        if ($field !== null) {
            // Match the value within the named field
            $keyword = $keystring;
        }

        if ($kind === null && !$quoted_string && !($field !== null && hook('customsearchkeywordfilter', null, array([$field_name, $keystring])))) {
            $kind = KeywordKind::Standard;
        }
    }

    if ($kind === null) {
        $kind = $quoted_string ? KeywordKind::Phrase : KeywordKind::Ignored;
    }

    return new Keyword(
        $kind,
        $index,
        $keyword,
        $field,
        $field_name,
        $nodes,
        non_field_union: $kind === KeywordKind::Standard
            ? search_keyword_non_field($non_field_text, $parsed, $ctx)
            : new PreparedStatementQuery(),
        basic_date: $basic_date
    );
}

/**
 * UNION members matching a keyword against data that is not indexed as metadata: the resource ID when the whole
 * search is one integer that is a resource ref, a resource type name, and (with $index_contributed_by) the
 * username of a contributor.
 *
 * @param string        $keyword Keyword as typed, including any field prefix
 * @param ParsedSearch  $parsed  Parsed search
 * @param SearchContext $ctx     Search context
 *
 * @return PreparedStatementQuery UNION clauses starting with " UNION ", or empty when nothing matches
 */
function search_keyword_non_field(string $keyword, ParsedSearch $parsed, SearchContext $ctx): PreparedStatementQuery
{
    $non_field_union = new PreparedStatementQuery();
    if ($keyword == $parsed->search && is_int_loose($keyword) && $parsed->matches_resource_id) {
        // Resource ID is no longer indexed, if search is just for a single integer then include this
        $non_field_union->sql .= " UNION (SELECT " . (int)$keyword . " AS resource, [bit_or_condition] 1 AS score)";
    } elseif (in_array(mb_strtolower($keyword), array_map("mb_strtolower", array_column($ctx->resource_types, "name")))) {
        // Resource type is no longer actually indexed but this will still honour the config by including in search
        $non_field_union->sql .= " UNION (SELECT r.ref AS resource, [bit_or_condition] 1 AS score FROM resource r LEFT JOIN resource_type rt ON r.resource_type=rt.ref WHERE r.ref > 0 AND rt.name LIKE ?)";
        array_push($non_field_union->parameters, "s", $keyword);
    }
    if ($ctx->index_contributed_by) {
        // Contributor is no longer actually indexed but this will still honour the config by including in search
        $user_sql = get_users(0, $keyword, "u.username", true, -1, "", true);
        if (count(ps_query($user_sql->sql, $user_sql->parameters)) > 0) {
            $non_field_union->sql .= " UNION ( SELECT r.ref AS resource, [bit_or_condition] 1 AS score FROM resource r"
                . " WHERE r.created_by IN (SELECT u.ref FROM (" . $user_sql->sql . " ) as u) AND r.ref >0 )";
            $non_field_union->parameters = array_merge($non_field_union->parameters, $user_sql->parameters);
        }
    }
    return $non_field_union;
}

/**
 * Look up a field by short name for a "field:value" keyword. The query uses the "schema" query cache, which is
 * cleared when fields change, so no further caching is done here.
 *
 * @param string $field_name Field short name
 *
 * @return array|null ['ref' => int, 'type' => int], or null when no such field exists
 */
function search_keyword_field_info(string $field_name): ?array
{
    $fieldinfo = ps_query("SELECT ref, `type` FROM resource_type_field WHERE name = ?", ["s", $field_name], "schema");
    return count($fieldinfo) > 0 ? $fieldinfo[0] : null;
}

/**
 * Look up a field by ref, in the same shape as search_keyword_field_info().
 *
 * @param int $field_ref Field ref
 *
 * @return array|null ['ref' => int, 'type' => int], or null when no such field exists
 */
function search_keyword_field_info_by_ref(int $field_ref): ?array
{
    $fieldinfo = ps_query("SELECT ref, `type` FROM resource_type_field WHERE ref = ?", ["i", $field_ref], "schema");
    return count($fieldinfo) > 0 ? $fieldinfo[0] : null;
}

/**
 * Resolve the value(s) of a legacy "fixedlistfield:value;value" keyword to node refs by name.
 *
 * @param int    $field_ref Fixed list field
 * @param string $keystring Value, or ";" separated values to OR
 *
 * @return array Node refs; empty when no value names a node
 */
function search_keyword_fixed_list_nodes(int $field_ref, string $keystring): array
{
    $field_nodes = get_nodes($field_ref, null, false, true);
    $node_names = array_map('i18n_get_translated', array_map('mb_strtolower', array_column($field_nodes, 'name')));

    $nodes = [];
    foreach (explode(';', $keystring) as $keyword_expanded) {
        debug("keyword_expanded:  " . $keyword_expanded);
        $field_node_index = array_search(mb_strtolower(i18n_get_translated($keyword_expanded)), $node_names);
        if (false !== $field_node_index) {
            $nodes[] = $field_nodes[$field_node_index]['ref'];
        }
    }
    return $nodes;
}

/**
 * Fulltext keyword: match node names with an InnoDB full text search.
 *
 * @param Keyword       $keyword Keyword; text is the full text search string
 * @param SearchContext $ctx     Search context
 * @param SearchQuery   $query   Query under construction
 */
function search_keyword_fulltext(Keyword $keyword, SearchContext $ctx, SearchQuery $query): void
{
    $union = new PreparedStatementQuery();
    $union->sql = " SELECT resource, [bit_or_condition] 1 AS score FROM resource_node rn LEFT JOIN node n ON n.ref=rn.node WHERE MATCH(name) AGAINST (? IN BOOLEAN MODE)";
    $union->parameters = ["s", $keyword->text];
    if (count($ctx->hidden_indexed_fields) > 0) {
        $union->sql .= " AND n.resource_type_field NOT IN (" . ps_param_insert(count($ctx->hidden_indexed_fields)) . ")";
        $union->parameters = array_merge($union->parameters, ps_param_fill($ctx->hidden_indexed_fields, "i"));
    }
    $query->addUnion(new KeywordUnion($union, false));
}

/**
 * Date field keyword: field:YYYY-MM-DD (prefix match), field:rangestartYYYY-MM-DDendYYYY-MM-DD, or the simple
 * search parts basicyear/basicmonth/basicday which are combined into one filter on the configured date field.
 *
 * @param Keyword       $keyword Keyword; text is the value
 * @param SearchContext $ctx     Search context
 * @param SearchQuery   $query   Query under construction; basic_date_join and basic_date_parts are shared between
 *                               the basicyear/basicmonth/basicday keywords of one search
 */
function search_keyword_date(Keyword $keyword, SearchContext $ctx, SearchQuery $query): void
{
    $fieldname = $keyword->field_name;
    $keystring = $keyword->text;
    $fieldinfo = $keyword->field;
    // basicyear/basicmonth/basicday, resolved to the configured date field by search_keyword_classify()
    $basic_date = $keyword->basic_date;

    if (!$basic_date && substr($keystring, 0, 5) != "range") {
        $c = ++$query->join_count;
        $datefield = $fieldinfo['ref'];

        $val = str_replace("n", "_", $keystring);
        $val = str_replace("|", "-", $val);

        if ($fieldinfo['type'] == FIELD_TYPE_DATE_RANGE) {
            // Find where the searched value is between the range values
            $query->addJoin(
                " JOIN resource_node drrn" . $c . "s ON drrn" . $c . "s.resource=r.ref JOIN node drn" . $c . "s ON drn" . $c . "s.ref=drrn" . $c . "s.node AND drn" . $c . "s.resource_type_field = ? AND drn" . $c . "s.name >= ? JOIN resource_node drrn" . $c . "e ON drrn" . $c . "e.resource=r.ref JOIN node drn" . $c . "e ON drn" . $c . "e.ref=drrn" . $c . "e.node AND drn" . $c . "e.resource_type_field = ? AND DATE(drn" . $c . "e.name) <= ?",
                ["i", $datefield, "i", $datefield, "s", $val, "s", $val]
            );
        } else {
            $query->addJoin(
                " JOIN resource_node rnd" . $c . " ON rnd" . $c . ".resource=r.ref JOIN node dn" . $c . " ON dn" . $c . ".ref=rnd" . $c . ".node AND dn" . $c . ".resource_type_field = ?",
                ["i", $datefield]
            );
            $query->andFilter("dn" . $c . ".name like ?", ["s", $val . "%"]);
        }
    } elseif ($basic_date) {
        $c = ++$query->join_count;
        if ($query->basic_date_join === null) {
            // We only want to join once to the date_field
            $query->addJoin(
                " JOIN resource_node rdnf" . $c . " ON rdnf" . $c . ".resource=r.ref JOIN node rdn" . $c . " ON rdnf" . $c  . ".node=rdn" . $c . ".ref AND rdn" . $c . ".resource_type_field = ?",
                ["i", $ctx->date_field]
            );
            $query->basic_date_join = $c;
        }
        $query->basic_date_parts[substr($fieldname, 5)] = $keystring;   // day, month or year
    } else {
        # Additional date range filtering
        $c = ++$query->join_count;
        $rangestring = substr($keystring, 5);
        if (strpos($rangestring, "start") !== false) {
            $rangestartpos = strpos($rangestring, "start") + 5;
            $rangestart = str_replace(" ", "-", substr($rangestring, $rangestartpos, strpos($rangestring, "end") ? strpos($rangestring, "end") - $rangestartpos : 10));
            // Need to check for a date greater than the start date
            $query->addJoin(
                " JOIN resource_node drrn" . $c . "s ON drrn" . $c . "s.resource=r.ref JOIN node drn" . $c . "s ON drn" . $c . "s.ref=drrn" . $c . "s.node AND drn" . $c . "s.resource_type_field = ? AND drn" . $c . "s.name>= ? ",
                ["i", $fieldinfo['ref'], "s", $rangestart]
            );
        }
        if (strpos($keystring, "end") !== false) {
            $rangeend = str_replace(" ", "-", $rangestring);
            $rangeend = substr($rangeend, strpos($rangeend, "end") + 3, 10) . " 23:59:59";
            // Need to check for a date earlier than the end date
            $query->addJoin(
                " JOIN resource_node drrn" . $c . "e ON drrn" . $c . "e.resource=r.ref JOIN node drn" . $c . "e ON drn" . $c . "e.ref=drrn" . $c . "e.node AND drn" . $c . "e.resource_type_field = ? AND drn" . $c . "e.name <= ? ",
                ["i", $fieldinfo['ref'], "s", $rangeend]
            );
        }
    }
}

/**
 * Numeric range keyword on a text field: field:numrangeMIN|MAX ("neg" prefix for negative numbers). With only one
 * bound given the field must equal it.
 *
 * @param Keyword     $keyword Keyword; text is "numrange..." value
 * @param SearchQuery $query   Query under construction
 */
function search_keyword_numrange(Keyword $keyword, SearchQuery $query): void
{
    $c = ++$query->join_count;
    $rangefield = $keyword->field['ref'];
    $minmax = explode("|", substr($keyword->text, 8));
    $min = str_replace("neg", "-", $minmax[0]);
    $max = isset($minmax[1]) ? str_replace("neg", "-", $minmax[1]) : '';
    if ($max != '' || $min != '') {
        // At least the min or max should be set
        if ($max == '' || $min == '') {
            // if only one number is entered, do a direct search
            $query->andFilter("rnn" . $c . ".name = ? ", ["d", max($min, $max)]);
        } else {
            // else use min and max values as a range search
            $query->andFilter("rnn" . $c . ".name >= ? ", ["d", $min]);
            $query->andFilter("rnn" . $c . ".name <= ? ", ["d", $max]);
        }
    }

    $query->addJoin(
        " JOIN resource_node rrn" . $c . " ON rrn" . $c . ".resource=r.ref LEFT JOIN node rnn" . $c . " ON rnn" . $c . ".ref=rrn" . $c . ".node AND rnn" . $c . ".resource_type_field = ?",
        ["i", $rangefield]
    );
}

/**
 * Standard keyword: -omit, !empty, wildcard and plain forms, optionally restricted to a field.
 *
 * Resolves the keyword (with stemming and alternatives such as "red;blue"), queues the parts of a keyword
 * containing configured separators, records an unmatched plain keyword for suggestions, and otherwise adds the
 * union or filter for the keyword.
 *
 * @param Keyword       $keyword Keyword
 * @param ParsedSearch  $parsed  Parsed search
 * @param SearchContext $ctx     Search context
 * @param SearchQuery   $query   Query under construction
 *
 * @return SearchStop|null A stop (do_search() returns false) for an !empty search that names no field or a field
 *                         the user cannot see
 */
function search_keyword_standard(Keyword $keyword, ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): ?SearchStop
{
    $text = $keyword->text;
    $keywords_expanded = explode(';', $text);
    $keywords_expanded_or = count($keywords_expanded) > 1;

    # Omit resources containing this keyword?
    $omit = false;
    if (substr($text, 0, 1) == "-") {
        $omit = true;
        $text = substr($text, 1);
    }

    # Search for resources with an empty field, ex: !empty18  or  !emptycaption
    $empty = false;
    $nodatafield = null;
    if (substr($text, 0, 6) == "!empty") {
        $nodatafield = str_replace("!empty", "", $text);
        if (!is_numeric($nodatafield)) {
            $nodatafield = ps_value("SELECT ref value FROM resource_type_field WHERE name = ?", ["s", $nodatafield], "", "schema");
        }
        if ($nodatafield == "" || !is_numeric($nodatafield)) {
            debug("do_search(): !empty search names no field: {$text}");
            return new SearchStop(false, 'invalid_empty_search');
        }
        $empty = true;
    }

    if (in_array($text, $ctx->noadd)) { # skip common words that are excluded from indexing
        debug("do_search(): skipped common word: {$text}");
        return null;
    }

    $wildcards = false;
    if (strpos($text, "*") !== false || $ctx->wildcard_always_applied) {
        if ($ctx->wildcard_always_applied && strpos($text, "*") === false) {
            # Suffix asterisk if none supplied and using $wildcard_always_applied mode.
            $text = $text . "*";
        }
        $wildcards = true;
    }

    # Resolve keyword. Ignore any wildcards when resolving. We need wildcards to be present later but not here.
    $keyref = resolve_keyword(str_replace('*', '', $text), false, true, true);
    if ($keyref === false && $ctx->stemming) {
        // Attempt to find match for original keyword
        $keyref = resolve_keyword(str_replace('*', '', $text), false, true, false);
    }
    if ($keyref === false && !$empty && !$keywords_expanded_or && strpos($text, "*") === false && preg_match('/\\s/', $parsed->search) !== 1) {
        // Check keyword for defined separators and if found each part of the value is added as a keyword for checking.
        // Not for wildcards with no spaces, nor for an !empty search whose field name may contain a separator
        foreach ($ctx->separators as $separator) {
            if (strpos($text, $separator) !== false) {
                $keyword_split = split_keywords($text);
                if ($keyword->field !== null) {
                    $keyword_split = array_map(prefix_value($keyword->field_name . ":"), $keyword_split);
                }
                $query->addKeywords($keyword_split);
                return null;
            }
        }
    }

    if ($keyref === false && !$omit && !$empty && !$wildcards && $keyword->field === null && !$keyword->matchesNonField()) {
        // No match: suggest dropping the word, or a word that sounds like it
        $soundex = resolve_soundex($text);
        $query->unresolved[$keyword->index] = ($soundex === false) ? "" : "<i>" . $soundex . "</i>";
        return null;
    }

    $alternative_keywords_sql = search_keyword_alternatives($keywords_expanded_or ? $keywords_expanded : []);
    if ($keyref === false) {
        # make a new keyword
        $keyref = resolve_keyword(str_replace('*', '', $text), true, true, false);
    }
    # Key match, add to query.
    $query->join_count++;
    $relatedsql = search_keyword_related($keyref, $text, $keyword->field, $ctx);
    $exclude_fields = hook("excludefieldsfromkeywordsearch");

    if ($omit) {
        # Exclude matching resources from query (omit feature): check that keyword does not exist via resource_node->node_keyword relationship
        $query->andFilter(
            "`r`.`ref` NOT IN (SELECT `resource` FROM `resource_node` JOIN `node_keyword` ON `resource_node`.`node`=`node_keyword`.`node`" .
                " WHERE `resource_node`.`resource`=`r`.`ref` AND `node_keyword`.`keyword` = ?)",
            ["i", $keyref]
        );
        return null;
    }

    // These restrictions apply to !empty searches as well as normal keyword searches
    $restriction = search_keyword_restriction($exclude_fields, $ctx, $keyword->field['ref'] ?? null, $wildcards);

    if ($empty) {
        return search_keyword_empty((int)$nodatafield, $exclude_fields, $ctx, $query);
    }
    if ($wildcards) {
        search_keyword_wildcard($text, $restriction, $keyword->non_field_union, $query);
        return null;
    }

    // ----- resource_node -> node_keyword sub query -----
    $union = new PreparedStatementQuery();
    $union->sql = " SELECT resource, [bit_or_condition] hit_count AS score"
        . " FROM resource_node rn[union_index]"
        . " WHERE rn[union_index].node IN (SELECT node FROM `node_keyword` nk[union_index]"
        . " WHERE ((nk[union_index].keyword = ? " . $relatedsql->sql . ") " . $restriction->sql . ")"
        . ($alternative_keywords_sql->sql != "" ? $alternative_keywords_sql->sql . $restriction->sql : "")
        . ") GROUP BY resource "
        . $keyword->non_field_union->sql;

    $union->parameters = array_merge(["i", $keyref], $relatedsql->parameters, $restriction->parameters);
    if ($alternative_keywords_sql->sql != "") {
        $union->parameters = array_merge($union->parameters, $alternative_keywords_sql->parameters, $restriction->parameters);
    }
    if ($keyword->non_field_union->sql != "") {
        $union->parameters = array_merge($union->parameters, $keyword->non_field_union->parameters);
    }

    // Alternatives ("red;blue") are OR'd with neighbouring alternative keywords
    $query->addUnion(new KeywordUnion($union, $keywords_expanded_or));

    if ($ctx->stats_logging && !$ctx->go) {
        $query->keywords_used[] = $keyref;
    }
    return null;
}

/**
 * Build the "OR nk.keyword IN (...)" clause for the alternatives of a "red;blue" keyword.
 *
 * @param array $alternatives Alternative keyword texts; empty when the keyword has no alternatives
 *
 * @return PreparedStatementQuery Empty when no alternative resolves to a keyword
 */
function search_keyword_alternatives(array $alternatives): PreparedStatementQuery
{
    $sql = new PreparedStatementQuery();
    $keyrefs = [];
    foreach ($alternatives as $alternative) {
        $keyref = resolve_keyword($alternative, false, true, true);
        if ($keyref !== false) {
            $keyrefs[] = $keyref;
        }
    }
    if (count($keyrefs) > 0) {
        $sql->sql = " OR nk[union_index].keyword IN (" . ps_param_insert(count($keyrefs)) . ")";
        $sql->parameters = ps_param_fill($keyrefs, "i");
        debug("do_search(): \$alternative_keywords_sql = {$sql->sql}, parameters = " . implode(",", $sql->parameters));
    }
    return $sql;
}

/**
 * Build the "OR (nk.keyword IN (...))" clause matching keywords related to the one searched. With stemming, the
 * stems of keywords related to the unstemmed keyword are included.
 *
 * @param int           $keyref  Resolved keyword
 * @param string        $text    Keyword text (may contain wildcards)
 * @param array|null    $field   Field restriction ['ref' => , 'type' => ], if any
 * @param SearchContext $ctx     Search context
 *
 * @return PreparedStatementQuery Empty when there are no related keywords
 */
function search_keyword_related(int $keyref, string $text, ?array $field, SearchContext $ctx): PreparedStatementQuery
{
    $relatedsql = new PreparedStatementQuery();
    $related = get_related_keywords($keyref);
    if ($ctx->stemming) {
        # Need to ensure we include related keywords for original string
        $original_keyref = resolve_keyword(str_replace('*', '', $text), false, true, false);
        if ($original_keyref && $original_keyref !== $keyref) {
            $original_related = get_related_keywords($original_keyref);
            if (count($original_related) > 0) {
                $original_related_kws = ps_array("SELECT keyword AS `value` FROM keyword WHERE ref IN (" . ps_param_insert(count($original_related)) . ")", ps_param_fill($original_related, "i"));
                $extra_related = array();
                foreach ($original_related_kws as $orig_related_kw) {
                    $extrakeyword = GetStem(trim($orig_related_kw));
                    $extra_related[] = resolve_keyword($extrakeyword, true, false, false);
                }
                $related = array_merge($related, $extra_related);
            }
        }
    }
    if (count($related) > 0) {
        $relatedsql->sql .= " OR (nk[union_index].keyword IN (" . ps_param_insert(count($related)) . ")";
        $relatedsql->parameters = array_merge($relatedsql->parameters, ps_param_fill($related, "i"));
        if ($field !== null) {
            $relatedsql->sql .= " AND nk[union_index].node IN (SELECT ref FROM node WHERE resource_type_field = ? )";
            $relatedsql->parameters[] = "i";
            $relatedsql->parameters[] = $field['ref'];
        }
        $relatedsql->sql .= ")";
    }
    return $relatedsql;
}

/**
 * Build the clause restricting a keyword union to fields the user may search: fields excluded by the
 * excludefieldsfromkeywordsearch hook, hidden indexed fields, and the field named in a field specific keyword.
 *
 * Wildcard unions select from node, so the clause refers to resource_type_field directly; other unions select
 * from a node_keyword alias (nk[union_index] for a standard keyword, nk_[union_index]_N for the Nth word of a
 * phrase) and restrict its node with a sub query.
 *
 * @param mixed         $exclude_fields Field refs returned by the excludefieldsfromkeywordsearch hook (false when none)
 * @param SearchContext $ctx            Search context
 * @param int|null      $field_ref      Field named in the keyword, if any
 * @param bool          $wildcards      True for a wildcard union
 * @param string        $node_alias     node_keyword alias whose node is restricted; ignored for a wildcard union
 *
 * @return PreparedStatementQuery Clause starting with " AND ", or empty
 */
function search_keyword_restriction(
    $exclude_fields,
    SearchContext $ctx,
    ?int $field_ref,
    bool $wildcards,
    string $node_alias = "nk[union_index]"
): PreparedStatementQuery {
    $clause = new PreparedStatementQuery();
    if (!empty($exclude_fields)) {
        if ($wildcards) {
            $clause->sql .= " AND resource_type_field NOT IN (" . ps_param_insert(count($exclude_fields)) . ")";
        } else {
            $clause->sql .= " AND " . $node_alias . ".node NOT IN (SELECT ref FROM node WHERE resource_type_field IN (" . ps_param_insert(count($exclude_fields)) . "))";
        }
        $clause->parameters = array_merge($clause->parameters, ps_param_fill($exclude_fields, "i"));
    }
    if (count($ctx->hidden_indexed_fields) > 0) {
        if ($wildcards) {
            $clause->sql .= " AND resource_type_field NOT IN (" . ps_param_insert(count($ctx->hidden_indexed_fields)) . ")";
        } else {
            $clause->sql .= " AND " . $node_alias . ".node NOT IN (SELECT ref FROM node WHERE node.resource_type_field IN (" . ps_param_insert(count($ctx->hidden_indexed_fields)) . "))";
        }
        $clause->parameters = array_merge($clause->parameters, ps_param_fill($ctx->hidden_indexed_fields, "i"));
    }
    if ($field_ref !== null) {
        // Search is looking for a keyword in a specified field
        if ($wildcards) {
            $clause->sql .= " AND resource_type_field = ?";
        } else {
            $clause->sql .= " AND " . $node_alias . ".node IN (SELECT ref FROM node WHERE node.resource_type_field = ?)";
        }
        $clause->parameters = array_merge($clause->parameters, ["i", $field_ref]);
    }
    return $clause;
}

/**
 * !empty keyword: resources with no value in the given field, within the searched resource types.
 *
 * @param int           $nodatafield    Field ref
 * @param mixed         $exclude_fields Field refs returned by the excludefieldsfromkeywordsearch hook (false when none)
 * @param SearchContext $ctx            Search context
 * @param SearchQuery   $query          Query under construction
 *
 * @return SearchStop|null A stop (do_search() returns false) when the user cannot see the field or it does not exist
 */
function search_keyword_empty(int $nodatafield, $exclude_fields, SearchContext $ctx, SearchQuery $query): ?SearchStop
{
    // First check user can see this field
    $skipfields = array_merge(is_array($exclude_fields) ? $exclude_fields : [], $ctx->hidden_indexed_fields);
    if (in_array($nodatafield, $skipfields)) {
        return new SearchStop(false, 'no_field_access');
    }

    $selectedrestypes = [];
    $restypes_requested = trim($ctx->restypes);
    if ($restypes_requested != "") {
        $selectedrestypes = explode(",", $restypes_requested);
    }

    $nodatafieldinfo = get_resource_type_field($nodatafield);
    if (!$nodatafieldinfo) {
        debug("do_search(): !empty search for unknown field {$nodatafield}");
        return new SearchStop(false, 'empty_field_not_found');
    }
    $nodatarestypes = trim((string)$nodatafieldinfo["resource_types"]);
    $nodatarestypes = ($nodatarestypes != "") ? explode(",", $nodatarestypes) : [];

    $restypesql = new PreparedStatementQuery();
    if ($nodatafieldinfo["global"] === 1) {
        // Global field empty search
        // Candidate resources are those which exist in selected resource types
        if (count($selectedrestypes) > 0) {
            $restypesql->sql = " AND r[union_index].resource_type IN (" . ps_param_insert(count($selectedrestypes)) . ") ";
            $restypesql->parameters = ps_param_fill($selectedrestypes, "i");
        }
    } else {
        // Non-global field empty search
        // Candidate resources are those whose resource type is linked to the field and which exists in selected resource types
        if (count($selectedrestypes) > 0) {
            $candidaterestypes = array_intersect($nodatarestypes, $selectedrestypes);
        } else {
            $candidaterestypes = $nodatarestypes;
        }
        if (count($candidaterestypes) > 0) {
            $restypesql->sql = " AND r[union_index].resource_type IN (" . ps_param_insert(count($candidaterestypes)) . ") ";
            $restypesql->parameters = ps_param_fill($candidaterestypes, "i");
        } else {
            // No searched resource type can hold the field, so no resource has a value in it: every candidate matches
            $restypesql->sql = " AND 0 ";
        }
    }

    // Check that nodes are empty
    $union = new PreparedStatementQuery();
    $union->sql = "SELECT ref AS resource, [bit_or_condition] 1 AS score FROM resource r[union_index]"
        . " WHERE r[union_index].ref NOT IN ("
        . " SELECT rn.resource FROM node n RIGHT JOIN resource_node rn ON rn.node=n.ref"
        . " WHERE n.resource_type_field = ? " . $restypesql->sql
        . " GROUP BY rn.resource )";
    $union->parameters = array_merge(["i", $nodatafield], $restypesql->parameters);
    $query->addUnion(new KeywordUnion($union, false));
    return null;
}

/**
 * Wildcard keyword: matched against node names with a full text search where possible, otherwise a LIKE on the
 * keyword table or a word-boundary RLIKE on node names.
 *
 * @param string                 $text            Keyword with "*" wildcards
 * @param PreparedStatementQuery $restriction     Field restriction clause from search_keyword_restriction()
 * @param PreparedStatementQuery $non_field_union Non-field UNION members from the Keyword
 * @param SearchQuery            $query           Query under construction
 */
function search_keyword_wildcard(string $text, PreparedStatementQuery $restriction, PreparedStatementQuery $non_field_union, SearchQuery $query): void
{
    $keyword = $text;
    $union = new PreparedStatementQuery();
    preg_match_all('/
                                (?=             # Positive lookahead
                                    [a-zA-Z0-9] # Match a single character that is a letter or digit
                                )
                                [a-zA-Z0-9*]{3,}   # Match a group of 3 or more characters that are letters, digits, or asterisks
                                /x', $keyword, $matches);
    $stop_words = search_keyword_stop_words($matches[0]);

    if (
        substr($keyword, 0, 1) != "*"
        && strlen(trim($keyword, '*')) >= 3
        && preg_match('/[-+@<>().;]/', $keyword) !== 1
        && empty($stop_words)
    ) {
        // Use fulltext search as a preference, but not if
        // there is a leading wildcard
        // or the search is too short
        // or the search contains any of +,-,@,<,>,(,),.;
        // or contains any words InnoDB considers a stop word
        $union->sql = " SELECT resource, [bit_or_condition] hit_count AS score"
            . " FROM resource_node rn[union_index]"
            . " WHERE rn[union_index].node IN (SELECT ref FROM `node` WHERE MATCH(name) AGAINST (? IN BOOLEAN MODE) "
            . $restriction->sql . ") GROUP BY resource "
            . $non_field_union->sql;
        $keyword = "+" . $keyword;
    } elseif (preg_match('/\W/', str_replace("*", "", $keyword)) !== 1) {
        // Use a LIKE search, unless there is any whitespace
        $keyword = str_replace("*", "%", $keyword);
        $union->sql = " SELECT resource, [bit_or_condition] hit_count AS score"
            . " FROM resource_node rn[union_index]"
            . " WHERE rn[union_index].node IN (SELECT n.ref FROM keyword k"
            . " JOIN node_keyword nk ON nk.keyword=k.ref JOIN `node` n ON n.ref=nk.node"
            . " WHERE k.keyword LIKE ? " . $restriction->sql . ") GROUP BY resource "
            . $non_field_union->sql;
    } else {
        // Use RLIKE to search between word boundaries in the node names
        $union->sql = "SELECT resource, [bit_or_condition] SUM(hit_count) AS score"
            . " FROM resource_node rn[union_index]"
            . " WHERE rn[union_index].node IN (SELECT ref FROM `node` WHERE ";

        // Full text matching is used to reduce the rows checked by regex where possible
        $pattern = '/
                                        (
                                        [a-zA-Z0-9_] # First character cannot be a wildcard
                                        [a-zA-Z0-9_*]{2,} # Must be three characters long for full text min length
                                        )
                                        /x';
        $boolean_terms = [];
        foreach (explode(";", $keyword) as $or_part) {
            $or_part = trim($or_part);
            if (empty($or_part)) {
                // At least one part of the OR terms lacks enough words to prefilter
                break;
            }
            $and_matches = [];
            if (preg_match_all($pattern, $or_part, $matches)) {
                foreach (array_unique($matches[1]) as $match) {
                    if (preg_match('/[a-zA-Z0-9_]{3,}/', $match) && !in_array($match, $stop_words)) {
                        $and_matches[] = '+' . $match; // + means required
                    }
                }
            }
            if (!empty($and_matches)) {
                $boolean_terms[] = '(' . implode(" ", $and_matches) . ')';
            }
        }
        if (!empty($boolean_terms)) {
            $union->sql .= "MATCH(name) AGAINST (? IN BOOLEAN MODE) AND ";
            $union->parameters = array_merge(['s', implode(' ', $boolean_terms)], $union->parameters);
        }

        $union->sql .= "name RLIKE ? " . $restriction->sql . ") GROUP BY resource " . $non_field_union->sql;
        $keyword = prepare_regex_search_string($keyword);
    }

    $union->parameters = array_merge($union->parameters, ["s", $keyword], $restriction->parameters);
    if ($non_field_union->sql != "") {
        $union->parameters = array_merge($union->parameters, $non_field_union->parameters);
    }
    $query->addUnion(new KeywordUnion($union, false));
}

/**
 * InnoDB full text stop words matching any of the given wildcard patterns. A full text search for a stop word
 * returns nothing, so wildcard keywords containing one use a LIKE or RLIKE search instead.
 *
 * The stop word list is fixed for the server, so it is read once per process.
 *
 * @param array $patterns Letters, digits and "*" wildcards, as matched in the keyword
 *
 * @return array Matching stop words; empty when $patterns is empty
 */
function search_keyword_stop_words(array $patterns): array
{
    static $stop_words = null;
    if ($patterns === []) {
        return [];
    }
    if ($stop_words === null) {
        $stop_words = ps_array("SELECT value FROM INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD", []);
    }
    $regexes = array_map(fn($pattern) => '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i', $patterns);
    return array_values(array_filter($stop_words, function ($word) use ($regexes) {
        foreach ($regexes as $regex) {
            if (preg_match($regex, $word)) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Phrase keyword: a quoted string whose words must appear in sequence within one node (skipping words that are
 * excluded from indexing). With a "-" prefix, resources containing the phrase are excluded instead.
 *
 * @param Keyword       $keyword Keyword; text is the quoted string, or the unquoted value for a field specific phrase
 * @param SearchContext $ctx     Search context
 * @param SearchQuery   $query   Query under construction
 */
function search_keyword_phrase(Keyword $keyword, SearchContext $ctx, SearchQuery $query): void
{
    $text = $keyword->text;
    $quotedfieldid = null;
    if ($keyword->field !== null) {
        // We have already parsed the keyword when looking for a node, get string and then filter on this field
        $quotedkeywords = split_keywords($text);
        $quotedfieldid = (int)$keyword->field['ref'];
    } else {
        $quotedkeywords = split_keywords(substr($text, 1, -1));
    }
    $omit = substr($text, 0, 1) == "-";
    $exclude_fields = hook("excludefieldsfromkeywordsearch");

    $qk = 1; // Set the counter to the first keyword
    $last_key_offset = 1;
    $fixedunion = new PreparedStatementQuery();
    $fixedunioncondition = new PreparedStatementQuery();
    foreach ($quotedkeywords as $quotedkeyword) {
        if (in_array($quotedkeyword, $ctx->noadd)) { # skip common words that are excluded from indexing
            # Support skipped keywords - if the last keyword was skipped (listed in $noadd), increase the allowed position from the previous keyword. Useful for quoted searches that contain $noadd words, e.g. "black and white" where "and" is a skipped keyword.
            ++$last_key_offset;
            continue;
        }
        $keyref = resolve_keyword($quotedkeyword, false, true, false); # Resolve keyword.
        if ($keyref === false) {
            # make a new keyword
            $keyref = resolve_keyword($quotedkeyword, true, true, false);
        }

        $restriction = search_keyword_restriction($exclude_fields, $ctx, $quotedfieldid, false, "nk_[union_index]_" . $qk);

        if ($qk == 1) {
            // Add code to find matching nodes in resource_node
            $fixedunion->sql = " SELECT rn_[union_index]_" . $qk . ".resource, [bit_or_condition] rn_[union_index]_" . $qk . ".hit_count AS score FROM resource_node rn_[union_index]_" . $qk .
                " LEFT OUTER JOIN `node_keyword` nk_[union_index]_" . $qk . " ON rn_[union_index]_" . $qk . ".node=nk_[union_index]_" . $qk . ".node AND (nk_[union_index]_" . $qk . ".keyword = ? " .  ")";
            $fixedunion->parameters = array_merge($fixedunion->parameters, ["i", $keyref]);

            $fixedunioncondition->sql = "nk_[union_index]_" . $qk . ".keyword = ? " . $restriction->sql;
            $fixedunioncondition->parameters = array_merge(["i", $keyref], $restriction->parameters);
        } else {
            # For keywords other than the first one, check the position is next to the previous keyword.
            # Also check these occurances are within the same field.
            $fixedunion->sql .= " JOIN `node_keyword` nk_[union_index]_" . $qk . " ON nk_[union_index]_" . $qk . ".node = nk_[union_index]_" . ($qk - 1) . ".node AND nk_[union_index]_" . $qk . ".keyword = ? AND  nk_[union_index]_" . $qk . ".position=nk_[union_index]_" . ($qk - 1) . ".position+" . $last_key_offset ;
            array_push($fixedunion->parameters, "i", $keyref);
        }
        $last_key_offset = 1;
        $qk++;
    }

    if (trim($fixedunioncondition->sql) != "") {
        if ($omit) {
            # Instead of adding to the union, filter out resources that do contain the quoted string.
            $filter = str_replace("[bit_or_condition]", "", " r.ref NOT IN (SELECT resource FROM (" . $fixedunion->sql . " WHERE " . $fixedunioncondition->sql . ") qfilter[union_index]) ");
            $query->andFilter(
                str_replace("[union_index]", $keyword->index + 1, $filter),
                array_merge($fixedunion->parameters, $fixedunioncondition->parameters)
            );
        } else {
            $union = new PreparedStatementQuery();
            $union->sql = $fixedunion->sql . " WHERE " . $fixedunioncondition->sql . " GROUP BY resource ";
            $union->parameters = array_merge($fixedunion->parameters, $fixedunioncondition->parameters);
            $query->addUnion(new KeywordUnion($union, false));
        }
    }
    $query->join_count++;
}
