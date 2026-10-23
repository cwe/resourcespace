# Operations

The operations page (G4) grows here task by task: install, configure, rebuild, monitor, recover. The sections below
are the ones written so far.

## Web exposure of plugin files (X3, Q12.6)

Only `pages/` is meant to be reached over the web. `scripts/` holds the command-line tools and `harness/` the test
harness (on the GitHub branch only; left out of the release). Every script and harness entry refuses a web request
itself (`PHP_SAPI` guard, HTTP 401 or 404) and both directories carry a "Deny from all" `.htaccess` on the
`include/.htaccess` precedent, which Apache honours when `AllowOverride` permits it. nginx reads no `.htaccess`, so
add the equivalent rule to the server block, before the PHP location, with the installation's base path in place of
the leading `/`:

```nginx
location ~ ^/plugins/typesense_search/(scripts|harness)(/|$) {
    return 404;
}
```

The rule covers the non-PHP files in those directories too (the harness's fixtures, snapshots and results, which the
`PHP_SAPI` guard cannot). The `tests/` bridge ships in every release, guarded as core guards `tests/`. The X1 battery
(`harness/ab/X1_keys.php`) requests every file in the three directories through PHP's built-in server and asserts the
refusal; the setup page's actions are reached only by an administrator's POST with the form token.

## Keys (X1, Q12.1)

Two keys per installation, created from the setup page with the server's bootstrap key (the `--api-key` the server was
started with), which is used for that one request and never stored or logged: a search key (`documents:search`) and an
admin key (`documents:*`, `collections:*`, `aliases:upsert`, `aliases:get`, `aliases:delete`, `debug:list`,
`metrics.json:list`), both scoped to the installation's collections (`<prefix>_.*`, a regular expression on Typesense
30.x). Rotation is the same action: new keys are created and stored and the previous ones revoked by their recorded
ids. A hosted installation sets `$typesense_search_search_key_override` and `$typesense_search_admin_key_override` in
`config.php` instead, and the page shows the keys as locked and hidden. The keys never appear on the page, in its HTML
source, in the debug log or in the activity log.
