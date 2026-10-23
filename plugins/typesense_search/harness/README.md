# Harness

The batteries that prove the `typesense_search` plugin, package by package, against what core actually does.
Command line only: every entry refuses a web request and `.htaccess` denies the directory. The harness lives
in the plugin on the GitHub branch and is left out of the release; nothing in the plugin depends on it.

The plan, the catalogue of search forms and the coverage map are in the planning repository
(`typesense_plan`); a case label here is a catalogue row id (`A1`, `D1`, `F19`) or a battery case id (`A3.12`).

## What is here

| Path | What it is |
|---|---|
| `ab/boot.php` | Boots core's real search code on an in-memory SQLite database built from core's `dbstruct/` and the plugin's, the plugin's real code against a private local Typesense (started on demand), and defines the A/B runner `ab()` and the check helper `harness_check()`. |
| `ab/fixture_lib.php`, `ab/fixtures/` | Fixture helpers that write rows the way core writes them (core's own keyword indexing), the base fixture, and per-package additions loaded with `fx_load()`. `fx_index()` builds the plugin's index through the generation rebuild once B8 exists. |
| `ab/accepted_divergences.php` | The accepted-divergence list the runner reads: one entry per case, with the kind, the reason and the decision row. Empty to start. |
| `ab/<ID>_<slug>.php` | One battery per task or package. `H1_smoke.php` is the smoke battery every session runs before handing over; `A1_skeleton.php` proves the skeleton (the option accessor, the lock, the setup page, the Q8.3 group override); `A2_client.php` proves the client against the private Typesense and against `stub_typesense.php`; `A3_schema.php` proves the schema module; `A4_dates.php` proves the dates module; `A5_text.php` proves the text module; `A6_state.php` proves the state store, with two processes sharing a file database for the counter. |
| `ab/stub_typesense.php` | A canned Typesense the A2 battery runs under PHP's built-in server on a free port: it records every request (the key header, the URI, the body) and answers 5xx, 503 or slowly to order, for the key, redaction, back-off, breaker and timeout cases a healthy server cannot produce. |
| `ab/state_writer.php` | The second writer of the A6 two-process case: boots on the shared file database named by `HARNESS_DB` and increments a counter when told to. |
| `ab/expected/` | Hand-written snapshots the batteries compare against (the version-1 schema from the register). |
| `run_all.sh`, `summarise.php` | Run every battery into `results/ab/`, then condense them into `results/summary.md` and `results/verdicts.json`. `results/` is never committed. |
| `typesense.sh` | Status, start or stop of the private Typesense. |
| `live/` | The live runners: the A/B through the API as two users (`run.php`), the catalogue run (`catalogue_run.php`), a value survey and a read-only database survey. Credentials come from the environment only. |
| `catalogue_table.php` | Writes the catalogue table (`../docs/core-search-catalogue.md`) from `live/catalogue.php` and a catalogue run's results. |
| `../tests/000010_typesense_smoke.php` | The one file core's test runner finds: runs the smoke battery when this directory exists and a local Typesense is available, passes quietly otherwise. |

## Running

Needs PHP with `sqlite3` (3.35 or later) and `curl`, and `typesense-server` (30.x) on the PATH.

```bash
./run_all.sh
```

Everything, into `results/`; exit 0 green, 1 a failure, 2 nothing could run. One battery:

```bash
php ab/A3_schema.php
```

| Variable | Default | Purpose |
|---|---|---|
| `HARNESS_ROOT` | this checkout | The code tree under test. |
| `HARNESS_SCRATCH` | `<system temp>/rs_typesense_search_harness` | Generated files: the debug log, Typesense's data directory and log. |
| `HARNESS_TS_HOST`, `HARNESS_TS_PORT`, `HARNESS_TS_KEY` | `127.0.0.1`, `18208`, `local-harness-key` | The private Typesense. Started by `boot.php` when nothing answers on a loopback host. |
| `HARNESS_DB` | `:memory:` | A file for the SQLite database, so that another process can share it (the A6 battery sets it for its writers). |

**Never point it at a Typesense that holds real data.** Every run drops and recreates the `harness_*` collections
and aliases: the harness is its own tenant (prefix `harness`) on the shared local server.

## Reading the output

```
A1:  "sunset"
    core:   total=5 refs=[1,8,12,36,37]  (order [8,1,37,36,12])
    plugin: not run (the plugin has no search hook yet)
    => PASS
```

- `core:` is core's answer on SQLite; a case with an `expect` set fails when core's answer is not the one derived
  by hand (a harness or fixture error, or a wrong derivation). `(core side emulated: full text)` marks the forms
  SQLite only emulates; H9's MySQL mode is their proof.
- `plugin:` is `served` (compared with core), `declined (<outcome>: <reason>)`, `not consulted` (core returned
  before the hook), `not run` (no search hook yet) or `PHP ERROR`.
- `=>` applies the acceptance rule (Q10.3): a served case passes when set, total and order equal core's — order
  only under an explicit sort or keyword-less relevance; a listed `order` entry allows a different order; a declined
  case passes only with its listed reason; a listed `core-bug` case is expected to differ; everything else fails.
  Security cases (`'security' => true`) are never listed.
- `CHECK <label>: ok|FAIL` lines are plain assertions; `RESULT:` closes a battery.

A case is written `ab('<label>', '<search>', array(...do_search arguments...), array('expect' => array(...)))`.
The session user is a standard user (`$userpermissions` in `ab/boot.php`); a battery changes globals to try other
permissions and options, and sees the options it set echoed in the case line.

## Contracts with the plugin

- **The veto reason** is read from the plugin's one `debug()` line per search:
  `typesense_search: outcome=<served|declined|failed> ... reason=<text to the end of the line>`.
- **The fixture index** is built by `typesense_search_rebuild_generation()`; until B8 exists `fx_index()` says so.
- **Configuration** is set with the plugin's option names (`$typesense_search_host`, `_port`, `_protocol`,
  `_search_key`, `_admin_key`, `_prefix`, `_enabled`) after `config/config.php` is included.

## Limits

- **SQLite is not MySQL.** `MATCH … AGAINST` and `RLIKE` are emulated in PHP and the case says so. Text comparison
  is case-insensitive but not accent-insensitive. A core statement that does not run on SQLite is printed with the
  case.
- **The fixture shows behaviour, not scale.** Counts are tiny; the live runners give real numbers, kept light on the
  test host.
- Only the database driver is replaced. Everything else is the checkout's real code, so results move with it.

## Live runners

```bash
RS_BASE_URL=https://host/path RS_USER_TS=… RS_KEY_TS=… RS_USER_CORE=… RS_KEY_CORE=… php live/run.php
```

Credentials are read from the environment only and never written to the output. `live/catalogue_run.php` sends
the catalogue rows (`RS_PAUSE` between calls, `--slow` for the date-field searches); `live/api.php` makes one call;
`live/catalogue_values.php` and `live/db_survey.php` are read-only database surveys. H4 re-cuts these for the
paired-user matrix.
