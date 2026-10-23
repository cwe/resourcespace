<?php

/**
 * typesense_search: the curl transport behind the client's one seam (Q9.10).
 *
 * client.php hands request arrays to typesense_search_client_curl_send_many() (or one to _send()) and gets
 * response arrays back; nothing else in the plugin calls curl, and the official library would replace this
 * file alone. A request: method, url, headers (complete header lines), body (string or null), timeout and
 * connect_timeout (seconds). A response: status (0 when nothing was received), body (raw), error (curl's
 * message, empty on success), time (ms) and connects (new connections made: 0 means the keep-alive connection
 * was reused). Keys travel only in the headers client.php builds; a https host is always verified (Q12.2) and
 * there is no option to switch that off; redirects are never followed.
 */

/**
 * The easy handle of one pool slot, reset for a new request but keeping its live connection. Slot 0 serves
 * every single request; parallel imports take one slot each.
 *
 * @param int $slot Pool slot
 *
 * @return CurlHandle
 */
function typesense_search_client_curl_handle(int $slot): CurlHandle
{
    static $pool = array();

    if (!isset($pool[$slot])) {
        $pool[$slot] = curl_init();
    }
    curl_reset($pool[$slot]);

    return $pool[$slot];
}

/**
 * Put a request on a handle.
 *
 * @param CurlHandle $handle  Pool handle
 * @param array      $request Request array
 *
 * @return void
 */
function typesense_search_client_curl_prepare(CurlHandle $handle, array $request): void
{
    $options = array(
        CURLOPT_URL => $request['url'],
        CURLOPT_CUSTOMREQUEST => $request['method'],
        CURLOPT_HTTPHEADER => $request['headers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT_MS => (int)round($request['connect_timeout'] * 1000),
        CURLOPT_TIMEOUT_MS => (int)round($request['timeout'] * 1000),
    );
    if ($request['body'] !== null) {
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }
    curl_setopt_array($handle, $options);
}

/**
 * The response of a finished handle.
 *
 * @param CurlHandle $handle Pool handle
 * @param mixed      $body   What curl_exec() or curl_multi_getcontent() gave
 *
 * @return array
 */
function typesense_search_client_curl_response(CurlHandle $handle, $body): array
{
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    $received = is_string($body) && $status > 0;
    if (!$received && $error === '') {
        $error = 'no response';
    }

    return array(
        'status' => $received ? $status : 0,
        'body' => $received ? $body : '',
        'error' => $received ? '' : $error,
        'time' => (int)round((float)curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000),
        'connects' => (int)curl_getinfo($handle, CURLINFO_NUM_CONNECTS),
    );
}

/**
 * Send one request on the keep-alive handle.
 *
 * @param array $request Request array
 *
 * @return array Response array
 */
function typesense_search_client_curl_send(array $request): array
{
    $handle = typesense_search_client_curl_handle(0);
    typesense_search_client_curl_prepare($handle, $request);

    return typesense_search_client_curl_response($handle, curl_exec($handle));
}

/**
 * Send several requests at once through curl_multi, one pool slot each.
 *
 * @param array $requests Request arrays
 *
 * @return array Response arrays in the requests' order
 */
function typesense_search_client_curl_send_many(array $requests): array
{
    static $multi = null;

    $requests = array_values($requests);
    if (count($requests) === 1) {
        return array(typesense_search_client_curl_send($requests[0]));
    }
    if ($multi === null) {
        $multi = curl_multi_init();
    }
    $handles = array();
    try {
        foreach ($requests as $slot => $request) {
            $handle = typesense_search_client_curl_handle($slot);
            typesense_search_client_curl_prepare($handle, $request);
            curl_multi_add_handle($multi, $handle);
            $handles[$slot] = $handle;
        }
        do {
            $state = curl_multi_exec($multi, $active);
            if ($active > 0 && curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        } while ($active > 0 && $state === CURLM_OK);
        $responses = array();
        foreach ($handles as $slot => $handle) {
            $responses[$slot] = typesense_search_client_curl_response($handle, curl_multi_getcontent($handle));
        }
    } finally {
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
        }
    }

    return $responses;
}
