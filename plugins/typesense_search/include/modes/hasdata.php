<?php

/**
 * Has-data mode - "!hasdata<fieldref>".
 *
 * Resources that hold a value in the given field. Like core, it does not apply the default
 * workflow-state restriction (the default-archive restriction is suppressed).
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
