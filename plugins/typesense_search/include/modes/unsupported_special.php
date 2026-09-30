<?php

/**
 * Catch-all for special ("!") searches with no mode of their own - leaves them to core.
 */
class TypesenseUnsupportedSpecialMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command !== null;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $plan->markUnsupported('special search !' . $ctx->command . ' not handled by Typesense');
    }
}
