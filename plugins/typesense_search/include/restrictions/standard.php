<?php

/**
 * Standard visibility/scope restrictions applied under every mode - the Typesense equivalent of
 * the bulk of core search_filter(): resource type, archive state (defaults + workflow perms),
 * created-by filter, recent-search day limit and pending-state hiding (with the "ert" resource
 * type exemption, $uploader_view_override and the external-share exemption, as in core).
 *
 * Confidential / custom access grants are handled by AccessRestriction, not here.
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
     * Resource type filters: the requested restypes plus any "T" permission exclusions.
     * Special searches only honour restypes when $special_search_honors_restypes is set (and
     * never for !collection), mirroring core.
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
     * $resource_created_by_filter - restrict to resources created by the given users (-1 aliases
     * the current user).
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
        // Core: "creation_date > (curdate() - interval n DAY)" - created after the midnight that
        // began the day n days ago, not n x 24 hours ago.
        $cutoff = strtotime(sprintf('today %+d days', -(int)$ctx->recent_search_daylimit));
        if ($cutoff !== false) {
            $plan->addFilter('creation_date:>' . $cutoff);
        }
    }

    /**
     * Archive state filtering: default search states (or the explicit request), the "z"
     * permission exclusions and the pending-state hide, honouring a mode's suppression.
     */
    private function applyArchive(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $search_all_workflow_states, $archive_standard, $additional_archive_states, $userpermissions;
        global $uploader_view_override, $collection_allow_not_approved_share;

        // Match core: only valid integer archive states count (a stray "" must not become 0).
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

        // "z" permission: exclude the blocked archive states. With $uploader_view_override a user
        // still sees their own resources in a blocked state, as in core.
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

        // Hide resources in a pending state (-2 / -1) from users without "v", except the user's own
        // resources and the resource types the user has "ert" permission for. Core's
        // ((archive<>-2 OR created_by=U) AND (archive<>-1 OR created_by=U)) OR resource_type IN (ert)
        // is archive NOT IN (-2,-1) OR created_by=U OR resource_type IN (ert). As in core, the hide
        // is skipped when a collection is viewed through an external share key and
        // $collection_allow_not_approved_share is set.
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
