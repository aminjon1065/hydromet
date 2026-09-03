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

Two public surfaces read it, and both go through the same query
(`PublicAlertOverview`), so they cannot disagree about what is public. The
overview at `/` lists what is in force and draws the areas. `GET
/alerts/{source}/{identifier}` is one warning in full, at an address that can be
shared — the pair, because a CAP identifier is unique within its sender rather
than globally. That page deliberately serves a warning that is **no longer** in
force: a link followed an hour later has to say that the warning expired or was
superseded, and show the rest of the message chain, rather than answer `404` as
though nothing had ever been issued. A message that is not `Actual` + `Public`
is reported as missing on both surfaces, so neither can be used to learn what
the other hides.

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

### 9.1a Account administration

Accounts are created and changed only through
`App\Domain\Identity\Services\UserAccountManager`. The Filament pages hand it
the submitted fields and do nothing else, so normalisation, uniqueness,
hashing, the audit write and every rule live in one place a second caller can
find.

Access: authenticated, active, `administrator`. The panel asks the service the
same question it uses to authorize a write, so hiding a menu entry and refusing
a request cannot drift apart — an operator or editor gets no navigation entry
*and* a refusal at the URL.

Two invariants protect the panel from locking everyone out:

| Rule | Why |
| --- | --- |
| The last active administrator cannot be deactivated or demoted | It is the only remaining way in |
| An administrator cannot deactivate themselves or change their own role | They would lose access mid-session; a colleague can do it |

The last-administrator rule is evaluated first, so someone who is the only
administrator is told that rather than being sent to look for a colleague who
does not exist. It is transaction-safe: the active administrator rows are locked
with `lockForUpdate` and re-counted inside the same transaction, so two
concurrent demotions cannot each observe the other as the survivor. SQLite
ignores the lock and relies on its single writer, so the rule holds on both.

Deactivation, a role change and a password change all end the account's existing
sessions, so a change of rights applies to the next request rather than to the
next sign-in.

They do it through a security stamp on the account (`users.session_version`),
not by deleting rows from the `sessions` table. That table is only where
sessions live on one driver; `.env.example` selects Redis, where the rows do not
exist and where finding one account's sessions would mean scanning the keyspace
of shared infrastructure. So the account carries a version instead:
`App\Http\Middleware\EnforceAccountSessionVersion`, registered in the panel's
authenticated middleware after `Authenticate`, stamps a session on its first
authenticated request and compares the stamp against the stored column on every
request after that. A session opened before the change carries the older number
and is signed out — `logoutCurrentDevice`, the session invalidated, the CSRF
token regenerated, and the person sent to `/admin/login` as unauthenticated.
The stamp moves inside the same transaction as the change that requires it, so a
rolled-back change does not sign anybody out; a rename or a corrected address
does not move it.

Nothing about the session store is assumed, so the behaviour is identical on
Redis, the database, files and the array driver. **Nothing is deleted from any
session store**, on any driver: no store is searched, scanned or flushed to find
a session, and no `sessions` row is removed. The stale session can no longer be
used to reach anything, so it is left to the driver's own lifetime and garbage
collection. A cleanup write would also have to happen after the change had
already committed, on a store this transaction does not own — so its only
possible effect is an error shown for work that was in fact done. Session
contents are never read or recorded, and the session stamp itself holds only the
account id and the version — nothing that could authenticate as anybody.

Filament's `AuthenticateSession` stays in the panel stack as the second line for
a changed password hash. It cannot see a deactivation or a role change, which is
what the stamp covers.

**The first administrator** is created by `php artisan
users:bootstrap-administrator`, the one path that writes a user without an
administrator asking for it — because on an empty installation there is nobody
to ask. It refuses the moment `users` holds a single row, prompts for the
password hidden rather than accepting it as an option, applies the same password
policy as the panel form, and writes the account and its `identity.user.created`
event in one transaction with `actor_id` null. `UserAccountManager::create` is
unchanged and still requires an active administrator; nothing routes to the
bootstrap path, so it is unreachable over HTTP and from Filament.

The "no account exists" condition is the one invariant in the portal that cannot
be held by locking rows, because the point of it is that there are none. Two
simultaneous runs would both read an empty table, and both would be right at the
moment they read it. So the transaction serializes before it reads:

| Driver | Lock | Why that one |
| --- | --- | --- |
| PostgreSQL | `pg_advisory_xact_lock` on a fixed constant | Not attached to a row, and released by the server on commit or rollback, so a crashed run cannot block the command forever |
| SQLite | `pragma user_version` written back as itself | No advisory locks exist, and a deferred transaction holds nothing until it writes; a real write takes SQLite's single write lock, and writing the value back unchanged makes it a no-op |
| Anything else | Refused | Running the one operation whose safety is the lock, without the lock, would look correct until the day two people ran it together |

The identifier is a written-out constant, never derived. A number computed from
a hash of a class name, a table name or an application key can change with a PHP
build or a rename, and two processes then wait politely on two different locks.
The existence check is made after the lock, so the second process asks its
question once the first has committed and is refused.

Accounts are never deleted, at three boundaries: no delete ability or route in
Filament, a `LogicException` from the model, and a trigger in
`2026_09_02_120013_add_user_account_guards` (plus a `TRUNCATE` guard and a role
`CHECK` on PostgreSQL). The restrictive `audit_events.actor_id` foreign key
remains behind all of it. Deactivation is the supported way to remove someone,
and it keeps their audit history attributable.

Nothing credential-shaped is readable through the panel or the audit log: no
password, hash, confirmation, remember token, session payload or reset token.

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
