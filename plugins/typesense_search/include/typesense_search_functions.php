<?php

require_once __DIR__ . '/typesense_search_query.php';

/**
 * Build the Typesense query_by field list for the current schema (there is no wildcard
 * query_by, so the searchable fields are enumerated from the RS schema).
 *
 * @return array
 */
function typesense_build_query_by(): array
{

    global $view_title_field;

    $visible_fields = get_visible_indexed_fields();

    $fields = get_fields($visible_fields, true);

    $query_by = [];

    foreach ($fields as $field) {
        $ref = (int) $field["ref"];

        $mapping = typesense_search_get_query_field($field);

        if ($ref === (int) $view_title_field) {
            $query_by[] = "title";
        } else {
            $query_by[] = $mapping;
        }
    }

    return $query_by;

}

function typesense_search_get_query_field(array $field): ?string
{
    $ref = (int) $field["ref"];

    switch ((int) $field["type"]) {
        case FIELD_TYPE_TEXT_BOX_SINGLE_LINE:
        case FIELD_TYPE_WARNING_MESSAGE:
            if ($field["field_constraint"] === 1) {
                return "field_{$ref}_q";
            } else {
                return "field_{$ref}_s";
            }
        case FIELD_TYPE_TEXT_BOX_MULTI_LINE:
        case FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE:
        case FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE:
            return "field_{$ref}_text";

        case FIELD_TYPE_DYNAMIC_KEYWORDS_LIST:
        case FIELD_TYPE_CHECK_BOX_LIST:
        case FIELD_TYPE_DROP_DOWN_LIST:
        case FIELD_TYPE_CATEGORY_TREE:
        case FIELD_TYPE_RADIO_BUTTONS:
            return "field_{$ref}_ss";
        case FIELD_TYPE_DATE:
        case FIELD_TYPE_DATE_AND_OPTIONAL_TIME:
        case FIELD_TYPE_EXPIRY_DATE:
        case FIELD_TYPE_DATE_RANGE:
            return "field_{$ref}_q";
    }

    // switch ($resource_attributes[$key]['field_type']) {
    //         case FIELD_TYPE_TEXT_BOX_SINGLE_LINE:
    //         case FIELD_TYPE_WARNING_MESSAGE:
    //             if ($resource_attribute['node_value'] == 1) {
    //                 // numeric type
    //                 $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_f'] = (float) $resource_attribute['node_value'];
    //                 // store string representation for searching
    //                 $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_q'][] = $resource_attribute['node_value'];
    //             } else {
    //                 // string type
    //                 $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_s'] = (string) $resource_attribute['node_value'];
    //             }
    //             break;
            
    //         case FIELD_TYPE_TEXT_BOX_MULTI_LINE:
    //         case FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE:
    //         case FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE:
    //             $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_text'] = (string) $resource_attribute['node_value'];

    //             break;

    //         case FIELD_TYPE_DYNAMIC_KEYWORDS_LIST:
    //         case FIELD_TYPE_CHECK_BOX_LIST:
    //         case FIELD_TYPE_DROP_DOWN_LIST:
    //         case FIELD_TYPE_CATEGORY_TREE:
    //         case FIELD_TYPE_RADIO_BUTTONS:
    //             $resource_array_info[(int) $resource_attribute['resource_ref']]['field_' . (int) $resource_attribute['field_ref'] . '_ss'][] = (string) $resource_attribute['node_value'];
    //             break;

    //         case FIELD_TYPE_DATE:
    //         case FIELD_TYPE_DATE_AND_OPTIONAL_TIME:
    //         case FIELD_TYPE_EXPIRY_DATE:

    //             // date processing
    //             if ($resource_attribute['node_value'] !== '') {
    //                 $parsed = typesense_parse_date(
    //                     $resource_attribute['node_value']
    //                 );

    //                 if ($parsed !== null) {
    //                     $prefix =
    //                         'field_' .
    //                         (int) $resource_attribute['field_ref'];

    //                     // Text representations for normal Typesense q matching
    //                     if (!empty($parsed['representations'])) {
    //                         $resource_attributes[$key][
    //                             $prefix . '_q'
    //                         ] = $parsed['representations'];
    //                     }

    //                     // Only present for a complete date / datetime
    //                     if ($parsed['timestamp'] !== null) {
    //                         $resource_attributes[$key][
    //                             $prefix . '_ts'
    //                         ] = $parsed['timestamp'];
    //                     }

    //                     // Present for any date that maps to an absolute interval
    //                     if ($parsed['range_start'] !== null) {
    //                         $resource_attributes[$key][
    //                             $prefix . '_range_start'
    //                         ] = $parsed['range_start'];
    //                     }

    //                     if ($parsed['range_end'] !== null) {
    //                         $resource_attributes[$key][
    //                             $prefix . '_range_end'
    //                         ] = $parsed['range_end'];
    //                     }
    //                 }
    //             }
    //             break;



    //             // if (strlen($resource_attribute['node_value']) > 0) {
    //             //     $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_ts'] = (int) strtotime($resource_attribute['node_value']);
    //             // }
    //             // break;

    //         case FIELD_TYPE_DATE_RANGE:
    //             // if (strlen($resource_attribute['node_value']) > 0) {
    //             //     $resource_array_info[(int) $resource_attribute['resource_ref']]['field_' . (int) $resource_attribute['field_ref'] . '_tss'][] = (int) strtotime($resource_attribute['node_value']);
    //             // }
    //             // break;
    //                 if ($resource_attribute['node_value'] !== '') {
    //                     $parsed = typesense_parse_date(
    //                         $resource_attribute['node_value']
    //                     );

    //                     if ($parsed !== null) {
    //                         $resource_ref =
    //                             (int) $resource_attribute['resource_ref'];

    //                         $field_ref =
    //                             (int) $resource_attribute['field_ref'];

    //                         $date_range_info[
    //                             $resource_ref
    //                         ][
    //                             $field_ref
    //                         ][] = $parsed;
    //                     }
    //                 }
    //                 break;
            
    //         default:
    //             // string default
    //             $resource_attributes[$key]['field_' . (int) $resource_attribute['field_ref'] . '_s'] = (string) $resource_attribute['value_s'];
    //             break;
    //     }

    return null;
}


/**
 * Hydrate Typesense resource refs into the standard ResourceSpace search result structure.
 *
 * @param array $refs Ordered resource refs from Typesense.
 * @param int $total Total number of matches reported by Typesense.
 * @param mixed $fetchrows Result limit or chunk details.
 * @param bool $return_refs_only Whether only resource refs should be returned.
 * @param PreparedStatementQuery $select Existing ResourceSpace SELECT fields.
 * @param string $order_by The order by SQL from the standard ResourceSpace search construction.
 *
 * @return array ResourceSpace-compatible search results.
 */
function typesense_search_hydrate_refs(
    array $refs,
    int $total,
    $fetchrows,
    bool $return_refs_only,
    PreparedStatementQuery $select,
    string $order_by
): array {
    if (count($refs) === 0) {
        return is_array($fetchrows)
            ? array('total' => 0, 'data' => array())
            : array();
    }

    setup_search_chunks($fetchrows, $chunk_offset, $search_chunk_size);

    $ref_placeholders = ps_param_insert(count($refs));
    $field_placeholders = ps_param_insert(count($refs));

    $ref_params = array();
    $field_params = array();

    foreach ($refs as $ref) {
        $ref_params[] = 'i';
        $ref_params[] = (int)$ref;

        $field_params[] = 'i';
        $field_params[] = (int)$ref;
    }

    $query = new PreparedStatementQuery();

    if ($return_refs_only) {
        $query->sql =
            'SELECT r.ref'
            . ' FROM resource r'
            . ' WHERE r.ref IN (' . $ref_placeholders . ')'
            . ' AND r.ref > 0';

        $query->parameters = $ref_params;
    } else {
        // For non-'v' users, $select (built by do_search) references the rca/rca2 custom-access
        // columns (group_access/user_access/resultant_access). Reproduce the resource_custom_access
        // joins from do_search so those columns resolve; otherwise MySQL errors on unknown columns.
        // Placeholder order in the SQL: SELECT (select params) -> JOIN (rca params) -> WHERE (refs).
        $custom_access_join = '';
        $custom_access_params = array();
        if (strpos($select->sql, 'rca.') !== false || strpos($select->sql, 'rca2.') !== false) {
            global $userref, $usergroup;
            $custom_access_join =
                ' LEFT OUTER JOIN resource_custom_access rca2'
                . ' ON r.ref = rca2.resource AND rca2.user = ?'
                . ' AND (rca2.user_expires IS NULL OR rca2.user_expires > now()) AND rca2.access <> 2'
                . ' LEFT OUTER JOIN resource_custom_access rca'
                . ' ON r.ref = rca.resource AND rca.usergroup = ? AND rca.access <> 2';
            $custom_access_params = array('i', (int)$userref, 'i', (int)$usergroup);
        }

        $query->sql =
            'SELECT r.hit_count score, ' . $select->sql
            . ' FROM resource r'
            . ' JOIN resource_type AS rty ON r.resource_type = rty.ref'
            . $custom_access_join
            . ' WHERE r.ref IN (' . $ref_placeholders . ')'
            . ' AND r.ref > 0';

        $query->parameters = array_merge(
            $select->parameters,
            $custom_access_params,
            $ref_params
        );
    }

    $query->sql .=
        ' GROUP BY r.ref'
        . ' ORDER BY FIELD(r.ref, ' . $field_placeholders . ')';

    $query->parameters = array_merge($query->parameters, $field_params);

    debug('typesense_search_hydrate_refs(): candidate refs=' . count($refs));
    debug('typesense_search_hydrate_refs(): sql=' . $query->sql);
    debug('typesense_search_hydrate_refs(): params=' . print_r($query->parameters, true));

    $rows = ps_query($query->sql, $query->parameters);
    $paged_rows = $rows;

    if ($return_refs_only) {
        $paged_rows = array_map(
            function ($row) {
                return array('ref' => (int)$row['ref']);
            },
            $paged_rows
        );
    }

    if (is_array($fetchrows)) {
        return array('total' => $total, 'data' => $paged_rows);
    }
    else  {
        return $paged_rows;
    }
}


/**
 * Send a request to the Typesense API.
 *
 * @param string $method HTTP method.
 * @param string $endpoint API endpoint beginning with a slash.
 * @param array|null $payload Optional request payload.
 *
 * @return array|false Decoded JSON response, or false on failure.
 */
function typesense_search_request(string $method, string $endpoint, $batch = false, ?array $payload = null)
{
    global $typesense_search_host, $typesense_search_port;
    global $typesense_search_protocol, $typesense_search_api_key;
    global $typesense_search_timeout;

    $url =
        $typesense_search_protocol
        . '://'
        . $typesense_search_host
        . ':'
        . $typesense_search_port
        . $endpoint;

    // Reuse a single cURL handle across calls so the connection is kept alive - a reindex makes
    // thousands of requests and would otherwise open a new connection each time.
    static $curl = null;
    if (!($curl instanceof CurlHandle) && $curl === null) {
        $curl = curl_init();
        if ($curl === false) {
            debug('typesense_search_request(): Failed to initialise cURL');
            return false;
        }
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TCP_KEEPALIVE, 1);
    }

    $headers = array(
        'Content-Type: application/json',
        'X-TYPESENSE-API-KEY: ' . $typesense_search_api_key,
    );

    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $typesense_search_timeout);
    curl_setopt($curl, CURLOPT_TIMEOUT, $typesense_search_timeout);

    // Always set the body (empty when no payload) so a stale body from a previous reused request
    // is never resent.
    $body = '';
    if ($payload !== null) {
        if ($batch) {
            $lines = array();
            foreach ($payload as $row) {
                $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $body = implode("\n", $lines) . "\n";
        } else {
            $body = json_encode($payload);
        }
    }
    curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($curl);

    if ($response === false) {
        debug('typesense_search_request(): cURL error: ' . curl_error($curl));
        file_put_contents(get_temp_dir() . '/jsonl.txt', 'typesense_search_request(): cURL error: ' . curl_error($curl) . PHP_EOL, FILE_APPEND);
        return false;
    }

    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    debug(
        'typesense_search_request(): '
        . $method
        . ' '
        . $endpoint
        . ' returned HTTP '
        . $status
    );

    if ($status < 200 || $status >= 300) {
        debug('typesense_search_request(): Response body: ' . $response);
        // file_put_contents(get_temp_dir() . '/jsonl.txt', $response, FILE_APPEND);
        //echo $response;
        return false;
    }

    // Process response
    if ($batch) {
        // Response in JSONL format



        if (str_contains($response, '{"success":false}')) {
            // file_put_contents(get_temp_dir() . '/jsonl.txt', $response, FILE_APPEND);
            return false;
        } else {
            return array('success' => true);
        }

        // $response_lines = explode("\n", $response);

        // if (!is_array($response_lines)) {
        //     debug('typesense_search_request(): Failed to decode JSON response');
        //     return false;
        // }

        // foreach ($response_lines as $response_line) {
        //     $decoded[] = json_decode($response_line, true);
        // }

    } else {
        $decoded = json_decode($response, true);
    }

    if (!is_array($decoded)) {
        debug('typesense_search_request(): Failed to decode JSON response');
        return false;
    }

    return $decoded;
}


/**
 * Ensure that the Typesense resource collections exists.
 *
 * @return bool true if all the collections exist or were created
 */
function typesense_search_ensure_collection(): bool
{
    global $typesense_search_collection_prefix;
    $created_resource_collection = false;
    $created_rcm_collection = false;

    $existing_resource_collection = typesense_search_request('GET', '/collections/' . rawurlencode($typesense_search_collection_prefix . 'resources'));

    if ($existing_resource_collection === false) {

        $schema = array(
            'name' => $typesense_search_collection_prefix . 'resources',
            'fields' => array(
                array('name' => 'ref', 'type' => 'int32', 'sort' => true),
                array('name' => 'ref_s', 'type' => 'string'),
                array('name' => 'title', 'type' => 'string', 'stem' => true),
                array('name' => 'resource_type', 'type' => 'int32', 'facet' => true, 'sort' => true),
                array('name' => 'archive', 'type' => 'int32', 'facet' => true),
                array('name' => 'created_by', 'type' => 'int32', 'facet' => true),
                array('name' => 'access', 'type' => 'int32', 'facet' => true),
                array('name' => 'created_date', 'type' => 'int64', 'sort' => true, 'optional' => true, 'range_index' => true ),
                array('name' => 'modified_date', 'type' => 'int64', 'sort' => true, 'optional' => true, 'range_index' => true ),
                
                array('name' => 'nodes', 'type' => 'int32[]', 'facet' => true),
                array('name' => 'populated_field_ids', 'type' => 'int32[]', 'optional' => true),

                array('name' => 'field_.*_s', 'type' => 'string', 'optional' => true, 'facet' => true),
                array('name' => 'field_.*_ss', 'type' => 'string[]', 'optional' => true, 'facet' => true),

                // array('name' => 'field_.*_i', 'type' => 'int64', 'optional' => true, 'range_index' => true, 'sort' => true),
                // array('name' => 'field_.*_is', 'type' => 'int64[]', 'optional' => true),

                array('name' => 'field_.*_f', 'type' => 'float', 'optional' => true, 'range_index' => true, 'sort' => true),
                // array('name' => 'field_.*_b', 'type' => 'bool', 'optional' => true, 'facet' => true),
                array(
                    'name' => 'field_.*_ts',
                    'type' => 'int64',
                    'optional' => true,
                    'range_index' => true,
                    'sort' => true
                ),

                array(
                    'name' => 'field_.*_range_start',
                    'type' => 'int64',
                    'optional' => true,
                    'range_index' => true,
                    'sort' => true
                ),

                array(
                    'name' => 'field_.*_range_end',
                    'type' => 'int64',
                    'optional' => true,
                    'range_index' => true
                ),
                
                // Text representation of non-string fields for Typesense query matching
                array('name' => 'field_.*_q', 'type' => 'string[]', 'optional' => true),

                array('name' => 'field_.*_text', 'type' => 'string', 'optional' => true, 'stem' => true),

                ),
            'default_sorting_field' => 'ref',
        );

        $created_resource_collection = typesense_search_request('POST', '/collections', false, $schema);
        if (!$created_resource_collection) {
            debug('typesense_search_ensure_collection(): resource collection creation FAILED');
            return false;
        }

    }

    $existing_rcm_collection = typesense_search_request('GET', '/collections/' . rawurlencode($typesense_search_collection_prefix . 'resource_collection_memberships'));

    if ($existing_rcm_collection === false) {

        $schema_rcm = array(
            'name' => $typesense_search_collection_prefix . 'resource_collection_memberships',
            'fields' => array(
                array('name' => 'resource_id', 'type' => 'string', 'reference' => $typesense_search_collection_prefix . 'resources.id'),

                array('name' => 'collection_ref', 'type' => 'int64'),
                array('name' => 'sortorder', 'type' => 'int32'),
                

                array('name' => 'date_added', 'type' => 'int64', 'sort' => true),

                array('name' => 'collection_type', 'type' => 'int32'),

            ),
        );

        $created_rcm_collection = typesense_search_request('POST', '/collections', false, $schema_rcm);
        if (!$created_rcm_collection) {
            debug('typesense_search_ensure_collection(): resource_collection_memberships collection creation FAILED');
            return false;
        }
    }

    // $existing_ra_collection = typesense_search_request('GET', '/collections/' . rawurlencode($typesense_search_collection_prefix . 'resource_attributes'));

    // if ($existing_ra_collection === false) {

    //     $schema_ra = array(
    //         'name' => $typesense_search_collection_prefix . 'resource_attributes',
    //         'fields' => array(
    //             // array('name' => 'resource_id', 'type' => 'string', 'reference' => $typesense_search_collection_prefix . 'resources.id'),
    //             array('name' => 'resource_ref', 'type' => 'int64', 'sort' => true),
    //             array('name' => 'field_ref', 'type' => 'int32', 'facet' => true),
    //             array('name' => 'node_ref', 'type' => 'int64', 'optional' => true, 'facet' => true),
    //             array('name' => 'node_parent', 'type' => 'int64', 'optional' => true, 'facet' => true),

    //             array('name' => 'field_type', 'type' => 'int32', 'facet' => true),

    //             // array('name' => 'value_type', 'type' => 'string', 'facet' => true),

    //             // array('name' => 'is_node', 'type' => 'bool', 'facet' => true),

                
    //             array('name' => 'value_s', 'type' => 'string', 'optional' => true, 'facet' => true),
    //             array('name' => 'value_text', 'type' => 'string', 'optional' => true, 'stem' => true),

    //             array('name' => 'value_i', 'type' => 'int64', 'sort' => true, 'optional' => true, 'range_index' => true),
    //             array('name' => 'value_f', 'type' => 'float', 'sort' => true, 'optional' => true, 'range_index' => true),
    //             array('name' => 'value_b', 'type' => 'bool', 'optional' => true, 'facet' => true),

    //             array('name' => 'value_ts', 'type' => 'int64', 'sort' => true, 'optional' => true, 'range_index' => true),

                
    //             // array('name' => 'updated_at_ts', 'type' => 'int64', 'sort' => true),
    //             array('name' => 'indexed_at_ts', 'type' => 'int64', 'sort' => true),
    //         ),
    //         'default_sorting_field' => 'indexed_at_ts',
    //     );

    //     $created_ra_collection = typesense_search_request('POST', '/collections', false, $schema_ra);
    //     if (!$created_ra_collection) {
    //         debug('typesense_search_ensure_collection(): resource_attributes collection creation FAILED');
    //         return false;
    //     }

    // }

    // Grants collection - one doc per non-confidential resource_custom_access row, joined at
    // query time by the access restriction for confidential/custom resources.
    $existing_grants_collection = typesense_search_request('GET', '/collections/' . rawurlencode($typesense_search_collection_prefix . 'resource_access_grants'));

    if ($existing_grants_collection === false) {
        $schema_grants = array(
            'name' => $typesense_search_collection_prefix . 'resource_access_grants',
            'fields' => array(
                array('name' => 'resource_id', 'type' => 'string', 'reference' => $typesense_search_collection_prefix . 'resources.id'),
                array('name' => 'user', 'type' => 'int32', 'facet' => true),
                array('name' => 'usergroup', 'type' => 'int32', 'facet' => true),
                array('name' => 'access', 'type' => 'int32'),
                array('name' => 'expires', 'type' => 'int64', 'range_index' => true),
            ),
        );

        $created_grants_collection = typesense_search_request('POST', '/collections', false, $schema_grants);
        if (!$created_grants_collection) {
            debug('typesense_search_ensure_collection(): resource_access_grants collection creation FAILED');
            return false;
        }
    }

    return (bool) ($existing_resource_collection || (bool) $created_resource_collection)
        && ($existing_rcm_collection || (bool) $created_rcm_collection)
        && ($existing_grants_collection || (bool) ($created_grants_collection ?? false));
}


/**
 * Build a Typesense document for a ResourceSpace resource.
 *
 * @param int $resource Resource ID.
 *
 * @return array|false Typesense document data, or false if the resource cannot be indexed.
 */
function typesense_search_get_document_data(int $resource)
{
    global $date_field;

    $resource_data = get_resource_data($resource);

    if ($resource_data === false || !is_array($resource_data)) {
        return false;
    }

    $fields = get_resource_type_fields($resource_data['resource_type']);

    if (!is_array($fields)) {
        return false;
    }

    $indexed_values = array($resource); // Always index the resource ID itself.
    $title = '';

    foreach ($fields as $field) {
        if (($field['keywords_index'] ?? $field['index'] ?? 0) != 1) {
            continue;
        }

        

        $value = trim((string) get_data_by_field($resource, (int) $field['ref']));

        if ($value === '') {
            continue;
        }

        $indexed_values[] = $value;

        if ((int) $field['ref'] === (int) $GLOBALS['view_title_field']) {
            $title = $value;
        }
    }

    // Fetch CLIP vetor if we have one.
    /*
    $image_embedding=null;
    $image_embedding_blob=ps_value("SELECT vector_blob value FROM resource_clip_vector WHERE ref=? LIMIT 1",["i",$resource],"");
    if (strlen($image_embedding_blob)>0) {
        $image_embedding=unpack('g*', $image_embedding_blob);
    }
    */

    // Created date
    $date = null;
    $date_value = $resource_data['field' . $date_field];
    if (strlen($date_value) > 0) {
        $date = strtotime($date_value);
    }

    // Modified date
    $modified = null;
    $modified_value = $resource_data['modified'];
    if (strlen($modified_value) > 0) {
        $modified = strtotime($modified_value);
    }

    return array(
        'id' => (string) $resource,
        'ref' => (int) $resource,
        'title' => $title,
        'resource_type' => (int) $resource_data['resource_type'],
        'archive' => (int) $resource_data['archive'],
        'created_by' => (int) $resource_data['created_by'],
        'created_date' => $date,
        'modified_date' => $modified,
        'attributes' => $indexed_values
    );
}


/**
 * Index a single ResourceSpace resource in Typesense.
 *
 * @param int $resource Resource ID.
 *
 * @return bool True if the resource was indexed successfully.
 */
function typesense_search_index_resource(int $resource): bool
{
    global $typesense_search_collection;

    if (!typesense_search_ensure_collection()) {
        return false;
    }

    $document = typesense_search_get_document_data($resource);

    if ($document === false) {
        return false;
    }

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection)
        . '/documents?action=upsert';

    return typesense_search_request('POST', $endpoint, $document) !== false;
}


/**
 * Delete a resource document from Typesense.
 *
 * @param int $resource Resource ID.
 *
 * @return bool True if the delete request succeeded.
 */
function typesense_search_delete_resource(int $resource): bool
{
    global $typesense_search_collection;

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection)
        . '/documents/'
        . rawurlencode((string)$resource);

    return typesense_search_request('DELETE', $endpoint) !== false;
}


/**
 * Reindex all resources that are currently linked to a node.
 *
 * @param int $node Node ID.
 *
 * @return int Number of resources successfully reindexed.
 */
function typesense_search_reindex_node_resources(int $node): int
{
    $resources = ps_array(
        'SELECT DISTINCT resource value FROM resource_node WHERE node = ?',
        array('i', $node)
    );

    $indexed = 0;

    foreach ($resources as $resource) {
        if (typesense_search_index_resource((int)$resource)) {
            $indexed++;
        }
    }

    return $indexed;
}


/**
 * Reindex resources in batches.
 *
 * @param int $limit Maximum number of resources to index in this batch.
 * @param int $after Only index resources with refs greater than this value.
 *
 * @return array Batch indexing summary.
 */
function typesense_search_reindex_resources(int $limit = 100, int $after = 0): array
{

    global $date_field, $view_title_field;

    $indexed = 0;
    $failed = 0;
    $last = $after;

    $resources = ps_query(
        "SELECT 
            r.ref,
            COALESCE(TRIM(t.name), '') AS title,
            r.resource_type,
            r.archive,
            r.created_by,
            r.access,
            r.field" . (int) $date_field . " AS created_date,
            r.modified AS modified_date
            FROM resource r
            LEFT JOIN (
                        SELECT 
                            rn.resource,
                            n.name
                        FROM resource_node rn
                        INNER JOIN node n
                            ON rn.node = n.ref
                        AND n.resource_type_field = " . (int) $view_title_field . "
                    ) t
                        ON r.ref = t.resource
            WHERE
            r.ref > ?
            ORDER BY r.ref ASC
            LIMIT ?;",
        array('i', $after, 'i', $limit)
    );

    $resource_count = count($resources);

    foreach ($resources as $key => $resource) {

        $last = (int) $resource['ref'];

        // Set document ID as string of resource reference
        $resources[$key]['id'] = (string) $resource['ref'];
        $resources[$key]['ref_s'] = (string) $resource['ref'];
        
        $resources[$key]['nodes'] = [0];

        // Populate the title field

        $resources[$key]['title'] = (string) $resource['title'];

        // $resources[$key]['title'] = trim((string) get_data_by_field($resource['ref'], (int) $GLOBALS['view_title_field']));

        // Process dates to integers

        if (strlen($resource['created_date']) > 0) {
           $resources[$key]['created_date'] = (int) strtotime($resource['created_date']);
        }

        if (strlen($resource['modified_date']) > 0) {
           $resources[$key]['modified_date'] = (int) strtotime($resource['modified_date']);
        }
    }

    // $documents = [];

    // foreach ($resources as $resource) {
    //     $last = (int)$resource;

    //     $document = typesense_search_get_document_data($last);

    //     if ($document === false) {
    //         $failed++;
    //         continue;
    //     } else {
    //         $documents[] = $document;
    //     }

    //     $content_length += strlen($document['title'] ?? '');
    //     $content_length += strlen($document['text'] ?? '');


    // }

    if (typesense_search_index_document_batch($resources)) {
        $indexed += $resource_count;
    } else {
        $failed += $resource_count;
    }

    return array(
        'indexed' => $indexed,
        'failed' => $failed,
        'last' => $last,
        'content_length' => 0,
        'complete' => $resource_count < $limit,
    );
}

/**
 * Reindex collection memberships in batches. A collection can hold more resources than one batch,
 * so pagination uses a keyset cursor on the composite (collection, resource) key - paging by
 * collection alone would skip the rest of a collection that straddles a batch boundary.
 *
 * @param int $limit           Batch size.
 * @param int $after_collection Cursor: last collection processed.
 * @param int $after_resource   Cursor: last resource processed within $after_collection.
 *
 * @return array Batch summary incl. last_collection / last_resource for the next call.
 */
function typesense_search_reindex_resource_collection_memberships(int $limit = 100, int $after_collection = 0, int $after_resource = 0): array
{
    $rcms = ps_query(
        "SELECT CONCAT_WS(':', cr.collection, cr.resource) AS id,
            cr.resource as resource_id,
            cr.collection as collection_ref,
            cr.sortorder as sortorder,
            UNIX_TIMESTAMP(cr.date_added) as date_added,
            c.type as collection_type
            FROM collection_resource cr
            INNER JOIN collection c ON cr.collection = c.ref
            WHERE cr.collection > ? OR (cr.collection = ? AND cr.resource > ?)
            ORDER BY cr.collection ASC, cr.resource ASC
            LIMIT ?;",
        array('i', $after_collection, 'i', $after_collection, 'i', $after_resource, 'i', $limit)
    );

    $rcm_count = count($rcms);

    if ($rcm_count == 0) {
        return array(
            'indexed' => 0,
            'failed' => 0,
            'last_collection' => $after_collection,
            'last_resource' => $after_resource,
            'content_length' => 0,
            'complete' => true,
        );
    }

    $last_collection = $after_collection;
    $last_resource = $after_resource;

    foreach ($rcms as $key => $rcm) {
        $last_collection = (int) $rcm['collection_ref'];
        $last_resource = (int) $rcm['resource_id'];
        $rcms[$key]['resource_id'] = (string) $rcm['resource_id'];
    }

    $ok = typesense_search_index_rcms_batch($rcms);

    return array(
        'indexed' => $ok ? $rcm_count : 0,
        'failed' => $ok ? 0 : $rcm_count,
        'last_collection' => $last_collection,
        'last_resource' => $last_resource,
        'content_length' => 0,
        'complete' => $rcm_count < $limit,
    );
}

function typesense_search_reindex_resource_attributes(int $limit = 100, int $after = 0): array
{
    $indexed = 0;
    $failed = 0;
    $last = $after;

    // Get list of resources to process attributes for

    $resource_list = ps_array("SELECT 
            r.ref value
            FROM resource r
            WHERE
            r.ref > ?
            ORDER BY r.ref ASC
            LIMIT ?;",
        array('i', $after, 'i', $limit));

    if (empty($resource_list)) {
        return array(
            'indexed' => 0,
            'failed' => 0,
            'last' => $last,
            'content_length' => 0,
            'complete' => true,
        );
    }

    $param_array = ps_param_fill($resource_list, 'i');

    $resource_attributes = ps_query(
        "SELECT
            '' AS id,
            rn.resource AS resource_ref,
            rtf.ref AS field_ref,
            rtf.type AS field_type,
            rtf.field_constraint AS field_constraint,
            TRIM(n.name) AS node_value,
            n.ref as node_ref
            FROM resource_type_field rtf
            INNER JOIN node n ON n.resource_type_field  = rtf.ref
            INNER JOIN resource_node rn ON rn.node = n.ref
            WHERE (rtf.keywords_index = 1 OR rtf.partial_index = 1 OR rtf.complete_index = 1)
            AND rn.resource IN (" . ps_param_insert(count($resource_list)) . ")
            ORDER BY rn.resource ASC, rtf.ref ASC, n.ref, n.name ASC;",
        $param_array
    );

    // Paginate by resource ref (the resource_list is ordered ASC), so the cursor always advances
    // past every resource in the batch - not just the last one that happened to have attributes.
    $resource_list_count = count($resource_list);
    $last = (int) $resource_list[$resource_list_count - 1];

    $resource_array_info = array();
    $date_range_info = array();

    // nodes[] and populated_field_ids[] are built from ALL of the resource's nodes - every field
    // type, whether or not the field is keyword-indexed - so node_bucket filters and !hasdata work
    // for fields that aren't keyword-indexed. (field_*_* below drive keyword matching and so stay
    // limited to indexed fields.)
    $all_nodes = ps_query(
        "SELECT rn.resource AS resource_ref, rn.node AS node_ref, n.resource_type_field AS field_ref
            FROM resource_node rn
            INNER JOIN node n ON n.ref = rn.node
            WHERE rn.resource IN (" . ps_param_insert(count($resource_list)) . ")",
        $param_array
    );

    foreach ($all_nodes as $all_node) {
        $rref = (int) $all_node['resource_ref'];
        $resource_array_info[$rref]['nodes'][] = (int) $all_node['node_ref'];
        $resource_array_info[$rref]['populated_field_ids'][] = (int) $all_node['field_ref'];
    }

    // Merge every indexed attribute row for a resource into a single per-resource entry, so the
    // import sends one document per resource instead of one per field value.
    foreach ($resource_attributes as $resource_attribute) {
        $rref = (int) $resource_attribute['resource_ref'];
        $fref = (int) $resource_attribute['field_ref'];
        $prefix = 'field_' . $fref;
        $value = $resource_attribute['node_value'];

        switch ((int) $resource_attribute['field_type']) {
            case FIELD_TYPE_TEXT_BOX_SINGLE_LINE:
            case FIELD_TYPE_WARNING_MESSAGE:
                if ($value == 1) {
                    // numeric type
                    $resource_array_info[$rref][$prefix . '_f'] = (float) $value;
                    $resource_array_info[$rref][$prefix . '_q'][] = (string) $value;
                } else {
                    // string type
                    $resource_array_info[$rref][$prefix . '_s'] = (string) $value;
                }
                break;

            case FIELD_TYPE_TEXT_BOX_MULTI_LINE:
            case FIELD_TYPE_TEXT_BOX_LARGE_MULTI_LINE:
            case FIELD_TYPE_TEXT_BOX_FORMATTED_AND_TINYMCE:
                $resource_array_info[$rref][$prefix . '_text'] = (string) $value;
                break;

            case FIELD_TYPE_DYNAMIC_KEYWORDS_LIST:
            case FIELD_TYPE_CHECK_BOX_LIST:
            case FIELD_TYPE_DROP_DOWN_LIST:
            case FIELD_TYPE_CATEGORY_TREE:
            case FIELD_TYPE_RADIO_BUTTONS:
                $resource_array_info[$rref][$prefix . '_ss'][] = (string) $value;
                break;

            case FIELD_TYPE_DATE:
            case FIELD_TYPE_DATE_AND_OPTIONAL_TIME:
            case FIELD_TYPE_EXPIRY_DATE:
                if ($value !== '') {
                    $parsed = typesense_parse_date($value);
                    if ($parsed !== null) {
                        if (!empty($parsed['representations'])) {
                            $resource_array_info[$rref][$prefix . '_q'] = $parsed['representations'];
                        }
                        if ($parsed['timestamp'] !== null) {
                            $resource_array_info[$rref][$prefix . '_ts'] = $parsed['timestamp'];
                        }
                        if ($parsed['range_start'] !== null) {
                            $resource_array_info[$rref][$prefix . '_range_start'] = $parsed['range_start'];
                        }
                        if ($parsed['range_end'] !== null) {
                            $resource_array_info[$rref][$prefix . '_range_end'] = $parsed['range_end'];
                        }
                    }
                }
                break;

            case FIELD_TYPE_DATE_RANGE:
                if ($value !== '') {
                    $parsed = typesense_parse_date($value);
                    if ($parsed !== null) {
                        $date_range_info[$rref][$fref][] = $parsed;
                    }
                }
                break;

            default:
                // string default
                $resource_array_info[$rref][$prefix . '_s'] = (string) $value;
                break;
        }
    }

    foreach ($date_range_info as $resource_ref => $fields) {
        foreach ($fields as $field_ref => $dates) {
            $prefix = 'field_' . $field_ref;

            /*
            * Combine the searchable text representations from
            * both ResourceSpace nodes.
            */
            $representations = array();

            foreach ($dates as $date) {
                $representations = array_merge(
                    $representations,
                    $date['representations']
                );
            }

            $representations = array_values(
                array_unique($representations)
            );

            if (!empty($representations)) {
                $resource_array_info[
                    $resource_ref
                ][
                    $prefix . '_q'
                ] = $representations;
            }

            /*
            * Both endpoints need to map to absolute timestamp
            * intervals before we can create an accurate range.
            */
            $rangeable = array_filter(
                $dates,
                static function ($date) {
                    return $date['range_start'] !== null
                        && $date['range_end'] !== null;
                }
            );

            /*
            * ResourceSpace date range fields should have two
            * endpoint nodes.
            *
            * Don't create a misleading numeric range if one
            * endpoint cannot be represented as an epoch range.
            */
            if (count($rangeable) !== 2) {
                continue;
            }

            $starts = array_column(
                $rangeable,
                'range_start'
            );

            $ends = array_column(
                $rangeable,
                'range_end'
            );

            /*
            * ResourceSpace's nodes don't need to arrive in
            * start/end order.
            */
            $resource_array_info[
                $resource_ref
            ][
                $prefix . '_range_start'
            ] = min($starts);

            $resource_array_info[
                $resource_ref
            ][
                $prefix . '_range_end'
            ] = max($ends);
        }
    }
    
    // Build one document per resource from the merged attribute info.
    $documents = array();
    foreach ($resource_array_info as $resource_ref => $info) {
        $document = array('id' => (string) $resource_ref);

        foreach ($info as $field => $value) {
            if ($field === 'nodes' || $field === 'populated_field_ids') {
                // Unique, plus the intentional 0 sentinel so the array field always exists.
                $values = array_values(array_unique($value));
                array_unshift($values, 0);
                $values = array_values(array_unique($values));
                sort($values, SORT_NUMERIC);
                $document[$field] = $values;
            } elseif (is_array($value) && (substr($field, -2) === '_q' || substr($field, -3) === '_ss')) {
                // De-duplicate multi-value string fields.
                $document[$field] = array_values(array_unique($value));
            } else {
                $document[$field] = $value;
            }
        }

        $documents[] = $document;
    }

    $document_count = count($documents);

    if (typesense_search_index_attributes_batch($documents)) {
        $indexed += $document_count;
    } else {
        $failed += $document_count;
    }

    return array(
        'indexed' => $indexed,
        'failed' => $failed,
        'last' => $last,
        'content_length' => 0,
        'complete' => $resource_list_count < $limit,
    );

}


/**
 * Index a Typesense document.
 *
 * @param array $document Typesense document data.
 *
 * @return bool True if the document was indexed successfully.
 */
function typesense_search_index_document(array $document): bool
{
    global $typesense_search_collection_prefix;

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection_prefix . "resources")
        . '/documents?action=upsert';

    return typesense_search_request('POST', $endpoint, false, $document) !== false;
}

/**
 * Import documents into a collection, splitting into chunks that never exceed a maximum document
 * count or byte size per HTTP POST (so a large batch cannot produce an oversized request).
 * Caps are overridable via $typesense_search_import_max_docs / _max_bytes.
 *
 * @param string $collection_suffix Collection name after the prefix (e.g. "resources").
 * @param array  $documents         Documents to import.
 * @param string $action            Typesense import action (upsert / update / create).
 *
 * @return bool True if every chunk imported successfully.
 */
function typesense_search_import(string $collection_suffix, array $documents, string $action): bool
{
    global $typesense_search_collection_prefix;
    global $typesense_search_import_max_docs, $typesense_search_import_max_bytes;

    if (count($documents) === 0) {
        return true;
    }

    $max_docs = (isset($typesense_search_import_max_docs) && (int) $typesense_search_import_max_docs > 0)
        ? (int) $typesense_search_import_max_docs : 500;
    $max_bytes = (isset($typesense_search_import_max_bytes) && (int) $typesense_search_import_max_bytes > 0)
        ? (int) $typesense_search_import_max_bytes : 4 * 1024 * 1024;

    $endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection_prefix . $collection_suffix)
        . '/documents/import?action=' . rawurlencode($action) . '&return_id=true';

    $ok = true;
    $chunk = array();
    $chunk_bytes = 0;

    foreach ($documents as $doc) {
        $len = strlen(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) + 1;

        // Flush the current chunk before it would exceed a cap (always keep >= 1 doc per chunk).
        if (count($chunk) > 0 && (count($chunk) >= $max_docs || ($chunk_bytes + $len) > $max_bytes)) {
            $ok = (typesense_search_request('POST', $endpoint, true, $chunk) !== false) && $ok;
            $chunk = array();
            $chunk_bytes = 0;
        }

        $chunk[] = $doc;
        $chunk_bytes += $len;
    }

    if (count($chunk) > 0) {
        $ok = (typesense_search_request('POST', $endpoint, true, $chunk) !== false) && $ok;
    }

    return $ok;
}

function typesense_search_index_document_batch(array $documents): bool
{
    return typesense_search_import('resources', $documents, 'upsert');
}

function typesense_search_index_rcms_batch(array $documents): bool
{
    return typesense_search_import('resource_collection_memberships', $documents, 'upsert');
}

function typesense_search_index_attributes_batch(array $documents): bool
{
    return typesense_search_import('resources', $documents, 'update');
}


/**
 * Build a Typesense grant document from a resource_custom_access row (access <> 2 only).
 * Group grants never expire (mirroring the rca join); user grants honour user_expires.
 *
 * @param array $row resource_custom_access row (resource, user, usergroup, access, user_expires).
 *
 * @return array Grant document.
 */
function typesense_search_grant_document(array $row): array
{
    $resource = (int) $row['resource'];
    $user = (int) ($row['user'] ?? 0);
    $usergroup = (int) ($row['usergroup'] ?? 0);
    $expires = ($user > 0 && !empty($row['user_expires'])) ? (int) strtotime($row['user_expires']) : 0;

    // A row is either a user grant or a group grant. Store -1 (never a real ref) in the unused
    // field so the query filter's user:=<userref> / usergroup:=<usergroup> can never match the
    // placeholder for an anonymous / no-group user (whose userref or usergroup is 0).
    if ($user > 0) {
        $id = $resource . '_u' . $user;
        $usergroup = -1;
    } else {
        $id = $resource . '_g' . $usergroup;
        $user = -1;
    }

    return array(
        'id' => $id,
        'resource_id' => (string) $resource,
        'user' => $user,
        'usergroup' => $usergroup,
        'access' => (int) $row['access'],
        'expires' => $expires,
    );
}


/**
 * Batch-import grant documents into the grants collection.
 *
 * @param array $documents Grant documents.
 *
 * @return bool
 */
function typesense_search_index_grants_batch(array $documents): bool
{
    return typesense_search_import('resource_access_grants', $documents, 'upsert');
}


/**
 * Reindex resource_custom_access grants in batches (access <> 2 only). Used by the reindex CLI.
 *
 * @param int $limit Batch size.
 * @param int $after Only grants for resources with ref greater than this.
 *
 * @return array Batch summary.
 */
function typesense_search_reindex_grants(int $limit = 1000, int $after = 0): array
{
    $rows = ps_query(
        "SELECT resource, user, usergroup, access, user_expires
           FROM resource_custom_access
          WHERE access <> 2 AND resource > ?
       ORDER BY resource ASC
          LIMIT ?",
        array('i', $after, 'i', $limit)
    );

    $count = count($rows);

    if ($count === 0) {
        return array('indexed' => 0, 'failed' => 0, 'last' => $after, 'content_length' => 0, 'complete' => true);
    }

    $documents = array();
    $last = $after;
    foreach ($rows as $row) {
        $last = (int) $row['resource'];
        $documents[] = typesense_search_grant_document($row);
    }

    $ok = typesense_search_index_grants_batch($documents);

    return array(
        'indexed' => $ok ? $count : 0,
        'failed' => $ok ? 0 : $count,
        'last' => $last,
        'content_length' => 0,
        'complete' => $count < $limit,
    );
}


/**
 * (Re)index all grants for a single resource: delete its existing grant docs, then add the
 * current non-confidential ones. For future incremental sync from custom-access hooks.
 *
 * @param int $resource Resource ID.
 *
 * @return bool
 */
function typesense_search_index_grants(int $resource): bool
{
    global $typesense_search_collection_prefix;

    $collection = $typesense_search_collection_prefix . 'resource_access_grants';

    // Remove existing grant docs for this resource.
    typesense_search_request(
        'DELETE',
        '/collections/' . rawurlencode($collection) . '/documents?filter_by=' . rawurlencode('resource_id:=' . $resource)
    );

    $rows = ps_query(
        "SELECT resource, user, usergroup, access, user_expires FROM resource_custom_access WHERE access <> 2 AND resource = ?",
        array('i', $resource)
    );

    if (count($rows) === 0) {
        return true;
    }

    $documents = array_map('typesense_search_grant_document', $rows);

    return typesense_search_index_grants_batch($documents);
}


/**
 * Synchronise ResourceSpace related keywords to Typesense synonyms.
 *
 * Creates or updates synonym groups in the configured Typesense collection
 * based on ResourceSpace related keyword relationships so that searches
 * automatically match related terms using OR-style expansion.
 *
 * @return bool True if the sync completed successfully.
 */
function typesense_search_sync_related_keywords(): bool
{
    global $typesense_search_collection;

    $synonyms_endpoint =
        '/collections/'
        . rawurlencode($typesense_search_collection)
        . '/synonyms';

    $existing = typesense_search_request('GET', $synonyms_endpoint);

    if ($existing !== false && isset($existing['synonyms']) && is_array($existing['synonyms'])) {
        foreach ($existing['synonyms'] as $synonym) {
            $id = $synonym['id'] ?? '';

            if (strpos($id, 'rs_related_') !== 0) {
                continue;
            }

            typesense_search_request(
                'DELETE',
                $synonyms_endpoint . '/' . rawurlencode($id)
            );
        }
    }

    $groups = get_grouped_related_keywords('');

    foreach ($groups as $group) {
        $keywords = array();

        $keywords[] = trim((string)$group['keyword']);

        foreach (explode(',', (string)$group['related']) as $related) {
            $related = trim($related);

            if ($related !== '') {
                $keywords[] = $related;
            }
        }

        $keywords = array_values(array_unique(array_filter($keywords)));

        if (count($keywords) < 2) {
            continue;
        }

        sort($keywords);

        $payload = array(
            'synonyms' => $keywords,
        );

        $endpoint =
            $synonyms_endpoint
            . '/'
            . rawurlencode('rs_related_' . md5(implode('|', $keywords)));

        typesense_search_request('PUT', $endpoint, false, $payload);
    }

    return true;
}

function typesense_parse_date(
    string $input,
    ?DateTimeZone $timezone = null
): ?array {
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    /*
     * Use UTC unless your ResourceSpace dates have a specific timezone
     * that you want to preserve in the index.
     */
    $timezone ??= new DateTimeZone('UTC');

    /*
     * Match:
     *
     * YYYY
     * YYYY-MM
     * YYYY-MM-DD
     *
     * with optional time only after YYYY-MM-DD:
     *
     * YYYY-MM-DD HH:MM
     * YYYY-MM-DD HH:MM:SS
     * YYYY-MM-DDTHH:MM
     * YYYY-MM-DDTHH:MM:SS
     */
    $pattern = '/^
        (?<year>\d{4})
        (?:
            -(?<month>\d{2})
            (?:
                -(?<day>\d{2})
                (?:
                    [ T]
                    (?<hour>\d{2})
                    :
                    (?<minute>\d{2})
                    (?:
                        :
                        (?<second>\d{2})
                    )?
                )?
            )?
        )?
    $/xD';

    if (!preg_match(
        $pattern,
        $input,
        $matches,
        PREG_UNMATCHED_AS_NULL
    )) {
        return null;
    }

    /*
     * Preserve the original textual components so month/day remain
     * zero-padded in the Typesense query representations.
     */
    $year_text   = $matches['year'];
    $month_text  = $matches['month'];
    $day_text    = $matches['day'];
    $hour_text   = $matches['hour'];
    $minute_text = $matches['minute'];
    $second_text = $matches['second'];

    /*
     * Numeric forms for validation.
     */
    $year = (int) $year_text;

    $month = $month_text !== null
        ? (int) $month_text
        : null;

    $day = $day_text !== null
        ? (int) $day_text
        : null;

    $hour = $hour_text !== null
        ? (int) $hour_text
        : null;

    $minute = $minute_text !== null
        ? (int) $minute_text
        : null;

    $second = $second_text !== null
        ? (int) $second_text
        : null;

    $has_time = $hour !== null;

    /*
     * -----------------------------------------------------------------
     * YYYY
     * -----------------------------------------------------------------
     */
    if ($month === null) {
        if ($year === 0) {
            return null;
        }

        $start = typesense_date_create(
            $year,
            1,
            1,
            0,
            0,
            0,
            $timezone
        );

        if ($start === null) {
            return null;
        }

        $end = $start->modify('+1 year');

        return array(
            'representations' => array(
                $year_text,
            ),
            'timestamp' => null,
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'year',
            'has_time' => false,
        );
    }

    /*
     * Validate month.
     *
     * 00 is allowed because ResourceSpace uses it to represent an
     * unknown component.
     */
    if ($month < 0 || $month > 12) {
        return null;
    }

    /*
     * -----------------------------------------------------------------
     * YYYY-MM
     * -----------------------------------------------------------------
     *
     * The short YYYY-MM form must have an actual year and month.
     */
    if ($day === null) {
        if ($year === 0 || $month === 0) {
            return null;
        }

        $start = typesense_date_create(
            $year,
            $month,
            1,
            0,
            0,
            0,
            $timezone
        );

        if ($start === null) {
            return null;
        }

        $end = $start->modify('+1 month');

        return array(
            'representations' => array(
                $year_text,
                $month_text,
                $year_text . '-' . $month_text,
            ),
            'timestamp' => null,
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'month',
            'has_time' => false,
        );
    }

    /*
     * Validate day.
     *
     * Again, 00 is valid as an "unknown" ResourceSpace component.
     */
    if ($day < 0 || $day > 31) {
        return null;
    }

    /*
     * Explicitly define the ResourceSpace partial-date combinations
     * that we support.
     */

    // 2024-00-00
    $year_only =
        $year > 0 &&
        $month === 0 &&
        $day === 0;

    // 2024-08-00
    $year_month =
        $year > 0 &&
        $month > 0 &&
        $day === 0;

    // 0000-08-00
    $month_only =
        $year === 0 &&
        $month > 0 &&
        $day === 0;

    // 0000-00-11
    $day_only =
        $year === 0 &&
        $month === 0 &&
        $day > 0;

    // 2024-08-11
    $full_date =
        $year > 0 &&
        $month > 0 &&
        $day > 0;

    /*
     * Reject other zero-component combinations, for example:
     *
     * 2024-00-11
     * 0000-08-11
     * 0000-00-00
     */
    if (
        !$year_only &&
        !$year_month &&
        !$month_only &&
        !$day_only &&
        !$full_date
    ) {
        return null;
    }

    /*
     * Time is only meaningful against a complete date.
     */
    if ($has_time && !$full_date) {
        return null;
    }

    /*
     * Validate time.
     */
    if ($has_time) {
        if (
            $hour < 0 ||
            $hour > 23 ||
            $minute === null ||
            $minute < 0 ||
            $minute > 59 ||
            (
                $second !== null &&
                ($second < 0 || $second > 59)
            )
        ) {
            return null;
        }
    }

    /*
     * -----------------------------------------------------------------
     * YYYY-00-00
     * -----------------------------------------------------------------
     */
    if ($year_only) {
        $start = typesense_date_create(
            $year,
            1,
            1,
            0,
            0,
            0,
            $timezone
        );

        if ($start === null) {
            return null;
        }

        $end = $start->modify('+1 year');

        return array(
            'representations' => array(
                $year_text,
            ),
            'timestamp' => null,
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'year',
            'has_time' => false,
        );
    }

    /*
     * -----------------------------------------------------------------
     * YYYY-MM-00
     * -----------------------------------------------------------------
     */
    if ($year_month) {
        $start = typesense_date_create(
            $year,
            $month,
            1,
            0,
            0,
            0,
            $timezone
        );

        if ($start === null) {
            return null;
        }

        $end = $start->modify('+1 month');

        return array(
            'representations' => array(
                $year_text,
                $month_text,
                $year_text . '-' . $month_text,
            ),
            'timestamp' => null,
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'month',
            'has_time' => false,
        );
    }

    /*
     * -----------------------------------------------------------------
     * 0000-MM-00
     * -----------------------------------------------------------------
     *
     * "August in any year" does not describe a single continuous
     * position on the absolute timeline, so no epoch range is emitted.
     */
    if ($month_only) {
        return array(
            'representations' => array(
                $month_text,
            ),
            'timestamp' => null,
            'range_start' => null,
            'range_end' => null,
            'precision' => 'month_only',
            'has_time' => false,
        );
    }

    /*
     * -----------------------------------------------------------------
     * 0000-00-DD
     * -----------------------------------------------------------------
     *
     * Likewise, "11th day of any month/year" cannot map to one epoch
     * interval.
     */
    if ($day_only) {
        return array(
            'representations' => array(
                $day_text,
            ),
            'timestamp' => null,
            'range_start' => null,
            'range_end' => null,
            'precision' => 'day_only',
            'has_time' => false,
        );
    }

    /*
     * -----------------------------------------------------------------
     * Complete YYYY-MM-DD
     * -----------------------------------------------------------------
     */

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    /*
     * No time supplied: the value represents the whole calendar day.
     */
    if (!$has_time) {
        $start = typesense_date_create(
            $year,
            $month,
            $day,
            0,
            0,
            0,
            $timezone
        );

        if ($start === null) {
            return null;
        }

        $end = $start->modify('+1 day');

        $date_text =
            $year_text . '-' .
            $month_text . '-' .
            $day_text;

        return array(
            'representations' => array(
                $year_text,
                $month_text,
                $day_text,
                $year_text . '-' . $month_text,
                $date_text,
            ),

            /*
             * A complete date can have a useful sortable timestamp.
             * Midnight is the canonical beginning of that date, while
             * range_start/range_end preserve the fact that it represents
             * the entire day.
             */
            'timestamp' => $start->getTimestamp(),
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'day',
            'has_time' => false,
        );
    }

    /*
     * -----------------------------------------------------------------
     * Complete date + time
     * -----------------------------------------------------------------
     */

    $actual_second = $second ?? 0;

    $start = typesense_date_create(
        $year,
        $month,
        $day,
        $hour,
        $minute,
        $actual_second,
        $timezone
    );

    if ($start === null) {
        return null;
    }

    $date_text =
        $year_text . '-' .
        $month_text . '-' .
        $day_text;

    $minute_text_value =
        $date_text . ' ' .
        $hour_text . ':' .
        $minute_text;

    $representations = array(
        $year_text,
        $month_text,
        $day_text,
        $year_text . '-' . $month_text,
        $date_text,
        $minute_text_value,
    );

    /*
     * HH:MM means the value has minute precision.
     */
    if ($second === null) {
        $end = $start->modify('+1 minute');

        return array(
            'representations' => $representations,
            'timestamp' => $start->getTimestamp(),
            'range_start' => $start->getTimestamp(),
            'range_end' => $end->getTimestamp(),
            'precision' => 'minute',
            'has_time' => true,
        );
    }

    /*
     * HH:MM:SS means second precision.
     */
    $second_text_value =
        $minute_text_value . ':' . $second_text;

    $representations[] = $second_text_value;

    $end = $start->modify('+1 second');

    return array(
        'representations' => $representations,
        'timestamp' => $start->getTimestamp(),
        'range_start' => $start->getTimestamp(),
        'range_end' => $end->getTimestamp(),
        'precision' => 'second',
        'has_time' => true,
    );
}


/**
 * Safely construct a DateTimeImmutable without allowing PHP's normal
 * date parser to silently normalise an invalid value.
 */
function typesense_date_create(
    int $year,
    int $month,
    int $day,
    int $hour,
    int $minute,
    int $second,
    DateTimeZone $timezone
): ?DateTimeImmutable {
    $value = sprintf(
        '%04d-%02d-%02d %02d:%02d:%02d',
        $year,
        $month,
        $day,
        $hour,
        $minute,
        $second
    );

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        $timezone
    );

    if ($date === false) {
        return null;
    }

    $errors = DateTimeImmutable::getLastErrors();

    /*
     * getLastErrors() returns false when there were no warnings/errors.
     */
    if (
        is_array($errors) &&
        (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        )
    ) {
        return null;
    }

    /*
     * Ensure PHP hasn't normalised the date behind our back.
     */
    if ($date->format('Y-m-d H:i:s') !== $value) {
        return null;
    }

    return $date;
}