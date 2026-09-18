<?php

/**
 * Contributions mode - "!contributions<user>".
 *
 * Resources created by the given user. When the user is viewing their own contributions and
 * $open_access_for_contributor is set, they may see their own pending resources, so the
 * pending-state restriction is suppressed (mirroring core).
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
            $plan->suppressRestriction('pending');
        }
    }
}
