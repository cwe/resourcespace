<?php

/**
* Takes a search string $search, as provided by the user, and returns a results set of matching resources. If there are
* no matches, instead returns an array of suggested searches
*
* The search runs as a pipeline: search_context_create() resolves the caller, options, permissions and configuration
* once; search_parse() splits the search string; search_query_build() assembles the SQL; special searches and
* external search providers may answer instead; search_execute() runs the query and search_suggest_zero_results()
* proposes an alternative search when there are no results.
*
* @uses debug()
* @uses hook()
* @uses search_context_create()
* @uses search_parse()
* @uses search_query_build()
* @uses search_run_special()
* @uses search_run_external()
* @uses search_execute()
* @uses search_suggest_zero_results()
*
* @param string      $search                  Search string
* @param string      $restypes                Optionally used to specify which resource types to search for
* @param string      $order_by
* @param string      $archive                 Allows searching in more than one archive state
* @param int|array   $fetchrows               - If passing an integer, retrieve the specified number of rows (a limit with no offset).
*                                             The returned array of resources will be padded with '0' elements up to the total without the limit.
*                                             - If passing an array, the first element must be the offset (int) and the second the limit (int)
*                                             (the number of rows to return). See setup_search_chunks() for more detail.
*                                             IMPORTANT: When passing an array, the returned array will be in structured form - as returned by
*                                             sql_limit_with_total_count() i.e. the array will have 'total' (the count) and 'data' (the resources)
*                                             named array elements, and the data will not be padded.
* @param string      $sort
* @param boolean     $access_override         Used by smart collections, so that all all applicable resources can be judged
*                                             regardless of the final access-based results
* @param integer     $starsearch              DEPRECATED_STARSEARCH passed in for backwards compatibility
* @param boolean     $ignore_filters
* @param boolean     $return_disk_usage
* @param string      $recent_search_daylimit
* @param string|bool $go                      Paging direction (prev|next)
* @param boolean     $stats_logging           Log keyword usage
* @param boolean     $return_refs_only
* @param boolean     $editable_only
* @param boolean     $returnsql               Returns the query as a PreparedStatementQuery instance
* @param integer     $access                  Search for resources with this access
* @param boolean     $smartsearch
* @param int|null    $resource_collection Restrict results to members of this collection, when an integer is supplied.
*
* @return null|string|array|PreparedStatementQuery
*/
function do_search(
    $search,
    $restypes = '',
    $order_by = 'relevance',
    $archive = '0',
    $fetchrows = -1,
    $sort = 'desc',
    $access_override = false,
    $starsearch = DEPRECATED_STARSEARCH,     # Parameter retained for backwards compatibility
    $ignore_filters = false,
    $return_disk_usage = false,
    $recent_search_daylimit = '',
    $go = false,
    $stats_logging = true,
    $return_refs_only = false,
    $editable_only = false,
    $returnsql = false,
    $access = null,
    $smartsearch = false,
    $resource_collection = null
) {
    debug_function_call("do_search", func_get_args());

    // Everything about the caller (options, permissions, filters, configuration) is resolved once here. The pipeline
    // steps read $ctx instead of globals. External search providers are handed the options as given.
    $options = [
        'restypes'               => $restypes,
        'order_by'               => $order_by,
        'archive'                => $archive,
        'fetchrows'              => $fetchrows,
        'sort'                   => $sort,
        'access_override'        => $access_override,
        'ignore_filters'         => $ignore_filters,
        'return_disk_usage'      => $return_disk_usage,
        'recent_search_daylimit' => $recent_search_daylimit,
        'go'                     => $go,
        'stats_logging'          => $stats_logging,
        'return_refs_only'       => $return_refs_only,
        'editable_only'          => $editable_only,
        'returnsql'              => $returnsql,
        'access'                 => $access,
        'smartsearch'            => $smartsearch,
        'resource_collection'    => $resource_collection,
    ];
    $ctx = search_context_create($options);

    if ($ctx->editable_only && !$ctx->returnsql && $ctx->external_share && !$ctx->internal_share) {
        return array();
    }

    $alternativeresults = hook("alternativeresults", "", array($go));
    if ($alternativeresults) {
        return $alternativeresults;
    }

    // Split the search string into keywords, node restrictions and special search parameters, then build the query
    $parsed = search_parse((string)$search);
    $query = search_query_build($parsed, $ctx);
    if ($query instanceof SearchStop) {
        debug("do_search(): stopped before running a query (" . $query->reason . ")");
        return $query->result;
    }

    // Special searches ("!" prefix, a resource ID, or a plugin's own) and external search providers answer
    // instead of the standard query when they recognise the search
    $result = search_run_special($parsed, $query, $ctx);
    if ($result !== false) {
        return $result;
    }
    $result = search_run_external($parsed, $query, $ctx, $options);
    if ($result !== false) {
        return $result;
    }

    $result = search_execute($query, $ctx);
    if ($ctx->return_refs_only || $result !== []) {
        return $result;
    }
    return search_suggest_zero_results($parsed, $query);
}

/**
 * Answer the search as a special search when it is one.
 *
 * The builders in search_special.php handle the "!" searches, a numeric resource ID when $config_search_for_number
 * is set, and any search a plugin claims through the addspecialsearch hook. They are given a copy of the select
 * list because special searches and plugins rewrite it. Keyword usage is logged when the search is answered.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchQuery   $query  Built query
 * @param SearchContext $ctx    Search context
 *
 * @return array|PreparedStatementQuery|false The result (or its SQL with $ctx->returnsql); false when the search
 *                                            is not a special search
 */
function search_run_special(ParsedSearch $parsed, SearchQuery $query, SearchContext $ctx): array|PreparedStatementQuery|false
{
    $special = new SpecialSearch(
        $parsed->search,
        clone $query->select,
        $query->join,
        $query->filter,
        $query->prefix,
        $query->suffix,
        $query->order_by,
        $ctx->requested_order_by,
        $ctx->archive,
        $ctx->return_disk_usage
    );
    $result = search_special_execute($special, $ctx->fetchrows, $ctx->return_refs_only, $ctx->returnsql);
    if ($result !== false) {
        log_keyword_usage($query->keywords_used, $result);
    }
    return $result;
}

/**
 * Offer the search to external search providers through the external_search hook.
 *
 * The hook receives the do_search() options as the caller passed them, with the parsed keywords and nodes, the
 * resolved ORDER BY and the built filter, join and select fragments. Keyword usage is logged when a provider
 * answers.
 *
 * @param ParsedSearch  $parsed  Parsed search
 * @param SearchQuery   $query   Built query
 * @param SearchContext $ctx     Search context
 * @param array         $options do_search() options keyed by parameter name, as passed by the caller
 *
 * @return mixed The provider's result; false when no provider answered
 */
function search_run_external(ParsedSearch $parsed, SearchQuery $query, SearchContext $ctx, array $options): mixed
{
    $result = hook(
        'external_search',
        '',
        [
            $parsed->search,
            $query->keywords,
            $query->node_bucket,
            $parsed->node_bucket_not,
            $options['restypes'],
            $query->order_by,
            $ctx->archive,
            $ctx->fetchrows,
            $ctx->sort,
            $options['access_override'],
            $options['ignore_filters'],
            $options['return_disk_usage'],
            $options['recent_search_daylimit'],
            $options['return_refs_only'],
            $options['editable_only'],
            $options['returnsql'],
            $options['access'],
            $options['smartsearch'],
            $query->filter,
            $query->join,
            $query->select,
        ]
    );
    if ($result !== false) {
        log_keyword_usage($query->keywords_used, $result);
    }
    return $result;
}

/**
 * Run the built search query and shape the results for do_search()'s callers.
 *
 * With $ctx->returnsql the compiled statement is returned instead. Otherwise the query runs for the requested
 * rows (search_fetch()), keyword usage is logged, and the result is:
 * - the ['total' => , 'data' => ] structure when fetchrows is an array;
 * - a list of ['ref' => ] entries for refs-only searches, or [] when nothing matched;
 * - the resource rows, padded with 0 up to the total when a row limit applies (search_pad_rows()), after the
 *   beforereturnresults hook;
 * - [] when nothing matched (keyword usage is not logged in this case).
 *
 * Special searches end in search_special_finish(), which shares search_fetch() and search_pad_rows() but differs
 * in what it returns for refs-only searches and in how it calls the beforereturnresults hook.
 *
 * @param SearchQuery   $query Built query
 * @param SearchContext $ctx   Search context
 *
 * @return array|PreparedStatementQuery
 */
function search_execute(SearchQuery $query, SearchContext $ctx): array|PreparedStatementQuery
{
    $results_sql = search_query_compile($query);
    debug('$results_sql=' . $results_sql->sql . ", parameters: " . implode(",", $results_sql->parameters));

    if ($ctx->returnsql) {
        return $results_sql;
    }

    $result = search_fetch($results_sql, search_query_compile_count($query), $ctx->fetchrows, true);

    // An array fetchrows returns the ['total' => , 'data' => ] structure as it is; otherwise shape the rows
    if (!is_array($ctx->fetchrows)) {
        if ($ctx->return_refs_only) {
            $result = array_map(fn($row) => ["ref" => $row["ref"]], $result["data"]);
        } else {
            $result = search_pad_rows($result, $ctx->fetchrows);
            if ($result === []) {
                return [];
            }
            hook("beforereturnresults", "", array($result, $ctx->archive));
        }
    }

    log_keyword_usage($query->keywords_used, $result);
    return $result;
}

/**
 * Run a search statement for the rows do_search() was asked for, together with the total number of matches.
 *
 * The shared last query of a search: search_execute() calls it for the standard query and
 * search_special_finish() for a special search.
 *
 * @param PreparedStatementQuery $sql         Statement returning the rows
 * @param PreparedStatementQuery $count_sql   Statement whose row count is the total; a reduced form of $sql
 * @param int|array              $fetchrows   Rows to fetch, or an [offset, count] chunk; see setup_search_chunks()
 * @param bool                   $cache_count Allow the total to be read from the query cache
 * @param int|null               $row_cap     Most rows the statement can return, for a search with a fixed limit:
 *                                            a chunk that would reach past the cap is moved back to end at it
 *
 * @return array{total: int, data: array} The total and the fetched rows, see sql_limit_with_total_count()
 */
function search_fetch(
    PreparedStatementQuery $sql,
    PreparedStatementQuery $count_sql,
    int|array $fetchrows,
    bool $cache_count,
    ?int $row_cap = null
): array {
    setup_search_chunks($fetchrows, $chunk_offset, $search_chunk_size);
    if ($row_cap !== null && $chunk_offset > $row_cap - $search_chunk_size) {
        $chunk_offset = $row_cap - $search_chunk_size;
    }
    return sql_limit_with_total_count($sql, $search_chunk_size, $chunk_offset, $cache_count, $count_sql);
}

/**
 * Turn the result of search_fetch() into the list do_search() returns for an integer $fetchrows.
 *
 * Callers page through the list by position, so when a row limit applies the fetched rows are followed by 0
 * entries up to the total. Nothing is padded when every row was requested (-1), where the list is already
 * complete and a total read from the query cache may be out of date.
 *
 * @param array{total: int, data: array} $result    Result of search_fetch()
 * @param int                            $fetchrows Row limit the search ran with; -1 for every row
 *
 * @return array The rows, padded up to the total; [] when nothing matched
 */
function search_pad_rows(array $result, int $fetchrows): array
{
    $rows = $result["data"];
    $total = $result["total"] ?? 0;
    if ($total <= 0 || count($rows) == 0) {
        return [];
    }
    if ($fetchrows === -1) {
        return $rows;
    }
    for ($missing = $total - count($rows); $missing > 0; $missing -= 1000000) {
        $rows = array_merge($rows, array_fill(0, min($missing, 1000000), 0));
    }
    return $rows;
}

/**
 * Suggest an alternative search when a search matched its keywords but returned no results.
 *
 * Calls the zero_search_results hook. For a search of two or more keywords without a field restriction, suggests
 * the search with its least used keyword removed (or, if a keyword was negated with "-", with that term removed).
 *
 * @param ParsedSearch $parsed Parsed search
 * @param SearchQuery  $query  Built query; keywords is read
 *
 * @return string|array The suggested search; "" when there is nothing to suggest; [] when the search had no keywords
 */
function search_suggest_zero_results(ParsedSearch $parsed, SearchQuery $query): string|array
{
    hook('zero_search_results');

    // No suggestions for field-specific searching or if just one keyword
    $keywords = $query->keywords;
    if (strpos($parsed->search, ":") !== false || count($keywords) === 1) {
        return "";
    }

    # All keywords resolved OK, but there were no matches
    # Remove keywords, least used first, until we get results.
    $lsql = new PreparedStatementQuery();
    $omit = "";
    foreach ($keywords as $keyword) {
        if (substr($keyword, 0, 1) == "-") {
            $omit = $keyword;
        }
        if ($lsql->sql != "") {
            $lsql->sql .= " OR ";
        }
        $lsql->sql .= "keyword = ?";
        $lsql->parameters = array_merge($lsql->parameters, ["s", $keyword]);
    }

    if ($omit !== "") {
        return trim_spaces(str_replace(" " . $omit . " ", " ", " " . join(" ", $keywords) . " "));
    }
    if ($lsql->sql != "") {
        $least = ps_value("SELECT keyword value FROM keyword WHERE " . $lsql->sql . " ORDER BY hit_count ASC LIMIT 1", $lsql->parameters, "");
        return trim_spaces(str_replace(" " . $least . " ", " ", " " . join(" ", $keywords) . " "));
    }
    return array();
}
