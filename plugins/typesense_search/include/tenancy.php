<?php

/**
 * typesense_search: the collection prefix as the tenant boundary.
 *
 * The prefix is generated at the first save of the setup page and stored (Q9.12); $typesense_search_prefix_override
 * in config.php assigns one instead. Seam for A8, which adds the identity metadata, the clash check and the
 * "regenerate prefix" action. Nothing else in the plugin may build a server-side name.
 */

/**
 * The collection prefix, read through the option accessor. Empty when not set up.
 *
 * @return string
 */
function typesense_search_tenancy_prefix(): string
{
    $prefix = typesense_search_option('prefix');
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
 * A new prefix: a random token of twelve lower-case letters and digits (Q9.12).
 *
 * @return string
 */
function typesense_search_tenancy_generate_prefix(): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $prefix = '';
    for ($i = 0; $i < 12; $i++) {
        $prefix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $prefix;
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
