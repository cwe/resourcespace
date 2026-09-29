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
        // The count is taken exactly as core takes it (search_special(), "!last"): everything after
        // "!last" up to the first comma. So text after the number - "!last50 sunset" - makes it
        // non-numeric and core silently uses 1000, while the keyword still refines the matches.
        $arg = str_replace('!last', '', explode(',', $ctx->search)[0]);
        $num = is_int_loose($arg) ? (int)$arg : 1000;

        $plan->q = '*';
        $plan->setRecentSelection($num);

        // Core special-cases a relevance order for "!last" (search_special(), "!last"): the newest
        // N are listed in ref order, and the direction is DESC only when the resolved order-by
        // string contains an upper-case "DESC" - the search page's default - so a lower-case
        // "desc", as the API sends, lists them oldest first. Replicated with the same test on the
        // same string. (Core keys this on the order-by *name* being "relevance"; the hook only sees
        // the resolved string, so a relevance string reached through an empty name is treated the
        // same.) The recent-N *selection* is by ref desc regardless - see setRecentSelection().
        $order_by = trim((string)$ctx->order_by);
        if ($order_by === '' || strpos($order_by, 'score') === 0) {
            $plan->setSort('ref', strpos($order_by, 'DESC') === false ? 'asc' : 'desc');
        }
    }
}
