<?php

/**
 * Featured-collections-only restriction ("J" permission).
 *
 * When a user has the "J" permission they may only see resources that belong to a featured
 * collection they can access. Enforced by joining the memberships collection, restricted to the
 * user's accessible featured-collection refs (or all featured collections when unrestricted).
 * The user's own upload collection is exempt, matching core.
 */
class TypesenseFeaturedCollectionsRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        if (!checkperm('J') || $ctx->access_override) {
            return false;
        }

        $upload_collection = '!collection' . (0 - $ctx->userref);
        return $ctx->search !== $upload_collection;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $accessible = compute_featured_collections_access_control();

        if ($accessible === true) {
            // Access to all featured collections: require membership of any featured collection.
            $plan->addJoinFilter('resource_collection_memberships', 'collection_type:=' . COLLECTION_TYPE_FEATURED);
        } elseif (is_array($accessible) && count($accessible) > 0) {
            $refs = implode(',', array_map('intval', $accessible));
            $plan->addJoinFilter('resource_collection_memberships', 'collection_ref:=[' . $refs . ']');
        } else {
            // No accessible featured collections - return nothing.
            $plan->addFilter('ref:<0');
        }
    }
}
