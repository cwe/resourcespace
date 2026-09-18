<?php

/**
 * Archive-pending mode - "!archivepending".
 *
 * Resources awaiting archival (archive state 1). The default-archive restriction is suppressed
 * and replaced with the fixed state; the "z" permission and pending restrictions still apply.
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
