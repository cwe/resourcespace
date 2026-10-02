# Typesense search review, October 2026

A review of every kind of search core ResourceSpace supports, what the `typesense_search` plugin does with each,
and where the two differ. It was done from the code, not from the other documents in this folder, and every
difference listed was measured by running both engines.

- **Code reviewed:** branch `typesense` at `c72fe64e`.
- **Dates:** 1 and 2 October 2026.
- **Nothing was fixed.** This is a record of behaviour.
- **How to reproduce:** [`../harness/README.md`](../harness/README.md). Raw output is in `../harness/results/`.

Counts are written **core / plugin**: the number of resources each engine returned for the same search.

---

## 1. Summary

Most searches the plugin serves return the same resources as core, in the same order where the order is
defined, and everything it has no mode for is handed back to core correctly. Paging is exact at every window
shape tried. Combining terms does not change this: across about 2,500 generated combinations of terms,
arguments, special searches and permissions, the plugin returned the expected resources in every form but one
(item 33; see "Combinations" in section 4).

There are 33 numbered differences below, plus the ones already known before this review. The ones that matter
most on the test system (about 100,000 visible resources, `$stemming` on), with what was measured there:

| What a user does | Core | Plugin | Item |
|---|---|---|---|
| Exports search results as CSV metadata | Works | PHP TypeError, for every user group | 17 |
| Searches a quoted phrase, `"sculpture park"` | 6,883 | 0 | 26 |
| Uses the year dropdown on the simple search bar | 8,838 | 0 | 1 |
| Clicks a metadata value, then searches again from the search box (option name with a space) | 148 | 0 | 4 |
| Types part of an option name, `usagerights:social` | 148 | 100,862 (everything) | 3 |
| Searches a plain word, `land` | 3,172 | 69,408 | 2 |
| Includes a stop word, `the sculpture` | 49,468 | 17,228 | 22 |
| Wildcard in a field, `title:con*` | 1,317 | 262 | 18 |
| Leading wildcard, `*scape` | 68,601 | 381 | 11 |
| Date range ending in a month with no day chosen (February) | 822 | 21,151 | 6 |
| Hand-typed search on a date field that is not indexed | 3,676 | 0 | 20 |

The first row was measured locally and follows from the code for any system with the plugin active; it was not
triggered on the live system. Every other row is a live figure.

Also relevant there: nothing reaches the index between reindexes (K1), and next / previous on the resource page
follows a different order from the result grid for large relevance-sorted searches (23).

Not relevant on that system today, from a survey of its database: it has no formatted (HTML) fields, no
translated values, no category trees, no search filters, and one value longer than 500 characters in an indexed
field. Items 7, 8, 9 and 16 therefore have no effect there.

---

## 2. Method and limits

Three instruments, all in `../harness/`:

1. **Local A/B** (`ab/`). Core's real `do_search()` and the plugin's real indexer, query builder and hydrate on
   the same small fixture. Core's SQL ran on SQLite; the plugin talked to a local Typesense 30.2, the version
   on the test system. About 440 hand-written cases, plus 2,481 generated combinations run with `$stemming` off
   and again with it on.
2. **Trace** (`trace/`). No database, no Typesense: shows the SQL core builds and the request the plugin would
   send.
3. **Live A/B** (`live/`). 59 searches sent through the API as two users with the same permissions, one in a
   group with the plugin switched off, one in a group with it on. Paced and kept light. Plus one read-only
   survey of the database.

Limits to keep in mind:

- SQLite is not MySQL. Core's wildcard and full-text matching is emulated, and accent folding (which MySQL does
  through its collation) is not. Statements about those on the core side are inferred unless a live figure is
  given.
- The fixture shows behaviour, not scale. Where scale changes the picture (prefix matching, wildcards) the live
  figures are the ones to read.
- On the live system the plugin's group is in Typesense-only mode, so a search the plugin declines returns
  nothing there instead of falling back. Whether a live zero is a decline or a served zero was decided from the
  local run of the same search.
- Not checked: non-space-delimited scripts (Chinese, Japanese, Thai), and anything that only shows in a browser.
- Searching *for collections* (`do_collections_search()`) never reaches the plugin and is always MySQL.

---

## 3. How a search reaches the plugin

Core does a lot before it asks ([`do_search()`](../../../include/do_search.php:44)):

1. Pulls `@@` node tokens out of the search string into node buckets.
2. Splits the rest into keywords and builds the standard filter (resource types, workflow states, permissions).
3. Processes every keyword ([`do_search_keywords.php`](../../../include/do_search_keywords.php)): resolves
   `field:value` on fixed-list fields into nodes, date fields into joins, and looks every plain word up in the
   `keyword` table.
4. Calls the `external_search` hook ([`do_search.php:342`](../../../include/do_search.php:342)) with the
   keywords, node buckets and arguments. The plugin answers with rows, or `false` to let core continue.

Two consequences:

- **Core returns before the plugin is asked** when a plain word is not in the `keyword` table (it returns a
  "did you mean" string) or a field is not viewable by the user (it returns `false`). The plugin never sees
  those searches, so they cannot differ.
- The plugin is handed core's keyword list, not the raw string. Where core has already turned a term into a node
  the plugin only has to honour the bucket. Where core treats a term specially without changing the list (a
  date dropdown, a punctuated token, a stop word), the plugin has to know the rule too. Most differences below
  are rules of that kind that the plugin does not have.

The plugin ([`typesense_search_run()`](../include/typesense_search_query.php:1303)) picks one mode from the
search (collection, last, list, contributions, has-data, archive-pending, user-pending, single resource, or
standard), adds keyword matching and the restrictions, sends one `multi_search` to Typesense, and fetches the
rows for the returned refs from MySQL with core's own column list.

It always leaves these to core: `returnsql`, disk-usage totals, `editable_only` and smart-collection searches
([`all.php:88`](../hooks/all.php:88)).

---

## 4. Coverage by search type

"Same" means the same resources. Order is the same for the date, resource ID and modified sorts and differs for
relevance (K12).

### Free text

| Search | Outcome |
|---|---|
| One or more keywords, commas, upper case, empty search | Served, same |
| Quoted phrase | Served. Same with `$stemming` off unless it contains a stop word (21); nothing with `$stemming` on (26) |
| `-word`, negative-only search | Served, same, except words in hidden or inactive fields (K5) |
| Trailing wildcard | Served. Same only while the word has few different completions (18) |
| Two wildcards; bare number with the default config | Falls back to core |
| Bare number with `$config_search_for_number` | Served, same |
| A word that is not in the keyword table; un-fielded `a;b` | Core returns a suggestion before the plugin is asked |
| Stop words, resource type names, punctuation, leading wildcard, last-word prefix | Differ (22, K3, 10, 11, 2) |

### Field-specific

| Search | Outcome |
|---|---|
| Text `field:word`, several words, with free text, partial-index field | Served, same |
| `field:word*` | Served. Same only while the word has at most 4 different completions (18) |
| Fixed-list value that is exactly an option, `a;b` options, translated option by name | Served, same |
| `numrange` (both ends, one end, decimals), date by year / month / day, full-date ranges, expiry dates | Served, same, on indexed fields (20); ranges differ for stored dates that are only a year or a month (K7) |
| Text-field `field:a;b`, `-field:value`, `!empty` first, full-text search | Falls back to core |
| Field the user cannot view | Core returns `false` before the plugin is asked |
| Date dropdowns, quoted field search, value that is not exactly an option, plain value on a numeric field, range end that is not a date | Differ (1, 4, 3, 5, 6) |

### Nodes

`@@n`, OR within a word, AND across words, NOT, with keywords, tree parent and child, and
`$category_tree_search_use_and_logic` all return the same resources.

### Special searches

| Search | Outcome |
|---|---|
| `!collection` (own, featured, public), collection order both ways, keyword inside, count only | Served, same |
| `!last`, `!list`, `!listall`, `!resource`, `!contributions`, `!hasdata`, `!archivepending`, `!userpending` | Served, same |
| Selection or upload collection, unreadable or missing collection, `J` inside a collection that is not permitted | Falls back to core |
| `!related`, `!relatedpushed`, `!duplicates`, `!nodownloads`, `!unused`, `!geo`, `!colour`, `!colourkey`, `!rgb`, `!nopreview`, `!images`, `!properties`, `!integrityfail`, `!locked`, `!noningested`, `!report`, plugin specials (`!license`, `!consent`, `!face`, `!clipsearch`) | Falls back to core |

`!last` combined with a node filter differs because of a core bug (section 7).

### Arguments and permissions

| Item | Outcome |
|---|---|
| Resource type lists, `Global`, workflow states, `$search_all_workflow_states`, day limit, `access`, every `fetchrows` shape | Same |
| Sort by date, resource ID, modified | Same order, including ties and across page boundaries |
| Sort by popularity, rating, colour, title, type, extension, status, random, other fields | Falls back to core |
| `v`, `T`, `z`, `ert`, own pending resources, `J` with `j*` or `j<n>`, filters ALL / NONE / ANY, contributor override, share key | Same |
| Resource types on special searches, `access_override`, `ignore_filters`, `J` with no `j` | Differ (K8, K10, K11, 14) |

### Combinations

The tables above are about one kind of term at a time. Combinations were tested separately
(`harness/ab/50_combinations.php`) on a fixture built for the purpose: 512 resources, one for every mix of nine
two-valued attributes (a title word, a phrase, a caption word, a dropdown option, a dynamic keyword, a date, a
number, the resource type, the workflow state). Any AND of terms then has a known answer, and each case was
checked three ways: core against the expected set, the plugin against it, and the two against each other.

Only terms that agree on their own were combined (29 forms: keyword, wildcard, field term, field wildcard,
negative word, phrase, negative phrase, fixed-list value, node, NOT node, date by year / month / day, date
range, `numrange`), so a difference here would come from the combination. A combination that includes a term
from section 5 behaves as that item describes.

| Group | Cases | Plugin returned the expected set | Core returned the expected set |
|---|---|---|---|
| Single terms | 30 | 30 | 30 |
| Every ordered pair, comma separated | 870 | 868 | 870 |
| Ordered pairs, space separated (sample) | 216 | 216 | 216 |
| Three to seven terms | 300 | 300 | 300 |
| Terms with resource types, workflow states, day limit, sort and result window | 300 | 300 | 300 |
| Special searches alone | 6 | 6 | 6 |
| Special search plus one term | 360 | 360 | 340 |
| Special search plus two to four terms and workflow states | 150 | 150 | 133 |
| Permissions (`T`, `J`, search filter) with terms and arguments | 240 | 240 | 240 |
| OR groups of nodes | 9 | 7 | 7 |

- The order was identical in all 323 cases where an order is defined (date, resource ID, modified, collection).
- The two plugin misses in the pairs are one form in both orders: item 33.
- The core misses are the `!last` bug of section 7 and two quirks listed there. In the two OR-group cases both
  engines return the same (empty) result.
- With `$stemming` on the outcome was identical, case for case.
- 13 searches fell back to core. The only form in the list that the plugin declines is two free-text wildcards
  in one search.
- **Live:** 14 combinations on the test system (keyword with nodes, a field term, a field wildcard, a date, a
  NOT node, a negative word; resource type and workflow states; date and resource ID sorts; a collection and
  `!last` with a keyword) gave the same totals, and the same first rows where an order was checked.

---

## 5. Differences

Each item gives what was measured on the fixture and, where it was run, on the live system.

### Errors

**17. CSV metadata export fails with a TypeError.**
The export page passes `null` for `$smartsearch`
([`csv_export_results_metadata.php:35`](../../../pages/csv_export_results_metadata.php:35)); the hook declares
that parameter `bool` ([`all.php:53`](../hooks/all.php:53)), and PHP refuses a null for a typed parameter before
the function body runs. Core calls hooks with no exception handling, so the export stops. It happens with the
plugin's own toggle off too (`$typesense_search_enabled = false`): only deactivating the plugin avoids it. It is
the only `do_search()` call of 80 that passes a literal null to a typed hook parameter
(`harness/results/callscan.txt`).

**14. `J` permission with no `j` permission: TypeError.**
`compute_featured_collections_access_control()` returns `false` for such a user and the restriction passes it
to `array_map()` ([`featured_collections.php:33`](../include/restrictions/featured_collections.php:33), `:45`).
Core returns no results.

### Returns nothing where core finds resources

**1. Simple-search date dropdowns (`basicyear:`, `basicmonth:`, `basicday:`).**
Core matches them against the date field
([`do_search_keywords.php:163`](../../../include/do_search_keywords.php:163)). The plugin does not know the
names, treats the colon as incidental and searches for the text "basicyear 2024"
([`query.php:603`](../include/typesense_search_query.php:603)).
Fixture `basicyear:2024` 3 / 0. Live 8,838 / 0; with a month 723 / 0.
Reach: the "By date" dropdowns shown on the simple search bar by default (`$simple_search_date`).

**4. Quoted field search, `"field:two words"`.**
Core resolves it to the option or to a phrase within the field. The plugin only checks a quoted keyword for
full-text syntax and then sends the whole string, field name included, as a phrase
([`query.php:558`](../include/typesense_search_query.php:558)).
Fixture `"title:launch party"` 1 / 0, `"country:United Kingdom"` 1 / 0, `"keywords:modern art"` 2 / 0.
Live `"usagerights:Social media"` 148 / 0.
Reach: this is common. Advanced search emits it for a quoted phrase in a text box
([`search_functions.php:275`](../../../include/search_functions.php:275)). The search bar writes it into its own
box after any node search on a field that is not on the simple search bar
([`searchbar.php:141`](../../../include/searchbar.php:141)), for example after clicking a metadata value on a
resource; searching again from the box then sends it.

**26. Quoted phrases when `$stemming` is on.**
The index holds stems. Typesense does not stem the words of a quoted phrase, so a phrase containing any word the
stemmer changes cannot match. Core indexes the original word alongside the stem and looks phrases up unstemmed.
Fixture (stemming variant) `"launch party"` 1 / 0, `"sculpture park"` 1 / 0, a quoted single word `"sculpture"`
5 / 0; `"red car"`, whose words the stemmer leaves alone, 1 / 1. A negative phrase stops excluding: 1 / 2.
Asking Typesense directly for the stems as a phrase finds the resource.
Live `"sculpture park"` 6,883 / 0, `"sculpture"` 49,069 / 0, `"black and white"` 3,179 / 3,179.

**5. Plain value on a numeric field.**
The filter targets the text form of the field ([`query.php:777`](../include/typesense_search_query.php:777));
numeric values are indexed only as number and exact-match forms
([`functions.php:1269`](../include/typesense_search_functions.php:1269)).
Fixture `price:100` 1 / 0. `numrange` searches on the same field are the same.

**20. Date value, date range or `numrange` on a field that is not flagged for indexing.**
Core reads node values directly for these, so the field's index flag does not matter. The plugin's indexer only
writes values for indexed fields ([`functions.php:1229`](../include/typesense_search_functions.php:1229)), so
the filter finds nothing, and nothing falls back.
Fixture 1 / 0 for each of the three forms. Live `datephototaken:2024` 3,676 / 0.
Reach on the live system: that is its only such field, and it is not on either search form, so hand-typed only.

**27. Older and hand-typed date forms.**
Core matches a date value as a prefix with `n` as a wildcard and `|` as a separator
([`do_search_keywords.php:146`](../../../include/do_search_keywords.php:146)); the plugin matches whole years,
months, days or dates.
Fixture `date:nnnn|05` 1 / 0, `date:202` 9 / 0, `date:2024-0` 3 / 0. The other way round, `date:05` 0 / 1 and
`date:2024*` 0 / 4, because the plugin indexes the month and day on their own.
Live `date:nnnn|05` 9,511 / 0.
Reach: no current form emits these; saved searches and dash tiles from older versions may hold them.

### Returns far more than core

**3. Fixed-list `field:value` where the value is not exactly an option.**
Core looks the value up as an option; if that fails it searches for the word within that field. The plugin skips
every `field:value` on a fixed-list field on the assumption that core already resolved it
([`query.php:580`](../include/typesense_search_query.php:580)), so an unresolved one restricts nothing.
Fixture `country:united` 1 / 29, `country:fran*` 2 / 29, `keywords:modern` 2 / 29, a tree child by name
`subject:Birds` 1 / 29, a value in another language `country:allemagne` 1 / 29, options that do not exist 0 / 29.
Live `usagerights:social` 148 / 100,862.
Reach: typed searches; dynamic keyword fields shown as text boxes; and the search bar, which rewrites a tree
child node into `field:Name` text (same mechanism as item 4), a form core's lookup does not resolve because it
only reads top-level options.

**2. The last word is matched as a prefix.**
The plugin sends `prefix` only for a wildcard ([`query.php:403`](../include/typesense_search_query.php:403)) and
Typesense's default is on, for the last word of the query.
Fixture `car` 2 / 3 ("Carpet"). Live `land` 3,172 / 69,408, `sculpt` 3,685 / 49,610.
Exact matches are never lost; only extra resources are added, from up to about ten longer words (see 18).

**6. Date range whose end is not a real date.**
The form appends `-31` when a month is chosen without a day
([`search_functions.php:3369`](../../../include/search_functions.php:3369)) and `-99` for every EDTF range
(`:3343`). Core compares strings, so both work as "end of month". The plugin cannot parse them and drops the end
bound without declining ([`query.php:832`](../include/typesense_search_query.php:832)).
Fixture end `2024-02-31` 1 / 4, end `2023-04-31` 0 / 5, EDTF February 1 / 4.
Live end `2024-02-31` 822 / 21,151.
Reach: `$daterange_search` on and an end month of 30 days or fewer with no day; or `$daterange_edtf_support`.
Both are off by default.

**13. A number among several keywords matches a resource ID.**
`ref_s` is always searched ([`query.php:529`](../include/typesense_search_query.php:529)). Fixture `sunset 8`
0 / 1.

**12. Resource types consisting only of `FeaturedCollections`.**
Core binds the text as resource type 0 and finds nothing; the plugin discards non-numeric entries and applies no
type filter ([`standard.php:42`](../include/restrictions/standard.php:42)). Fixture 0 / 2.

**19. Words that match nothing are retried joined or split.**
Typesense's `split_join_tokens` defaults to trying this when the query has no results.
Fixture `sun set` 0 / 5 (finds "sunset"), `basketball` 0 / 1 (finds "Basket ball court"), `foobar` 0 / 3. With
`split_join_tokens=off` Typesense returns nothing for each. On the live system the three pairs tried all had
direct matches, so nothing was retried and the totals were the same.

### Returns fewer, or a different set

**18. Wildcards are incomplete.**
Typesense expands a prefix to a limited number of candidate words: about 10 in the query (`max_candidates`) and
4 in a filter (`max_filter_by_candidates`). The plugin sets neither
([`query.php:385`](../include/typesense_search_query.php:385)).
Fixture, 40 different words starting "zeb": `zeb*` 41 / 10, `title:zeb*` 40 / 4, with
`$wildcard_always_applied` 41 / 10. Asked directly, `max_candidates=100` or `exhaustive_search=true` returns all
40, and `max_filter_by_candidates=100` does for the filter.
Live `title:con*` 1,317 / 262, `con*` 61,993 / 53,361, `gar*` 15,291 / 14,227. Words with few different
completions were close or equal: `title:sculpt*` 4,522 / 4,522, `photo*` 23,951 / 23,959.
The un-fielded live figures also include K6 (core's wildcard reads text in fields that are not indexed), so only
the field figure isolates the cap.

**11. Leading or middle wildcard.**
Only a trailing `*` is handled ([`query.php:612`](../include/typesense_search_query.php:612)); anything else is
sent as literal text. Fixture `*bour` 1 / 0. Live `*scape` 68,601 / 381.

**22. Stop words.**
Core skips the words in `$noadd` ([`do_search_keywords.php:325`](../../../include/do_search_keywords.php:325));
the plugin requires them. `$use_refine_searchstring` is off by default, so the search page does not strip them
first.
Fixture `the sunset` 5 / 1, `title:the` 34 / 2, `the` alone 34 / 3.
Live `the sculpture` 49,468 / 17,228, `the` 100,862 / 32,537, `title:the` 100,862 / 10,564.

**21. Phrase containing a stop word.**
Core skips the stop word and only checks that the next word is two positions on
([`do_search_keywords.php:780`](../../../include/do_search_keywords.php:780)), so any word may sit in the gap.
The plugin needs the exact phrase.
Fixture `"black and white"` 2 / 1 (core also finds "Black or white"), `"foo the bar"` 2 / 0.
Live `"black and white"` 3,179 / 3,179: no visible effect there.

**10. A punctuated token is a phrase in core.**
A token such as `foo-bar` is quoted internally by core
([`do_search_keywords.php:270`](../../../include/do_search_keywords.php:270)) when it is the whole search, or
when the search also contains a colon or a quote (those searches are split on spaces only). The plugin always
matches the parts anywhere.
Fixture `foo-bar` 1 / 3, `caption:foo-bar` 1 / 3, `title:launch foo-bar` 1 / 2. Live `black-and-white`
3,179 / 3,194.

**7. Formatted (HTML) fields.**
Core strips tags before indexing; the plugin indexes the stored text
([`functions.php:1282`](../include/typesense_search_functions.php:1282)), so a word straight after a tag is
glued to the tag name and tag names become searchable.
Fixture `hello` 2 / 1, `strong` 1 / 2, `notes:hello` 1 / 0.

**8. Translated values (`~en:Germany~fr:Allemagne`).**
Core indexes each translation; the plugin indexes the raw string, which glues the first translation to the next
language code. Fixture `germany gate` 1 / 0.

**9. Text beyond the first 500 characters.**
Core indexes only the first `$node_keyword_index_chars` characters; the plugin indexes everything. Fixture
`zeppelin` 1 / 2.

### Access and visibility

**16. Per-resource access for users with a search filter is decided by the index.**
`get_resource_access()` runs a `!resource<ref>` search for such users
([`resource_functions.php:5207`](../../../include/resource_functions.php:5207)), and the plugin serves it. After
a metadata change with no reindex the plugin opened a resource core denied and denied two that core allowed
(`harness/results/ab/09_resource_access.txt`).

**15. Expired user grant overrides a search filter.**
With `$custom_access_overrides_search_filter`, core's join ignores expired user grants; the plugin's override
clause has no expiry test ([`group_filter.php:68`](../include/restrictions/group_filter.php:68)). Fixture 3 / 4.

**24. Collection gate inside an external upload-share session.**
Core allows only the session's own collections
([`search_functions.php:1206`](../../../include/search_functions.php:1206)); the plugin's gate is
`collection_readable()` alone ([`collection.php:28`](../include/modes/collection.php:28)), which accepts any
collection once a valid key is present. Fixture 0 / 2 and 0 / 3.
Measured at `do_search()` level only. Upload-share keys are limited to a few pages; one of them, `edit.php`, runs
a search taken from the request. That path was not run end to end.

**25. `$typesense_search_global_filter` applies only to searches the plugin serves.**
Any search that falls back ignores it ([`query.php:365`](../include/typesense_search_query.php:365)). Fixture
1 with the filter, 2 for the same search with a sort the plugin declines. It cannot be relied on as a
restriction.

### Order

**23. Next / previous on the resource and preview pages.**
Those pages fetch every row ([`view.php:57`](../../../pages/view.php:57),
[`preview.php:88`](../../../pages/preview.php:88)). Above `$typesense_search_max_rows` (25,000) that request
falls back to MySQL while the grid stays on Typesense, and under relevance, the default sort, the two orders
differ. With the limit lowered to 500 on a 620-resource fixture, the resource after the first grid result was a
different one by next / previous. On the live system the empty search alone has 100,862 results.

### Minor

**28. Special search followed directly by a comma term containing digits.** The plugin reads the argument from
all the digits in the first token. `!contributions1,date:2024, sunset` 5 / 0 (it reads user 12024);
`!hasdata12,date:2024` 9 / 0. Hand-typed or API only.

**29. `-foo-bar` as the whole search.** Core loses the negation and searches for both words; the plugin excludes
and finds nothing. 3 / 0.

**30. `numrange` spanning zero on a numeric field that also holds text.** MySQL counts the text as 0; the
plugin has no number for it. `price:numrangeneg5|5` 1 / 0 (emulated on SQLite).

**31. `!empty` after another term.** Core handles `!empty` anywhere in the keyword list; the plugin only
declines it when it is first. `title:sunset !empty18` 4 / 0.

**33. A word together with a wildcard that only completes to that word.** `alpha, alph*` 128 / 0, in either
order. The plugin sends both as query words with the second as a prefix; Typesense returns nothing when the
prefix can only complete to a word already in the query (asked directly: `alpha alph` 0, `alph` 128,
`piece alph` 128). Found by the combination battery; the only combination-specific difference.

**32. Typesense-only mode.** With `$typesense_search_only` every search the plugin declines, and any Typesense
outage, returns nothing; `returnsql`, disk usage, `editable_only` and smart searches still go to core. It is
described as a testing aid. Without it an unreachable Typesense falls back to core correctly.

---

## 6. Differences known before this review, re-measured

| | Search or situation | Fixture | Live |
|---|---|---|---|
| K1 | **Index freshness.** The save hook posts to `/collections//documents` and gets a 404, because [`typesense_search_index_resource()`](../include/typesense_search_functions.php:895) reads an unset variable for the collection name. Nothing changes between reindexes: an edited title is not found (1 / 0), deleted or newly confidential resources still show and new ones are missing (4 / 5), removed collection members remain (2 / 3) | as stated | |
| K2 | Stop word plus keyword | `the sunset` 5 / 1 | see 22 |
| K3 | Resource type name as a keyword: core returns every resource of that type | `video` 3 / 1 | `document` 490 / 375 |
| K4 | Related keywords. The sync also posts to `/collections//synonyms` (404) | `automobile` 2 / 0 | one pair defined |
| K5 | `-word` when the word is only in a hidden or inactive field: core's exclusion ignores field visibility | 0 / 1 | |
| K6 | Wildcard on text in a field that is not indexed: core's wildcard reads node text directly | `zeb*` 1 / 0 | `sculpt*` 51,007 / 49,610 |
| K7 | Year-only or partial dates inside a range: the plugin treats them as covering their whole period | 3 / 4 | 822 / 854 |
| K8 | Resource types on special searches, when a caller passes them (the search page blanks them) | `!list1:2:3` type 2: 1 / 3 | |
| K9 | Collection gate: `R` or `h` users, or `access_override`, see another user's private collection | 0 / 2 | |
| K10 | Group search filter under `access_override` | 1 / 10 | |
| K11 | `ignore_filters` drops the standard restrictions | 5 / 8 | |
| K12 | Relevance order differs whenever relevance is the sort. This includes `!collection<n>` through the API, whose default sort is relevance | same set | same 55, different first rows |
| K13 | Stemmer drift with `$stemming` on: 34 of 40 word-form pairs agree; irregular plurals match in core only | | |
| K14 | `$wildcard_always_applied`: only the last word is prefixed and field values are not | `laun part` 1 / 0 | |
| K15 | `$index_contributed_by`: usernames are not matched | `admin` 29 / 1 | |
| K16 | Field-specific search on an inactive field is served. A change that makes it fall back, and stops indexing inactive text, existed uncommitted when this was written | `oldfield:legacy` 0 / 1 | |

---

## 7. Two core bugs

Both are in files identical to `master`. They are recorded because they make core the odd one out. Decision
taken during the review: leave core as it is.

**`!last` combined with a node filter returns one row.**
A node filter turns the hit-count column into an aggregate
([`do_search_nodes.php:24`](../../../include/do_search_nodes.php:24)). The `!last` inner query has no `GROUP BY`
([`search_functions.php:1141`](../../../include/search_functions.php:1141)), so the aggregate collapses it to a
single row, or to one all-NULL row when nothing matches. MySQL accepts it because core removes
`ONLY_FULL_GROUP_BY` at connect ([`database_functions.php:298`](../../../include/database_functions.php:298)).
Fixture `!last10, @@201` 1 / 2, with nothing matching 1 / 0. Keywords alone do not trigger it.

**An exact value on a date-range field never matches.**
The join has four placeholders in the order field, value, field, value, and binds field, field, value, value
([`do_search_keywords.php:151`](../../../include/do_search_keywords.php:151)). With the parameters swapped the
same SQL finds the resource for a date inside the range.
The plugin uses a different rule, a match on the year, month, day or date of either end, so neither engine does
what core intends: a day inside the range 0 / 0, the start date 0 / 1, the year 0 / 1. Live year 0 / 2.
Reach: advanced search with `$daterange_search` off shows a date-range field as dropdowns, which produce this
search.

---

### Core quirks seen in combinations

Not plugin differences, but they explain where core misses its own expected result in the combination table.

- **`!resource<n>` followed by a term containing digits.** Core builds the resource number from every digit in
  the search string ([`search_functions.php:1422`](../../../include/search_functions.php:1422)), so
  `!resource1100, date:2023-03` looks for resource 1100202303 and returns nothing. The plugin reads the number
  from the first token and returns the resource: 0 / 1.
- **The same node alone and then inside an OR group**, `@@201, @@201@@203`. Removing the first token from the
  string also removes it from the group ([`do_search.php:529`](../../../include/do_search.php:529)), leaving a
  stray token. Both engines return nothing, where the expected answer is the resources with node 201. The
  other order works.

---

## 8. Checked and the same

- **Combinations.** See section 4: 2,481 generated cases, with and without stemming, and 14 on the live system.

- **Paging and order at scale.** 26 cases on 620 resources: windows across Typesense's 250-row page limit, tied
  dates, missing and year-only dates, all rows, integer `fetchrows` padded with zeros, refs only, an offset past
  the end. Identical rows in identical order.
- **Special searches.** Every served mode with keywords and nodes added; `!hasdata` on inactive, hidden and
  non-indexed fields; the collection gate for a missing collection, collection 0, an `a` admin and
  `$ignore_collection_access`.
- **Text matching.** Apostrophes (straight and curly), email addresses, decimals, negative words, a negative
  wildcard, option names as free text, the day and month numbers of dates as free text (core indexes them too),
  upper-case field names and values, a colon that is not a field.
- **Accents.** Typesense folds them (`cafe` finds "Café"). Core does through MySQL's collation; that side is
  inferred.
- **Typesense unreachable.** Falls back to core.
- **Hooks.** No bundled plugin implements a search-pipeline hook that the plugin's path would skip.
- **Keyword usage statistics** are still logged for searches the plugin serves (read from the code,
  [`do_search.php:374`](../../../include/do_search.php:374), not measured).

---

## 9. The live system at the time

From the read-only survey (`harness/results/live/db_survey.txt`):

- 100,862 resources in the default workflow state; 110 metadata fields, 22 inactive.
- No formatted-text fields, no category trees, no values in translation syntax, no search filters.
- 3,060 values longer than 500 characters, one of them in an indexed field.
- One date field not flagged for indexing, on neither search form. No such numeric field.
- Two fields on the simple search bar: the date field and one checkbox list.
- The plugin is active for every user group, with Typesense-only mode off in its own settings. Two of the 14
  groups override that: the group of the "plugin" API user turns Typesense-only mode on, and the group of the
  "core" API user switches the plugin off. The other 12 groups run the plugin with fallback to core.

Not from the survey:

- `$stemming` is on. Known from earlier work on that system and consistent with the live phrase results (26).
- Not established: whether `$daterange_search` is on there (decides item 6).

---

## 10. Open questions

1. Is searching for collections still out of scope?
2. Should the plugin copy core for the date-range exact search (section 7), stay as it is, or decline it?
3. Item 24: is the upload-share path reachable in practice? It needs a run on a real system.
