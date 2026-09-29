<?php

/**
 * Resource access level restrictions - the Typesense equivalent of core's access rules in
 * search_filter() (include/search_functions.php) and the custom-access joins in do_search().
 *
 * 1. Confidential / custom access grants (Option A). For users without "v" (and no access
 *    override), reproduces core's visibility filter using a joined grants collection
 *    (<prefix>resource_access_grants, one doc per non-2 resource_custom_access row):
 *      - access = 2 (confidential): visible only if a valid grant exists - a user grant (honouring
 *        expiry) or a group grant (group grants do not expire, mirroring the rca join).
 *      - access = 3 (custom only): visible only if a group grant exists (core checks the group join).
 *    Requires the resources schema to carry `access` and the grants collection to exist/be indexed;
 *    until a reindex creates them, the grant join errors and the search falls back to core (safe).
 *    The `g`-permission resultant_access (download/watermark level) is display logic, handled at
 *    hydrate, not a visibility filter.
 *
 * 2. Specific access level. Only users with "v" can search for resources with a given access
 *    level - the advanced search "Access" option, passed to do_search() as $access. Core adds
 *    "r.access = ?" for a numeric value, regardless of $access_override, and ignores the value
 *    for everyone else (the "All" option posts its label, which is not numeric).
 */
class TypesenseAccessRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $this->grantsApply($ctx) || $this->accessLevelApplies($ctx);
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        if ($this->accessLevelApplies($ctx)) {
            $plan->addFilter('access:=' . (int)$ctx->access);
        }

        if ($this->grantsApply($ctx)) {
            $this->applyGrants($ctx, $plan);
        }
    }

    /**
     * Grant-based visibility applies to users without "v" when access is not overridden.
     */
    private function grantsApply(TypesenseSearchContext $ctx): bool
    {
        return !$ctx->access_override && !checkperm('v');
    }

    /**
     * A specific access level is honoured for "v" users only, and only when numeric, as core does.
     */
    private function accessLevelApplies(TypesenseSearchContext $ctx): bool
    {
        return checkperm('v') && is_numeric($ctx->access);
    }

    private function applyGrants(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $typesense_search_collection_prefix;

        $grants = $typesense_search_collection_prefix . 'resource_access_grants';
        $u = (int)$ctx->userref;
        $g = (int)$ctx->usergroup;
        $now = time();

        // access = 2: not confidential, or a valid user grant (with expiry) or group grant.
        $plan->addFilterOr(array(
            'access:!=2',
            '$' . $grants . '((user:=' . $u . ' && (expires:=0 || expires:>' . $now . ')) || usergroup:=' . $g . ')',
        ));

        // access = 3: not custom-only, or a group grant exists.
        $plan->addFilterOr(array(
            'access:!=3',
            '$' . $grants . '(usergroup:=' . $g . ')',
        ));
    }
}
