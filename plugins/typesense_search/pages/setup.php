<?php

// typesense_search setup page: the Q9.1 shell. Each option draws editable, or locked when config.php sets
// $typesense_search_<option>_override; the keys are password fields shown as set or not set and never echoed; the
// prefix is read-only and generated on the first save (Q9.12); the health block arrives with G1.
include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('a')) {
    exit($lang['error-permissiondenied']);
}

$plugin_name = 'typesense_search';
if (!in_array($plugin_name, $plugins)) {
    plugin_activate_for_setup($plugin_name);
}

// Save: a POST carrying the form token (checked for every POST by authenticate.php) from an administrator.
if ((getval('save', '') !== '' || getval('submit', '') !== '') && enforcePostRequest(false)) {
    $posted = array();
    foreach (typesense_search_option_editable() as $option => $type) {
        $posted[$option] = $type === 'list'
            ? getval('typesense_search_' . $option, array(), false, 'is_array')
            : getval('typesense_search_' . $option, '');
    }
    $config = typesense_search_option_save(get_plugin_config($plugin_name) ?? array(), $posted);
    set_plugin_config($plugin_name, $config);
    foreach ($config as $option => $value) {
        $GLOBALS[$option] = $value;
    }
    if (getval('submit', '') !== '') {
        redirect('pages/team/team_plugins.php');
    }
}

include '../../../include/header.php';
config_gen_setup_html(typesense_search_option_page_def(), $plugin_name, null, $lang['typesense_search_configuration']);
include '../../../include/footer.php';
