<?php

/**
 * Read-only description of who is searching, with which options, under which permissions and configuration.
 *
 * Built once per do_search() call by search_context_create(). Everything the search pipeline needs to know about the
 * caller lives here so that the query-building steps do not read globals or call permission helpers themselves.
 *
 * Call options mirror the do_search() parameters after normalisation (validated sort, exploded archive list, hook
 * adjusted fetch rows). Permission flags are resolved with checkperm() at construction time. Legacy text search and
 * edit filters are migrated to filter IDs during construction; see resolve_user_search_filter().
 */
final class SearchContext
{
    public function __construct(
        // Call options
        public readonly string $restypes,
        public readonly array $archive,
        public readonly int|array $fetchrows,
        public readonly string $sort,
        public readonly string $requested_order_by,
        public readonly bool $access_override,
        public readonly bool $ignore_filters,
        public readonly bool $return_disk_usage,
        public readonly string $recent_search_daylimit,
        public readonly string|bool $go,
        public readonly bool $stats_logging,
        public readonly bool $return_refs_only,
        public readonly bool $editable_only,
        public readonly bool $returnsql,
        public readonly ?int $access,
        public readonly bool $smartsearch,
        // Caller identity and permissions
        public readonly int $userref,
        public readonly int $usergroup,
        public readonly bool $sees_all_access,
        public readonly bool $sees_restricted,
        public readonly bool $featured_only,
        public readonly array $x_restricted_types,
        public readonly bool $internal_share,
        public readonly bool $external_share,
        public readonly ?int $search_filter_id,
        public readonly ?int $edit_filter_id,
        public readonly ?int $derestrict_filter_id,
        public readonly array $hidden_indexed_fields,
        public readonly array $resource_types,
        // Configuration snapshot
        public readonly bool $stemming,
        public readonly bool $wildcard_always_applied,
        public readonly bool $index_contributed_by,
        public readonly bool $search_for_number,
        public readonly bool $category_tree_and_logic,
        public readonly array $noadd,
        public readonly array $separators,
        public readonly int $date_field,
        public readonly array $date_field_types,
        public readonly array $fixed_list_field_types,
        public readonly int $view_title_field,
    ) {
    }
}

/**
 * Build the SearchContext for a do_search() call.
 *
 * Reads the current user's permissions, filters and the search related configuration once, normalises the call
 * options and applies the 'modifyfetchrows' hook.
 *
 * Side effects: a legacy text search filter (and, when $editable_only is set, a legacy text edit filter) is migrated
 * to a filter ID on first use, which updates the user/usergroup record and notifies administrators. See
 * resolve_user_search_filter() and resolve_user_edit_filter().
 *
 * @param array $options Call options keyed by do_search() parameter name: restypes, order_by, archive, fetchrows,
 *                       sort, access_override, ignore_filters, return_disk_usage, recent_search_daylimit, go,
 *                       stats_logging, return_refs_only, editable_only, returnsql, access, smartsearch.
 *                       Missing keys take the do_search() defaults.
 *
 * @return SearchContext
 */
function search_context_create(array $options): SearchContext
{
    global $userref, $usergroup, $k, $internal_share_access, $userderestrictfilter,
        $stemming, $wildcard_always_applied, $index_contributed_by, $config_search_for_number,
        $category_tree_search_use_and_logic, $noadd, $config_separators, $date_field,
        $DATE_FIELD_TYPES, $FIXED_LIST_FIELD_TYPES, $view_title_field;

    $defaults = [
        'restypes'               => '',
        'order_by'               => 'relevance',
        'archive'                => '0',
        'fetchrows'              => -1,
        'sort'                   => 'desc',
        'access_override'        => false,
        'ignore_filters'         => false,
        'return_disk_usage'      => false,
        'recent_search_daylimit' => '',
        'go'                     => false,
        'stats_logging'          => true,
        'return_refs_only'       => false,
        'editable_only'          => false,
        'returnsql'              => false,
        'access'                 => null,
        'smartsearch'            => false,
    ];
    $options = array_merge($defaults, $options);

    $fetchrows = $options['fetchrows'];
    $modifyfetchrows = hook('modifyfetchrows', '', [$fetchrows]);
    if ($modifyfetchrows) {
        $fetchrows = $modifyfetchrows;
    }

    $sort = (string) $options['sort'];
    if (!validate_sort_value($sort)) {
        $sort = 'asc';
    }

    $archive = $options['archive'];
    $archive = is_array($archive) ? $archive : explode(',', (string) $archive);

    $editable_only = (bool) $options['editable_only'];

    $sees_all_access = checkperm('v');
    $sees_restricted = checkperm('g');
    $internal_share = (bool) $internal_share_access;
    $resource_types = get_resource_types();

    // Resource types restricted by the X permission. search_query_select() only consults them for a user who does
    // not see every resource but does see restricted ones (or is using an internal share), so they are resolved
    // only then.
    $x_restricted_types = [];
    if (!$sees_all_access && ($sees_restricted || $internal_share)) {
        foreach ($resource_types as $resource_type) {
            if (checkperm('X' . $resource_type['ref'])) {
                $x_restricted_types[] = $resource_type['ref'];
            }
        }
    }

    return new SearchContext(
        restypes: (string) ($options['restypes'] ?? ''),
        archive: $archive,
        fetchrows: is_array($fetchrows) ? $fetchrows : (int) $fetchrows,
        sort: $sort,
        requested_order_by: (string) $options['order_by'],
        access_override: (bool) $options['access_override'],
        ignore_filters: (bool) $options['ignore_filters'],
        return_disk_usage: (bool) $options['return_disk_usage'],
        recent_search_daylimit: (string) ($options['recent_search_daylimit'] ?? ''),
        go: is_string($options['go']) ? $options['go'] : (bool) $options['go'],
        stats_logging: (bool) $options['stats_logging'],
        return_refs_only: (bool) $options['return_refs_only'],
        editable_only: $editable_only,
        returnsql: (bool) $options['returnsql'],
        access: is_numeric($options['access']) ? (int) $options['access'] : null,
        smartsearch: (bool) $options['smartsearch'],
        userref: (int) $userref,
        usergroup: (int) $usergroup,
        sees_all_access: $sees_all_access,
        sees_restricted: $sees_restricted,
        featured_only: checkperm('J'),
        x_restricted_types: $x_restricted_types,
        internal_share: $internal_share,
        external_share: trim((string) $k) != '',
        search_filter_id: resolve_user_search_filter(),
        edit_filter_id: $editable_only ? resolve_user_edit_filter() : null,
        derestrict_filter_id: is_int_loose($userderestrictfilter) && $userderestrictfilter > 0 ? (int) $userderestrictfilter : null,
        hidden_indexed_fields: get_hidden_indexed_fields(),
        resource_types: $resource_types,
        stemming: (bool) $stemming,
        wildcard_always_applied: (bool) $wildcard_always_applied,
        index_contributed_by: (bool) $index_contributed_by,
        search_for_number: (bool) $config_search_for_number,
        category_tree_and_logic: (bool) $category_tree_search_use_and_logic,
        noadd: is_array($noadd) ? $noadd : [],
        separators: is_array($config_separators) ? $config_separators : [],
        date_field: (int) $date_field,
        date_field_types: $DATE_FIELD_TYPES,
        fixed_list_field_types: $FIXED_LIST_FIELD_TYPES,
        view_title_field: (int) $view_title_field,
    );
}

/**
 * Resolve the current user's search filter to a filter ID.
 *
 * A legacy text filter ($usersearchfilter holding filter text rather than an ID) is migrated with migrate_filter()
 * the first time it is seen: on success the user override or usergroup record is updated to the new filter ID and
 * $usersearchfilter is replaced with it; on failure the record is marked with -1 so migration is not retried.
 * Administrators are notified of both outcomes. Previously performed inline by do_search(); to be moved to a
 * migration script (q13255).
 *
 * @return int|null The filter ID to apply, or null when no numeric filter is set
 */
function resolve_user_search_filter(): ?int
{
    global $usersearchfilter, $userdata, $usergroup, $userref, $lang, $baseurl;

    if (
        strlen(trim((string) $usersearchfilter)) > 0
        && !is_numeric($usersearchfilter)
        && (
            (isset($userdata[0]["search_filter_override"]) && trim($userdata[0]["search_filter_override"]) != ""
                && isset($userdata[0]["search_filter_o_id"]) && $userdata[0]["search_filter_o_id"] != -1)
            ||
            (isset($userdata[0]["search_filter"]) && trim($userdata[0]["search_filter"]) != ""
                && isset($userdata[0]["search_filter_id"]) && $userdata[0]["search_filter_id"] != -1)
            )
    ) {
        // Migrate old style filter unless previously failed attempt
        $migrateresult = migrate_filter($usersearchfilter);
        $notification_users = get_notification_users();
        if (is_numeric($migrateresult)) {
            message_add(array_column($notification_users, "ref"), $lang["filter_migrate_success"] . ": '" . $usersearchfilter . "'", generateURL($baseurl . "/pages/admin/admin_group_management_edit.php", array("ref" => $usergroup)));

            // Successfully migrated - now use the new filter
            if (isset($userdata[0]["search_filter_override"]) && $userdata[0]["search_filter_override"] != '') {
                // This was a user override filter - update the user record
                ps_query("UPDATE user SET search_filter_o_id = ? WHERE ref = ?", ["i",$migrateresult,"i",$userref]);
            } else {
                save_usergroup($usergroup, array('search_filter_id' => $migrateresult));
            }
            $usersearchfilter = $migrateresult;
            debug("FILTER MIGRATION: Migrated filter - new filter id#" . $usersearchfilter);
        } elseif (is_array($migrateresult)) {
            debug("FILTER MIGRATION: Error migrating filter: '" . $usersearchfilter . "' - " . implode('\n', $migrateresult));
            // Error - set flag so as not to reattempt migration and notify admins of failure
            if (isset($userdata[0]["search_filter_override"]) && $userdata[0]["search_filter_override"] != '') {
                ps_query("UPDATE user SET search_filter_o_id='-1' WHERE ref = ?", ["i",$userref]);
            } else {
                save_usergroup($usergroup, array('search_filter_id' => -1));
            }

            message_add(array_column($notification_users, "ref"), $lang["filter_migration"] . " - " . $lang["filter_migrate_error"] . ": <br />" . implode('\n', $migrateresult), generateURL($baseurl . "/pages/admin/admin_group_management_edit.php", array("ref" => $usergroup)));
        }
    }

    // Old text filters are no longer supported
    return is_int_loose($usersearchfilter) && $usersearchfilter > 0 ? (int) $usersearchfilter : null;
}

/**
 * Resolve the current user's edit filter to a filter ID.
 *
 * A legacy text edit filter is first reduced with edit_filter_to_restype_permission() (resource type rules become
 * permissions) and the remainder migrated with migrate_filter(). The usergroup record is updated with the new filter
 * ID, or marked with -1 on failure so migration is not retried, and administrators are notified of failures.
 * Previously performed inline by do_search() for editable-only searches.
 *
 * @return int|null The edit filter ID to apply, or null when no numeric filter is set
 */
function resolve_user_edit_filter(): ?int
{
    global $usereditfilter, $userdata, $usergroup, $userpermissions, $lang, $baseurl;

    if (
        strlen(trim($usereditfilter ?? "")) > 0
        && !is_numeric($usereditfilter)
        && trim($userdata[0]["edit_filter"]) != ""
        && $userdata[0]["edit_filter_id"] != -1
    ) {
        // Migrate unless marked not to due to failure
        $usereditfilter = edit_filter_to_restype_permission($usereditfilter, $usergroup, $userpermissions);
        if (trim($usereditfilter) !== "") {
            $migrateresult = migrate_filter($usereditfilter);
        } else {
            $migrateresult = 0; // filter was only for resource type, hasn't failed but no need to migrate again
            save_usergroup($usergroup, array('edit_filter' => ''));
        }
        if (is_numeric($migrateresult)) {
            debug("Migrated . " . $migrateresult);
            // Successfully migrated - now use the new filter
            save_usergroup($usergroup, array('edit_filter_id' => $migrateresult));
            debug("FILTER MIGRATION: Migrated edit filter - '" . $usereditfilter . "' filter id#" . $migrateresult);
            $usereditfilter = $migrateresult;
        } elseif (is_array($migrateresult)) {
            debug("FILTER MIGRATION: Error migrating edit filter: '" . $usereditfilter . "' - " . implode('\n', $migrateresult));
            // Error - set flag so as not to reattempt migration and notify admins of failure
            save_usergroup($usergroup, array('edit_filter_id' => -1));
            $notification_users = get_notification_users();
            message_add(array_column($notification_users, "ref"), $lang["filter_migration"] . " - " . $lang["filter_migrate_error"] . ": <br />" . implode('\n', $migrateresult), generateURL($baseurl . "/pages/admin/admin_group_management_edit.php", array("ref" => $usergroup)));
        }
    }

    return is_numeric($usereditfilter) && $usereditfilter > 0 ? (int) $usereditfilter : null;
}
