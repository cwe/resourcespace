<?php

/**
 * Has-data mode - "!hasdata<fieldref>". Resources with a value in the given field, in any
 * archive state.
 */
class TypesenseHasDataMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'hasdata';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $fieldref = (int)preg_replace('/[^0-9]/', '', $ctx->command_arg);

        $plan->q = '*';
        $plan->addFilter('populated_field_ids:=' . $fieldref);
        $plan->suppressRestriction('archive');
    }
}
