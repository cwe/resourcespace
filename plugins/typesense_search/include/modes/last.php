<?php

/**
 * View-last mode - "!last<num>".
 *
 * Returns the most-recent N resources (highest refs), capped at N. This is a result-count
 * restriction rather than a filter. Standard restrictions (archive, access, etc.) still apply.
 */
class TypesenseLastMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'last';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $num = is_int_loose($ctx->command_arg) ? (int)$ctx->command_arg : 1000;

        $plan->q = '*';
        $plan->setSort('ref', 'desc');
        $plan->setResultLimit($num);
    }
}
