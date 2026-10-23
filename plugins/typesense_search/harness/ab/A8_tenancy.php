<?php

// A8 battery: tenancy. The prefix through the accessor (override over stored, invalid and empty refused), every
// generated name against the pattern, the identity in each generation's metadata, two tenants on the private
// Typesense each built, aliased, searched through its alias and deleted without touching the other, the clash check
// (an overlapping prefix either way, a foreign name inside the pattern, a build over another installation's
// collections, an invalid prefix, a key that cannot list), the serve-time alias guard, "regenerate prefix", and a
// scan of the plugin for server-side names built outside typesense_search_tenancy_name().
require __DIR__ . '/boot.php';

$A8_KEY = $HARNESS['ts']['key']; // the private server's bootstrap key: lists every collection and alias
$A8_PLUGIN = $ROOT . '/plugins/typesense_search';
$A8_RANDOM = function (): string {
    return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 2)), 0, 8);
};
// Random tokens, so the battery cannot meet another battery's tenant on the shared server. B's prefix holds an
// underscore so that a prefix of it can be tried.
$A8_A = array('prefix' => 'a8' . $A8_RANDOM(), 'baseurl' => 'https://a.example/rs/', 'db' => 'rs_tenant_a');
$A8_B = array('prefix' => 'a8' . $A8_RANDOM() . '_b', 'baseurl' => 'https://b.example', 'db' => 'rs_tenant_b');

/** Act as a tenant: its stored prefix, base URL and database name. */
function a8_as(array $tenant): void
{
    unset($GLOBALS['typesense_search_prefix_override']);
    $GLOBALS['typesense_search_prefix'] = $tenant['prefix'];
    $GLOBALS['baseurl'] = $tenant['baseurl'];
    $GLOBALS['mysql_db'] = $tenant['db'];
}

/** Does name() throw for this configuration? */
function a8_refused(): bool
{
    try {
        typesense_search_tenancy_name('resources', 1);
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}

/** The refs a search through the tenant's resources alias returns. */
function a8_search(): array
{
    $result = typesense_search_client_search(
        typesense_search_tenancy_name('resources'),
        array('q' => '*', 'query_by' => 'title', 'include_fields' => 'ref,title')
    );
    $titles = array();
    foreach ($result['body']['hits'] ?? array() as $hit) {
        $titles[] = $hit['document']['title'];
    }
    sort($titles);
    return $titles;
}

/** A tenant's generation built as the rebuild will: the definitions with the identity, created, filled, aliased. */
function a8_build(array $tenant, int $generation, string $title): bool
{
    a8_as($tenant);
    $ok = true;
    foreach (typesense_search_tenancy_definitions(typesense_search_schema_generation($generation)) as $definition) {
        $ok = typesense_search_client_collection_create($definition)['ok'] && $ok;
    }
    $doc = array(
        'id' => '1', 'ref' => 1, 'ref_s' => '1', 'title' => $title, 'resource_type' => 1, 'archive' => 0,
        'access' => 0, 'creation_date' => 0, 'modified_date' => 0, 'date_field_sort' => '0:', 'nodes' => array(0),
        'populated_field_ids' => array(0), 'hit_count' => 0, 'ur_hits' => 0, 'hits_ur' => 0, 'date_ref' => 1,
        'rating_ur' => 0,
    );
    $ok = typesense_search_client_import(typesense_search_tenancy_name('resources', $generation), array($doc))['ok'] && $ok;
    foreach (typesense_search_schema_aliases($generation) as $alias => $collection) {
        $ok = typesense_search_client_alias_upsert($alias, $collection)['ok'] && $ok;
    }
    return $ok;
}

/** Drop a tenant's aliases and every generation up to $last, through the plugin's client and name(). */
function a8_drop(array $tenant, int $last): void
{
    a8_as($tenant);
    foreach (array('resources', 'memberships', 'grants') as $kind) {
        typesense_search_client_alias_delete(typesense_search_tenancy_name($kind));
    }
    for ($generation = 1; $generation <= $last; $generation++) {
        foreach (array('grants', 'memberships', 'resources') as $kind) {
            typesense_search_client_collection_delete(typesense_search_tenancy_name($kind, $generation));
        }
    }
}

/** Every collection name on the server. */
function a8_collections(): array
{
    list(, $body) = harness_ts('GET', '/collections?exclude_fields=fields');
    return array_column(is_array($body) ? $body : array(), 'name');
}

// ----------------------------------------------------------------------------------------- the prefix
$typesense_search_prefix = 'storedprefix';
harness_check('A8.1 the stored prefix is read', typesense_search_tenancy_prefix() === 'storedprefix');
$typesense_search_prefix_override = 'hosted_site1';
harness_check('A8.2 the config.php override wins over the stored value', typesense_search_tenancy_prefix() === 'hosted_site1' && typesense_search_tenancy_name('grants', 2) === 'hosted_site1_grants_g2' && typesense_search_option_locked('prefix'));
foreach (array('' => 'empty', 'bad-prefix' => 'hyphen', 'a b' => 'space', 'é' => 'non-ASCII', str_repeat('x', 33) => '33 characters') as $bad => $what) {
    $typesense_search_prefix_override = $bad;
    harness_check('A8.3 an invalid override is refused, not replaced by the stored value: ' . $what, typesense_search_tenancy_prefix() === '' && a8_refused());
}
$typesense_search_prefix_override = array('x');
harness_check('A8.3 a non-string override is refused', typesense_search_tenancy_prefix() === '' && a8_refused());
unset($typesense_search_prefix_override);
foreach (array('' => 'empty', 'no.dots' => 'dot', str_repeat('y', 33) => '33 characters') as $bad => $what) {
    $typesense_search_prefix = $bad;
    harness_check('A8.4 an invalid stored prefix is refused: ' . $what, typesense_search_tenancy_prefix() === '' && a8_refused());
}
$typesense_search_prefix = str_repeat('z', 32);
harness_check('A8.4 32 characters is within the cap', typesense_search_tenancy_prefix() === str_repeat('z', 32));
$generated = typesense_search_tenancy_generate_prefix();
harness_check('A8.5 a generated prefix is twelve lower-case letters and digits', preg_match('/^[a-z0-9]{12}$/', $generated) === 1, $generated);

// ----------------------------------------------------------------------------------------- every generated name
$pattern = '/^[A-Za-z0-9_]{1,32}_(resources|memberships|grants|related)(_g[1-9][0-9]*)?$/';
$names_ok = true;
$parsed_ok = true;
$bad_names = array();
foreach (array($A8_A, $A8_B, array('prefix' => 'harness') + $A8_A, array('prefix' => str_repeat('Z', 32)) + $A8_A) as $tenant) {
    a8_as($tenant);
    $names = array(typesense_search_tenancy_name('related'));
    foreach (array('resources', 'memberships', 'grants') as $kind) {
        $names[] = typesense_search_tenancy_name($kind);
        foreach (array(1, 2, 17, 4096) as $generation) {
            $names[] = typesense_search_tenancy_name($kind, $generation);
        }
    }
    $names = array_merge($names, array_keys(typesense_search_schema_aliases(3)), array_values(typesense_search_schema_aliases(3)));
    foreach (typesense_search_tenancy_definitions(typesense_search_schema_generation(5)) as $definition) {
        $names[] = $definition['name'];
    }
    foreach ($names as $name) {
        $parsed = typesense_search_tenancy_name_parse($name);
        if (preg_match($pattern, $name) !== 1 || !str_starts_with($name, $tenant['prefix'] . '_')) {
            $names_ok = false;
            $bad_names[] = $name;
        }
        if ($parsed === null || $parsed['prefix'] !== $tenant['prefix'] || typesense_search_tenancy_name($parsed['kind'], $parsed['generation']) !== $name) {
            $parsed_ok = false;
            $bad_names[] = $name . ' (parse)';
        }
    }
}
harness_check('A8.6 every generated name matches <prefix>_<kind>[_g<N>]', $names_ok, implode(', ', $bad_names));
harness_check('A8.7 every generated name parses back to its prefix, kind and generation', $parsed_ok, implode(', ', $bad_names));
a8_as($A8_A);
harness_check('A8.8 the key pattern is the regular expression "<prefix>_.*" (X1: Typesense reads it as a regex)', typesense_search_tenancy_key_pattern() === $A8_A['prefix'] . '_.*');
harness_check('A8.8 names the plugin does not make do not parse', typesense_search_tenancy_name_parse('a_things') === null && typesense_search_tenancy_name_parse('a_related_g1') === null && typesense_search_tenancy_name_parse('a_resources_g0') === null && typesense_search_tenancy_name_parse('resources') === null);
harness_check('A8.9 the overlap rule: equal, one a prefix of the other at an underscore; not "site" and "site2"', typesense_search_tenancy_prefixes_overlap('site', 'site') && typesense_search_tenancy_prefixes_overlap('site', 'site_a') && typesense_search_tenancy_prefixes_overlap('site_a', 'site') && !typesense_search_tenancy_prefixes_overlap('site', 'site2') && !typesense_search_tenancy_prefixes_overlap('site_a', 'site_b'));

// ----------------------------------------------------------------------------------------- identity and metadata
a8_as($A8_A);
$identity = typesense_search_tenancy_identity();
harness_check('A8.10 identity: $baseurl without its trailing slash, the database name, the yaml version', $identity === array('baseurl' => 'https://a.example/rs', 'database' => 'rs_tenant_a', 'plugin_version' => '1.0'), json_encode($identity));
$definitions = typesense_search_tenancy_definitions(typesense_search_schema_generation(4));
$metadata_ok = true;
foreach ($definitions as $definition) {
    $metadata_ok = $metadata_ok && $definition['metadata'] === array('generation' => 4, 'schema_version' => typesense_search_schema_version(), 'installation' => $identity);
}
harness_check('A8.11 every definition\'s metadata is the schema\'s plus the installation identity', $metadata_ok, json_encode($definitions['grants']['metadata']));
$other_version = array('installation' => array('plugin_version' => '9.9') + $identity);
$no_baseurl = array('installation' => array('database' => 'rs_tenant_a', 'plugin_version' => '1.0'));
$other_db = array('installation' => array('database' => 'rs_other') + $identity);
harness_check('A8.12 the identity match ignores the plugin version and needs base URL and database', typesense_search_tenancy_identity_matches($other_version) && !typesense_search_tenancy_identity_matches($no_baseurl) && !typesense_search_tenancy_identity_matches($other_db) && !typesense_search_tenancy_identity_matches(array('generation' => 1)) && !typesense_search_tenancy_identity_matches(null));

// ----------------------------------------------------------------------------------------- two tenants on the server
harness_check('A8.13 tenant A built: three collections with its identity, aliased', a8_build($A8_A, 1, 'tenant a'));
harness_check('A8.13 tenant B built: three collections with its identity, aliased', a8_build($A8_B, 1, 'tenant b'));
a8_as($A8_A);
$stored = typesense_search_client_collection_get(typesense_search_tenancy_name('memberships', 1));
harness_check('A8.14 the server stores the identity in the metadata', ($stored['body']['metadata']['installation'] ?? null) === typesense_search_tenancy_identity(), json_encode($stored['body']['metadata'] ?? null));
harness_check('A8.15 A searched through its alias sees only its own document', a8_search() === array('tenant a'), json_encode(a8_search()));
a8_as($A8_B);
harness_check('A8.15 B searched through its alias sees only its own document', a8_search() === array('tenant b'), json_encode(a8_search()));

// ----------------------------------------------------------------------------------------- the alias guard
a8_as($A8_A);
$check = typesense_search_tenancy_alias_check();
harness_check('A8.16 the guard passes A as A', typesense_search_tenancy_verify_alias() && $check['generation'] === 1, json_encode($check));
a8_as($A8_B);
harness_check('A8.16 the guard passes B as B', typesense_search_tenancy_verify_alias());
a8_as(array('baseurl' => $A8_B['baseurl']) + $A8_A);
$check = typesense_search_tenancy_alias_check();
harness_check('A8.17 the guard fails A\'s prefix under another base URL (a clone)', !typesense_search_tenancy_verify_alias() && str_contains($check['error'], 'identity'), $check['error']);
a8_as(array('db' => 'rs_other') + $A8_A);
harness_check('A8.17 the guard fails A\'s prefix under another database name', !typesense_search_tenancy_verify_alias());
a8_as($A8_A);
typesense_search_client_alias_upsert(typesense_search_tenancy_name('resources'), $A8_B['prefix'] . '_resources_g1');
$check = typesense_search_tenancy_alias_check();
harness_check('A8.18 the guard fails A\'s alias pointed at B\'s collection', !$check['ok'] && str_contains($check['error'], 'outside'), $check['error']);
harness_check('A8.18 the guard\'s reason does not name B\'s collection', !str_contains($check['error'], $A8_B['prefix']), $check['error']);
typesense_search_client_alias_upsert(typesense_search_tenancy_name('resources'), typesense_search_tenancy_name('resources', 1));
harness_check('A8.19 a second generation for A built and swapped', a8_build($A8_A, 2, 'tenant a, generation 2'));
typesense_search_client_alias_upsert(typesense_search_tenancy_name('grants'), typesense_search_tenancy_name('grants', 1));
$check = typesense_search_tenancy_alias_check();
harness_check('A8.20 the guard fails aliases at different generations', !$check['ok'], $check['error']);
typesense_search_client_alias_upsert(typesense_search_tenancy_name('grants'), typesense_search_tenancy_name('grants', 2));
$check = typesense_search_tenancy_alias_check();
harness_check('A8.21 the guard passes A on generation 2', $check['ok'] && $check['generation'] === 2, json_encode($check));
harness_check('A8.21 A\'s search follows the swap', a8_search() === array('tenant a, generation 2'), json_encode(a8_search()));
a8_as($A8_B);
harness_check('A8.21 B is untouched by A\'s swap', a8_search() === array('tenant b') && typesense_search_tenancy_verify_alias());
a8_as($A8_A);
typesense_search_client_alias_delete(typesense_search_tenancy_name('memberships'));
$check = typesense_search_tenancy_alias_check();
harness_check('A8.22 the guard fails a missing alias', !$check['ok'] && str_contains($check['error'], 'could not be read'), $check['error']);
typesense_search_client_alias_upsert(typesense_search_tenancy_name('memberships'), typesense_search_tenancy_name('memberships', 2));
$typesense_search_prefix = '';
harness_check('A8.23 the guard fails without a prefix, sending nothing', !typesense_search_tenancy_verify_alias());

// ----------------------------------------------------------------------------------------- the clash check
a8_as($A8_A);
$reasons = typesense_search_tenancy_check_clash($A8_KEY);
harness_check('A8.24 A\'s own prefix is clear: its own collections and aliases, its identity', $reasons === array(), implode('; ', $reasons));
a8_as($A8_B);
$reasons = typesense_search_tenancy_check_clash($A8_KEY);
harness_check('A8.24 B\'s own prefix is clear', $reasons === array(), implode('; ', $reasons));
$candidate = $A8_A['prefix'] . '_x';
$reasons = typesense_search_tenancy_check_clash($A8_KEY, $candidate);
harness_check('A8.25 a prefix prefixed by another tenant\'s is refused', count($reasons) === 1 && str_contains($reasons[0], 'overlaps'), implode('; ', $reasons));
harness_check('A8.25 the reason does not name the other tenant', !str_contains(implode(' ', $reasons), $A8_A['prefix'] . '_resources'), implode('; ', $reasons));
$candidate = substr($A8_B['prefix'], 0, -2); // B is "<that>_b"
$reasons = typesense_search_tenancy_check_clash($A8_KEY, $candidate);
harness_check('A8.26 a prefix that is a prefix of another tenant\'s is refused', count($reasons) === 1 && str_contains($reasons[0], 'did not make'), implode('; ', $reasons));
harness_check('A8.26 the reason does not name the other tenant', !str_contains(implode(' ', $reasons), $A8_B['prefix']), implode('; ', $reasons));
harness_check('A8.27 a prefix sharing only the leading characters is clear', typesense_search_tenancy_check_clash($A8_KEY, $A8_A['prefix'] . '2') === array());
a8_as(array('baseurl' => 'https://clone.example') + $A8_A);
$reasons = typesense_search_tenancy_check_clash($A8_KEY);
harness_check('A8.28 a build over another installation\'s collections (same prefix, other identity) is refused', count($reasons) === 1 && str_contains($reasons[0], 'identity'), implode('; ', $reasons));
a8_as($A8_A);
$foreign = $A8_A['prefix'] . '_scratch';
list($status) = harness_ts('POST', '/collections', array('name' => $foreign, 'fields' => array(array('name' => 'x', 'type' => 'string'))));
$reasons = typesense_search_tenancy_check_clash($A8_KEY);
harness_check('A8.29 a name inside "<prefix>_*" that the plugin did not make is refused', $status === 201 && count($reasons) === 1 && str_contains($reasons[0], 'did not make'), implode('; ', $reasons));
harness_ts('DELETE', '/collections/' . rawurlencode($foreign));
harness_ts('PUT', '/aliases/' . rawurlencode(typesense_search_tenancy_name('related')), array('collection_name' => $A8_B['prefix'] . '_resources_g1'));
$reasons = typesense_search_tenancy_check_clash($A8_KEY);
harness_check('A8.30 an alias of the prefix pointing outside it is refused', count($reasons) === 1 && str_contains($reasons[0], 'points outside'), implode('; ', $reasons));
harness_ts('DELETE', '/aliases/' . rawurlencode(typesense_search_tenancy_name('related')));
foreach (array('' => 'an empty', 'bad-prefix' => 'an invalid', str_repeat('q', 33) => 'a 33-character') as $bad => $what) {
    $reasons = typesense_search_tenancy_check_clash($A8_KEY, $bad);
    harness_check('A8.31 ' . $what . ' prefix is refused before the server is asked', count($reasons) === 1 && str_contains($reasons[0], 'not valid'), implode('; ', $reasons));
}
$typesense_search_prefix_override = 'bad-override';
harness_check('A8.31 an invalid override is refused by the clash check', count(typesense_search_tenancy_check_clash($A8_KEY)) === 1);
unset($typesense_search_prefix_override);
$reasons = typesense_search_tenancy_check_clash('not-the-bootstrap-key');
harness_check('A8.32 a key that cannot list refuses, and the key is not in the reason', count($reasons) === 1 && str_contains($reasons[0], 'could not list') && !str_contains($reasons[0], 'not-the-bootstrap-key'), implode('; ', $reasons));

// ----------------------------------------------------------------------------------------- regenerate prefix
$stored = array('typesense_search_prefix' => 'oldprefix', 'typesense_search_search_key' => 's1', 'typesense_search_admin_key' => 'a1', 'typesense_search_host' => 'ts.example');
$config = typesense_search_tenancy_regenerate($stored);
harness_check('A8.33 regenerate stores a new valid prefix and clears both keys, nothing else changed', is_array($config) && $config['typesense_search_prefix'] !== 'oldprefix' && preg_match('/^[a-z0-9]{12}$/', $config['typesense_search_prefix']) === 1 && $config['typesense_search_search_key'] === '' && $config['typesense_search_admin_key'] === '' && $config['typesense_search_host'] === 'ts.example', json_encode($config));
$typesense_search_admin_key_override = 'locked-key';
$config = typesense_search_tenancy_regenerate($stored);
harness_check('A8.34 a key locked in config.php is not touched', is_array($config) && $config['typesense_search_admin_key'] === 'a1' && $config['typesense_search_search_key'] === '');
unset($typesense_search_admin_key_override);
$typesense_search_prefix_override = 'hosted_site1';
harness_check('A8.35 regenerate is refused while the override locks the prefix', typesense_search_tenancy_regenerate($stored) === null);
unset($typesense_search_prefix_override);
$setup = file_get_contents($A8_PLUGIN . '/pages/setup.php');
harness_check('A8.36 the setup page action: admin only, POST enforced, its own form token, locked by the override', str_contains($setup, "checkperm('a')") && preg_match('/regenerate_prefix.{0,40}enforcePostRequest\(false\)/', $setup) === 1 && str_contains($setup, "generateFormToken('typesense_search_regenerate')") && str_contains($setup, "if (!typesense_search_option_locked('prefix'))"));

// ----------------------------------------------------------------------------------------- deleted without touching the other
a8_drop($A8_A, 2);
$names = a8_collections();
$left = array_filter($names, fn($name) => str_starts_with($name, $A8_A['prefix'] . '_'));
harness_check('A8.37 A deleted: none of its collections left', count($left) === 0, implode(', ', $left));
a8_as($A8_B);
harness_check('A8.38 B untouched by A\'s delete: guard passes, search answers', typesense_search_tenancy_verify_alias() && a8_search() === array('tenant b') && in_array(typesense_search_tenancy_name('grants', 1), $names, true));
a8_drop($A8_B, 1);
$left = array_filter(a8_collections(), fn($name) => str_starts_with($name, $A8_B['prefix'] . '_'));
harness_check('A8.39 B deleted: none of its collections left', count($left) === 0, implode(', ', $left));

// ----------------------------------------------------------------------------------------- one place builds names
$builders = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($A8_PLUGIN, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($A8_PLUGIN) + 1));
    if ($file->getExtension() !== 'php' || str_starts_with($path, 'harness/') || $path === 'include/tenancy.php') {
        continue;
    }
    foreach (file($file->getPathname()) as $n => $line) {
        // The prefix joined to anything with an underscore, or a generation suffix: a name built by hand.
        if (preg_match('/(tenancy_prefix\(\)|\$prefix|typesense_search_prefix\b[^_])\s*\.\s*[\'"]_|[\'"]_g[\'"]?\s*\./', $line) === 1) {
            $builders[] = $path . ':' . ($n + 1);
        }
    }
}
harness_check('A8.40 no server-side name is built outside typesense_search_tenancy_name()', count($builders) === 0, implode(', ', $builders));

harness_finish('A8_tenancy');
