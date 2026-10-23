<?php

/**
 * typesense_search: the collection prefix as the tenant boundary (A8).
 *
 * The prefix is generated at the first save of the setup page and stored (Q9.12); $typesense_search_prefix_override
 * in config.php assigns one instead, and an invalid one is refused rather than replaced by the stored value. Every
 * server-side name comes from typesense_search_tenancy_name(): nothing else in the plugin may build one. Each
 * generation's metadata carries the installation's identity; the clash check (provisioning, Q12.8) refuses a
 * prefix whose key pattern would overlap another tenant's and a build over another installation's collections;
 * the alias guard refuses to serve a generation that is not this installation's. Messages never name another
 * tenant's collections: those names are the protection (Q12.8).
 */

/**
 * The collection prefix, read through the option accessor: the config.php override, else the stored value. Empty
 * when not set up or not valid.
 *
 * @return string
 */
function typesense_search_tenancy_prefix(): string
{
    $prefix = typesense_search_option('prefix');

    return is_string($prefix) && typesense_search_tenancy_prefix_valid($prefix) ? $prefix : '';
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
 * The kinds of server-side name: the three collections, which have generations, and the synonym set.
 *
 * @return array
 */
function typesense_search_tenancy_kinds(): array
{
    return array('resources', 'memberships', 'grants', 'related');
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
    if ($prefix === '') {
        throw new InvalidArgumentException('typesense_search: the collection prefix is not set or not valid');
    }
    if (!in_array($kind, typesense_search_tenancy_kinds(), true)) {
        throw new InvalidArgumentException('typesense_search: unknown name kind "' . $kind . '"');
    }
    if ($generation !== null && ($kind === 'related' || $generation < 1)) {
        throw new InvalidArgumentException('typesense_search: no generation for "' . $kind . '"');
    }

    return $prefix . '_' . $kind . ($generation === null ? '' : '_g' . $generation);
}

/**
 * The collection pattern a scoped key is granted on (X1, Q12.8): "<prefix>_.*". Typesense reads the pattern as a
 * regular expression (verified on 30.2: "<prefix>_*" matches the prefix followed by underscores only).
 *
 * @return string
 */
function typesense_search_tenancy_key_pattern(): string
{
    $prefix = typesense_search_tenancy_prefix();
    if (!typesense_search_tenancy_prefix_valid($prefix)) {
        throw new InvalidArgumentException('typesense_search: the collection prefix is not set or not valid');
    }

    return $prefix . '_.*';
}

/**
 * Read a server-side name back into its parts, the inverse of typesense_search_tenancy_name().
 *
 * @param string $name Collection, alias or synonym-set name
 *
 * @return array|null prefix, kind, generation (null for an alias); null for a name the plugin does not make
 */
function typesense_search_tenancy_name_parse(string $name): ?array
{
    $kinds = implode('|', typesense_search_tenancy_kinds());
    if (preg_match('/^([A-Za-z0-9_]{1,32})_(' . $kinds . ')(?:_g([1-9][0-9]*))?$/', $name, $parts) !== 1) {
        return null;
    }
    $generation = isset($parts[3]) ? (int)$parts[3] : null;
    if ($generation !== null && $parts[2] === 'related') {
        return null;
    }

    return array('prefix' => $parts[1], 'kind' => $parts[2], 'generation' => $generation);
}

/**
 * Do two prefixes overlap? A key pattern "<a>_.*" reaches "<b>_..." when one, followed by an underscore, starts
 * the other: equal prefixes overlap, "site" and "site_a" overlap, "site" and "site2" do not.
 *
 * @param string $a A prefix
 * @param string $b Another prefix
 *
 * @return bool
 */
function typesense_search_tenancy_prefixes_overlap(string $a, string $b): bool
{
    return str_starts_with($a . '_', $b . '_') || str_starts_with($b . '_', $a . '_');
}

/**
 * The installation identity every generation's metadata carries: $baseurl, the database name and the plugin
 * version. The first two identify the installation; the version is recorded for the operator only.
 *
 * @return array baseurl, database, plugin_version
 */
function typesense_search_tenancy_identity(): array
{
    global $baseurl, $mysql_db;
    static $version = null;

    if ($version === null) {
        $yaml = get_plugin_yaml('typesense_search', false, false);
        $version = is_array($yaml) ? (string)$yaml['version'] : '';
    }

    return array(
        'baseurl' => rtrim((string)$baseurl, '/'),
        'database' => (string)$mysql_db,
        'plugin_version' => $version,
    );
}

/**
 * Does a generation's metadata carry this installation's identity? The plugin version is not compared, so an
 * upgrade keeps the live generation.
 *
 * @param mixed $metadata A collection's metadata as the server returns it
 *
 * @return bool
 */
function typesense_search_tenancy_identity_matches($metadata): bool
{
    $identity = typesense_search_tenancy_identity();
    $theirs = is_array($metadata) ? ($metadata['installation'] ?? null) : null;

    return is_array($theirs)
        && ($theirs['baseurl'] ?? null) === $identity['baseurl']
        && ($theirs['database'] ?? null) === $identity['database'];
}

/**
 * A generation's metadata with the installation identity added.
 *
 * @param array $metadata As typesense_search_schema_metadata() returns it
 *
 * @return array
 */
function typesense_search_tenancy_metadata(array $metadata): array
{
    return $metadata + array('installation' => typesense_search_tenancy_identity());
}

/**
 * Collection definitions as the rebuild creates them: each carrying the installation identity in its metadata.
 * The rebuild passes typesense_search_schema_generation()'s output through this.
 *
 * @param array $definitions Collection definitions keyed by kind
 *
 * @return array
 */
function typesense_search_tenancy_definitions(array $definitions): array
{
    foreach ($definitions as $kind => $definition) {
        $metadata = (array)($definition['metadata'] ?? array());
        $definitions[$kind]['metadata'] = typesense_search_tenancy_metadata($metadata);
    }

    return $definitions;
}

/**
 * The clash check, run at provisioning with a key that can list every collection and alias (the bootstrap key).
 *
 * Refused: a prefix that is not valid; a prefix overlapping a name on the server that is not this installation's
 * (a name inside "<prefix>_*", or another tenant's prefix of which this one is a prefix, or that is a prefix of
 * this one); this prefix's alias pointing outside it; this prefix's collections carrying another installation's
 * identity or none. A server that cannot be asked refuses too.
 *
 * @param string      $key    A key with collections:list and aliases:list, used for this call only
 * @param string|null $prefix The prefix to check; null for the current one
 *
 * @return array Why the prefix is refused, one line each; empty when it may be used
 */
function typesense_search_tenancy_check_clash(string $key, ?string $prefix = null): array
{
    $prefix = $prefix ?? typesense_search_tenancy_prefix();
    if (!typesense_search_tenancy_prefix_valid($prefix)) {
        return array('the prefix is empty or not valid: letters, digits and underscore, up to 32 characters');
    }
    list($collections, $aliases) = typesense_search_client_call_many(array(
        array('path' => '/collections', 'query' => array('exclude_fields' => 'fields'), 'key' => $key),
        array('path' => '/aliases', 'key' => $key),
    ));
    if (!$collections['ok'] || !$aliases['ok'] || !is_array($aliases['body']['aliases'] ?? null)) {
        $failed = !$collections['ok'] ? $collections : $aliases;
        return array('the server could not list its collections and aliases: ' . $failed['error']);
    }

    $reasons = array();
    $foreign = array('overlap' => 0, 'inside' => 0, 'identity' => 0);
    $ours = array();
    $names = array();
    foreach ($collections['body'] as $collection) {
        $name = (string)($collection['name'] ?? '');
        $names[$name] = $collection['metadata'] ?? null;
    }
    foreach ($aliases['body']['aliases'] as $alias) {
        $names += array((string)($alias['name'] ?? '') => null);
    }
    foreach ($names as $name => $metadata) {
        $parsed = typesense_search_tenancy_name_parse($name);
        if ($parsed !== null && $parsed['prefix'] === $prefix) {
            if ($parsed['generation'] !== null) {
                $ours[$name] = typesense_search_tenancy_identity_matches($metadata);
            }
        } elseif (str_starts_with($name, $prefix . '_')) {
            $foreign['inside']++;
        } elseif ($parsed !== null && typesense_search_tenancy_prefixes_overlap($prefix, $parsed['prefix'])) {
            $foreign['overlap']++;
        }
    }
    foreach ($aliases['body']['aliases'] as $alias) {
        $parsed = typesense_search_tenancy_name_parse((string)($alias['name'] ?? ''));
        if ($parsed === null || $parsed['prefix'] !== $prefix) {
            continue;
        }
        $target = (string)($alias['collection_name'] ?? '');
        $points = typesense_search_tenancy_name_parse($target);
        if ($points === null || $points['prefix'] !== $prefix || $points['kind'] !== $parsed['kind']) {
            $reasons[] = 'the alias ' . $alias['name'] . ' points outside this prefix';
        }
    }
    $foreign['identity'] = count(array_filter($ours, fn($matches) => !$matches));

    if ($foreign['inside'] > 0) {
        $reasons[] = $foreign['inside'] . ' name(s) on the server that this installation did not make start with "'
            . $prefix . '_"';
    }
    if ($foreign['overlap'] > 0) {
        $reasons[] = $foreign['overlap'] . ' name(s) on the server belong to a prefix that overlaps "' . $prefix
            . '" (one is a prefix of the other)';
    }
    if ($foreign['identity'] > 0) {
        $reasons[] = 'the prefix\'s existing collections carry another installation\'s identity or none ('
            . $foreign['identity'] . '): refusing to build over them';
    }

    return $reasons;
}

/**
 * The serve-time guard in detail: every alias resolves to this prefix's collection of its kind, all three in the
 * same generation, and each collection's metadata names that generation and carries this installation's
 * identity. Read with the admin key (aliases:get, collections:get on the prefix).
 *
 * @return array ok, generation (0 when unresolved), error (why not, for the alert)
 */
function typesense_search_tenancy_alias_check(): array
{
    $check = array('ok' => false, 'generation' => 0, 'error' => '');
    $kinds = array('resources', 'memberships', 'grants');
    if (typesense_search_tenancy_prefix() === '') {
        $check['error'] = 'the collection prefix is not set or not valid';
        return $check;
    }
    $specs = array();
    foreach ($kinds as $kind) {
        $path = '/aliases/' . rawurlencode(typesense_search_tenancy_name($kind));
        $specs[] = array('path' => $path, 'timeout' => 'search');
    }
    $generation = null;
    $specs_collections = array();
    foreach (typesense_search_client_call_many($specs) as $i => $result) {
        $alias = typesense_search_tenancy_name($kinds[$i]);
        $target = $result['ok'] ? (string)($result['body']['collection_name'] ?? '') : '';
        $points = typesense_search_tenancy_name_parse($target);
        if (!$result['ok']) {
            $check['error'] = 'the alias ' . $alias . ' could not be read: ' . $result['error'];
            return $check;
        }
        if (
            $points === null || $points['generation'] === null
            || $target !== typesense_search_tenancy_name($kinds[$i], $points['generation'])
        ) {
            $check['error'] = 'the alias ' . $alias . ' points outside this prefix';
            return $check;
        }
        if ($generation !== null && $points['generation'] !== $generation) {
            $check['error'] = 'the aliases point at different generations';
            return $check;
        }
        $generation = $points['generation'];
        $specs_collections[] = array('path' => '/collections/' . rawurlencode($target), 'timeout' => 'search');
    }
    foreach (typesense_search_client_call_many($specs_collections) as $i => $result) {
        $name = typesense_search_tenancy_name($kinds[$i], $generation);
        if (!$result['ok']) {
            $check['error'] = 'the collection ' . $name . ' could not be read: ' . $result['error'];
            return $check;
        }
        $metadata = $result['body']['metadata'] ?? null;
        $named = is_array($metadata) && ($metadata['generation'] ?? null) === $generation;
        if (!$named || !typesense_search_tenancy_identity_matches($metadata)) {
            $check['error'] = 'the collection ' . $name . ' does not carry this installation\'s identity';
            return $check;
        }
    }
    $check['ok'] = true;
    $check['generation'] = (int)$generation;

    return $check;
}

/**
 * The serve-time guard (Q12.8): before serving and after every rebuild, does the alias resolve to a generation
 * carrying this installation's identity? False means fall back and alert, with
 * typesense_search_tenancy_alias_check() giving the reason.
 *
 * @return bool
 */
function typesense_search_tenancy_verify_alias(): bool
{
    return typesense_search_tenancy_alias_check()['ok'];
}

/**
 * The configuration to store after "regenerate prefix" (the clone-of-production case, Q9.12): a new random prefix,
 * and every stored key cleared with its recorded id, as those are scoped to the old prefix and, in a clone, are
 * production's (a later rotation must not revoke them). The old collections are left alone. Null when the config.php
 * override locks the prefix.
 *
 * @param array $stored The plugin configuration as stored, keyed by global name
 *
 * @return array|null The configuration to store, keyed by global name
 */
function typesense_search_tenancy_regenerate(array $stored): ?array
{
    if (typesense_search_option_locked('prefix')) {
        return null;
    }
    do {
        $prefix = typesense_search_tenancy_generate_prefix();
    } while ($prefix === ($stored['typesense_search_prefix'] ?? null));
    $stored['typesense_search_prefix'] = $prefix;
    foreach (array_keys(typesense_search_option_editable()) as $name) {
        if (typesense_search_option_secret($name) && !typesense_search_option_locked($name)) {
            $stored['typesense_search_' . $name] = '';
            $stored['typesense_search_' . $name . '_id'] = 0;
        }
    }

    return $stored;
}

/**
 * The "regenerate prefix" button beside the prefix row. It submits its own form, typesense_search_regenerate, so it
 * is never the default button of the settings form.
 *
 * @return string HTML
 */
function typesense_search_tenancy_regenerate_html(): string
{
    global $lang;

    return '<div class="Question"><label></label>'
        . '<input type="submit" form="typesense_search_regenerate" name="regenerate_prefix" value="'
        . escape($lang['typesense_search_prefix_regenerate']) . '" onclick="return confirm('
        . escape(json_encode($lang['typesense_search_prefix_regenerate_confirm'])) . ');" />'
        . '<div class="clearerleft"></div>'
        . typesense_search_option_help_html($lang['typesense_search_prefix_regenerate_help'])
        . '</div>';
}
