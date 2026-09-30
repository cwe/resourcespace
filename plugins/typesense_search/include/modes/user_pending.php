<?php

/**
 * User-pending mode - "!userpending". Resources in archive state -1.
 */
class TypesenseUserPendingMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'userpending';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $plan->q = '*';
        $plan->suppressRestriction('archive');
        $plan->addFilter('archive:=-1');
    }
}
