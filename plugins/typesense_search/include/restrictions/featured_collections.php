<?php

/**
 * Featured-collections-only restriction ("J" permission).
 *
 * Core (do_search.php) joins collection_resource / collection for every search except the user's
 * own upload collection, restricted by featured_collections_permissions_filter_sql(): the
 * collections returned by compute_featured_collections_access_control(); no restriction at all
 * when that returns true (j* with no -j exclusion - core then accepts membership of ANY
 * collection, of any type, which is replicated here); nothing when it returns an empty list. It
 * applies under access_override too.
 *
 * Inside !collection<C> core's two joins may match different rows: the resource must be in C and
 * in some permitted collection. Typesense requires two filters on the same referenced collection
 * to match the same membership document, so the J join can only be added when it is redundant -
 * when C is itself permitted, every member of C satisfies it through C's own membership row.
 * Otherwise the search is left to core.
 */
class TypesenseFeaturedCollectionsRestriction implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        if (!checkperm('J')) {
            return false;
        }

        // Core exempts the user's upload collection (an exact match on the search string) so that
        // upload-then-edit still works.
        $upload_collection = '!collection' . (0 - $ctx->userref);
        return $ctx->search !== $upload_collection;
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        $accessible = compute_featured_collections_access_control();

        if (is_array($accessible) && count($accessible) === 0) {
            // No permitted collections - core's "AND 1 = 0": nothing is visible, in or out of a
            // collection.
            $plan->addFilter('ref:<0');
            return;
        }

        if ($ctx->command === 'collection') {
            $collection = typesense_search_collection_ref($ctx);
            if ($accessible === true || in_array($collection, array_map('intval', $accessible), true)) {
                // Every member of a permitted collection satisfies the J join through that
                // collection's own membership row, so the join would restrict nothing.
                return;
            }
            $plan->markUnsupported('J inside collection ' . $collection . ', which is not a permitted collection');
            return;
        }

        if ($accessible === true) {
            // j* with no exclusions: core's filter is empty, so membership of any collection
            // qualifies.
            $plan->addJoinFilter('resource_collection_memberships', 'collection_type:>=0');
        } else {
            $refs = implode(',', array_map('intval', $accessible));
            $plan->addJoinFilter('resource_collection_memberships', 'collection_ref:=[' . $refs . ']');
        }
    }
}
