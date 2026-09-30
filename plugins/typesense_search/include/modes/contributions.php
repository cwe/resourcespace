<?php

/**
 * Contributions mode - "!contributions<user>". Resources created by the given user.
 */
class TypesenseContributionsMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'contributions';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $open_access_for_contributor;

        $cuser = (int)preg_replace('/[^0-9]/', '', $ctx->command_arg);

        $plan->q = '*';
        $plan->addFilter('created_by:=' . $cuser);

        if (!empty($open_access_for_contributor) && $ctx->userref === $cuser) {
            $this->buildOwnOpenAccess($ctx, $plan);
        }
    }

    /**
     * Own contributions with $open_access_for_contributor: as core, drop every restriction and the
     * keyword criteria, and show the results as open access.
     */
    private function buildOwnOpenAccess(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // As core, a stray "" is state 0. An empty list is left to core.
        $states = array_values(array_unique(array_map('intval', $ctx->archive)));
        if (count($states) === 0) {
            $plan->markUnsupported('!contributions (own, open access) without archive states');
            return;
        }

        $plan->addFilter('ref:>0');
        $plan->addFilter('archive:=[' . implode(',', $states) . ']');
        $plan->suppressAllRestrictions();
        $plan->suppressKeywordMatching();
        $plan->setOpenAccess();
    }
}
