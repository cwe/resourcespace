<?php

// A1 battery: the plugin skeleton. The yaml as core parses it, the option accessor's precedence (override, stored,
// default) through core's own loader, the lock and what the page draws for it (a key is never drawn), the save rules
// (a locked option keeps its stored value, a blank key keeps the stored key, the prefix is generated once), the setup
// page rendered through core's generator, and the Q8.3 test: a group configuration override of
// $typesense_search_enabled, applied as setup_user() applies it, is what the search hook sees.
require __DIR__ . '/fixture.php';

$A1_PLUGIN = $ROOT . '/plugins/typesense_search';
$A1_SEEN = array();

// ----------------------------------------------------------------------------------------- the files
$yaml = get_plugin_yaml('typesense_search', true);
harness_check('A1.1 core parses the yaml', is_array($yaml) && $yaml['name'] === 'typesense_search');
harness_check('A1.2 a global plugin: disable_group_select', is_array($yaml) && $yaml['disable_group_select'] === '1');
harness_check(
    'A1.3 version, description and setup page in the yaml',
    is_array($yaml) && $yaml['version'] === '1.0' && $yaml['desc'] !== ''
        && $yaml['config_url'] === 'plugins/typesense_search/pages/setup.php'
);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($A1_PLUGIN . '/pages/setup.php') . ' 2>&1', $lint, $code);
harness_check('A1.4 the setup page parses', $code === 0, implode(' ', $lint));
$leaks = array();
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($A1_PLUGIN, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = $file->getPathname();
    if (substr($path, -4) !== '.php' || preg_match('#/(harness|tests)/#', $path) === 1) {
        continue;
    }
    if (preg_match('/^\s*(require|include)(_once)?\b[^;\n]*harness/im', file_get_contents($path)) === 1) {
        $leaks[] = substr($path, strlen($A1_PLUGIN));
    }
}
harness_check('A1.5 no plugin file loads anything from the harness', count($leaks) === 0, implode(', ', $leaks));
include $ROOT . '/languages/en.php';
include $A1_PLUGIN . '/languages/en.php';
harness_check(
    'A1.6 the language file carries the page\'s keys',
    isset($lang['typesense_search_locked'], $lang['typesense_search_locked_hidden'], $lang['typesense_search_prefix'])
);

// ----------------------------------------------------------------------------------------- the accessor
$saved = array();
foreach (array('host', 'port', 'protocol', 'search_key', 'admin_key', 'prefix', 'enabled') as $name) {
    $saved[$name] = $GLOBALS['typesense_search_' . $name];
}
harness_check('A1.7 the harness configuration reads as the stored tier', typesense_search_option('port') === $HARNESS['ts']['port']);
unset($typesense_search_prefix);
include $A1_PLUGIN . '/config/config.php';
harness_check(
    'A1.8 the default from config/config.php, which sets no prefix',
    typesense_search_option('port') === 8108 && typesense_search_option('enabled') === false
        && typesense_search_option('prefix') === null
);
include_plugin_config('typesense_search', '', json_encode(array('typesense_search_port' => 18208, 'typesense_search_enabled' => true)));
harness_check(
    'A1.9 the stored value, loaded by core\'s include_plugin_config(), beats the default',
    typesense_search_option('port') === 18208 && typesense_search_option('enabled') === true
);
$typesense_search_port_override = 9999;
harness_check(
    'A1.10 the config.php override beats the stored value and locks the option',
    typesense_search_option('port') === 9999 && typesense_search_option_locked('port')
);
unset($typesense_search_port_override);
harness_check(
    'A1.11 without the override the stored value is back',
    typesense_search_option('port') === 18208 && !typesense_search_option_locked('port')
);
harness_check(
    'A1.12 config.php-only knobs read through the same accessor',
    typesense_search_option('max_rows') === 25000 && typesense_search_option('import_concurrency') === 2
        && typesense_search_option('only') === false
);
harness_check('A1.13 an option that does not exist is null', typesense_search_option('no_such_option') === null);
foreach ($saved as $name => $value) {
    $GLOBALS['typesense_search_' . $name] = $value;
}

// ----------------------------------------------------------------------------------------- the lock on the page
$typesense_search_host_override = 'locked.example';
$typesense_search_search_key_override = 'sekrit-locked';
harness_check(
    'A1.14 a locked option is drawn as locked in global configuration to: value',
    typesense_search_option_locked_text('host') === 'Locked in global configuration to: locked.example'
);
harness_check(
    'A1.15 a locked key is drawn as hidden',
    typesense_search_option_locked_text('search_key') === 'Locked in global configuration (hidden)'
);
$typesense_search_enabled_override = true;
$typesense_search_excluded_fields_override = array(3, 8);
harness_check(
    'A1.16 a locked boolean and a locked list are drawn readably',
    typesense_search_option_locked_text('enabled') === 'Locked in global configuration to: True'
        && typesense_search_option_locked_text('excluded_fields') === 'Locked in global configuration to: 3, 8'
);
unset($typesense_search_enabled_override, $typesense_search_excluded_fields_override);

// ----------------------------------------------------------------------------------------- the save rules
$stored = array(
    'typesense_search_host' => 'stored.example',
    'typesense_search_search_key' => 'oldkey',
    'typesense_search_admin_key' => 'oldadmin',
);
$posted = array(
    'host' => 'posted.example',
    'port' => '18208',
    'protocol' => 'https',
    'search_key' => '',
    'admin_key' => ' newadmin ',
    'enabled' => '1',
    'show_indicator' => '0',
    'excluded_fields' => array('3', '3', '8', 'x'),
    'prefix' => 'posted_prefix',
);
$config = typesense_search_option_save($stored, $posted);
harness_check('A1.17 a locked option keeps its stored value whatever was posted', $config['typesense_search_host'] === 'stored.example');
harness_check('A1.18 a blank key keeps the stored key', $config['typesense_search_search_key'] === 'oldkey');
harness_check('A1.19 a posted key replaces the stored key', $config['typesense_search_admin_key'] === 'newadmin');
harness_check(
    'A1.20 values are stored with their types',
    $config['typesense_search_port'] === 18208 && $config['typesense_search_protocol'] === 'https'
        && $config['typesense_search_enabled'] === true && $config['typesense_search_show_indicator'] === false
        && $config['typesense_search_excluded_fields'] === array(3, 8)
);
harness_check(
    'A1.21 the prefix is generated on the first save, valid, and never read from the form',
    isset($config['typesense_search_prefix']) && $config['typesense_search_prefix'] !== 'posted_prefix'
        && typesense_search_tenancy_prefix_valid($config['typesense_search_prefix'])
        && strlen($config['typesense_search_prefix']) === 12,
    $config['typesense_search_prefix'] ?? 'none'
);
$again = typesense_search_option_save($config, array('host' => 'x', 'prefix' => 'posted_prefix'));
harness_check('A1.22 later saves keep the prefix', $again['typesense_search_prefix'] === $config['typesense_search_prefix']);
$typesense_search_prefix_override = 'hostedname';
$locked = typesense_search_option_save(array(), array());
harness_check('A1.23 no prefix is generated while config.php assigns one', !isset($locked['typesense_search_prefix']));
unset($typesense_search_prefix_override);
harness_check(
    'A1.24 two generated prefixes differ',
    typesense_search_tenancy_generate_prefix() !== typesense_search_tenancy_generate_prefix()
);

// ----------------------------------------------------------------------------------------- the page through core's generator
$usersession = 'harness-session';
$typesense_search_admin_key = 'sekrit-stored';
$typesense_search_prefix = '';
ob_start();
config_gen_setup_html(typesense_search_option_page_def(), 'typesense_search', null, $lang['typesense_search_configuration']);
$html = ob_get_clean();
harness_check(
    'A1.25 the page renders through core\'s generator with the form token and the save buttons',
    strpos($html, 'name="CSRFToken"') !== false && strpos($html, 'name="save"') !== false
        && strpos($html, 'name="typesense_search_port"') !== false
);
harness_check(
    'A1.26 a locked row is read-only text with no input',
    strpos($html, 'Locked in global configuration to: locked.example') !== false
        && strpos($html, 'name="typesense_search_host"') === false
);
harness_check(
    'A1.27 a locked key is hidden and no key value is anywhere in the HTML',
    strpos($html, 'Locked in global configuration (hidden)') !== false && strpos($html, 'sekrit') === false
        && strpos($html, 'name="typesense_search_search_key"') === false
);
harness_check(
    'A1.28 a stored key is a blank password field shown as set',
    preg_match('/name="typesense_search_admin_key"[^>]*type="password"[^>]*value=""/', $html) === 1
        && strpos($html, 'Set. Leave blank to keep the stored key.') !== false
);
// The field list inside the control is core's config_multi_ftype_select(); its ORDER BY is ambiguous on SQLite, so
// only the control itself is checked here.
harness_check(
    'A1.29 the prefix is read-only and not yet generated; the excluded-fields control is drawn',
    strpos($html, 'Not yet generated') !== false && strpos($html, 'name="typesense_search_prefix"') === false
        && strpos($html, 'name="typesense_search_excluded_fields[]"') !== false
);
harness_check('A1.30 the health placeholder is drawn', strpos($html, 'Not available until the index has been built.') !== false);
$typesense_search_prefix = $saved['prefix'];
$typesense_search_admin_key = $saved['admin_key'];
unset($typesense_search_host_override, $typesense_search_search_key_override);

// ----------------------------------------------------------------------------------------- Q8.3: the group override
// A web request: boot.php loads the plugin's configuration, then authenticate.php's setup_user() applies the user
// group's configuration override through override_rs_variables_by_eval(), before any page code runs a search. The
// plugin has no search hook until D1, so a probe in its place records what the accessor says when the hook fires;
// once the real hook exists the check is that the override stops it serving.
$A1_PROBE = !function_exists('HookTypesense_searchAllExternal_search');
if ($A1_PROBE) {
    function HookTypesense_searchAllExternal_search()
    {
        global $A1_SEEN;
        $A1_SEEN[] = typesense_search_option('enabled');
        return false;
    }
}
$o = array(
    'restypes' => '', 'order_by' => 'relevance', 'archive' => '0', 'fetchrows' => array(0, 200), 'sort' => 'DESC',
    'access_override' => false, 'ignore_filters' => false, 'return_disk_usage' => false, 'daylimit' => '',
    'return_refs_only' => false, 'editable_only' => false, 'returnsql' => false, 'access' => null, 'smartsearch' => false,
);
$typesense_search_enabled = true;
$USE_PLUGIN = true;
$PLUGIN_STATE = array('hook_reached' => false, 'served' => false, 'no_hook' => false, 'outcome' => null, 'reason' => null);
harness_reset_caches();
harness_search('sunset', $o);
harness_check(
    'A1.31 the search hook fires with the plugin enabled and sees enabled',
    $PLUGIN_STATE['hook_reached'] && ($A1_PROBE ? $A1_SEEN === array(true) : !$PLUGIN_STATE['no_hook'])
);

$code = '$typesense_search_enabled = false;';
override_rs_variables_by_eval($GLOBALS, '//SIG' . sign_code($code) . "\n" . $code, 'usergroup');
harness_check(
    'A1.32 the group override is applied to the global before any search runs',
    $typesense_search_enabled === false && typesense_search_option('enabled') === false
);
$A1_SEEN = array();
$PLUGIN_STATE = array('hook_reached' => false, 'served' => false, 'no_hook' => false, 'outcome' => null, 'reason' => null);
harness_reset_caches();
harness_search('sunset', $o);
harness_check(
    'A1.33 the search hook sees the group override: enabled is false for a user of that group (Q8.3)',
    $PLUGIN_STATE['hook_reached'] && ($A1_PROBE ? $A1_SEEN === array(false) : !$PLUGIN_STATE['served'])
);
$typesense_search_enabled_override = true;
harness_check(
    'A1.34 a config.php override still wins over the group override',
    typesense_search_option('enabled') === true
);
unset($typesense_search_enabled_override);
override_rs_variables_by_eval($GLOBALS, '', 'usergroup');
harness_check('A1.35 core reverts the group override and the stored value is back', $typesense_search_enabled === true);

$code = '$typesense_search_only = true;';
override_rs_variables_by_eval($GLOBALS, '//SIG' . sign_code($code) . "\n" . $code, 'usergroup');
harness_check('A1.36 Typesense-only mode is a group override of $typesense_search_only', typesense_search_option('only') === true);
override_rs_variables_by_eval($GLOBALS, '', 'usergroup');
harness_check('A1.37 Typesense-only mode is off once the override is reverted', typesense_search_option('only') === false);
$USE_PLUGIN = false;

harness_finish('A1_skeleton');
