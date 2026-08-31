# Architecture

## 1. Decision

Use a Laravel modular monolith. The public React application, administration, API, scheduled imports and persistence are deployed as one product. External Hydromet systems are isolated behind adapters.

Recommended baseline at project bootstrap:

- Laravel 13 or the current supported Laravel release;
- Inertia.js, React and TypeScript for the interactive public portal;
- shadcn/ui with Tailwind CSS and semantic CSS variables for the public design system;
- Filament 5 for administration;
- PostgreSQL with PostGIS;
- Leaflet for the unified station and warning map;
- Apache ECharts for time-series charts;
- Laravel Scheduler and queued jobs for imports;
- Redis for production cache and queues, with database queues acceptable during early development;
- Nginx, PHP-FPM and Docker Compose on the VPS.

Exact framework versions are locked when scaffolding begins and recorded in `composer.lock` and `package-lock.json`.

Initialize shadcn/ui through its official Laravel/Inertia setup. Use TSX, disable React Server Components, enable CSS variables and add components incrementally. shadcn/ui source lives in the application and is reviewed like first-party code.

## 2. Why this level is sufficient

The portal has one owner, one deployment cadence, one main database and modest concurrency. Microservices, CQRS, Kafka and Kubernetes would add operational cost without solving a current requirement.

External data sources are volatile, so adapter boundaries are valuable. They allow the portal to use HTTP JSON today and SmartMet Timeseries or another format later without changing public pages or domain records.

## 3. System context

```text
Hydromet station API / files / SmartMet Timeseries
                         |
                         v
                 Source adapters
                         |
                         v
               Laravel import jobs ---- Integration health
                         |
                         v
             PostgreSQL/PostGIS database
                         |
              +----------+-----------+
              |                      |
       Public React portal      Filament admin
              |
       Leaflet + ECharts

Hydromet MeteoAlert/CAP ------> Alert adapter ------> alerts + geometries
Hydromet SmartMet WMS --------> configured map layer (no local raster copy)
FMI SILAM page ---------------> sandboxed responsive iframe
```

## 4. Application capabilities

Organize code by capability rather than global `Controllers`, `Services` and `Repositories` folders:

```text
app/
  Domain/
    Stations/
    Measurements/
    AirQuality/
    Alerts/
    Content/
    Identity/
    Audit/
    Integrations/
  Http/
  Jobs/
  Console/
```

The internal structure may stay compact. Interfaces are introduced only for external sources and other volatile edges.

### Stations

Owns station identity, coordinates, lifecycle status, regional assignment and available parameters.

### Measurements

Owns source measurements, normalized units, revisions, aggregation queries and CSV exports. It is the only capability allowed to write measurement tables.

### AirQuality

Owns pollutant definitions, approved index schemes, breakpoints, categories and health recommendations. It consumes measurements but does not rewrite them.

### Alerts

Owns canonical CAP-compatible alerts, update/cancel resolution, validity, severity and affected geometries.

### Integrations

Owns credentials, source configuration, cursors, retry policy, payload mapping and synchronization history. It writes business data only through the owning capability's import service.

### Content

Owns news, bulletins, static pages and translated health content.

### Identity and Audit

Own users, roles, permissions, sessions and immutable records of sensitive administrative actions.

## 5. Import flow

1. Scheduler dispatches a source-specific synchronization job.
2. Adapter requests a bounded interval using UTC.
3. Raw response is validated before mapping.
4. Adapter maps source fields to canonical station/measurement DTOs.
5. Import service upserts records by source identity and natural uniqueness key.
6. Invalid rows are rejected individually and reported; they do not silently become zero.
7. Import run records counts, cursor, duration and sanitized error details.
8. The next request overlaps the previous cursor by a configurable interval so late corrections are captured.

Recommended measurement uniqueness:

```text
source_id + station_id + parameter_id + observed_at + sensor_no
```

## 6. Data ownership and persistence

- PostgreSQL is the portal's system of record for imported copies, configuration and audit.
- Hydromet remains the authoritative owner of observations and warnings.
- PostGIS stores alert polygons and optionally administrative boundaries.
- Measurement tables are partitioned by observation date only after actual volume justifies it. The first migration should not depend on TimescaleDB.
- Raw source payloads are retained selectively for troubleshooting, with secrets removed and a defined retention period.
- Uploaded documents and database backups are copied to storage outside the VPS.

## 7. Failure behaviour

| Failure | Required behaviour |
| --- | --- |
| Hydromet timeout | Retry with backoff; retain previous successful data; mark source stale |
| Partial invalid batch | Store valid rows; quarantine invalid rows; expose counts in admin |
| Duplicate batch | Idempotent upsert; no duplicate measurements |
| Late source correction | Update effective imported value and record source revision |
| CAP Update | Supersede referenced alert while retaining history |
| CAP Cancel | Stop public display and retain cancellation audit |
| SILAM unavailable | Show an unavailable message and external link |
| Queue stopped | Health endpoint fails readiness and monitoring raises an alert |

## 8. VPS topology

For the portal, database and queue only, begin with approximately 4 vCPU, 8 GB RAM and 160 GB or more SSD. Final disk sizing depends on station count, parameters, frequency and historical retention.

Containers/services:

```text
nginx
app (PHP-FPM)
queue-worker
scheduler
postgres-postgis
redis
```

SmartMet processing is not installed on this VPS under the clarified scope. If that responsibility changes, it needs a separately sized host and a revised estimate.

## 9. Security boundaries

- Public reads and administration use separate route groups and policies.
- Administrative actions require authentication, authorization and CSRF protection.
- Integration credentials exist only in server-side secrets.
- Browser code never receives Hydromet credentials.
- External HTML is sanitized; CAP descriptions are treated as untrusted text.
- The SILAM iframe uses an explicit Content Security Policy `frame-src` allowance and restrictive sandbox attributes compatible with the FMI page.
- Logs must not contain API keys, passwords or full authorization headers.
