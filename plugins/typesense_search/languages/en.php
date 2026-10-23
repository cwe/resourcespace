<?php

$lang["typesense_search_configuration"] = "Typesense search configuration";
$lang["typesense_search_server"] = "Typesense server";
$lang["typesense_search_host"] = "Host";
$lang["typesense_search_port"] = "Port";
$lang["typesense_search_protocol"] = "Protocol";
$lang["typesense_search_search_key"] = "Search key";
$lang["typesense_search_admin_key"] = "Admin key";
$lang["typesense_search_key_set"] = "Set. Leave blank to keep the stored key.";
$lang["typesense_search_key_not_set"] = "Not set.";
$lang["typesense_search_locked"] = "Locked in global configuration to: [value]";
$lang["typesense_search_locked_hidden"] = "Locked in global configuration (hidden)";
$lang["typesense_search_searching"] = "Searching";
$lang["typesense_search_enabled"] = "Serve searches from Typesense";
$lang["typesense_search_enabled_help"] = 'Switch per user group with a group configuration override of $typesense_search_enabled. Typesense-only mode ($typesense_search_only, a search the plugin does not serve returns nothing) is a testing aid for test groups only, never for real users.';
$lang["typesense_search_show_indicator"] = "Show the indicator badge on search results";
$lang["typesense_search_index"] = "Index";
$lang["typesense_search_excluded_fields"] = "Fields excluded from the index";
$lang["typesense_search_excluded_fields_help"] = "An excluded field is never copied to Typesense and a search naming it falls back to ResourceSpace's search. A change takes effect at the next rebuild.";
$lang["typesense_search_prefix"] = "Collection prefix";
$lang["typesense_search_prefix_help"] = "Generated when this page is first saved. Every collection on the Typesense server belonging to this installation starts with it.";
$lang["typesense_search_prefix_unset"] = "Not yet generated";
$lang["typesense_search_health"] = "Health";
$lang["typesense_search_health_status"] = "Status";
$lang["typesense_search_health_unavailable"] = "Not available until the index has been built.";
