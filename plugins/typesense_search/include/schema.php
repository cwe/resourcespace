<?php

/**
 * typesense_search: the versioned schema of the three collections.
 *
 * A change to any definition here is a new schema version and a generation rebuild; a live collection is never
 * altered in place. The per-field representations are dynamic patterns; which fields get which one is decided
 * by the representation registry (representations.php), not here.
 */

/**
 * Version of the collection definitions in this file, stored in each generation's metadata.
 *
 * @return int
 */
function typesense_search_schema_version(): int
{
    return 1;
}

/**
 * Characters that split text into tokens: core's separators plus comma and colon, single-byte only, no space.
 *
 * @return array
 */
function typesense_search_schema_token_separators(): array
{
    global $config_separators;

    $separators = array();
    foreach (array_merge((array)$config_separators, array(',', ':')) as $separator) {
        if (strlen($separator) === 1 && $separator !== ' ' && !in_array($separator, $separators, true)) {
            $separators[] = $separator;
        }
    }

    return $separators;
}

/**
 * The per-field representations as dynamic field patterns.
 *
 * Text patterns stem iff $stemming; _q, _k and _p never stem. Array patterns must always be sent as arrays.
 *
 * @return array
 */
function typesense_search_schema_field_patterns(): array
{
    global $stemming;

    $stem = (bool)$stemming;
    return array(
        array('name' => 'field_.*_s', 'type' => 'string', 'optional' => true, 'stem' => $stem),
        array('name' => 'field_.*_text', 'type' => 'string', 'optional' => true, 'stem' => $stem),
        array('name' => 'field_.*_ss', 'type' => 'string[]', 'optional' => true, 'facet' => true, 'stem' => $stem),
        array('name' => 'field_.*_q', 'type' => 'string[]', 'optional' => true),
        array('name' => 'field_.*_f', 'type' => 'float', 'optional' => true),
        array('name' => 'field_.*_k', 'type' => 'int64[]', 'optional' => true),
        array('name' => 'field_.*_p', 'type' => 'string[]', 'optional' => true),
    );
}

/**
 * Metadata every generation carries; the rebuild adds the plugin version, the installation identity and timings.
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_metadata(int $generation): array
{
    return array('generation' => $generation, 'schema_version' => typesense_search_schema_version());
}

/**
 * The resources collection of a generation.
 *
 * Always present: ref, ref_s, title, resource_type, archive, access, creation_date, modified_date,
 * date_field_sort (0: null, 1: empty, 2:<value>), nodes and populated_field_ids (both hold the sentinel 0 so the arrays always exist, then the real ids),
 * hit_count and the four packed sort keys. Absent when NULL: created_by, user_rating, rating.
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_resources(int $generation): array
{
    global $stemming;

    $stem = (bool)$stemming;
    $fields = array(
        array('name' => 'ref', 'type' => 'int32', 'sort' => true),
        array('name' => 'ref_s', 'type' => 'string'),
        array('name' => 'title', 'type' => 'string', 'stem' => $stem),
        array('name' => 'resource_type', 'type' => 'int32', 'facet' => true),
        array('name' => 'archive', 'type' => 'int32', 'facet' => true, 'sort' => true),
        array('name' => 'access', 'type' => 'int32'),
        array('name' => 'created_by', 'type' => 'int32', 'optional' => true),
        array('name' => 'creation_date', 'type' => 'int64'),
        array('name' => 'modified_date', 'type' => 'int64', 'sort' => true),
        array('name' => 'date_field_sort', 'type' => 'string', 'sort' => true),
        array('name' => 'nodes', 'type' => 'int32[]', 'facet' => true),
        array('name' => 'populated_field_ids', 'type' => 'int32[]'),
        array('name' => 'hit_count', 'type' => 'int32', 'sort' => true),
        array('name' => 'user_rating', 'type' => 'int32', 'optional' => true),
        array('name' => 'rating', 'type' => 'int32', 'optional' => true),
        array('name' => 'ur_hits', 'type' => 'int64', 'sort' => true),
        array('name' => 'hits_ur', 'type' => 'int64', 'sort' => true),
        array('name' => 'date_ref', 'type' => 'int64', 'sort' => true),
        array('name' => 'rating_ur', 'type' => 'int64', 'sort' => true),
    );

    return array(
        'name' => typesense_search_tenancy_name('resources', $generation),
        'fields' => array_merge($fields, typesense_search_schema_field_patterns()),
        'token_separators' => typesense_search_schema_token_separators(),
        'metadata' => typesense_search_schema_metadata($generation),
    );
}

/**
 * The reference field of a joined collection: bound to the generation's resources collection at creation.
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_resource_reference(int $generation): array
{
    return array(
        'name' => 'resource_id',
        'type' => 'string',
        'reference' => typesense_search_tenancy_name('resources', $generation) . '.id',
        'async_reference' => true,
        'cascade_delete' => true,
    );
}

/**
 * The memberships collection of a generation: one document per (collection, resource), id "collection:resource".
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_memberships(int $generation): array
{
    return array(
        'name' => typesense_search_tenancy_name('memberships', $generation),
        'fields' => array(
            typesense_search_schema_resource_reference($generation),
            array('name' => 'collection_ref', 'type' => 'int64'),
            array('name' => 'sortorder', 'type' => 'int32'),
            array('name' => 'date_added', 'type' => 'int64', 'sort' => true),
            array('name' => 'collection_type', 'type' => 'int32'),
        ),
        'metadata' => typesense_search_schema_metadata($generation),
    );
}

/**
 * The grants collection of a generation: one document per custom access row, id "resource:user:usergroup",
 * -1 in the unused slot, expires 0 for never.
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_grants(int $generation): array
{
    return array(
        'name' => typesense_search_tenancy_name('grants', $generation),
        'fields' => array(
            typesense_search_schema_resource_reference($generation),
            array('name' => 'user', 'type' => 'int32', 'facet' => true),
            array('name' => 'usergroup', 'type' => 'int32', 'facet' => true),
            array('name' => 'access', 'type' => 'int32'),
            array('name' => 'expires', 'type' => 'int64', 'range_index' => true),
        ),
        'metadata' => typesense_search_schema_metadata($generation),
    );
}

/**
 * The three collection definitions of a generation in creation order: resources first, as the references bind
 * to it at creation.
 *
 * @param int $generation Generation number
 *
 * @return array Keyed by kind
 */
function typesense_search_schema_generation(int $generation): array
{
    return array(
        'resources' => typesense_search_schema_resources($generation),
        'memberships' => typesense_search_schema_memberships($generation),
        'grants' => typesense_search_schema_grants($generation),
    );
}

/**
 * The aliases of a generation, alias name => collection name. All three are swapped together: moving one
 * breaks the joins.
 *
 * @param int $generation Generation number
 *
 * @return array
 */
function typesense_search_schema_aliases(int $generation): array
{
    $aliases = array();
    foreach (array('resources', 'memberships', 'grants') as $kind) {
        $aliases[typesense_search_tenancy_name($kind)] = typesense_search_tenancy_name($kind, $generation);
    }

    return $aliases;
}
