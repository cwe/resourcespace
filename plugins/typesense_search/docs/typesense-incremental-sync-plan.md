# Typesense Search — Incremental Index Sync Plan

## Status (23 Sep 2026)

This is a plan only. Nothing here is implemented. The direction was agreed on 23 Sep (Chris); §9
records what is decided and what is still open.

**The agreed shape:**
- A **nightly full rebuild** into fresh generation collections, swapped in through Typesense
  aliases, is the backbone. It makes the index exactly right once a day and handles schema changes
  and recovery the same way.
- Between rebuilds, ResourceSpace hooks (the existing ones plus a small set of new core hooks) flag
  changed objects in a **plugin queue table**. The request that made a change flushes its own rows
  before responding, and a **per-minute cron worker** applies everything else within seconds.
- While a **bulk change** (a node rename, a field-config change, a large batch edit) is being
  applied, searches are served by MySQL so results are never inconsistent.
- **Offline jobs** are used only for admin-triggered full rebuilds; a job started while the nightly
  rebuild is running simply fails.

**Scope:** keep the three Typesense collections (`<prefix>resources`,
`<prefix>resource_collection_memberships`, `<prefix>resource_access_grants`) correct after the full
reindex has run, for every change ResourceSpace makes afterwards. Today the index is correct only
straight after [`scripts/reindex.php`](../scripts/reindex.php); every later edit, upload, delete,
access change, collection change and grant change is missed, and the on-save code that was meant
to cover this has never worked (§1).

Companion docs:
- [Query-build architecture](typesense-query-architecture.md) — the document shape, the reindexers,
  the *Indexing gaps* item this plan closes, and the planned API parity testing.
- [`!collection` parity plan](typesense-collection-parity-plan.md) — its query-time membership
  freshness check is superseded by this plan; §8 lists what the two plans share.
- [ResourceSpace search behaviour](resourcespace-search-behaviour.md) — §7.2 is the grant model the
  grants collection reproduces.

Evidence:
- Core and plugin code on the `typesense` branch (94245579).
- Live experiments against the test Typesense (30.2, `ysp_` prefix), run only on scratch
  `synctest_*` collections that were deleted afterwards. Appendix A lists them.
- Five code inventories of the core write paths (resource lifecycle and grants, metadata writers,
  node/field/config changes, collections, job queue/cron/plugin loading). Line numbers below are
  for the `typesense` branch.
- Typesense's own guidance on syncing and re-indexing, and ResourceSpace's guidance on scheduled
  tasks (Appendix D).

## Summary

**What has to be true.** A search served by Typesense must never show a resource the user may not
see (access, archive state, grant revoked, deleted), must not miss a resource the user should see,
and should reflect a change within seconds of it being made. Results are hydrated from MySQL by
ref without re-applying access, so a stale `access`, `archive` or grant in the index is a security
problem, not just a freshness one.

**What the inventories found.** Core fires hooks for resource saves, uploads, archive changes and
hard deletes, but nothing for: node renames or deletes, any `resource_node` write outside
`save_resource_data`/`update_field`, resource-type changes, most custom-access writes, every
collection change apart from two hooks that fire *before* the row is written, field-config changes
(two argument-less hooks), and any config change. `resource.modified` moves only when a
`resource_log` row is written, and membership and grant changes never write one. Tools, upgrade
scripts and several plugins write raw SQL. So hooks decide how *quickly* the index converges; the
nightly rebuild is what guarantees that it does.

**The design in six parts:**

1. **One document builder** (§6.1), used by the rebuild and by the incremental path alike, so a
   single-resource sync always writes exactly the document the rebuild would. Full upserts only.
2. **A queue table** (§6.2) that hooks insert into. One row per changed object, coalesced by a
   unique key, with a small set of priorities (§6.3) that put visibility changes first and bulk
   work last.
3. **A request-end flush and a cron worker** (§6.5, §6.6). The request that changed something
   writes its own rows before the response goes out, so the next page already sees the change.
   A worker started by cron every minute drains everything else, polling every few seconds while
   it runs, as Typesense's sync guide recommends for a buffer table.
4. **Serving rules** (§6.7): searches use Typesense only when a complete generation is recorded as
   ready, no bulk work is pending, and the circuit breaker is closed; otherwise core's MySQL search
   runs. Read-time validation (§5.7) re-applies core's access and archive rules to every page, so a
   stale document can never leak a resource.
5. **The nightly rebuild behind aliases** (§6.8). New generation collections are built while the
   old ones serve; rows queued during the build are stamped and replayed against the new generation
   before the three aliases are swapped; the old generation is deleted once the swap is verified.
6. **A small set of new core hooks** (§6.11) for the paths that are silent today, the most valuable
   being one at the bottom of the node layer.

Anything written by raw SQL (staticsync, workflow plugins, maintenance tools) is corrected by the
nightly rebuild and hidden meanwhile by read-time validation if it concerns visibility.

## 1. Where things stand

### 1.1 The on-save path is broken in more ways than the three known ones

All verified in [`typesense_search_functions.php`](../include/typesense_search_functions.php) and
[`hooks/all.php`](../hooks/all.php):

| # | Problem | Effect |
|---|---|---|
| 1 | `$typesense_search_collection` is read by `typesense_search_index_resource()` (:697), `typesense_search_delete_resource()` (:727) and `typesense_search_sync_related_keywords()` (:1449) but never defined; config only has `$typesense_search_collection_prefix`. | Requests go to `/collections//…` and fail. Synonyms never sync either. |
| 2 | `typesense_search_index_resource()` passes the document as `typesense_search_request()`'s third argument (`$batch`), not the fourth (`$payload`). | No body is sent even with a correct URL. |
| 3 | It builds the legacy shape via `typesense_search_get_document_data()` (:614): `attributes[]`, no `ref_s`, `access`, `nodes`, `populated_field_ids` or `field_<ref>_*`. | Fixing 1 and 2 alone would make every upsert **strip** the resource's node, access and field data. It would leave node filters, `!hasdata`, `field:value` and the access restriction (`access` missing → the filter can't evaluate as intended). |
| 4 | `HookTypesense_searchAllBeforenodedelete` / `Afternodedelete` (:117, :133) listen for hooks core never fires; `node_functions.php` contains no `hook()` call at all. | Node deletes never reindex anything. |
| 5 | `HookTypesense_searchAllAftersaveresourcedata` (:148) reads `global $ref` instead of the hook's first argument. Batch edit passes `$list` (an array) at [resource_functions.php:2396](../../../include/resource_functions.php:2396) and `$ref` is only the page's first resource. | Batch edits reindex at most one resource. |
| 6 | `typesense_search_index_resource()` calls `typesense_search_ensure_collection()` first, which **creates empty collections** if the GET fails. do_search uses any non-`false` hook result, including an empty one ([do_search.php:370](../../../include/do_search.php:370)). | A lost or renamed index is silently replaced by an empty one and every search shows nothing. |
| 7 | `typesense_search_index_grants()` (:1413) exists, correctly deletes by `resource_id:=` and re-adds, but has no caller. | Grant changes never reach the index. |
| 8 | The four full-reindex passes only upsert. Nothing is ever deleted. | Resources, memberships and grants removed in RS survive every reindex into an existing collection. |
| 9 | The attributes pass imports with `action=update`. On Typesense a per-line `update` of a missing id fails (`404 Could not find a document`) inside an HTTP 200 (Appendix A). | Fine after pass 1, but any single-resource path built the same way would silently lose the update. |
| 10 | `typesense_search_reindex_grants()` (:1369) pages with `resource > ? … LIMIT ?`; a batch boundary inside one resource's rows skips the rest of them. `copy_resource` produces `(usergroup NULL, user NULL)` rows ([resource_functions.php:3757](../../../include/resource_functions.php:3757)) which become `<ref>_g0` documents with `usergroup: 0`. | Occasional missing grants; junk grant documents (harmless for anonymous users since 0 ≠ -1, but wrong). |
| 11 | One `$typesense_search_timeout` (30 s) is used for both connect and total time, for reads and writes. | If the host is unreachable (packets dropped rather than refused) every search and every save waits up to 30 s. |
| 12 | `typesense_search_hydrate_refs()` (:197) adds the `rca`/`rca2` joins only so `$select` resolves; no access or archive condition is applied. | Stale index data is shown as-is. |

### 1.2 What the full reindex does today

Four keyset-paginated passes ([reindex.php](../scripts/reindex.php)): resources (core columns +
title, `upsert`), memberships (`upsert`, NULL `sortorder` → INT32_MIN, NULL `date_added` → 0),
attributes (`update`: `nodes[]`, `populated_field_ids[]`, `field_<ref>_*`), grants (`upsert`). It
runs `typesense_search_ensure_collection()` (creates the schema if missing) and the synonym sync.
It takes no lock, never deletes, and is the only way any change reaches the index.

## 2. What must stay in sync

| Document / field | MySQL source | Changed by (see §3) |
|---|---|---|
| `resources` doc id = `ref`; `ref`, `ref_s` | `resource.ref` | create, copy, hard delete, `renumber_resources` tool |
| `title` | node of `$view_title_field` | metadata writes; config change |
| `resource_type` | `resource.resource_type` | `update_resource_type`, batch edit, API `put_resource_data`, CSV |
| `archive` | `resource.archive` | `update_archive_status`, saves, `put_resource_data`, raw SQL in staticsync / tools / workflow plugins |
| `created_by` | `resource.created_by`; NULL when created without a user (CLI, staticsync) → indexed as no value, so it never matches a `created_by:=` filter | create, copy, saves, `put_resource_data` |
| `access` | `resource.access` | saves, batch edit, `put_resource_data`, `copy_locked_data`, CSV, staticsync, action_dates |
| `creation_date` | `resource.creation_date` (recent-days limit) | set once, when the resource is created |
| `date_field_sort` | `resource.field<$date_field>`, the cached text of `$date_field` (date sort) | metadata writes; config change |
| `modified_date` | `resource.modified` | any `resource_log()` call — including downloads (§3.6) |
| `nodes[]`, `populated_field_ids[]` | all `resource_node` rows (any field) | every node writer |
| `field_<ref>_{s,ss,text,q,f,ts,range_start,range_end}` | `resource_node` × `node.name` for **indexed** fields, typed by `resource_type_field.type` / `field_constraint` | node writers; node rename/delete; field config (index flags, type, constraint); field delete; `$stemming`, `$view_title_field`, `$date_field` |
| `memberships` doc id = `collection:resource`; `resource_id`, `collection_ref`, `sortorder`, `date_added`, `collection_type` | `collection_resource` + `collection.type` | every membership writer; collection type change; collection delete; resource soft/hard delete |
| `grants` doc id = `<ref>_u<user>` / `<ref>_g<group>`; `user`/`usergroup` (-1 when unused), `access`, `expires` | `resource_custom_access` (`access <> 2`), `user_expires` | every grant writer; resource hard delete |
| synonyms (`rs_related_*`) | `keyword_related` | `save_related_keywords()` |

Expiry needs no reindex: `AccessRestriction` filters `expires:>now` at query time. Deleted users
and groups need none either: their ids can't appear in a query. Negative refs (upload templates)
must never be indexed.

## 3. Event inventory

Key: **hook** = a core hook a plugin can use today (name, timing, arguments); **modified** = whether
`resource.modified` is bumped (only `resource_log()` does this, [resource_functions.php:3842](../../../include/resource_functions.php:3842));
**gap** = what the sync design must cover because no hook does.

### 3.1 Resource lifecycle

| Event | Core path | Hook | modified | Gap / note |
|---|---|---|---|---|
| Create | `create_resource()` [rf:563](../../../include/resource_functions.php:563) | `resourcecreate($ref,$resource_type)` :603 — fires **before** autocomplete and the log; metadata not yet written | yes | Later metadata arrives through `update_field`/`aftersaveresourcedata`. Callers: API, upload (`upload_then_edit`), staticsync, contact sheets, csv_upload, emu, video_splice… |
| Copy (incl. normal upload from the user template) | `copy_resource()` [rf:3674](../../../include/resource_functions.php:3674) | `afternewresource($to)` :3793 at the end | yes | Copies `access` and custom-access rows (as junk `(NULL,NULL)` rows for user grants). `resourcecreate` does **not** fire here. |
| Upload sequence | [upload_batch.php](../../../pages/upload_batch.php) | `resourcecreate`/`afternewresource`, `after_update_archive_status` (:804, always called), `uploadfilesuccess(resource_ref)` [ip:501](../../../include/image_processing.php:501), `afterpluploadfile($ref,$extension)` :890 (last per file), `afterpreviewcreation` later (maybe from a job) | yes | One upload fires ~10 hooks and many `update_field` hooks (exif extraction). The queue must coalesce them. With `$upload_then_process` part of it runs in the `upload_processing` job. |
| Soft delete (`$resource_deletion_state`, default 3) | `delete_resource()` [rf:2985](../../../include/resource_functions.php:2985) → `update_archive_status()` | `after_update_archive_status($resources[],$archive,$existing[])` [rf:6463](../../../include/resource_functions.php:6463) | yes | Core also deletes the resource's `collection_resource` rows (raw SQL, :6455, when `$remove_deleted_resources_from_collections`) — membership docs must go too. Restoring does not restore memberships. |
| Hard delete (already in deletion state, or state unset; purge tool) | `delete_resource()` [rf:2996-3063](../../../include/resource_functions.php:2996) | `delete_resource_extra($ref)` :3030, `beforedeleteresourcefromdb($ref)` :3045, `afterdeleteresource()` :3063 (**no args**) | n/a | Deletes `resource_node`, `collection_resource`, `resource_custom_access` rows. Capture the ref in the *before* hook. `Removefromcollectionsuccess` does not fire for the membership rows. |
| Bulk delete in a collection | `delete_resources_in_collection()` [rf:6468](../../../include/resource_functions.php:6468) | as above, `after_update_archive_status` once with arrays | yes | |
| Archive / workflow change | `update_archive_status()` [rf:6412](../../../include/resource_functions.php:6412); `save_resource_data` :1346; batch :2260; API; workflow plugins | `after_update_archive_status` (fires even if unchanged) | yes (logged **before** the UPDATE) | **No hook:** `put_resource_data()` [rf:540](../../../include/resource_functions.php:540) (API `put_resource_data`), `update_resource()` :4584 (`after_update_resource(resourceId)` fires later), staticsync :719/:959/:1215, `remove_missing_files.php:25` (unlogged), rse_workflow_delete_state, emu, video_splice. |
| Access level change | `save_resource_data` [rf:1382](../../../include/resource_functions.php:1382); batch :2304; `put_resource_data`; `copy_locked_data` :6833; csv_upload; action_dates | `aftersaveresourcedata` (single: `$ref`; batch: `$list[]`) | yes | **No hook:** `put_resource_data`, `copy_locked_data` (only `copy_locked_data_extra` from edit.php), csv_upload, staticsync, action_dates. Leaving access 3 deletes group grant rows (:1374, :2307). |
| Resource type change | `update_resource_type()` [rf:4154](../../../include/resource_functions.php:4154) | **none** | only if the type changed | Also deletes now-invalid `resource_node` rows. Reached from `process_edit_form` (then `aftersaveresourcedata`, unless the save returns early at :682/:1239), batch edit, API `update_resource_type`, `copy_locked_data` (even on render), csv_upload, emu. `put_resource_data` can change the type with no node cleanup. |

### 3.2 Metadata writes

| Path | Writes nodes via | Hook | modified | Gap / note |
|---|---|---|---|---|
| Edit page | `save_resource_data()` [rf:645](../../../include/resource_functions.php:645) | `befsaveresourcedata($ref)` :655; `aftersaveresourcedata($ref,$nodes_to_add,$nodes_to_remove,$autosave_field,$fields,$updated_resources)` :1397 | yes | Early returns without the after-hook at :682 (locked) and :1239 (field errors) after some writes (in-place text node renames, EDTF `update_field`, `fieldN`). Text edits often **rename the node in place** (:1199) instead of touching `resource_node`. |
| Batch edit | `save_resource_data_multi()` [rf:1464](../../../include/resource_functions.php:1464) | `saveextraresourcedata($list)` :2392; `aftersaveresourcedata($list,…)` :2396 | only where values changed | Node writes are unlogged (`add_resource_nodes_multi`, `delete_resource_nodes_multi`); early return at :2356 skips both after-hooks **after** the writes. |
| `update_field()` [rf:2443](../../../include/resource_functions.php:2443) | nodes / in-place rename | `update_field($resource,$field,$value,$existing,$fieldinfo,$newnodes,$newvalues)` :2814 | yes (unless `$log=false`) | Not fired on validation failures or unchanged text. Used by API `update_field`, `set_resource_defaults`, exif/text extraction, geolocation, autocomplete (text), locked fields, csv text columns, most plugins (vision, clip, tesseract, whisper, openai_gpt, tms_link, emu…). |
| API `add_resource_nodes` / `add_resource_nodes_multi` [ab:769/807](../../../include/api_bindings.php:769) | `add_resource_nodes*` | **none** | yes (logged) | No API to delete nodes; no API for `save_resource_data`. |
| API `put_resource_data` [ab:379](../../../include/api_bindings.php:379) | `resource` columns incl. `modified` | **none** | yes | |
| csv_upload fixed-list columns [csv:659-811](../../../plugins/csv_upload/include/csv_functions.php:659) | `set_node`, `delete_resource_nodes`, `add_resource_nodes` | **none** | yes (logged) | Text columns use `update_field` (hook fires). Runs on the web or as a `csv_upload` job. |
| Metadata templates / "save all remaining" [edit.php:676-712](../../../pages/edit.php:676) | `copyAllDataToResource` → `copy_resource_nodes` (additive), `copy_locked_data`, `copy_locked_fields` | **none** (`copy_locked_data_extra` only) | yes (logged) | No `aftersaveresourcedata` for the other resources of the batch. |
| Fixed-list autocomplete [rf:5925](../../../include/resource_functions.php:5925) | `add_resource_nodes` | **none** | yes | Text autocomplete goes through `update_field`. |
| Annotations [annotation_functions.php:436-522](../../../include/annotation_functions.php:436) | `add/delete_resource_nodes` | **none** | yes | |
| Plugins writing nodes directly: google_vision (:104-108), clip (:217-219), faces (:231/268), museumplus (raw DELETE :172), rse_version revert | `set_node` + `add_resource_nodes` | **none** (`afterpreviewcreation` precedes the vision/clip/faces writes) | mostly yes | The write happens *inside* the `afterpreviewcreation` hook, after any hook the plugin fires at the same time — ordering matters. |
| Tools: `nodes_remove_duplicates`, `migrate_fixed_to_text`, `cleanup_invalid_nodes`, `database_prune`, `renumber_resources`, `join_fields`, `remove_html`, staticsync | raw / unlogged | **none** (`dbprune` only) | no | Only the rolling crawl catches these. |
| Related keywords | `save_related_keywords()` [sf:2423](../../../include/search_functions.php:2423) | `after_save_related_keywords($keyword,$related)` :2430 | n/a | Synonyms; works once bug 1 is fixed. |

`delete_resource_nodes_multi()` never logs, `add_resource_nodes_multi()` logs only when asked, and
`delete_all_resource_nodes()` / `delete_node_resources()` never log.

### 3.3 Node definitions, field config, global config

| Event | Core path | Hook | Effect on the index |
|---|---|---|---|
| Node rename / parent / order | `set_node()` [nf:15](../../../include/node_functions.php:15) (MFO admin :85, API `set_node`, migrations) | **none** | The name is stored in every resource's `field_<ref>_*` → every resource using the node needs a resync (thousands possible). Core itself only remaps `node_keyword`. Ancestors are stored per resource at save time, so a parent change does not retro-change existing resources (nothing to do). |
| Node delete | `delete_node()` [nf:165](../../../include/node_functions.php:165) → `delete_node_resources()` :1514 | **none** | Affected resources must be resynced; the list must be read **before** the delete. `check_delete_nodes()` runs after saves but only deletes unused text nodes (no resources affected). |
| Node active toggle | `update_node_active_state()` :2726, API | **none** | Core search ignores `node.active`; nothing to do. |
| Field config saved | `save_resource_type_field()` [cf:1877](../../../include/config_functions.php:1877) | `afterresourcetypefieldeditsave()` :2059 — no args, old values not visible (`$GLOBALS['ref']`, `$_POST` available) | `keywords_index`/`partial_index`/`complete_index` decide whether `field_<ref>_*` exists; `type` decides `_s`/`_ss`/`_text`/dates; `field_constraint` switches `_s` ↔ `_f`+`_q` and its `query_by` mapping; `active`/`name` decide visibility. Any of these → resync every resource with nodes in that field. Core never reindexes automatically. |
| Field deleted | `delete_resource_type_field()` [rf:8426](../../../include/resource_functions.php:8426) | `after_delete_resource_type_field()` :8468 — no args, rows already gone (`$GLOBALS['affected_resources']` available in page context) | Documents keep stale `field_<ref>_*` and `populated_field_ids`; find them in Typesense (`populated_field_ids:=<ref>`) and resync. |
| Field created / copied | `create_resource_type_field()`, `admin_copy_field.php` | **none** | Nothing until resources get values. The regex schema (`field_.*_s`…) needs no schema change. |
| Resource type create/rename/delete | [admin_resource_type_edit.php](../../../pages/admin/admin_resource_type_edit.php) | **none** | Delete moves resources via `update_resource_type` (logged). |
| `$stemming`, `$partial_index_min_word_length`, `$resource_field_verbatim_keyword_regex`, `$view_title_field`, `$date_field`, `$config_separators` | `config.php` edits only; `set_config_option()` [cf:124](../../../include/config_functions.php:124) has no hook and none of these are on the system-config page | **none** | `$stemming` is schema-level (the schema currently stems `title`/`_text` unconditionally) → collection rebuild. `$view_title_field`/`$date_field` → every document. No runtime event exists; detect by snapshot diff (§6.5). |

### 3.4 Collections

Only `add_resource_to_collection()` and `remove_resource_from_collection()` fire hooks, and both fire
**before** the write with named arguments (`resourceId`, `collectionId`); a handler must be declared
with exactly those parameter names (Appendix B). The full writer list is in the collection plan's
Appendix B; the inventory adds a few:

| Event | Core path | Hook | Note |
|---|---|---|---|
| Add | `add_resource_to_collection()` [cf:341](../../../include/collections_functions.php:341) | `Addtocollectionsuccess` :454, before the delete+insert | Always delete + re-insert: re-adding resets `date_added`, `sortorder` → NULL. Callers: `collection_add_resources`, API, collections page, `copy_collection`, `update_smart_collection`, upload (:817 and the `-userref` review collection :822), edit.php, requests, csv_upload, rse_version… |
| Remove | `remove_resource_from_collection()` :493 | `Removefromcollectionsuccess` :502, before the DELETE | Fires even when not a member. |
| Reorder | `update_collection_order()` :3076 | **none** | NULL `sortorder` → 99999 for the rest. |
| "Add all results" | `add_saved_search_items()` :2431 | **none** | Shifts every `sortorder`, re-adds. |
| Empty | `remove_all_resources_from_collection()` :3610 | **none** | Also: selection cleared on new search, **logout** (`login.php:153`), upload review reset. |
| Copy with wipe | `copy_collection(…,true)` :3305 | **none** | |
| Upload-review clean-up | `collection_cleanup_inaccessible_resources()` :4968 | **none** | A delete during a read (`get_collection_resources` of `-userref`). |
| Delete collection | `delete_collection()` :875 (explicit delete of its rows) | **none** | `cleanup_anonymous_collections()` :6027 deletes only the `collection` row and orphans the memberships (cron). |
| Type / parent / public / user | `save_collection()` :1297, `update_collection_type()` :5040, `collection_set_public()` :3593, `update_collection_user()` :3843 | **none** | Membership docs carry `collection_type`. Selection collections are created as type 0 then set to 2. |
| Smart collections | `update_smart_collection()` :6496, from `search_special('!collection')` (sync, or async `exec` when `$smart_collections_async`) | via add/remove hooks per resource | `CollectionMode` already runs it before querying. |
| Soft-deleted resource | `update_archive_status` :6455 raw delete | `after_update_archive_status` | see §3.1 |
| Raw SQL | staticsync :808/:1221, action_dates :223, research copy, `database_prune`, `renumber_resources` | **none** | |

`collection_resource` has no primary or unique key, so duplicate rows are possible; the document id
`collection:resource` de-duplicates them.

### 3.5 Custom access grants and users

`resource_custom_access(resource, usergroup, user, access, user_expires)`; a row is either a group
grant or a user grant; only user grants expire; nothing purges expired rows. There is no
collection-level custom-access table: collection sharing uses `user_collection`,
`usergroup_collection` and `external_access_keys`, none of which affect resource search access. The
only "collection grants" are one-off snapshots written per resource by `open_access_to_user()`.

| Writer | Where | Hook | modified |
|---|---|---|---|
| Edit page / batch edit custom access | `save_resource_custom_access()` [rf:4073](../../../include/resource_functions.php:4073) (deletes all group rows, inserts from `$_POST`, incl. `access=2` rows) | `aftersaveresourcedata` fires afterwards, but cannot tell whether grants changed | only if the access level changed |
| Leaving access 3 | `delete_resource_custom_access_usergroups()` :6633 | `aftersaveresourcedata` | yes |
| Request approval; "grant internal access" on collection email | `open_access_to_user()` [uf:2186](../../../include/user_functions.php:2186) (sets `user_expires`) | **none** usable (`saverequest` is a pre-hook, `additional_email_collection` has no resource list) | **no** |
| Email resource with access | `open_access_to_group()` :2205 via `resolve_open_access()` | `additional_email_resource` (skipped if the send fails) | yes (`E`) |
| Request declined / back to pending | `remove_access_to_user()` :2257 | **none** | **no** |
| Resource share page | `delete_resource_custom_user_access()` [rf:6726](../../../include/resource_functions.php:6726) | **none** | yes |
| `pages/ajax/remove_custom_access.php` | raw delete | **none** | no (nothing calls it in core) |
| Copy / locked-field copy | `copy_resource` :3757 (junk `(NULL,NULL)` rows), `copy_locked_data` :6837 (duplicates, drops expiry) | `afternewresource` / edit.php only | yes |
| csv_upload | :424 | **none** | yes |
| Hard delete | :3052 | before/after delete hooks | n/a |
| User / group deleted | `save_user` (`on_delete_user($ref)`), `delete_usergroup()` | rows left in place | no — nothing to do for the index |
| Tools | `renumber_resources`, `database_prune` (its grant-prune condition never matches) | none | no |

### 3.6 Coverage summary

| Signal | Covers | Misses |
|---|---|---|
| Existing core hooks | saves, `update_field`, create/copy, upload, archive changes, hard delete, add/remove to collection (before write), related keywords, field save/delete (no args) | node rename/delete; direct node writers (API, CSV fixed-list, templates, annotations, autocomplete, vision/clip/faces); type change; `put_resource_data`; most grant writers; every bulk collection change; config |
| `resource_log` rows (⇒ `resource.modified`) | everything above that logs, plus `put_resource_data`, type change, csv, staticsync archive changes, downloads and emails | membership changes (`collection_log` only); grant changes unless the access level changed; node rename/delete; unlogged node writes (`delete_resource_nodes_multi`, tools); `remove_missing_files`; workflow plugins' raw SQL |
| `collection_log` | add/remove/reorder/empty/delete for most collection types | selection collections, research copies, staticsync, `cleanup_anonymous_collections` |
| Per-collection fingerprint (proposed in the collection plan; not used by this plan, §8) | any membership or type change, however made | nothing, for memberships |
| Rolling `sync_hash` crawl (Appendix C, not used) | any resource-document difference, however made, incl. node renames; orphans; missing docs | nothing, but with a delay of one full pass |

Note that `resource.modified` is bumped by **every** `resource_log()` call — downloads
([collections_functions.php:4628](../../../include/collections_functions.php:4628)), emails,
preview creation — and is written before the data change in several paths (archive change, hard
delete). It is a coarse "something was logged" signal, not a change timestamp.

## 4. Typesense behaviour the design relies on (live-verified on 30.2)

- **Reference fields:** the live schema has `cascade_delete: true`, `async_reference: false`.
  Deleting a resource document removes its membership and grant documents (both settings). With
  `async_reference: false` a membership whose resource document is missing is rejected per line;
  with `async_reference: true` it is accepted and joins once the resource arrives.
- **`upsert`** replaces the whole document (omitted optional fields disappear). Upsert or `emplace`
  of a referenced resource document keeps its membership and grant documents and the joins.
- **`emplace`** creates or partially merges; `PATCH /documents/<id>` merges. **`update`** on a missing
  id fails per line inside an HTTP 200.
- **Delete by filter** works on the reference field, as a single value and as an array
  (`resource_id:=[1,2]`), and on `ref:=[…]`; cascade applies.
- **Export** with `include_fields` and `filter_by` streams JSONL; a stored-only field
  (`index: false`) is exported but cannot be filtered on.
- **Schema alteration:** `PATCH /collections/<name>` adds fields (stored-only and indexed) to an
  existing collection. Metadata can also be stored on the collection (`PATCH … {"metadata":{…}}`).
- **Aliases:** searches and `filter_by` joins can be addressed by alias name (`$<alias>(…)`). A
  reference may name an alias, but it is bound to the real collection at creation time: after the
  resources alias is moved, joins from the old memberships collection fail with
  `Failed to join on …: No reference field found` (HTTP 400 → the plugin falls back to MySQL).
  Rebuilding memberships and grants against the new generation and moving all three aliases
  restores the joins.
- Per-line import results are already parsed by `typesense_search_request()` (any failed line →
  `false`). Import limit: the plugin chunks to 500 docs / 4 MB per POST.

## 5. Mechanisms and trade-offs

### 5.1 Capturing changes

Hooks are the intraday capture mechanism, and the nightly rebuild is the guarantee. The existing
hooks (§3) cover resource saves, `update_field`, create and copy, uploads, archive changes, hard
deletes, single add/remove to a collection and related keywords. The new core hooks in §6.11 cover
the paths that are silent today: every `resource_node` write, node renames and deletes,
resource-type changes, custom-access writers, the API's raw resource update and the bulk collection
operations.

What remains uncovered until the next rebuild is raw SQL: staticsync's inserts and archive updates,
the action_dates and workflow plugins' bulk archive SQL, the research-request copy, and the
maintenance and migration tools (§3.2, §3.4). Read-time validation (§5.7) turns any of those into a
freshness issue rather than a security one.

Sweeps of the resource and collection logs, a grants fingerprint and a rolling per-document hash
crawl were considered as hook-independent capture and set aside (Appendix C): with a durable queue
and a daily rebuild they would mostly re-find rows the hooks already queued.

### 5.2 Transport: how a change reaches Typesense

| Option | Freshness | Typesense down | Notes |
|---|---|---|---|
| Synchronous upsert inside each hook | Immediate | Request blocks up to the timeout; the change is lost | An upload fires ~10 hooks; collection hooks fire before the write; other plugins write nodes inside the same hook the plugin would react to |
| In-memory dirty set, flushed at request end | Immediate | Change lost until the nightly rebuild | Coalesces and defers correctly, but marks die with the process, CLI sources flush only at exit, bulk work needs the offline job queue, nothing to report |
| **Queue table + request-end flush + worker** | Immediate for the request's own rows; seconds for the rest | Rows wait and are retried; nothing lost | Durable, coalesced across requests, bulk work handled by the worker, exact catch-up for a rebuild, observable |

**Chosen: the queue table.** The hook layer is identical in the last two options (a hook calls one
mark function), so the choice is purely about what the mark function does. Typesense's sync guide
describes this table-and-scheduled-import pattern as its recommended "buffer table" approach
(Appendix D). `job_queue_add()` is not the right queue for per-object rows: one job row each,
dedupe only against pending jobs, a job user required, the runner off by default and each handler
responsible for its own final status ([job_functions.php:301-413](../../../include/job_functions.php:301)).

### 5.3 Scheduling

| Option | Facts | Verdict |
|---|---|---|
| **Cron entry every minute** running `scripts/sync.php`, which loops for ~55 s polling every 5 s | Same shape as the offline job runner RS already asks for ("a frequent scheduled task", run as the web service account); Windows Task Scheduler works because the loop is internal; no daemon to supervise | **Chosen** for the worker; a second nightly entry for the rebuild, as `reindex.php` is today |
| `hook("cron")` | The only periodic plugin hook; cadence varies per install from daily to every minute; runs under `$cron_job_time_limit` (30 min); group-restricted plugins not loaded | Not used for either the worker or the rebuild |
| Offline job queue | Needs `$offline_job_queue` and a job user; handler must set its own status; a self-rescheduling job is easy to loop | **Used only for admin-triggered full rebuilds** (§6.9), where its job log, My Jobs status and completion notification are exactly what an admin wants |
| Long-running daemon | Marginally lower latency | Foreign to how RS installs are operated; option for very large sites only |

### 5.4 Writing documents

- **Resource documents: always a full `upsert` from the shared builder.** One code path, no stale
  fields, atomic per document. Never `update` (fails silently per line on a missing document);
  `emplace` is not needed and would introduce a second document shape.
- **Memberships:** upsert `collection:resource` from the MySQL row; delete the id when the row is
  gone. Collection-scoped resync (collection plan): upsert all rows, then delete
  `collection_ref:=C && id:!=[…]`. Resource-scoped resync (soft delete): delete `resource_id:=R`,
  then re-add whatever rows still exist.
- **Grants:** per batch of resources, delete `resource_id:=[…]`, then import the current
  `access <> 2` rows; skip rows with neither user nor group.
- **Ordering:** resource documents before memberships and grants within a batch; with
  `async_reference: true` from the first generation build this stops being a requirement.

### 5.5 Deletes and references

Hard delete → delete the resource document; Typesense's cascade removes memberships and grants
(§4). Soft delete (deletion state) → the document stays with `archive=3`, which core can search
explicitly, and the memberships core removed are deleted by `resource_id:=R`. A row is always
resolved against MySQL when it is processed, so a ref that no longer exists is treated as a delete;
the "before delete" hook only has to queue the ref.

### 5.6 The nightly rebuild behind aliases

Each run builds a new generation: `<prefix>resources_g<N>`, `<prefix>resource_collection_memberships_g<N>`
(reference → `…resources_g<N>.id`), `<prefix>resource_access_grants_g<N>`, while the three aliases
that carry today's names keep serving generation N-1. Searches and join filters both resolve alias
names (§4), so the query side is unchanged.

Constraints from the live tests: a reference field binds to the real collection when it is created,
so memberships and grants must be rebuilt against the new resources collection every time and all
three aliases swapped. The swap is three calls; in the sub-second window where they disagree a
joined search returns HTTP 400, which already falls back to MySQL.

**Changes made during the build** are handled by the queue, not by dual-writing: while a build is
in progress the worker still applies each row to the live alias, then keeps the row stamped with
the generation it was applied to instead of deleting it. At the end of the build those rows are
replayed against the new generation (§6.8). Because processing always resolves against MySQL as it
is now, this covers edits the builder copied too early, resources deleted after the builder copied
them and memberships removed mid-build.

The in-place alternative in Typesense's guide (upsert everything, then delete documents whose
`last_synced_at` is older than the run) was set aside because the served index is in a mixed state
for the whole run; today's four-pass reindex additionally leaves every document partial between
passes (§1.2).

Triggers for a rebuild: the nightly schedule, `$stemming` and other schema-affecting config,
reference options (`async_reference`), a lost Typesense data directory, a version upgrade, or an
admin's request. Non-schema config such as `$view_title_field` needs only a full resync through the
queue (a `full` row), not a new collection.

### 5.7 Defence in depth: validate at read time

Hydrate already runs one MySQL query over the page's refs. Adding core's own restrictions to it
costs nothing measurable and closes the security gap for stale documents:

- append `search_filter($search, $archive, $restypes, $recent_search_daylimit, $access_override,
  $return_disk_usage, $editable_only, $access, $smartsearch)`
  ([search_functions.php:730](../../../include/search_functions.php:730)) — it is a pure function of
  the hook arguments and the user's globals and returns a `PreparedStatementQuery`;
- keep the `rca`/`rca2` joins and add `NOT (rca.resource IS NULL AND r.access=3)` for non-`v`
  users, as [do_search.php:183-197](../../../include/do_search.php:183) does;
- `r.ref > 0`.

A row the user may no longer see is dropped from the page (the page gets shorter; `total` stays as
Typesense reported) and its ref is queued for repair. This is the intended meaning of the unused
`typesense_search_validate_access` string; make it a config toggle, default on. It cannot add
resources the index lacks — that is freshness, handled by the queue and the rebuild. The group
`search_filter` node rules are not re-applied here (they are reproduced in the query by
`GroupFilterRestriction`; re-applying would need `do_search_filtering`'s joins) — optional later.

### 5.8 Plugin loading

- A group-restricted plugin's hooks only run for users in those groups
  ([plugin_functions.php:1671](../../../include/plugin_functions.php:1671)), and not at all in
  `batch/cron.php`. The parity-testing setup (plugin enabled for one group only) would therefore
  stop indexing edits made by the other group. **Indexing must be global:** set
  `disable_group_select: 1` in [typesense_search.yaml](../typesense_search.yaml) and gate *serving*
  with `$typesense_search_enabled` per user group through the group config override instead.
- CLI scripts (`boot.php`) load global plugins and their DB-stored config, so the worker and the
  build see the same settings as the web app.
- Hooks with string-keyed argument arrays arrive as **named arguments** on PHP 8.2
  (`resourceId`, `collectionId`, `resource_ref`); handler parameter names must match (Appendix B).

### 5.9 Serving while bulk work is pending

Searches run against the index as it is, so a record the worker has not reached yet still matches
on its old data. For metadata changes that means wrong hits in both directions until the row is
processed; for access and archive changes read-time validation hides the rows but the reported
total still counts them; for memberships a collection view shows its old contents.

| Option | Effect | Verdict |
|---|---|---|
| **1. Fall back to MySQL while bulk rows are pending** | Every search exactly right for the minutes the bulk work takes; the site runs on MySQL meanwhile, as it does today with the plugin off | **Chosen.** Global, for resource-document and membership bulk work alike (a node rename can affect any keyword search) |
| 2. Exclude pending refs from the Typesense query | Removes false positives only | Not taken |
| **3. Make the bulk path fast** | Shortens the window: overlap building and uploading, larger batches, more worker processes within Typesense's concurrency limit | **Chosen**, in that order, after measuring the rebuild's rate on the target system |

Every bulk kind is applied intraday under the fallback; nothing waits for the nightly rebuild
(decided 23 Sep: a spell on MySQL search during a bulk change is acceptable, stale results are not).

## 6. Recommended design

### 6.1 Components

| Component | Responsibility |
|---|---|
| `typesense_search_build_resource_documents(array $refs): array` | The single builder: core columns + title + `nodes[]`/`populated_field_ids[]` from all nodes + `field_<ref>_*` from indexed fields, for up to 500 refs per SQL round, using live config for the title and date fields. Resources with no nodes still get `[0]` arrays. Never for `ref <= 0`. Used by the rebuild and by the worker. |
| `typesense_search_build_membership_documents(...)`, `..._grant_documents(...)` | Same idea for the other two collections (per collection, per resource, or per ref range). |
| `typesense_search_mark_dirty(string $kind, int $ref, int $ref2 = 0, int $priority): void` | The only thing hooks call: `INSERT … ON DUPLICATE KEY UPDATE` into the queue table, keeping the lower priority; remembers the row for the request-end flush; registers the shutdown function on first use. No Typesense I/O. |
| Hooks ([hooks/all.php](../hooks/all.php)) | Map events to `typesense_search_mark_dirty()` calls (§6.4). |
| Request-end flush | Shutdown function: processes the request's own rows before the response is sent (§6.5). |
| Worker `scripts/sync.php` | Started by cron every minute; loops for ~55 s (§6.6). |
| Rebuild `scripts/reindex.php` | Full build into a new generation and alias swap (§6.8); also the code behind the admin-triggered job. |
| Job handler `job_handlers/typesense_search_reindex.php` | Runs the rebuild as an offline job; fails if the build lock is held (§6.9). |
| Name resolver `typesense_search_collection_name(string $suffix, ?string $generation = null)` | Replaces the six inline `$typesense_search_collection_prefix . '…'` concatenations in the query side and the indexer. Returns the alias, or the real name of a generation for the build and the catch-up. |
| State (sysvar `typesense_search_state`, JSON) | `ready`, `live_generation`, `building_generation`, `bulk_pending`, `breaker_open_until`, `last_worker_run`, `last_build`, counters. Sysvars are loaded at boot and query-cached, so the search hook reads them for free. |
| Setup page | Queue depth, oldest row, parked rows, bulk flag, breaker state, last worker run, live generation and last build; buttons for "queue full resync" and "rebuild now" (§6.10). |

### 6.2 Queue table (`plugins/typesense_search/dbstruct/table_typesense_search_sync_queue.txt`)

Created automatically for active plugins by `check_db_structs()`
([database_functions.php:879](../../../include/database_functions.php:879)).

| Column | Purpose |
|---|---|
| `ref` int auto | |
| `kind` varchar(24) | `resource`, `grants`, `membership`, `memberships_of_resource`, `collection`, `node`, `field`, `field_deleted`, `full` |
| `object_ref` int, `object_ref2` int (default 0) | resource / collection / node / field ref; `membership` uses (collection, resource) |
| `priority` tinyint | see §6.3 |
| `queued` datetime, `not_before` datetime NULL | FIFO within a priority; backoff |
| `attempts` tinyint, `last_error` varchar(255) | retries, then parked |
| `claimed` datetime NULL, `claimed_by` varchar(64) NULL | claim-then-select, so the flush and workers never double-process; a claim older than the worker's own lifetime is treated as abandoned |
| `applied_generation` int NULL | set instead of deleting the row while a rebuild is in progress (§6.8) |
| `cursor` int NULL | bulk parent rows: the last ref processed, so a restart resumes |
| UNIQUE (`kind`, `object_ref`, `object_ref2`) | coalescing across requests |
| INDEX (`priority`, `queued`), INDEX (`claimed`) | claiming |

### 6.3 Priorities

| Priority | Name | What goes here | Inserted by | Handling |
|---|---|---|---|---|
| 0 | Visibility | Access level, archive state, grant and deletion changes, and the resource-scoped membership removal after a soft delete. Normally applied by the request-end flush; these rows remain only when the flush did not finish (Typesense down, request died, over the inline cap). | Hooks | Claimed first on every loop: resources, then grants, then memberships |
| 1 | Edit | Ordinary per-resource changes from a request: metadata saves, uploads, single collection adds and removes. Same origin as above. | Hooks | Next, FIFO |
| 2 | Repair | Refs whose document is known stale: dropped by read-time validation, mismatched in the rebuild's verification, or an admin's "resync now". | Hydrate, rebuild, setup page | After edits; the document is already hidden or already wrong |
| 3 | Bulk | Parent rows for a node rename or delete, a field-config change, a deleted field, a collection resync, an oversized batch edit, an admin's "full resync", and the resources streamed from them. | Hooks, request flush when over the cap | Remaining capacity, with a reserved floor; sets `bulk_pending` while any remain; walked in ref order with `cursor` |
| 9 | Parked | Rows that exhausted their retries. | Worker | Not processed, not counted for `bulk_pending`, listed on the setup page |

Rules:
- **Coalescing keeps the most urgent priority.** A resource queued for bulk work at 3 that a user
  then edits becomes one row at 1; a later bulk expansion never demotes a pending edit.
- **Strict order with a floor for bulk.** Priorities 0–2 are normally empty within one loop; each
  loop still reserves a share (say a fifth) of its capacity for priority 3, so a constant trickle of
  edits on a busy site cannot hold a node rename open indefinitely.
- **Retries back off, then park:** 1, 5, 15 and 60 minutes, keeping the priority, then priority 9
  with the last error.
- **HTTP 503 (lagging / not ready) is not a failure.** The worker pauses bulk processing and retries
  after 10–60 s with jitter, per Typesense's guidance; the rows' `attempts` are not incremented.
- **No priority for who made the change, and no ageing.** An API edit and a web edit are treated
  alike; the floor already prevents starvation, and ageing would blur a stale confidential flag
  with a stale caption.

### 6.4 Event → queue row

| Event (hook) | Rows |
|---|---|
| `resourcecreate`, `afternewresource`, `afterpluploadfile`, `uploadfilesuccess`, `afterpreviewcreation`, `after_update_resource` | `resource R` (1); `afternewresource` also `grants R` (0), since copies carry grants |
| `update_field` | `resource R` (1) |
| `aftersaveresourcedata` (int or array first argument), `saveextraresourcedata($list)` | `resource R…` (1) and `grants R…` (0); the batch-edit early return at [rf:2356](../../../include/resource_functions.php:2356) skips both hooks after the writes — corrected by the rebuild |
| `after_update_archive_status($refs, $archive, …)` | `resource R…` (0); when `$archive == $resource_deletion_state` and `$remove_deleted_resources_from_collections`: `memberships_of_resource R…` (0) |
| `beforedeleteresourcefromdb($ref)` | `resource R` (0) — resolved as a delete when the row is gone |
| `Addtocollectionsuccess(resourceId, collectionId)`, `Removefromcollectionsuccess` | `membership (C, R)` (1) — applied after the write by the flush or the worker |
| `after_save_related_keywords` | synonym sync, direct (small) |
| `afterresourcetypefieldeditsave` (`$GLOBALS['ref']`) | `field F` (3) — the worker compares the field's index-relevant columns with a stored snapshot and, if they changed, streams every resource with nodes in F |
| `after_delete_resource_type_field` | `field_deleted F` (3) — the worker exports `populated_field_ids:=F` from Typesense and streams those refs |
| New core hooks (§6.11) | `node N` (3), `resource R` (0/1), `grants R` (0), `collection C` (3) |
| Read-time validation dropped a row | `resource R` (2) |
| Setup page / resource page actions | `resource R` (2), `collection C` (3), `full` (3) |

### 6.5 Request-end flush

- Registered by the first `typesense_search_mark_dirty()` call in a request. It runs as a shutdown
  function **before** the response is sent (no `fastcgi_finish_request`), so a redirect to the
  search page already sees the change; the cost is a few milliseconds per save.
- It claims exactly the rows its own request inserted, processes them in kind order with the same
  code as the worker, and deletes them on success (or stamps them during a rebuild, §6.8).
- Bounded by `$typesense_search_flush_max_rows` (a few hundred). A request that inserted more
  leaves its rows for the worker and sets `bulk_pending`.
- Skipped when the breaker is open, when `ready` is false, or when the request is the worker or
  the rebuild itself. CLI processes (staticsync, the CSV job) do not flush; their rows are picked
  up by the worker within seconds.
- A failed flush opens the breaker and leaves the rows in place; nothing else happens in the
  request.

### 6.6 Worker (`scripts/sync.php`, cron every minute)

1. Take the process lock; treat a lock older than a few minutes as stale (the stock
   `$process_locks_max_seconds` is four hours, too long for a worker that died). Exit if held.
2. **Health:** GET each alias; check the collection metadata's generation against the state. A
   missing alias → `ready = false`, admin notification, stop. If the breaker is open, probe
   `/health` and close it on success.
3. **Loop for ~55 s**, so the next cron start finds the lock free:
   - claim rows in priority order (§6.3), N per kind per loop; kind order `node/field →
     resource → grants → memberships_of_resource → membership → collection`;
   - `resource` batch: one builder call → one import (`upsert`); refs missing from MySQL → one
     delete by `ref:=[…]`;
   - bulk parents (`node`, `field`, `field_deleted`, `collection`, `full`): resolve the affected
     refs in ref order from `cursor`, stream them through the builder in batches, advance
     `cursor` after each batch, delete the parent when done;
   - delete processed rows, or stamp them with `applied_generation` while a rebuild is running;
   - on failure: `attempts`, `not_before`, `last_error`, unclaim; on 503: pause bulk work and back
     off without counting an attempt;
   - set `bulk_pending` when any priority-3 row exists, clear it when none (parked rows excluded);
   - when idle, sleep 5 s and poll again (Typesense's recommended cadence for a buffer table).
4. Update `last_worker_run` and counters; release the lock.

**Speed (option 3 of §5.9), in the order to apply after measuring the rebuild's rate:** overlap
building batch N+1 with uploading batch N using curl's multi interface, with two or three imports in
flight; raise the 500-document / 4 MB chunk cap; allow several worker processes, each claiming a
disjoint slice through `claimed_by`. Import concurrency is capped by
`$typesense_search_import_concurrency` (default 1) and must never exceed the Typesense server's CPU
cores minus two (Appendix D). Every speed-up here also shortens the nightly build, which uses the
same builder and import code.

### 6.7 Serving rules

The search hook returns `false` (core runs its MySQL search) when any of these hold:
- `ready` is false — no complete generation is recorded;
- `bulk_pending` is set — bulk work is in flight (§5.9); the badge shows "Standard search" and the
  setup page shows why and how many rows remain;
- the breaker is open — a recent transport failure.

Only the rebuild may create collections; the write path never does. Read-time validation (§5.7)
applies to every served page. Connect, read and write timeouts are separate settings (defaults 2 s,
5 s, 60 s); any transport failure opens the breaker for 60 s.

### 6.8 Full rebuild (`scripts/reindex.php`, nightly cron and the admin job)

1. Take the build lock (shared with the job handler). If held: log and exit.
2. Record `building_generation = N+1` in the state; create the three `_g<N+1>` collections
   (schema gated on `$stemming`; `async_reference: true`; `cascade_delete: true`; metadata
   `{generation, rs_version, started}`).
3. Resources in ref ranges through the shared builder (`upsert`), then memberships (keyset by
   (collection, resource), NULL handling as today), then grants (keyset by `(resource, user,
   usergroup)`, skipping `(NULL, NULL)` rows), then synonyms. The worker keeps running throughout
   and stamps every row it processes with `applied_generation = N` instead of deleting it.
4. **Catch-up:** claim every row stamped `N` and process it against the `_g<N+1>` collections by
   their real names, reading MySQL now; repeat until the remaining set is tiny.
5. Pause the worker (a flag it checks between batches), run one final catch-up, verify
   (`num_documents` vs MySQL counts within tolerance; a sample join through the new names), set the
   collection metadata `complete: true`, swap the three aliases, set `live_generation = N+1`,
   clear `building_generation`, set `ready = true`, resume the worker. Any row still stamped `N`
   is simply reprocessed by the worker against the alias, which now points at `N+1`, and deleted —
   the delete rule is "only when `applied_generation` equals the live generation".
6. Delete `_g<N>` once the swap is verified. No stale generation is kept: rollback is to clear
   `ready` (searches fall back to MySQL), fix the cause and rebuild.
7. If verification fails: no swap, the old generation keeps serving, delete the new collections,
   notify an admin.

The one-off migration from today's un-aliased names: build `_g1`, delete the three old collections,
create the aliases with the old names. A short window without an alias → HTTP 404 → fallback.

### 6.9 Admin-triggered rebuild (offline job)

- Registered the way the clip plugin does it: an `addtriggerablejob` hook entry, a page under
  `plugins/typesense_search/pages/offline_jobs/` that calls `job_queue_add('typesense_search_reindex', …)`,
  and the handler `plugins/typesense_search/job_handlers/typesense_search_reindex.php`. Admins launch
  it from Manage Jobs; the setup page's "Rebuild now" button queues the same job.
- The handler runs the identical generation build; the job framework adds progress lines in the
  job log, a status under My Jobs, a completion notification, and runs as the admin who triggered
  it. It sets its own final status (`STATUS_COMPLETE` / `STATUS_ERROR`) and honours
  `$offline_job_delete_completed`.
- **If the build lock is held** (the nightly build is running) the job is set to error with the
  failure text "A scheduled reindex started at <time> is still running; try again once it has
  finished". No rescheduling. An errored job does not block a new one (dedupe only looks at
  pending jobs), so the admin simply triggers it again later. Conversely, the nightly cron build
  exits with a log line if a job holds the lock, and the next night's run covers it.
- The job code derived from the job data means a second pending "rebuild all" is refused by
  `job_queue_add`, so the button is safe to click twice.

### 6.10 Front-end triggers and observability

- **"Resync now"** on a resource's page for admins: inserts a priority-2 row.
- **Setup page:** queue depth by priority, oldest unprocessed row, parked rows with their errors,
  `bulk_pending` with rows remaining, breaker state, last worker run, live generation and last
  build; buttons "Queue full resync" (a `full` row) and "Rebuild now" (the offline job).
- **Warnings** (RS system notifications to admins): the worker has not run for 5 minutes; the
  oldest row is older than `$typesense_search_max_lag_minutes`; `bulk_pending` set longer than
  `$typesense_search_max_bulk_minutes`; a rebuild failed verification; the alias is missing.

### 6.11 Core hooks to add (Phase 2)

Additive, plain arguments (no string keys, to avoid the named-argument trap), one small PR. They
shorten delays; correctness is guaranteed by the rebuild either way.

| Hook | Where | Args |
|---|---|---|
| `after_resource_nodes_changed` | end of `add_resource_nodes()`, `add_resource_nodes_multi()`, `delete_resource_nodes()`, `delete_resource_nodes_multi()`, `delete_all_resource_nodes()`, `copy_resource_nodes()` ([node_functions.php:1208-1581](../../../include/node_functions.php:1208)). Fires inside the save transaction; listeners only mark rows and act at request end. Covers CSV fixed-list columns, annotations, fixed-list autocomplete, metadata templates, the API node calls and the vision, clip and faces plugins in one place. | `$resources[]` |
| `after_set_node` | `set_node()` after the UPDATE, when name or parent changed ([:104-131](../../../include/node_functions.php:104)); also covers in-place renames of single-use text nodes | `$node, $field, $old_name, $new_name` |
| `beforenodedelete` / `afternodedelete` | `delete_node()` around the deletes (:182-185) — the names the plugin already expects | `$node` |
| `after_update_resource_type` | `update_resource_type()` after the UPDATE ([rf:4163](../../../include/resource_functions.php:4163)) | `$resource, $old_type, $new_type` |
| `after_resource_custom_access_change` | end of `save_resource_custom_access()`, `delete_resource_custom_access_usergroups()`, `open_access_to_user()`, `open_access_to_group()`, `remove_access_to_user()`, `delete_resource_custom_user_access()`, `pages/ajax/remove_custom_access.php` | `$resource` |
| `after_put_resource_data` | `put_resource_data()` after the UPDATE (:540) | `$resource` |
| Collection bulk hooks (the collection plan's list) | after the writes in `update_collection_order`, `delete_collection`, `remove_all_resources_from_collection`, `add_saved_search_items`, `copy_collection`, `save_collection`, `update_collection_type`, `collection_set_public`, `collection_cleanup_inaccessible_resources` | `$collection` |
| `afterresourcetypefieldeditsave` (existing), `after_delete_resource_type_field` (existing) | pass `$ref, $existingfield, $new` / `$ref, $affected_resources` instead of nothing | optional; the snapshot diff and `populated_field_ids` export work without them |

Not proposed: changing the timing of `Addtocollectionsuccess` / `Removefromcollectionsuccess`.

### 6.12 Config

`$typesense_search_connect_timeout` (2), `$typesense_search_read_timeout` (5),
`$typesense_search_write_timeout` (60), `$typesense_search_flush_max_rows` (300),
`$typesense_search_validate_access` (true), `$typesense_search_import_concurrency` (1),
`$typesense_search_import_max_docs` (500) / `_max_bytes` (4 MB) (existing),
`$typesense_search_bulk_fallback` (true),
`$typesense_search_max_lag_minutes` (10), `$typesense_search_max_bulk_minutes` (30),
`$typesense_search_job_user` (fallback admin for
notifications from cron-started builds).

## 7. Phases

### Phase 0 — correct on-save sync with the existing hooks, and a safe rebuild (plugin only)

1. Shared builder; delete `typesense_search_get_document_data()` and the legacy shape; fix the
   collection-name, `$batch`/`$payload` and synonym-collection bugs; the grants keyset and junk rows.
2. Queue table, `typesense_search_mark_dirty()`, the request-end flush, `scripts/sync.php` with the
   basic loop (priorities 0–2, retries, parking), the per-minute cron entry documented.
3. Hooks switched to marking: `aftersaveresourcedata` (int or array), `update_field`,
   `resourcecreate`, `afternewresource`, `afterpluploadfile`, `afterpreviewcreation`,
   `after_update_resource`, `after_update_archive_status` (with the soft-delete membership
   removal), `beforedeleteresourcefromdb`, `Addtocollectionsuccess` / `Removefromcollectionsuccess`
   (named args), grants from `aftersaveresourcedata`. Remove the two dead node hooks (they return
   in Phase 2 under the same names).
4. Guards: ready flag, breaker, separate timeouts, never create collections outside the rebuild.
5. Read-time validation (§5.7) with read-repair rows.
6. `disable_group_select: 1`; document the per-group `$typesense_search_enabled` override.
7. `reindex.php` rewritten as the generation build with the alias swap, stamped-row catch-up,
   verification and the one-off migration from the current names; nightly cron entry documented.

Outcome: edits, uploads, archive/access changes, deletes, grant edits and single collection
changes reach the index before the next page load; anything the flush could not apply is applied
by the worker within seconds; stale documents can no longer leak resources; a lost index falls
back instead of serving nothing; the nightly rebuild corrects everything else.

### Phase 1 — bulk work, serving rules, admin tooling

1. Bulk parent rows with `cursor` (`node`, `field`, `field_deleted`, `collection`, `full`), the
   field-config snapshot diff and the priority-3 floor.
2. `bulk_pending` and the MySQL fallback while it is set; 503 handling; pipelined imports and the
   concurrency setting after measuring the rebuild's rate.
3. Offline job for admin-triggered rebuilds with fail-if-locked (§6.9); "Resync now"; setup-page
   stats and warnings.
4. Bring the collection bulk hooks and the node-layer hook forward from Phase 2 if the core PR is
   ready; until then bulk collection changes and hook-less node writes wait for the nightly rebuild.

### Phase 2 — core hooks

The hooks of §6.11 in one core PR, and the plugin handlers that mark `node`, `resource`, `grants`
and `collection` rows. Outcome: node renames, type changes, grant writers, hook-less node writers
and bulk collection changes are picked up at once instead of at the next rebuild.

### Phase 3 — verification and tuning

Extend the planned API parity harness (architecture doc, *Planned: Typesense-vs-core parity
testing*):
- **Freshness cases:** edit a field via `update_field`, change access, move archive state, add and
  remove from a collection, grant and revoke custom access — each followed immediately by the same
  search as both users; expect identical results.
- **Bulk-window cases:** rename a node used by thousands of resources; expect searches to be served
  by MySQL until the worker finishes, then identical results from Typesense.
- **Hook-less cases:** reorder a collection and rename a node with raw SQL from the CLI; expect
  identical results after the nightly rebuild.
- **Outage case:** stop Typesense, make edits, restart; expect the queue to drain and the parity run
  to pass, and searches to have fallen back meanwhile (never empty results).
- **Rebuild case:** edit resources during a build; expect them correct in the new generation.
- Builder unit test: the document for a resource from `build_resource_documents([ref])` equals the
  one the rebuild writes (same function — the test guards against a future fork).
- Measure the rebuild's and the worker's rates and set the concurrency and chunk settings from them.

## 8. Compatibility with the `!collection` parity plan

- Shared: the membership document id (`collection:resource`), the NULL handling, and the
  per-collection resync (upsert then `collection_ref:=C && id:!=[…]`), which is this plan's
  `collection C` queue row.
- **Superseded (decided 23 Sep):** the collection plan's query-time fingerprint check, its
  `typesense_search_collection_sync` table, its read-repair and its cron fingerprint sweep.
  Collection contents are kept fresh the same way as resource documents: the two existing hooks
  (applied after the write by the flush), the collection bulk hooks (§6.11), and the nightly
  rebuild for anything written by raw SQL. The other session should drop its Phase 0 step 9 and
  Phase 1 read-repair.
- Their in-request "resync marked collections just before querying" (so the collection bar shows a
  just-added resource in the same request) can stay as an in-request step; it complements the
  request-end flush.
- Both plans need one rebuild (their NULL `sortorder` docs, this plan's `async_reference`): it is
  the first generation build.
- Both use `Addtocollectionsuccess` / `Removefromcollectionsuccess` with the same named-argument
  signature; the handler should exist once and mark rows.
- The name resolver and aliases keep their join expressions unchanged
  (`$<prefix>resource_collection_memberships(...)`).

## 9. Decisions

### Taken (23 Sep 2026)

1. Nightly full rebuild into generation collections behind Typesense aliases; the previous
   generation is deleted once the swap is verified (rollback = fall back to MySQL and rebuild).
2. Intraday changes through hooks into a queue table; the request flushes its own rows; a
   per-minute cron worker with an internal 5-second polling loop drains the rest.
3. Mid-build changes handled by stamped queue rows and a catch-up before the swap, not by
   dual-writing.
4. While bulk rows are pending, searches are served by MySQL (option 1), and the bulk path is made
   fast by pipelining, larger batches and bounded parallelism (option 3).
5. Offline jobs only for admin-triggered full rebuilds; a job started while the nightly build is
   running fails with a message; no rescheduling; the cron build likewise exits if a job holds the
   lock.
6. New core hooks are acceptable; the node-layer hook is the priority.
7. Read-time validation stays, default on; the plugin becomes global with per-group enable.
8. The request flushes its own rows **before the response is sent** (a shutdown function, no
   `fastcgi_finish_request`), with a short write timeout of its own. No core change is needed.
9. No bulk change waits for the nightly rebuild: every bulk kind is applied intraday, with searches
   served by MySQL while it runs. There is no deferred priority.
10. Resources in the deletion state stay in the index with their archive value, so the advanced
    search page's deleted-state option is served like any other archive state.
11. The rebuild time is configurable: it is the scheduled-task entry the administrator sets, which
    the plugin documents and reports (last run, last result) on its setup page.
12. Import concurrency, chunk size and worker count ship with safe defaults (one import in flight,
    500 documents / 4 MB, one worker, pipelining on), are all configurable, and are tuned from the
    rebuild's own timing report on the target system. On a 503 the worker halves its concurrency
    as well as backing off.
13. The collection plan's query-time fingerprint check is not used. Collection contents follow the
    same rules as resource documents: hooks during the day, the nightly rebuild for raw SQL.

### Still open

1. **Validation strictness:** drop stale rows and repair (proposed) vs fall back for the whole search
   when any row is dropped.
2. **Group override:** confirm the group config override of `$typesense_search_enabled` is applied
   before the search hook runs.

## Appendix A — Live experiments (Typesense 30.2, scratch collections, all cleaned up)

| # | Test | Result |
|---|---|---|
| E3 | Create a collection whose reference names an **alias** | Accepted; bound to the real collection (errors name `synctest_res_v1`) |
| E6 | Import a membership for a missing resource, `async_reference: false` / `true` | `400 Referenced document having id: 99 not found` per line / accepted |
| E7 | Join `$synctest_mem(...)` searching via the real name and via the alias | Both HTTP 200, same hits |
| E8 | `PATCH /documents/1 {"access":2}` | Other fields kept |
| E9a | Import `action=update` for a missing id | `{"code":404,"error":"Could not find a document with id: 77","success":false}` inside HTTP 200 |
| E9b | Import `action=emplace` with a partial document | Merged, other fields kept |
| E10 | `upsert` without a previously present optional field | Field gone (full replace) |
| E11 | Delete a resource document | Membership docs referencing it gone (sync and async collections) |
| E12 | `DELETE /documents?filter_by=resource_id:=2` on the reference field | `num_deleted: 1` |
| E13 | Async membership imported before its resource, resource added later | Join returns it |
| E14 | Move the resources alias to a new generation | Joins from memberships built against the old one → `400 Failed to join on …: No reference field found` |
| E15 | Delete the referenced collection | Allowed; referencing collection survives, joins fail |
| A2 | Join addressed by an alias of the memberships collection | Works |
| B1/B2 | `upsert` / `emplace` of a referenced resource document | Membership docs and joins intact |
| C1/C2 | `export?include_fields=id,ref`, `export?filter_by=…` | JSONL as expected |
| D2 | New memberships generation referencing the new resources generation + both aliases moved | Joins work again |
| E1 | `PATCH /collections/x {"metadata":{…}}` | Stored and returned |
| S1–S3 | Stored-only `sync_hash` (`index:false`) | Exported; `Cannot filter on non-indexed field` |
| S5/S6 | Delete by array filter `resource_id:=[1,2]`, `ref:=[2,3]` | Works; cascade removes grants |
| P1–P4 | `PATCH /collections/x {"fields":[…]}` adding stored-only and indexed fields to a live collection | HTTP 200; `emplace` then export shows the new field |

## Appendix B — Hook argument gotchas

`hook()` passes `$params` with `call_user_func_array`, so string keys become **named arguments** on
PHP 8.2 (`composer.json` requires ^8.2). Handler parameter names must match exactly:

| Hook | Parameter names |
|---|---|
| `Addtocollectionsuccess`, `Removefromcollectionsuccess` | `$resourceId, $collectionId` |
| `uploadfilesuccess` | `$resource_ref` |
| `after_update_resource` | `$resourceId` |
| `afterdeleteresource` | none — capture the ref in `beforedeleteresourcefromdb` |
| `after_update_archive_status` | `$resources` is always an array; fires even when the state did not change |
| `aftersaveresourcedata` | first argument is `$ref` (int) from `save_resource_data` and `$list` (array) from `save_resource_data_multi` |
| `afterresourcetypefieldeditsave`, `after_delete_resource_type_field` | none; use `$GLOBALS['ref']` (and `$GLOBALS['affected_resources']` for the delete, page context only) |

`$hook_cache` is per process (reset at boot), so new hook functions need no cache clearing. Only
`hooks/all.php`, `hooks/<page>.php` and `api/api_bindings.php` are loaded automatically; the
plugin's `include/*.php` must be `include_once`d from `hooks/all.php`, as it is now.

## Appendix C — Options considered and not taken

| Option | Why it was set aside |
|---|---|
| **In-memory dirty set** instead of a queue table (hooks mark refs in a PHP array, a shutdown function flushes) | Same hook layer and builder, fewer moving parts, but marks are lost when Typesense is down or PHP dies, CLI sources only flush at process exit, bulk work needs the offline job queue (a job user and `$offline_job_queue` on), there is nothing to report on the setup page, and mid-build changes can only be recovered from the resource and collection logs, which miss unlogged writes. The queue table costs one table and one cron entry and removes all of that. |
| **Dual-write** to the live and the building generation during a rebuild | Works, but has an ordering hazard (a builder batch that read MySQL before an edit and wrote after it overwrites the worker's newer document) that needs a catch-up anyway. Stamped queue rows give an exact catch-up with a single write path. |
| **In-place rebuild** with a `last_synced_at` stamp and a delete of stale documents afterwards (the alternative in Typesense's sync guide) | Endorsed by the guide, but the live index is in a mixed state for the whole run and today's four-pass reindex leaves every document partial between passes. Aliases keep the served index consistent throughout. |
| **Log watermarks** (`resource_log.ref`, `collection_log.ref`) as a periodic sweep | Useful as a catch-up in the in-memory design; with a durable queue nothing is lost, so the sweep would only re-find rows the hooks already queued. Misses unlogged writes and grant changes anyway. |
| **Grants fingerprint** (per-resource `COUNT, BIT_XOR(CRC32(…))` vs a grants export) | Cheap, but the new custom-access hook plus the nightly rebuild cover the same ground. Keep in mind if grant drift is ever observed between rebuilds. |
| **Per-collection fingerprint check at query time** (the collection plan's option B: compare `COUNT(*), BIT_XOR(CRC32(resource:sortorder:date_added))` and the type with a stored value on every collection search, fall back on mismatch) | Correct whatever wrote the rows, but once the collection hooks exist it buys only intraday cover for raw-SQL collection writers, at one aggregate query per collection search. The same trade was decided the other way for resource documents (decided 23 Sep). |
| **Rolling `sync_hash` crawl** (a stored-only hash on every document, compared with MySQL in ref ranges; verified: a stored-only field is exported and can be added by schema PATCH) | The only intraday cover for raw-SQL writers and node renames without hooks, but the nightly rebuild bounds that to one day and the crawl adds a stored field, a cursor and a permanent background load. Reconsider only if daily correction proves too slow for some writer. |
| **Exclude dirty refs** from Typesense queries while bulk work is pending | Removes false positives but not missing results; the MySQL fallback is exact and simpler. |
| **Bulk work through the offline job queue** | Needs a job user and the queue enabled, and a self-rescheduling job is easy to loop. The worker handles bulk rows itself. |
| **Rescheduling** an admin-triggered rebuild that finds the nightly one running | Not needed; the job fails with a clear message and can be triggered again. |
| **Moving the two collection hooks after the write** | Other plugins may depend on the current timing; the request-end flush already runs after the write. |

## Appendix D — External guidance consulted

**Typesense** ([Syncing data into Typesense](https://typesense.org/docs/guide/syncing-data-into-typesense.html),
[Collection alias](https://typesense.org/docs/latest/api/collection-alias.html),
[Documents API](https://typesense.org/docs/latest/api/documents.html)):
- The "buffer table" pattern is described as the way to handle changes: insert into a table
  without waiting for indexing, then bulk-import from it on a schedule of every 5 to 10 seconds.
  ORM-hook and change-data-capture queues are given the same 5-second cadence; a periodic
  `updated_at` sync every 30 seconds. The single-document API is for edits that must appear
  immediately; bulk import "is much more performant and uses less CPU capacity".
- Concurrent bulk imports "should not exceed N-2, where N is the number of CPU cores" of the
  Typesense server.
- Batch size is a client-side concern; leave the server-side `batch_size` (40) alone.
- HTTP 503 "lagging" / "not ready" means writes are backing up: retry after 10 to 60 seconds with
  jitter; avoid retry storms; the server thresholds are `healthy-read-lag` / `healthy-write-lag`.
  Increase client timeouts for large imports (the guide goes as far as 60 minutes).
- Zero-downtime re-indexing: versioned collections plus an alias, switched when the new one is
  complete; delete the old collection after confirming. The alias page documents no caveat about
  reference fields; the binding behaviour in §4 was found by the live tests.
- Deletions: track deleted ids or a soft-delete flag in the source. This plan resolves each row
  against MySQL when it is processed instead; `ignore_not_found=true` on delete-by-filter avoids
  spurious errors.

**ResourceSpace** ([Cron](https://www.resourcespace.com/knowledge-base/systemadmin/cron),
`include/config.default.php` lines 3165–3172):
- `batch/cron.php` should run "at least once daily", or "every 15 minutes, or even every minute"
  when action emails or file-integrity checks are on; the example crontab runs as `www-data` with
  `nice ionice`; a Windows Task Scheduler equivalent is given; the account must match the web
  application pool user.
- When the offline job queue is enabled "a frequent scheduled task must be created on the server
  to run pages/tools/offline_jobs.php", "as the web service account to avoid file permission
  issues"; a run keeps going until the queue is empty.
- Cron runs under `$cron_job_time_limit` (30 minutes), which a full build can exceed, so the
  nightly rebuild is its own scheduled entry, not a `hook("cron")` listener.
