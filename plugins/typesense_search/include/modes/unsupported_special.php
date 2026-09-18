<?php

/**
 * Catch-all for any special ("!") command not claimed by a dedicated mode.
 *
 * Vetoes the search so the plugin falls back to core MySQL processing, which handles the
 * remaining special searches (e.g. !geo, !duplicates, !related, !report).
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
