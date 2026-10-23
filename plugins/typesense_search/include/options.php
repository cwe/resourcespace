<?php

/**
 * typesense_search: the options and the one accessor every module reads them through (Q9.1).
 *
 * A setup-page option X lives in $typesense_search_X: core's include_plugin_config() loads the stored value over the
 * default from config/config.php. $typesense_search_X_override in config.php wins over both and locks the row on
 * the setup page, which draws it as "locked in global configuration to: [value]", or "(hidden)" for a key (Q12.1).
 * A group configuration override writes $typesense_search_X itself, so it beats the default and the stored value
 * and loses to the config.php override (P13, Q8.3).
 */

/**
 * Read an option: the config.php override, else the stored value, else the default.
 *
 * @param string $name Option name without the typesense_search_ prefix, e.g. host
 *
 * @return mixed Null for an option that is not defined at all
 */
function typesense_search_option(string $name)
{
    return $GLOBALS['typesense_search_' . $name . '_override'] ?? $GLOBALS['typesense_search_' . $name] ?? null;
}

/**
 * Is an option fixed by $typesense_search_<option>_override in config.php?
 *
 * @param string $name Option name without the typesense_search_ prefix
 *
 * @return bool
 */
function typesense_search_option_locked(string $name): bool
{
    return isset($GLOBALS['typesense_search_' . $name . '_override']);
}

/**
 * The options the setup page stores, name => the type its value is cast to on save: string, int, bool, list (field
 * refs) or secret (a key: a blank form value keeps the stored one and the value is never drawn).
 *
 * @return array
 */
function typesense_search_option_editable(): array
{
    return array(
        'host' => 'string',
        'port' => 'int',
        'protocol' => 'string',
        'search_key' => 'secret',
        'admin_key' => 'secret',
        'enabled' => 'bool',
        'show_indicator' => 'bool',
        'excluded_fields' => 'list',
    );
}

/**
 * Is an option one of the two keys?
 *
 * @param string $name Option name without the typesense_search_ prefix
 *
 * @return bool
 */
function typesense_search_option_secret(string $name): bool
{
    return (typesense_search_option_editable()[$name] ?? '') === 'secret';
}

/**
 * Is a key stored or overridden? The page says set or not set and never shows the value.
 *
 * @param string $name search_key or admin_key
 *
 * @return bool
 */
function typesense_search_option_key_set(string $name): bool
{
    return trim((string)typesense_search_option($name)) !== '';
}

/**
 * The text of a locked row: the value, or no value at all for a key (Q9.1, Q12.1).
 *
 * @param string $name Option name without the typesense_search_ prefix
 *
 * @return string
 */
function typesense_search_option_locked_text(string $name): string
{
    global $lang;

    if (typesense_search_option_secret($name)) {
        return $lang['typesense_search_locked_hidden'];
    }
    $value = typesense_search_option($name);
    if (is_bool($value)) {
        $value = $lang['false-true'][(int)$value];
    } elseif (is_array($value)) {
        $value = implode(', ', $value);
    }

    return str_replace('[value]', (string)$value, $lang['typesense_search_locked']);
}

/**
 * The configuration to store after a save of the setup page.
 *
 * Every editable option that is not locked is taken from $posted and cast to its type; a blank key keeps the stored
 * key; a locked option keeps whatever is stored; the prefix is never read from the form and is generated on the
 * first save unless config.php assigns one (Q9.12).
 *
 * @param array $stored The plugin configuration as stored, keyed by global name; empty on a clean install
 * @param array $posted Form values keyed by option name, as getval() returns them
 *
 * @return array The configuration to store, keyed by global name
 */
function typesense_search_option_save(array $stored, array $posted): array
{
    $config = $stored;
    foreach (typesense_search_option_editable() as $name => $type) {
        if (typesense_search_option_locked($name) || !array_key_exists($name, $posted)) {
            continue;
        }
        $value = $posted[$name];
        switch ($type) {
            case 'int':
                $value = (int)$value;
                break;
            case 'bool':
                $value = (bool)(int)$value;
                break;
            case 'list':
                $value = array_values(array_unique(array_filter(array_map(intval(...), (array)$value))));
                break;
            case 'secret':
                $value = trim((string)$value);
                if ($value === '') {
                    continue 2;
                }
                break;
            default:
                $value = trim((string)$value);
        }
        $config['typesense_search_' . $name] = $value;
    }
    if (
        !typesense_search_option_locked('prefix')
        && !typesense_search_tenancy_prefix_valid((string)($config['typesense_search_prefix'] ?? ''))
    ) {
        $config['typesense_search_prefix'] = typesense_search_tenancy_generate_prefix();
    }

    return $config;
}

/**
 * An option's row on the setup page: the editable control, or the locked read-only row when config.php fixes it.
 *
 * @param string $name     Option name without the typesense_search_ prefix
 * @param array  $editable The control from a config_add_* function
 *
 * @return array
 */
function typesense_search_option_row(string $name, array $editable): array
{
    global $lang;

    if (!typesense_search_option_locked($name)) {
        return $editable;
    }

    return config_add_fixed_input($lang['typesense_search_' . $name], typesense_search_option_locked_text($name));
}

/**
 * A key's row: a password field never filled in, with whether a key is stored (Q12.1).
 *
 * @param string $name  search_key or admin_key
 * @param string $label Row label
 *
 * @return string HTML
 */
function typesense_search_option_key_html(string $name, string $label): string
{
    global $lang;

    $id = 'typesense_search_' . $name;
    $state = typesense_search_option_key_set($name)
        ? $lang['typesense_search_key_set']
        : $lang['typesense_search_key_not_set'];

    return '<div class="Question" id="question_' . $id . '">'
        . '<label for="' . $id . '">' . escape($label) . '</label>'
        . '<input id="' . $id . '" name="' . $id . '" type="password" value="" autocomplete="new-password"'
        . ' style="width:420px" />'
        . '<div class="clearerleft"></div>'
        . typesense_search_option_help_html($state)
        . '</div>';
}

/**
 * Help text under a row, as render_fixed_text_question() draws it.
 *
 * @param string $text Help text
 *
 * @return string HTML
 */
function typesense_search_option_help_html(string $text): string
{
    return '<div class="FormHelp" style="padding:0; clear:left;"><div class="FormHelpInner">' . escape($text)
        . '</div></div>';
}

/**
 * The setup page: an editable control or the locked row per option, the prefix read-only, the health placeholder.
 *
 * @return array Page definition for config_gen_setup_html()
 */
function typesense_search_option_page_def(): array
{
    global $lang;

    $page_def = array();
    $page_def[] = config_add_section_header($lang['typesense_search_server']);
    $page_def[] = typesense_search_option_row(
        'host',
        config_add_text_input('typesense_search_host', $lang['typesense_search_host'])
    );
    $page_def[] = typesense_search_option_row(
        'port',
        config_add_text_input('typesense_search_port', $lang['typesense_search_port'], false, 100)
    );
    $page_def[] = typesense_search_option_row(
        'protocol',
        config_add_single_select(
            'typesense_search_protocol',
            $lang['typesense_search_protocol'],
            array('http' => 'http', 'https' => 'https')
        )
    );
    foreach (array('search_key', 'admin_key') as $key) {
        $page_def[] = typesense_search_option_row(
            $key,
            config_add_html(typesense_search_option_key_html($key, $lang['typesense_search_' . $key]))
        );
    }

    $page_def[] = config_add_section_header($lang['typesense_search_searching']);
    $page_def[] = typesense_search_option_row(
        'enabled',
        config_add_boolean_select('typesense_search_enabled', $lang['typesense_search_enabled'])
    );
    $page_def[] = config_add_html(
        '<div class="Question">' . typesense_search_option_help_html($lang['typesense_search_enabled_help'])
        . '<div class="clearerleft"></div></div>'
    );
    $page_def[] = typesense_search_option_row(
        'show_indicator',
        config_add_boolean_select('typesense_search_show_indicator', $lang['typesense_search_show_indicator'])
    );

    $page_def[] = config_add_section_header($lang['typesense_search_index']);
    $page_def[] = typesense_search_option_row(
        'excluded_fields',
        config_add_multi_ftype_select(
            'typesense_search_excluded_fields',
            $lang['typesense_search_excluded_fields'],
            420
        )
    );
    $page_def[] = config_add_html(
        '<div class="Question">' . typesense_search_option_help_html($lang['typesense_search_excluded_fields_help'])
        . '<div class="clearerleft"></div></div>'
    );
    if (typesense_search_option_locked('prefix')) {
        $page_def[] = config_add_fixed_input(
            $lang['typesense_search_prefix'],
            typesense_search_option_locked_text('prefix')
        );
    } else {
        $prefix = typesense_search_tenancy_prefix();
        $page_def[] = config_add_fixed_input(
            $lang['typesense_search_prefix'],
            $prefix === '' ? $lang['typesense_search_prefix_unset'] : $prefix,
            $lang['typesense_search_prefix_help']
        );
    }

    // The read-only health block arrives with G1.
    $page_def[] = config_add_section_header($lang['typesense_search_health']);
    $page_def[] = config_add_fixed_input(
        $lang['typesense_search_health_status'],
        $lang['typesense_search_health_unavailable']
    );

    return $page_def;
}
