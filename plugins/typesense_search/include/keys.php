<?php

/**
 * typesense_search: the two scoped keys and the provisioning action (X1, Q12.1).
 *
 * The search key carries documents:search and the admin key documents:*, collections:*, the three alias actions and
 * the two read-only status actions, each confined to this installation's collections (Q12.8, Q12.11). Provisioning
 * takes the server's bootstrap key once, from the setup page, creates both keys through the client, stores them as
 * plugin options with their ids and revokes the keys they replace; rotation is the same action. The bootstrap key
 * lives in a local variable, travels in the one header the client builds and is redacted from the client's log lines
 * (Q12.7); nothing here writes it anywhere else.
 *
 * Verified on Typesense 30.2: the collection pattern is a regular expression, so the scope is <prefix>_.* (a glob's
 * <prefix>_* would match the prefix followed by underscores only; tenancy.php builds it); an action's verb is the HTTP method's, so
 * PUT /aliases/<name> needs aliases:upsert (aliases:create is accepted at creation and grants nothing); a request
 * outside a key's scope is answered 401.
 */

/**
 * The actions a key is created with (Q12.8, Q12.11): never a list action on a data endpoint, never keys:*.
 *
 * @param string $role search or admin
 *
 * @return array
 */
function typesense_search_keys_actions(string $role): array
{
    if ($role === 'search') {
        return array('documents:search');
    }

    return array(
        'documents:*',
        'collections:*',
        'aliases:upsert',
        'aliases:get',
        'aliases:delete',
        'debug:list',
        'metrics.json:list',
    );
}

/**
 * The collection scope of both keys: the tenancy module's key pattern, this installation's prefix as the server's
 * regular expression.
 *
 * @return string Empty while the prefix is not set
 */
function typesense_search_keys_scope(): string
{
    try {
        return typesense_search_tenancy_key_pattern();
    } catch (InvalidArgumentException $e) {
        return '';
    }
}

/**
 * The description a key is created with, so that an operator can tell one installation's keys apart on the server.
 *
 * @param string $role search or admin
 *
 * @return string
 */
function typesense_search_keys_description(string $role): string
{
    return 'typesense_search ' . typesense_search_tenancy_prefix() . ' ' . $role . ' key';
}

/**
 * Is either key fixed from config.php? Then the host provisions and the page offers no action (Q9.1).
 *
 * @return bool
 */
function typesense_search_keys_locked(): bool
{
    return typesense_search_option_locked('search_key') || typesense_search_option_locked('admin_key');
}

/**
 * One request to the server with the bootstrap key in place of a stored key.
 *
 * @param string     $bootstrap The server's bootstrap key
 * @param string     $method    HTTP method
 * @param string     $path      Endpoint path
 * @param array|null $body      JSON body
 *
 * @return array Result array
 */
function typesense_search_keys_call(string $bootstrap, string $method, string $path, ?array $body = null): array
{
    return typesense_search_client_call(array(
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'key' => $bootstrap,
        'timeout' => 'search',
    ));
}

/**
 * Create one scoped key.
 *
 * @param string $bootstrap The server's bootstrap key
 * @param string $role      search or admin
 *
 * @return array ok, id, value (the key, returned by the server once) and error
 */
function typesense_search_keys_create(string $bootstrap, string $role): array
{
    $result = typesense_search_keys_call($bootstrap, 'POST', '/keys', array(
        'description' => typesense_search_keys_description($role),
        'actions' => typesense_search_keys_actions($role),
        'collections' => array(typesense_search_keys_scope()),
    ));
    $body = is_array($result['body']) ? $result['body'] : array();
    $ok = $result['ok'] && isset($body['id'], $body['value']) && is_string($body['value']) && $body['value'] !== '';

    return array(
        'ok' => $ok,
        'id' => $ok ? (int)$body['id'] : 0,
        'value' => $ok ? $body['value'] : '',
        'error' => $ok ? '' : ($result['error'] !== '' ? $result['error'] : 'the server returned no key'),
    );
}

/**
 * Revoke a key by id.
 *
 * @param string $bootstrap The server's bootstrap key
 * @param int    $id        Key id
 *
 * @return bool Revoked, or already gone
 */
function typesense_search_keys_revoke(string $bootstrap, int $id): bool
{
    $result = typesense_search_keys_call($bootstrap, 'DELETE', '/keys/' . $id);

    return $result['ok'] || $result['status'] === 404;
}

/**
 * Store the two keys and their ids as plugin options and make them the running configuration.
 *
 * @param array $search The search key as typesense_search_keys_create() returned it
 * @param array $admin  The admin key
 *
 * @return void
 */
function typesense_search_keys_store(array $search, array $admin): void
{
    $config = get_plugin_config('typesense_search') ?? array();
    $config['typesense_search_search_key'] = $search['value'];
    $config['typesense_search_search_key_id'] = $search['id'];
    $config['typesense_search_admin_key'] = $admin['value'];
    $config['typesense_search_admin_key_id'] = $admin['id'];
    typesense_search_option_store($config);
}

/**
 * The provisioning action: create a search key and an admin key with the bootstrap key, store them, then revoke the
 * keys they replace. Rotation is the same call. Nothing is stored unless both keys were created.
 *
 * @param string $bootstrap The server's bootstrap key, for this call only
 *
 * @return array ok; error (no_bootstrap, locked, no_prefix or the server's redacted message); created (role => id);
 *               revoked and unrevoked (ids of the previous keys); hand_made (a previous key had no recorded id)
 */
function typesense_search_keys_provision(string $bootstrap): array
{
    $outcome = array(
        'ok' => false, 'error' => '', 'created' => array(), 'revoked' => array(), 'unrevoked' => array(),
        'hand_made' => false,
    );
    $bootstrap = trim($bootstrap);
    if ($bootstrap === '') {
        $outcome['error'] = 'no_bootstrap';
        return $outcome;
    }
    if (typesense_search_keys_locked()) {
        $outcome['error'] = 'locked';
        return $outcome;
    }
    if (typesense_search_keys_scope() === '') {
        $outcome['error'] = 'no_prefix';
        return $outcome;
    }

    $previous = array();
    foreach (array('search_key', 'admin_key') as $name) {
        $id = (int)typesense_search_option($name . '_id');
        if ($id > 0) {
            $previous[] = $id;
        } elseif (typesense_search_option_key_set($name)) {
            $outcome['hand_made'] = true;
        }
    }

    $search = typesense_search_keys_create($bootstrap, 'search');
    if (!$search['ok']) {
        $outcome['error'] = $search['error'];
        return $outcome;
    }
    $admin = typesense_search_keys_create($bootstrap, 'admin');
    if (!$admin['ok']) {
        typesense_search_keys_revoke($bootstrap, $search['id']);
        $outcome['error'] = $admin['error'];
        return $outcome;
    }

    typesense_search_keys_store($search, $admin);
    $outcome['ok'] = true;
    $outcome['created'] = array('search' => $search['id'], 'admin' => $admin['id']);
    foreach (array_unique($previous) as $id) {
        $outcome[typesense_search_keys_revoke($bootstrap, $id) ? 'revoked' : 'unrevoked'][] = $id;
    }
    typesense_search_client_log(
        'keys provisioned: search ' . $search['id'] . ', admin ' . $admin['id']
        . ', revoked [' . implode(',', $outcome['revoked']) . ']'
        . ($outcome['unrevoked'] === array() ? '' : ', not revoked [' . implode(',', $outcome['unrevoked']) . ']')
    );

    return $outcome;
}

/**
 * What the page says about the last action. Key ids only, never a value.
 *
 * @param array $outcome As typesense_search_keys_provision() returned it
 *
 * @return string
 */
function typesense_search_keys_notice(array $outcome): string
{
    global $lang;

    if (!$outcome['ok']) {
        $known = array('no_bootstrap', 'locked', 'no_prefix');
        return in_array($outcome['error'], $known, true)
            ? $lang['typesense_search_keys_' . $outcome['error']]
            : str_replace('[error]', $outcome['error'], $lang['typesense_search_keys_failed']);
    }
    $notice = $lang['typesense_search_keys_ok'];
    if ($outcome['revoked'] !== array()) {
        $notice .= ' ' . $lang['typesense_search_keys_revoked'];
    }
    if ($outcome['unrevoked'] !== array()) {
        $ids = implode(', ', $outcome['unrevoked']);
        $notice .= ' ' . str_replace('[ids]', $ids, $lang['typesense_search_keys_unrevoked']);
    }
    if ($outcome['hand_made']) {
        $notice .= ' ' . $lang['typesense_search_keys_hand_made'];
    }

    return $notice;
}

/**
 * The provisioning rows of the setup page: the last action's notice, then the bootstrap field and the button, or the
 * locked text when config.php fixes the keys. No key value is drawn.
 *
 * @param string $notice What the last action said, if one ran in this request
 *
 * @return array Page definition rows
 */
function typesense_search_keys_page_rows(string $notice = ''): array
{
    global $lang;

    $rows = array(config_add_section_header($lang['typesense_search_keys'], $lang['typesense_search_keys_help']));
    if ($notice !== '') {
        $rows[] = config_add_fixed_input($lang['typesense_search_keys_result'], $notice);
    }
    if (typesense_search_keys_locked()) {
        $rows[] = config_add_fixed_input($lang['typesense_search_bootstrap_key'], $lang['typesense_search_locked_hidden']);
        return $rows;
    }
    $button = typesense_search_option_key_set('search_key') || typesense_search_option_key_set('admin_key')
        ? $lang['typesense_search_keys_rotate']
        : $lang['typesense_search_keys_create'];
    $id = 'typesense_search_bootstrap_key';
    $rows[] = config_add_html(
        '<div class="Question" id="question_' . $id . '">'
        . '<label for="' . $id . '">' . escape($lang[$id]) . '</label>'
        . '<input id="' . $id . '" name="' . $id . '" type="password" value="" autocomplete="off" style="width:420px" />'
        . '<div class="clearerleft"></div>'
        . typesense_search_option_help_html($lang['typesense_search_bootstrap_key_help'])
        . '</div>'
        . '<div class="Question"><input type="submit" name="typesense_search_keys_provision" value="' . escape($button)
        . '" /><div class="clearerleft"></div></div>'
    );

    return $rows;
}
