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
 * Configuration comes from the SearchContext. Permissions are read live through checkperm() and
 * $userpermissions, because the modifyuserpermissions hook may replace them at the start of the filter.
 * search_filter() in search_functions.php keeps the previous signature and wraps search_query_filter().
 */

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
    if (($restypes != "") && (substr($restypes, 0, 6) != "Global") && substr($search, 0, 11) != '!collection') {
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
 * Archive states and pending resources. Not applied with access override.
 *
 * A collection, list or pending search accepts members in any state, apart from archived members of a collection
 * when $collections_omit_archived applies to the user. Searches over all workflow states leave the states to the
 * search_all_workflow_states_filter hook. Otherwise the requested states apply, or the default search states when
 * none were requested or $archive_standard is set. A user without the 'v' permission then also loses pending
 * resources they did not contribute, except for resource types covered by an 'ert' permission, and except inside
 * a collection shared with a key when $collection_allow_not_approved_share is set.
 *
 * @param string        $search Search string
 * @param SearchContext $ctx    Search context
 * @param SearchQuery   $query  Query being built
 *
 * @return void
 */
function search_query_filter_archive(string $search, SearchContext $ctx, SearchQuery $query): void
{
    global $userpermissions;

    if ($ctx->access_override) {
        return;
    }
    $archive = array_filter($ctx->archive, is_int_loose(...));

    $any_state = substr($search, 0, 11) == "!collection"
        || substr($search, 0, 5) == "!list"
        || substr($search, 0, 15) == "!archivepending"
        || substr($search, 0, 12) == "!userpending";
    $all_workflow_states = $ctx->search_all_workflow_states
        || substr($search, 0, 8) == "!related"
        || substr($search, 0, 8) == "!hasdata"
        || strpos($search, "integrityfail") !== false;

    if ($any_state) {
        # Resources in a collection or list may be in any archive state
        # Other special searches define the archive state in search_special()
        if (substr($search, 0, 11) == "!collection" && $ctx->collections_omit_archived && !checkperm("e2")) {
            $query->andFilter("archive<>2");
        }
    } elseif ($all_workflow_states) {
        hook("search_all_workflow_states_filter", "", [$query->filter]);
    } elseif (count($archive) == 0 || $ctx->archive_standard && !$ctx->smartsearch) {
        # If no archive specified add in default archive states (set by config options or as set in rse_workflow plugin)
        # Defaults are not used if searching smartsearch collection, actual values will be used instead
        $defaultsearchstates = get_default_search_states();
        if (count($defaultsearchstates) == 0) {
            // Make sure we have at least one state - system has been misconfigured
            $defaultsearchstates[] = 0;
        }
        $query->andFilter(
            "archive IN (" . ps_param_insert(count($defaultsearchstates)) . ")",
            ps_param_fill($defaultsearchstates, "i")
        );
    } else {
        # Append normal filtering - extended as advanced search now allows searching by archive state
        $query->andFilter("archive IN (" . ps_param_insert(count($archive)) . ")", ps_param_fill($archive, "i"));
    }

    $shared_collection = substr($search, 0, 11) == "!collection"
        && $ctx->external_share
        && $ctx->collection_allow_not_approved_share;
    if (!checkperm("v") && !$shared_collection) {
        // Append standard filtering to hide resources in a pending state, whatever the search
        // except when the resource is of a type that the user has ert permission for
        $rtexclusions = "";
        $rtexclusions_params = [];
        for ($n = 0; $n < count($userpermissions); $n++) {
            if (substr($userpermissions[$n], 0, 3) == "ert") {
                $rt = substr($userpermissions[$n], 3);
                if (is_int_loose($rt)) {
                    $rtexclusions .= " OR (resource_type = ?)";
                    array_push($rtexclusions_params, "i", $rt);
                }
            }
        }
        $query->andFilter(
            "(((r.archive<>-2 OR r.created_by = ?) AND (r.archive<>-1 OR r.created_by = ?)) " . $rtexclusions . ")",
            array_merge(["i",$ctx->userref,"i",$ctx->userref], $rtexclusions_params)
        );
    }
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
 * Restricted resources are excluded for a user without the 'v' permission unless custom access opens them. Without
 * the 't' permission, pending resources must be the user's own or of a type covered by an 'ert' permission. States
 * without an 'e' permission are excluded, unless the user contributed the resource and
 * $edit_access_for_contributor is set, or the type is covered by an 'ert' permission. 'XE' permissions block or
 * allow editing by resource type. The modifysearcheditable hook may replace the whole clause.
 *
 * @param SearchContext $ctx   Search context
 * @param SearchQuery   $query Query being built
 *
 * @return void
 */
function search_query_filter_editable(SearchContext $ctx, SearchQuery $query): void
{
    global $userpermissions;

    $userref = $ctx->userref;
    $editable_filter = new PreparedStatementQuery();
    if (!checkperm("v") && !$ctx->access_override) {
        // following condition added 2020-03-02 so that resources without an entry in the resource_custom_access table
        // are included in the search results - "OR (rca.access IS NULL AND rca2.access IS NULL)"
        $editable_filter->sql .= "(r.access <> 1 OR (r.access = 1 AND ((rca.access IS NOT null AND rca.access <> 1)"
            . " OR (rca2.access IS NOT null AND rca2.access <> 1)"
            . " OR (rca.access IS NULL AND rca2.access IS NULL)))) ";
    }

    # Construct resource type exclusion based on 'ert' permission
    # look for all 'ert' permissions and append to the exclusion array.
    $rtexclusions = array();
    for ($n = 0; $n < count($userpermissions); $n++) {
        if (substr($userpermissions[$n], 0, 3) == "ert") {
            $rt = substr($userpermissions[$n], 3);
            if (is_numeric($rt)) {
                $rtexclusions[] = $rt;
            }
        }
    }

    $blockeditstates = array();
    for ($n = -2; $n <= 3; $n++) {
        if (!checkperm("e" . $n)) {
            $blockeditstates[] = $n;
        }
    }
    foreach ($ctx->additional_archive_states as $additional_archive_state) {
        if (!checkperm("e" . $additional_archive_state)) {
            $blockeditstates[] = $additional_archive_state;
        }
    }
    // Add code to hide resources in archive<0 unless has 't' permission, resource has been contributed by user or has ert permission
    if (!checkperm("t")) {
        if ($editable_filter->sql != "") {
            $editable_filter->sql .= " AND ";
        }
        $editable_filter->sql .= "(archive NOT IN (-2,-1) OR (created_by = ?";
        $editable_filter->parameters = ["i",$userref];
        if (count($rtexclusions) > 0) {
            $editable_filter->sql  .= " OR resource_type IN (" . ps_param_insert(count($rtexclusions)) . ")";
            $editable_filter->parameters = array_merge($editable_filter->parameters, ps_param_fill($rtexclusions, "i"));
        }
        $editable_filter->sql .= "))";
    }

    if (count($blockeditstates) > 0) {
        $blockeditoverride          = "";
        $blockeditoverride_params   = [];
        if ($ctx->edit_access_for_contributor) {
            $blockeditoverride .= " created_by = ?";
            $blockeditoverride_params[] = "i";
            $blockeditoverride_params[] = $userref;
        }
        if (count($rtexclusions) > 0) {
            if ($blockeditoverride != "") {
                $blockeditoverride .= " AND ";
            }
            $blockeditoverride .= "resource_type IN (" . ps_param_insert(count($rtexclusions)) . ")";
            $blockeditoverride_params = array_merge($blockeditoverride_params, ps_param_fill($rtexclusions, "i"));
        }
        if ($editable_filter->sql != "") {
            $editable_filter->sql .= " AND ";
        }

        $editable_filter->sql .= "(archive NOT IN (" . ps_param_insert(count($blockeditstates)) . ")";
        $editable_filter->parameters = array_merge($editable_filter->parameters, ps_param_fill($blockeditstates, "i"));
        if ($blockeditoverride != "") {
            $editable_filter->sql .= " OR " . $blockeditoverride;
            $editable_filter->parameters = array_merge($editable_filter->parameters, $blockeditoverride_params);
        }
        $editable_filter->sql .= ")";
    }

    // Check for blocked/allowed resource types
    $allrestypes = get_resource_types("", false, false, true);
    $blockedrestypes = array();
    foreach ($allrestypes as $restype) {
        if (checkperm("XE" . $restype["ref"])) {
            $blockedrestypes[] = $restype["ref"];
        }
    }
    if (checkperm("XE")) {
        $okrestypes = array();
        $okrestypesor = "";
        $okrestypesorparams = [];
        foreach ($allrestypes as $restype) {
            if (checkperm("XE-" . $restype["ref"])) {
                $okrestypes[] = $restype["ref"];
            }
        }
        if (count($okrestypes) > 0) {
            if ($editable_filter->sql != "") {
                $editable_filter->sql .= " AND ";
            }
            if ($ctx->edit_access_for_contributor) {
                $okrestypesor .= " created_by = ?";
                $okrestypesorparams = ["i",$userref];
            }
            $editable_filter->sql .= "(resource_type IN (" . ps_param_insert(count($okrestypes)) . ")"
                . (($okrestypesor != "") ? " OR " . $okrestypesor : "") . ")";
            $editable_filter->parameters = array_merge(
                $editable_filter->parameters,
                ps_param_fill($okrestypes, "i"),
                $okrestypesorparams
            );
        } else {
            if ($editable_filter->sql != "") {
                $editable_filter->sql .= " AND ";
            }
            $editable_filter->sql .= " 0=1";
        }
    }

    if (count($blockedrestypes) > 0) {
        $blockrestypesor = "";
        $blockrestypesorparams = [];
        if ($ctx->edit_access_for_contributor) {
            $blockrestypesor .= " created_by = ?";
            $blockrestypesorparams = ["i",$userref];
        }
        if ($editable_filter->sql != "") {
            $editable_filter->sql .= " AND ";
        }
        $editable_filter->sql .= "(resource_type NOT IN (" . ps_param_insert(count($blockedrestypes)) . ")"
            . (($blockrestypesor != "") ? " OR " . $blockrestypesor : "") . ")";
        $editable_filter->parameters = array_merge(
            $editable_filter->parameters,
            ps_param_fill($blockedrestypes, "i"),
            $blockrestypesorparams
        );
    }

    $updated_editable_filter = hook("modifysearcheditable", "", array($editable_filter,$userref));
    if ($updated_editable_filter !== false) {
        $editable_filter = $updated_editable_filter;
    }

    $query->andFilter($editable_filter->sql, $editable_filter->parameters);
}
