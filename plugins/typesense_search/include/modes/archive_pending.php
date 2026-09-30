<?php

/**
 * Archive-pending mode - "!archivepending". Resources in archive state 1.
 */
class TypesenseArchivePendingMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'archivepending';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $plan->q = '*';
        $plan->suppressRestriction('archive');
        $plan->addFilter('archive:=1');
    }
}
