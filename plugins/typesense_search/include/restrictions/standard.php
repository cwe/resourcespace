<?php

/**
 * Standard restrictions applied under every mode, as core's search_filter(): resource type,
 * archive state, created-by filter, recent-search day limit and pending-state hiding.
 */
class TypesenseStandardRestrictions implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return !$ctx->ignore_filters;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // Never return the batch upload template (negative refs).
        $plan->addFilter('ref:>0');

        $this->applyResourceTypes($ctx, $plan);
        $this->applyCreatedByFilter($ctx, $plan);
        $this->applyRecentDayLimit($ctx, $plan);

        if (!$ctx->access_override) {
            $this->applyArchive($ctx, $plan);
        }
    }

    /**
     * The requested resource types, plus any "T" permission exclusions.
     */
    private function applyResourceTypes(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $special_search_honors_restypes, $userpermissions;

        $is_special = $ctx->command !== null;
        $honour_restypes = !$is_special
            || (!empty($special_search_honors_restypes) && $ctx->command !== 'collection');

        if ($honour_restypes) {
            $restypes = $ctx->restypes;
            $types = array();
            if (is_string($restypes) && trim($restypes) !== '' && substr($restypes, 0, 6) !== 'Global') {
                $types = array_filter(array_map('intval', explode(',', $restypes)));
            } elseif (is_array($restypes)) {
                $types = array_filter(array_map('intval', $restypes));
            }
            if (count($types) > 0) {
                $plan->addFilter('resource_type:=[' . implode(',', $types) . ']');
            }
        }

        // "T" permission: hide the listed resource types.
        if (!$ctx->access_override) {
            $exclude = array();
            foreach ((array)$userpermissions as $perm) {
                if (substr($perm, 0, 1) === 'T' && is_numeric(substr($perm, 1))) {
                    $exclude[] = (int)substr($perm, 1);
                }
            }
            if (count($exclude) > 0) {
                $plan->addFilter('resource_type:!=[' . implode(',', $exclude) . ']');
            }
        }
    }

    /**
     * $resource_created_by_filter - only resources created by the given users (-1 = current user).
     */
    private function applyCreatedByFilter(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $resource_created_by_filter;

        if (!isset($resource_created_by_filter) || !is_array($resource_created_by_filter) || count($resource_created_by_filter) === 0) {
            return;
        }

        $users = array();
        foreach ($resource_created_by_filter as $filter_user) {
            $users[] = ($filter_user == -1) ? $ctx->userref : (int)$filter_user;
        }
        if (count($users) > 0) {
            $plan->addFilter('created_by:=[' . implode(',', $users) . ']');
        }
    }

    private function applyRecentDayLimit(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        if ($ctx->recent_search_daylimit === '' || !is_numeric($ctx->recent_search_daylimit)) {
            return;
        }
        // As core: since midnight n days ago, not n x 24 hours ago.
        $cutoff = strtotime(sprintf('today %+d days', -(int)$ctx->recent_search_daylimit));
        if ($cutoff !== false) {
            $plan->addFilter('creation_date:>' . $cutoff);
        }
    }

    /**
     * Archive states (the defaults or those requested), "z" permission exclusions and the
     * pending-state hide.
     */
    private function applyArchive(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $search_all_workflow_states, $archive_standard, $additional_archive_states, $userpermissions;
        global $uploader_view_override, $collection_allow_not_approved_share;

        // Only valid integer states count, as in core.
        $archive = array_values(array_filter($ctx->archive, 'is_int_loose'));

        if (!$plan->isSuppressed('archive')) {
            if (!empty($search_all_workflow_states)) {
                // No archive-state restriction.
            } elseif (count($archive) === 0 || (!empty($archive_standard) && !$ctx->smartsearch)) {
                $states = get_default_search_states();
                if (count($states) === 0) {
                    $states = array(0);
                }
                $plan->addFilter('archive:=[' . implode(',', array_map('intval', $states)) . ']');
            } else {
                $plan->addFilter('archive:=[' . implode(',', array_map('intval', $archive)) . ']');
            }
        }

        // "z" permission: exclude blocked states, except own resources with $uploader_view_override.
        $blocked = array();
        for ($n = -2; $n <= 3; $n++) {
            if (checkperm('z' . $n)) {
                $blocked[] = $n;
            }
        }
        foreach ((array)$additional_archive_states as $extra_state) {
            if (checkperm('z' . $extra_state)) {
                $blocked[] = (int)$extra_state;
            }
        }
        if (count($blocked) > 0) {
            $blocked_clause = 'archive:!=[' . implode(',', $blocked) . ']';
            if (!empty($uploader_view_override)) {
                $plan->addFilterOr(array($blocked_clause, 'created_by:=' . $ctx->userref));
            } else {
                $plan->addFilter($blocked_clause);
            }
        }

        // Hide pending states (-2, -1) from users without "v", except their own resources and "ert"
        // resource types. Skipped for an external share with $collection_allow_not_approved_share.
        $shared_pending_allowed = $ctx->command === 'collection'
            && $ctx->k !== ''
            && !empty($collection_allow_not_approved_share);
        if (!checkperm('v') && !$shared_pending_allowed && !$plan->isSuppressed('pending')) {
            $ert = array();
            foreach ((array)$userpermissions as $perm) {
                if (substr($perm, 0, 3) === 'ert' && is_numeric(substr($perm, 3))) {
                    $ert[] = (int)substr($perm, 3);
                }
            }
            $clauses = array('archive:!=[-2,-1]', 'created_by:=' . $ctx->userref);
            if (count($ert) > 0) {
                $clauses[] = 'resource_type:=[' . implode(',', array_unique($ert)) . ']';
            }
            $plan->addFilterOr($clauses);
        }
    }
}
