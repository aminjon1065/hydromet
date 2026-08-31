# Hydromet environmental monitoring portal

Russian project summary: [README.ru.md](README.ru.md).

This repository contains the source requirements, the project specification and the implemented application foundation (Phase 1) for a new standalone environmental monitoring portal for Tajikistan.

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

## Source documents

- `ТЗ_Таджикистан_веб_портал_экомониторинга_RU_v2_SmartMet.docx`
- `FINTAJ_IT assisment_Agreement_11082026_web_site (1).docx`

## Status

Phase 1 (application foundation) is complete.

Phase 2A (station and parameter catalogue) and Phase 2B (measurement storage and
source revisions) are complete against **mock** providers: the canonical schema,
both import services and a read-only administration view exist, fed by
checked-in development fixtures. No Hydromet data has been received. The
remaining data work starts once the decisions marked `BLOCKING` in the Hydromet
input checklist are answered or explicitly replaced with documented mock
contracts.

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
value. AQI, SmartMet, MeteoAlert, SILAM, manual correction, incremental
scheduling, the public map, charts and the public API are not implemented yet.

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

The portal is served on `http://localhost:8080` (`APP_HTTP_PORT`), the
administration panel on `http://localhost:8080/admin`.

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

No account is seeded: a shared password must never exist in the repository.

```bash
docker compose exec app php artisan make:filament-user
```

New accounts default to the `editor` role and `is_active = true`; change the
role in the database until user management ships.

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

### Test

```bash
docker compose exec app php artisan test    # backend, SQLite in memory
npm test                                    # frontend (Vitest)
```

A few schema guarantees — `CHECK` constraints, foreign-key behaviour and the
PostGIS extension — exist only on PostgreSQL and are skipped on SQLite. Run the
suite against PostgreSQL before relying on them:

```bash
docker compose exec postgres psql -U hydromet -d postgres \
  -c "CREATE DATABASE hydromet_testing OWNER hydromet"

docker compose exec \
  -e DB_CONNECTION=pgsql -e DB_DATABASE=hydromet_testing \
  app php artisan test
```

### Lint, static analysis and typecheck

```bash
composer lint        # Laravel Pint, check only
composer format      # Laravel Pint, apply
composer analyse     # PHPStan / Larastan, level 8
composer check       # lint + analyse + test

npm run lint         # ESLint
npm run types:check  # tsc --noEmit
npm run format       # Prettier, frontend sources only
npm run build        # production asset bundle
```

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

`/api/v1/system/status` stays reserved for the public source-health contract in
`docs/05-api-contract.md` and is implemented with the first integration.

### Languages and time

Application locale keys are `tj`, `ru` and `en` with `ru` as the fallback. The
internal `tj` key is mapped to the standards-based `tg-TJ` tag only at external
boundaries (HTML `lang`, `Content-Language`, later CAP). Timestamps are stored
in UTC and displayed in `Asia/Dushanbe` (`APP_DISPLAY_TIMEZONE`).

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
