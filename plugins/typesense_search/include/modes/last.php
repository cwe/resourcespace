<?php

/**
 * View-last mode - "!last<num>". The newest N matching resources, in the requested sort order.
 */
class TypesenseLastMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'last';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // As core: the text after "!last" up to the first comma, or 1000 if that isn't a number.
        $arg = str_replace('!last', '', explode(',', $ctx->search)[0]);
        $num = is_int_loose($arg) ? (int)$arg : 1000;

        $plan->q = '*';
        $plan->setRecentSelection($num);

        // Core lists a relevance order by ref, descending only if the order string contains "DESC".
        $order_by = trim((string)$ctx->order_by);
        if ($order_by === '' || strpos($order_by, 'score') === 0) {
            $plan->setSort('ref', strpos($order_by, 'DESC') === false ? 'asc' : 'desc');
        }
    }
}
