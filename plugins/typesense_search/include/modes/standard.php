<?php

/**
 * Standard keyword search mode.
 *
 * Claims any search that is not a special ("!") command. It adds no scope of its own - the
 * shared keyword-matching step (run by the orchestrator for every mode) builds q, query_by and
 * node-bucket filters. Kept as an explicit mode so search selection always resolves to one.
 */
class TypesenseStandardSearchMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === null;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // No special scope. typesense_apply_keyword_matching() (shared, run by the orchestrator)
        // does the keyword + node-bucket work.
    }
}
