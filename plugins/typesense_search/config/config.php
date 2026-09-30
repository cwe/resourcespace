<?php

$typesense_search_host = '127.0.0.1';
$typesense_search_port = 8108;
$typesense_search_protocol = 'http';
$typesense_search_api_key = '';
$typesense_search_collection_prefix = 'test3_';
$typesense_search_timeout = 30;
$typesense_search_global_filter=""; // String to append to the filter - will apply to all search queries. Could be set in a group override to provide group filters until search filters are supported. Example:   $typesense_search_global_filter=" && resource_type:=3" - show resources of type 3 only.

$typesense_search_enabled = true;  // Master toggle. When false, the standard ResourceSpace (MySQL) search is used.
$typesense_search_only = false;    // Testing aid. When true, a search Typesense cannot handle returns no results instead of falling back to the standard search.
$typesense_search_show_indicator = true; // Show a badge next to the search title indicating whether results came from Typesense or the standard search.
$typesense_search_max_rows = 25000; // A search needing more rows than this from Typesense uses the standard MySQL search instead, unless $typesense_search_only is on. 0 = no limit.
$typesense_search_filter_max_ops = 100; // Should match Typesense's --filter-by-max-ops setting (default 100). A search whose filter has more operations than this uses the standard MySQL search instead. 0 = no check.

