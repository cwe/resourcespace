<?php

/**
 * typesense_search: the client contract every caller uses (A2, Q9.10).
 *
 * Everything transport-independent is here: the endpoint rule (plain http for a loopback host only, Q12.2), the
 * key by operation (the search key for searches, the admin key for everything else, Q12.1), the two timeouts,
 * the GET-or-multi_search choice by query size, the 50-search and import chunking, the per-line import results,
 * the 503 back-off with halved concurrency (P16), the circuit breaker (Q8.6) and the mapping of every exception
 * into a failed result so nothing escapes into a search. client_curl.php is the transport behind
 * typesense_search_client_transport_send_many(): the one seam a later client_library.php would replace.
 *
 * Every call returns a result array: ok (bool), status (the HTTP status, 0 when nothing was received), failure
 * (null, or config, breaker, transport, server, client), error (text, keys redacted), body (the decoded JSON,
 * or the raw text of a JSONL import) and time (ms). A transport failure or a 5xx counts towards the breaker; a
 * 4xx, a configuration refusal and a breaker refusal never do. The breaker's storage is the static behind
 * typesense_search_client_breaker_get() and _set(); A6's state store replaces that storage and nothing else.
 */

// ------------------------------------------------------------------------------------------------- options

/**
 * A string option, empty unless it is a scalar.
 *
 * @param string $name Option name without the typesense_search_ prefix
 *
 * @return string
 */
function typesense_search_client_option_string(string $name): string
{
    $value = typesense_search_option($name);

    return is_scalar($value) ? trim((string)$value) : '';
}

/**
 * An integer option, never below a floor.
 *
 * @param string $name  Option name without the typesense_search_ prefix
 * @param int    $floor The least value returned
 *
 * @return int
 */
function typesense_search_client_option_int(string $name, int $floor): int
{
    $value = typesense_search_option($name);

    return max($floor, is_numeric($value) ? (int)$value : $floor);
}

/**
 * The timeout of a kind of request, in seconds.
 *
 * @param string $kind search (searches, status calls, the health probe) or import (imports, documents,
 *                     collections, aliases)
 *
 * @return float
 */
function typesense_search_client_timeout(string $kind): float
{
    $value = typesense_search_option($kind === 'import' ? 'import_timeout' : 'search_timeout');

    return is_numeric($value) && (float)$value > 0 ? (float)$value : ($kind === 'import' ? 600.0 : 2.0);
}

/**
 * The longest query string a GET search may carry, in encoded bytes: the server answers 400 from 4,000 bytes
 * (verified on 30.2: 3,999 is served, 4,000 refused, whatever the encoding).
 *
 * @return int
 */
function typesense_search_client_get_max_bytes(): int
{
    return 3999;
}

/**
 * The most searches one multi_search request may carry (the server's limit_multi_searches default).
 *
 * @return int
 */
function typesense_search_client_multi_search_max(): int
{
    return 50;
}

/**
 * How many times a chunk is sent before a persistent 503 fails it.
 *
 * @return int
 */
function typesense_search_client_import_attempts(): int
{
    return 5;
}

/**
 * The pause before a chunk that got a 503 is sent again: a random point between the two back-off options (P16).
 *
 * @return int Seconds
 */
function typesense_search_client_backoff_seconds(): int
{
    $min = typesense_search_client_option_int('import_backoff_min', 0);
    $max = max($min, typesense_search_client_option_int('import_backoff_max', 0));

    return random_int($min, $max);
}

/**
 * Import requests in flight: the option at first, halved on every 503 for the rest of the process.
 *
 * @param int|null $set A value to hold from now on
 *
 * @return int
 */
function typesense_search_client_import_concurrency(?int $set = null): int
{
    static $concurrency = null;

    if ($set !== null) {
        $concurrency = max(1, $set);
    } elseif ($concurrency === null) {
        $concurrency = typesense_search_client_option_int('import_concurrency', 1);
    }

    return $concurrency;
}

// ------------------------------------------------------------------------------------------------- endpoint and keys

/**
 * Is a host a loopback address (127.0.0.0/8, ::1) or localhost?
 *
 * @param string $host Host as configured
 *
 * @return bool
 */
function typesense_search_client_host_loopback(string $host): bool
{
    $host = strtolower(trim($host, '[]'));

    return $host === 'localhost' || $host === '::1'
        || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host) === 1;
}

/**
 * Why a server address cannot be used, or an empty string when it can (Q12.2): http for a loopback host only,
 * https for any host, a host name or an IP literal (IPv6 in brackets), a port from 1 to 65535.
 *
 * @param string $protocol http or https
 * @param string $host     Host name or address
 * @param int    $port     Port
 *
 * @return string
 */
function typesense_search_client_endpoint_error(string $protocol, string $host, int $port): string
{
    if ($protocol !== 'http' && $protocol !== 'https') {
        return 'the protocol must be http or https';
    }
    if (
        preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host) !== 1
        && preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $host) !== 1
    ) {
        return 'the host must be a host name or an IP address';
    }
    if ($port < 1 || $port > 65535) {
        return 'the port must be between 1 and 65535';
    }
    if ($protocol === 'http' && !typesense_search_client_host_loopback($host)) {
        return 'plain http is allowed for a loopback address only: use https for ' . $host;
    }

    return '';
}

/**
 * The server's base URL from the options, or why it cannot be built.
 *
 * @return array url (empty when refused), error
 */
function typesense_search_client_base_url(): array
{
    $protocol = strtolower(typesense_search_client_option_string('protocol'));
    $host = typesense_search_client_option_string('host');
    $port = typesense_search_client_option_int('port', 0);
    $error = typesense_search_client_endpoint_error($protocol, $host, $port);

    return array('url' => $error === '' ? $protocol . '://' . $host . ':' . $port : '', 'error' => $error);
}

/**
 * The key an operation sends: the search key for a search, the admin key for anything else (Q12.1).
 *
 * @param string $role search or admin
 *
 * @return string
 */
function typesense_search_client_key(string $role): string
{
    return typesense_search_client_option_string($role === 'search' ? 'search_key' : 'admin_key');
}

/**
 * Text with every key replaced by [redacted]: the two configured keys, any extra secret, and the value of any
 * X-TYPESENSE-API-KEY header line (Q12.7).
 *
 * @param string $text    Text for a log line or an error
 * @param array  $secrets Further strings to redact
 *
 * @return string
 */
function typesense_search_client_redact(string $text, array $secrets = array()): string
{
    $keys = array(typesense_search_client_key('search'), typesense_search_client_key('admin'));
    foreach (array_merge($keys, $secrets) as $key) {
        if (is_string($key) && $key !== '') {
            $text = str_replace($key, '[redacted]', $text);
        }
    }

    return (string)preg_replace('/(x-typesense-api-key\s*[:=]\s*)\S+/i', '$1[redacted]', $text);
}

/**
 * One debug line, keys redacted.
 *
 * @param string $text    The line
 * @param array  $secrets Further strings to redact
 *
 * @return void
 */
function typesense_search_client_log(string $text, array $secrets = array()): void
{
    debug('typesense_search client: ' . typesense_search_client_redact($text, $secrets));
}

// ------------------------------------------------------------------------------------------------- the breaker

/**
 * The static behind the breaker's getter and setter: the storage A6 replaces.
 *
 * @param array|null $set A state to hold from now on
 *
 * @return array
 */
function typesense_search_client_breaker_store(?array $set = null): array
{
    static $state = null;

    if ($set !== null) {
        $state = $set;
    }

    return $state ?? array();
}

/**
 * The breaker's state: failures (transport failures and 5xx in a row), open_until (time; 0 when closed),
 * trips (times opened), last_error, last_failure (time). Read by the setup page (G1) and the flush (C3).
 *
 * @return array
 */
function typesense_search_client_breaker_get(): array
{
    $state = typesense_search_client_breaker_store();

    return array(
        'failures' => (int)($state['failures'] ?? 0),
        'open_until' => (int)($state['open_until'] ?? 0),
        'trips' => (int)($state['trips'] ?? 0),
        'last_error' => (string)($state['last_error'] ?? ''),
        'last_failure' => (int)($state['last_failure'] ?? 0),
    );
}

/**
 * Store the breaker's state. An empty array closes it.
 *
 * @param array $state As typesense_search_client_breaker_get() returns it
 *
 * @return void
 */
function typesense_search_client_breaker_set(array $state): void
{
    typesense_search_client_breaker_store($state);
}

/**
 * closed, open (inside the cooldown: no request is made) or half_open (the cooldown has passed: the next call
 * probes /health).
 *
 * @return string
 */
function typesense_search_client_breaker_state(): string
{
    $open_until = (int)typesense_search_client_breaker_get()['open_until'];
    if ($open_until === 0) {
        return 'closed';
    }

    return time() < $open_until ? 'open' : 'half_open';
}

/**
 * Record a call's outcome (Q8.6): a success closes the breaker; a transport failure or a 5xx counts, and the
 * configured number in a row opens it, or keeps it open, for the cooldown. A 4xx or a refusal changes nothing.
 *
 * @param array $result Result array
 *
 * @return void
 */
function typesense_search_client_breaker_record(array $result): void
{
    $state = typesense_search_client_breaker_get();
    if ($result['failure'] === null) {
        if ($state['failures'] !== 0 || $state['open_until'] !== 0) {
            $state['failures'] = 0;
            $state['open_until'] = 0;
            typesense_search_client_breaker_set($state);
        }
        return;
    }
    if ($result['failure'] !== 'transport' && $result['failure'] !== 'server') {
        return;
    }
    $state['failures']++;
    $state['last_error'] = $result['error'];
    $state['last_failure'] = time();
    if ($state['failures'] >= typesense_search_client_option_int('breaker_failures', 1)) {
        if ($state['open_until'] === 0) {
            $state['trips']++;
        }
        $state['open_until'] = time() + typesense_search_client_option_int('breaker_cooldown', 0);
    }
    typesense_search_client_breaker_set($state);
}

/**
 * May a request be made? Closed: yes. Open: no, at once and without a request. Half-open: one probe of /health
 * decides, closing the breaker or opening it for another cooldown.
 *
 * @return bool
 */
function typesense_search_client_available(): bool
{
    $state = typesense_search_client_breaker_get();
    if ($state['open_until'] === 0) {
        return true;
    }
    if (time() < $state['open_until']) {
        return false;
    }
    $probe = typesense_search_client_health_result();
    if ($probe['ok'] && is_array($probe['body']) && ($probe['body']['ok'] ?? false) === true) {
        $state['failures'] = 0;
        $state['open_until'] = 0;
        typesense_search_client_breaker_set($state);
        return true;
    }
    $state['last_error'] = $probe['error'] !== '' ? $probe['error'] : 'health probe answered not ok';
    $state['last_failure'] = time();
    $state['open_until'] = time() + typesense_search_client_option_int('breaker_cooldown', 0);
    typesense_search_client_breaker_set($state);

    return false;
}

// ------------------------------------------------------------------------------------------------- the call

/**
 * A result that never reached the transport.
 *
 * @param string $failure config, breaker or client
 * @param string $error   Why
 *
 * @return array
 */
function typesense_search_client_failure(string $failure, string $error): array
{
    return array('ok' => false, 'status' => 0, 'failure' => $failure, 'error' => $error, 'body' => null, 'time' => 0);
}

/**
 * Request parameters as the server reads them: booleans as true/false, arrays joined with commas, nulls dropped.
 *
 * @param array $params Parameters by name
 *
 * @return array
 */
function typesense_search_client_params(array $params): array
{
    $out = array();
    foreach ($params as $name => $value) {
        if ($value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value)) {
            $value = implode(',', $value);
        }
        $out[$name] = $value;
    }

    return $out;
}

/**
 * Parameters as a query string (RFC 3986 encoding).
 *
 * @param array $params Parameters by name
 *
 * @return string
 */
function typesense_search_client_query_string(array $params): string
{
    $pairs = array();
    foreach (typesense_search_client_params($params) as $name => $value) {
        $pairs[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
    }

    return implode('&', $pairs);
}

/**
 * The transport seam: client_curl.php today, the official library behind the same two array shapes later.
 *
 * @param array $requests Request arrays
 *
 * @return array Response arrays in the requests' order
 */
function typesense_search_client_transport_send_many(array $requests): array
{
    return typesense_search_client_curl_send_many($requests);
}

/**
 * A transport request from a call spec: the URL, the key in its header, the body encoded, the timeouts.
 *
 * @param string $base_url Scheme, host and port
 * @param array  $spec     Call spec with the defaults applied
 *
 * @return array A request array, or a failed result when the body cannot be encoded
 */
function typesense_search_client_request(string $base_url, array $spec): array
{
    $headers = array('Expect:');
    $key = $spec['key'] ?? ($spec['role'] === 'none' ? '' : typesense_search_client_key($spec['role']));
    if ($key !== '') {
        $headers[] = 'X-TYPESENSE-API-KEY: ' . $key;
    }
    $body = null;
    if (is_string($spec['body'])) {
        $body = $spec['body'];
        $headers[] = 'Content-Type: text/plain';
    } elseif ($spec['body'] !== null) {
        $body = json_encode($spec['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $why = 'the request could not be encoded: ' . json_last_error_msg();
            return typesense_search_client_failure('client', $why);
        }
        $headers[] = 'Content-Type: application/json';
    }
    $query = typesense_search_client_query_string($spec['query']);
    $timeout = typesense_search_client_timeout($spec['timeout']);

    return array(
        'method' => $spec['method'],
        'url' => $base_url . $spec['path'] . ($query === '' ? '' : '?' . $query),
        'headers' => $headers,
        'body' => $body,
        'timeout' => $timeout,
        'connect_timeout' => min($timeout, typesense_search_client_timeout('search')),
    );
}

/**
 * A transport response classified: ok for a 2xx, otherwise a transport (nothing received), server (5xx) or
 * client (4xx) failure with the server's message.
 *
 * @param array $spec     Call spec with the defaults applied
 * @param array $response Response array
 * @param array $secrets  Further strings to redact
 *
 * @return array Result array
 */
function typesense_search_client_result(array $spec, array $response, array $secrets = array()): array
{
    $status = (int)$response['status'];
    $result = array(
        'ok' => false,
        'status' => $status,
        'failure' => null,
        'error' => '',
        'body' => null,
        'time' => (int)$response['time'],
    );
    if ($status === 0) {
        $result['failure'] = 'transport';
        $result['error'] = typesense_search_client_redact((string)$response['error'], $secrets);
        return $result;
    }
    $raw = (string)$response['body'];
    $decoded = $spec['jsonl'] ? null : json_decode($raw, true);
    if ($status >= 200 && $status < 300) {
        if (!$spec['jsonl'] && !is_array($decoded)) {
            $result['failure'] = 'server';
            $result['error'] = 'HTTP ' . $status . ' with a body that is not JSON';
            return $result;
        }
        $result['ok'] = true;
        $result['body'] = $spec['jsonl'] ? $raw : $decoded;
        return $result;
    }
    $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? '') : '';
    if (!is_string($message) || $message === '') {
        $message = substr(preg_replace('/\s+/', ' ', $raw), 0, 200);
    }
    $result['failure'] = $status >= 500 ? 'server' : 'client';
    $result['error'] = typesense_search_client_redact('HTTP ' . $status . ': ' . $message, $secrets);
    $result['body'] = is_array($decoded) ? $decoded : null;

    return $result;
}

/**
 * Requests to the server, together, through the breaker: the endpoint rule, the key by role, the timeouts, the
 * transport with every exception caught, each result classified, recorded and logged.
 *
 * A spec: method, path, query (array), body (an array to send as JSON, a string to send as is, or null), role
 * (search, admin or none), timeout (search or import), jsonl (the response stays raw), key (a one-off key in
 * place of the role's, for provisioning), gate (false skips the breaker check), record (false keeps the
 * outcome off the breaker).
 *
 * @param array $specs Call specs
 *
 * @return array Result arrays in the specs' order
 */
function typesense_search_client_call_many(array $specs): array
{
    $defaults = array(
        'method' => 'GET', 'path' => '/', 'query' => array(), 'body' => null, 'role' => 'admin',
        'timeout' => 'import', 'jsonl' => false, 'key' => null, 'gate' => true, 'record' => true,
    );
    $base = typesense_search_client_base_url();
    $available = null;
    $prepared = array();
    $results = array();
    $requests = array();
    $secrets = array();
    foreach (array_values($specs) as $i => $spec) {
        $spec += $defaults;
        $prepared[$i] = $spec;
        if ($base['error'] !== '') {
            $results[$i] = typesense_search_client_failure('config', 'server address refused: ' . $base['error']);
            continue;
        }
        if ($spec['gate']) {
            $available = $available ?? typesense_search_client_available();
            if (!$available) {
                $results[$i] = typesense_search_client_failure('breaker', 'circuit breaker open: no request made');
                continue;
            }
        }
        $request = typesense_search_client_request($base['url'], $spec);
        if (isset($request['failure'])) {
            $results[$i] = $request;
            continue;
        }
        $requests[$i] = $request;
        if (is_string($spec['key']) && $spec['key'] !== '') {
            $secrets[] = $spec['key'];
        }
    }
    if (count($requests) > 0) {
        $started = microtime(true);
        try {
            $responses = array_values(typesense_search_client_transport_send_many(array_values($requests)));
        } catch (Throwable $e) {
            $elapsed = (int)round((microtime(true) - $started) * 1000);
            $error = get_class($e) . ': ' . $e->getMessage();
            $response = array('status' => 0, 'body' => '', 'error' => $error, 'time' => $elapsed);
            $responses = array_fill(0, count($requests), $response);
        }
        $n = 0;
        foreach (array_keys($requests) as $i) {
            $response = $responses[$n++] ?? array('status' => 0, 'body' => '', 'error' => 'no response', 'time' => 0);
            $results[$i] = typesense_search_client_result($prepared[$i], $response, $secrets);
            if ($prepared[$i]['record']) {
                typesense_search_client_breaker_record($results[$i]);
            }
            $line = $prepared[$i]['method'] . ' ' . $prepared[$i]['path'] . ' ' . $results[$i]['status'] . ' '
                . $results[$i]['time'] . 'ms';
            if (!$results[$i]['ok']) {
                $line .= ' ' . $results[$i]['failure'] . ': ' . $results[$i]['error'];
            }
            typesense_search_client_log($line, $secrets);
        }
    }
    ksort($results);

    return $results;
}

/**
 * One request to the server through the breaker.
 *
 * @param array $spec Call spec, as typesense_search_client_call_many() takes it
 *
 * @return array Result array
 */
function typesense_search_client_call(array $spec): array
{
    return typesense_search_client_call_many(array($spec))[0];
}

// ------------------------------------------------------------------------------------------------- searches

/**
 * One search, by GET while its query string fits the server's cap and through multi_search above it; the
 * result's body is the search result either way, and route says which (get or post).
 *
 * @param string $collection Collection or alias name
 * @param array  $params     Search parameters (q, query_by, filter_by, sort_by, page, per_page, ...)
 *
 * @return array Result array
 */
function typesense_search_client_search(string $collection, array $params): array
{
    if (strlen(typesense_search_client_query_string($params)) <= typesense_search_client_get_max_bytes()) {
        $result = typesense_search_client_call(array(
            'method' => 'GET',
            'path' => '/collections/' . rawurlencode($collection) . '/documents/search',
            'query' => $params,
            'role' => 'search',
            'timeout' => 'search',
        ));
        $result['route'] = 'get';
        return $result;
    }
    $result = typesense_search_client_multi_search(array(array('collection' => $collection) + $params))[0];
    $result['route'] = 'post';

    return $result;
}

/**
 * Several searches, at most 50 per request, each answered on its own: a search that fails inside a 200
 * (a code and an error in its slot) is a failure for that search only.
 *
 * @param array $searches Search parameter arrays, each with its collection
 *
 * @return array Result arrays in the searches' order
 */
function typesense_search_client_multi_search(array $searches): array
{
    $results = array();
    foreach (array_chunk(array_values($searches), typesense_search_client_multi_search_max()) as $chunk) {
        $result = typesense_search_client_call(array(
            'method' => 'POST',
            'path' => '/multi_search',
            'body' => array('searches' => array_map('typesense_search_client_params', $chunk)),
            'role' => 'search',
            'timeout' => 'search',
        ));
        foreach (array_keys($chunk) as $i) {
            $one = $result['ok'] ? ($result['body']['results'][$i] ?? null) : null;
            if (!$result['ok']) {
                $results[] = array('body' => null) + $result;
            } elseif (!is_array($one)) {
                $why = 'no result for search ' . $i;
                $results[] = array('ok' => false, 'failure' => 'server', 'error' => $why, 'body' => null) + $result;
            } elseif (isset($one['code'], $one['error']) && (int)$one['code'] >= 400) {
                $code = (int)$one['code'];
                $results[] = array(
                    'ok' => false,
                    'status' => $code,
                    'failure' => $code >= 500 ? 'server' : 'client',
                    'error' => 'HTTP ' . $code . ': ' . typesense_search_client_redact((string)$one['error']),
                    'body' => $one,
                    'time' => $result['time'],
                );
            } else {
                $results[] = array('body' => $one) + $result;
            }
        }
    }

    return $results;
}

// ------------------------------------------------------------------------------------------------- documents

/**
 * The lines of an import response against the chunk they answer: successes counted, each failed line reported.
 *
 * @param string $body   The response, one JSON object per line
 * @param array  $chunk  The chunk as typesense_search_client_import() built it
 * @param array  $failed Failures so far, appended to
 *
 * @return int Documents imported
 */
function typesense_search_client_import_lines(string $body, array $chunk, array &$failed): int
{
    $lines = explode("\n", trim($body));
    $imported = 0;
    foreach ($chunk['indexes'] as $k => $index) {
        $line = isset($lines[$k]) ? json_decode($lines[$k], true) : null;
        if (is_array($line) && ($line['success'] ?? false) === true) {
            $imported++;
            continue;
        }
        $failed[] = array(
            'index' => $index,
            'id' => $chunk['ids'][$k],
            'code' => is_array($line) ? (int)($line['code'] ?? 0) : 0,
            'error' => is_array($line)
                ? typesense_search_client_redact((string)($line['error'] ?? 'failed'))
                : 'no result line',
        );
    }

    return $imported;
}

/**
 * Import documents as JSONL with dirty_values=reject, in chunks of at most import_max_docs documents and
 * import_max_bytes bytes, import_concurrency requests in flight, every result line read. A 503 pauses for
 * the back-off, halves the concurrency and sends the chunk again (P16); a chunk still refused after the last
 * attempt, or failing any other way, reports every document in it. The breaker is checked once at the start:
 * an import under way is not cut short by its own 503s, but once the server has gone the rest is not sent.
 *
 * @param string $collection Collection name
 * @param array  $documents  Documents, each an array with its id
 * @param string $action     create, upsert, update or emplace
 *
 * @return array Result array plus imported (count), failed (index, id, code, error per document), requests,
 *               retries and concurrency (as left after the halvings)
 */
function typesense_search_client_import(string $collection, array $documents, string $action = 'upsert'): array
{
    $started = microtime(true);
    $max_docs = typesense_search_client_option_int('import_max_docs', 1);
    $max_bytes = typesense_search_client_option_int('import_max_bytes', 1);
    $summary = array(
        'ok' => true, 'status' => 200, 'failure' => null, 'error' => '', 'body' => null, 'time' => 0,
        'imported' => 0, 'failed' => array(), 'requests' => 0, 'retries' => 0,
        'concurrency' => typesense_search_client_import_concurrency(),
    );
    $chunks = array();
    $chunk = null;
    foreach (array_values($documents) as $index => $document) {
        $id = is_array($document) && isset($document['id']) ? (string)$document['id'] : null;
        $line = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            $why = 'json_encode: ' . json_last_error_msg();
            $summary['failed'][] = array('index' => $index, 'id' => $id, 'code' => 0, 'error' => $why);
            continue;
        }
        $bytes = strlen($line) + 1;
        if ($chunk !== null && (count($chunk['lines']) >= $max_docs || $chunk['bytes'] + $bytes > $max_bytes)) {
            $chunks[] = $chunk;
            $chunk = null;
        }
        if ($chunk === null) {
            $chunk = array('lines' => array(), 'ids' => array(), 'indexes' => array(), 'bytes' => 0, 'attempts' => 0);
        }
        $chunk['lines'][] = $line;
        $chunk['ids'][] = $id;
        $chunk['indexes'][] = $index;
        $chunk['bytes'] += $bytes;
    }
    if ($chunk !== null) {
        $chunks[] = $chunk;
    }

    $pending = array_keys($chunks);
    $spec = array(
        'method' => 'POST',
        'path' => '/collections/' . rawurlencode($collection) . '/documents/import',
        'query' => array('action' => $action, 'dirty_values' => 'reject'),
        'role' => 'admin',
        'timeout' => 'import',
        'jsonl' => true,
        'gate' => false,
    );
    if (count($pending) > 0 && !typesense_search_client_available()) {
        $refused = typesense_search_client_failure('breaker', 'circuit breaker open: no request made');
        typesense_search_client_import_fail($summary, $chunks, $pending, $refused);
        $pending = array();
    }
    while (count($pending) > 0) {
        $batch = array_splice($pending, 0, typesense_search_client_import_concurrency());
        $specs = array();
        foreach ($batch as $i) {
            $specs[] = array('body' => implode("\n", $chunks[$i]['lines'])) + $spec;
            $chunks[$i]['attempts']++;
        }
        $results = typesense_search_client_call_many($specs);
        $summary['requests'] += count($specs);
        $retry = array();
        foreach ($batch as $n => $i) {
            $result = $results[$n];
            if ($result['status'] === 503 && $chunks[$i]['attempts'] < typesense_search_client_import_attempts()) {
                $retry[] = $i;
            } elseif ($result['ok']) {
                $body = (string)$result['body'];
                $summary['imported'] += typesense_search_client_import_lines($body, $chunks[$i], $summary['failed']);
            } else {
                typesense_search_client_import_fail($summary, $chunks, array($i), $result);
            }
        }
        if (count($retry) > 0) {
            $summary['retries'] += count($retry);
            typesense_search_client_import_concurrency(intdiv(typesense_search_client_import_concurrency(), 2));
            sleep(typesense_search_client_backoff_seconds());
            $pending = array_merge($retry, $pending);
        } elseif (count($pending) > 0 && typesense_search_client_breaker_state() === 'open') {
            $why = 'circuit breaker opened during the import: the rest was not sent';
            $refused = typesense_search_client_failure('breaker', $why);
            typesense_search_client_import_fail($summary, $chunks, $pending, $refused);
            $pending = array();
        }
    }
    if (count($summary['failed']) > 0) {
        usort($summary['failed'], function (array $a, array $b) {
            return $a['index'] <=> $b['index'];
        });
        $summary['ok'] = false;
        $summary['failure'] = $summary['failure'] ?? 'client';
        if ($summary['error'] === '') {
            $summary['error'] = count($summary['failed']) . ' of ' . count($documents) . ' documents failed';
        }
    }
    $summary['concurrency'] = typesense_search_client_import_concurrency();
    $summary['time'] = (int)round((microtime(true) - $started) * 1000);

    return $summary;
}

/**
 * Report every document of some chunks as failed with a request-level result.
 *
 * @param array $summary The import summary, updated
 * @param array $chunks  All chunks
 * @param array $which   Chunk indexes to fail
 * @param array $result  The result they failed with
 *
 * @return void
 */
function typesense_search_client_import_fail(array &$summary, array $chunks, array $which, array $result): void
{
    foreach ($which as $i) {
        foreach ($chunks[$i]['indexes'] as $k => $index) {
            $summary['failed'][] = array(
                'index' => $index,
                'id' => $chunks[$i]['ids'][$k],
                'code' => $result['status'],
                'error' => $result['error'],
            );
        }
    }
    $summary['status'] = $result['status'];
    $summary['failure'] = $result['failure'];
    $summary['error'] = $result['error'];
}

/**
 * Delete one document.
 *
 * @param string $collection Collection name
 * @param string $id         Document id
 *
 * @return array Result array; a missing id is a 404 client failure
 */
function typesense_search_client_delete(string $collection, string $id): array
{
    return typesense_search_client_call(array(
        'method' => 'DELETE',
        'path' => '/collections/' . rawurlencode($collection) . '/documents/' . rawurlencode($id),
    ));
}

/**
 * Delete every document a filter matches.
 *
 * @param string $collection Collection name
 * @param string $filter_by  Filter expression
 *
 * @return array Result array; the body carries num_deleted
 */
function typesense_search_client_delete_filter(string $collection, string $filter_by): array
{
    return typesense_search_client_call(array(
        'method' => 'DELETE',
        'path' => '/collections/' . rawurlencode($collection) . '/documents',
        'query' => array('filter_by' => $filter_by),
    ));
}

// ------------------------------------------------------------------------------------------------- collections

/**
 * Create a collection from a definition. The rebuild (B8) is the only caller: nothing else in the plugin may
 * create a collection, and nothing here creates one on its own.
 *
 * @param array $definition As the schema module builds it
 *
 * @return array Result array; 201 with the stored definition, 409 when the name exists
 */
function typesense_search_client_collection_create(array $definition): array
{
    return typesense_search_client_call(array('method' => 'POST', 'path' => '/collections', 'body' => $definition));
}

/**
 * A collection's stored definition, with num_documents and its metadata.
 *
 * @param string $name Collection name
 *
 * @return array Result array; 404 client failure when missing
 */
function typesense_search_client_collection_get(string $name): array
{
    return typesense_search_client_call(array('method' => 'GET', 'path' => '/collections/' . rawurlencode($name)));
}

/**
 * Does a collection exist?
 *
 * @param string $name Collection name
 *
 * @return bool|null Null when the server could not be asked
 */
function typesense_search_client_collection_exists(string $name): ?bool
{
    $result = typesense_search_client_collection_get($name);
    if ($result['ok']) {
        return true;
    }

    return $result['status'] === 404 ? false : null;
}

/**
 * Drop a collection and every document in it.
 *
 * @param string $name Collection name
 *
 * @return array Result array
 */
function typesense_search_client_collection_delete(string $name): array
{
    $path = '/collections/' . rawurlencode($name);

    return typesense_search_client_call(array('method' => 'DELETE', 'path' => $path));
}

/**
 * The collection an alias points at.
 *
 * @param string $alias Alias name
 *
 * @return array Result array; the body carries collection_name; 404 client failure when missing
 */
function typesense_search_client_alias_get(string $alias): array
{
    return typesense_search_client_call(array('method' => 'GET', 'path' => '/aliases/' . rawurlencode($alias)));
}

/**
 * Point an alias at a collection, creating or moving it.
 *
 * @param string $alias      Alias name
 * @param string $collection Collection name
 *
 * @return array Result array
 */
function typesense_search_client_alias_upsert(string $alias, string $collection): array
{
    return typesense_search_client_call(array(
        'method' => 'PUT',
        'path' => '/aliases/' . rawurlencode($alias),
        'body' => array('collection_name' => $collection),
    ));
}

/**
 * Remove an alias.
 *
 * @param string $alias Alias name
 *
 * @return array Result array
 */
function typesense_search_client_alias_delete(string $alias): array
{
    return typesense_search_client_call(array('method' => 'DELETE', 'path' => '/aliases/' . rawurlencode($alias)));
}

// ------------------------------------------------------------------------------------------------- the server

/**
 * The /health answer, with no key, outside the breaker and never counted by it: the half-open probe.
 *
 * @return array Result array
 */
function typesense_search_client_health_result(): array
{
    return typesense_search_client_call(array(
        'method' => 'GET',
        'path' => '/health',
        'role' => 'none',
        'timeout' => 'search',
        'gate' => false,
        'record' => false,
    ));
}

/**
 * Is the server up?
 *
 * @return bool
 */
function typesense_search_client_health(): bool
{
    $result = typesense_search_client_health_result();

    return $result['ok'] && is_array($result['body']) && ($result['body']['ok'] ?? false) === true;
}

/**
 * The server's version from /debug (the admin key carries debug:list, Q12.11), read once per process.
 *
 * @param bool $refresh Read it again
 *
 * @return string|null Null when it could not be read
 */
function typesense_search_client_version(bool $refresh = false): ?string
{
    static $version = null;

    if ($version === null || $refresh) {
        $result = typesense_search_client_call(array('method' => 'GET', 'path' => '/debug', 'timeout' => 'search'));
        $version = $result['ok'] && is_string($result['body']['version'] ?? null) ? $result['body']['version'] : null;
    }

    return $version;
}

/**
 * The least server version the plugin runs on (Q1.9).
 *
 * @return string
 */
function typesense_search_client_version_minimum(): string
{
    return '30.0';
}

/**
 * The major versions the batteries have been run on; a later major warns rather than refuses (Q1.9).
 *
 * @return array
 */
function typesense_search_client_version_tested(): array
{
    return array(30);
}

/**
 * A version against the minimum and the tested range.
 *
 * @param string|null $version A version, or null to read the server's
 *
 * @return array version, minimum, tested, state (ok, too_old, untested or unknown) and ok (usable: ok or untested)
 */
function typesense_search_client_version_check(?string $version = null): array
{
    $version = $version ?? typesense_search_client_version();
    $check = array(
        'version' => $version,
        'minimum' => typesense_search_client_version_minimum(),
        'tested' => typesense_search_client_version_tested(),
        'state' => 'unknown',
        'ok' => false,
    );
    if ($version === null || preg_match('/^(\d+)\.\d+/', $version, $parts) !== 1) {
        return $check;
    }
    if (version_compare($version, $check['minimum'], '<')) {
        $check['state'] = 'too_old';
    } else {
        $check['state'] = in_array((int)$parts[1], $check['tested'], true) ? 'ok' : 'untested';
        $check['ok'] = true;
    }

    return $check;
}

/**
 * The server's memory and disk figures from /metrics.json (the admin key carries metrics.json:list).
 *
 * @return array Result array
 */
function typesense_search_client_metrics(): array
{
    return typesense_search_client_call(array('method' => 'GET', 'path' => '/metrics.json', 'timeout' => 'search'));
}
