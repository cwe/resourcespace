<?php

/**
 * Contributions mode - "!contributions<user>".
 *
 * Resources created by the given user, under the normal restrictions - except for a user viewing
 * their own contributions with $open_access_for_contributor set, where core's search_special()
 * ([search_functions.php:1447](../../../../include/search_functions.php:1447)) replaces the whole
 * filter and its joins with
 *
 *     created_by = ? AND r.ref > 0 AND archive IN (<the archive states as requested>)
 *
 * and zeroes the custom-access columns in the SELECT. That discards every restriction (access
 * grants, resource types and "T", "z" states, the pending rules, the day limit, the group search
 * filter, "J") and - because core had appended them to the same filter - the keyword, field:value
 * and node-bucket criteria too, so a keyword typed on that page is ignored. Replicated exactly,
 * quirks included, so the two engines return the same rows.
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
     * The user's own contributions with $open_access_for_contributor - see the class comment.
     */
    private function buildOwnOpenAccess(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        // Core binds the raw exploded archive list as integers, so a stray "" is state 0. An empty
        // list would be "archive IN ()", a SQL error in core; leave that case to core.
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
