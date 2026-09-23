# Typesense Search — `!collection` Parity Plan

## Status (23 Sep 2026)

This is a plan only. Nothing here is implemented yet, and four decisions are still open (§4).

**Scope:** searching *within* a collection (`!collection<ref>`) must behave exactly like core. That
includes the collection access check (readability), `$collections_omit_archived`, and the `J`
featured-collections restriction, which also applies inside collections. Searching *for* collections
(`search_public_collections()`) is out of scope.

Companion docs:
- [Query-build architecture](typesense-query-architecture.md) (`CollectionMode`,
  `FeaturedCollectionsRestriction`, indexing gaps, the planned API parity testing).
- [ResourceSpace search behaviour](resourcespace-search-behaviour.md) (§7.4 `J`, §8 `!collection`,
  §13 combinations).

Evidence comes from three sources:
- Core code read on the `typesense` branch.
- Read-only queries against the test Typesense (30.2, prefix `ysp_`, 112,608 resource and 44,801
  membership documents). See Appendix A.
- A MySQL count supplied by Chris.

## Summary

Today the plugin can return wrong collection results in four ways, and one of them (a 250-row cap)
also changes data.

On top of that, **most collection memberships probably never reached Typesense.** MySQL has 109,220
membership rows with a NULL `sortorder`, which is core's default when a resource is added. The index
can't store a NULL in that field and holds only 44,801 membership docs in total.

Almost no core code that changes memberships fires a hook (Appendix B), so hooks alone can't keep the
index correct. The recommendation is a freshness check at query time:
- On every `!collection` search, fingerprint the collection's rows in MySQL.
- Compare it with the fingerprint recorded when the collection was last synced to Typesense.
- On a mismatch, fall back to core.

That makes every served result correct. Read-repair and hooks then keep collections served by
Typesense.

## 1. Where the plugin differs from core

⚠ = silently wrong result · ◐ = difference in order, fields or strictness

| # | Difference | Evidence |
|---|---|---|
| 1 ⚠ | **`J` inside a collection returns nothing** unless the collection is itself a permitted featured collection. `TypesenseCollectionMode` and `TypesenseFeaturedCollectionsRestriction` each add a `$memberships(...)` join, and Typesense requires both to match the same membership document. | Live: collection 3837 gives 35 and 1,115 with each join alone, 0 together (Appendix A) |
| 2 ⚠ | **Non-paged `fetchrows` is capped at 250 rows.** `-1` and `[x,-1]` become `per_page=250`, and integer `fetchrows` isn't zero-padded the way `search_special()` pads it. This hits the collection bar, the download, edit, share and feedback pages, and the API default. Two paths change data: `add_saved_search_items()` ("add all results to collection") adds only 250 resources, and the search page's selection clean-up (`pages/search.php`, `check_selection_collection`) removes selections past row 250. | Live: `!collection108` returns 250 of 328 |
| 3 ⚠ | **Memberships are missing or stale.**<br>• **NULL `sortorder`**, the default from `add_resource_to_collection()`, can't be imported into the non-optional `int32` field. MySQL has **109,220** such rows and the index holds 44,801 docs in total, so most memberships are probably absent. Collection views, and `J`, silently miss those resources.<br>• Per-line import failures aren't detected, because the check looks for the literal `{"success":false}`. The reindex therefore reported them as indexed.<br>• There's no sync on save, and the full reindex only upserts, so removed members survive it.<br>• A member whose resource document isn't indexed can't be imported, because the reference needs it. | MySQL count vs index count. Exact split: see *Also found* |
| 4 ⚠ | **The access check is looser than core's.** The plugin only calls `collection_readable()`. Core ([search_functions.php:1203-1238](../../../include/search_functions.php:1203)) also requires the collection to be one of the user's own, shared, public, selection, request or research collections, a permitted featured collection, or allowed by `$ignore_collection_access`. It also has an external-key path. `collection_readable()` is true for every collection for `R` and `h` users, and for `request_feedback` collections when `$collection_commenting` is on. Group-limited public collections and upload-share sessions differ too. The plugin also skips the check under `access_override`, which core doesn't. | Code |
| 5 ◐ | **Ties are ordered differently.** The plugin sorts by `sortorder` only. Core sorts by `c.sortorder, c.date_added` (reversed), then `r.ref` ([search_functions.php:3184](../../../include/search_functions.php:3184)). | Live: collection 108 (180+ members share one `sortorder`) differs from row 20. The fix in Phase 0 matches 328/328 in both directions |
| 6 ◐ | **Relevance inside a collection.** Core's `score` there is `r.hit_count`. The plugin uses text match, or `ref:desc` with no keywords. Most internal `do_search('!collection…')` calls use the default `relevance`. | Code |
| 7 ◐ | **Row fields.** `c.date_added`, `c.comment` and `commentset` are missing. Nothing in core's UI reads them from search rows, but `api_do_search()` returns whole rows, so API clients lose them. | grep |
| 8 ◐ | **Archive and pending edge cases:**<br>• `$collections_omit_archived` is applied even under `access_override`; core applies it only without.<br>• Pending resources are hidden in external-share views, even though core shows them when `$collection_allow_not_approved_share` is set.<br>• `ert` and `$uploader_view_override` aren't modelled (a plugin-wide gap).<br>• `J` is skipped under `access_override`; core applies it. | Code |
| 9 ◐ | **`J` with `j*` and no `-j`.** Core accepts membership of *any* collection, the plugin only featured ones. See Appendix C. | Code |
| 10 ◐ | **Parsing and volatile collections.** `!collection123,456` means collection 123 in core but 123456 in the plugin. Selection (type 2), upload (1) and share-upload (5) collections are indexed and served, though they change on every click or hold resources that aren't indexed yet. | Code |

These already match core:
- ignoring `restypes`
- `T` permissions
- any archive state, with `$collections_omit_archived`/`e2`
- the smart-collection refresh
- the upload-collection exemption from `J`
- keyword, node and `field:value` refinement inside a collection
- the `J` list and "none" cases outside collections

## 2. Options

### 2.1 Keeping membership data trustworthy

Only `add_resource_to_collection()` and `remove_resource_from_collection()` fire hooks, and both fire
*before* the SQL runs. These have no hook at all:
- reordering
- deleting or emptying a collection
- "add all results" (which also shifts every `sortorder` in one UPDATE)
- the `copy_collection` wipe
- research copies, staticsync and the action_dates plugin
- changes to a collection's type

`collection_log()` has no hook either, and doesn't log many of these changes. See Appendix B.

| Option | For | Against |
|---|---|---|
| A. Hooks only: the two existing ones plus about 10 new core hooks | Cheapest queries | Can never be complete, because tools, plugins and cron write raw SQL. One missed path means silently wrong results. |
| **B. Freshness check plus read-repair.** Store a fingerprint per collection when it's synced. Each query compares `COUNT(*), BIT_XOR(CRC32(resource:sortorder:date_added))` and the collection type against it. On a mismatch, resync if the collection is small, otherwise fall back to core. | Correct whatever made the change. Needs no hooks. Correct straight after a reindex. | One indexed MySQL aggregate per `!collection` query, about the cost of core's own plain browse. Needs a plugin table. |
| C. Get the member list from MySQL on every query (`ref:=[…]`) | Always fresh | Very large filters. Collection order needs multiple page fetches. `J` still needs the index. |
| D. Serve only keyword and field refinements | Less to get right | Still needs freshness, and the plain collection view is never served. |

**Recommended: B.** The two existing hooks can refresh the index when memberships change. Any new
core hooks would only reduce delay; they aren't needed for correctness.

### 2.2 `J` inside a collection

- **J1, skip or fall back.** If the collection is in `compute_featured_collections_access_control()`,
  `J` adds nothing, so drop its join. Otherwise fall back to core. Small and exact.
  - Use that function, which returns the same set core's `J` join uses. Don't use
    `featured_collection_check_access_control()`: the two disagree when a `-j` sits under a `j`
    category.
- **J2, a second referenced collection** holding only featured memberships, joined by `J`. Joins
  across *different* collections work on the live server. Served by Typesense, but it's a second
  index to keep fresh.
- **J3, featured collection refs stored on each resource document**, so `J` becomes a plain filter
  on every `J` search. Faster, but it belongs in the planned rework of resource documents
  ([OPEN] item 3 in the architecture doc).

**Recommended: J1 now**, and J3 later only if `J` users' collection views need to be served by
Typesense.

### 2.3 The access check

- **G1:** move core's check out of `search_special()` into a function that core and the plugin both
  call. Exact, and core's behaviour doesn't change.
- **G2:** copy the check into the plugin. It will drift from core, and it repeats expensive lookups
  (`get_user_collections()`, `search_public_collections()`, `get_requests()`, …).

**Recommended: G1.**

### 2.4 Non-paged `fetchrows`

- **F1:** fall back to core for `-1`, `[x,-1]` and anything over 250, and zero-pad integer `n` the way
  core does. This fixes the whole plugin, not just collections.
- **F2:** page through Typesense (refs only) and hydrate everything. Only worth it for coverage in
  Typesense-only mode.

**Recommended: F1.**

## 3. Phases

### Phase 0 — never serve a wrong collection result

Plugin changes plus one core helper, then a reindex.

1. **Access check:** use G1. Don't skip it under `access_override`, and fall back for negative
   collection refs.
2. **`J`:** use J1, and apply `J` under `access_override` as core does.
3. **`fetchrows`:** use F1.
4. **Default order, and the missing memberships:**
   - Sort by `$<prefix>resource_collection_memberships(sortorder:d,date_added:<opposite of d>),ref:d`.
   - Index NULL `sortorder` as −2147483648 and NULL `date_added` as 0. This reproduces MySQL's
     placement of NULLs in both directions, and it also lets the ~109k NULL-`sortorder` rows import.
5. **Relevance sort inside a collection:** fall back to core (decision 3).
6. **Volatile collections:** don't index or serve selection, upload, share-upload or `-userref`
   collections.
7. **Missing fields:** when hydrating `!collection` results, LEFT JOIN `collection_resource` to get
   `c.date_added`, `c.comment` and `commentset` from MySQL.
8. **Archive and pending rules:**
   - Apply `$collections_omit_archived` only without `access_override`.
   - Don't hide pending resources when `$k` and `$collection_allow_not_approved_share` are both set.
   - Handle `ert` and `$uploader_view_override` everywhere in the plugin.
9. **Freshness check** (option B, without repair):
   - Add a `typesense_search_collection_sync` table (collection, fingerprint, row count, type,
     status).
   - Fall back to core when the fingerprint doesn't match, is missing, or its sync failed.
   - For `J`, compare all featured collections' fingerprints once per request, and include type
     changes.
10. **Indexer:**
    - Add a per-collection sync that upserts, then deletes stale docs with
      `collection_ref:=C && id:!=[…]` (verified live).
    - The full reindex drops and recreates the memberships collection and empties the sync table.
      Searches fall back to core until it finishes.
    - Detect failed import lines properly, and record a fingerprint only when every line succeeds. A
      member whose resource isn't indexed fails the reference, so that collection stays on core.
11. **Small fixes:**
    - Parse the collection ID the way core does (split on space, then comma, then `(int)`).
    - Run the smart-collection refresh only once the plugin is committed to serving, because a later
      fallback makes core run it again.

After Phase 0, Typesense serves the paged collection view, paged API calls and refined searches.
Bulk internal callers (the collection bar, downloads, batch edit, the selection) go to MySQL, and
that's also where freshness matters most.

Files touched:
- Plugin:
  - [modes/collection.php](../include/modes/collection.php)
  - [restrictions/featured_collections.php](../include/restrictions/featured_collections.php)
  - [typesense_search_query.php](../include/typesense_search_query.php) (`fetchrows`, sync before
    query)
  - [typesense_search_functions.php](../include/typesense_search_functions.php) (hydrate, sync, import
    errors, schema)
  - [hooks/all.php](../hooks/all.php)
  - [scripts/reindex.php](../scripts/reindex.php)
  - a plugin table definition
- Core:
  - [search_functions.php](../../../include/search_functions.php) for the shared access check
  - [do_search.php:328](../../../include/do_search.php:328) if decision 2 is to fix core

### Phase 1 — keep collections served by Typesense

- **Read-repair:** on a mismatch, resync immediately if the collection is below a size threshold (for
  example 5,000 rows). Above it, fall back to core and queue the resync.
- **Existing hooks as a warm-up:** `Addtocollectionsuccess` and `Removefromcollectionsuccess` mark the
  collection for resync.
  - The plugin's hook functions must be declared exactly `($resourceId, $collectionId)`, because PHP
    8.2+ passes the string keys as named arguments.
  - Resync the marked collections just before the plugin queries Typesense, after the smart refresh,
    so adding a resource and then showing the collection in the same request works (for example the
    collection bar). Resync again at the end of the request.
- **Resource deletion:** `beforedeleteresourcefromdb` → `afterdeleteresource`, and
  `after_update_archive_status` (moving to the deletion state removes memberships), trigger the same
  resync.
- **Cron** (`hook("cron")`): compare every collection's fingerprint with one GROUP BY, and retry
  failed syncs.
- **Optional core hooks** after the writes in `update_collection_order`, `delete_collection`,
  `remove_all_resources_from_collection`, `add_saved_search_items`, `copy_collection` and the type
  changers (`save_collection`, `update_collection_type`, `collection_set_public`). These resync when
  the change happens instead of on the next read.
- **Dependency:** resource documents must be fresh too ([OPEN] on-save indexing in the architecture
  doc). Until then, a collection holding unindexed resources stays on core, which is safe.

### Phase 2 — collection cases for the API parity test harness

This extends the planned harness in the architecture doc (*Planned: Typesense-vs-core parity testing
via the RS API*).

- **Users,** each as a Typesense/MySQL pair:
  - a standard user
  - `J` with `j<category>` and `-j<child>`
  - `J` with `j*`
  - `R` or `h`
  - with and without `e2`
  - with `ert<type>`
- **Collections:**
  - own, shared with a user, shared with a group, public
  - featured: permitted, permitted through a category, excluded
  - request, smart, selection, a nonexistent ID
  - collection 108 (over 250 members, 180+ ties), one with NULL sortorders
  - members in archive states 2, −1 and −2, and confidential or custom-access members
- **Queries:**
  - `fetchrows` of `-1`, `5`, `0,48` and `48,48`
  - sorts: collection order ascending and descending, resource ID, date, modified, relevance
  - each plain, and with a keyword, a node and a `field:value`
- **Checks:**
  - Compare the total, the order of refs and the row keys.
  - With `$typesense_search_only` off, every case must match. With it on, empty results show
    coverage gaps.
- **Freshness:**
  - Add or remove via the API and search immediately.
  - Reorder with raw SQL from the CLI to prove the freshness check trips.
- **`$k` and `access_override`:** the API can't set these, so test them in PHP.
- **Config toggles:** `$collections_omit_archived`, `$collection_commenting` (with `request_feedback`)
  and `$ignore_collection_access`.

### Phase 3 — optional

- J3 (with the resource-document rework).
- F2.
- Indexing `hit_count` so relevance-sorted collection searches can be served.

## 4. Open decisions

1. **Are small core changes OK?** The shared access check doesn't change core's behaviour, and the
   write hooks are optional. Without core changes, it's G2 plus the freshness check.
2. **`J` with `j*`:** fix core by adding `jc.type = COLLECTION_TYPE_FEATURED` to the `J` join, or copy
   its "any collection" behaviour? Copying it would only be approximate once volatile collections are
   left out of the index.
3. **Relevance inside collections:** fall back to core for exact order, or accept the difference as the
   main search already does?
4. **Read-repair size threshold,** and is one MySQL aggregate per `!collection` query acceptable?

## Also found

- **Exact split of the missing memberships.** The 109,220 count may include rows the indexer skips
  anyway (orphans, negative upload-collection refs). This gives the precise numbers: if
  `indexable − null_sortorder` is about 44,801, every NULL row was dropped.

  ```sql
  SELECT COUNT(*) AS indexable,
         SUM(cr.sortorder IS NULL) AS null_sortorder,
         SUM(cr.date_added IS NULL) AS null_date_added
    FROM collection_resource cr
    JOIN collection c ON c.ref = cr.collection;
  ```
- **Two unrelated core collection access-control issues** turned up during this work. They were
  flagged separately and aren't described here.

---

## Appendix A — Live-verified Typesense behaviour (30.2, `ysp_`)

- **Two joins to the same collection must match the same document.** For collection 3837 (35
  members, all also in featured collections):
  - `$m(collection_ref:=3837)` alone → 35.
  - `$m(collection_ref:=[featured list])` alone → 1,115.
  - Both ANDed, with or without parentheses → **0**. Same as putting both conditions in one join.
  - Featured collection 3706 ANDed with the featured list → 256, because it's the same document.
- **Joins across different collections work.** `$memberships(...)` combined with the
  `$resource_access_grants(...)` access clauses → 35.
- **Collection order.**
  - `$m(sortorder:asc,date_added:desc),ref:asc` reproduces core's order exactly on collection 108
    (328/328).
  - The mirror `$m(sortorder:desc,date_added:asc),ref:desc` also matches exactly.
  - Today's `$m(sortorder:asc)` alone first differs at row 20.
  - The two fields inside the join count toward Typesense's limit of 3 sort fields; a 4th gives a
    422.
  - It works with a keyword query (`q` on `title` inside collection 108 → 86 results, HTTP 200).
- **Page size.** `per_page` over 250 → 422 `Only upto 250 hits can be fetched per page.`
  `per_page=0` returns only `found`.
- **Filtering on `id`.** `` id:=[`108:1278`,`108:1296`] `` and `collection_ref:=108 && id:!=[…]` both
  work on the memberships collection.
- **Memberships schema and contents.**
  - `resource_id` references `ysp_resources.id` with `cascade_delete: true` and
    `async_reference: false`.
  - `sortorder` and `date_added` are non-optional, so a NULL can't be stored.
  - 44,801 docs in total, against 109,220 MySQL rows with a NULL `sortorder`.
  - Membership types present in the index: 0 (42,911), 3 (1,116), 4 (774).
- **Scale of the `J` quirk** (before access and archive filters):
  - Resources in any collection in the index: 32,705.
  - Resources in a featured collection: 1,115.

## Appendix B — Core writes to `collection_resource` and their hooks

Line numbers are for the `typesense` branch as of 23 Sep 2026.

| Path | Change | Hook |
|---|---|---|
| `add_resource_to_collection()` [collections_functions.php:341](../../../include/collections_functions.php:341) | Delete and re-insert one row. `sortorder` NULL unless passed; `date_added` now; comment wiped. | `Addtocollectionsuccess` (named args `resourceId`, `collectionId`), **before** the write |
| `remove_resource_from_collection()` [:493](../../../include/collections_functions.php:493) | Delete one row | `Removefromcollectionsuccess`, **before** the write |
| `collection_add_resources()` / `collection_remove_resources()` / `copy_collection()` adds / `update_smart_collection()` / request collections / API add and remove / collection bar / upload / edit pages | Through the two functions above | Once per resource, as above |
| `delete_collection()` [:875](../../../include/collections_functions.php:875) | Delete all of the collection's rows | none |
| `remove_all_resources_from_collection()` [:3610](../../../include/collections_functions.php:3610) | Delete all rows (also used for selection clears and logout) | none |
| `copy_collection(…, remove_existing)` [:3300](../../../include/collections_functions.php:3300) | Wipe the target | none |
| `add_saved_search_items()` [:2431](../../../include/collections_functions.php:2431) | Shift every `sortorder`, then re-add the results | none |
| `update_collection_order()` [:3076](../../../include/collections_functions.php:3076) | Reorder; NULL → 99999 | none |
| `swap_collection_order()` [:3024](../../../include/collections_functions.php:3024) | Swap `sortorder`/`date_added` (no callers) | none |
| `collection_cleanup_inaccessible_resources()` [:4968](../../../include/collections_functions.php:4968) | Delete from the `-userref` review collection, as a side effect of a read | none |
| `cleanup_anonymous_collections()` [:6027](../../../include/collections_functions.php:6027) | Deletes collections and leaves their rows orphaned | none |
| `save_research_request()` [research_functions.php:228](../../../include/research_functions.php:228) | `INSERT … SELECT` copy | none |
| `delete_resource()` [resource_functions.php:2956](../../../include/resource_functions.php:2956) | Delete the resource's rows (hard delete) | `beforedeleteresourcefromdb` (before), `afterdeleteresource` (after, no args) |
| `update_archive_status()` [resource_functions.php:6412](../../../include/resource_functions.php:6412) | Deletion state removes rows (`$remove_deleted_resources_from_collections`) | `after_update_archive_status` (after) |
| staticsync [pages/tools/staticsync.php:808](../../../pages/tools/staticsync.php:808), [:1221](../../../pages/tools/staticsync.php:1221) | Raw insert and delete | none |
| action_dates plugin [hooks/all.php:223](../../action_dates/hooks/all.php:223) | Raw delete | none |
| `pages/tools/database_prune.php`, `pages/tools/renumber_resources.php` | Orphan clean-up; renumbering refs | none |

Collection-level changes also have no hooks: `create_collection()`, `save_collection()` (type,
parent, public), `update_collection_type()`, `update_collection_parent()` and
`collection_set_public()`. They matter because memberships store `collection_type`.
[`collection_log()`](../../../include/collections_functions.php:3450) fires no hook and doesn't log
many changes (selection adds, reorders unless deduplicated, research copies, staticsync), so it
isn't a single point where every change can be caught.

## Appendix C — The `J` + `j*` quirk

Core builds `J` as a join
([do_search.php:325-332](../../../include/do_search.php:325)):
`JOIN collection_resource jcr … JOIN collection jc` plus
[`featured_collections_permissions_filter_sql("AND", "jc.ref", true)`](../../../include/collections_functions.php:5251).
That filter returns one of these, based on `compute_featured_collections_access_control()`:

| User's `j` permissions | Filter | Effective restriction |
|---|---|---|
| `j*`, no `-j` | `""` | **any collection, of any type** |
| `j*` plus at least one `-j<ref>` | `AND jc.ref IN (all featured except excluded)` | featured only |
| `j<ref>` permissions | `AND jc.ref IN (those and their children)` | featured only |
| none | `AND 1 = 0` | nothing |

**Why this is a bug.** The empty string means "no permission filter needed", and that's only safe
when the query already requires `c.type = FEATURED`. Every other caller adds that condition itself
([collections_functions.php:1112](../../../include/collections_functions.php:1112),
[:2751](../../../include/collections_functions.php:2751),
[:5775](../../../include/collections_functions.php:5775),
[resource_functions.php:4125](../../../include/resource_functions.php:4125)). The `J` join doesn't.
The permission text (*"display only resources that exist within featured collections to which the
user has access"*) and core's own `-j` behaviour both point to featured-only.

**The plugin** uses `collection_type:=3` for this case, so it's stricter than core.

**A second, smaller oddity.** With `j*`, a `-j<category>` hides the category and its direct children,
but deeper levels stay visible. The plugin calls the same function, so this one doesn't cause a
parity difference.
