<?php

$typesense_search_host = '127.0.0.1';
$typesense_search_port = 8108;
$typesense_search_protocol = 'http';
$typesense_search_api_key = '';
$typesense_search_collection_prefix = 'test3_';
$typesense_search_timeout = 30;
$typesense_search_global_filter=""; // String to append to the filter - will apply to all search queries. Could be set in a group override to provide group filters until search filters are supported. Example:   $typesense_search_global_filter=" && resource_type:=3" - show resources of type 3 only.

$typesense_search_enabled = true;  // Master toggle. When false, searches use the standard ResourceSpace (MySQL) search - useful for disabling Typesense without deactivating the plugin.
$typesense_search_only = false;    // Testing aid. When true, searches Typesense cannot handle return no results instead of falling back to the standard MySQL search - so you only ever see Typesense results.
$typesense_search_show_indicator = true; // Show a badge next to the search title indicating whether results came from Typesense or the standard search.
$typesense_search_max_rows = 25000; // Typesense returns at most 250 results per request, so bigger requests (e.g. every result of a search) are fetched a page at a time, which slows down as the pages get deeper. A request for more rows than this uses the standard MySQL search instead, unless $typesense_search_only is on. 0 = no limit.
$typesense_search_filter_max_ops = 100; // Typesense refuses a filter with more operations than its --filter-by-max-ops setting (default 100): every clause and every && or || between clauses counts one, so 50 ANDed single-node clauses is the most. A search whose filter would cost more uses the standard MySQL search instead. Set this to match the Typesense server; 0 = send the filter regardless.

