<?php

/**
 * Query assembly for do_search().
 *
 * A SearchQuery accumulates the SELECT, JOIN and WHERE fragments of the main search query while the pipeline
 * steps in do_search() run. Each step is a plain function taking the SearchContext (caller, permissions and
 * configuration, see search_context.php) and the SearchQuery being built. A step that must end the search
 * early, for example to return a suggested search instead of results, returns a SearchStop.
 */

/**
 * Typed early return from a search pipeline step: do_search() returns $result to its caller.
 */
final class SearchStop
{
    /**
     * @param string|array|false $result What do_search() returns to its caller
     * @param string             $reason Short machine readable reason, for debugging
     */
    public function __construct(
        public readonly string|array|false $result,
        public readonly string $reason,
    ) {
    }
}

/**
 * One member of the keyword matching UNION. Each union returns the resources matching a single keyword (or a
 * fixed list of keyword alternatives) together with a score. search_query_assemble_unions() combines the members
 * into one derived table and a condition stating which members must have matched.
 */
final class KeywordUnion
{
    /**
     * @param PreparedStatementQuery $sql      SELECT returning resource, [bit_or_condition] and score. The
     *                                         [union_index], [union_index_minus_one] and [bit_or_condition]
     *                                         placeholders are replaced during assembly.
     * @param bool                   $or_group True when a match on this member is an alternative to its
     *                                         neighbours: consecutive members with the same flag are grouped,
     *                                         OR'd together when the flag is true and AND'd when it is false, and
     *                                         a change of flag starts a new AND group.
     */
    public function __construct(
        public readonly PreparedStatementQuery $sql,
        public readonly bool $or_group,
    ) {
    }
}

/**
 * The main search query under construction.
 *
 * The final statement is assembled as
 *   {prefix} SELECT DISTINCT {score} score, {select} FROM resource r {join} WHERE {filter} GROUP BY ... ORDER BY {order_by} {suffix}
 * with the parameters of select, join and filter in that order.
 */
final class SearchQuery
{
    /** Columns selected for each resource (without the leading score column) */
    public PreparedStatementQuery $select;
    /** The group_access and user_access columns within $select, needed by the GROUP BY; used by the count statement */
    public string $access_columns = '';
    /** JOIN clauses appended after "FROM resource r" */
    public PreparedStatementQuery $join;
    /** WHERE conditions, AND'd together */
    public PreparedStatementQuery $filter;
    /** Expression giving the hit count of a resource; selected as total_hit_count */
    public string $hitcount_select = 'r.hit_count';
    /** Relevance expression selected as score and available to ORDER BY */
    public string $score = 'r.hit_count';
    /** ORDER BY clause body */
    public string $order_by = '';
    /** True when the ORDER BY refers to metadata field columns, which must then be selected even for refs-only searches */
    public bool $order_needs_fields = false;
    /** Outer query wrapper used when summing disk usage */
    public string $prefix = '';
    public string $suffix = '';
    /** @var KeywordUnion[] Keyword matching unions, in keyword order */
    public array $unions = [];
    /** @var int[] Keyword refs matched, for log_keyword_usage() */
    public array $keywords_used = [];
    /**
     * @var string[] Keywords processed: the parsed keywords followed by any terms split while matching (see
     *               search_query_keywords())
     */
    public array $keywords = [];
    /**
     * @var string[] Keywords that could not be matched, keyed by their index in $keywords: "" to suggest dropping
     *               the word, or a sound-alike keyword wrapped in <i></i>
     */
    public array $unresolved = [];
    /**
     * @var array Node restrictions as [AND group] => [node refs to OR]: the node tokens from the search string plus
     *            the nodes resolved from legacy "fixedlistfield:value" keywords
     */
    public array $node_bucket = [];
    /** Number of keywords that added a join or union; used to make join aliases unique */
    public int $join_count = 0;
    /** Alias number of the join to the configured date field shared by basicyear/basicmonth/basicday keywords, once added */
    public ?int $basic_date_join = null;
    /** @var string[] Values of basicyear/basicmonth/basicday keywords keyed by 'year', 'month' and 'day' */
    public array $basic_date_parts = [];

    public function __construct()
    {
        $this->select = new PreparedStatementQuery();
        $this->join = new PreparedStatementQuery();
        $this->filter = new PreparedStatementQuery();
    }

    /**
     * Append columns to the SELECT list. Fragments carry their own leading comma, matching the plugin hooks that
     * append to the select.
     *
     * @param string $sql        Column expressions, with placeholders
     * @param array  $parameters Bound parameters for the expressions (type/value pairs)
     */
    public function addSelect(string $sql, array $parameters = []): void
    {
        $this->select->sql .= $sql;
        $this->select->parameters = array_merge($this->select->parameters, $parameters);
    }

    /**
     * Append a condition to the WHERE clause, adding AND when a condition is already present. Empty SQL is ignored.
     *
     * @param string $sql        Condition, with placeholders
     * @param array  $parameters Bound parameters for the condition (type/value pairs)
     */
    public function andFilter(string $sql, array $parameters = []): void
    {
        if ($sql === '') {
            return;
        }
        if ($this->filter->sql != '') {
            $this->filter->sql .= ' AND ';
        }
        $this->filter->sql .= $sql;
        $this->filter->parameters = array_merge($this->filter->parameters, $parameters);
    }

    /**
     * Place a condition at the start of the WHERE clause, AND'd with the existing conditions. Empty SQL is ignored.
     *
     * @param string $sql        Condition, with placeholders
     * @param array  $parameters Bound parameters for the condition (type/value pairs)
     */
    public function prependFilter(string $sql, array $parameters = []): void
    {
        if ($sql === '') {
            return;
        }
        if ($this->filter->sql != '') {
            $sql .= ' AND ';
        }
        $this->filter->sql = $sql . $this->filter->sql;
        $this->filter->parameters = array_merge($parameters, $this->filter->parameters);
    }

    /**
     * Append a JOIN clause.
     *
     * @param string $sql        JOIN clause including a leading space
     * @param array  $parameters Bound parameters for the clause (type/value pairs)
     */
    public function addJoin(string $sql, array $parameters = []): void
    {
        $this->join->sql .= $sql;
        $this->join->parameters = array_merge($this->join->parameters, $parameters);
    }

    /**
     * Place a JOIN clause before the existing ones.
     *
     * @param string $sql        JOIN clause including a leading space
     * @param array  $parameters Bound parameters for the clause (type/value pairs)
     */
    public function prependJoin(string $sql, array $parameters = []): void
    {
        $this->join->sql = $sql . $this->join->sql;
        $this->join->parameters = array_merge($parameters, $this->join->parameters);
    }

    /**
     * Queue further keywords for matching, after those already listed. search_query_keywords() iterates by index,
     * so keywords queued while it runs are processed in the same pass.
     *
     * @param string[] $keywords Keyword texts
     */
    public function addKeywords(array $keywords): void
    {
        $this->keywords = array_merge($this->keywords, $keywords);
    }

    /**
     * Add a keyword union member. Members are assembled by search_query_assemble_unions().
     */
    public function addUnion(KeywordUnion $union): void
    {
        $this->unions[] = $union;
    }
}

/**
 * Build the main search query for a parsed search.
 *
 * Runs the pipeline steps in order: ORDER BY validation, the base filter (search_query_filter()), custom access joins,
 * selected columns, keyword matching, node restrictions, user search/edit filters, keyword union assembly, the
 * score columns and the featured collections restriction. Plugins see the query through the
 * search_pipeline_setup hook after the selected columns are set and before keywords are matched.
 *
 * The steps after the hook depend on each other and are called in the only order that works; the dependencies
 * are noted inline.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context
 *
 * @return SearchQuery|SearchStop A stop when do_search() must return early: no access to a searched field, or a
 *                                suggested search because a keyword could not be matched
 */
function search_query_build(ParsedSearch $parsed, SearchContext $ctx): SearchQuery|SearchStop
{
    // Plugin-facing flag: the 'search_pipeline_setup' hook may set this to false to disable keyword matching
    global $keysearch;

    $query = new SearchQuery();
    $query->keywords = $parsed->keywords;
    $query->node_bucket = $parsed->node_bucket;

    // The select step needs order_needs_fields from here
    search_query_order($parsed, $ctx, $query);

    // The base filter: what was asked for and what the user may see; the same clauses apply to special searches
    search_query_filter($parsed->search, $ctx, $query);

    # If returning disk used by the resources in the search results then wrap the returned SQL in an outer query that sums disk usage.
    if ($ctx->return_disk_usage) {
        $query->prefix = "SELECT sum(disk_usage) total_disk_usage, count(*) total_resources, resourcelist.ref, resourcelist.score, resourcelist.user_rating, resourcelist.total_hit_count FROM (";
        $query->suffix = ") resourcelist";
    }

    search_query_access($ctx, $query);
    search_query_select($ctx, $query);

    # Do not process keywords if a numeric search is provided (resource ID)
    $keysearch = !($ctx->search_for_number && is_numeric($parsed->search));

    // Order by resource type
    $query->addJoin(" JOIN resource_type AS rty ON r.resource_type = rty.ref ");
    $query->addSelect(", rty.order_by ");

    hook("search_pipeline_setup", "", array($parsed->search, $query->select, $query->join, $query->filter, ""));

    // Keywords first: fixed list keywords add to node_bucket, and unmatched keywords fill unresolved
    if ($keysearch) {
        $stop = search_query_keywords($parsed, $ctx, $query);
        if ($stop !== null) {
            return $stop;
        }
    }
    search_query_nodes($parsed, $ctx, $query);
    $stop = search_query_unresolved($parsed, $query);
    if ($stop !== null) {
        return $stop;
    }
    search_query_user_filters($ctx, $query);
    search_query_assemble_unions($query);
    // After nodes (hit count expression) and unions (summed keyword score)
    search_query_score($query);

    # Handle numeric searches when $config_search_for_number=false, i.e. perform a normal search but include matches for resource ID first
    if (!$ctx->search_for_number && is_int_loose($parsed->search)) {
        # Always show exact resource matches first.
        $query->order_by = "(r.ref='" . $parsed->search . "') desc," . $query->order_by;
    }

    search_query_featured_only($parsed, $ctx, $query);
    return $query;
}

/**
 * Pipeline step: validate the requested ORDER BY for this search (resolve_search_order_by(), which also lets plugins
 * change it) and note whether it refers to metadata field columns.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context; requested_order_by and sort are read
 * @param SearchQuery   $query  Query under construction; order_by and order_needs_fields are set
 */
function search_query_order(ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): void
{
    $resolved = resolve_search_order_by($parsed->search, $ctx->requested_order_by, $ctx->sort);
    $query->order_by = $resolved['order_by'];
    $query->order_needs_fields = $resolved['needs_fields'];
}

/**
 * Pipeline step: join the custom access records of the user and their group so that custom access can be
 * evaluated in the query, and exclude custom access resources without a matching record.
 *
 * Not needed when the user sees all resources ("v" permission) or access is overridden.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query under construction; join and filter are updated
 */
function search_query_access(SearchContext $ctx, SearchQuery $query): void
{
    if ($ctx->sees_all_access || $ctx->access_override) {
        return;
    }
    # one extra join (rca2) is required for user specific permissions (enabling more intelligent watermarks in search view)
    # the original join is used to gather group access into the search query as well.
    $query->addJoin(
        " LEFT OUTER JOIN resource_custom_access rca2 ON r.ref=rca2.resource AND rca2.user = ? AND (rca2.user_expires IS null or rca2.user_expires>now()) AND rca2.access<>2  ",
        ["i", $ctx->userref]
    );
    $query->addJoin(
        " LEFT OUTER JOIN resource_custom_access rca ON r.ref=rca.resource AND rca.usergroup = ? AND rca.access<>2 ",
        ["i", $ctx->usergroup]
    );
    # If rca.resource is null, then no matching custom access record was found
    # If r.access is also 3 (custom) then the user is not allowed access to this resource.
    # Note that it's normal for null to be returned if this is a resource with non custom permissions (r.access<>3).
    $query->andFilter(" NOT (rca.resource IS null AND r.access=3)");
}

/**
 * Pipeline step: the columns selected for each resource: the resource table columns, plugin additions
 * (modifyselect and modifyselect2 hooks), disk usage when requested, the custom access columns and resultant
 * access for this user, and the metadata field columns the user may view.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query under construction; select is set
 */
function search_query_select(SearchContext $ctx, SearchQuery $query): void
{
    $query->addSelect("r.ref, r.resource_type, r.has_image, r.is_transcoding, r.creation_date, r.rating, r.user_rating, r.user_rating_count, r.user_rating_total, r.file_extension, r.preview_extension, r.image_red, r.image_green, r.image_blue, r.thumb_width, r.thumb_height, r.archive, r.access, r.colour_key, r.created_by, r.file_modified, r.file_checksum, r.request_count, r.new_hit_count, r.expiry_notification_sent, r.preview_tweaks, r.file_path, r.modified, r.file_size ");

    $modified_select = hook('modifyselect');
    $query->addSelect($modified_select ? $modified_select : '');      // modify select hook 1

    $modified_select2 = hook('modifyselect2');
    $query->addSelect($modified_select2 ? $modified_select2 : '');    // modify select hook 2

    $query->addSelect($ctx->return_disk_usage ? ',r.disk_usage' : '');      // disk usage

    // Get custom group and user access rights if available to generate resultant_access,
    // otherwise select null values so columns can still be used regardless
    // This can then be passed through  to access checking functions in order to eliminate many single queries.
    if (!$ctx->sees_all_access && !$ctx->access_override) {
        // Get custom access
        $query->access_columns = ",rca.access group_access,rca2.access user_access ";
        $query->addSelect($query->access_columns);
        if (!$ctx->sees_restricted && !$ctx->internal_share) {
            // Restrict all resources by default
            if ($ctx->derestrict_filter_id !== null) {
                // Add exemption for custom access
                $derestrict_filter_sql = get_filter_sql($ctx->derestrict_filter_id);
                if ($derestrict_filter_sql instanceof PreparedStatementQuery) {
                    $access_sql = "CASE WHEN " . $derestrict_filter_sql->sql . " THEN 0 ELSE 1 END";
                    $query->addSelect(
                        ", LEAST(IFNULL(rca.access, $access_sql), IFNULL(rca2.access, $access_sql)) resultant_access ",
                        array_merge($derestrict_filter_sql->parameters, $derestrict_filter_sql->parameters)
                    );
                }
            } else {
                // Set access to restricted unless custom access is set
                $query->addSelect(", LEAST(IFNULL(rca.access, 1), IFNULL(rca2.access, 1)) resultant_access ");
            }
        } elseif (count($ctx->x_restricted_types) > 0) {
            // Consider X permission else apply standard resultant_access for this user based on resource access and custom access
            // Custom access can override X permission. Note: rows not returned for custom access "Confidential" so X can't increase access here.
            $case_sql = ", CASE ";
            $case_parameters = [];
            foreach ($ctx->x_restricted_types as $sql_restricted_type) {
                $case_sql .= "WHEN resource_type = ? THEN LEAST(IFNULL(rca.access, 1), IFNULL(rca2.access, 1)) ";
                $case_parameters[] = 'i';
                $case_parameters[] = $sql_restricted_type;
            }
            $case_sql .= "ELSE LEAST(IFNULL(rca.access, r.access), IFNULL(rca2.access, r.access)) END resultant_access ";
            $query->addSelect($case_sql, $case_parameters);
        } else {
            $query->addSelect(", LEAST(IFNULL(rca.access, r.access), IFNULL(rca2.access, r.access)) resultant_access ");
        }
    } else {
        $query->access_columns = ", null group_access, null user_access ";
        $query->addSelect($query->access_columns);
        $query->addSelect(", r.access resultant_access ");
    }

    # Add the metadata field columns (not when returning refs only, unless the ORDER BY needs them)
    $joins = (!$ctx->return_refs_only || $query->order_needs_fields) ? get_resource_table_joins() : array();
    foreach ($joins as $datajoin) {
        if (metadata_field_view_access($datajoin) || $datajoin == $ctx->view_title_field) {
            $query->addSelect(", r.field{$datajoin} ");
        }
    }
}

/**
 * Pipeline step: the relevance score and the total_hit_count column.
 *
 * Must follow search_query_nodes(), which folds node hit counts into the hit count expression, and
 * search_query_assemble_unions(), whose summed keyword score takes precedence as the relevance score.
 *
 * @param SearchQuery $query Query under construction; score is set and select is updated
 */
function search_query_score(SearchQuery $query): void
{
    # Use amalgamated resource_keyword hitcounts for scoring (relevance matching based on previous user activity)
    $query->score = $query->unions !== [] ? "h.score" : $query->hitcount_select;
    $query->addSelect(", " . $query->hitcount_select . " total_hit_count");
}

/**
 * Pipeline step: users with the "J" permission can only search resources that belong to featured collections.
 * Doesn't apply to the user's special upload collection, to allow for upload then edit mode.
 *
 * @param ParsedSearch  $parsed Parsed search
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query under construction; join is updated
 */
function search_query_featured_only(ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): void
{
    $upload_collection = '!collection' . (0 - $ctx->userref);
    if ($ctx->featured_only && $parsed->search != $upload_collection) {
        $collection_join = " JOIN collection_resource AS jcr ON jcr.resource = r.ref JOIN collection AS jc ON jcr.collection = jc.ref";
        $collection_join .= featured_collections_permissions_filter_sql("AND", "jc.ref", true);
        $query->prependJoin($collection_join);
    }
}

/**
 * Compile the query into the statement that returns the ordered results.
 *
 * @param SearchQuery $query Built query
 *
 * @return PreparedStatementQuery
 */
function search_query_compile(SearchQuery $query): PreparedStatementQuery
{
    return search_query_compile_statement($query, " ORDER BY " . $query->order_by);
}

/**
 * Compile the query into the statement whose rows are counted for paging: the same groups of rows as the results
 * statement without the ORDER BY. Unless the query is wrapped for disk usage, only the grouping columns are
 * selected, as the full column list makes the count noticeably slower.
 *
 * @param SearchQuery $query Built query
 *
 * @return PreparedStatementQuery
 */
function search_query_compile_count(SearchQuery $query): PreparedStatementQuery
{
    if ($query->prefix === '') {
        return new PreparedStatementQuery(
            "SELECT r.ref" . $query->access_columns . search_query_compile_from($query) . " ",
            array_merge($query->join->parameters, $query->filter->parameters)
        );
    }
    return search_query_compile_statement($query, " ");
}

/**
 * The full statement selecting every column, wrapped in the disk usage prefix and suffix when set.
 *
 * @param SearchQuery $query Built query
 * @param string      $tail  Text between the GROUP BY and the suffix: the ORDER BY clause, or a space
 *
 * @return PreparedStatementQuery
 */
function search_query_compile_statement(SearchQuery $query, string $tail): PreparedStatementQuery
{
    return new PreparedStatementQuery(
        $query->prefix
            . "SELECT DISTINCT " . $query->score . " score, " . $query->select->sql
            . search_query_compile_from($query)
            . $tail
            . $query->suffix,
        array_merge($query->select->parameters, $query->join->parameters, $query->filter->parameters)
    );
}

/**
 * The FROM, WHERE and GROUP BY clauses shared by the results and count statements.
 *
 * @param SearchQuery $query Built query
 *
 * @return string
 */
function search_query_compile_from(SearchQuery $query): string
{
    return " FROM resource r" . $query->join->sql
        . " WHERE " . $query->filter->sql
        . " GROUP BY r.ref, user_access, group_access";
}

/**
 * Pipeline step: restrict the search to the nodes given in the search string (resolve_given_nodes()) or resolved
 * from legacy "fieldname:value" fixed list searches.
 *
 * Each entry of SearchQuery::$node_bucket is a list of node refs to OR, and the entries are AND'd by joining
 * resource_node once per entry (or once per node when category tree AND logic is configured). Node hit counts are
 * folded into the hit count expression. Nodes in ParsedSearch::$node_bucket_not exclude resources through a
 * NOT EXISTS condition placed first in the WHERE clause.
 *
 * @param ParsedSearch  $parsed Parsed search; node_bucket_not is read
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query under construction; node_bucket is read, join, filter and hitcount_select are updated
 */
function search_query_nodes(ParsedSearch $parsed, SearchContext $ctx, SearchQuery $query): void
{
    $rn = 0;
    $node_hitcount = "";
    foreach ($query->node_bucket as $node_bucket_or) {
        if ($ctx->category_tree_and_logic) {
            foreach ($node_bucket_or as $node_bucket_and) {
                $query->addJoin(
                    ' JOIN `resource_node` rn' . $rn . ' ON r.`ref`=rn' . $rn . '.`resource` AND rn' . $rn . '.`node` = ?',
                    ["i", $node_bucket_and]
                );
                $node_hitcount .= (($node_hitcount != "") ? " +" : "") . "rn" . $rn . ".hit_count";
                $rn++;
            }
        } else {
            $query->addJoin(
                ' JOIN `resource_node` rn' . $rn . ' ON r.`ref`=rn' . $rn . '.`resource` AND rn' . $rn . '.`node` IN ('
                    . ps_param_insert(count($node_bucket_or)) . ')',
                ps_param_fill($node_bucket_or, "i")
            );
            $node_hitcount .= (($node_hitcount != "") ? " +" : "") . "rn" . $rn . ".hit_count";
            $rn++;
        }
    }

    if ($node_hitcount != "") {
        $query->hitcount_select = "(SUM(" . $query->hitcount_select . ") + SUM(" . $node_hitcount . ")) ";
    }

    if (count($parsed->node_bucket_not) > 0) {
        $query->prependFilter(
            'NOT EXISTS (SELECT `resource`, node FROM `resource_node` WHERE r.ref=`resource` AND `node` IN ('
                . ps_param_insert(count($parsed->node_bucket_not)) . '))',
            ps_param_fill($parsed->node_bucket_not, "i")
        );
    }
}

/**
 * Pipeline step: when a keyword could not be matched, stop and suggest an alternative search.
 *
 * The suggestion is the parsed keywords with each unmatched keyword (SearchQuery::$unresolved) replaced by its
 * sound-alike, or dropped.
 *
 * @param ParsedSearch $parsed Parsed search; the keywords and the separator used in the search string are read
 * @param SearchQuery  $query  Query under construction; unresolved and keywords are read
 *
 * @return SearchStop|null Null when every keyword matched; otherwise the suggested search ("" when there is
 *                         nothing to suggest)
 */
function search_query_unresolved(ParsedSearch $parsed, SearchQuery $query): ?SearchStop
{
    if ($query->unresolved === []) {
        return null;
    }
    $suggested = $parsed->keywords;
    foreach ($query->unresolved as $index => $suggestion) {
        $suggested[$index] = $suggestion;
    }
    if ($suggested == $query->keywords) {
        debug("No alternative keywords to suggest.");
        return new SearchStop("", 'nothing_to_suggest');
    }

    $suggestjoin = (strpos($parsed->search, ",") === false) ? " " : ", ";
    $suggest = "";
    foreach ($suggested as $suggestion) {
        if ($suggestion != "") {
            if ($suggest != "") {
                $suggest .= $suggestjoin;
            }
            $suggest .= $suggestion;
        }
    }
    debug("Suggesting $suggest");
    return new SearchStop($suggest, 'suggest');
}

/**
 * Pipeline step: apply the user's search filter and, for editable-only searches, the edit filter.
 *
 * Legacy text filters were migrated to filter IDs when the SearchContext was created, see
 * resolve_user_search_filter().
 *
 * A search filter that cannot be loaded is a configuration fault that must not go unnoticed, so this is the one
 * place the pipeline still exits with a message instead of returning a SearchStop: an empty result would hide
 * the fault from administrators while silently showing users nothing.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query under construction; filter is updated
 */
function search_query_user_filters(SearchContext $ctx, SearchQuery $query): void
{
    if ($ctx->search_filter_id !== null) {
        $search_filter_sql = get_filter_sql($ctx->search_filter_id);
        if (!($search_filter_sql instanceof PreparedStatementQuery)) {
            global $lang;
            exit($lang["error_search_filter_invalid"]);
        }
        $query->andFilter($search_filter_sql->sql, $search_filter_sql->parameters);
    }

    if ($ctx->editable_only && $ctx->edit_filter_id !== null) {
        $edit_filter_sql = get_filter_sql($ctx->edit_filter_id);
        if ($edit_filter_sql instanceof PreparedStatementQuery) {
            $query->andFilter($edit_filter_sql->sql, $edit_filter_sql->parameters);
        }
    }
}

/**
 * Pipeline step: combine the keyword unions into one derived table joined to the resource table.
 *
 * Each union member selects its matching resources with a keyword_N_found flag; the derived table "h" groups them
 * by resource, summing the score and OR'ing the flags. A condition on the flags, following the members'
 * KeywordUnion::$or_group grouping, is added to the WHERE clause. search_query_score() then selects the summed
 * score as the relevance score. Does nothing when there are no unions.
 *
 * @param SearchQuery $query Query under construction; join and filter are updated
 */
function search_query_assemble_unions(SearchQuery $query): void
{
    $count = count($query->unions);
    if ($count === 0) {
        return;
    }

    $union_sql = [];
    $union_params = [];
    $aggregation = [];
    $criteria = [];
    foreach ($query->unions as $i => $union) {
        $index = $i + 1;
        $bit_or_condition = "";
        for ($y = 1; $y <= $count; $y++) {
            if ($index == $y) {
                $bit_or_condition .= " TRUE AS `keyword_{$y}_found`, ";
            } else {
                $bit_or_condition .= " FALSE AS `keyword_{$y}_found`,";
            }
        }
        $union_sql[] = str_replace(
            ['[bit_or_condition]', '[union_index]', '[union_index_minus_one]'],
            [$bit_or_condition, $index, $i],
            $union->sql->sql
        );
        $union_params = array_merge($union_params, $union->sql->parameters);
        $aggregation[] = "BIT_OR(`keyword_{$index}_found`) AS `keyword_{$index}_found`";
        $criteria[] = "`h`.`keyword_{$index}_found`";
    }

    $query->addJoin(
        " JOIN (
            SELECT resource,sum(score) AS score,
            " . join(", ", $aggregation) . " from
            (" . join(" union ", $union_sql) . ") AS hits GROUP BY resource) AS h ON h.resource=r.ref ",
        $union_params
    );

    $condition = "(";
    foreach ($criteria as $i => $criterion) {
        if ($i == 0) {
            $condition .= $criterion;
            continue;
        }
        $or_group = $query->unions[$i]->or_group;
        if ($or_group !== $query->unions[$i - 1]->or_group) {
            $condition .= ') AND (' . $criterion;
            continue;
        }
        $condition .= ($or_group ? ' OR ' : ' AND ') . $criterion;
    }
    $condition .= ")";
    $query->andFilter($condition);
}
