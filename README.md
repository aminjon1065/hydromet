# Hydromet environmental monitoring portal

Russian project summary: [README.ru.md](README.ru.md).

This repository contains the source requirements, the project specification and
the implementation in progress for a new standalone environmental monitoring
portal for Tajikistan.

The current product decisions are:

- the portal is a new codebase; changes to `meteo.tj` are outside the main estimate;
- Hydromet supplies the station registry, current and historical observations, SmartMet endpoints and MeteoAlert data;
- SILAM is embedded through the supplied iframe URL and is not processed locally;
- the portal normalizes external data into its own canonical model and remains usable when an upstream source is temporarily unavailable;
- the recommended implementation is a Laravel modular monolith with an Inertia React + TypeScript and shadcn/ui public interface, Filament administration and PostgreSQL/PostGIS;
- the first production year runs on a VPS supplied by the contractor, with backups stored outside that VPS.

## Documentation

- [Product scope](docs/01-product-scope.md)
- [Architecture](docs/02-architecture.md)
- [Data contracts](docs/03-data-contracts.md)
- [SmartMet and MeteoAlert analysis](docs/04-smartmet-and-alerts.md)
- [Portal API contract](docs/05-api-contract.md)
- [Testing and acceptance](docs/06-testing-and-acceptance.md)
- [Delivery plan and estimate](docs/07-delivery-plan.md)
- [Inputs required from Hydromet](docs/08-hydromet-input-checklist.md)
- [Operational runbooks](docs/09-runbooks.md)

## Source documents

- `ТЗ_Таджикистан_веб_портал_экомониторинга_RU_v2_SmartMet.docx`
- `FINTAJ_IT assisment_Agreement_11082026_web_site (1).docx`

## Status

Phase 1 (application foundation) is complete.

Phases 2A–2F are complete against **mock** providers: the canonical station and
measurement schema, both import services, the synchronization journal and its
read-only operator views, aggregate reconciliation, and bounded incremental
window/overlap workflow exist, fed by checked-in development fixtures. No
Hydromet data has been received. A real provider adapter and its scheduled job
remain blocked until the decisions marked `BLOCKING` in the Hydromet input
checklist are answered or explicitly replaced with documented mock contracts.

The safe mock-backed part of Phase 3 is also implemented: the multilingual
public station overview uses a Leaflet map, each public station has historical
ECharts views for 24 hours, 7 days, 30 days and 1 year, and the same selected
period/parameters can be streamed as CSV. Invalid values are excluded and
missing values remain gaps. The supplied SILAM view is available separately as
an iframe with a direct-link fallback. These screens clearly identify fixture
data; they are not Hydromet observations.

Phase 2G adds the mock-backed MeteoAlert vertical slice: a canonical warning
model with full CAP `Alert`/`Update`/`Cancel` lifecycle and message history, a
provider-neutral `AlertProvider` boundary with a fixture adapter, an idempotent
import journalled through the existing synchronization runner, `/api/v1/alerts`
and `/api/v1/alerts/{identifier}`, warning polygons on the existing station map
with an accessible warning list and a legend, and a read-only administrative
view. The warnings are invented demonstration data, labelled as such in all
three languages on every screen that shows them. No MeteoAlert source has been
chosen yet, so no real adapter exists.

## Application

The Phase 1 foundation is in place: a Laravel modular monolith with an Inertia
React + TypeScript public shell, a Filament administration panel, Docker
Compose services and the code-quality tool-chain.

Phase 2A adds the `stations`, `parameters` and `station_parameter` tables, the
canonical station/parameter records from `docs/03-data-contracts.md`, the
`StationRegistryProvider` integration boundary with a fixture adapter, an
idempotent registry import service and read-only `Station`/`Parameter` Filament
resources.

Phase 2B adds the `measurements` and `measurement_revisions` tables, the
canonical measurement record, the `MeasurementProvider` boundary with fixture
adapters for a base batch and a correction batch, and an idempotent import
service that applies source revisions while preserving the originally supplied
value.

Phase 2C adds the `integration_sources`, `synchronization_runs` and
`synchronization_rejected_rows` tables and the `SynchronizationRunner` that
journals every import attempt. Both fixture commands now run through it, so each
invocation leaves a run with its status, counters and quarantined rows.

Phase 2D exposes integration-source configuration, synchronization runs and
safe rejected-row summaries in Filament. These operator resources only register
list and view routes: synchronization evidence cannot be created, edited or
deleted through the panel.

Phase 2E adds deterministic aggregate reconciliation for the fixture dataset.
Phase 2F adds bounded UTC synchronization windows, persisted run cursors and
configurable overlap. Its fixture contract proves a late source correction is
captured without duplicating measurements.

The first public portal slice adds the national station map and current values,
localized station metadata, historical charts with server-side hourly/daily
aggregation, parameter/period filters, source synchronization time and a
memory-safe streamed CSV export. SILAM is embedded from the configured FMI URL
under a page-specific frame policy. A local CMS now supports incomplete drafts,
three required title/body translations for publication, future publication,
plain-text public pages and `/api/v1/content/{slug}`. Administrators and editors
may create/edit content; operators have read-only access. Content creation and
changes write immutable before/after audit events, visible only to
administrators under the provisional least-privilege role matrix.

Response hardening is in place and covered by tests: baseline security headers
and a Content Security Policy on every response, including error and
unmatched-route responses; a SILAM frame permission derived from the configured
embed URL that fails closed; API failures that never disclose an exception,
stack trace or credential even with `APP_DEBUG` on; and a panel-wide sweep
asserting that no administration page is reachable by a guest or a deactivated
user.

Every public response carries a per-request `script-src` nonce, so an injected
script is refused unless it carries a value the attacker cannot predict. Styles
default to `'self' 'unsafe-inline'`; `CSP_STYLE_NONCE=true` switches them to the
nonce form, which additionally needs `style-src-attr 'unsafe-inline'` for the
inline style attributes Leaflet sets — see `config/security.php` for why that is
not the default. The administration panel runs on a separate, weaker policy
because Filament renders inline scripts that accept no nonce and Alpine
evaluates its expressions with `new AsyncFunction`.

Administrators can stream the immutable audit log as CSV from the panel. The
file is language-neutral, its cells cannot be evaluated as spreadsheet formulas,
and every export is itself recorded in the log.

Account administration is implemented on the provisional three-role model:
administrators create accounts, assign a role, activate or deactivate, and set a
password, all through one audited domain service. The panel refuses to remove
the last active administrator, and refuses to let an administrator deactivate or
demote themselves. Accounts are deactivated, never deleted, so their audit
history keeps its actor — enforced by the model and by a database trigger.
Deactivating an account, changing its role or changing its password signs its
existing sessions out on their very next request, through a security stamp on
the account rather than by deleting session rows, so it works whichever session
driver is configured. Passwords are stored hashed and never displayed, exported
or written to the audit log. The first administrator is created by a one-time
command on an empty installation. The final role matrix and the list of users are
still awaited from Hydromet, the portal ships with no default administrator, and
password-reset e-mail is blocked until SMTP is configured.

`GET /api/v1/system/status` publishes whether the portal's copy of each enabled
source is current: a source code, a state, the last successful import and the
approved staleness threshold, and nothing about the internal configuration. It
is not a health check of the application — `/up` and `/health` answer that and
are what a monitoring system should watch. Real thresholds are still awaited
from Hydromet, so a source without one is published as `unknown`, which is
deliberately not presented as healthy; no fixture result is evidence of a
production service level.

The CSV layout remains provisional until Hydromet approves an acceptance
fixture. AQI, SmartMet layers, real source adapters (MeteoAlert included),
manual measurement correction, real-source scheduling, queue retries, approved
staleness thresholds, and approved content and navigation are not implemented
yet. The `/api/v1` metadata, station list/detail, bounded series, CSV,
published-content, alert — in force, published history and detail — and
system-status endpoints are implemented against canonical local read models.

### Selected versions

| Component | Version |
| --- | --- |
| PHP | 8.5 (`^8.3` required) |
| Laravel | 13.x |
| Inertia (Laravel adapter) | 3.x |
| React | 19.x |
| TypeScript | 5.9 |
| Vite | 8.x |
| Tailwind CSS | 4.x |
| shadcn/ui CLI | 4.x |
| Filament | 5.x |
| Leaflet / React Leaflet | 1.9 / 5.x |
| ECharts | 6.x |
| PostgreSQL / PostGIS | 18 / 3.6 |
| Redis | 8.x |
| Nginx | 1.29 |

### shadcn/ui configuration

Recorded in `components.json`: preset **Nova**, component base **radix**, style
`radix-nova`, TSX enabled, React Server Components disabled, CSS variables
enabled, base colour `neutral`, icon library `lucide`. Components are added
individually; the foundation uses only `button`, `card`, `badge` and
`dropdown-menu`.

### Layout

```text
app/
  Console/Commands/  Artisan commands
  Domain/            business capabilities (Identity, Stations, Integrations)
    Identity/        users, roles, panel access
    Integrations/    external source contracts and adapters
      Contracts/     StationRegistryProvider
      Fixtures/      development fixture adapter and its JSON data
    Stations/        station registry and parameter catalogue
      Data/          canonical records, batches, import results
      Enums/         status, type, parameter kind, rejection reasons
      Models/        Station, Parameter
      Services/      StationRegistryImporter (only writer of these tables)
  Filament/          administration resources (read-only in this phase)
  Http/              controllers and middleware
  Providers/         service and Filament panel providers
  Support/
    Health/          readiness checks
    Locale/          application locale keys and BCP 47 mapping
docker/              Nginx and PHP-FPM images and configuration
lang/{tj,ru,en}/     interface translations
resources/js/        Inertia React application
  components/ui/     shadcn/ui components
  hooks/ layouts/ lib/ pages/ types/
tests/               PHPUnit backend tests
tests/frontend/      Vitest frontend tests
```

## Local development

Everything runs through Docker Compose. Frontend tooling runs on the host.

### Install

```bash
cp .env.example .env
docker compose build
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
npm install
npm run build
```

### Start

```bash
docker compose up -d          # nginx, app, queue, scheduler, postgres, redis
npm run dev                   # Vite dev server with hot reload
```

The portal is served on `http://localhost:8080` (`APP_HTTP_PORT`), the SILAM
view on `http://localhost:8080/silam`, and the administration panel on
`http://localhost:8080/admin`. Station detail URLs are generated from the
public overview after fixture or approved production data has been imported.
Published CMS pages use `http://localhost:8080/content/{slug}`; the corresponding
JSON endpoint is `http://localhost:8080/api/v1/content/{slug}`.
`http://localhost:8080/alerts` is the published warning history — everything the
portal has published, newest first, including expired and withdrawn warnings —
and a single warning is at `http://localhost:8080/alerts/{source}/{identifier}`,
linked from each entry: a CAP identifier is unique within its sender, not
globally, so the address is the pair.
The versioned read API starts at `http://localhost:8080/api/v1/metadata` and
`http://localhost:8080/api/v1/stations`.

### Container topology and published ports

| Service | Published on the host | Reached from containers as |
| --- | --- | --- |
| `nginx` | `0.0.0.0:${APP_HTTP_PORT:-8080}` → 80 | — |
| `postgres` | `127.0.0.1:${FORWARD_DB_PORT:-5432}` → 5432 | `postgres:5432` |
| `redis` | `127.0.0.1:${FORWARD_REDIS_PORT:-6379}` → 6379 | `redis:6379` |
| `app`, `queue`, `scheduler` | not published | `app:9000` (FastCGI) |

PostgreSQL and Redis are bound to loopback so they are never exposed on the
host's external interfaces. The `app`, `queue` and `scheduler` containers do
not use those mappings: they resolve `postgres` and `redis` over the internal
Compose network, which is why `DB_HOST=postgres` and `REDIS_HOST=redis` in
`.env.example`. Only host-side tooling such as `psql` or `redis-cli` uses the
loopback mappings.

> **Development configuration.** `compose.yaml` bind-mounts the working copy
> into the containers (`.:/var/www/html`) and serves assets built on the host.
> It is a development environment, not a deployable artefact. See
> [Production deployment](#production-deployment).

### Migrate

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh    # rebuild the schema
```

### First administrator

No account is seeded: a shared password must never exist in the repository, and
there is no default administrator.

```bash
docker compose exec app php artisan users:bootstrap-administrator
```

The command asks for a name, an address and a password — typed hidden, never
passed as an option, so it stays out of the shell history and the process list —
applies the same password policy as the panel, creates one active
`administrator` and records an `identity.user.created` event. It runs **only
while the `users` table is completely empty** and refuses afterwards, so it
cannot become a second way to add people.

Every account after the first is created in the panel, under
**Identity → User accounts**. Create a second administrator early: a sole
administrator can no longer be deactivated or demoted by anyone, including
themselves.

### Station registry fixture

Until Hydromet supplies a real registry export, the station and parameter
catalogue is loaded from a checked-in development fixture. The data is invented
and stored under the `fixture` source key so it can never be confused with, or
collide with, a real provider.

```bash
docker compose exec app php artisan stations:import-fixture-registry
```

The command is idempotent: running it twice reports every row as `unchanged`
and adds no rows. The fixture deliberately contains one broken row, so the
output always ends with a partial result and one rejection; that is expected
and does not make the command fail. It refuses to run in `production`.

### Measurement fixtures

Observations come from the same invented `fixture` source. The station registry
fixture must be imported first, because a measurement is tied to a station by
`source` + `station_external_id`.

```bash
docker compose exec app php artisan measurements:import-fixture-batch --scenario=base
docker compose exec app php artisan measurements:import-fixture-batch --scenario=correction
docker compose exec app php artisan data:reconcile-fixture
```

`base` is a small historical batch containing one reading with no value
(`quality: missing`), one row with no sensor number, and one deliberately broken
row naming a station that does not exist. `correction` restates one of those
observations at revision 2 with a different value and quality.

Both scenarios are idempotent. The correction writes one `measurement_revisions`
row holding the value before and after; re-running it reports `unchanged` and
writes no further history. The originally supplied value and quality are never
rewritten, so replaying `base` afterwards is rejected as a stale revision rather
than undoing the correction. `--scenario` is required and accepts only `base` or
`correction`, and the command refuses to run in `production`.

### Warning fixtures

Warnings come from the same invented `fixture` source. Hydromet has not chosen a
MeteoAlert source type, so there is no real adapter and these are demonstration
data only (`docs/08-hydromet-input-checklist.md`, section 3).

```bash
docker compose exec app php artisan alerts:import-fixture-feed --scenario=baseline
docker compose exec app php artisan alerts:import-fixture-feed --scenario=lifecycle
```

`baseline` contains an active warning with one polygon, a warning with two
affected areas (one of them a MultiPolygon), an already-expired warning, a
`Test`-status message that must never reach the public, and one deliberately
broken row whose area is a `LineString` and is therefore rejected.

`lifecycle` sends an `Update` for the first warning and a `Cancel` for the
second. The `Update` supersedes its predecessor and takes its place; the
`Cancel` withdraws its target and is never itself displayed. Both superseded
messages stay in `alert_messages`, because the published history is the point.

Both scenarios are idempotent: re-running reports every message as `unchanged`
and supersedes nothing further. `--scenario` is required and accepts only
`baseline` or `lifecycle`, and the command refuses to run in `production`.

Event codes carry a `FIXTURE_` prefix, and the severity colours on the map are a
provisional portal choice labelled as such in all three languages — neither is
an approved Hydromet vocabulary.

### Synchronization journal

All three fixture commands run through `SynchronizationRunner`, so every
invocation is recorded in `synchronization_runs`
(docs/03-data-contracts.md, section 8).
The `fixture` row in `integration_sources` is created on first use; no seeding
step is needed.

```bash
docker compose exec postgres psql -U hydromet -d hydromet -c \
  "SELECT id, kind, status, received_count, accepted_count, updated_count, rejected_count
   FROM synchronization_runs ORDER BY id"

docker compose exec postgres psql -U hydromet -d hydromet -c \
  "SELECT synchronization_run_id, reference, reason_code, safe_detail
   FROM synchronization_rejected_rows ORDER BY id"
```

A run is `succeeded` when nothing was quarantined, `partial` when it finished
with rejected rows, and `failed` when it stopped on an unexpected error. Running
a command twice creates two runs and changes no data — the second one reports
everything as already stored.

Nothing in the journal is a secret. `integration_sources` has no credential
column at all: it records *how* a source authenticates, while the key itself
lives in server-side secrets, and a stored `base_url` may carry neither a query
string nor `user:pass@` userinfo. A failed run keeps a stable `error_code` and a
fixed safe sentence, and the log records only the run id, the source code, the
kind and the exception's class name. The exception itself — its message and
trace — is deliberately never written anywhere, because a provider failure
message routinely carries a DSN, an `Authorization` header or a slice of the
raw payload. Reproducing a cause means re-running the import with the
provider's own diagnostics.

Active panel users can inspect the same non-secret data without database access:

- `/admin/integration-sources` lists source configuration and mappings;
- `/admin/synchronization-runs` lists attempts, counters and statuses;
- each run view includes its sanitized rejected-row summaries.

These pages are strictly read-only. Credentials, raw provider payloads,
exception messages and stack traces are neither stored nor displayed.

### Fixture data reconciliation

After importing the registry, base measurement batch and correction batch, run:

```bash
docker compose exec app php artisan data:reconcile-fixture
```

The command compares station count, measurement count by station and parameter,
observation bounds, missing/invalid/suspect counts and revision count with the
checked-in synthetic expectation. It exits non-zero and prints every differing
aggregate when anything is missing or extra. It is blocked in production and is
explicitly not Hydromet acceptance evidence; the real reference-period totals
must be supplied and approved by Hydromet.

### Incremental synchronization core

`SynchronizationWindowPlanner` starts from an explicit bootstrap boundary,
then builds each next UTC interval from the latest completed cursor minus the
source's configured overlap. Failed and still-running attempts never advance
that cursor. `MeasurementSynchronizer` applies the bounded provider read through
the same idempotent importer and stores the exact attempted interval on the run.
The checked-in correction fixture proves that a late correction inside the
overlap is applied once and recorded as a source revision.

### Test

```bash
docker compose exec app composer test    # backend, SQLite in memory
npm test                                 # frontend (Vitest)
```

`tests/TestEnvironment.php` pins cache, session, queue, mail and broadcasting to
isolated drivers before PHPUnit loads a test, so a run behaves the same on the
host and inside Compose, which passes the whole `.env` to every service. The
database defaults to SQLite in memory and only an explicit opt-in moves it; an
inherited `DB_CONNECTION` or `DB_DATABASE` never does.

A few schema guarantees — `CHECK` constraints, trigger-enforced immutability,
foreign-key behaviour and the PostGIS extension — exist only on PostgreSQL and
are skipped on SQLite. Create the scratch database once, then run the suite
against PostgreSQL before relying on them:

```bash
docker compose exec postgres psql -U hydromet -d postgres \
  -c "CREATE DATABASE hydromet_testing OWNER hydromet"

docker compose exec app composer test:pgsql
```

`composer test:pgsql` sets `HYDROMET_TEST_DB=pgsql`, uses the scratch database
`hydromet_testing` and runs with `--fail-on-skipped`, so a PostgreSQL-only
guarantee that stops executing fails the run instead of disappearing. Host, port
and credentials still come from `.env`. `HYDROMET_TEST_DATABASE` renames the
scratch database; naming the application's own database aborts the run.

### Lint, static analysis and typecheck

```bash
composer lint        # Laravel Pint, check only
composer format      # Laravel Pint, apply
composer analyse     # PHPStan / Larastan, level 8
composer check       # lint + analyse + test

npm run lint         # ESLint
npm run types:check  # tsc --noEmit
npm run format       # Prettier, frontend sources only
npm run format:check # Prettier, check only
npm run build        # production asset bundle
```

### Dependency audit

```bash
composer security         # locked PHP tree: any advisory or abandoned package fails
npm run audit:production  # runtime JS dependencies: moderate and above fails
npm run audit:all         # runtime and development JS: high and above fails
npm run security          # both npm audits, in that order
```

| Ecosystem | Scope | Fails on | Why |
| --- | --- | --- | --- |
| PHP | Whole locked tree (`composer.lock`) | Any advisory, and any abandoned package | The backend serves every request; an unmaintained package will not be issued an advisory when it needs one |
| npm | Runtime dependencies only | `moderate`, `high`, `critical` | These reach a browser or a request |
| npm | Runtime and development | `high`, `critical` | The development tree only has to build and test soundly |

The audits read `composer.lock` and `package-lock.json`, so they judge the tree
that is actually deployed — not whatever a fresh resolution would pick — and
neither needs the dependencies installed. Both are read-only: nothing here
updates a lock file, and there is deliberately no `npm audit fix` and no
`composer update` in any script or workflow. A failure is triaged by hand; the
procedure is in [`docs/09-runbooks.md`](docs/09-runbooks.md), section 8b.

On 2026-09-03 all three reported zero findings.

These thresholds are a **provisional** policy chosen to be safe rather than
convenient. Whether a dependency scan must gate a release, and at which
severity, is still an owner decision
([`docs/08-hydromet-input-checklist.md`](docs/08-hydromet-input-checklist.md),
section 6).

### Continuous integration

`.github/workflows/ci.yml` runs on every push to `master` and every pull
request, in three parallel jobs:

| Job | Checks |
| --- | --- |
| Backend | `composer validate --strict`, `composer lint`, `composer analyse`, `composer test` |
| Backend (PostgreSQL) | `composer test:pgsql` against a `postgis/postgis:18-3.6` service, no skipped tests |
| Frontend | `npm ci`, `format:check`, `lint`, `types:check`, `npm test`, `npm run build` |

It installs from `composer.lock` and `package-lock.json`, uses the PHP version
in `docker/php/Dockerfile` and the Node version in `.nvmrc`, and publishes no
artefact and no deployment. The commands are the ones above, so a green pipeline
and a green local run mean the same thing.

`.github/workflows/dependency-security.yml` is separate, because it answers a
question whose answer changes without the code changing: it also runs weekly, on
a cron GitHub evaluates in **UTC**, and can be started by hand.

| Job | Checks | Runs on |
| --- | --- | --- |
| Locked dependencies | `composer validate --strict`, `composer security`, `npm run audit:production`, `npm run audit:all` | Pull requests, pushes to `master`, Mondays 04:17 UTC, manual dispatch |
| Dependency review | `actions/dependency-review-action@v4`, blocking `moderate` and above in every scope — `runtime`, `development`, `unknown` | Pull requests only |

It holds `contents: read` and nothing more, uses no secret, writes no pull-request
comment and installs neither dependency tree — the audits read the lock files.
The two halves are complementary: the audits judge the whole tree as it stands,
the review judges what a pull request proposes to add.

Every Dependency Review setting is stated rather than inherited, because the
action's default `fail-on-scopes` is `runtime` alone: it runs with
`fail-on-scopes: runtime, development, unknown`, `vulnerability-check: true` and
`warn-only: false`. There is no allowlist and no exception, here or in
`composer.json`. Full rationale in
[`docs/06-testing-and-acceptance.md`](docs/06-testing-and-acceptance.md),
section 6.2.

> **Not yet observed on GitHub.** The workflow's configuration is asserted by
> `tests/Feature/Security/DependencyAuditPolicyTest.php` and its commands have
> been run locally, but no run has executed on GitHub yet. In particular
> Dependency Review needs the repository's dependency graph enabled; until the
> first remote run, treat that job as unverified.

### Stop

```bash
docker compose down          # stop services, keep data
docker compose down -v       # stop services and delete database/Redis volumes
```

### Health endpoints

| Path | Purpose |
| --- | --- |
| `/up` | Liveness. Proves the framework booted. |
| `/health` | Readiness. Also checks the database and cache store; returns `503` when either is unavailable. Contains no hostnames or credentials. |

`/api/v1/system/status` is implemented and is a different question from the two
above: it reports whether the portal's copy of each **enabled** source is
current, not whether the application is alive. It does not replace `/health`,
and a monitoring system should keep watching `/up` and `/health`.

It currently answers with an empty list and an overall `unknown`, because the
fixture source is deliberately disabled and no real source exists yet. Once a
source is enabled it reports `unknown` until an approved `stale_after_seconds`
is entered for it — the portal will not call a source healthy on a threshold
nobody approved. The contract is in `docs/05-api-contract.md`.

### Languages and time

Application locale keys are `tj`, `ru` and `en` with `ru` as the fallback. The
internal `tj` key is mapped to the standards-based `tg-TJ` tag only at external
boundaries (HTML `lang`, `Content-Language`, later CAP). Timestamps are stored
in UTC and displayed in `Asia/Dushanbe` (`APP_DISPLAY_TIMEZONE`).

Browsers do not all carry Tajik locale data — Chrome 152 has none, and
`Intl.DateTimeFormat('tg-TJ')` silently resolves to `en-US` there, which used to
print `Jan 15, 2026, 11:30 AM` on Tajik pages. `resources/js/lib/datetime.ts`
detects that once at load and composes CLDR's `tg` format itself, taking the
calendar and timezone arithmetic from a locale every runtime has. A runtime that
does carry the data keeps using it, so the fallback retires itself.

### Production deployment

**`compose.yaml` is for local development only and must not be used as-is on
the VPS.** It bind-mounts the working copy into every PHP container, ships no
compiled assets, runs with `APP_DEBUG=true` defaults from `.env.example` and
relies on `composer install` being run by the developer.

Before the first VPS deployment the following is still required and is **not**
part of Phase 1:

- a release image that `COPY`s the application, runs `composer install
  --no-dev --optimize-autoloader` and embeds the `npm run build` output,
  instead of bind-mounting the source tree;
- a `compose.prod.yaml` override that removes the bind mounts and the
  `postgres`/`redis` host port mappings, pins image tags by digest and sets
  restart/resource policies;
- TLS termination, `APP_DEBUG=false`, cached config/routes/views and a
  non-writable application directory;
- the external backup destination and restore rehearsal required by
  `docs/01-product-scope.md`, section 3.4.

### Dependency notes

`composer.json` contains one deliberate `conflict` entry:

```json
"conflict": { "symfony/mailer": "8.1.5" }
```

The distributable archive for `symfony/mailer v8.1.5` is not retrievable from
GitHub: `api.github.com/.../zipball/89f43137…` redirects to
`codeload.github.com/.../legacy.zip/89f43137…`, which answers `400 Bad Request`,
so any clean `composer install` fails on CI and on the VPS. The commit itself is
valid and the tag archive works, so this is an upstream packaging fault rather
than a problem with the release. The conflict pins the dependency to `v8.1.2`,
which installs normally.

Remove the entry and run `composer update symfony/mailer` once the upstream
archive is downloadable again.

### Without Docker

The application can also be run with `php artisan serve` against SQLite, which
is useful when Docker is unavailable. PostgreSQL-specific behaviour, including
the PostGIS extension, is then not exercised.
