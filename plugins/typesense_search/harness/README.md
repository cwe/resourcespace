# Harness

The batteries that prove the `typesense_search` plugin, package by package, against what core actually does.
Command line only: every entry refuses a web request and `.htaccess` denies the directory. The harness lives
in the plugin on the GitHub branch and is left out of the release; nothing in the plugin depends on it.

The plan, the catalogue of search forms and the coverage map are in the planning repository
(`typesense_plan`); a case label here is a catalogue row id (`A1`, `D1`, `F19`) or a battery case id (`A3.12`).

## What is here

| Path | What it is |
|---|---|
| `ab/boot.php` | Boots core's real search code on one of two database drivers (below), the plugin's real code against a private local Typesense (started on demand), and defines the A/B runner `ab()`, the check helper `harness_check()` and `harness_scalar()` / `harness_run()` for a battery's own queries. `$HARNESS['hook_stub']`, when set, is returned by the `external_search` hook in place of the plugin, and `$PLUGIN_STATE['args']` records what the hook received. |
| `ab/db_sqlite.php`, `ab/db_mysql.php` | The two database drivers `boot.php` chooses between: SQLite (the default, in memory, built from `dbstruct/`, MySQL-only SQL translated, full text and `RLIKE` emulated) and MySQL (`HARNESS_DB=mysql`, see [The MySQL mode](#the-mysql-mode-h9)). |
| `ab/fixture_lib.php`, `ab/fixtures/` | Fixture helpers that write rows the way core writes them (core's own keyword indexing), the base fixture, and per-package additions loaded with `fx_load()`. `fx_index()` builds the plugin's index through the generation rebuild once B8 exists. |
| `ab/accepted_divergences.php` | The accepted-divergence list the runner reads: one entry per case, with the kind, the reason and the decision row. Empty to start. |
| `ab/<ID>_<slug>.php` | One battery per task or package. `H1_smoke.php` is the smoke battery every session runs before handing over; `A1_skeleton.php` proves the skeleton (the option accessor, the lock, the setup page, the Q8.3 group override); `A2_client.php` proves the client against the private Typesense and against `stub_typesense.php`; `A3_schema.php` proves the schema module; `A4_dates.php` proves the dates module; `A5_text.php` proves the text module; `A6_state.php` proves the state store, with two processes sharing a file database for the counter; `I1_hook_order.php` proves the core change for the `external_search` hook: it fires before `search_special()` with the filter, join and select built, its answer is core's answer (an empty set included), `false` leaves the search to core, and the access checks that search (`do_search_access_check()`) never reach it; `H9_mysql_parity.php` proves the emulations against MySQL (see [The MySQL mode](#the-mysql-mode-h9)); `X1_keys.php` proves the keys (provisioning with the private server's bootstrap key, the two keys' exact actions and scope, every refusal, the Q12.8 alias verdicts, the bootstrap key in no option, log or page, rotation) and X3's web exposure (every PHP file under `scripts/`, `tests/` and `harness/` requested through PHP's built-in server and refused, the deny `.htaccess` files, the setup page's gates). It uses two prefixes of its own (`x1a…`, `x1b…`) and removes its keys and collections. |
| `ab/stub_typesense.php` | A canned Typesense the A2 battery runs under PHP's built-in server on a free port: it records every request (the key header, the URI, the body) and answers 5xx, 503 or slowly to order, for the key, redaction, back-off, breaker and timeout cases a healthy server cannot produce. |
| `ab/state_writer.php` | The second writer of the A6 two-process case: boots on the shared file database named by `HARNESS_DB` (or, in the MySQL mode, on the battery's own database) and increments a counter when told to. |
| `ab/expected/` | Hand-written snapshots the batteries compare against (the version-1 schema from the register). |
| `run_all.sh`, `summarise.php` | Run every battery into `results/ab/`, then condense them into `results/summary.md` and `results/verdicts.json`; the MySQL mode writes the same set under `results/mysql/`. `results/` is never committed. |
| `typesense.sh` | Status, start or stop of the private Typesense (native hosts). |
| `run.ps1`, `battery.ps1`, `check.ps1`, `run.sh` | The Docker entry points (see [Running through Docker](#running-through-docker)): every battery, one battery, the code checks; `run.sh` does all three on a POSIX host. `run.ps1 -Db mysql` and `battery.ps1 <name> -Db mysql` (`HARNESS_DB=mysql ./run.sh`) run them on MySQL. |
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
| `HARNESS_DB` | `:memory:` | The database driver. `mysql` selects the MySQL mode (below); anything else is a file for the SQLite database, so that another process can share it (the A6 battery sets it for its writers). |
| `HARNESS_MYSQL_HOST`, `_PORT`, `_USER`, `_PASSWORD`, `_DATABASE` | `127.0.0.1`, `13306`, `rs_harness`, `rs_harness`, `rs_harness` | The MySQL mode's server: the compose file's `mysql` service on its published port (the php container is given the service name and port 3306). The database is dropped and created at every boot. |
| `HARNESS_MYSQL_KEEP` | unset | `1`: a second process that shares a battery's MySQL database (the A6 writers) connects to it as it is instead of recreating it. |

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
host port 13306, data on `mysql-data`) is for the MySQL mode and starts only when asked (`run.ps1 -Db mysql` does,
and waits for its health check):

```powershell
docker compose -f docker/compose.yaml --profile mysql up -d --wait mysql
```

The keys and passwords in the compose file are throwaway defaults for containers that only listen inside the
compose network (and `mysql` on the loopback port above); none of them is a secret.

## The MySQL mode (H9)

SQLite stands in for MySQL by default because it is fast (every battery in about twenty-five seconds), but it
emulates `MATCH … AGAINST` and `RLIKE` in PHP and compares text without folding accents. `HARNESS_DB=mysql` runs the
same batteries, runner and summariser with core's own database code and SQL, unchanged, against a real MySQL 8: the
proof for the forms the emulation only imitates (wildcards, the full-text box, phrases, accents, collation).

```powershell
.\run.ps1 -Db mysql                          # every battery, about a minute and a half
.\battery.ps1 H9_mysql_parity -Db mysql      # one
```

`HARNESS_DB=mysql ./run.sh [battery]` on a POSIX host. Nothing runs in this mode unless asked, and the SQLite mode
stays the default. Results are kept apart: `results/mysql/` holds the same `ab/`, `summary.md` and `verdicts.json`.
A battery in this mode prints the server it ran on as its first line (`driver: MySQL 8.4.13: innodb_ft_min_token_size=3
…`). The first start pulls `mysql:8` and initialises the data volume, which takes a minute. Natively, start a MySQL
on `127.0.0.1:13306` with the database, user and password above (the compose file's service on its published port)
and run `HARNESS_DB=mysql ./run_all.sh`.

**What the driver does** (`ab/db_mysql.php`). Core's `include/database_functions.php` is loaded whole, so `ps_query`,
`ps_value`, `ps_array`, `sql_connect()`, `check_db_structs()` and the rest are core's, not replacements. The copy
differs from the file in three places only: its own location, a call that hands every statement to the statement log
(`$HARNESS['sql_log']`, which A6 and I1 read), and a call that records a statement MySQL refuses even after core's
retry through `check_db_structs()` (as `$HARNESS['sql_errors']`, shown as "core SQL did not run on MySQL") where core
would call `errorhandler()` and `exit()`. At every boot the database is dropped and created, `sql_connect()` connects,
and `check_db_structs()` builds the tables from core's `dbstruct/` and the plugin's, twice (the second pass adds the
`resource.field<n>` copy columns); the initial rows of a new installation (`dbstruct/data_*.txt`) are then emptied so
that the fixture is the whole content, as it is under SQLite, and the `field<n>` columns the fixture writes that core's
default configuration does not join are added. The query cache is off. A second process that shares a battery's
database (the A6 writers) sets `HARNESS_MYSQL_KEEP=1`. The fixtures are the same files, written through `fx_exec()`.

**The server** (`mysql` in `docker/compose.yaml`) runs with MySQL 8's defaults for everything the searches depend on,
written on the command line so that they are visible:

| Setting | Value |
|---|---|
| `innodb_ft_min_token_size` | 3 |
| `innodb_ft_max_token_size` | 84 |
| `innodb_ft_enable_stopword` | ON, with no `innodb_ft_server_stopword_table`: InnoDB's built-in list of 36 words (`INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD`) |
| `character-set-server`, `collation-server` | `utf8mb4`, `utf8mb4_0900_ai_ci` (case- and accent-insensitive) |

**These are not known to be the test host's values**: the plan does not record them. Confirm them there with
`SHOW VARIABLES LIKE 'innodb_ft%';`, `SELECT @@version, @@collation_server, @@collation_database;` and the
collation of `node.name` and `keyword.keyword`, and change the compose file's command (and the `mysql:8` tag, which is
8.4 today) if they differ: the full-text results change with them. H9's first check asserts the settings the server
reports against the list above.

**The H9 battery** (`ab/H9_mysql_parity.php`, fixture additions in `ab/fixtures/h9_parity.php`) runs the emulated
forms (a trailing, a leading, an inner and a two-letter wildcard, an OR of two wildcards with a semicolon, the
full-text box with words, a phrase, a phrase with a stop word or a short word, a hyphen and an exclusion, quoted
phrases, case and accents) with the refs MySQL must return derived by hand. In the MySQL mode it then boots a SQLite
process on the same fixture and prints the two answers side by side; a case fails where they differ unless it carries
a documented limit of the emulation, and fails too when a documented limit stops applying. In the default mode it
checks the emulation's answers against the same table.

**Documented limit of the emulation**: accents. SQLite's `NOCASE` folds case for ASCII only and the emulated full text
compares exact characters, so a search for `zoe` does not find "Zoë", `cafe*` does not find "Café", `country:zurich`
does not find the option "Zürich". The same goes for every other rule of the `utf8mb4_0900_ai_ci` collation (ligatures,
`ß`/`ss`, trailing spaces). Cases that depend on collation are proved by this mode only.

**Where the emulation was corrected** (H9 measured InnoDB and fixed `harness_ft_match()` in `ab/db_sqlite.php`): the
expression `(+land*) (+sun*)` core builds for the `RLIKE` prefilter was read as two required terms, so a wildcard OR
with a semicolon returned nothing; a phrase with a stop word or a word under three characters never matched (InnoDB
compares the phrase with the text, stop words kept, short words left out of both sides); a hyphen inside a word was
read as part of it, where InnoDB reads `foo-bar` as `foo -bar`.

## Reading the output

```
A1:  "sunset"
    core:   total=5 refs=[1,8,12,36,37]  (order [8,1,37,36,12])
    plugin: not run (the plugin has no search hook yet)
    => PASS
```

- `core:` is core's answer on the driver in use; a case with an `expect` set fails when core's answer is not the one derived
  by hand (a harness or fixture error, or a wrong derivation). `(core side emulated: full text)` marks the forms
  SQLite only emulates (the MySQL mode never prints it); the MySQL mode is their proof.
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
  case. The MySQL mode removes the caveat for the forms H9's battery covers; a form it does not cover is still
  unproved, and the emulation follows what InnoDB was measured to do, not InnoDB's source.
- **The MySQL mode is MySQL as the compose file configures it**, not the test host: the full-text settings and the
  collation are to be confirmed there (see above).
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
