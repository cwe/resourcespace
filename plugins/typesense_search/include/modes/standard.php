<?php

/**
 * Standard keyword search mode - any search that is not a special ("!") command.
 */
class TypesenseStandardSearchMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === null;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // No scope of its own; the shared keyword matching step does the work.
    }
}
