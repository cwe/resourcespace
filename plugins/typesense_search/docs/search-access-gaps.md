# Search results used for access decisions: plugin coverage

Points for discussion. Core uses `do_search()` not only to list results but to decide what a user may see, share,
request or edit. This note lists those uses, what the plugin serves for each, and where the two can disagree. It
follows the review ([typesense-search-review.md](typesense-search-review.md)) and the catalogue
([core-search-catalogue.md](core-search-catalogue.md)).

Nearly every gap comes from one design fact rather than from the search forms: the plugin decides *which*
resources are in a result from the index, then fetches the rows from MySQL with only `ref IN (...)` as the
condition ([`functions.php:260`](../include/typesense_search_functions.php:260)). Core's permission filter is not
re-applied at read time. The row columns are fresh; membership is not.

## Where core uses a search as the gate

| Use | Core | Plugin |
|---|---|---|
| Resource access for a user with a search filter | `get_resource_access()` runs `!resource<ref>` with all workflow states and denies access if the search is empty ([`resource_functions.php:5207`](../../../include/resource_functions.php:5207)) | Served by the single-resource mode from the index (review E2) |
| Sharing a collection | The share page refuses unless `!collection<ref>` returns every member ([`collection_share.php:171`](../../../pages/collection_share.php:171)); `collection_min_access()` and `is_collection_approved()` decide restricted-share and approval rules from the same search ([`collections_functions.php:3544`](../../../include/collections_functions.php:3544)) | Served by the collection mode from indexed membership and indexed access |
| Resource requests | A request copies the collection and silently drops any resource the `!collection` search does not return ([`request_functions.php:292`](../../../include/request_functions.php:292)) | Same |
| Edit gates | Batch edit, "edit selected" and `allow_multi_edit()` compare an all-resources search with an editable-only search ([`edit.php:309`](../../../pages/edit.php:309), [`render_functions.php:3615`](../../../include/render_functions.php:3615), [`collections_functions.php:2637`](../../../include/collections_functions.php:2637)) | The editable-only search always goes to core; the all-resources search goes to the plugin |
| Submit pending | `!contributions<user>` in state -2 lists what the user may submit ([`user_action.php:16`](../../../pages/ajax/user_action.php:16)) | Served by the contributions mode |
| Selection collection | `!collection<selection>` decides what is cleared from the selection ([`search.php:709`](../../../pages/search.php:709)) | Not indexed; falls back to core, or empty in Typesense-only mode |

## The gaps

1. **No read-time validation.** A resource whose workflow state moved to a z-blocked state, whose access became
   confidential, whose custom-access grant was revoked, whose search-filter node changed, or whose type became a
   T-blocked type since the last reindex still comes back, with its title and preview, until the next reindex.
   Core never returns it. Because the save hook gets a 404 (review E1), the window is the full reindex interval.
   It exposes existence and the display fields, not the file: view and download still call `get_resource_access()`
   against the database.
2. **The share gate inherits that.** With such a resource still in the index, the member count and the search count
   agree, so the share is allowed where core blocks it. In the other direction, a resource added since the reindex
   blocks the share and is dropped from request copies: safe, but wrong.
3. **`ignore_filters` is misread.** Core uses it only to stop converting field terms into dates, ranges and nodes
   ([`do_search_keywords.php:128`](../../../include/do_search_keywords.php:128)). The plugin's standard restriction
   switches itself off for it ([`standard.php:6`](../include/restrictions/standard.php:6)), dropping resource
   types, archive states, the z and T permissions, pending-state hiding and the created-by filter. No core caller
   passes it today; the only caller is the openai_gpt admin page, which also sets `access_override`. It is a latent
   hole for any plugin or API code that passes it.
4. **Mixed-engine comparisons.** The edit gates can misjudge in either direction whenever the plugin's count
   differs from core's for any reason in the catalogue. Not a hole, because `save_resource_data_multi()` re-derives
   the editable list in core and checks edit access per resource, but the page-level decision is unreliable.
5. **Typesense-only mode fails closed.** An outage or a declined form returns an empty set
   ([`query.php:1351`](../include/typesense_search_query.php:1351)), so a user with a search filter gets
   "confidential" for every resource, submit-pending finds nothing, and shares are refused. Safe, but it turns
   search availability into an access dependency.
6. **`$heightmin`**, the minimum-height media restriction in `search_filter()`
   ([`search_functions.php:905`](../../../include/search_functions.php:905)), is not replicated. Rarely used.

## What is covered

Hidden fields are excluded at query time through the visible-field list and per-field permission checks; node
buckets arrive pre-filtered from core; field-scoped searches on hidden fields are declined. The z, T, pending,
created-by, access-grant, search-filter and featured-collection rules are replicated and were exercised in the
review. Editable-only, smart-collection, `returnsql` and disk-usage searches always go to core; deleted resources
are dropped at hydration; share keys go through `collection_readable()`.

## Direction

Read-time validation closes points 1 and 2 and the index side of E2: re-run core's permission filter in the
hydration query. The hook already receives `$sql_filter` and `$sql_join`, but by then they also carry the keyword
criteria, so the permission part has to be separated out first. Point 3 is a one-line fix. Point 5 is a decision
about what Typesense-only mode is for.
