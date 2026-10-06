# Typesense search review, October 2026

A review of every kind of search core ResourceSpace supports, what the `typesense_search` plugin does with each,
and where the two differ. It was done from the code, not from the other documents in this folder, and every
difference listed was measured by running both engines.

- **Code reviewed:** branch `typesense` at `c72fe64e`, on 1 and 2 October 2026.
- **Nothing was fixed.** This is a record of behaviour.
- **How to reproduce:** [`../harness/README.md`](../harness/README.md). Raw output is in `../harness/results/`.
- Figures are written **core / plugin**: the number of resources each engine returned for the same search.
  "Local" figures come from a small fixture and use its own equivalent of the example; "test system" figures
  use the example shown.
- Findings are referred to as A1, B3 and so on. [Appendix A](#appendix-a-earlier-numbering) maps them to the
  numbers used in earlier notes.

---

## 1. The short version

The plugin returns the same resources as core for most searches, but 48 differences were found. They fall into
eight themes, grouped by cause, each shown with its worst case on the test system.

| Theme | Findings | Worst case on the test system | To decide |
|---|---|---|---|
| [A. Features that fail outright](#a-features-that-fail-outright) | 2 | CSV metadata export stops with a PHP error, for every user group | Change the plugin's hook, or core's call? |
| [B. Search forms the plugin does not recognise](#b-search-forms-the-plugin-does-not-recognise) | 17 | Year dropdown 8,838 / 0. Part of an option name 148 / 100,862 | For each form: teach the plugin the rule, or have it decline so core answers? |
| [C. Typesense defaults left as they are](#c-typesense-defaults-left-as-they-are) | 4 | Plain word `land` 3,172 / 69,408 | Send stricter search parameters with every query? |
| [D. Stemming](#d-stemming) | 2 | Quoted phrase `"sculpture park"` 6,883 / 0 | How should phrases work when stemming is on? |
| [E. What the index holds](#e-what-the-index-holds) | 10 | Nothing reaches the index between reindexes | What happens until incremental indexing lands? |
| [F. Access and permissions](#f-access-and-permissions) | 6 | Not run there; locally a private collection is shown to the wrong user, 0 / 2 | Share core's collection check, or copy it? |
| [G. Order and navigation](#g-order-and-navigation) | 2 | Next / previous follows a different order above 25,000 results | Is the relevance order acceptable as it is? |
| [H. Smaller items](#h-smaller-items) | 5 | Hand-typed searches only | Accept as they are? |
| [Core's own bugs and quirks](#4-cores-own-bugs-and-quirks) | 4 | `!last` with a node filter returns one row | Decision so far: leave core as it is |

---

## 2. What works

Everything in this table returned the same resources from both engines, and the same order where an order is
defined.

| Area | Confirmed the same |
|---|---|
| Free text | One or more keywords, commas, upper case, the empty search, negative words, a wildcard on a word with few completions, quoted phrases with stemming off |
| Field terms | Text `field:word`, a fixed-list value that is exactly an option, `a;b` options, `numrange`, dates by year, month or day, full-date ranges, expiry dates |
| Nodes | `@@n`, OR within a word, AND across words, NOT, tree parent and child, `$category_tree_search_use_and_logic` |
| Special searches | `!collection`, `!last`, `!list`, `!listall`, `!resource`, `!contributions`, `!hasdata`, `!archivepending`, `!userpending`, alone and with keywords or nodes added |
| Arguments | Resource types, `Global`, workflow states, `$search_all_workflow_states`, day limit, access level, every result-window shape |
| Sorts | Date, resource ID and modified, including ties and across page boundaries |
| Permissions | `v`, `T`, `z`, `ert`, own pending resources, `J` with `j`, search filters ALL / NONE / ANY, contributor override, share keys |
| Combinations | 2,481 generated combinations of the above, with stemming off and on: the plugin returned the expected resources in 2,477. The other four are C4 and Core 4. 14 more on the test system were all the same |
| Paging at scale | 26 cases on 620 resources, across Typesense's 250-row page limit |
| Text matching | Apostrophes, email addresses, decimals, option names as free text, the day and month numbers of dates, upper-case field names and values, accents (Typesense folds them; core does through MySQL's collation, which is inferred) |
| Handed back to core | Other sorts and special searches, OR within a text field, negative field terms, two wildcards, full-text search, editable-only, disk usage and smart-collection searches; and any search when Typesense is unreachable |

A search has one of three outcomes. The plugin serves it. The plugin declines it and core answers, so the two
cannot differ. Or core answers before asking the plugin, which happens when a word is not in the keyword table
or the field is hidden from the user. [Appendix B](#appendix-b-how-a-search-reaches-the-plugin) has the detail.

Special searches the plugin always declines: `!related`, `!relatedpushed`, `!duplicates`, `!nodownloads`,
`!unused`, `!geo`, `!colour`, `!colourkey`, `!rgb`, `!nopreview`, `!images`, `!properties`, `!integrityfail`,
`!locked`, `!noningested`, `!report`, `!empty`, and those added by other plugins. Sorts it declines: popularity,
rating, colour, title, type, extension, status, random and other fields.

### Combinations in detail

Combinations were tested on a fixture built for the purpose (`harness/ab/50_combinations.php`): 512 resources,
one for every mix of nine two-valued attributes, so any AND of terms has a known answer. Each case was checked
three ways: core against the expected set, the plugin against it, and the two against each other. Only terms that
agree on their own were combined (29 forms), so a difference here comes from the combination. A combination that
includes a term from section 3 behaves as that finding describes.

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

- The order was identical in all 323 cases where an order is defined.
- The two plugin misses in the pairs are C4, in both orders.
- The core misses are Core 1, Core 3 and Core 4. In the two OR-group cases both engines return the same result.
- With `$stemming` on the outcome was identical, case for case.
- 13 searches fell back to core. The only form in the list that the plugin declines is two free-text wildcards
  in one search.
- On the test system, 14 combinations gave the same totals, and the same first rows where an order was checked.

---

## 3. Findings

### A. Features that fail outright

Two actions stop with a PHP TypeError when the plugin is active.

| Ref | What the user does | Core | Plugin | Where |
|---|---|---|---|---|
| A1 | Exports search results as CSV metadata | Works | TypeError. It also happens with the plugin's own toggle off, so only deactivating the plugin avoids it | The page passes `null` for `$smartsearch` ([`csv_export_results_metadata.php:35`](../../../pages/csv_export_results_metadata.php:35)). The hook declares that parameter `bool` ([`all.php:53`](../hooks/all.php:53)) |
| A2 | Searches as a user with the `J` permission and no `j` permission | No results | TypeError | `false` is passed to `array_map()` ([`featured_collections.php:33`](../include/restrictions/featured_collections.php:33), `:45`) |

- **A1** was measured locally and not triggered on the test system. The plugin is active for all 14 user groups
  there, so it would affect every group. PHP refuses a null for a typed parameter before the function body runs,
  and core calls hooks with no exception handling. Of 80 `do_search()` calls in the codebase it is the only one
  that passes a null to a typed hook parameter (`harness/results/callscan.txt`).

**To decide:** A1 can be changed on either side: loosen the hook's parameter types, or stop core passing null.

### B. Search forms the plugin does not recognise

Core applies a rule to certain terms before it searches. The plugin receives the same terms without the rule,
so it returns nothing, everything, or a different set, and does not fall back.

#### Sent by the standard search pages

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| B1 | Picks a year or month on the simple search bar, which sends `basicyear:2024` | Matches the date field | Searches for the text "basicyear 2024" | Local 3 / 0. Test system 8,838 / 0 | [`query.php:603`](../include/typesense_search_query.php:603) |
| B2 | Clicks a metadata value whose name has a space, then searches again from the search box, which now holds `"usagerights:Social media"`. Advanced search sends the same form for a quoted phrase | Finds the option, or the phrase in that field | Sends the whole string, field name included, as a phrase | Local 1 / 0. Test system 148 / 0 | [`query.php:558`](../include/typesense_search_query.php:558), [`searchbar.php:141`](../../../include/searchbar.php:141) |
| B3 | Gives a fixed-list field a value that is not exactly an option: one word of it, a wildcard, or a tree child by name. Example `usagerights:social` | Searches for the word within that field | Drops the term, so nothing is restricted | Local 1 / 29. Test system 148 / 100,862 | [`query.php:580`](../include/typesense_search_query.php:580) |
| B4 | Picks a date range that ends in a month with no day. The form sends `end2024-02-31` | Treats it as the end of that month | Cannot read the date and drops the end of the range | Local 1 / 4. Test system 822 / 21,151 | [`query.php:832`](../include/typesense_search_query.php:832) |
| B5 | Includes a stop word: `the sculpture`, `title:the` | Ignores the word | Requires the word | Local 5 / 1. Test system 49,468 / 17,228 | [`do_search_keywords.php:325`](../../../include/do_search_keywords.php:325) |
| B6 | Searches a phrase containing a stop word: `"black and white"` | Any word may fill the gap, so "black or white" also matches | Exact phrase only | Local 2 / 1. Test system 3,179 / 3,179 | [`do_search_keywords.php:780`](../../../include/do_search_keywords.php:780) |
| B7 | Searches a hyphenated word or a filename: `black-and-white` | Treats it as a phrase | Matches the parts anywhere | Local 1 / 3. Test system 3,179 / 3,194 | [`do_search_keywords.php:270`](../../../include/do_search_keywords.php:270) |

- **B1** is reached from the "By date" dropdowns that the simple search bar shows by default
  (`$simple_search_date`). With a month as well: test system 723 / 0.
- **B2**: advanced search emits the quoted form for a quoted phrase in a text box
  ([`search_functions.php:275`](../../../include/search_functions.php:275)). The search bar writes it into its
  own box after any node search on a field that is not on the simple search bar. Local `"title:launch party"`
  1 / 0, `"country:United Kingdom"` 1 / 0, `"keywords:modern art"` 2 / 0. With a typed word added, test system
  100 / 0.
- **B3** is also what the search box holds after a click on a tree child node; core's option lookup only reads
  top-level options. Local `country:united` 1 / 29, `country:fran*` 2 / 29, `keywords:modern` 2 / 29,
  `subject:Birds` 1 / 29, a value in another language 1 / 29, options that do not exist 0 / 29.
- **B4** needs `$daterange_search`, which is off by default and not known for the test system. The form appends
  `-31` ([`search_functions.php:3369`](../../../include/search_functions.php:3369)); core compares text, so it
  works as "end of month". Every EDTF range loses its end the same way, because core pads it with `-99`
  (`:3343`); that needs `$daterange_edtf_support`.
- **B5** reaches the search from the search page too, because `$use_refine_searchstring` is off by default.
  Test system `the` alone 100,862 / 32,537, `title:the` 100,862 / 10,564.
- **B6**: core skips the stop word and only checks that the next word is two positions on. Local
  `"foo the bar"` 2 / 0.
- **B7** applies when the token is the whole search, or when the search also holds a field term or a quoted
  phrase (those searches are split on spaces only). Local `title:launch foo-bar` 1 / 2.

#### Typed, configured or sent through the API

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| B8 | Gives a numeric field a plain value: `price:100` | Matches | Nothing. The filter looks at the text form, and numbers are indexed as numbers | Local 1 / 0 | [`query.php:777`](../include/typesense_search_query.php:777), [`functions.php:1269`](../include/typesense_search_functions.php:1269) |
| B9 | Uses an older date form: `date:nnnn\|05`, `date:202` | Matches by prefix, with `n` as a wildcard | Matches whole years, months, days or dates only | Local 1 / 0. Test system 9,511 / 0 | [`do_search_keywords.php:146`](../../../include/do_search_keywords.php:146) |
| B10 | Uses a leading or middle wildcard: `*scape` | Matches word endings | Sends it as literal text | Local 1 / 0. Test system 68,601 / 381 | [`query.php:612`](../include/typesense_search_query.php:612) |
| B11 | Searches a resource type name: `document` | Also returns every resource of that type | Text matches only | Local 3 / 1. Test system 490 / 375 | [`do_search_keywords.php:56`](../../../include/do_search_keywords.php:56) |
| B12 | Searches a word that has related keywords | Matches the related words too | No related words. The sync posts to a URL with no collection name and gets a 404 | Local 2 / 0 | [`functions.php:1689`](../include/typesense_search_functions.php:1689) |
| B13 | Searches a contributor's username, with `$index_contributed_by` on | Returns their resources | Text matches only | Local 29 / 1 | [`do_search_keywords.php:62`](../../../include/do_search_keywords.php:62) |
| B14 | Searches with `$wildcard_always_applied` on | Every word and field value is a prefix | Only the last word is | Local 1 / 0 | [`query.php:521`](../include/typesense_search_query.php:521) |
| B15 | Sends resource types of `FeaturedCollections` only | Nothing | No type filter | Local 0 / 2 | [`standard.php:42`](../include/restrictions/standard.php:42) |
| B16 | Passes resource types with a special search, as the API and CSV export do | Applies them | Ignores them unless `$special_search_honors_restypes` is on | Local 1 / 3 | [`standard.php:36`](../include/restrictions/standard.php:36) |
| B17 | Includes a number among several keywords: `sunset 8` | Keyword only | Also matches resource 8 by its ID | Local 0 / 1 | [`query.php:529`](../include/typesense_search_query.php:529) |

- **B9**: no current form emits these; saved searches and dash tiles from older versions may hold them. The
  other way round, `date:05` is 0 / 1 and `date:2024*` 0 / 4, because the plugin indexes the month and day on
  their own.
- **B12**: one related-keyword pair is defined on the test system.

**To decide:** for each form, teach the plugin the rule, or have it decline so that core answers.

### C. Typesense defaults left as they are

Four differences come from search parameters the plugin does not send
([`query.php:385`](../include/typesense_search_query.php:385)), so Typesense's defaults apply.

| Ref | What the user does | Core | Plugin | Measured | Parameter |
|---|---|---|---|---|---|
| C1 | Searches a plain word: `land` | Exact word | The last word is also matched as the start of longer words | Local 2 / 3. Test system 3,172 / 69,408 | `prefix` defaults to on. The plugin only sends it for a wildcard ([`query.php:403`](../include/typesense_search_query.php:403)) |
| C2 | Uses a wildcard: `title:con*`, `con*` | Every word with that start | About 10 different words in the query, 4 in a field | Local 41 / 10 and 40 / 4. Test system 1,317 / 262 and 61,993 / 53,361 | Locally `max_candidates=100` and `max_filter_by_candidates=100` returned all 40 |
| C3 | Searches words that match nothing as typed: `sun set`, `basketball` | Nothing | Retries with the words joined or split, and finds "sunset" | Local 0 / 5. Not triggered on the test system | Locally `split_join_tokens=off` returned nothing |
| C4 | Searches a word together with its own wildcard: `alpha, alph*` | Matches | Nothing | Local 128 / 0 | None found |

- **C1** never loses an exact match. It only adds resources, from up to about ten longer words. Test system
  `sculpt` 3,685 / 49,610.
- **C2**: the field figure isolates the limit. The un-fielded figure also includes E4. Words with few different
  completions were close or equal: `title:sculpt*` 4,522 / 4,522, `photo*` 23,951 / 23,959, `gar*`
  15,291 / 14,227. `exhaustive_search=true` also returned all 40 locally.
- **C3**: on the test system the three pairs tried all had direct matches, so nothing was retried.
- **C4**: asked directly, Typesense returns nothing for `alpha alph` with the prefix on, 128 for `alph` and 128
  for `piece alph`. Found by the combination tests.

**To decide:** whether to send these parameters with every query. Their effect on speed was not measured.

### D. Stemming

With `$stemming` on, as on the test system, a quoted phrase returns nothing from the plugin if the stemmer
changes any of its words.

| Ref | What the user does | Core | Plugin | Measured |
|---|---|---|---|---|
| D1 | Searches a quoted phrase or a quoted single word: `"sculpture park"` | Looks the words up as typed. Core indexes the original word beside its stem | Typesense does not stem the words of a phrase, and the index holds stems | Local 1 / 0. Test system 6,883 / 0, and `"sculpture"` 49,069 / 0 |
| D2 | Searches another form of a word: `children` for "child" | Uses ResourceSpace's stemmer | Uses Typesense's stemmer | 34 of 40 word pairs agree. Irregular plurals match in core only |

- `"black and white"` is unaffected (3,179 / 3,179), because the stemmer leaves those words alone. Locally
  `"red car"` 1 / 1, `"launch party"` 1 / 0.
- A negative phrase stops excluding: local 1 / 2.
- Asked directly for the stems as a phrase, Typesense finds the resource.

**To decide:** how quoted phrases should work when stemming is on.

### E. What the index holds

The index is only as current as the last reindex. It also holds some text that core's keyword index does not,
and lacks some that core reads directly.

#### Freshness

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| E1 | Edits, deletes or uploads a resource, or changes a collection | Seen at once | Not seen until the next reindex. The save hook posts to a URL with no collection name and gets a 404 | Local: edited title 1 / 0, deleted and new resources 4 / 5, collection members 2 / 3 | [`functions.php:895`](../include/typesense_search_functions.php:895) |
| E2 | Opens a resource as a user who has a search filter | Decides access from current data | Decides access from the index, because core checks it with a search | Local: opened one resource core denied, denied two core allowed. No search filters on the test system | [`resource_functions.php:5207`](../../../include/resource_functions.php:5207) |

#### Fields and values

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| E3 | Searches a date, date range or number range on a field not flagged for indexing | Reads the values directly | Nothing. Those values are not in the index | Local 1 / 0. Test system `datephototaken:2024` 3,676 / 0 | [`functions.php:1229`](../include/typesense_search_functions.php:1229) |
| E4 | Uses a wildcard that matches text in a field not flagged for indexing | Matches, because its wildcard reads the text directly | No match | Local 1 / 0. Test system `sculpt*` 51,007 / 49,610 | [`do_search_keywords.php:622`](../../../include/do_search_keywords.php:622) |
| E5 | Searches a date range over dates stored as only a year or a month | Compares the text | Treats the partial date as covering its whole period | Local 3 / 4. Test system 822 / 854 | [`query.php:806`](../include/typesense_search_query.php:806) |
| E6 | Excludes a word that only appears in a hidden or inactive field | Still excludes the resource | Does not | Local 0 / 1 | [`do_search_keywords.php:478`](../../../include/do_search_keywords.php:478) |
| E7 | Searches a field that has been made inactive | Nothing | Still served | Local 0 / 1 | A change that makes it fall back existed uncommitted |
| E8 | Searches text in a formatted (HTML) field | Tags are stripped | Stored text is indexed, so a word after a tag is glued to it and tag names match | Local 2 / 1. No such fields on the test system | [`functions.php:1282`](../include/typesense_search_functions.php:1282) |
| E9 | Searches a translated value | Each translation is indexed | The raw string is indexed, gluing the first translation to the next language code | Local 1 / 0. None on the test system | [`functions.php:1282`](../include/typesense_search_functions.php:1282) |
| E10 | Searches a word past the first 500 characters of a value | Not indexed | Indexed | Local 1 / 2. One such value on the test system | `$node_keyword_index_chars` |

- **E1**: [`typesense_search_index_resource()`](../include/typesense_search_functions.php:895) reads an unset
  variable for the collection name.
- **E3**: on the test system that is the only such field and it is on neither search form, so the search is
  hand-typed only.

**To decide:** E1 is the planned incremental indexing; what happens until it lands? E3: should a search on a
field that is not indexed be declined?

### F. Access and permissions

Six differences change who can see what. All were measured locally; none was run on the test system, where the
two API users are ordinary users.

| Ref | Situation | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| F1 | A user with `R` or `h`, or a search with access override, opens another user's private collection | Nothing | The collection's contents | 0 / 2 | [`collection.php:28`](../include/modes/collection.php:28), against [`search_functions.php:1203`](../../../include/search_functions.php:1203) |
| F2 | The same request inside an external upload-share session | Only that session's own collections | Any collection, once a valid key is present | 0 / 2 and 0 / 3 | Same gate |
| F3 | A user has an expired grant, with `$custom_access_overrides_search_filter` on | The expired grant is ignored | The expired grant still overrides the search filter | 3 / 4 | [`group_filter.php:68`](../include/restrictions/group_filter.php:68) |
| F4 | A search with access override, by a user who has a search filter | The filter is applied | The filter is skipped | 1 / 10 | [`group_filter.php:11`](../include/restrictions/group_filter.php:11) |
| F5 | A caller passes `ignore_filters` | Only keyword parsing changes | Resource type, workflow state and the other standard restrictions are dropped | 5 / 8 | [`standard.php:11`](../include/restrictions/standard.php:11) |
| F6 | `$typesense_search_global_filter` is set | Not applicable | Applied to searches the plugin serves, ignored by any search that falls back | 1 with it, 2 for the same search when it falls back | [`query.php:365`](../include/typesense_search_query.php:365) |

- **F2** was measured at search level only. Upload-share keys work on a few pages; one of them, `edit.php`,
  runs a search taken from the request. That path was not run end to end.
- **F4 and F5** are latent. Only smart collections and one AI job pass those flags today, and smart-collection
  searches always go to core.

**To decide:** share core's collection check with the plugin, or copy it?

### G. Order and navigation

Relevance order differs between the engines by design, and one page depends on the two orders matching.

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| G1 | Sorts by relevance, which is the default | Orders by a hit-count score, then rating, date and ID | Orders by Typesense's text match, or by ID when there are no keywords | Same resources, different order. Test system: `!collection3808` through the API returned the same 55 with different first rows | [`query.php:686`](../include/typesense_search_query.php:686) |
| G2 | Uses next / previous on the resource or preview page, after a relevance-sorted search with more than 25,000 results | Follows the grid | The grid comes from Typesense, next / previous from MySQL, in a different order | Local, with the limit lowered to 500: a different resource came next. The empty search has 100,862 results on the test system | [`view.php:57`](../../../pages/view.php:57), [`preview.php:88`](../../../pages/preview.php:88) |

- **G1** affects every API call that asks for a collection without naming a sort, because the API's default
  sort is relevance.
- **G2**: those pages ask for every row. Above `$typesense_search_max_rows` that request falls back to core.
- Date, resource ID and modified sorts are identical, so G2 does not arise with them.

**To decide:** is the relevance order acceptable as it is? G2 follows from it.

### H. Smaller items

Five differences arise only from hand-typed or API searches, or from a testing setting.

| Ref | Search or setting | Core | Plugin | Measured |
|---|---|---|---|---|
| H1 | A special search followed directly by a comma and a term with digits: `!contributions1,date:2024, sunset` | Reads user 1 | Reads the user from every digit in the first token: 12024 | 5 / 0 |
| H2 | `-foo-bar` as the whole search | Loses the negation and searches for both words | Excludes, and finds nothing | 3 / 0 |
| H3 | A number range that spans zero, on a numeric field that also holds text | Counts the text as 0 | Has no number for it | 1 / 0, emulated |
| H4 | `!empty` after another term: `title:sunset !empty18` | Handles it anywhere | Only declines it when it comes first | 4 / 0 |
| H5 | Typesense-only mode, `$typesense_search_only` | Not applicable | Every declined search, and any Typesense outage, returns nothing | By design: a testing aid |

- **H1**: the same happens with `!hasdata12,date:2024`, 9 / 0.
- **H5**: `returnsql`, disk usage, `editable_only` and smart searches still go to core in that mode.

**To decide:** accept these as they are?

---

## 4. Core's own bugs and quirks

Four core behaviours are wrong in core itself, in files identical to `master`. The decision so far is to leave
core as it is.

| Ref | Search | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| Core 1 | `!last` with a node filter: `!last10, @@201` | One row, or one empty row when nothing matches | The right resources | 1 / 2, and 1 / 0 when nothing matches | The inner query has an aggregate and no `GROUP BY` ([`search_functions.php:1141`](../../../include/search_functions.php:1141)) |
| Core 2 | An exact value on a date-range field: `eventdates:2024` | Nothing | Matches the year, month, day or date of either end | Local 0 / 1. Test system 0 / 2 | The SQL parameters are bound in the wrong order ([`do_search_keywords.php:151`](../../../include/do_search_keywords.php:151)) |
| Core 3 | `!resource<n>` followed by a term containing digits | Nothing. It builds the resource number from every digit in the search | The resource | 0 / 1 | [`search_functions.php:1422`](../../../include/search_functions.php:1422) |
| Core 4 | The same node alone and then inside an OR group: `@@201, @@201@@203` | Nothing | Nothing, agreeing with core | 0 / 0, where 128 were expected | Removing the first token also breaks the group ([`do_search.php:529`](../../../include/do_search.php:529)) |

- **Core 1**: a node filter turns the hit-count column into an aggregate
  ([`do_search_nodes.php:24`](../../../include/do_search_nodes.php:24)). Keywords alone do not trigger it. MySQL
  accepts the query because core removes `ONLY_FULL_GROUP_BY` at connect
  ([`database_functions.php:298`](../../../include/database_functions.php:298)).
- **Core 2**: the join has four placeholders in the order field, value, field, value, and binds field, field,
  value, value. Neither engine does what core intends, which is "the value falls inside the range": a day inside
  the range is 0 / 0, the start date 0 / 1. With the parameters swapped, core's own SQL finds it. Advanced search
  sends this form when `$daterange_search` is off.
- **Core 3**: `!resource1100, date:2023-03` looks for resource 1100202303.
- **Core 4**: the other order, `@@201@@203, @@201`, works.

**To decide:** should the plugin copy core for Core 2, keep its own rule, or decline that search?

---

## 5. The test system: which findings apply there

On the test system users would meet A1, B1 to B3, B5, C1, C2, D1, E1 and G2 today. E2, E8 and E9 do not apply.

| Findings | Applies today | Why |
|---|---|---|
| A1 CSV export | Yes, all 14 user groups | The plugin is active for every group |
| B1 date dropdowns | Yes, if the "By date" dropdowns are shown | They are shown by default |
| B2, B3 search-box text after a click | Yes | Only one fixed-list field is on the simple search bar, so most clicked values are rewritten as text |
| B4 date range end | Not known | Depends on `$daterange_search` |
| B5 stop words, C1 prefix, C2 wildcards | Yes | Any search can meet them |
| D1 quoted phrases | Yes | `$stemming` is on |
| E1 freshness | Yes | Nothing updates between reindexes |
| E2 access from the index | No | No group or user has a search filter |
| E3 non-indexed date field | Hand-typed only | The one such field is on neither search form |
| E8, E9 HTML and translations | No | No formatted fields and no translated values |
| E10 long values | Barely | One value over 500 characters in an indexed field |
| G2 next / previous | Yes | The empty search alone has 100,862 results |
| F access items | Not run | The two API users are ordinary users |

From the read-only survey (`harness/results/live/db_survey.txt`):

- 100,862 resources in the default workflow state; 110 metadata fields, 22 of them inactive.
- No formatted-text fields, no category trees, no values in translation syntax, no search filters.
- 3,060 values longer than 500 characters, one of them in an indexed field.
- Two fields on the simple search bar: the date field and one checkbox list.
- The plugin is active for every user group. One group runs it in Typesense-only mode, so a declined search
  returns nothing for its users. One group has it switched off. The other 12 fall back to core.

Not from the survey: `$stemming` is on (known from earlier work there and consistent with D1). Whether
`$daterange_search` is on was not established.

---

## 6. How it was tested, and its limits

Every difference was measured by running both engines on the same search, locally and, for the main ones, on
the test system.

| Instrument | What it is | Size |
|---|---|---|
| Local comparison (`harness/ab/`) | Core's real search code with SQLite standing in for MySQL, and the plugin's real code on a local Typesense 30.2 | About 440 hand-written cases, plus 2,481 generated combinations run with stemming off and on |
| Trace (`harness/trace/`) | No database and no Typesense: shows the SQL core builds and the request the plugin would send | 7 scripts |
| Test system (`harness/live/`) | The same search sent through the API as two users with the same permissions, one with the plugin off and one with it on | 59 searches, paced to keep the load light |
| Database survey (`harness/live/db_survey.php`) | Read-only queries on the test system's database | Fields, values, plugin settings |

- **Local figures** come from a small fixture and use its own equivalent of the example shown. They show
  behaviour, not scale.
- **SQLite is not MySQL.** Core's wildcard and full-text matching is emulated locally. Accent folding, which
  MySQL does through its collation, is inferred.
- **Typesense-only mode on the test system.** The plugin user's group has it on, so a declined search returns
  nothing there. Whether a zero was a decline or a served zero was decided from the local run of the same search.
- **Not checked:** scripts without spaces between words (Chinese, Japanese, Thai), anything that only shows in
  a browser, and searching for collections (`do_collections_search()`), which never reaches the plugin.

---

## 7. Open questions

1. Is searching for collections still out of scope?
2. Is `$daterange_search` on for the target system? It decides whether B4 matters.
3. F2: is the upload-share path reachable in practice? It needs a run on a real system.

---

## Appendix A: earlier numbering

Earlier notes numbered the findings 1 to 33 and K1 to K16 in the order they were found.

| Theme | New reference = earlier number |
|---|---|
| A | A1 = 17, A2 = 14 |
| B | B1 = 1, B2 = 4, B3 = 3, B4 = 6, B5 = 22 and K2, B6 = 21, B7 = 10, B8 = 5, B9 = 27, B10 = 11, B11 = K3, B12 = K4, B13 = K15, B14 = K14, B15 = 12, B16 = K8, B17 = 13 |
| C | C1 = 2, C2 = 18, C3 = 19, C4 = 33 |
| D | D1 = 26, D2 = K13 |
| E | E1 = K1, E2 = 16, E3 = 20, E4 = K6, E5 = K7, E6 = K5, E7 = K16, E8 = 7, E9 = 8, E10 = 9 |
| F | F1 = K9, F2 = 24, F3 = 15, F4 = K10, F5 = K11, F6 = 25 |
| G | G1 = K12, G2 = 23 |
| H | H1 = 28, H2 = 29, H3 = 30, H4 = 31, H5 = 32 |
| Core | Core 1 and Core 2 were "the two core bugs"; Core 3 and Core 4 were the quirks found by the combination tests |

---

## Appendix B: how a search reaches the plugin

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
  "did you mean" string) or a field is not viewable by the user (it returns `false`).
- The plugin is handed core's keyword list, not the raw string. Where core has already turned a term into a node
  the plugin only has to honour the bucket. Where core treats a term specially without changing the list (a
  date dropdown, a punctuated token, a stop word), the plugin has to know the rule too. Theme B is the set of
  rules it does not have.

The plugin ([`typesense_search_run()`](../include/typesense_search_query.php:1303)) picks one mode from the
search (collection, last, list, contributions, has-data, archive-pending, user-pending, single resource, or
standard), adds keyword matching and the restrictions, sends one `multi_search` to Typesense, and fetches the
rows for the returned refs from MySQL with core's own column list.

It always leaves these to core: `returnsql`, disk-usage totals, `editable_only` and smart-collection searches
([`all.php:88`](../hooks/all.php:88)). Keyword usage statistics are still logged for searches the plugin serves
([`do_search.php:374`](../../../include/do_search.php:374), read from the code).

---

## Appendix C: findings from the search catalogue (6 October 2026)

The search catalogue ([core-search-catalogue.md](core-search-catalogue.md)) sent every form of search core
accepts through the API on the test system, as both test users, 275 rows in all. These are the items it added
to the findings above. Numbers are test-system totals, core / plugin; the letters and numbers in brackets are
catalogue rows.

### Core

| Ref | Search | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| Core 5 | `!empty` by field name: `!emptynumberfield` | Binds the name as an integer, which MySQL reads as 0, so it matches the first field whose name is not a number: field 1, keywords. `!emptynosuchfield` does the same instead of the intended "invalid !empty search" exit | Declines | 100,004 / 0 for both; `!empty170` is 100,850 (F45, F46, F49) | [`do_search_keywords.php:316`](../../../include/do_search_keywords.php:316) |
| Core 6 | `!empty` of a field that belongs to one resource type: `!empty95` | Meant to limit candidates to that type; the type condition sits inside the NOT IN subquery, so every resource of the other types comes back | Declines | 100,457 / 0, where at most 5,400 are of type 5 (F47) | [`do_search_keywords.php:566`](../../../include/do_search_keywords.php:566) |
| Core 7 | `!last` followed by a space and a term: `!last100 sculpture` | Reads "100 sculpture" as the number; not an integer, so 1000. With a comma, `!last100, sculpture`, it is 100 | Same as core | 1,000 / 1,000, and 100 / 100 with the comma (F4, F69, F68) | [`search_functions.php:1127`](../../../include/search_functions.php:1127) |
| Core 8 | A sort by a metadata field that is not a resource-table join column: `order_by=field170` | Swaps the order for resourceid, then the field branch overwrites that entry with "resourceid DESC" as a column name: an SQL error, so no results | Declines the sort | 0 / 0 for `sculpture`, which has 49,468 (G26, G43) | [`search_functions.php:3006`](../../../include/search_functions.php:3006), [`search_functions.php:3245`](../../../include/search_functions.php:3245) |
| Core 9 | OR without a field: `sculpture;landscape` | Looks the whole string up as one keyword: unknown, so a suggestion. A field search with the same string (`title:sculpture;landscape`) creates that keyword as a side effect, after which the OR works | Declines | 0 / 0 before, 83,840 / 0 after (A16, B5, H32) | [`do_search_keywords.php:387`](../../../include/do_search_keywords.php:387), [`do_search_keywords.php:429`](../../../include/do_search_keywords.php:429) |
| Core 10 | A search with no whitespace: `sculpture,landscape` | Kept as one keyword; the comma then counts as punctuation, so it becomes the phrase "sculpture landscape" | Two words | 22 / 34,409; with a space after the comma 34,408 / 34,409 (I2, A3) | [`do_search.php:137`](../../../include/do_search.php:137), [`do_search_keywords.php:286`](../../../include/do_search_keywords.php:286) |

- **Core 7** also explains `!last100 !collection3808`: the second special search is skipped by the keyword
  stage, and the space turns the first into `!last1000` (F68).
- `!related121409 sculpture` ran past the API's 120-second limit on the test system; `!related121409` alone
  took under a second (F22, F23). A performance point rather than a bug.
- Two paths in the advanced search form are unreachable rather than wrong: `startdate:` and `enddate:` terms
  are still assembled ([`search_functions.php:188`](../../../include/search_functions.php:188)) but no page posts
  those inputs and nothing in `do_search()` handles them (D16); and the branch that strips the first and last
  characters of a multi-word dropdown value ([`search_functions.php:290`](../../../include/search_functions.php:290))
  is never used, because dropdowns post node refs (I12).
- On the test system some option names are stored with a leading byte-order mark, so `materials:bronze` never
  matches the option by name in core and falls back to a keyword search within the field. The plugin drops the term, which is B3: `materials:bronze,
  materials:wood` is 0 / 128 (C7).

### Plugin

| Ref | What the user does | Core | Plugin | Measured | Where |
|---|---|---|---|---|---|
| B18 | Excludes a phrase: `-"sculpture park"` | Removes the resources with the phrase | Sends the term as it is and Typesense ignores it, so nothing is excluded | 93,979 / 100,862 (A10) | [`query.php:622`](../include/typesense_search_query.php:622) |
| B19 | Searches a year as a word: `2024` | Dates are indexed as keywords (year, year-month, date), so every date field matches, plus any text | Date values are held only as filter representations, which free text does not search | 10,289 / 0 (A26); `2024, sculpture` 5,477 / 5,476 (H36) | [`query.php:534`](../include/typesense_search_query.php:534), [`functions.php:1300`](../include/typesense_search_functions.php:1300) |
| B20 | Types a comma with no space: `sculpture,landscape` | One keyword, read as a phrase (Core 10) | Two words | 22 / 34,409; `title:sculpture,landscape` 0 / 25 (I2, I3) | [`query.php:622`](../include/typesense_search_query.php:622) |
| B21 | Quotes after the colon: `title:"henry moore"` | The tokens are `title:"henry` and `moore"`; the quotes are stripped, so henry in the title and moore anywhere | Takes the quoted value as a phrase in the field | 251 / 1 (B4) | [`query.php:594`](../include/typesense_search_query.php:594) |
| B22 | An unbalanced quote: `"sculpture` | Drops the quote | Sends it, and Typesense finds nothing | 49,468 / 0 (I8) | [`query.php:558`](../include/typesense_search_query.php:558) |
| E11 | Date values that are not dates: a five-digit year, a year of 0000, a day of 00 | Compares the text, so they fall inside any range that starts early enough | Not parsed, so not in the index | `date:rangeend2005-12-31` 722 / 652 (D7) | [`functions.php:1753`](../include/typesense_search_functions.php:1753) |
| E12 | A plain number on a numeric field: `numberfield:42` | A keyword search within the field | Filters the field's string attribute, which a numeric field does not have | 2 / 0 (E6) | [`query.php:780`](../include/typesense_search_query.php:780), [`functions.php:1271`](../include/typesense_search_functions.php:1271) |
| E13 | A number range on a text field: `title:numrange1\|10` | Accepted for any single-line text field; compares the values as numbers | Filters a numeric attribute the field does not have | 2,937 / 0 (E7) | [`query.php:754`](../include/typesense_search_query.php:754) |
| E14 | A number alone: `416` | Matches the fragments that the partial index stores for original filenames, plus resource 416 | Fragments are held for field searches only, not for free text | 100 / 0 (A25) | [`functions.php:1330`](../include/typesense_search_functions.php:1330) |
| H6 | `!lastabc` | 1000 | Reads the command as "lastabc" and declines | 1,000 / 0 (F3) | [`query.php:436`](../include/typesense_search_query.php:436) |
| H7 | `!LAST100` | Not `!last`, since the prefix test is case-sensitive: an unknown special search, so everything | Lower-cases the name, then re-reads the number case-sensitively: 1000 | 100,862 / 1,000 (I9) | [`last.php:16`](../include/modes/last.php:16), [`search_functions.php:1110`](../../../include/search_functions.php:1110) |
| H8 | A second special search: `!last100 !collection3808` | Skips the second word | Searches it as text | 1,000 / 0 (F68) | [`query.php:622`](../include/typesense_search_query.php:622), [`do_search_keywords.php:88`](../../../include/do_search_keywords.php:88) |

- **B19** and **E14** are the same gap seen from two sides: words that core's index holds because of how it
  indexes dates and partial fields are not words to Typesense.
- **H6**, **H7** and **H8** only arise from hand-typed or API searches.

**To decide:** B18 and B19 affect ordinary searches and belong with theme B's decision; the rest can be accepted
or declined with the others in H.
