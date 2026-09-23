<?php

/**
 * Collection view mode - "!collection<id>".
 *
 * Constrains results to a collection's members (via a join to the memberships collection) and
 * orders them by the collection sort order. Collection membership does not itself grant resource
 * access - resource-level visibility is still enforced by the restrictions layer.
 */
class TypesenseCollectionMode implements TypesenseSearchComponent
{
    public function applies(TypesenseSearchContext $ctx): bool
    {
        return $ctx->command === 'collection';
    }

    public function build(TypesenseSearchContext $ctx, TypesenseQueryPlan $plan): void
    {
        global $collections_omit_archived, $allow_smart_collections, $smart_collections_async;
        global $php_path, $remote_config, $host;

        $collection = (int)preg_replace('/[^0-9-]/', '', $ctx->command_arg);

        // Collection readability gate. If the user can't view this collection, fall back to core
        // (which returns an empty result set) rather than exposing its members.
        if (!checkperm('a') && !$ctx->access_override && !collection_readable($collection)) {
            $plan->markUnsupported('collection ' . $collection . ' not readable');
            return;
        }

        // Preserve the smart-collection refresh side effect that core performs for !collection.
        if ($allow_smart_collections && !$ctx->return_disk_usage) {
            $smartsearch_ref = ps_value('SELECT savedsearch value FROM collection WHERE ref = ?', array('i', $collection), '');
            if ($smartsearch_ref !== '') {
                if (!empty($smart_collections_async) && isset($php_path) && file_exists($php_path . '/php')) {
                    if (isset($remote_config, $host)) {
                        putenv('RESOURCESPACE_URL=' . $host);
                    }
                    exec($php_path . '/php ' . dirname(__DIR__, 2) . '/../../pages/ajax/update_smart_collection.php ' . escapeshellarg($smartsearch_ref) . ' > /dev/null 2>&1 &');
                } else {
                    update_smart_collection($smartsearch_ref);
                }
            }
        }

        $plan->q = '*';

        // Restrict to this collection's members.
        $plan->addJoinFilter('resource_collection_memberships', 'collection_ref:=' . $collection);

        // Collections may contain resources in any archive state - suppress the default archive
        // restriction, then re-apply the archived-hide rule when configured.
        $plan->suppressRestriction('archive');
        if (!empty($collections_omit_archived) && !checkperm('e2')) {
            $plan->addFilter('archive:!=2');
        }

        // Ordering. The collection's own stored order (RS "collection" sort) can only be expressed
        // via the memberships reference join - the standard sort mapping can't handle a
        // "c.sortorder ..." fragment. So apply the reference-collection sort here ONLY when the
        // search is using the default collection order; for any explicit sort (Resource ID, Date,
        // Modified, Relevance, ...) leave sort_by unset so the standard mapping applies the user's
        // choice, or vetoes to core for sorts Typesense can't do. This mirrors core's !collection,
        // whose outer query re-sorts the members by the requested order_by.
        global $typesense_search_collection_prefix;
        $order_by = trim((string)$ctx->order_by);
        if ($order_by === '' || strpos($order_by, 'c.sortorder') === 0) {
            // Honour the sort direction (default collection order is ascending). Break ties the way
            // core does - "c.sortorder <dir>, c.date_added <reversed dir>, r.ref <dir>" - since most
            // members share a sortorder (a collection that was never reordered has NULL for every
            // member, indexed as the same value).
            $direction = strtolower($ctx->sort) === 'desc' ? 'desc' : 'asc';
            $reverse_direction = $direction === 'desc' ? 'asc' : 'desc';
            $plan->addRawSort(
                '$' . $typesense_search_collection_prefix
                . 'resource_collection_memberships(sortorder:' . $direction . ',date_added:' . $reverse_direction . ')'
            );
            $plan->setSort('ref', $direction);
        }
    }
}
