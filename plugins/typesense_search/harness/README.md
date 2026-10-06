# Search harness

Tools used for the October 2026 review of every search type core ResourceSpace supports against what the
`typesense_search` plugin does with it. The findings are in
[`../docs/typesense-search-review.md`](../docs/typesense-search-review.md); this folder holds the code that
produced them and its raw output.

Command line only (every entry script refuses a web request). Not part of the plugin at run time, and
deliberately not named `tests/`, which core's test runner would pick up.

## What is here

| Path | What it is |
|---|---|
| `ab/` | **A/B harness.** Runs core's real `do_search()` twice per case: once alone, once with the plugin's real hook attached, and compares the resources returned. Core's SQL runs on an in-memory SQLite database built from `dbstruct/`; the plugin's real indexer and query code talk to a private local Typesense. |
| `trace/` | **Trace harness.** No database and no Typesense: a fake database layer feeds core, and the output shows the SQL core builds and the request the plugin would send. Useful to see *what is asked*, not what comes back. |
| `live/` | **Live A/B.** The same search sent through the ResourceSpace API as two users, one in a group with the plugin and one without. Needs a running system; credentials come from the environment. |
| `callscan.php` | Lists every `do_search()` call in the codebase whose arguments land on a typed parameter of the plugin's hook. |
| `summarise.php` | Condenses `results/ab/*.txt` into `results/summary.md`, one line per case. |
| `results/` | Output of every script as last run for the review. |

## Running the A/B harness

Needs PHP with the `sqlite3` and `curl` extensions, and `typesense-server` (the review used 30.2) on the PATH.

```bash
./start_typesense.sh        # private instance on 127.0.0.1:18108, data in a temp folder; leave it running
```

```bash
php ab/03_field_specific.php
```

```bash
./run_all.sh && php summarise.php      # everything, into results/ (about six minutes)
```

Environment variables:

| Variable | Default | Purpose |
|---|---|---|
| `HARNESS_ROOT` | this checkout | The code tree under test. Point it at another checkout to test that code with this harness. |
| `HARNESS_SCRATCH` | `<system temp>/rs_typesense_harness` | Generated files: debug log, Typesense data. |
| `HARNESS_TS_HOST`, `HARNESS_TS_PORT`, `HARNESS_TS_KEY` | `127.0.0.1`, `18108`, `local-harness-key` | The private Typesense. |

**Never point it at a Typesense that holds real data.** Every run drops and rebuilds the `harness_*` collections.

## Reading the output

```
B23 dropdown word that is not a whole option:  "country:united"
    core:   total=1 refs=[2]
    plugin: SERVED by Typesense  total=29 refs=[1,2,3,…]
    => DIFFERENT
```

- `SERVED by Typesense`: the plugin answered. The verdict compares its resources with core's.
- `fell back to core (reason)`: the plugin declined, so core answers and the two cannot differ.
- `not consulted`: core returned before reaching the plugin (a word that is not in the keyword table, or a
  field the user cannot view).
- `PHP ERROR`: the plugin's hook threw.
- `same set, different order` matters only for the sort that was asked for; relevance order is known to differ.

`ab/50_combinations.php` is different: it generates about 2,500 combinations of terms, arguments, special
searches and permissions on a fixture where every mix of attributes exists, checks core and the plugin against
the expected set, and prints only the cases where something disagrees, then a summary. It takes about two and a
half minutes; `HARNESS_COMBO_SCALE=0.1` shrinks the sampled groups and `HARNESS_COMBO_STEMMING=1` runs it with
`$stemming` on.

A case is written `ab('label', 'search string', array(...do_search arguments...))`. The fixture
(`ab/fixture_body.php`, built with the helpers in `ab/fixture_lib.php`) is about 35 resources chosen to separate
behaviours; scripts add their own where needed.
The session is a standard user (`$userpermissions` in `ab/boot.php`); scripts change globals to try other
permissions and config options.

## Limits

- **SQLite is not MySQL.** `MATCH … AGAINST` is emulated in PHP, so core's wildcard and full-text results are
  an emulation. Text comparison is case-insensitive but not accent-insensitive, as MySQL's default collations
  are. A few of core's MySQL-only statements do not run at all; the output says so where it happens, and so far
  only for searches the plugin declines anyway.
- **The fixture shows behaviour, not scale.** Counts are tiny. The live A/B is for real numbers.
- **The trace harness is tied to the code it was written against.** Its fake database answers the specific
  queries core made at the reviewed commit.
- Only the database driver is replaced. Everything else is the checkout's real code, so results move with it.

## Live A/B

```bash
RS_BASE_URL=https://host/path RS_USER_TS=… RS_KEY_TS=… RS_USER_CORE=… RS_KEY_CORE=… php live/run.php
```

Credentials are read from the environment only and are never written to the output. The script sends one
search at a time with a pause between them and asks for one row plus the total; it is meant to stay light on
the server. The cases are in `live/cases.php` and name fields and options of the system they were written for.

| Script | Purpose |
|---|---|
| `live/run.php [label prefix]` | The A/B run. `RS_PAUSE` sets the pause in seconds (default 2); `RS_INSECURE=1` accepts a private TLS certificate. |
| `live/api.php <ts\|core> <function> [name=value …]` | One API call, for looking up field names, options and collections before writing cases. |
| `live/db_survey.php` | Read-only survey of the database (`RS_DB_HOST`, `RS_DB_PORT`, `RS_DB_USER`, `RS_DB_PASS`, `RS_DB_NAME`): which fields are indexed, how many values are long, HTML or translated, how the plugin is configured. Only `SELECT`s; one of them scans the node table. |
| `live/catalogue_values.php` | Read-only survey (same `RS_DB_*` variables, plus `RS_USER_TS` / `RS_USER_CORE` for the test users) of the values the search catalogue examples use: resource types, fields, common keywords, fixed-list options with counts, dates, numbers, collections, users, and the counts behind the special searches. |
| `live/catalogue.php` | The search catalogue: every form of search core accepts, one case each, with examples from the test database. Data only; read by the two scripts below. |
| `live/catalogue_run.php [--slow] [--core-only] [--dry] [ids or group letters]` | Sends the catalogue through the API as the core user and, when `RS_USER_TS` is set, the plugin user, and keeps the totals in `results/live/catalogue.json` (merged, so partial runs are fine). Date-field searches are skipped unless `--slow` is given; they take 6 to 8 seconds each in core on that database. `RS_SUB_USERREF_CORE`, `RS_SUB_USERREF_TS`, `RS_SUB_OWNCOLLECTION_CORE` and `RS_SUB_OWNCOLLECTION_TS` fill the `{userref}` and `{owncollection}` placeholders. |
| `catalogue_table.php` | Writes `../docs/core-search-catalogue.md` from the catalogue and the recorded totals. |

If the plugin's group is in Typesense-only mode, a search the plugin declines comes back empty on that side
instead of falling back; compare with the local run of the same search to tell the two apart.
