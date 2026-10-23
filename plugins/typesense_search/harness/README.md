# Harness

The batteries that prove the `typesense_search` plugin, package by package, against what core actually does.
Command line only: every entry refuses a web request and `.htaccess` denies the directory. The harness lives
in the plugin on the GitHub branch and is left out of the release; nothing in the plugin depends on it.

The plan, the catalogue of search forms and the coverage map are in the planning repository
(`typesense_plan`); a case label here is a catalogue row id (`A1`, `D1`, `F19`) or a battery case id (`A3.12`).

## What is here

| Path | What it is |
|---|---|
| `ab/boot.php` | Boots core's real search code on an in-memory SQLite database built from core's `dbstruct/` and the plugin's, the plugin's real code against a private local Typesense (started on demand), and defines the A/B runner `ab()` and the check helper `harness_check()`. `$HARNESS['hook_stub']`, when set, is returned by the `external_search` hook in place of the plugin, and `$PLUGIN_STATE['args']` records what the hook received. |
| `ab/fixture_lib.php`, `ab/fixtures/` | Fixture helpers that write rows the way core writes them (core's own keyword indexing), the base fixture, and per-package additions loaded with `fx_load()`. `fx_index()` builds the plugin's index through the generation rebuild once B8 exists. |
| `ab/accepted_divergences.php` | The accepted-divergence list the runner reads: one entry per case, with the kind, the reason and the decision row. Empty to start. |
| `ab/<ID>_<slug>.php` | One battery per task or package. `H1_smoke.php` is the smoke battery every session runs before handing over; `A1_skeleton.php` proves the skeleton (the option accessor, the lock, the setup page, the Q8.3 group override); `A2_client.php` proves the client against the private Typesense and against `stub_typesense.php`; `A3_schema.php` proves the schema module; `A4_dates.php` proves the dates module; `A5_text.php` proves the text module; `A6_state.php` proves the state store, with two processes sharing a file database for the counter; `I1_hook_order.php` proves the core change for the `external_search` hook: it fires before `search_special()` with the filter, join and select built, its answer is core's answer (an empty set included), `false` leaves the search to core, and the access checks that search (`do_search_access_check()`) never reach it; `X1_keys.php` proves the keys (provisioning with the private server's bootstrap key, the two keys' exact actions and scope, every refusal, the Q12.8 alias verdicts, the bootstrap key in no option, log or page, rotation) and X3's web exposure (every PHP file under `scripts/`, `tests/` and `harness/` requested through PHP's built-in server and refused, the deny `.htaccess` files, the setup page's gates). It uses two prefixes of its own (`x1a…`, `x1b…`) and removes its keys and collections. |
| `ab/stub_typesense.php` | A canned Typesense the A2 battery runs under PHP's built-in server on a free port: it records every request (the key header, the URI, the body) and answers 5xx, 503 or slowly to order, for the key, redaction, back-off, breaker and timeout cases a healthy server cannot produce. |
| `ab/state_writer.php` | The second writer of the A6 two-process case: boots on the shared file database named by `HARNESS_DB` and increments a counter when told to. |
| `ab/expected/` | Hand-written snapshots the batteries compare against (the version-1 schema from the register). |
| `run_all.sh`, `summarise.php` | Run every battery into `results/ab/`, then condense them into `results/summary.md` and `results/verdicts.json`. `results/` is never committed. |
| `typesense.sh` | Status, start or stop of the private Typesense (native hosts). |
| `run.ps1`, `battery.ps1`, `check.ps1`, `run.sh` | The Docker entry points (see [Running through Docker](#running-through-docker)): every battery, one battery, the code checks; `run.sh` does all three on a POSIX host. |
| `docker/` | The compose file (`php`, `typesense`, and `mysql` under the `mysql` profile), the `php` image's Dockerfile, and `common.ps1` shared by the three scripts. |
| `.gitattributes` | Keeps the `*.sh` files LF on Windows checkouts, so that the containers can run them. |
| `live/` | The live runners: the A/B through the API as two users (`run.php`), the catalogue run (`catalogue_run.php`), a value survey and a read-only database survey. Credentials come from the environment only. |
| `catalogue_table.php` | Writes the catalogue table (`../docs/core-search-catalogue.md`) from `live/catalogue.php` and a catalogue run's results. |
| `../tests/000010_typesense_smoke.php` | The one file core's test runner finds: runs the smoke battery when this directory exists and a local Typesense is available, passes quietly otherwise. |

## Running

Either through Docker (the next section: nothing to install but Docker) or natively. Natively it needs PHP with
`sqlite3` (3.35 or later) and `curl`, and `typesense-server` (30.x) on the PATH.

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

## Running through Docker

For a host with no PHP or Typesense of its own (the Windows PC). Needs Docker Desktop running, with the checkout
on a drive it can share (it is on `C:` by default), and nothing else: the `php` image (`docker/Dockerfile`, PHP 8.3
CLI with the extensions core's `composer.json` lists plus `sqlite3`, Composer and phpcs) is built on first use, and
the private Typesense (`typesense/typesense:30.2`) runs as the `typesense` service, reachable only inside the compose
network. The checkout is mounted at `/app`, so results land in `results/` exactly as a native run leaves them.

From the checkout root, in PowerShell:

```powershell
.\plugins\typesense_search\harness\run.ps1
```

Every battery: builds the image if missing, starts `typesense` and waits for its health check, runs `run_all.sh`
inside `php`; the output and the exit code are `run_all.sh`'s (0 green, 1 a failure, 2 nothing ran). One battery:

```powershell
.\plugins\typesense_search\harness\battery.ps1 A3_schema
```

The code checks the plan's rule 12 requires: `composer install` once (again with `-Reinstall`), then
`phpcs --standard=phpcs.xml.dist plugins/typesense_search` and the repository-wide `composer dev:phpstan` with a 2G
memory limit, both printed in full with a two-line summary at the end. `vendor/` lives on the `vendor` volume,
mounted over `/app/vendor`, not in the checkout: unpacking one package onto the bind mount took over five minutes.
Docker leaves an empty `vendor/` in the checkout as the mount point; git does not see it.

```powershell
.\plugins\typesense_search\harness\check.ps1
```

On a POSIX host with Docker, `./run.sh` (every battery), `./run.sh A3_schema` (one) and `./run.sh --check` do the
same. Each script takes `-Rebuild` (`--rebuild`) to build the image again after a Dockerfile change.

Inside the containers the environment is fixed by `docker/compose.yaml`: `HARNESS_ROOT=/app`,
`HARNESS_TS_HOST=127.0.0.1`, `HARNESS_TS_PORT=8108`, `HARNESS_TS_KEY=local-harness-key`. The `php` service shares
the `typesense` container's network namespace (`network_mode: service:typesense`), so the server is a loopback
address to it: the plugin's client takes plain http from a loopback host only (Q12.2), and the A2 battery sends the
plugin's own client to the harness server. `boot.php` finds the server healthy and starts nothing (there is no
`typesense-server` in the image); the compose health check makes sure it answers before `php` runs. The scratch directory is the container's `/tmp`, kept on the `php-tmp` volume
between runs together with phpstan's result cache; Composer's download cache is on `composer-cache`; Typesense's
data on `typesense-data`. Nothing else is written outside the checkout. The `*.sh` files must be LF for the
containers and the plugin's `*.php` files for phpcs (and for the move into SVN): `.gitattributes` here and in the
plugin root make git write them so, and the scripts convert a file that is still CRLF from an older checkout (git
sees no change). phpcs's warnings (line length, side effects in the entry files) are printed but do not fail
`check.ps1`; its errors do.

The containers are started per run (`docker compose run --rm`) and the `typesense` service is left running. To stop
or reset, from the harness directory:

```powershell
docker compose -f docker/compose.yaml down        # stop the containers, keep the volumes
docker compose -f docker/compose.yaml down -v     # also drop Typesense's data, the scratch, vendor/ and the Composer cache
.\run.ps1 -Rebuild                                # rebuild the php image
```

The `mysql` service (`mysql:8`, database `rs_harness`, user and password `rs_harness`, root password `rs_harness`,
host port 13306, data on `mysql-data`) is for the H9 MySQL mode and starts only when asked:

```powershell
docker compose -f docker/compose.yaml --profile mysql up -d mysql
```

The keys and passwords in the compose file are throwaway defaults for containers that only listen inside the
compose network (and `mysql` on the loopback port above); none of them is a secret.

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
