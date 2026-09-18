<?php

/**
 * Single resource lookup mode - "!resource<n>", or a bare numeric search when
 * $config_search_for_number is enabled.
 */
class TypesenseResourceRefMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        if ($ctx->command === 'resource') {
            return true;
        }

        global $config_search_for_number;
        return !empty($config_search_for_number)
            && $ctx->command === null
            && is_numeric(trim($ctx->search));
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $raw = $ctx->command === 'resource' ? $ctx->command_arg : $ctx->search;
        $ref = (int)preg_replace('/[^0-9]/', '', $raw);

        $plan->q = '*';
        $plan->addFilter('ref:=' . $ref);
    }
}
