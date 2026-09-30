<?php

/**
 * Resource list mode - "!list<refs>" / "!listall<refs>", refs separated by colons.
 */
class TypesenseListMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'list' || $ctx->command === 'listall';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $plan->q = '*';

        $first_segment = explode(',', $ctx->command_arg)[0];
        $refs = array();
        foreach (explode(':', $first_segment) as $token) {
            $token = trim($token);
            if (is_int_loose($token)) {
                $refs[] = (int)$token;
            }
        }

        if (count($refs) === 0) {
            // No valid refs - return nothing.
            $plan->addFilter('ref:<0');
        } else {
            $plan->addFilter('ref:=[' . implode(',', $refs) . ']');
        }

        // Listed resources are shown in any archive state.
        $plan->suppressRestriction('archive');
    }
}
