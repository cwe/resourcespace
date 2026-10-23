<?php

// typesense_search defaults, read through typesense_search_option() (Q9.1): $typesense_search_<option>_override in
// config.php wins over the value the setup page stored, which wins over the default here. Everything from
// "config.php only" down has no setup-page row and is set here by name.

// Setup-page options. Lock any of them from config.php with $typesense_search_<option>_override.
$typesense_search_host = '127.0.0.1';
$typesense_search_port = 8108;
$typesense_search_protocol = 'http';
// The search-only key, the only key a web request loads (Q12.1).
$typesense_search_search_key = '';
// The write key: documents, collections and aliases on the prefix; loaded by the rebuild and the worker only (Q12.1).
$typesense_search_admin_key = '';
// The master toggle. A group configuration override of $typesense_search_enabled is the A/B switch per group (P13).
$typesense_search_enabled = false;
// The search-title badge: served / fallback / index stale / bulk pending (G3).
$typesense_search_show_indicator = true;
// Field refs never copied to the index (G9); a change takes effect at the next rebuild.
$typesense_search_excluded_fields = array();
// No default prefix: $typesense_search_prefix is generated at the first save of the setup page and stored (Q9.12);
// $typesense_search_prefix_override assigns one from here.

// Testing aid: a declined search returns nothing instead of falling back to ResourceSpace's search. Set by group
// configuration override for test groups only, never for real users (Q0.6).
$typesense_search_only = false;

// config.php only.
$typesense_search_search_timeout = 2;           // seconds, a search request
$typesense_search_import_timeout = 600;         // seconds, an import or collection request
$typesense_search_filter_max_ops = 100;         // the server's --filter-by-max-ops, mirrored per tenant (Q9.13)
$typesense_search_import_max_docs = 500;        // documents per import request (B7)
$typesense_search_import_max_bytes = 10485760;  // bytes per import request (B7)
$typesense_search_import_concurrency = 2;       // import requests in flight (Q9.13)
$typesense_search_import_backoff_min = 10;      // seconds, the least an import pauses after a 503 (P16)
$typesense_search_import_backoff_max = 60;      // seconds, the most; the pause is a random point between the two
$typesense_search_worker_batch = 500;           // queue rows claimed per worker pass (C4)
$typesense_search_flush_max_rows = 50;          // queue rows a web request applies at its end (C3)
$typesense_search_breaker_failures = 3;         // transport failures or 5xx responses in a row that open the breaker
$typesense_search_breaker_cooldown = 30;        // seconds the breaker stays open before the half-open /health probe
$typesense_search_log_retention_days = 30;      // the sync and rebuild log (Q12.5)
$typesense_search_max_rows = 25000;             // a search needing more rows than this falls back (P22)
