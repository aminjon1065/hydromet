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

### 9.1 Implemented response hardening

`App\Http\Middleware\SecurityHeaders` runs as the outermost global middleware,
so it also covers an unmatched route and a response produced by the exception
handler, which never reach group middleware. It sends:

| Header | Value |
| --- | --- |
| `X-Content-Type-Options` | `nosniff` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Permitted-Cross-Domain-Policies` | `none` |
| `Content-Security-Policy` | the policy for the surface, unless the route pinned its own |

The policy is composed once, in `App\Http\Security\ContentSecurityPolicy`, from
a baseline that is safe everywhere:

```
base-uri 'self'; form-action 'self'; frame-ancestors 'self'; frame-src 'none'; object-src 'none'
```

Directives are emitted in alphabetical order, so the header does not depend on
the order a caller composed it in.

**Public pages** add a per-request nonce:

```
script-src 'self' 'nonce-<40 random characters>'; style-src 'self' 'unsafe-inline'
```

An injected `<script>` is refused unless it carries a value the attacker cannot
predict. `'self'` stays alongside the nonce because the entry module imports its
page chunks dynamically, and those are same-origin files rather than inline
scripts. `'unsafe-eval'` is absent: nothing in the public bundle evaluates
strings as code.

The nonce is minted by the middleware through `Vite::useCspNonce()` before the
response is produced, so Laravel stamps it on every script, stylesheet and
preload tag it renders, and Livewire reads the same value. Two libraries create
`<style>` elements at runtime and are told the nonce explicitly: Inertia's
progress bar, through `createInertiaApp({ nonce })`, and the scroll lock Radix
applies while a dropdown is open, through `window.__webpack_nonce__`. The value
is read back from the entry script tag's `nonce` IDL property, so it is not
exposed in a readable attribute.

**Styles** default to `'unsafe-inline'` rather than a nonce, and
`config/security.php` explains why in full. In short: a nonce cannot be attached
to an inline `style` attribute, Leaflet positions every map pane with one, and
the companion directive that fixes this — `style-src-attr 'unsafe-inline'` — is
CSP Level 3. A browser that has not implemented it ignores it, falls back to
`style-src`, and the map stops rendering. `CSP_STYLE_NONCE=true` switches the
whole portal to the nonce form; it was verified working in Chrome, and needs
verification in the audience's other browsers before release.

**The SILAM page** takes the public policy and widens exactly one directive,
`frame-src`, with the origin derived from `services.silam.url`. Deriving rather
than hard-coding means changing `SILAM_URL` cannot leave a page rendering an
iframe its own policy blocks. A URL that is not an absolute `https` origin
leaves `frame-src 'none'` in place: an unusable embed fails closed, visible as
an empty frame with its working fallback link.

**The administration panel** cannot use the nonce policy, and pins its own:

```
script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'
```

This is a real weakening and it is deliberate, not an oversight. Filament
renders inline `<script>` and `<style>` blocks from its own Blade views, which
accept no nonce, and the Alpine build Livewire ships compiles every `x-`
expression with `new AsyncFunction`. Serving the panel the public policy was
tried: the login page rendered unstyled and non-functional, with 21 `EvalError`
exceptions from Livewire's evaluator. The concession therefore applies only to
authenticated panel routes, while the public portal — the surface an anonymous
visitor can reach — keeps the nonce. Removing it means either publishing and
patching Filament's Blade views or waiting for upstream nonce support; it is
recorded as an open item in `docs/07-delivery-plan.md`.

`docker/nginx/default.conf` sends the same static headers at the edge, from
`docker/nginx/snippets/security-headers.conf`. Two details matter and are
enforced there:

- nginx inherits `add_header` from an outer level only while the inner level
  declares none of its own, so every location that sets its own header repeats
  the snippet. Without that, `location /build/` would serve the built assets
  with no `nosniff`.
- nginx appends rather than replaces, and a repeated `X-Content-Type-Options` is
  read as one invalid value, which disables the protection. The PHP location
  therefore hides the application's copies with `fastcgi_hide_header`, leaving
  the edge as the single source in this topology while the application keeps the
  guarantee for any other front end and makes it provable in the test suite.

### 9.2 Failure disclosure

Every `/api/*` failure is rendered by `App\Http\Api\ApiErrorRenderer` into the
fixed envelope. The exception class, message, file, line and stack trace never
appear in a response, including when `APP_DEBUG` is on; `X-Request-Id` is the
only diagnostic channel and correlates the safe response with the server log.
Only `Allow`, `Retry-After`, `WWW-Authenticate` and `X-RateLimit-*` survive from
a failing response; everything else it carried, including cookies and upstream
headers, is dropped.

### 9.3 Audit export

`GET /admin/exports/audit-events.csv` streams the immutable audit log for an
active administrator, and is registered inside the panel's authenticated route
group so it inherits the panel session, CSRF and authentication middleware.

- Columns are stored machine values, never translated labels, and timestamps are
  UTC ISO 8601, so the file is byte-identical whichever language the exporting
  administrator is working in.
- Every cell passes through `App\Support\Csv\SpreadsheetSafeText`, which prefixes
  a value beginning with `=`, `+`, `-`, `@`, tab or a carriage return with an
  apostrophe and strips C0 control characters. A CMS title stored as
  `=HYPERLINK(...)` therefore reaches the spreadsheet as text. The guard is
  correct here because no column is a number; the measurement export
  deliberately does not use it, since a negative reading legitimately starts
  with `-`.
- Taking a copy is itself recorded as an `audit_exported` event, and that entry
  is the export's exclusive upper bound. The bound is a row id rather than a
  timestamp because `occurred_at` is stored to the second, so an entry written
  in the same second would otherwise be indistinguishable.
