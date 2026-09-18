<?php

/**
 * User-pending mode - "!userpending".
 *
 * Resources pending review (archive state -1). The default-archive restriction is suppressed and
 * replaced with the fixed state.
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
