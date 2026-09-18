<?php

include_once __DIR__ . '/../include/typesense_search_functions.php';

/**
 * Intercept ResourceSpace searches and optionally handle them using Typesense.
 *
 * Returning false allows normal MySQL-based ResourceSpace search processing
 * to continue.
 *
 * @param string                 $search                  Original search string.
 * @param array                  $keywords                Parsed search keywords.
 * @param array                  $node_bucket             Included node search buckets.
 * @param array                  $node_bucket_not         Excluded node search buckets.
 * @param mixed                  $restypes                Resource type filter.
 * @param mixed                  $order_by                Requested sort field.
 * @param array                  $archive                 Archive state filter.
 * @param mixed                  $fetchrows               Result limit or chunk details.
 * @param string                 $sort                    Sort direction.
 * @param bool                   $access_override         Whether access checks are overridden.
 * @param bool                   $ignore_filters          Whether standard filters are ignored.
 * @param bool                   $return_disk_usage       Whether disk usage totals are requested.
 * @param string                 $recent_search_daylimit  Recent search day limit.
 * @param bool                   $return_refs_only        Whether only resource refs should be returned.
 * @param bool                   $editable_only           Whether only editable resources should be returned.
 * @param bool                   $returnsql               Whether SQL should be returned instead of results.
 * @param mixed                  $access                  Access filter override.
 * @param bool                   $smartsearch             Whether smart search mode is active.
 * @param PreparedStatementQuery $sql_filter              Existing ResourceSpace filter SQL.
 * @param PreparedStatementQuery $sql_join                Existing ResourceSpace JOIN SQL.
 * @param PreparedStatementQuery $select                  Existing ResourceSpace SELECT SQL.
 *
 * @return array|false ResourceSpace-compatible search results, or false to fall back to core search.
 */
function HookTypesense_searchAllExternal_search(
    string $search,
    array $keywords,
    array $node_bucket,
    array $node_bucket_not,
    $restypes,
    $order_by,
    array $archive,
    $fetchrows,
    string $sort,
    bool $access_override,
    bool $ignore_filters,
    bool $return_disk_usage,
    string $recent_search_daylimit,
    bool $return_refs_only,
    bool $editable_only,
    bool $returnsql,
    $access,
    bool $smartsearch,
    PreparedStatementQuery $sql_filter,
    PreparedStatementQuery $sql_join,
    PreparedStatementQuery $select
) {
    global $typesense_search_enabled;

    // Master toggle - use the standard ResourceSpace search when Typesense is disabled.
    if (isset($typesense_search_enabled) && !$typesense_search_enabled) {
        return false;
    }

    $ctx = TypesenseSearchContext::fromHookArgs(array(
        'search' => $search,
        'keywords' => $keywords,
        'node_bucket' => $node_bucket,
        'node_bucket_not' => $node_bucket_not,
        'restypes' => $restypes,
        'order_by' => $order_by,
        'archive' => $archive,
        'fetchrows' => $fetchrows,
        'sort' => $sort,
        'access_override' => $access_override,
        'ignore_filters' => $ignore_filters,
        'return_disk_usage' => $return_disk_usage,
        'recent_search_daylimit' => $recent_search_daylimit,
        'return_refs_only' => $return_refs_only,
        'editable_only' => $editable_only,
        'returnsql' => $returnsql,
        'access' => $access,
        'smartsearch' => $smartsearch,
        'select' => $select,
    ));

    // Special request modes (SQL passthrough, disk usage totals, editable-only, smart search)
    // are not results Typesense produces - always let core handle them.
    if ($ctx->returnsql || $ctx->return_disk_usage || $ctx->editable_only || $ctx->smartsearch) {
        return false;
    }

    $results = typesense_search_run($ctx);
    if ($results !== false) {
        // Record that Typesense served a results search this request (used by the UI indicator).
        // Only the main display search, not internal refs-only lookups.
        if (!$ctx->return_refs_only) {
            $GLOBALS['typesense_search_served'] = true;
        }
        return $results;
    }

    // Typesense could not handle this search: empty result in Typesense-only mode, else fall back.
    return typesense_search_fallback_result($ctx);
}

/**
 * Store resources using a node before the node is deleted.
 *
 * @param int $node Node ID.
 *
 * @return void
 */
function HookTypesense_searchAllBeforenodedelete(int $node): void
{
    $GLOBALS['typesense_search_affected_node_resources'] = ps_array(
        'SELECT DISTINCT resource value FROM resource_node WHERE node = ?',
        array('i', $node)
    );
}


/**
 * Reindex resources that used a node after the node has been deleted.
 *
 * @param int $node Node ID.
 *
 * @return void
 */
function HookTypesense_searchAllAfternodedelete(int $node): void
{
    foreach ($GLOBALS['typesense_search_affected_node_resources'] ?? array() as $resource) {
        typesense_search_index_resource((int)$resource);
    }

    unset($GLOBALS['typesense_search_affected_node_resources']);
}


/**
 * Reindex resource after metadata has been saved.
 *
 * @return false
 */
function HookTypesense_searchAllAftersaveresourcedata()
{
    global $ref;

    if (!isset($ref) || !is_numeric($ref) || (int)$ref <= 0) {
        return false;
    }

    debug('typesense_search: reindexing after save_resource_data(): ' . $ref);

    typesense_search_index_resource((int)$ref);

    return false;
}


/**
 * Sync Typesense related keyword synonyms after related keywords have been saved.
 *
 * @param string $keyword Keyword that was updated.
 * @param string $related Related keyword string.
 *
 * @return false
 */
function HookTypesense_searchAllAfter_save_related_keywords(string $keyword, string $related)
{
    debug('typesense_search: syncing Typesense synonyms after related keyword update');

    typesense_search_sync_related_keywords();

    return false;
}


/**
 * Show a small badge next to the search title indicating which engine served the results -
 * Typesense or the standard (MySQL) search. Enable/disable via $typesense_search_show_indicator.
 *
 * @return void
 */
function HookTypesense_searchAllAftersearchtitle(): void
{
    global $typesense_search_show_indicator, $lang;

    if (isset($typesense_search_show_indicator) && !$typesense_search_show_indicator) {
        return;
    }

    $served = !empty($GLOBALS['typesense_search_served']);

    $label = $served
        ? ($lang['typesense_search_served_typesense'] ?? 'Typesense')
        : ($lang['typesense_search_served_mysql'] ?? 'Standard search');
    $colour = $served ? '#2e7d32' : '#757575';
    $icon = $served ? '&#9889;' : '&#9679;'; // ⚡ vs ●

    echo '<span title="Search engine that produced these results" style="'
        . 'display:inline-block;margin-left:10px;padding:1px 8px;border-radius:10px;'
        . 'font-size:11px;font-weight:bold;vertical-align:middle;color:#fff;background:' . $colour . ';">'
        . $icon . ' ' . htmlspecialchars($label)
        . '</span>';
}
