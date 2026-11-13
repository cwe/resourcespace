<?php

/**
 * The base filter for do_search(): what the caller asked for and what the user may see.
 *
 * search_query_filter() adds one WHERE clause per concern to the SearchQuery, in the order the clauses appear in
 * the generated SQL: the requested resource types and date limit, the contributor restriction, resource type
 * permissions, confidential resources, archive states and pending resources, blocked states, media limits, the
 * batch upload template, a requested access level and, for an editable-only search, what the user may edit. The
 * same clauses apply to the standard query and to every special search.
 *
 * The filter does not know the special searches by name, with one exception. Which archive states a special
 * search covers is declared beside the special search dispatch, in search_special_archive_states(). The exception
 * is a collection search, which has filter rules of its own that are driven by configuration.
 *
 * Configuration comes from the SearchContext. Permissions are read live through checkperm() and
 * $userpermissions, because the modifyuserpermissions hook may replace them at the start of the filter.
 * search_filter() in search_functions.php keeps the previous signature and wraps search_query_filter().
 */

/**
 * The archive states a search covers, see search_query_filter_archive().
 */
enum SearchArchiveStates
{
    /** The states the caller asked for, or the default search states when none were requested */
    case Requested;
    /** Every state: the members of a collection or list, and pending resources, are found whatever their state */
    case Any;
    /** Every workflow state, with the search_all_workflow_states_filter hook able to narrow them */
    case AllWorkflow;
}

/**
 * Add the base filter clauses to the query.
 *
 * @param string        $search Search string; special search identifiers change the archive handling
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query being built; its filter must be empty
 *
 * @return void
 */
function search_query_filter(string $search, SearchContext $ctx, SearchQuery $query): void
{
    global $userpermissions;

    if (hook("modifyuserpermissions")) {
        $userpermissions = hook("modifyuserpermissions");
    }
    $userpermissions = (isset($userpermissions)) ? $userpermissions : array();

    search_query_filter_requested($search, $ctx, $query);
    search_query_filter_contributors($ctx, $query);
    search_query_filter_type_permissions($ctx, $query);
    search_query_filter_confidential($ctx, $query);
    search_query_filter_archive($search, $ctx, $query);
    search_query_filter_pending($search, $ctx, $query);
    search_query_filter_blocked_states($ctx, $query);
    search_query_filter_media($ctx, $query);

    # append ref filter - never return the batch upload template (negative refs)
    $query->andFilter("r.ref>0");

    search_query_filter_access_level($ctx, $query);
    if ($ctx->editable_only) {
        search_query_filter_editable($ctx, $query);
    }

    debug("do_search(): \$sql_filter = '" . $query->filter->sql . "', parameters = ['" . implode("','", $query->filter->parameters) . "']");
}

/**
 * The resource types and recent-days limit the caller asked for.
 *
 * Resource types are ignored for a "Global" selection and for a collection search, whose members may be of any
 * type.
 *
 * @param string        $search Search string
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query being built
 *
 * @return void
 */
function search_query_filter_requested(string $search, SearchContext $ctx, SearchQuery $query): void
{
    $restypes = $ctx->restypes;
    if ($restypes != "" && substr($restypes, 0, 6) != "Global" && !str_starts_with($search, "!collection")) {
        $restypes_x = explode(",", $restypes);
        $query->andFilter(
            "resource_type IN (" . ps_param_insert(count($restypes_x)) . ")",
            ps_param_fill($restypes_x, "i")
        );
    }

    $recent_search_daylimit = $ctx->recent_search_daylimit;
    if ('' != $recent_search_daylimit && is_numeric($recent_search_daylimit)) {
        $query->andFilter("creation_date > (curdate() - interval ? DAY)", ["i",$recent_search_daylimit]);
    }
}

/**
 * The $resource_created_by_filter configuration: only resources contributed by the listed users, where -1 is the
 * current user.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_contributors(SearchContext $ctx, SearchQuery $query): void
{
    if (count($ctx->created_by_filter) == 0) {
        return;
    }
    $created_filter = "";
    $created_filter_params = [];
    foreach ($ctx->created_by_filter as $filter_user) {
        if ($filter_user == -1) {
            $filter_user = $ctx->userref;
        } # '-1' can be used as an alias to the current user. I.e. they can only see their own resources in search results.
        if ($created_filter != "") {
            $created_filter .= " OR ";
        }
        $created_filter .= "created_by = ?";
        $created_filter_params[] = "i";
        $created_filter_params[] = $filter_user;
    }
    if ($created_filter != "") {
        $query->andFilter("(" . $created_filter . ")", $created_filter_params);
    }
}

/**
 * Resource types the user may not see, from the 'T' permissions. Not applied with access override.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_type_permissions(SearchContext $ctx, SearchQuery $query): void
{
    global $userpermissions;

    # look for all 'T' permissions and append to the SQL filter.
    $rtfilter = array();
    for ($n = 0; $n < count($userpermissions); $n++) {
        if (substr($userpermissions[$n], 0, 1) == "T") {
            $rt = substr($userpermissions[$n], 1);
            if (is_numeric($rt) && !$ctx->access_override) {
                $rtfilter[] = $rt;
            }
        }
    }
    if (count($rtfilter) > 0) {
        $query->andFilter(
            "resource_type NOT IN (" . ps_param_insert(count($rtfilter)) . ")",
            ps_param_fill($rtfilter, "i")
        );
    }
}

/**
 * Hide confidential resources from a user without the 'v' permission, unless custom access on the resource opens
 * them to the user or their group. Not applied with access override.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_confidential(SearchContext $ctx, SearchQuery $query): void
{
    if (!checkperm("v") && !$ctx->access_override) {
        # Check both the resource access, but if confidential is returned, also look at the joined user-specific or
        # group-specific custom access for rows.
        $query->andFilter(
            "(r.access<>'2' OR (r.access=2 AND ((rca.access IS NOT null AND rca.access<>2)"
            . " OR (rca2.access IS NOT null AND rca2.access<>2))))"
        );
    }
}

/**
 * Archive states. Not applied with access override.
 *
 * search_special_archive_states() says which states the search covers; the $search_all_workflow_states
 * configuration widens an ordinary search to every workflow state.
 *
 * - Any: no condition, apart from archived members of a collection when $collections_omit_archived applies to
 *   the user.
 * - AllWorkflow: no condition, but the search_all_workflow_states_filter hook may add one.
 * - Requested: the states the caller asked for, or the default search states when none were requested or
 *   $archive_standard is set. A smart search always uses the states it asked for.
 *
 * @param string        $search Search string
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query being built
 *
 * @return void
 */
function search_query_filter_archive(string $search, SearchContext $ctx, SearchQuery $query): void
{
    if ($ctx->access_override) {
        return;
    }

    $states = search_special_archive_states($search);
    if ($states === SearchArchiveStates::Requested && $ctx->search_all_workflow_states) {
        $states = SearchArchiveStates::AllWorkflow;
    }

    if ($states === SearchArchiveStates::Any) {
        if (str_starts_with($search, "!collection") && $ctx->collections_omit_archived && !checkperm("e2")) {
            $query->andFilter("archive<>2");
        }
        return;
    }
    if ($states === SearchArchiveStates::AllWorkflow) {
        hook("search_all_workflow_states_filter", "", [$query->filter]);
        return;
    }

    $archive = array_filter($ctx->archive, is_int_loose(...));
    if (count($archive) == 0 || $ctx->archive_standard && !$ctx->smartsearch) {
        # If no archive specified add in default archive states (set by config options or as set in rse_workflow plugin)
        # Defaults are not used if searching smartsearch collection, actual values will be used instead
        $archive = get_default_search_states();
        if (count($archive) == 0) {
            // Make sure we have at least one state - system has been misconfigured
            $archive[] = 0;
        }
    }
    $query->andFilter("archive IN (" . ps_param_insert(count($archive)) . ")", ps_param_fill($archive, "i"));
}

/**
 * Pending resources. Not applied with access override.
 *
 * Whatever the search, a user without the 'v' permission does not see resources pending submission or review
 * (archive states -2 and -1) unless they contributed them or the resource type is covered by an 'ert'
 * permission. The exception is a collection shared with a key when $collection_allow_not_approved_share is set:
 * the key must be one issued for that collection, because a signed in user can put any value in the URL.
 *
 * @param string        $search Search string
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query being built
 *
 * @return void
 */
function search_query_filter_pending(string $search, SearchContext $ctx, SearchQuery $query): void
{
    if ($ctx->access_override || checkperm("v")) {
        return;
    }
    $shared_collection = str_starts_with($search, "!collection")
        && $ctx->external_share
        && $ctx->collection_allow_not_approved_share
        && collection_access_key_valid(search_special_collection_ref($search), $ctx->share_key);
    if ($shared_collection) {
        return;
    }

    $ert_types = search_filter_ert_types();
    $query->andFilter(
        "(((r.archive<>-2 OR r.created_by = ?) AND (r.archive<>-1 OR r.created_by = ?)) "
        . str_repeat(" OR (resource_type = ?)", count($ert_types)) . ")",
        array_merge(["i", $ctx->userref, "i", $ctx->userref], ps_param_fill($ert_types, "i"))
    );
}

/**
 * Archive states hidden from the user by 'z' permissions, including $additional_archive_states. With
 * $uploader_view_override the user still sees their own resources in those states. Not applied with access
 * override.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_blocked_states(SearchContext $ctx, SearchQuery $query): void
{
    $filterblockstates = [];
    for ($n = -2; $n <= 3; $n++) {
        if (checkperm("z" . $n) && !$ctx->access_override) {
            $filterblockstates[] = $n;
        }
    }
    foreach ($ctx->additional_archive_states as $additional_archive_state) {
        if (checkperm("z" . $additional_archive_state)) {
            $filterblockstates[] = $additional_archive_state;
        }
    }
    if (count($filterblockstates) == 0 || $ctx->access_override) {
        return;
    }
    if ($ctx->uploader_view_override) {
        $query->andFilter(
            "(archive NOT IN (" . ps_param_insert(count($filterblockstates)) . ") OR created_by = ?)",
            array_merge(ps_param_fill($filterblockstates, "i"), ["i", $ctx->userref])
        );
    } else {
        $query->andFilter(
            "archive NOT IN (" . ps_param_insert(count($filterblockstates)) . ")",
            ps_param_fill($filterblockstates, "i")
        );
    }
}

/**
 * The $heightmin configuration: a minimum image height.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_media(SearchContext $ctx, SearchQuery $query): void
{
    if ($ctx->heightmin != '') {
        $query->andFilter("dim.height>= ? ", ["i", $ctx->heightmin]);
    }
}

/**
 * A specific access level, which only a user with the 'v' permission may ask for.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_access_level(SearchContext $ctx, SearchQuery $query): void
{
    if (checkperm("v") && !is_null($ctx->access)) {
        $query->andFilter("r.access = ?", ["i", $ctx->access]);
    }
}

/**
 * For an editable-only search: what the user may edit.
 *
 * The clause is built from one part per rule, joined with AND, in the order below. A part that does not apply to
 * the user is left out. The modifysearcheditable hook is given the finished clause and may replace it.
 *
 * - search_editable_restricted(): restricted resources
 * - search_editable_pending(): pending resources
 * - search_editable_states(): archive states the user has no 'e' permission for
 * - search_editable_allowed_types(): resource types allowed by 'XE-' permissions
 * - search_editable_blocked_types(): resource types blocked by 'XE' permissions
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_editable(SearchContext $ctx, SearchQuery $query): void
{
    $ert_types = search_filter_ert_types();
    $resource_types = get_resource_types("", false, false, true);
    $parts = [
        search_editable_restricted($ctx),
        search_editable_pending($ctx, $ert_types),
        search_editable_states($ctx, $ert_types),
        search_editable_allowed_types($ctx, $resource_types),
        search_editable_blocked_types($ctx, $resource_types),
    ];

    $editable_filter = new PreparedStatementQuery();
    foreach ($parts as $part) {
        if ($part === null) {
            continue;
        }
        $editable_filter->sql .= ($editable_filter->sql != "" ? " AND " : "") . $part->sql;
        $editable_filter->parameters = array_merge($editable_filter->parameters, $part->parameters);
    }

    $updated_editable_filter = hook("modifysearcheditable", "", array($editable_filter, $ctx->userref));
    if ($updated_editable_filter !== false) {
        $editable_filter = $updated_editable_filter;
    }

    $query->andFilter($editable_filter->sql, $editable_filter->parameters);
}

/**
 * Resource types named by the user's 'ert' permissions. An 'ert' permission gives edit access to resources of
 * that type whatever their archive state, so these types are exceptions to the pending and state rules.
 *
 * @return array Resource type references
 */
function search_filter_ert_types(): array
{
    global $userpermissions;

    $types = [];
    foreach ($userpermissions as $permission) {
        $type = substr($permission, 3);
        if (substr($permission, 0, 3) == "ert" && is_int_loose($type)) {
            $types[] = $type;
        }
    }
    return $types;
}

/**
 * Editable-only part: a user without the 'v' permission cannot edit restricted resources, unless custom access
 * opens them or they have no custom access record at all. Not applied with access override.
 *
 * @param SearchContext $ctx Search context
 *
 * @return PreparedStatementQuery|null The part, or null when it does not apply
 */
function search_editable_restricted(SearchContext $ctx): ?PreparedStatementQuery
{
    if (checkperm("v") || $ctx->access_override) {
        return null;
    }
    // "OR (rca.access IS NULL AND rca2.access IS NULL)" was added 2020-03-02 so that resources without an entry in
    // the resource_custom_access table are included in the search results
    return new PreparedStatementQuery(
        "(r.access <> 1 OR (r.access = 1 AND ((rca.access IS NOT null AND rca.access <> 1)"
        . " OR (rca2.access IS NOT null AND rca2.access <> 1)"
        . " OR (rca.access IS NULL AND rca2.access IS NULL)))) "
    );
}

/**
 * Editable-only part: without the 't' permission, a pending resource (archive state -2 or -1) must have been
 * contributed by the user or be of a type covered by an 'ert' permission.
 *
 * @param SearchContext $ctx       Search context
 * @param array         $ert_types Resource types from search_filter_ert_types()
 *
 * @return PreparedStatementQuery|null The part, or null when it does not apply
 */
function search_editable_pending(SearchContext $ctx, array $ert_types): ?PreparedStatementQuery
{
    if (checkperm("t")) {
        return null;
    }
    $part = new PreparedStatementQuery("(archive NOT IN (-2,-1) OR (created_by = ?", ["i", $ctx->userref]);
    if (count($ert_types) > 0) {
        $part->sql .= " OR resource_type IN (" . ps_param_insert(count($ert_types)) . ")";
        $part->parameters = array_merge($part->parameters, ps_param_fill($ert_types, "i"));
    }
    $part->sql .= "))";
    return $part;
}

/**
 * Editable-only part: archive states the user has no 'e' permission for are excluded, including any of
 * $additional_archive_states.
 *
 * The exception is a resource the user contributed when $edit_access_for_contributor is set, or a resource of a
 * type covered by an 'ert' permission. Either is enough, as it is for get_edit_access().
 *
 * @param SearchContext $ctx       Search context
 * @param array         $ert_types Resource types from search_filter_ert_types()
 *
 * @return PreparedStatementQuery|null The part, or null when the user may edit in every state
 */
function search_editable_states(SearchContext $ctx, array $ert_types): ?PreparedStatementQuery
{
    $blocked_states = [];
    foreach (array_merge(range(-2, 3), $ctx->additional_archive_states) as $state) {
        if (!checkperm("e" . $state)) {
            $blocked_states[] = $state;
        }
    }
    if (count($blocked_states) == 0) {
        return null;
    }

    $exception = "";
    $exception_params = [];
    if ($ctx->edit_access_for_contributor) {
        $exception = " created_by = ?";
        $exception_params = ["i", $ctx->userref];
    }
    if (count($ert_types) > 0) {
        $exception .= ($exception != "" ? " OR " : "") . "resource_type IN (" . ps_param_insert(count($ert_types)) . ")";
        $exception_params = array_merge($exception_params, ps_param_fill($ert_types, "i"));
    }

    $part = new PreparedStatementQuery(
        "(archive NOT IN (" . ps_param_insert(count($blocked_states)) . ")",
        ps_param_fill($blocked_states, "i")
    );
    if ($exception != "") {
        $part->sql .= " OR " . $exception;
        $part->parameters = array_merge($part->parameters, $exception_params);
    }
    $part->sql .= ")";
    return $part;
}

/**
 * Editable-only part: with the 'XE' permission the user may only edit resource types named by an 'XE-' permission,
 * and nothing at all when there are none. A contributor may still edit their own resources when
 * $edit_access_for_contributor is set.
 *
 * @param SearchContext $ctx            Search context
 * @param array         $resource_types Every resource type, as returned by get_resource_types()
 *
 * @return PreparedStatementQuery|null The part, or null when the user does not have the 'XE' permission
 */
function search_editable_allowed_types(SearchContext $ctx, array $resource_types): ?PreparedStatementQuery
{
    if (!checkperm("XE")) {
        return null;
    }
    $allowed = [];
    foreach ($resource_types as $resource_type) {
        if (checkperm("XE-" . $resource_type["ref"])) {
            $allowed[] = $resource_type["ref"];
        }
    }
    if (count($allowed) == 0) {
        return new PreparedStatementQuery(" 0=1");
    }
    return search_editable_type_part("resource_type IN", $allowed, $ctx);
}

/**
 * Editable-only part: resource types named by an 'XE' permission cannot be edited. A contributor may still edit
 * their own resources when $edit_access_for_contributor is set.
 *
 * @param SearchContext $ctx            Search context
 * @param array         $resource_types Every resource type, as returned by get_resource_types()
 *
 * @return PreparedStatementQuery|null The part, or null when no resource type is blocked
 */
function search_editable_blocked_types(SearchContext $ctx, array $resource_types): ?PreparedStatementQuery
{
    $blocked = [];
    foreach ($resource_types as $resource_type) {
        if (checkperm("XE" . $resource_type["ref"])) {
            $blocked[] = $resource_type["ref"];
        }
    }
    if (count($blocked) == 0) {
        return null;
    }
    return search_editable_type_part("resource_type NOT IN", $blocked, $ctx);
}

/**
 * A resource type condition for the editable-only filter, with the contributor exception.
 *
 * @param string        $condition "resource_type IN" or "resource_type NOT IN"
 * @param array         $types     Resource type references; not empty
 * @param SearchContext $ctx       Search context
 *
 * @return PreparedStatementQuery
 */
function search_editable_type_part(string $condition, array $types, SearchContext $ctx): PreparedStatementQuery
{
    $part = new PreparedStatementQuery(
        "(" . $condition . " (" . ps_param_insert(count($types)) . ")",
        ps_param_fill($types, "i")
    );
    if ($ctx->edit_access_for_contributor) {
        $part->sql .= " OR  created_by = ?";
        array_push($part->parameters, "i", $ctx->userref);
    }
    $part->sql .= ")";
    return $part;
}
