<?php
// ResourceSpace API client for the live A/B scripts. Credentials come from the environment only:
//   RS_BASE_URL               e.g. https://host/path (no trailing slash needed)
//   RS_USER_TS,   RS_KEY_TS   a user whose group has the typesense_search plugin
//   RS_USER_CORE, RS_KEY_CORE a user with the same permissions whose group does not
//   RS_INSECURE=1             skip TLS verification (a test system with a private certificate)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Call an API function as "ts" or "core". Returns array(HTTP status, decoded body or raw text, milliseconds).
 * The private key signs the request locally and is never sent.
 */
function rs_api(string $who, string $function, array $params = array()): array
{
    $suffix = $who === 'ts' ? 'TS' : 'CORE';
    $base = rtrim((string)getenv('RS_BASE_URL'), '/');
    $user = (string)getenv('RS_USER_' . $suffix);
    $key = (string)getenv('RS_KEY_' . $suffix);
    if ($base === '' || $user === '' || $key === '') {
        fwrite(STDERR, "Set RS_BASE_URL, RS_USER_$suffix and RS_KEY_$suffix in the environment.\n");
        exit(1);
    }

    $query = http_build_query(array('user' => $user, 'function' => $function) + $params);
    $url = $base . '/api/?' . $query . '&sign=' . hash('sha256', $key . $query);

    $curl = curl_init($url);
    curl_setopt_array($curl, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10));
    if (getenv('RS_INSECURE')) {
        curl_setopt_array($curl, array(CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0));
    }
    $started = microtime(true);
    $body = curl_exec($curl);
    $ms = (int)round((microtime(true) - $started) * 1000);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($body === false) {
        return array(0, 'connection failed: ' . curl_error($curl), $ms);
    }
    $decoded = json_decode($body, true);

    return array($status, json_last_error() === JSON_ERROR_NONE ? $decoded : trim(substr($body, 0, 300)), $ms);
}
