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

        // Order by the stored collection sort order.
        global $typesense_search_collection_prefix;
        $plan->addRawSort('$' . $typesense_search_collection_prefix . 'resource_collection_memberships(sortorder:asc)');
    }
}
