<?php

/**
 * typesense_search: the collection prefix as the tenant boundary.
 *
 * Seam for A8, which adds the generated prefix, its storage, the identity metadata and the clash check. Until
 * then the prefix is read from configuration only. Nothing else in the plugin may build a server-side name.
 */

/**
 * The collection prefix: the config.php override wins over the stored option. Empty when not set up.
 *
 * @return string
 */
function typesense_search_tenancy_prefix(): string
{
    global $typesense_search_prefix, $typesense_search_prefix_override;

    $prefix = $typesense_search_prefix_override ?? $typesense_search_prefix ?? '';
    return is_string($prefix) ? $prefix : '';
}

/**
 * Check a prefix: letters, digits and underscore, 1 to 32 characters.
 *
 * @param string $prefix Candidate prefix
 *
 * @return bool
 */
function typesense_search_tenancy_prefix_valid(string $prefix): bool
{
    return preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix) === 1;
}

/**
 * Every server-side name: "<prefix>_<kind>" for an alias or the synonym set, "<prefix>_<kind>_g<N>" for a generation.
 *
 * @param string   $kind       resources, memberships, grants or related
 * @param int|null $generation Generation number for a collection; null for its alias
 *
 * @return string
 */
function typesense_search_tenancy_name(string $kind, ?int $generation = null): string
{
    $prefix = typesense_search_tenancy_prefix();
    if (!typesense_search_tenancy_prefix_valid($prefix)) {
        throw new InvalidArgumentException('typesense_search: the collection prefix is not set or not valid');
    }
    if (!in_array($kind, array('resources', 'memberships', 'grants', 'related'), true)) {
        throw new InvalidArgumentException('typesense_search: unknown name kind "' . $kind . '"');
    }
    if ($generation !== null && ($kind === 'related' || $generation < 1)) {
        throw new InvalidArgumentException('typesense_search: no generation for "' . $kind . '"');
    }

    return $prefix . '_' . $kind . ($generation === null ? '' : '_g' . $generation);
}
