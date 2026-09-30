<?php

/**
 * Collection view mode - "!collection<id>". Restricts results to the collection's members, in
 * the collection's sort order.
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

        $collection = typesense_search_collection_ref($ctx);

        // Selection and upload collections are not indexed; leave them to core.
        if (!typesense_search_collection_indexed($collection)) {
            $plan->markUnsupported('collection ' . $collection . ' is not indexed (selection/upload collection)');
            return;
        }

        // Leave a collection the user can't view to core.
        if (!checkperm('a') && !$ctx->access_override && !collection_readable($collection)) {
            $plan->markUnsupported('collection ' . $collection . ' not readable');
            return;
        }

        // Refresh a smart collection, as core does.
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

        // Any archive state, except archived when $collections_omit_archived is set.
        $plan->suppressRestriction('archive');
        if (!empty($collections_omit_archived) && !checkperm('e2')) {
            $plan->addFilter('archive:!=2');
        }

        // Use the collection's own order unless another sort was requested.
        global $typesense_search_collection_prefix;
        $order_by = trim((string)$ctx->order_by);
        if ($order_by === '' || strpos($order_by, 'c.sortorder') === 0) {
            // Break ties as core does: date added (reversed), then ref.
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
