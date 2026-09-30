<?php

/**
 * Resource access restrictions, as core's search_filter() and custom-access joins: confidential
 * and custom-access resources need a grant (users without "v"), and "v" users can search for a
 * specific access level.
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
