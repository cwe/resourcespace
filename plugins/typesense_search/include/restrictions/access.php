<?php

/**
 * Restricted-resource access control (Option A grants).
 *
 * Reproduces core's confidential/custom visibility filter for non-"v" users, using a joined
 * grants collection (<prefix>resource_access_grants, one doc per non-2 resource_custom_access row):
 *   - access = 2 (confidential): visible only if a valid grant exists - a user grant (honouring
 *     expiry) or a group grant (group grants do not expire, mirroring the rca join).
 *   - access = 3 (custom only): visible only if a group grant exists (core checks the group join).
 *
 * Requires the resources schema to carry `access` and the grants collection to exist/be indexed;
 * until a reindex creates them, the grant join errors and the search falls back to core (safe).
 * The `g`-permission resultant_access (download/watermark level) is display logic, handled at
 * hydrate, not a visibility filter.
 */
class TypesenseAccessRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return !$ctx->access_override && !checkperm('v');
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
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
