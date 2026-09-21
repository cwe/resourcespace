<?php

/**
 * View-last mode - "!last<num>".
 *
 * Returns the most-recent N resources (the N highest refs matching the other criteria). Like core,
 * the *selection* is by ref desc but the *display order* is the user's chosen sort - so sorting a
 * "recent" / home view by resource ID, date, etc. works. The recent-N selection is resolved to a
 * `ref:>=<cutoff>` filter at execute time (see typesense_search_execute()); we deliberately do NOT
 * set a sort here, so the standard sort mapping applies the requested order_by (and vetoes to core
 * for sorts Typesense can't do). Standard restrictions (archive, access, etc.) still apply.
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
        $plan->setRecentSelection($num);
    }
}
