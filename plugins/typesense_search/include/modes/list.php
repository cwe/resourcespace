<?php

/**
 * Explicit resource list mode - "!list<refs>" / "!listall<refs>".
 *
 * Refs are colon-separated (e.g. !list12:34:56). Both variants show the listed resources in any
 * archive state (the default-archive restriction is suppressed), matching core.
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
            // No valid refs - return nothing (core uses WHERE r.ref IS NULL).
            $plan->addFilter('ref:<0');
        } else {
            $plan->addFilter('ref:=[' . implode(',', $refs) . ']');
        }

        // A list references specific resources regardless of workflow state.
        $plan->suppressRestriction('archive');
    }
}
