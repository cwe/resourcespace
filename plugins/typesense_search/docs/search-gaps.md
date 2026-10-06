# Gaps in the search that do not make sense

Points for discussion, drawn from the search catalogue ([core-search-catalogue.md](core-search-catalogue.md))
run on 6 October 2026 and from the review ([typesense-search-review.md](typesense-search-review.md)). This is
not a list of core-versus-plugin differences; it is the behaviours that are inconsistent with themselves, in
core first and then in the plugin. Row references in brackets are catalogue rows; totals are core / plugin on
the test system.

## Core: the same idea works in one form and not in a neighbouring one

- **OR.** `title:sculpture;landscape`, `materials:bronze;wood` and `land*;sun*` all work as OR. Plain
  `sculpture;landscape` returns nothing, and starts working only after someone has searched a field term with the
  same string, because that search creates the combined word as a keyword (A16, B5, H32: 0 before, 83,840 after).
  A search should not change the index.
- **NOT.** `-word`, `-"phrase"` and `@@!n` work. There is no way to negate a field term, a date, a number range,
  or a node inside an OR group: `-title:sculpture` is read as the two words `-title` and `sculpture`,
  `title:-sculpture` excludes nothing, and `@@!389@@388` silently drops the NOT (B16, B17, C18).
- **Commas.** `sculpture, landscape` is two words; `sculpture,landscape` is the phrase "sculpture landscape",
  because a string with no whitespace is kept as one keyword and the comma then counts as punctuation (I2: 22
  against 34,408). The same happens inside a field term (I3). The meaning of a comma depends on whether a space
  exists anywhere else in the string.
- **Dates.** A single value matches by prefix, so a year, a month or a day all work. There is no OR for dates
  (`date:2024;2025` is nothing, D27) and two values on one field AND together, which can never match a
  single-value field (D26). One value on a date-range field returns nothing (D24, Core 2 in the review). The
  advanced form still assembles `startdate:` and `enddate:` terms that nothing handles (D16).
- **Numbers.** `numberfield:numrange|100` means exactly 100, not "up to 100", so there is no open-ended comparison
  (E2, E3). The syntax is accepted on any single-line text field and compares titles as numbers (E7).

## Core: special searches each parse their argument differently

- A space after `!last100` turns it into `!last1000`; a comma keeps 100 (F4, F69, Core 7). `!list416,4460`
  keeps only 416, because lists want colons (F41). `!resource416, date:2024` looks up resource 4162024 (F31,
  Core 3). `!empty` by field name binds the name as an integer and so always means field 1 (F46, F49, Core 5).
- The advanced search form puts its `!properties` prefix in front of the `!list` prefix it builds from the
  resource IDs box, so the IDs become a second special search, which core skips: with any property filled in,
  the resource IDs box is ignored (J1 returns 1, J2 returns 8 with the same IDs).
- Workflow states: `!collection`, `!list` and the two pending searches ignore the state parameter; `!related`,
  `!hasdata` and `!integrityfail` search every state; the rest honour it (F21, F22, F42). `!listall` is identical to
  `!list` (F38, F39). `!archivepending` exists to find state 1 yet returns nothing for a user with the z1
  permission (F32).
- Resource types are ignored for collection searches (F20), and a type list that starts with `Global` is ignored
  entirely even when real types follow it (G4).

## Core: failure modes are inconsistent, and the commonest one is "everything"

- An unknown special search, a misspelt one such as `!LAST100`, `!noningested` for a non-admin, and a search
  consisting only of a stop word all return the whole database (F65, F67, I9, A17, B18). An unknown word returns a
  suggestion string, which the API turns into zero rows with no explanation (A30, A31). An unknown field name
  becomes two words (B15). An unknown node returns nothing (C19). Four outcomes for "not understood", and the
  commonest is a full result set that looks like an answer.
- Sorting by a metadata field that is not a resource-table column returns nothing instead of the fallback the
  code intends (G26, G43, Core 8).
- Relevance order is the sum of past hit counts on the matched values, that is how often those resources were
  found before, not how well the text matched (G11).

## Plugin: the gaps that matter are the ones that return everything

- A negated phrase is dropped, so `-"sculpture park"` returns the full set (A10: 93,979 / 100,862, B18 in the
  review). One word of an option, a wildcard on an option, and an option of a non-indexed dropdown do the same
  (C4, C5, C9, B9; B3 in the review). Returning everything is worse than declining.
- A year works as `date:2024` but not as the bare word `2024`, because the index holds dates only as filter
  values (A26, H36; B19 in the review).
- Quoting and commas are read differently from core in four places (B4, I2, I3, I8; B20 to B22 in the review),
  and `!LAST100` becomes `!last1000` through a case-insensitive command name and a case-sensitive number (I9; H7
  in the review).
- In Typesense-only mode every declined form is an empty result: the other sorts, most special searches, OR
  groups, two wildcards, full-text search, and quoted phrases when stemming is on (review H5 and theme D).

## Three decisions

1. Which core quirks to raise with the core developers rather than copy into the plugin. Candidates: the OR
   keyword side effect, the comma rule, the `!last` and `!empty` parsing, the unknown-special fallback to
   everything, and the broken sort fallback.
2. Which plugin "everything" results should become declines, so that core answers instead.
3. Whether Typesense-only mode is acceptable for real users given how many legitimate forms it turns into empty
   results, or whether it stays a testing aid.
