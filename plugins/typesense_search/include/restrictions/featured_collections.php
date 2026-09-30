<?php

/**
 * Featured-collections-only restriction ("J" permission): resources must be in a featured
 * collection the user can see. Inside "!collection" it only applies when that collection is
 * itself permitted; otherwise the search is left to core.
 */
class TypesenseFeaturedCollectionsRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        if (!checkperm('J')) {
            return false;
        }

        // The user's upload collection is exempt, as in core.
        $upload_collection = '!collection' . (0 - $ctx->userref);
        return $ctx->search !== $upload_collection;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $accessible = compute_featured_collections_access_control();

        if (is_array($accessible) && count($accessible) === 0) {
            // No permitted collections - nothing is visible.
            $plan->addFilter('ref:<0');
            return;
        }

        if ($ctx->command === 'collection') {
            $collection = typesense_search_collection_ref($ctx);
            if ($accessible === true || in_array($collection, array_map('intval', $accessible), true)) {
                // Members of a permitted collection already qualify.
                return;
            }
            $plan->markUnsupported('J inside collection ' . $collection . ', which is not a permitted collection');
            return;
        }

        if ($accessible === true) {
            // No exclusions: membership of any indexed collection qualifies.
            $plan->addJoinFilter('resource_collection_memberships', 'collection_type:>=0');
        } else {
            $refs = implode(',', array_map('intval', $accessible));
            $plan->addJoinFilter('resource_collection_memberships', 'collection_ref:=[' . $refs . ']');
        }
    }
}
