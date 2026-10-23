<?php

// typesense_search setup page: the Q9.1 shell. Each option draws editable, or locked when config.php sets
// $typesense_search_<option>_override; the keys are password fields shown as set or not set and never echoed; the
// prefix is read-only, generated on the first save and replaced by "regenerate prefix" (Q9.12); the keys are provisioned
// from the server's bootstrap key (X1); the health block arrives with G1.
include '../../../include/boot.php';
include '../../../include/authenticate.php';

if (!checkperm('a')) {
    exit($lang['error-permissiondenied']);
}

$plugin_name = 'typesense_search';
if (!in_array($plugin_name, $plugins)) {
    plugin_activate_for_setup($plugin_name);
}

// Every action is a POST carrying the form token (checked for every POST by authenticate.php) from an administrator;
// the configuration is stored through typesense_search_option_store(), which keeps the keys out of the logs (X1).
// Regenerate prefix: a POST of its own form; refused when locked.
$regenerated = null;
if (getval('regenerate_prefix', '') !== '' && enforcePostRequest(false)) {
    $config = typesense_search_tenancy_regenerate(get_plugin_config($plugin_name) ?? array());
    $regenerated = $config !== null;
    if ($config !== null) {
        typesense_search_option_store($config);
    }
}

// Save.
if ((getval('save', '') !== '' || getval('submit', '') !== '') && enforcePostRequest(false)) {
    $posted = array();
    foreach (typesense_search_option_editable() as $option => $type) {
        $posted[$option] = $type === 'list'
            ? getval('typesense_search_' . $option, array(), false, 'is_array')
            : getval('typesense_search_' . $option, '');
    }
    typesense_search_option_store(typesense_search_option_save(get_plugin_config($plugin_name) ?? array(), $posted));
    if (getval('submit', '') !== '') {
        redirect('pages/team/team_plugins.php');
    }
}

// Provision or rotate the keys: the bootstrap key is read once and dropped from the request (X1).
$typesense_search_keys_notice = '';
if (getval('typesense_search_keys_provision', '') !== '' && enforcePostRequest(false)) {
    $outcome = typesense_search_keys_provision(getval('typesense_search_bootstrap_key', ''));
    unset($_POST['typesense_search_bootstrap_key'], $_REQUEST['typesense_search_bootstrap_key']);
    $typesense_search_keys_notice = typesense_search_keys_notice($outcome);
}

include '../../../include/header.php';
if ($regenerated !== null) {
    toast_notification(
        $regenerated ? ToastNotificationType::Success : ToastNotificationType::Error,
        $lang[$regenerated ? 'typesense_search_prefix_regenerated' : 'typesense_search_prefix_regenerate_locked']
    );
}
config_gen_setup_html(typesense_search_option_page_def(), $plugin_name, null, $lang['typesense_search_configuration']);
if (!typesense_search_option_locked('prefix')) {
    ?>
    <form id="typesense_search_regenerate" method="post" action="<?php echo escape($_SERVER['PHP_SELF']); ?>">
        <?php generateFormToken('typesense_search_regenerate'); ?>
    </form>
    <?php
}
include '../../../include/footer.php';
