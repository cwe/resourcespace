# Core search catalogue

Every form of search that core's `do_search()` accepts, one row each, with an example that uses values from the
test database. The list was built from the code at `typesense` c72fe64e (`include/do_search.php`, `do_search_keywords.php`,
`do_search_nodes.php`, `search_functions.php`, the advanced search form and the search bar). It is for checking through
by hand: tick the last column when a row has been confirmed, or correct the row.

- **Search** names the form. **String and parameters** is exactly what is sent to the API (`do_search`, or
  `search_get_previews` for a day limit); parameters not shown are `restypes=""`, `order_by=relevance`, `archive=0`,
  `sort=desc`, `fetchrows=0,1`.
- **What core does** is read from the code; **Where** links to it.
- **Core** and **Plugin** are the totals from `harness/live/catalogue_run.php` (a user whose group has the plugin
  off, and one whose group has it in Typesense-only mode, so a plugin 0 can mean "declined"). Five refs are shown when the row
  checks an order. "–" means not run yet; the last run was 2026-10-06. Rows marked n/a cannot be sent through the API.
- Workflow states 1, 2 and 3 are hidden from both test users (permissions z1, z2, z3), so every total is over the
  states they can see.

To rerun: `cd plugins/typesense_search/harness && RS_BASE_URL=… RS_USER_CORE=… RS_KEY_CORE=… RS_USER_TS=… RS_KEY_TS=… php live/catalogue_run.php [--slow] [ids]`,
then `php catalogue_table.php` to refresh this file. `live/catalogue_values.php` prints the database values the examples use.

## The test database

The examples use values that exist on the test database: its field short names, option names, node refs, collection refs
and user refs. `live/catalogue_values.php` lists the candidates on any database, so the cases can be rewritten for another one.

## Contents

- [A. Keywords](#a-keywords)
- [B. Field-specific terms (text fields)](#b-field-specific-terms-text-fields)
- [C. Fixed-list fields and node tokens](#c-fixed-list-fields-and-node-tokens)
- [D. Dates](#d-dates)
- [E. Numeric field](#e-numeric-field)
- [F. Special searches](#f-special-searches)
- [G. Parameters](#g-parameters)
- [H. Combinations](#h-combinations)
- [I. Syntax edge cases](#i-syntax-edge-cases)

## A. Keywords

Free text. Words are separated by spaces or commas and are ANDed; each word is looked up in the keyword table (stemmed when $stemming is on) and matched through node_keyword over every indexed field the user may see. A word that is not in the keyword table ends the search with a suggestion instead of results.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| A1 | One keyword | `sculpture` | Keyword resolved (stem), one union over node_keyword, scored by hit counts. | [do_search_keywords.php:358](../../../include/do_search_keywords.php:358), [do_search_keywords.php:717](../../../include/do_search_keywords.php:717) | 49,468 | 49,468 | [ ] |
| A2 | Two keywords | `sculpture landscape` | One union per word, all must match (AND). | [do_search.php:139](../../../include/do_search.php:139), [do_search_union_assembly.php:55](../../../include/do_search_union_assembly.php:55) | 34,408 | 34,409 | [ ] |
| A3 | Comma separated | `sculpture, landscape` | A comma is a separator like a space; same as A2. | [search_functions.php:2054](../../../include/search_functions.php:2054) | 34,408 | 34,409 | [ ] |
| A4 | Upper case | `SCULPTURE Park` | Lower-cased before matching. | [search_functions.php:2157](../../../include/search_functions.php:2157) | 19,680 | 19,688 | [ ] |
| A5 | Plural (stemming) | `sculptures` | Stemmed to the same keyword as A1. | [do_search_keywords.php:358](../../../include/do_search_keywords.php:358) | 49,468 | 49,468 | [ ] |
| A6 | Quoted single word | `"sculpture"` | Exact word, not stemmed, position join on one keyword. | [do_search_keywords.php:758](../../../include/do_search_keywords.php:758) | 49,069 | 0 | [ ] |
| A7 | Quoted phrase | `"sculpture park"` | Words at consecutive positions in one value of one field. | [do_search_keywords.php:758](../../../include/do_search_keywords.php:758), [do_search_keywords.php:816](../../../include/do_search_keywords.php:816) | 6,883 | 0 | [ ] |
| A8 | Phrase with a stop word | `"black and white"` | "and" is skipped and the position gap widened by one. | [do_search_keywords.php:780](../../../include/do_search_keywords.php:780) | 3,179 | 3,179 | [ ] |
| A9 | NOT a keyword | `-landscape` | Everything except resources with the keyword (NOT IN subquery). | [do_search_keywords.php:303](../../../include/do_search_keywords.php:303), [do_search_keywords.php:471](../../../include/do_search_keywords.php:471) | 32,045 | 32,078 | [ ] |
| A10 | NOT a phrase | `-"sculpture park"` | Everything except resources with the phrase. | [do_search_keywords.php:828](../../../include/do_search_keywords.php:828) | 93,979 | 100,862 | [ ] |
| A11 | Trailing wildcard | `sculpt*` | Full-text MATCH on node names ("+sculpt*") over all indexed fields. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 51,007 | 49,610 | [ ] |
| A12 | Leading wildcard | `*scape` | RLIKE on node names (".*?scape" at a word boundary). | [do_search_keywords.php:648](../../../include/do_search_keywords.php:648) | 68,601 | 381 | [ ] |
| A13 | Wildcard inside a word | `sc*pture` | Goes to the full-text branch as "+sc*pture"; MySQL honours only the trailing part of the pattern, so this behaves like sc*. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 69,679 | 0 | [ ] |
| A14 | Short wildcard (under 3 letters) | `so*` | LIKE "so%" on the keyword table instead of full text. | [do_search_keywords.php:634](../../../include/do_search_keywords.php:634) | 15,618 | 6,676 | [ ] |
| A15 | OR of two wildcards | `land*;sun*` | Semicolon forces the RLIKE branch: "(land.*?\|sun.*?)". | [do_search_keywords.php:648](../../../include/do_search_keywords.php:648), [search_functions.php:3263](../../../include/search_functions.php:3263) | 71,530 | 0 | [ ] |
| A16 | OR of two plain words | `sculpture;landscape` | The combined word "sculpture;landscape" is looked up as one keyword. First run: unknown, so a suggestion and no results. B5 then created that keyword as a side effect, and the re-run gave the OR (sculpture or landscape). See H32. | [do_search_keywords.php:301](../../../include/do_search_keywords.php:301), [do_search_keywords.php:387](../../../include/do_search_keywords.php:387) | 83,840 | 0 | [ ] |
| A17 | Stop word alone | `the` | Skipped ($noadd); no terms left, so everything. | [do_search_keywords.php:325](../../../include/do_search_keywords.php:325), [definitions.php:943](../../../include/definitions.php:943) | 100,862 | 32,537 | [ ] |
| A18 | Stop word plus a word | `the sculpture` | Stop word dropped; same as A1. | [do_search_keywords.php:325](../../../include/do_search_keywords.php:325) | 49,468 | 17,228 | [ ] |
| A19 | Hyphenated word | `black-and-white` | Separators split it into black, and, white; then as A8 but unordered (AND of the words). | [do_search_keywords.php:367](../../../include/do_search_keywords.php:367), [config.default.php:1340](../../../include/config.default.php:1340) | 3,179 | 3,194 | [ ] |
| A20 | Apostrophe | `atkinson's` | The apostrophe is a separator: atkinson AND s. | [do_search_keywords.php:367](../../../include/do_search_keywords.php:367) | 2 | 3 | [ ] |
| A21 | Related keyword | `antony` | Also matches the related keyword anthony (keyword_related). | [do_search_keywords.php:436](../../../include/do_search_keywords.php:436) | 1,491 | 205 | [ ] |
| A22 | The related word itself | `anthony` | Also matches antony; compare with A21. | [do_search_keywords.php:436](../../../include/do_search_keywords.php:436) | 1,491 | 1,300 | [ ] |
| A23 | Resource type name | `document` | Every resource of the type named Document, plus any with the word. | [do_search_keywords.php:56](../../../include/do_search_keywords.php:56) | 490 | 375 | [ ] |
| A24 | Contributor name | `admin` | With $index_contributed_by on, every upload by a user whose name matches; otherwise just the word (the option is off on the test system). | [do_search_keywords.php:62](../../../include/do_search_keywords.php:62), [config.default.php:2284](../../../include/config.default.php:2284) | 13 | 16 | [ ] |
| A25 | A resource number | `416` | With $config_search_for_number off (default): the word 416 plus resource 416, which is ordered first. On: same as !resource416. | [do_search.php:117](../../../include/do_search.php:117), [do_search.php:320](../../../include/do_search.php:320), [do_search.php:283](../../../include/do_search.php:283) | 100 | 0 | [ ] |
| A26 | A year as a word | `2024` | Dates are indexed as year, year-month and full date, so this matches resources dated 2024 in any date field, plus text containing 2024. | [search_functions.php:2059](../../../include/search_functions.php:2059) | 10,289 | 0 | [ ] |
| A27 | Full-text box | `"@FULL_TEXT:distant island"` | MATCH AGAINST in boolean mode over node names (what the advanced search full-text box sends). | [do_search_keywords.php:30](../../../include/do_search_keywords.php:30), [search_functions.php:242](../../../include/search_functions.php:242) | 956 | 0 | [ ] |
| A28 | Full-text with operators | `"@FULL_TEXT:+distant +island"` | Boolean operators pass straight to MySQL. | [do_search_keywords.php:30](../../../include/do_search_keywords.php:30) | 9 | 0 | [ ] |
| A29 | Full-text phrase | `"@FULL_TEXT:[QUOTES]distant island[QUOTES]"` | [QUOTES] becomes a double quote: a MySQL phrase search. | [do_search_keywords.php:31](../../../include/do_search_keywords.php:31) | 8 | 0 | [ ] |
| A30 | Misspelt word | `sculptre` | An unknown word gives no results and a sound-alike suggestion instead of an array; on the test system this misspelling happens to exist as a keyword, so A31 shows the real case. | [do_search_keywords.php:395](../../../include/do_search_keywords.php:395), [do_search_suggest.php:4](../../../include/do_search_suggest.php:4) | 1 | 1 | [ ] |
| A31 | Known plus unknown word | `sculpture zzzzqqq` | No results; the suggestion drops the unknown word. | [do_search_suggest.php:10](../../../include/do_search_suggest.php:10) | 0 | 0 | [ ] |
| A32 | Only an asterisk | `*` | Treated as the empty search. | [do_search.php:85](../../../include/do_search.php:85) | 100,862 | 100,862 | [ ] |
| A33 | Empty search | (empty) order_by=resourceid | No keyword unions: every resource the filters allow. | [do_search.php:412](../../../include/do_search.php:412) | 100,862 | 100,862 | [ ] |
## B. Field-specific terms (text fields)

shortname:value binds one word to one field. Only the word next to the colon is bound; the rest of a multi-word value is free text, unless the whole term is quoted as "shortname:two words", which the advanced search and the search bar produce.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| B1 | Word in a field | `title:sculpture` | Keyword union restricted to nodes of that field. | [do_search_keywords.php:95](../../../include/do_search_keywords.php:95), [do_search_keywords.php:717](../../../include/do_search_keywords.php:717) | 4,226 | 4,226 | [ ] |
| B2 | Two words after a field | `title:henry moore` | henry is bound to title; moore is free text. | [search_functions.php:2103](../../../include/search_functions.php:2103) | 251 | 251 | [ ] |
| B3 | Quoted phrase in a field | `"title:henry moore"` | Phrase search restricted to the field (the form the advanced search sends). | [do_search_keywords.php:758](../../../include/do_search_keywords.php:758), [search_functions.php:275](../../../include/search_functions.php:275) | 251 | 0 | [ ] |
| B4 | Quotes after the colon | `title:"henry moore"` | Not the supported form: the tokens are title:"henry and moore", but the stray quotes are stripped, so it works out the same as B2. | [search_functions.php:2103](../../../include/search_functions.php:2103) | 251 | 1 | [ ] |
| B5 | OR inside a field | `title:sculpture;landscape` | Either word in the field (alternative keywords IN). | [do_search_keywords.php:301](../../../include/do_search_keywords.php:301) | 5,120 | 0 | [ ] |
| B6 | Wildcard in a field | `title:sculpt*` | Full-text MATCH restricted to the field. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 4,522 | 4,522 | [ ] |
| B7 | Leading wildcard in a field | `title:*scape` | RLIKE restricted to the field. | [do_search_keywords.php:648](../../../include/do_search_keywords.php:648) | 921 | 0 | [ ] |
| B8 | Another text field | `credit:wilde` | As B1 on the credit field. | [do_search_keywords.php:95](../../../include/do_search_keywords.php:95) | 18,879 | 18,879 | [ ] |
| B9 | Field name with a slash | `photographer/videographer:wilde` | The name is looked up whole; one word of a dynamic-keyword option matches through node_keyword. | [do_search_keywords.php:95](../../../include/do_search_keywords.php:95), [do_search_keywords.php:253](../../../include/do_search_keywords.php:253) | 33,517 | 100,862 | [ ] |
| B10 | Punctuation in a field value | `exhibitiontitle:fabric-ation` | Core quotes the term and searches the phrase "fabric ation" in that field. | [do_search_keywords.php:286](../../../include/do_search_keywords.php:286) | 1,453 | 1,453 | [ ] |
| B11 | Dots in a field value | `dimensions:16.5x16.5cm` | Quoted, then split on the dots: the phrase 16, 5x16, 5cm. | [do_search_keywords.php:286](../../../include/do_search_keywords.php:286) | 20 | 20 | [ ] |
| B12 | Text field not flagged for indexing | `camera:nikon` | The field exists but has no keywords: nothing, although the values are there. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 0 | 0 | [ ] |
| B13 | Multi-line field not flagged for indexing | `accessibilityalttext:island` | Nothing, as B12. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 0 | 0 | [ ] |
| B14 | Partially indexed field, fragment | `originalfilename:ksho` | Fragments of 3+ letters are indexed, so "ksho" finds filenames containing "workshop". | [search_functions.php:2270](../../../include/search_functions.php:2270) | 665 | 665 | [ ] |
| B15 | Unknown field name | `foo:sculpture` | The colon becomes a space: foo and sculpture as two words (foo happens to exist as a keyword on the test system; an unknown word would give a suggestion). | [do_search_keywords.php:112](../../../include/do_search_keywords.php:112) | 25 | 25 | [ ] |
| B16 | NOT before a field term | `-title:sculpture` | Not supported: read as -title plus sculpture. | [do_search_keywords.php:112](../../../include/do_search_keywords.php:112) | 49,241 | 0 | [ ] |
| B17 | NOT after the colon | `title:-sculpture` | Quoted, then the word "-sculpture" is omitted: excludes nothing. | [do_search_keywords.php:286](../../../include/do_search_keywords.php:286), [do_search_keywords.php:769](../../../include/do_search_keywords.php:769) | 100,862 | 4,226 | [ ] |
| B18 | Stop word in a field | `title:the` | Skipped: everything. | [do_search_keywords.php:325](../../../include/do_search_keywords.php:325) | 100,862 | 10,564 | [ ] |
| B19 | Field hidden from advanced search, not indexed | `yearai:2025` | Nothing (no keywords); the field is still accepted by name. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 0 | 0 | [ ] |
| B20 | Two terms, one field | `title:sculpture, title:park` | AND of two field-restricted unions. | [do_search_union_assembly.php:55](../../../include/do_search_union_assembly.php:55) | 580 | 580 | [ ] |
| B21 | Two fields | `title:sculpture, credit:wilde` | AND across fields. | [do_search_union_assembly.php:55](../../../include/do_search_union_assembly.php:55) | 316 | 316 | [ ] |
## C. Fixed-list fields and node tokens

An option of a checkbox, dropdown, radio, tree or dynamic-keyword field can be named (shortname:option, quoted when it has spaces) or given as a node token (@@nodeID). Named options become nodes; one word of an option falls back to a keyword search inside the field. Tokens: @@a@@b is OR, separate tokens are AND, @@!a is NOT.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| C1 | Option by name | `orientation:landscape` | Converted to the node (OR bucket of one). | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253) | 58,028 | 58,028 | [ ] |
| C2 | Multi-word option, quoted | `"usagerights:Social media"` | Quoted field term resolved to the node; what the search bar rebuilds from a node. | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253), [searchbar.php:141](../../../include/searchbar.php:141) | 148 | 0 | [ ] |
| C3 | Multi-word option, unquoted | `usagerights:social media` | social is a word in the field; media is free text. | [search_functions.php:2103](../../../include/search_functions.php:2103) | 148 | 4,763 | [ ] |
| C4 | One word of an option | `usagerights:social` | Not an option name, so a keyword search restricted to the field. | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253), [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 148 | 100,862 | [ ] |
| C5 | Wildcard on an option | `usagerights:soc*` | LIKE on keywords within the field. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 148 | 100,862 | [ ] |
| C6 | OR of options | `materials:bronze;wood` | Both nodes in one OR bucket. | [do_search_keywords.php:262](../../../include/do_search_keywords.php:262) | 128 | 128 | [ ] |
| C7 | AND of options | `materials:bronze, materials:wood` | Two buckets: resources with both (the $checkbox_and form). On the test system the first option's name is stored with a leading byte-order mark, so it is not matched as an option and becomes a keyword search within the field; the plugin drops it instead (B3 in the review). | [search_functions.php:293](../../../include/search_functions.php:293), [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 0 | 128 | [ ] |
| C8 | Dropdown option, quoted | `"source:Digital Camera"` | Resolved to the node by name although the field is not flagged for indexing. | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253) | 2,224 | 0 | [ ] |
| C9 | One word of a dropdown option | `source:digital` | Keyword search in a fixed-list field not flagged for indexing: its option words are not in node_keyword, so nothing. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 0 | 100,862 | [ ] |
| C10 | Checkbox option, one word | `yspactivity:learning` | Option name matched case-insensitively. | [do_search_keywords.php:262](../../../include/do_search_keywords.php:262) | 11,671 | 11,671 | [ ] |
| C11 | Dynamic keyword option, quoted | `"artistname:Henry Moore"` | Resolved to the node by full name. | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253) | 2,713 | 0 | [ ] |
| C12 | One word of a dynamic keyword | `artistname:moore` | Keyword search in the field. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 2,713 | 100,862 | [ ] |
| C13 | Node token | `@@389` | Removed from the string and joined as a node bucket. | [do_search.php:111](../../../include/do_search.php:111), [do_search.php:519](../../../include/do_search.php:519), [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 58,028 | 58,028 | [ ] |
| C14 | OR of nodes | `@@389@@388` | One bucket with both nodes (IN). | [do_search.php:519](../../../include/do_search.php:519) | 94,936 | 94,936 | [ ] |
| C15 | AND of nodes | `@@425, @@389` | Two buckets, two joins. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 49 | 49 | [ ] |
| C16 | AND of nodes, space separated | `@@389 @@425` | Same as C15. | [do_search.php:519](../../../include/do_search.php:519) | 49 | 49 | [ ] |
| C17 | NOT a node | `@@!432` | NOT EXISTS on resource_node. | [do_search_nodes.php:31](../../../include/do_search_nodes.php:31) | 100,461 | 100,461 | [ ] |
| C18 | NOT inside an OR word | `@@!389@@388` | NOT is only honoured on a single token; here it is ignored and both nodes are ORed. | [do_search.php:538](../../../include/do_search.php:538) | 94,936 | 94,936 | [ ] |
| C19 | Unknown node | `@@999999999` | The node has no field; the token is kept and joins on a node that no resource has. | [do_search.php:534](../../../include/do_search.php:534) | 0 | 0 | [ ] |
| C20 | Simple-search-bar checkbox | `@@17913` | The fixed-list field shown on the simple search bar. | [searchbar.php:53](../../../include/searchbar.php:53) | 264 | 264 | [ ] |
| C21 | Dynamic keyword node | `@@2997` | The node of a dynamic-keyword option. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 33,333 | 33,333 | [ ] |
| C22 | GPT keyword node | `@@460` | A node from a large dynamic-keyword field. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 37,603 | 37,603 | [ ] |
| C23 | Option name and a node of the same field | `orientation:landscape, @@388` | Two buckets on one field: both options needed. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 0 | 0 | [ ] |
| C24 | Option names as the search page converts them | `materials:bronze;wood` | Same as C6. pages/search.php rewrites this to @@6125@@6130 before searching; the API gets the text form (C6). | [search.php:76](../../../pages/search.php:76), [search.php:150](../../../pages/search.php:150) | 128 | 128 | [ ] |
## D. Dates

A date field (date, date and time, expiry, date range) accepts a value or a range after the colon. Values are matched as a prefix on the stored string, so a year, a month or a day all work; ranges compare strings. basicyear, basicmonth and basicday address $date_field from the simple search bar. These searches are slow in core on the test system (several seconds each).

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| D1 | Year | `date:2024` | LIKE "2024%" on the field's node names. | [do_search_keywords.php:130](../../../include/do_search_keywords.php:130), [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 8,838 | 8,838 | [ ] |
| D2 | Year and month | `date:2024-05` | LIKE "2024-05%". | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 572 | 572 | [ ] |
| D3 | Full date | `date:2024-05-31` | LIKE "2024-05-31%". | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 317 | 317 | [ ] |
| D4 | Date stored with a time | `date:2017-05-10` | The prefix still matches a value stored with a time of day. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 626 | 626 | [ ] |
| D5 | Range | `date:rangestart2024-01-01end2024-02-29` | name >= start AND name <= "end 23:59:59". | [do_search_keywords.php:180](../../../include/do_search_keywords.php:180) | 822 | 854 | [ ] |
| D6 | Range, start only | `date:rangestart2026-01-01` | name >= start. | [do_search_keywords.php:193](../../../include/do_search_keywords.php:193) | 2,006 | 2,006 | [ ] |
| D7 | Range, end only | `date:rangeend2005-12-31` | name <= end. | [do_search_keywords.php:198](../../../include/do_search_keywords.php:198) | 722 | 652 | [ ] |
| D8 | Range as the EDTF box sends it | `date:rangestart2024-00-00end2024-12-99` | "2024/2024" in the EDTF box becomes this; string comparison still brackets the year. | [search_functions.php:3341](../../../include/search_functions.php:3341) | 8,838 | 21,151 | [ ] |
| D9 | Range, end month without a day | `date:rangestart2024-01-01end2024-02-31` | The form pads a missing day with 31; as a string "2024-02-31" is after every February date. | [search_functions.php:3362](../../../include/search_functions.php:3362) | 822 | 21,151 | [ ] |
| D10 | Simple search year | `basicyear:2024` | Joins $date_field once and filters LIKE "2024-__-__%". | [do_search_keywords.php:163](../../../include/do_search_keywords.php:163), [do_search_keywords.php:851](../../../include/do_search_keywords.php:851) | 8,838 | 0 | [ ] |
| D11 | Simple search month, any year | `basicmonth:05` | LIKE "____-05-__%". | [do_search_keywords.php:851](../../../include/do_search_keywords.php:851) | 9,511 | 0 | [ ] |
| D12 | Simple search day, any month | `basicday:05` | LIKE "____-__-05%" (the day dropdown needs $searchbyday). | [do_search_keywords.php:851](../../../include/do_search_keywords.php:851), [config.default.php:539](../../../include/config.default.php:539) | 3,227 | 0 | [ ] |
| D13 | Year and month dropdowns | `basicyear:2025, basicmonth:06` | One join, LIKE "2025-06-__%". | [do_search_keywords.php:851](../../../include/do_search_keywords.php:851) | 1,542 | 0 | [ ] |
| D14 | Year, month and day dropdowns | `basicyear:2025, basicmonth:06, basicday:05` | LIKE "2025-06-05%". | [do_search_keywords.php:851](../../../include/do_search_keywords.php:851) | 256 | 0 | [ ] |
| D15 | Legacy "any year" syntax | `date:nnnn\|05` | n becomes _, \| becomes -: LIKE "____-05%". | [do_search_keywords.php:146](../../../include/do_search_keywords.php:146) | 9,511 | 0 | [ ] |
| D16 | startdate / enddate form inputs | `startdate:2024-01-01, enddate:2024-12-31` | search_form_to_search_query still assembles these, but no page posts the inputs and nothing in do_search handles them: unknown field names, so words and a suggestion. | [search_functions.php:188](../../../include/search_functions.php:188), [do_search_keywords.php:112](../../../include/do_search_keywords.php:112) | 0 | 0 | [ ] |
| D17 | Date-and-time field, full date | `expirydate:2029-05-31` | As D3 on a type 4 field. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 224 | 224 | [ ] |
| D18 | Date-and-time field, year | `expirydate:2029` | As D1. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 3,171 | 3,171 | [ ] |
| D19 | Date-and-time field, range | `expirydate:rangestart2029-01-01end2029-12-31` | As D5. | [do_search_keywords.php:180](../../../include/do_search_keywords.php:180) | 3,171 | 3,171 | [ ] |
| D20 | Partial value (year only) | `dateofartwork:2025` | Prefix match includes year-only values stored as YYYY-00-00. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 2,593 | 2,593 | [ ] |
| D21 | Month, including month-only values | `eventdate:2022-04` | Prefix match includes month-only values. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 509 | 509 | [ ] |
| D22 | Date field not flagged for indexing | `datephototaken:2024` | Date terms join the node table directly, so the index flag does not matter. | [do_search_keywords.php:130](../../../include/do_search_keywords.php:130) | 3,676 | 0 | [ ] |
| D23 | Same field, full date | `datephototaken:2012-01-26` | Matches values stored with a time of day. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 4,549 | 0 | [ ] |
| D24 | Date-range field, one value | `daterangetest:2020` | Meant to find ranges containing 2020; the parameters are bound in the wrong order, so nothing (Core 2 in the review). | [do_search_keywords.php:149](../../../include/do_search_keywords.php:149) | 0 | 2 | [ ] |
| D25 | Date-range field, range | `daterangetest:rangestart2020-01-01end2021-12-31` | Start value >= start AND end value <= end, as strings. | [do_search_keywords.php:180](../../../include/do_search_keywords.php:180) | 1 | 2 | [ ] |
| D26 | Two values for one date field | `date:2024, date:2025` | Two joins, both must match: only resources holding both years. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 0 | 0 | [ ] |
| D27 | OR on a date | `date:2024;2025` | No OR for dates: LIKE "2024;2025%", nothing. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 0 | 0 | [ ] |
| D28 | Another date-and-time field | `copyrightexpirationd:2030` | As D1. | [do_search_keywords.php:157](../../../include/do_search_keywords.php:157) | 47 | 47 | [ ] |
## E. Numeric field

A text field with field_constraint = 1 takes shortname:numrangeMIN|MAX. "neg" stands for a minus sign. One bound means an exact value.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| E1 | Range | `numberfield:numrange0\|100` | name >= 0 AND name <= 100 (numeric compare). | [do_search_keywords.php:206](../../../include/do_search_keywords.php:206) | 9 | 9 | [ ] |
| E2 | Exact (one bound) | `numberfield:numrange42\|` | name = 42. | [do_search_keywords.php:222](../../../include/do_search_keywords.php:222) | 2 | 2 | [ ] |
| E3 | Max only | `numberfield:numrange\|100` | Also exact: name = 100. | [do_search_keywords.php:222](../../../include/do_search_keywords.php:222) | 1 | 1 | [ ] |
| E4 | Negative bounds | `numberfield:numrangeneg50\|neg1` | -50 to -1. | [do_search_keywords.php:213](../../../include/do_search_keywords.php:213) | 2 | 2 | [ ] |
| E5 | Decimal bounds | `numberfield:numrange3\|3.14` | 3 to 3.14. | [do_search_keywords.php:206](../../../include/do_search_keywords.php:206) | 1 | 1 | [ ] |
| E6 | Plain number in the field | `numberfield:42` | Not a range: an ordinary keyword search for "42" in the field. | [do_search_keywords.php:297](../../../include/do_search_keywords.php:297) | 2 | 0 | [ ] |
| E7 | numrange on a text field | `title:numrange1\|10` | Accepted for any type 0 field: compares title values as numbers. | [do_search_keywords.php:206](../../../include/do_search_keywords.php:206) | 2,937 | 0 | [ ] |
## F. Special searches

A search starting with ! is handled by search_special() (or by the keyword stage for !empty). Anything after the first space is keywords, nodes or field terms applied on top. A second word starting with ! is ignored. Resource types still apply to most of them, workflow states do not apply to collections, lists and the pending searches, and the z permissions always apply.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| F1 | !last | `!last100` | The 100 highest refs that pass the filters. | [search_functions.php:1110](../../../include/search_functions.php:1110) | 100 | 100 | [ ] |
| F2 | !last, first rows | `!last5` fetchrows=0,5 | Relevance order becomes ref DESC. | [search_functions.php:1115](../../../include/search_functions.php:1115) | 5 [127607,127608,127609,127610,127611] | 5 [127607,127608,127609,127610,127611] | [ ] |
| F3 | !last with no number | `!lastabc` | Not an integer: 1000. | [search_functions.php:1134](../../../include/search_functions.php:1134) | 1,000 | 0 | [ ] |
| F4 | !last plus a keyword | `!last100 sculpture` | The keyword applies, but "100 sculpture" is read as the number: not an integer, so !last1000 (F69 has the comma form that keeps 100). | [do_search.php:126](../../../include/do_search.php:126), [search_functions.php:1141](../../../include/search_functions.php:1141) | 1,000 | 1,000 | [ ] |
| F5 | !last plus a node | `!last1000 @@389` | Node join plus the aggregate hit count without GROUP BY: one row (Core 1 in the review). | [search_functions.php:1141](../../../include/search_functions.php:1141), [do_search_nodes.php:24](../../../include/do_search_nodes.php:24) | 1 | 1,000 | [ ] |
| F6 | !nodownloads | `!nodownloads` | Not in daily_stat as a download. | [search_functions.php:1143](../../../include/search_functions.php:1143) | 94,493 | 0 | [ ] |
| F7 | !duplicates (all) | `!duplicates` | Every resource whose checksum appears more than once. | [search_functions.php:1151](../../../include/search_functions.php:1151) | 59 | 0 | [ ] |
| F8 | !duplicates of one resource | `!duplicates123007` | Resources with the same checksum, including itself. | [search_functions.php:1160](../../../include/search_functions.php:1160) | 2 | 0 | [ ] |
| F9 | !collection | `!collection3808` | Members of a readable collection, any workflow state. | [search_functions.php:1191](../../../include/search_functions.php:1191), [search_functions.php:825](../../../include/search_functions.php:825) | 55 | 55 | [ ] |
| F10 | !collection, public | `!collection3618` | Public collections are readable by everyone. | [search_functions.php:1209](../../../include/search_functions.php:1209) | 949 | 949 | [ ] |
| F11 | !collection, featured | `!collection3706` | Featured collections pass through featured_collection_check_access_control. | [search_functions.php:1231](../../../include/search_functions.php:1231) | 262 | 262 | [ ] |
| F12 | !collection, featured but not public | `!collection3125` | Access decided by the featured-collection rules. | [search_functions.php:1231](../../../include/search_functions.php:1231) | 257 | 257 | [ ] |
| F13 | !collection, someone else's private | `!collection158` | Not in the user's readable set: empty. | [search_functions.php:1235](../../../include/search_functions.php:1235) | 0 | 0 | [ ] |
| F14 | !collection, own empty collection | `!collection{owncollection}` | Readable, no members. | [search_functions.php:1209](../../../include/search_functions.php:1209) | 0 | 0 | [ ] |
| F15 | !collection, own upload collection | `!collection-{userref}` | A negative ref is the user's upload collection. | [search_functions.php:1229](../../../include/search_functions.php:1229) | 0 | 0 | [ ] |
| F16 | !collection plus a keyword | `!collection3808 sculpture` | Keyword applied inside the collection. | [do_search.php:126](../../../include/do_search.php:126) | 55 | 55 | [ ] |
| F17 | !collection, comma then keyword | `!collection3808, sculpture` | Same as F16 (the comma before the space is dropped). | [do_search.php:126](../../../include/do_search.php:126) | 55 | 55 | [ ] |
| F18 | !collection plus a node | `!collection3808 @@389` | Node join inside the collection. | [do_search.php:111](../../../include/do_search.php:111) | 1 | 1 | [ ] |
| F19 | !collection in collection order | `!collection3808` order_by=collection, sort=asc, fetchrows=0,5 | c.sortorder, then date added, then ref. | [search_functions.php:3206](../../../include/search_functions.php:3206) | 55 [59367,59354,59348,59320,59315] | 55 [59367,59354,59348,59320,59315] | [ ] |
| F20 | !collection with a resource type | `!collection3808` restypes=5 | Resource types are not applied to collection searches. | [search_functions.php:751](../../../include/search_functions.php:751) | 55 | 55 | [ ] |
| F21 | !collection with a workflow state | `!collection3808` archive=2 | Workflow states are not applied to collections; the z permissions still are. | [search_functions.php:825](../../../include/search_functions.php:825), [search_functions.php:873](../../../include/search_functions.php:873) | 55 | 55 | [ ] |
| F22 | !related | `!related121409` | Both directions of resource_related; all workflow states. | [search_functions.php:1336](../../../include/search_functions.php:1336), [search_functions.php:831](../../../include/search_functions.php:831) | 488 | 0 | [ ] |
| F23 | !related plus a keyword | `!related121409 sculpture` | Keyword applied to the related set. On the test system this query ran past the 120-second API timeout. | [do_search.php:126](../../../include/do_search.php:126) | error: HTTP 0 connection failed: Operation timed out after 120002 m | 0 | [ ] |
| F24 | !relatedpushed | `!relatedpushed121409` | Related resources of types with push_metadata (view page). | [search_functions.php:1295](../../../include/search_functions.php:1295) | 0 | 0 | [ ] |
| F25 | !geo | `!geo53p5bm1p7t53p7bm1p4` | Bounding box: m is minus, p is the point, b separates lat/long, t separates the corners (53.5,-1.7 to 53.7,-1.4). | [search_functions.php:1355](../../../include/search_functions.php:1355) | 4,893 | 0 | [ ] |
| F26 | !colourkey | `!colourkeyEPNB` | First four letters of the colour key, resources with a preview. | [search_functions.php:1373](../../../include/search_functions.php:1373) | 1,979 | 0 | [ ] |
| F27 | !colour | `!colourE` | Colour key LIKE "E%" OR "_E%". | [search_functions.php:1382](../../../include/search_functions.php:1382) | 70,574 | 0 | [ ] |
| F28 | !rgb | `!rgb:610,391,405` | Nearest average colour, at most 500 rows. | [search_functions.php:1398](../../../include/search_functions.php:1398), [search_functions.php:1407](../../../include/search_functions.php:1407) | 500 | 0 | [ ] |
| F29 | !nopreview | `!nopreview` | has_image = 0. | [search_functions.php:1410](../../../include/search_functions.php:1410) | 105 | 0 | [ ] |
| F30 | !resource | `!resource416` | One resource by ref (the search bar resource ID box). | [search_functions.php:1421](../../../include/search_functions.php:1421), [search.php:236](../../../pages/search.php:236) | 1 | 1 | [ ] |
| F31 | !resource plus a term with digits | `!resource416, date:2024` | Every digit in the string forms the ref: resource 4162024, nothing (Core 3 in the review). | [search_functions.php:1422](../../../include/search_functions.php:1422) | 0 | 1 | [ ] |
| F32 | !archivepending | `!archivepending` | State 1 (pending review); hidden from these users by z1. | [search_functions.php:1426](../../../include/search_functions.php:1426), [search_functions.php:873](../../../include/search_functions.php:873) | 0 | 0 | [ ] |
| F33 | !userpending | `!userpending` | State -1; non-admins only see their own. | [search_functions.php:1430](../../../include/search_functions.php:1430), [search_functions.php:868](../../../include/search_functions.php:868) | 0 | 0 | [ ] |
| F34 | !contributions | `!contributions9` | created_by = 9. | [search_functions.php:1438](../../../include/search_functions.php:1438) | 41,283 | 41,283 | [ ] |
| F35 | !contributions, own | `!contributions{userref}` | Own uploads ($open_access_for_contributor lifts the access filter). | [search_functions.php:1447](../../../include/search_functions.php:1447) | 0 | 0 | [ ] |
| F36 | !images | `!images` | has_image > 0. | [search_functions.php:1459](../../../include/search_functions.php:1459) | 100,757 | 0 | [ ] |
| F37 | !unused | `!unused` | In no collection. | [search_functions.php:1464](../../../include/search_functions.php:1464) | 6,967 | 0 | [ ] |
| F38 | !list | `!list416:4460:8692` | Refs separated by colons (the example refs are in three different states); workflow states not applied, z permissions are. | [search_functions.php:1480](../../../include/search_functions.php:1480), [search_functions.php:825](../../../include/search_functions.php:825) | 1 | 1 | [ ] |
| F39 | !listall | `!listall416:4460:8692` | Same, by name "all"; the filter difference is in search_filter, which treats both alike. | [search_functions.php:1484](../../../include/search_functions.php:1484) | 1 | 1 | [ ] |
| F40 | !list plus a keyword | `!list416:4460:8692 sculpture` | Keyword applied to the list. | [do_search.php:126](../../../include/do_search.php:126) | 1 | 1 | [ ] |
| F41 | !list with commas | `!list416,4460` | Only the part before the first comma is the list: resource 416. | [search_functions.php:1489](../../../include/search_functions.php:1489) | 1 | 1 | [ ] |
| F42 | !hasdata | `!hasdata170` | Resources with any value in the field (by ref only); all workflow states. | [search_functions.php:1512](../../../include/search_functions.php:1512), [search_functions.php:831](../../../include/search_functions.php:831) | 12 | 12 | [ ] |
| F43 | !hasdata on a non-indexed field | `!hasdata93` | Node join, so indexing does not matter. | [search_functions.php:1512](../../../include/search_functions.php:1512) | 63,316 | 63,316 | [ ] |
| F44 | !hasdata plus a keyword | `!hasdata170 sculpture` | Keyword applied. | [do_search.php:126](../../../include/do_search.php:126) | 0 | 0 | [ ] |
| F45 | !empty by ref | `!empty170` | Handled as a keyword: resources with no value in the field. | [do_search_keywords.php:310](../../../include/do_search_keywords.php:310), [do_search_keywords.php:570](../../../include/do_search_keywords.php:570) | 100,850 | 0 | [ ] |
| F46 | !empty by short name | `!emptynumberfield` | Meant to be F45 by name, but the name is bound as an integer (type "i"), which MySQL reads as 0 and matches the first field whose name is not a number: field 1, keywords. | [do_search_keywords.php:316](../../../include/do_search_keywords.php:316) | 100,004 | 0 | [ ] |
| F47 | !empty on a field of one type | `!empty95` | Meant to limit candidates to the field's resource types, but the type condition sits inside the NOT IN subquery, so resources of every other type come back too. | [do_search_keywords.php:566](../../../include/do_search_keywords.php:566) | 100,457 | 0 | [ ] |
| F48 | !empty with a resource type | `!empty170` restypes=1 | Candidates limited to the given types. | [do_search_keywords.php:555](../../../include/do_search_keywords.php:555) | 93,054 | 0 | [ ] |
| F49 | !empty, unknown field | `!emptynosuchfield` | Meant to exit with "invalid !empty search"; because of the integer binding in F46 it becomes !empty1 (keywords) instead. | [do_search_keywords.php:316](../../../include/do_search_keywords.php:316), [do_search_keywords.php:320](../../../include/do_search_keywords.php:320) | 100,004 | 0 | [ ] |
| F50 | !properties file extension | `!propertiesfext:jpg` | file_extension LIKE "jpg". | [search_functions.php:1524](../../../include/search_functions.php:1524), [search_functions.php:1566](../../../include/search_functions.php:1566) | 66,810 | 0 | [ ] |
| F51 | !properties, not an extension | `!propertiesfext:-jpg` | NOT LIKE. | [search_functions.php:1569](../../../include/search_functions.php:1569) | 34,052 | 0 | [ ] |
| F52 | !properties, extension wildcard | `!propertiesfext:jp*` | * becomes %: jpg and jpeg. | [search_functions.php:1567](../../../include/search_functions.php:1567) | 71,886 | 0 | [ ] |
| F53 | !properties minimum height | `!propertieshmin:3000` | rdim.height >= 3000. | [search_functions.php:1541](../../../include/search_functions.php:1541) | 69,894 | 0 | [ ] |
| F54 | !properties, several | `!propertieswmin:4000;hmin:3000` | Semicolon-separated properties are ANDed. | [search_functions.php:1528](../../../include/search_functions.php:1528) | 55,705 | 0 | [ ] |
| F55 | !properties minimum size | `!propertiesfmin:5` | file_size >= 5 MB. | [search_functions.php:1558](../../../include/search_functions.php:1558) | 53,138 | 0 | [ ] |
| F56 | !properties maximum size | `!propertiesfmax:1` | file_size <= 1 MB. | [search_functions.php:1563](../../../include/search_functions.php:1563) | 11,993 | 0 | [ ] |
| F57 | !properties has preview | `!propertiespi:1` | has_image = 1. | [search_functions.php:1580](../../../include/search_functions.php:1580) | 100,072 | 0 | [ ] |
| F58 | !properties contributor | `!propertiescu:9` | created_by = 9. | [search_functions.php:1584](../../../include/search_functions.php:1584) | 41,283 | 0 | [ ] |
| F59 | !properties orientation | `!propertiesorientation:portrait` | Compares resource_dimensions height and width. | [search_functions.php:1588](../../../include/search_functions.php:1588) | 36,092 | 0 | [ ] |
| F60 | !properties plus a keyword | `!propertiesfext:jpg sculpture` | Keyword applied (the advanced form joins them with a space). | [search_functions.php:1526](../../../include/search_functions.php:1526), [search_functions.php:406](../../../include/search_functions.php:406) | 32,459 | 0 | [ ] |
| F61 | !properties plus a node | `!propertiesfext:jpg @@389` | Node applied. | [do_search.php:111](../../../include/do_search.php:111) | 41,004 | 0 | [ ] |
| F62 | !properties, unknown property | `!propertiesbogus:1` | No filter added: everything. | [search_functions.php:1539](../../../include/search_functions.php:1539) | 100,862 | 0 | [ ] |
| F63 | !integrityfail | `!integrityfail` | integrity_fail = 1 and a file present; all workflow states. | [search_functions.php:1619](../../../include/search_functions.php:1619), [search_functions.php:831](../../../include/search_functions.php:831) | 0 | 0 | [ ] |
| F64 | !locked | `!locked` | lock_user <> 0. | [search_functions.php:1624](../../../include/search_functions.php:1624) | 1 | 0 | [ ] |
| F65 | !noningested (admin only) | `!noningested` | For admins: resources with a file_path. For anyone else no branch matches, so a plain search with no terms: everything. | [search_functions.php:1629](../../../include/search_functions.php:1629) | 100,862 | 0 | [ ] |
| F66 | !report | `!report3p365` | Report 3 over 365 days as results; needs the reports permission, otherwise empty. | [search_functions.php:1633](../../../include/search_functions.php:1633), [search_functions.php:1663](../../../include/search_functions.php:1663) | 0 | 0 | [ ] |
| F67 | Unknown special search | `!nosuchsearch` | No branch and no plugin hook: a plain search with no terms, so everything. | [search_functions.php:1717](../../../include/search_functions.php:1717), [do_search.php:382](../../../include/do_search.php:382) | 100,862 | 0 | [ ] |
| F68 | Two special searches | `!last100 !collection3808` | The second is skipped by the keyword stage, and the space makes the !last number invalid (F4): !last1000. | [do_search_keywords.php:88](../../../include/do_search_keywords.php:88) | 1,000 | 0 | [ ] |
| F69 | !last, comma before the keyword | `!last100, sculpture` | The comma ends the number, so the last 100 that match (compare F4). | [search_functions.php:1127](../../../include/search_functions.php:1127) | 100 | 100 | [ ] |
## G. Parameters

do_search() arguments the API exposes: restypes, archive, order_by, sort, fetchrows and offset through do_search; recent_search_daylimit through search_get_previews. The rest (go, editable_only, access, smartsearch, ignore_filters, return_refs_only, return_disk_usage, access_override, returnsql) are reachable only from PHP.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| G1 | One resource type | `sculpture` restypes=1 | resource_type IN (1). | [search_functions.php:751](../../../include/search_functions.php:751) | 45,727 | 45,727 | [ ] |
| G2 | Several resource types | `sculpture` restypes=1,5 | resource_type IN (1,5). | [search_functions.php:751](../../../include/search_functions.php:751) | 48,620 | 48,620 | [ ] |
| G3 | Resource types "Global" | `sculpture` restypes=Global | Ignored: no type filter (the advanced search "all types" value). | [search_functions.php:751](../../../include/search_functions.php:751) | 49,468 | 49,468 | [ ] |
| G4 | "Global" followed by a type | `sculpture` restypes=Global,5 | Starts with Global, so the whole list is ignored. | [search_functions.php:751](../../../include/search_functions.php:751) | 49,468 | 49,468 | [ ] |
| G5 | Workflow state 0 | `sculpture` archive=0 | archive IN (0). | [search_functions.php:851](../../../include/search_functions.php:851) | 49,468 | 49,468 | [ ] |
| G6 | Two states | `sculpture` archive=0,8 | archive IN (0,8); a custom state the test users can see. | [search_functions.php:851](../../../include/search_functions.php:851) | 52,848 | 52,848 | [ ] |
| G7 | Custom state only | `sculpture` archive=8 | archive IN (8). | [search_functions.php:851](../../../include/search_functions.php:851) | 3,380 | 3,380 | [ ] |
| G8 | State hidden by a z permission | `sculpture` archive=2 | archive IN (2) AND archive NOT IN (1,2,3): nothing for these users. | [search_functions.php:873](../../../include/search_functions.php:873) | 0 | 0 | [ ] |
| G9 | Pending submission | `sculpture` archive=-1 | Only the user's own resources in state -1. | [search_functions.php:868](../../../include/search_functions.php:868) | 0 | 0 | [ ] |
| G10 | Empty archive | `sculpture` archive= | Default states ($searchstates, else 0). | [search_functions.php:839](../../../include/search_functions.php:839), [search_functions.php:1962](../../../include/search_functions.php:1962) | 49,468 | 49,468 | [ ] |
| G11 | Order: relevance (default) | `sculpture` order_by=relevance, fetchrows=0,5 | score, user_rating, hit count, date field, ref. | [search_functions.php:3180](../../../include/search_functions.php:3180) | 49,468 [44274,17206,3962,37462,82074] | 49,468 [11083,11081,11079,11077,11073] | [ ] |
| G12 | Order: popularity | `sculpture` order_by=popularity, fetchrows=0,5 | user_rating, hit count, date, ref. | [search_functions.php:3181](../../../include/search_functions.php:3181) | 49,468 [44274,17206,3962,37462,82074] | 0 | [ ] |
| G13 | Order: rating | `sculpture` order_by=rating, fetchrows=0,5 | r.rating, user_rating, score, ref. | [search_functions.php:3182](../../../include/search_functions.php:3182) | 49,468 [127577,127561,127494,127493,127491] | 0 | [ ] |
| G14 | Order: date | `sculpture` order_by=date, fetchrows=0,5 | $date_field value, then ref. | [search_functions.php:3183](../../../include/search_functions.php:3183) | 49,468 [120365,120367,120373,120363,120372] | 49,468 [120365,120367,120373,120363,120372] | [ ] |
| G15 | Order: colour | `sculpture` order_by=colour, fetchrows=0,5 | has_image, blue, green, red, date, ref. | [search_functions.php:3184](../../../include/search_functions.php:3184) | 49,468 [103695,100497,104695,90697,70145] | 0 | [ ] |
| G16 | Order: title | `sculpture` order_by=title, fetchrows=0,5 | $view_title_field, ref. | [search_functions.php:3185](../../../include/search_functions.php:3185) | 49,468 [113856,113855,113854,113853,113851] | 0 | [ ] |
| G17 | Order: file_path | `sculpture` order_by=file_path, fetchrows=0,5 | file_path, ref. | [search_functions.php:3186](../../../include/search_functions.php:3186) | 49,468 [127577,127561,127494,127493,127491] | 0 | [ ] |
| G18 | Order: resourceid | `sculpture` order_by=resourceid, fetchrows=0,5 | ref. | [search_functions.php:3187](../../../include/search_functions.php:3187) | 49,468 [127577,127561,127494,127493,127491] | 49,468 [127577,127561,127494,127493,127491] | [ ] |
| G19 | Order: resourcetype | `sculpture` order_by=resourcetype, fetchrows=0,5 | resource type order_by, type, ref. | [search_functions.php:3188](../../../include/search_functions.php:3188) | 49,468 [126154,126153,122676,121712,120929] | 0 | [ ] |
| G20 | Order: extension | `sculpture` order_by=extension, fetchrows=0,5 | file_extension, ref. | [search_functions.php:3189](../../../include/search_functions.php:3189) | 49,468 [70811,125814,115883,72838,16383] | 0 | [ ] |
| G21 | Order: status | `sculpture` order_by=status, fetchrows=0,5 | archive, ref. | [search_functions.php:3190](../../../include/search_functions.php:3190) | 49,468 [127577,127561,127494,127493,127491] | 0 | [ ] |
| G22 | Order: modified | `sculpture` order_by=modified, fetchrows=0,5 | modified, ref. | [search_functions.php:3191](../../../include/search_functions.php:3191) | 49,468 [108258,108125,108107,106979,106974] | 49,468 [108258,108125,108107,106979,106974] | [ ] |
| G23 | Order: random | `sculpture` order_by=random, fetchrows=0,5 | RAND(). | [search_functions.php:3192](../../../include/search_functions.php:3192) | 49,468 [73411,22273,7893,64079,60516] | 0 | [ ] |
| G24 | Order: country | `sculpture` order_by=country, fetchrows=0,5 | Only offered when field 3 exists; absent here, so relevance. | [search_functions.php:3200](../../../include/search_functions.php:3200), [search_functions.php:3249](../../../include/search_functions.php:3249) | 49,468 [2181,2135,2130,2127,2082] | 0 | [ ] |
| G25 | Order: a field by number (date) | `sculpture` order_by=field12, fetchrows=0,5 | field12, ref (must be in the resource table joins). | [search_functions.php:3216](../../../include/search_functions.php:3216) | 49,468 [120365,120367,120373,120363,120372] | 49,468 [120365,120367,120373,120363,120372] | [ ] |
| G26 | Order: numeric field | `numberfield:numrangeneg100\|2000` order_by=field170, sort=asc, fetchrows=0,12 | field170 +0: numeric order, if the field is a resource-table join column. It is not one on the test system: the fallback rewrites the resourceid order with an invalid column, so the query fails and nothing comes back (G43). | [search_functions.php:3243](../../../include/search_functions.php:3243) | 0 | 0 | [ ] |
| G27 | Order: title field by number | `sculpture` order_by=field8, fetchrows=0,5 | field8, ref: text order. | [search_functions.php:3245](../../../include/search_functions.php:3245) | 49,468 [113856,113855,113854,113853,113851] | 0 | [ ] |
| G28 | Order: unknown | `sculpture` order_by=bogus, fetchrows=0,5 | Falls back to relevance. | [search_functions.php:3249](../../../include/search_functions.php:3249) | 49,468 [44274,17206,3962,37462,82074] | 49,468 [11083,11081,11079,11077,11073] | [ ] |
| G29 | Sort ascending | `sculpture` order_by=resourceid, sort=asc, fetchrows=0,5 | ref ASC. | [search_functions.php:3163](../../../include/search_functions.php:3163) | 49,468 [416,518,1274,1275,1276] | 49,468 [416,518,1274,1275,1276] | [ ] |
| G30 | Sort: invalid value | `sculpture` order_by=resourceid, sort=sideways, fetchrows=0,5 | Anything but asc/desc becomes asc. | [general_functions.php:5821](../../../include/general_functions.php:5821), [do_search.php:95](../../../include/do_search.php:95) | 49,468 [416,518,1274,1275,1276] | 49,468 [416,518,1274,1275,1276] | [ ] |
| G31 | Window: offset and count | `sculpture` order_by=resourceid, sort=asc, fetchrows=0,5 | fetchrows "0,5": total plus five rows (structured). | [api_bindings.php:20](../../../include/api_bindings.php:20) | 49,468 [416,518,1274,1275,1276] | 49,468 [416,518,1274,1275,1276] | [ ] |
| G32 | Window: count only (legacy) | `sculpture` order_by=resourceid, sort=asc, fetchrows=5 | fetchrows "5": five rows, no total. | [api_bindings.php:22](../../../include/api_bindings.php:22) | 5 rows [416,518,1274,1275,1276] | 5 rows [416,518,1274,1275,1276] | [ ] |
| G33 | Window: legacy with an offset | `sculpture` order_by=resourceid, sort=asc, fetchrows=5, offset=10 | Rows 11 to 15. | [api_bindings.php:25](../../../include/api_bindings.php:25) | 5 rows [1282,1283,1284,1285,1286] | 5 rows [1282,1283,1284,1285,1286] | [ ] |
| G34 | Recent days: 365 | `sculpture` daylimit=365 | creation_date > today - 365 days (search_get_previews only). | [search_functions.php:760](../../../include/search_functions.php:760), [api_bindings.php:65](../../../include/api_bindings.php:65) | 10,365 | 10,365 | [ ] |
| G35 | Recent days: 90 | (empty) daylimit=90 | Everything created in the last 90 days. | [search_functions.php:760](../../../include/search_functions.php:760) | 3,089 | 3,089 | [ ] |
| G36 | Recent days: 30 | (empty) daylimit=30 | Nothing on the test system was created in the last 30 days. | [search_functions.php:760](../../../include/search_functions.php:760) | 0 | 0 | [ ] |
| G37 | go (previous / next) | `(any)` | Page direction for view.php; only affects keyword logging in do_search. | [do_search.php:44](../../../include/do_search.php:44), [view.php:57](../../../pages/view.php:57) | n/a | n/a | [ ] |
| G38 | editable_only | `(any)` | Adds the edit-permission filter (e states, ert, XE, edit filter). | [search_functions.php:928](../../../include/search_functions.php:928), [do_search_filtering.php:62](../../../include/do_search_filtering.php:62) | n/a | n/a | [ ] |
| G39 | access | `(any)` | r.access = n, only for users with v. | [search_functions.php:920](../../../include/search_functions.php:920) | n/a | n/a | [ ] |
| G40 | smartsearch | `(any)` | Keeps the saved archive states instead of the defaults. | [search_functions.php:839](../../../include/search_functions.php:839) | n/a | n/a | [ ] |
| G41 | ignore_filters | `(any)` | Skips date, numrange and fixed-list conversion of field terms. | [do_search_keywords.php:128](../../../include/do_search_keywords.php:128) | n/a | n/a | [ ] |
| G42 | return_refs_only / return_disk_usage / returnsql / access_override | `(any)` | Shape of the result, disk usage wrapper, SQL instead of rows, permission override for smart collections. | [do_search.php:44](../../../include/do_search.php:44), [do_search.php:177](../../../include/do_search.php:177) | n/a | n/a | [ ] |
| G43 | Order: a field that is not a join column | `sculpture` order_by=field170, fetchrows=0,5 | check_order_by_in_table_joins() swaps the order for resourceid, but the field branch then writes "resourceid DESC" as a column name: an SQL error, so no results. | [search_functions.php:3006](../../../include/search_functions.php:3006), [search_functions.php:3245](../../../include/search_functions.php:3245) | 0 | 0 | [ ] |
## H. Combinations

Terms of different kinds in one search. Everything is ANDed: keyword unions, phrase unions, node buckets, date and numeric joins, the special search's own filter, and the parameters.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| H1 | Keyword and node | `sculpture, @@389` | Union join plus node join. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 26,893 | 26,893 | [ ] |
| H2 | Keyword and option name | `sculpture, orientation:landscape` | Same as H1. | [do_search_keywords.php:253](../../../include/do_search_keywords.php:253) | 26,893 | 26,893 | [ ] |
| H3 | Field term and node | `title:sculpture, @@389` | Restricted union plus node join. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 2,439 | 2,439 | [ ] |
| H4 | Keyword and two nodes | `sculpture, @@425, @@389` | Two node joins. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 41 | 41 | [ ] |
| H5 | Field wildcard and node | `title:sculpt*, @@389` | Full-text union plus node join. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 2,507 | 2,507 | [ ] |
| H6 | Node and NOT node | `@@425, @@!389` | Join plus NOT EXISTS. | [do_search_nodes.php:31](../../../include/do_search_nodes.php:31) | 99 | 99 | [ ] |
| H7 | Keyword and date | `sculpture, date:2024` | Union plus date join. | [do_search_keywords.php:130](../../../include/do_search_keywords.php:130) | 4,517 | 4,517 | [ ] |
| H8 | Keyword, node and date, by date | `sculpture, @@389, date:2024` order_by=date, fetchrows=0,5 | All three, ordered by the date field. | [search_functions.php:3183](../../../include/search_functions.php:3183) | 2,034 [75485,75463,75482,75462,75484] | 2,034 [75485,75463,75482,75462,75484] | [ ] |
| H9 | Keyword, type, by ref | `sculpture` restypes=5, order_by=resourceid, sort=asc, fetchrows=0,5 | Type filter and ref order. | [search_functions.php:751](../../../include/search_functions.php:751) | 2,893 [2181,3879,4093,4094,4096] | 2,893 [2181,3879,4093,4094,4096] | [ ] |
| H10 | Keyword, node, type and two states | `sculpture, @@389` restypes=5, archive=0,2 | State 2 is hidden by z2, so effectively state 0. | [search_functions.php:873](../../../include/search_functions.php:873) | 1,408 | 1,408 | [ ] |
| H11 | Collection and keyword, collection order | `!collection3808 sculpture` order_by=collection, sort=asc, fetchrows=0,5 | Keyword inside the collection, collection order. | [search_functions.php:3206](../../../include/search_functions.php:3206) | 55 [59367,59354,59348,59320,59315] | 55 [59367,59354,59348,59320,59315] | [ ] |
| H12 | Last 100 and keyword | `!last100 sculpture` fetchrows=0,5 | Newest 100 that match. | [search_functions.php:1141](../../../include/search_functions.php:1141) | 1,000 [124864,124872,124874,124888,124889] | 1,000 [124864,124872,124874,124888,124889] | [ ] |
| H13 | Node and NOT keyword | `@@425, -sculpture` | Node join plus NOT IN. | [do_search_keywords.php:471](../../../include/do_search_keywords.php:471) | 48 | 48 | [ ] |
| H14 | Phrase and node | `"sculpture park", @@389` | Phrase union plus node join. | [do_search_keywords.php:758](../../../include/do_search_keywords.php:758) | 3,412 | 0 | [ ] |
| H15 | Keyword and NOT keyword | `sculpture, -landscape` | Union plus NOT IN. | [do_search_keywords.php:471](../../../include/do_search_keywords.php:471) | 15,049 | 15,059 | [ ] |
| H16 | Keyword and numeric range | `sculpture, numberfield:numrange0\|100` | Union plus numeric join. | [do_search_keywords.php:206](../../../include/do_search_keywords.php:206) | 0 | 0 | [ ] |
| H17 | Node and date | `@@389, date:2024` | Node join plus date join. | [do_search_keywords.php:130](../../../include/do_search_keywords.php:130) | 4,169 | 4,169 | [ ] |
| H18 | OR of options and a node | `materials:bronze;wood, @@389` | OR bucket plus a second bucket. | [do_search_nodes.php:17](../../../include/do_search_nodes.php:17) | 18 | 18 | [ ] |
| H19 | Simple-search year and keyword | `basicyear:2024, sculpture` | Date-field join plus union. | [do_search_keywords.php:163](../../../include/do_search_keywords.php:163) | 4,517 | 0 | [ ] |
| H20 | Simple-search year and node | `basicyear:2024, @@389` | Date-field join plus node join. | [do_search_keywords.php:163](../../../include/do_search_keywords.php:163) | 4,169 | 0 | [ ] |
| H21 | Two date fields | `date:2024, expirydate:2029` | Two date joins. | [do_search_keywords.php:130](../../../include/do_search_keywords.php:130) | 2,972 | 2,972 | [ ] |
| H22 | Two wildcards | `sculpt*, land*` | Two full-text unions. | [do_search_keywords.php:617](../../../include/do_search_keywords.php:617) | 36,026 | 0 | [ ] |
| H23 | Phrase and NOT phrase | `"sculpture park", -"black and white"` | Phrase union plus phrase NOT IN. | [do_search_keywords.php:828](../../../include/do_search_keywords.php:828) | 6,758 | 0 | [ ] |
| H24 | Four keywords | `sculpture landscape nature outdoor` | Four unions ANDed. | [do_search_union_assembly.php:55](../../../include/do_search_union_assembly.php:55) | 19,608 | 19,608 | [ ] |
| H25 | Two fields, node and date | `title:sculpture, credit:wilde, @@389, date:2024` | Two restricted unions, node join, date join. | [do_search_union_assembly.php:38](../../../include/do_search_union_assembly.php:38) | 65 | 65 | [ ] |
| H26 | Collection and date | `!collection3808 date:2025` | Date join inside the collection. | [search_functions.php:1285](../../../include/search_functions.php:1285) | 55 | 55 | [ ] |
| H27 | Contributions, keyword and node | `!contributions9 sculpture, @@389` | created_by filter plus union plus node. | [search_functions.php:1457](../../../include/search_functions.php:1457) | 9,240 | 9,240 | [ ] |
| H28 | List and node | `!list416:4460:8692 @@389` | Ref list plus node join. | [search_functions.php:1480](../../../include/search_functions.php:1480) | 1 | 1 | [ ] |
| H29 | Hasdata and node | `!hasdata170 @@389` | Field join plus node join. | [search_functions.php:1512](../../../include/search_functions.php:1512) | 5 | 5 | [ ] |
| H30 | Properties and date | `!propertiesfext:jpg date:2024` | Extension filter plus date join. | [search_functions.php:1524](../../../include/search_functions.php:1524) | 6,224 | 0 | [ ] |
| H31 | Everything at once | `sculpture, "sculpture park", @@389, orientation:landscape, date:2024, -"black and white"` restypes=1, archive=0, order_by=date, sort=desc, fetchrows=0,5 | Keyword, phrase, node, option (same node twice), date, NOT phrase, type, state, order. | [do_search.php:412](../../../include/do_search.php:412) | 307 [47432,47431,47430,47429,47428] | 0 | [ ] |
| H32 | Bare OR and a node | `sculpture;landscape, @@389` | Ran after B5 had created the keyword "sculpture;landscape", so the OR worked: sculpture or landscape, and every resource with that orientation node also carries the word landscape, hence the same total as @@389 alone. Before B5 it would be a suggestion (A16). | [do_search_keywords.php:387](../../../include/do_search_keywords.php:387) | 58,028 | 0 | [ ] |
| H33 | Wildcard OR and a node | `sculpt*;land*, @@389` | RLIKE union plus node join. | [do_search_keywords.php:648](../../../include/do_search_keywords.php:648) | 58,028 | 0 | [ ] |
| H34 | Field OR and a node | `title:sculpture;park, @@389` | Alternative keywords in the field plus node join. | [do_search_keywords.php:301](../../../include/do_search_keywords.php:301) | 2,653 | 0 | [ ] |
| H35 | Date range, node and keyword | `date:rangestart2024-01-01end2024-12-31, @@389, sculpture` | Range join, node join, union. | [do_search_keywords.php:180](../../../include/do_search_keywords.php:180) | 2,030 | 2,034 | [ ] |
| H36 | Year as a word and a keyword | `2024, sculpture` | Two keyword unions (2024 is a keyword from date indexing). | [search_functions.php:2059](../../../include/search_functions.php:2059) | 5,477 | 5,476 | [ ] |
| H37 | Type name and keyword | `document sculpture` | Type union plus keyword union. | [do_search_keywords.php:56](../../../include/do_search_keywords.php:56) | 111 | 101 | [ ] |
| H38 | Resource types and collection ignored together | `!collection3618 sculpture` restypes=5, archive=8 | Types and states are both ignored for a collection search; the keyword still applies. | [search_functions.php:751](../../../include/search_functions.php:751), [search_functions.php:825](../../../include/search_functions.php:825) | 409 | 409 | [ ] |
## I. Syntax edge cases

Strings a user might type that core accepts but reads differently from what was meant.

| # | Search | String and parameters | What core does | Where | Core | Plugin | OK |
|---|---|---|---|---|---|---|---|
| I1 | No space after a special search | `!collection3808,sculpture` | Keywords are only taken after the first space: the keyword is dropped. | [do_search.php:126](../../../include/do_search.php:126) | 55 | 55 | [ ] |
| I2 | Comma without a space | `sculpture,landscape` | A string with no whitespace is kept as one keyword; the comma then counts as punctuation, so core searches the phrase "sculpture landscape", not two words. | [search_functions.php:2157](../../../include/search_functions.php:2157) | 22 | 34,409 | [ ] |
| I3 | Comma inside a field term | `title:sculpture,landscape` | Quoted because of the comma, then split: the phrase "sculpture landscape" in title. | [do_search_keywords.php:286](../../../include/do_search_keywords.php:286) | 0 | 25 | [ ] |
| I4 | Repeated word | `sculpture sculpture` | Duplicates removed: same as A1. | [do_search.php:146](../../../include/do_search.php:146) | 49,468 | 49,468 | [ ] |
| I5 | Surrounding spaces | `   sculpture   ` | Trimmed. | [do_search.php:145](../../../include/do_search.php:145) | 49,468 | 49,468 | [ ] |
| I6 | Trailing colon | `sculpture:` | Field "sculpture" does not exist: colon becomes a space, so the word sculpture. | [do_search_keywords.php:112](../../../include/do_search_keywords.php:112) | 49,468 | 49,468 | [ ] |
| I7 | Leading colon | `:sculpture` | Empty field name: same as I6. | [do_search_keywords.php:112](../../../include/do_search_keywords.php:112) | 49,468 | 49,468 | [ ] |
| I8 | Unbalanced quote | `"sculpture` | Not a quoted string (no closing quote); the quote is a separator. | [do_search_keywords.php:21](../../../include/do_search_keywords.php:21) | 49,468 | 0 | [ ] |
| I9 | Upper-case special search | `!LAST100` | Prefixes are compared case-sensitively: not !last, so an unknown special search (F67). | [search_functions.php:1110](../../../include/search_functions.php:1110) | 100,862 | 1,000 | [ ] |
| I10 | Nodes without a space | `@@389,@@425` | Both tokens found; the comma is left behind and ignored. | [do_search.php:529](../../../include/do_search.php:529) | 49 | 49 | [ ] |
| I11 | Upper-case field name and value | `Title:Sculpture` | Field names compare case-insensitively in MySQL; the value is normalised. | [do_search_keywords.php:105](../../../include/do_search_keywords.php:105) | 4,226 | 4,226 | [ ] |
| I12 | Dropdown value from the advanced form | `"source:igital Camer"` | search_form_to_search_query has a branch that strips the first and last characters of a dropdown value with spaces; dropdowns post node refs instead, so the branch is unreachable and this is only what it would send for "Digital Camera". | [search_functions.php:290](../../../include/search_functions.php:290) | 0 | 0 | [ ] |

