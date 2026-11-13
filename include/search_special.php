<?php

/**
 * Special searches for do_search().
 *
 * A special search answers instead of the standard keyword query: the "!" searches ("!collection123", "!last50",
 * "!properties..."), a resource ID when $config_search_for_number is set, and any search a plugin claims through
 * the addspecialsearch hook. Each core search is a builder function that takes the SpecialSearch holding the query
 * fragments do_search() prepared and fills in its SQL template. search_special_builder() picks the builder,
 * search_special_build() runs it and the plugin hook, and search_special_finish() turns the template into the
 * query to run. search_special() in search_functions.php keeps the previous signature and wraps the three.
 */

define('SEARCH_SPECIAL_REPORT_PATTERN', '/^!report(\d+)(p[-1\d]+)?(d\d+)?(fy\d{4})?(fm\d{2})?(fd\d{2})?(ty\d{4})?(tm\d{2})?(td\d{2})?/i');

/**
 * The inputs a special search builder works from and the query template it produces.
 *
 * The template keeps the [SELECT_SQL], [GROUP_BY_SQL] and [ORDER_BY_SQL] placeholders that search_special_finish()
 * fills in, so that one template serves the full query, the reduced refs-only query and the count query. A builder
 * may also rewrite the select list and the ORDER BY, and the four output properties carry what the finish step
 * needs beyond the template.
 */
final class SpecialSearch
{
    /** @var PreparedStatementQuery The query template; empty until a builder or a plugin fills it */
    public PreparedStatementQuery $sql;

    /** @var array Parameters bound inside the ORDER BY clause, appended after the template's own */
    public array $order_by_params = [];

    /** @var int|null Hard limit on the number of rows the search may return */
    public ?int $hard_limit = null;

    /** @var bool Whether the count query may be cached */
    public bool $cache_count = false;

    /**
     * @param string                 $search             Search string, starting with the special search identifier
     * @param PreparedStatementQuery $select             Select list; builders prepend their score column to it
     * @param PreparedStatementQuery $join               JOIN fragment of the standard query
     * @param PreparedStatementQuery $filter             WHERE fragment built by search_query_filter()
     * @param string                 $prefix             SQL wrapped around the query, e.g. for disk usage
     * @param string                 $suffix             SQL appended after the query
     * @param string                 $order_by           Resolved ORDER BY clause
     * @param string                 $requested_order_by Ordering as the caller requested it, e.g. "relevance"
     * @param array                  $archive            Archive states being searched
     * @param bool                   $return_disk_usage  Whether the caller wants disk usage rather than rows
     */
    public function __construct(
        public string $search,
        public PreparedStatementQuery $select,
        public PreparedStatementQuery $join,
        public PreparedStatementQuery $filter,
        public string $prefix,
        public string $suffix,
        public string $order_by,
        public string $requested_order_by,
        public array $archive,
        public bool $return_disk_usage,
    ) {
        $this->sql = new PreparedStatementQuery();
    }
}

/**
 * Answer a special search: build its query and run it.
 *
 * @param SpecialSearch $s                The search and the query fragments do_search() prepared
 * @param int|array     $fetchrows        Rows to fetch, or a [offset, count] chunk; see setup_search_chunks()
 * @param bool          $return_refs_only Run the reduced query that returns resource references only
 * @param bool          $returnsql        Return the query instead of running it
 *
 * @return array|PreparedStatementQuery|false The result rows (or the total/data structure for a chunked search),
 *                                            the query with $returnsql, or false when this is not a special search
 */
function search_special_execute(SpecialSearch $s, int|array $fetchrows, bool $return_refs_only, bool $returnsql): array|PreparedStatementQuery|false
{
    $built = search_special_build($s);
    if ($built === null) {
        return false;
    }
    if ($built instanceof SearchStop) {
        return $built->result;
    }
    return search_special_finish($built, $fetchrows, $return_refs_only, $returnsql);
}

/**
 * Say which archive states a search covers, for the base filter (search_query_filter_archive()).
 *
 * This sits beside search_special_builder() so that a special search's dispatch and its archive handling are
 * read, and changed, together. It tests the search string rather than the chosen builder because the rule also
 * holds when keywords follow the identifier, and because "integrityfail" is matched anywhere in the search, as
 * it always has been.
 *
 * @param string $search Search string
 *
 * @return SearchArchiveStates Requested for every search not listed here, including standard searches
 */
function search_special_archive_states(string $search): SearchArchiveStates
{
    return match (true) {
        // Members of a collection or list, and pending resources, are found whatever their state
        str_starts_with($search, "!collection"),
        str_starts_with($search, "!list"),
        str_starts_with($search, "!archivepending"),
        str_starts_with($search, "!userpending") => SearchArchiveStates::Any,
        // Related resources (which covers "!relatedpushed"), field data and integrity failures in any workflow state
        str_starts_with($search, "!related"),
        str_starts_with($search, "!hasdata"),
        str_contains($search, "integrityfail") => SearchArchiveStates::AllWorkflow,
        default => SearchArchiveStates::Requested,
    };
}

/**
 * Name the builder function for a special search.
 *
 * The order of the checks matters where identifiers share a prefix: "!listall" is handled by the "!list" builder,
 * "!relatedpushed" must come before "!related" and "!colourkey" before "!colour". Searches that only match the whole
 * string ("!images", "!locked") fall through to the standard query when keywords follow them.
 *
 * @param string $search Search string
 *
 * @return string|null The builder function name, or null when this is not a core special search
 */
function search_special_builder(string $search): ?string
{
    global $config_search_for_number;

    return match (true) {
        str_starts_with($search, "!last") => "search_special_last",
        str_starts_with($search, "!nodownloads") => "search_special_nodownloads",
        str_starts_with($search, "!duplicates") => "search_special_duplicates",
        str_starts_with($search, "!collection") => "search_special_collection",
        str_starts_with($search, "!relatedpushed") => "search_special_relatedpushed",
        str_starts_with($search, "!related") => "search_special_related",
        str_starts_with($search, "!geo") => "search_special_geo",
        str_starts_with($search, "!colourkey") => "search_special_colourkey",
        str_starts_with($search, "!colour") => "search_special_colour",
        str_starts_with($search, "!rgb") => "search_special_rgb",
        str_starts_with($search, "!nopreview") => "search_special_nopreview",
        ($config_search_for_number && is_numeric($search)) || str_starts_with($search, "!resource") => "search_special_resource",
        str_starts_with($search, "!archivepending") => "search_special_archivepending",
        str_starts_with($search, "!userpending") => "search_special_userpending",
        str_starts_with($search, "!contributions") => "search_special_contributions",
        $search == "!images" => "search_special_images",
        str_starts_with($search, "!unused") => "search_special_unused",
        str_starts_with($search, "!list") => "search_special_list",
        str_starts_with($search, "!hasdata") => "search_special_hasdata",
        str_starts_with($search, "!properties") => "search_special_properties",
        $search == "!integrityfail" => "search_special_integrityfail",
        $search == "!locked" => "search_special_locked",
        checkperm('a') && $search == "!noningested" => "search_special_noningested",
        preg_match(SEARCH_SPECIAL_REPORT_PATTERN, $search) === 1 => "search_special_report",
        default => null,
    };
}

/**
 * Fill the query template for a special search, from its core builder and then the addspecialsearch hook.
 *
 * The hook always runs, with whatever the core builder produced, so a plugin can add its own special searches or
 * replace a core one. A plugin's query is given the same prefix and suffix as a core query.
 *
 * @param SpecialSearch $s The search and the query fragments do_search() prepared
 *
 * @return SpecialSearch|SearchStop|null The search with its template filled; a SearchStop when the search is
 *                                       answered without a query (no access, an invalid identifier); null when
 *                                       neither core nor a plugin recognised the search
 */
function search_special_build(SpecialSearch $s): SpecialSearch|SearchStop|null
{
    $builder = search_special_builder($s->search);
    if ($builder !== null) {
        $built = $builder($s);
        if ($built instanceof SearchStop) {
            return $built;
        }
    }

    # Within this hook implementation, set the value of the global $sql variable:
    # Since there will only be one special search executed at a time, only one of the
    # hook implementations will set the value. So, you know that the value set
    # will always be the correct one (unless two plugins use the same !<type> value).
    $hooksql = hook("addspecialsearch", "", [$s->search, $s->select, $s->join, $s->filter, $s->sql]);
    if ($hooksql instanceof PreparedStatementQuery) {
        debug("Addspecialsearch hook returned useful results.");
        $hooksql->sql = $s->prefix . $hooksql->sql . $s->suffix;
        $s->sql = $hooksql;
    }

    return $s->sql->sql != "" ? $s : null;
}

/**
 * Compile a filled special search template and run it, shaping the result for do_search()'s callers.
 *
 * The counterpart of search_execute() for special searches; both run their query through search_fetch() and pad
 * a row limited result with search_pad_rows(). A special search differs in two ways that callers and plugins may
 * rely on: a refs-only search returns the rows of the reduced query (and pads them) rather than bare references,
 * and the beforereturnresults hook is given the total/data structure and is called even when nothing matched.
 *
 * @param SpecialSearch $s                A search whose template search_special_build() filled
 * @param int|array     $fetchrows        Rows to fetch, or a [offset, count] chunk; see setup_search_chunks()
 * @param bool          $return_refs_only Run the reduced query that returns resource references only
 * @param bool          $returnsql        Return the query instead of running it
 *
 * @return array|PreparedStatementQuery The rows padded to the total, the total/data structure when $fetchrows is a
 *                                      chunk, or the query with $returnsql
 */
function search_special_finish(SpecialSearch $s, int|array $fetchrows, bool $return_refs_only, bool $returnsql): array|PreparedStatementQuery
{
    [$sql, $count_sql] = search_special_compile($s, $return_refs_only);
    if ($returnsql) {
        return $sql;
    }

    // A search with a fixed limit (!rgb) keeps a full query's chunk within that limit
    $row_cap = $return_refs_only ? null : $s->hard_limit;
    $result = search_fetch($sql, $count_sql, $fetchrows, $s->cache_count, $row_cap);
    if (is_array($fetchrows)) {
        return $result;
    }

    hook('beforereturnresults', '', [$result, $s->archive]);
    return search_pad_rows($result, $fetchrows);
}

/**
 * Fill the placeholders of a special search template to get the statement to run and the statement to count.
 *
 * The [SELECT_SQL], [GROUP_BY_SQL] and [ORDER_BY_SQL] placeholders are filled twice: once for the full query and
 * once for a reduced query that selects only the columns needed to count rows or list references and has no
 * ORDER BY. The reduced select strips the per-row columns by pattern, which only happens when no prefix wraps the
 * query, since a wrapper such as the disk usage query expects the full column list.
 *
 * @param SpecialSearch $s                A search whose template search_special_build() filled
 * @param bool          $return_refs_only Run the reduced query instead of the full query
 *
 * @return array{0: PreparedStatementQuery, 1: PreparedStatementQuery} The statement to run, then the reduced
 *                                                                     statement that counts the matches
 */
function search_special_compile(SpecialSearch $s, bool $return_refs_only): array
{
    // Construct a reduced query for refs only searches, disk usage or count only searches
    $removecolumns = ["c.date_added",
        "c.comment",
        "length\\(c\\.comment\\) commentset",
        "r.has_image",
        "r.is_transcoding",
        "r.creation_date",
        "r.user_rating_count",
        "r.user_rating_total",
        "r.user_rating",
        "r.rating",
        "r.file_extension",
        "r.preview_extension",
        "r.image_red",
        "r.image_green",
        "r.image_blue",
        "r.thumb_width",
        "r.thumb_height",
        "r.colour_key",
        "r.created_by",
        "r.file_modified",
        "r.file_checksum",
        "r.request_count",
        "r.new_hit_count",
        "r.expiry_notification_sent",
        "r.preview_tweaks",
        "r.file_path",
        "r.modified",
        "r.file_size",
        "rty.order_by",
        "c.date_added",
        "c.comment",
    ];

    $reducedselect = $s->select->sql;

    // Only reduce columns if an sql prefix is not set, this is to ensure compatibility with any encapsulating query that might be present. I.e. disk usage
    if (trim($s->prefix) == "") {
        foreach ($removecolumns as $removecolumn) {
            $reducedselect = preg_replace("/(,\s?" . $removecolumn . ")/", "", $reducedselect);
        }
        $reducedselect = preg_replace("/(,\s?r\\.field\\d+)/", "", $reducedselect); // remove any fieldXX columns from select
    }

    $placeholders = ["[SELECT_SQL]", "[GROUP_BY_SQL]", "[ORDER_BY_SQL]"];

    $reduced_sql = new PreparedStatementQuery(
        str_replace($placeholders, [$reducedselect, "GROUP BY r.ref", ""], $s->sql->sql),
        $s->sql->parameters
    );
    if ($s->hard_limit !== null) {
        $reduced_sql->sql .= " LIMIT {$s->hard_limit}";
    }
    if ($return_refs_only) {
        return [$reduced_sql, $reduced_sql];
    }

    // The ORDER BY parameters follow every other parameter; only the !rgb search has them
    $full_sql = new PreparedStatementQuery(
        str_replace($placeholders, [$s->select->sql, "GROUP BY r.ref", "ORDER BY {$s->order_by}"], $s->sql->sql),
        array_merge($s->sql->parameters, $s->order_by_params)
    );
    return [$full_sql, $reduced_sql];
}

/**
 * Build the common template for a special search that only adds a condition on the resource table.
 *
 * @param SpecialSearch $s     Special search
 * @param string        $where Condition on the resource row, with no parameters
 *
 * @return SpecialSearch
 */
function search_special_where(SpecialSearch $s, string $where): SpecialSearch
{
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql . " WHERE " . $where . " AND "
        . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!lastN": the N most recently created resources.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_last(SpecialSearch $s): SpecialSearch
{
    global $date_field;

    # Replace r2.ref with r.ref for the alternative query used here.
    $s->order_by = str_replace("r.ref", "r2.ref", $s->order_by);
    if ($s->requested_order_by == "relevance") {
        # Special case for ordering by relevance for this query.
        $direction = ((strpos($s->order_by, "DESC") === false) ? "ASC" : "DESC");
        $s->order_by = "r2.ref " . $direction;
    }

    # add date field, if access allowed, for use in $order_by
    if (metadata_field_view_access($date_field) && strpos($s->select->sql, "field" . $date_field) === false) {
        $s->select->sql .= ", field{$date_field} ";
    }

    # Extract the number of records to produce
    $last = explode(",", $s->search);
    $last = str_replace("!last", "", $last[0]);

    # !Last must be followed by an integer. SQL injection filter.
    if (is_int_loose($last)) {
        $last = (int)$last;
    } else {
        $last = 1000;
        $s->search = "!last1000";
    }

    # Fix the ORDER BY for this query (special case due to inner query)
    $s->order_by = str_replace("r.rating", "rating", $s->order_by);

    $s->sql->sql = $s->prefix . "SELECT DISTINCT *,r2.total_hit_count score FROM ("
        . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql . " WHERE " . $s->filter->sql
        . " ORDER BY ref DESC LIMIT $last ) r2 [ORDER_BY_SQL] " . $s->suffix;
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!nodownloads": resources that have never been downloaded.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_nodownloads(SpecialSearch $s): SpecialSearch
{
    if ($s->requested_order_by == "relevance") {
        $s->order_by = "ref DESC";
    }
    $s->select->sql = "r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql . "  WHERE " . $s->filter->sql
        . " AND r.ref NOT IN (SELECT DISTINCT object_ref FROM daily_stat WHERE activity_type='Resource download')"
        . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!duplicates" or "!duplicatesN": resources sharing a file checksum, with every other resource or with resource N.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch|SearchStop An empty result when N is not a resource ID
 */
function search_special_duplicates(SpecialSearch $s): SpecialSearch|SearchStop
{
    // Extract the resource ID
    $ref = explode(" ", $s->search);
    $ref = str_replace("!duplicates", "", $ref[0]);
    $ref = explode(",", $ref); // just get the number
    $ref = $ref[0];
    if ($ref != "") {
        # Find duplicates of a given resource
        if (!is_int_loose($ref)) {
            // Given resource is not a valid identifier
            return new SearchStop([], "duplicates_invalid_ref");
        }
        $s->sql->sql = $s->prefix . sprintf(
            "SELECT [SELECT_SQL]
                        FROM resource r %s
                        WHERE %s
                            AND file_checksum <> ''
                            AND file_checksum IS NOT NULL
                            AND file_checksum = (
                                SELECT file_checksum
                                    FROM resource
                                    WHERE ref= ?
                                        AND (file_checksum <> '' AND file_checksum IS NOT NULL)
                                )
                        [GROUP_BY_SQL]
                        [ORDER_BY_SQL]",
            $s->join->sql,
            $s->filter->sql
        ) . $s->suffix;
        $s->order_by = "file_checksum, ref";
        $s->sql->parameters = array_merge(
            $s->select->parameters,
            $s->join->parameters,
            $s->filter->parameters,
            ["i", $ref]
        );
    } else {
        // Find all duplicate resources
        $s->order_by = "file_checksum, ref";
        $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
            . " WHERE " . $s->filter->sql
            . " AND file_checksum IN (SELECT file_checksum FROM ("
            . "SELECT file_checksum FROM resource WHERE file_checksum <> '' AND file_checksum IS NOT null"
            . " GROUP BY file_checksum having count(file_checksum)>1)r2)"
            . " [ORDER_BY_SQL] {$s->suffix}";
        $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    }
    $s->select->sql = "r.hit_count score, {$s->select->sql}";
    return $s;
}

/**
 * "!collectionN": the resources in collection N, with their collection row.
 *
 * A user who is not an administrator may only search collections they own, can see or share, or that an external
 * share key opens, see search_special_collection_denied(). A smart collection is refreshed before it is listed. The
 * modifycollectionsearchsql hook may replace the query.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch|SearchStop An empty result when the user may not see the collection
 */
function search_special_collection(SpecialSearch $s): SpecialSearch|SearchStop
{
    $collection = search_special_collection_ref($s->search);

    $denied = search_special_collection_denied($collection);
    if ($denied !== null) {
        return new SearchStop([], $denied);
    }

    // Asking only for the disk usage does not bring a smart collection up to date
    if (!$s->return_disk_usage) {
        refresh_smart_collection($collection);
    }

    $s->select->sql = "DISTINCT c.date_added,c.comment,r.hit_count score,length(c.comment) commentset, "
        . $s->select->sql;
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r JOIN collection_resource c ON r.ref=c.resource "
        . $s->join->sql . " WHERE c.collection = ? AND (" . $s->filter->sql . ")"
        . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";

    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["i", $collection],
        $s->filter->parameters
    );

    $collectionsearchsql = hook('modifycollectionsearchsql', '', array($s->sql));

    if ($collectionsearchsql) {
        $s->sql = $collectionsearchsql;
    }
    return $s;
}

/**
 * The collection a "!collectionN" search names.
 *
 * @param string $search Search string starting with "!collection"
 *
 * @return int Collection ID, 0 when the search gives no number
 */
function search_special_collection_ref(string $search): int
{
    $collection = explode(' ', $search);
    $collection = str_replace('!collection', '', $collection[0]);
    $collection = explode(',', $collection); // just get the number
    return (int)$collection[0];
}

/**
 * Why the current user may not search a collection, or null when they may.
 *
 * A user with the 'a' permission may search any collection. Anyone else may search a collection on their list, see
 * search_special_collection_listed(), or any collection when $ignore_collection_access is set, provided that
 * collection_readable() agrees. A collection that is not on the list opens only with an external share key that was
 * issued for it, and never during an upload share.
 *
 * @param int $collection Collection ID
 *
 * @return string|null The reason to give SearchStop, or null when the search may run
 */
function search_special_collection_denied(int $collection): ?string
{
    global $ignore_collection_access, $k;

    if (checkperm('a')) {
        return null;
    }

    if (search_special_collection_listed($collection) || $ignore_collection_access) {
        return collection_readable($collection) ? null : "collection_not_readable";
    }

    // Not one of the user's collections, so only an external share key for this collection opens it. The key is
    // checked here as well as by the page: a signed in user can put any value in the URL.
    if (upload_share_active() !== false || !collection_access_key_valid($collection, (string) $k)) {
        return "collection_no_access";
    }
    return null;
}

/**
 * Whether a collection is on the list of collections the current user may search without an external share key.
 *
 * The list holds the collections get_user_collections() returns for the user (their own and those shared with them
 * or their group), their selection collection, public collections, requested collections with the 'R' permission
 * and research collections with the 'r' permission. During an upload share it holds the collections of that
 * session instead. The user's upload review collection is always on it, and a featured collection counts when the
 * user's 'j' permissions open it.
 *
 * @param int $collection Collection ID
 *
 * @return bool
 */
function search_special_collection_listed(int $collection): bool
{
    global $userref, $USER_SELECTION_COLLECTION;

    if (upload_share_active() !== false) {
        $validcollections = get_session_collections(get_rs_session_id(), $userref);
    } else {
        $user_collections = array_column(get_user_collections($userref, "", "name", "ASC", -1, false), "ref");
        $public_collections = array_column(search_public_collections('', 'name', 'ASC', true, false, false), 'ref');
        # include collections of requested resources
        $request_collections = array();
        if (checkperm("R")) {
            include_once 'request_functions.php';
            $request_collections = array_column(get_requests(), 'collection');
            $externally_requested_collections = array_column(
                ps_query('SELECT ref FROM collection WHERE user = -2'),
                'ref'
            );
            $request_collections = array_merge($request_collections, $externally_requested_collections);
        }
        # include collections of research resources
        $research_collections = array();
        if (checkperm("r")) {
            include_once 'research_functions.php';
            $research_collections = array_column(get_research_requests(), 'collection');
        }
        $validcollections = array_unique(array_merge(
            $user_collections,
            array($USER_SELECTION_COLLECTION),
            $public_collections,
            $request_collections,
            $research_collections
        ));
    }

    // Attach the negated user reference special collection
    $validcollections[] = (0 - $userref);

    return in_array($collection, $validcollections)
        || (
            in_array($collection, array_column(get_all_featured_collections(), 'ref'))
            && featured_collection_check_access_control($collection)
        );
}

/**
 * "!relatedpushedN": resources related to resource N whose resource type pushes its metadata (for the view page).
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_relatedpushed(SpecialSearch $s): SpecialSearch
{
    # Extract the resource number
    $resource = explode(" ", $s->search);
    $resource = str_replace("!relatedpushed", "", $resource[0]);

    if (isset($GLOBALS["related_pushed_order_by"])) {
        if (is_int_loose($GLOBALS["related_pushed_order_by"])) {
            if (metadata_field_view_access($GLOBALS["related_pushed_order_by"])) {
                $s->order_by = resolve_search_order_by($s->search, "field" . $GLOBALS["related_pushed_order_by"], "ASC")["order_by"];
            }
        } else {
            $s->order_by = resolve_search_order_by($s->search, $GLOBALS["related_pushed_order_by"], "ASC")["order_by"];
        }
    }

    $s->order_by = str_replace("r.", "", $s->order_by); # UNION below doesn't like table aliases in the ORDER BY.
    $s->select->sql = "DISTINCT r.hit_count score, rt.name resource_type_name, {$s->select->sql}";
    // The same query in both directions of the relationship. The halves are concatenated rather than formatted,
    // so a "%" in the join or filter SQL cannot be mistaken for a format specifier.
    $related_select = "
                    SELECT [SELECT_SQL]
                      FROM resource r
                      JOIN resource_type rt ON r.resource_type=rt.ref AND rt.push_metadata=1
                      JOIN resource_related t ON (";
    $related_where = ") " . $s->join->sql . " WHERE 1=1 AND " . $s->filter->sql . " [GROUP_BY_SQL]";

    $s->sql->sql = $s->prefix
        . $related_select . "t.related=r.ref AND t.resource = ?" . $related_where
        . " UNION "
        . $related_select . "t.resource=r.ref AND t.related= ?" . $related_where
        . " [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        ["i",$resource],
        $s->join->parameters,
        $s->filter->parameters,
        $s->select->parameters,
        ["i",$resource],
        $s->join->parameters,
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!relatedN": resources related to resource N in either direction, optionally with resource N itself.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_related(SpecialSearch $s): SpecialSearch
{
    global $pagename, $related_search_show_self;

    # Extract the resource number
    $resource = explode(" ", $s->search);
    $resource = str_replace("!related", "", $resource[0]);
    $s->order_by = str_replace("r.", "", $s->order_by); # UNION below doesn't like table aliases in the ORDER BY.

    $sql_self = new PreparedStatementQuery();
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    if ($related_search_show_self && ($pagename == 'search' || $pagename == 'collections')) {
        $sql_self->sql = " SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
            . " WHERE r.ref = ? AND " . $s->filter->sql . " GROUP BY r.ref UNION ";
        $sql_self->parameters = array_merge(
            $s->select->parameters,
            $s->join->parameters,
            ["i", $resource],
            $s->filter->parameters
        );
    }

    // The same query in both directions of the relationship: resources related to N, then resources N is related to
    $related_select = "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql . " JOIN resource_related t ON (";
    $related_where = ") WHERE " . $s->filter->sql . " [GROUP_BY_SQL]";
    $related_params = array_merge($s->select->parameters, $s->join->parameters, ["i", $resource], $s->filter->parameters);
    $s->sql->sql = $s->prefix . $sql_self->sql
        . $related_select . "t.related = r.ref AND t.resource = ?" . $related_where
        . " UNION "
        . $related_select . "t.resource = r.ref AND t.related = ?" . $related_where
        . " [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($sql_self->parameters, $related_params, $related_params);
    return $s;
}

/**
 * "!geo...": resources geotagged inside a bounding box, encoded as bottom-left "t" top-right with "b" between
 * latitude and longitude, "m" for a minus sign and "p" for a decimal point.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_geo(SpecialSearch $s): SpecialSearch
{
    global $lang;

    $geo = explode("t", str_replace(array("m","p"), array("-","."), substr($s->search, 4))); # Specially encoded string to avoid keyword splitting
    if (!isset($geo[0]) || empty($geo[0]) || !isset($geo[1]) || empty($geo[1])) {
        exit($lang["geographicsearchmissing"]);
    }
    $bl = explode("b", $geo[0]);
    $tr = explode("b", $geo[1]);
    $s->select->sql = "r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE geo_lat > ? AND geo_lat < ? AND geo_long > ? AND geo_long < ? AND " . $s->filter->sql
        . " [GROUP_BY_SQL] [ORDER_BY_SQL]" . $s->suffix;
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["d", $bl[0], "d", $tr[0], "d", $bl[1], "d", $tr[1]],
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!colourkeyK": resources whose colour key starts with K.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_colourkey(SpecialSearch $s): SpecialSearch
{
    # Extract the colour key
    $colourkey = explode(" ", $s->search);
    $colourkey = str_replace("!colourkey", "", $colourkey[0]);
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE has_image > 0 AND LEFT(colour_key,4) = ? AND " . $s->filter->sql . " [GROUP_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["s", $colourkey],
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!colourC": resources whose colour key has colour C as its first or second character.
 *
 * The two patterns are bracketed so that the base filter applies to both. Without the brackets AND binds first,
 * and a resource matching the first pattern is returned whatever its archive state or access.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_colour(SpecialSearch $s): SpecialSearch
{
    $colour = explode(" ", $s->search);
    $colour = str_replace("!colour", "", $colour[0]);
    $s->select->sql = "r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE (colour_key LIKE ? OR colour_key LIKE ?) AND " . $s->filter->sql
        . " [GROUP_BY_SQL] [ORDER_BY_SQL]" . $s->suffix;
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["s", $colour . "%", "s", "_" . $colour . "%"],
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!rgb:R,G,B": the 500 resources whose average colour is closest to the given one.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_rgb(SpecialSearch $s): SpecialSearch
{
    $rgb = explode(":", $s->search);
    $rgb = explode(",", $rgb[1]);
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE has_image > 0 AND " . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL]" . $s->suffix;
    $s->order_by = "(abs(image_red - ?)+abs(image_green - ?)+abs(image_blue - ?)) ASC";
    $s->order_by_params = ["i",$rgb[0],"i",$rgb[1],"i",$rgb[2]];
    $s->hard_limit = 500;
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!nopreview": resources without a preview image.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_nopreview(SpecialSearch $s): SpecialSearch
{
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix .
        "SELECT [SELECT_SQL]
                FROM resource r
                {$s->join->sql}
                WHERE has_image=0
                  AND {$s->filter->sql}
                [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!resourceN", or a plain number when $config_search_for_number is set: resource N.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_resource(SpecialSearch $s): SpecialSearch
{
    $searchref = preg_replace("/[^0-9]/", "", $s->search);
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE r.ref = ? AND " . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL]" . $s->suffix;
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["i", $searchref],
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!archivepending": resources awaiting archive review.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_archivepending(SpecialSearch $s): SpecialSearch
{
    return search_special_where($s, "r.archive=1");
}

/**
 * "!userpending": contributed resources awaiting review, most requested first when ordered by rating.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_userpending(SpecialSearch $s): SpecialSearch
{
    if ($s->requested_order_by == "rating") {
        $s->order_by = "request_count DESC," . $s->order_by;
    }
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql . " WHERE r.archive=-1
        AND {$s->filter->sql} [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!contributionsU": resources contributed by user U. With $open_access_for_contributor, a user searching their own
 * contributions sees them regardless of the access filter.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_contributions(SpecialSearch $s): SpecialSearch
{
    global $userref, $open_access_for_contributor;

    # Extract the user ref
    $cuser = explode(" ", $s->search);
    $cuser = str_replace("!contributions", "", $cuser[0]);

    // Don't filter if user is searching for their own resources and $open_access_for_contributor=true;
    if ($open_access_for_contributor && $userref == $cuser) {
        $s->filter->sql = "archive IN (" . ps_param_insert(count($s->archive)) . ")";
        $s->filter->parameters = ps_param_fill($s->archive, "i");
        $s->join->sql = " JOIN resource_type AS rty ON r.resource_type = rty.ref ";
        $s->join->parameters = array();
        // Remove reference to custom access
        $s->select->sql = str_replace(["rca.access", "rca2.access"], "0", $s->select->sql);
    }

    $s->select->sql = "DISTINCT r.hit_count score, " . str_replace(
        ",rca.access group_access,rca2.access user_access ",
        ",null group_access, null user_access ",
        $s->select->sql
    );
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE created_by = ? AND r.ref > 0 AND " . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        ["i", $cuser],
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!images": resources with a preview image.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_images(SpecialSearch $s): SpecialSearch
{
    return search_special_where($s, "has_image>0");
}

/**
 * "!unused": resources that are in no collection.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_unused(SpecialSearch $s): SpecialSearch
{
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix;
    $s->sql->sql .= sprintf(
        "SELECT [SELECT_SQL]
                FROM resource r %s
                WHERE r.ref>0
                    AND r.ref NOT IN (SELECT c.resource FROM collection_resource c)
                    AND %s
                [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}",
        $s->join->sql,
        $s->filter->sql
    );
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!listA:B:C" or "!listallA:B:C": the listed resources. The archive filter is not applied to "!listall"; that is
 * decided in search_special_archive_states().
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_list(SpecialSearch $s): SpecialSearch
{
    $resources = explode(" ", $s->search);
    if (str_starts_with($s->search, "!listall")) {
        $resources = str_replace("!listall", "", $resources[0]);
    } else {
        $resources = str_replace("!list", "", $resources[0]);
    }
    $resources = explode(",", $resources); // Separate out any additional keywords
    $resources = array_filter(explode(":", $resources[0]), "is_int_loose");
    $listsql = new PreparedStatementQuery();
    if (count($resources) == 0) {
        $listsql->sql = " WHERE r.ref IS NULL";
        $listsql->parameters = [];
    } else {
        $listsql->sql = " WHERE r.ref IN (" . ps_param_insert(count($resources)) . ")";
        $listsql->parameters = ps_param_fill($resources, "i");
    }

    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix;
    $s->sql->sql .= sprintf(
        "SELECT [SELECT_SQL]
                FROM resource r %s%s
                    AND %s
                [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}",
        $s->join->sql,
        $listsql->sql,
        $s->filter->sql,
    );
    $s->sql->parameters = array_merge(
        $s->select->parameters,
        $s->join->parameters,
        $listsql->parameters,
        $s->filter->parameters
    );
    return $s;
}

/**
 * "!hasdataF": resources with a value in metadata field F, useful before deleting an unused field.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_hasdata(SpecialSearch $s): SpecialSearch
{
    $fieldref = intval(trim(substr($s->search, 8)));
    $s->join->sql .= " RIGHT JOIN resource_node rn ON r.ref=rn.resource JOIN node n ON n.ref=rn.node"
        . " WHERE n.resource_type_field = ?";
    array_push($s->join->parameters, "i", $fieldref);

    // Cache this as it is a very slow query
    $s->cache_count = true;

    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " AND " . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!propertiesname:value;name:value": resources by file and image properties: hmin, hmax, wmin, wmax (pixels),
 * fmin, fmax (MB), fext (extension, "-" to exclude, "*" as a wildcard), pi (has preview), cu (contributor) and
 * orientation (portrait, landscape, square). Unknown properties are ignored.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_properties(SpecialSearch $s): SpecialSearch
{
    // Note: in order to combine special searches with normal searches, these are separated by space (" ")
    $searches_array = explode(' ', $s->search);
    $properties     = explode(';', substr($searches_array[0], 11));

    // Use a new variable to ensure nothing changes $sql_filter unless this is a valid property search
    $propertiessql = new PreparedStatementQuery();
    foreach ($properties as $property) {
        $propertycheck = explode(":", $property);
        if (count($propertycheck) == 2) {
            $propertyname   = $propertycheck[0];
            $propertyval    = $propertycheck[1];

            $sql_filter_properties_and = $propertiessql->sql != "" ? " AND "  : "";
            switch ($propertyname) {
                case "hmin":
                    $propertiessql->sql .= $sql_filter_properties_and . " rdim.height >= ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;
                case "hmax":
                    $propertiessql->sql .= $sql_filter_properties_and . " rdim.height <= ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;
                case "wmin":
                    $propertiessql->sql .= $sql_filter_properties_and . " rdim.width >= ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;
                case "wmax":
                    $propertiessql->sql .= $sql_filter_properties_and . " rdim.width <= ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;
                case "fmin":
                    // Need to convert MB value to bytes
                    $propertiessql->sql .= $sql_filter_properties_and . " r.file_size >= ?";
                    array_push($propertiessql->parameters, "i", floatval($propertyval) * 1024 * 1024);
                    break;
                case "fmax":
                    // Need to convert MB value to bytes
                    $propertiessql->sql .= $sql_filter_properties_and . " COALESCE(r.file_size, 0) <= ?";
                    array_push($propertiessql->parameters, "i", floatval($propertyval) * 1024 * 1024);
                    break;
                case "fext":
                    $propertyval = str_replace("*", "%", $propertyval);
                    $propertiessql->sql .= $sql_filter_properties_and . " r.file_extension ";
                    if (substr($propertyval, 0, 1) == "-") {
                        $propertyval = substr($propertyval, 1);
                        $propertiessql->sql .= " NOT ";
                    }
                    if (substr($propertyval, 0, 1) == ".") {
                        $propertyval = substr($propertyval, 1);
                    }
                    $propertiessql->sql .= " LIKE ?";
                    array_push($propertiessql->parameters, "s", $propertyval);
                    break;
                case "pi":
                    $propertiessql->sql .= $sql_filter_properties_and . " r.has_image = ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;
                case "cu":
                    $propertiessql->sql .= $sql_filter_properties_and . " r.created_by = ?";
                    array_push($propertiessql->parameters, "i", $propertyval);
                    break;

                case "orientation":
                    $orientation_filters = array(
                        "portrait"  => "COALESCE(rdim.height, 0) > COALESCE(rdim.width, 0)",
                        "landscape" => "COALESCE(rdim.height, 0) < COALESCE(rdim.width, 0)",
                        "square"    => "COALESCE(rdim.height, 0) = COALESCE(rdim.width, 0)",
                    );

                    if (!in_array($propertyval, array_keys($orientation_filters))) {
                        break;
                    }
                    $propertiessql->sql .= $sql_filter_properties_and .  $orientation_filters[$propertyval];
                    break;
            }
        }
    }
    if ($propertiessql->sql != "") {
        if (strpos($s->join->sql, "LEFT JOIN resource_dimensions rdim on r.ref=rdim.resource") === false) {
            $s->join->sql .= " LEFT JOIN resource_dimensions rdim on r.ref=rdim.resource";
        }
        if ($s->filter->sql == "") {
            $s->filter->sql .= " WHERE " . $propertiessql->sql;
        } else {
            $s->filter->sql .= " AND " . $propertiessql->sql;
        }
        $s->filter->parameters = array_merge($s->filter->parameters, $propertiessql->parameters);
    }

    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE r.ref > 0 AND " . $s->filter->sql . " [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    return $s;
}

/**
 * "!integrityfail": resources whose file failed its integrity check but is still present.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_integrityfail(SpecialSearch $s): SpecialSearch
{
    return search_special_where($s, "integrity_fail=1 AND no_file=0");
}

/**
 * "!locked": resources locked for editing.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_locked(SpecialSearch $s): SpecialSearch
{
    return search_special_where($s, "lock_user<>0");
}

/**
 * "!noningested" (system administrators only): resources whose file is outside the filestore, unfiltered.
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_noningested(SpecialSearch $s): SpecialSearch
{
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource r " . $s->join->sql
        . " WHERE file_path IS NOT NULL AND file_path <> '' [GROUP_BY_SQL] [ORDER_BY_SQL] {$s->suffix}";
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters);
    return $s;
}

/**
 * "!reportID...": the resources a report's query returns as thumbnails, for users who may run reports.
 *
 * Special search "!report" can contain extra info for the reports' query period.
 *
 * Syntax: !reportID[p?][d??][fy????][fm??][fd??][ty????][tm??][td??]
 * Where:
 * - ID  is the actual report ref (mandatory)
 * - p   is the selected period (see $reporting_periods_default config option)
 * - d   is the period in specific number of days (p=0 in this case)
 * - fy,fm,fd (and their counter parts: ty,tm,td) represent a full date range (p=-1 in this case)
 *
 * Examples for viewing as search results report #18:
 *  - Last 7 days: !report18p7
 *  - Last 23 days: !report18p0d23
 *  - Between 2000-01-06 & 2023-03-16: !report18p-1fy2000fm01fd06ty2023tm03td16
 *
 * @param SpecialSearch $s Special search
 *
 * @return SpecialSearch
 */
function search_special_report(SpecialSearch $s): SpecialSearch
{
    global $userref;

    preg_match(SEARCH_SPECIAL_REPORT_PATTERN, $s->search, $report_search_data);
    debug('[search_special] Running a "!report" search...');
    $s->select->sql = "DISTINCT r.hit_count score, {$s->select->sql}";
    $no_results_sql = new PreparedStatementQuery(
        $s->prefix . "SELECT [SELECT_SQL] FROM resource AS r "
        . $s->join->sql
        . ' WHERE 1 = 2 AND ' . $s->filter->sql
        . ' [GROUP_BY_SQL] [ORDER_BY_SQL] ' . $s->suffix,
        array_merge($s->join->parameters, $s->filter->parameters)
    );

    // Users with no access control to reports get no results back (ie []).
    if (!checkperm('t')) {
        debug(sprintf(
            '[WARNING][search_special][access control] User #%s attempted to run "%s" search without the right permissions',
            (int) $userref,
            $s->search
        ));
        $s->sql = $no_results_sql;
        return $s;
    }

    include_once 'reporting_functions.php';
    $report_id = $report_search_data[1];
    $all_reports = get_reports();
    $reports_w_thumbnail = array_filter(array_column($all_reports, 'query', 'ref'), 'report_has_thumbnail');
    $reports_w_support_non_correlated_sql = array_filter(array_column($all_reports, 'support_non_correlated_sql', 'ref'));
    $reports = array_diff_key($reports_w_thumbnail, $reports_w_support_non_correlated_sql);
    if (!isset($reports[$report_id])) {
        debug("[search_special] Report #{$report_id} not found");
        $s->sql = $no_results_sql;
        return $s;
    }
    $report = $reports[$report_id];

    $report_period = [];
    $report_period_info_idxs = range(2, 9);
    $report_period_info_names = array_combine($report_period_info_idxs, ['period', 'period_days', 'from-y', 'from-m', 'from-d', 'to-y', 'to-m', 'to-d']);
    $report_period_info_lookups = array_combine($report_period_info_idxs, ['p', 'd', 'fy', 'fm', 'fd', 'ty', 'tm', 'td']);
    foreach ($report_period_info_names as $idx => $info_name) {
        if (!isset($report_search_data[$idx])) {
            continue;
        }

        $report_period[$info_name] = str_replace($report_period_info_lookups[$idx], '', $report_search_data[$idx]);
    }

    $period = report_process_period($report_period);

    $report_sql = report_process_query_placeholders($report, [
        '[from-y]' => $period['from_year'],
        '[from-m]' => $period['from_month'],
        '[from-d]' => $period['from_day'],
        '[to-y]' => $period['to_year'],
        '[to-m]' => $period['to_month'],
        '[to-d]' => $period['to_day'],
    ]);
    $report_sql = preg_replace('/;\s?/m', '', $report_sql, 1);

    $s->sql->sql = $s->prefix . "SELECT [SELECT_SQL] FROM resource AS r"
        . " INNER JOIN ($report_sql) AS rsr ON rsr.thumbnail = r.ref "
        . $s->join->sql
        . ' WHERE ' . $s->filter->sql . ' [GROUP_BY_SQL] [ORDER_BY_SQL] ' . $s->suffix;
    $s->sql->parameters = array_merge($s->select->parameters, $s->join->parameters, $s->filter->parameters);
    debug("[search_special] SQL = " . json_encode($s->sql));
    return $s;
}
