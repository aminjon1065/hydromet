# Application capabilities

Backend code is organized by business capability, as defined in `docs/02-architecture.md`.
Each capability owns its own models, enums, services and jobs. Only the owning capability
writes to its tables; other capabilities go through its public services.

| Capability | Ownership |
| --- | --- |
| `Stations` | Station identity, coordinates, lifecycle status, region assignment, available parameters |
| `Measurements` | Source measurements, normalized units, revisions, aggregation queries, CSV exports |
| `AirQuality` | Pollutant definitions, approved index schemes, breakpoints, categories, health advice |
| `Alerts` | Canonical CAP-compatible alerts, update/cancel resolution, validity, severity, geometries |
| `Content` | News, bulletins, static pages, translated health content |
| `Identity` | Users, roles, permissions, sessions |
| `Audit` | Immutable records of sensitive administrative actions |
| `Integrations` | Source configuration, cursors, retry policy, payload mapping, synchronization history |

Interfaces are introduced only for external or volatile edges (Hydromet, SmartMet,
MeteoAlert). Ordinary local Eloquent access does not get a repository interface.

## Implemented so far

| Capability | State |
| --- | --- |
| `Identity` | Phase 1: users, roles, panel access |
| `Stations` | Phase 2A: station registry, parameter catalogue, registry import |
| `Measurements` | Phase 2B: canonical observations, source revision history, measurement import. Public slice: bounded series aggregation and streamed CSV |
| `Integrations` | Phase 2A/2B: provider contracts with fixture adapters. Phase 2C: synchronization journal. Phase 2D: operator views. Phase 2E: reconciliation. Phase 2F: bounded incremental windows and overlap |
| `Content` | Translated drafts/publication, future scheduling, plain-text public page and `/api/v1/content/{slug}`, Filament create/edit with provisional least-privilege roles |
| `Audit` | Append-only CMS create/update events with actor and before/after values; Eloquent and database mutation guards; administrator-only Filament inspection and a streamed, language-neutral CSV export |

| `Alerts` | Phase 2G: canonical CAP-vocabulary warnings, Update/Cancel resolution, affected areas, public read model, read-only operator view |

`AirQuality` remains a placeholder so that policy-dependent phase adds code in
the agreed location.

## Warning lifecycle

A warning starts at `effective_at ?? sent_at`, defined once in
`AlertMessage::startsAt()` and used by both the SQL scope and the object method.
A message whose start has not arrived is *scheduled*, not active — treating an
absent `effective_at` as "always in force" published warnings their sender had
dated into the future.

`Alerts` stores one row per received message, never one per "warning". CAP
models a warning as a chain — an `Alert`, then `Update`s, then possibly a
`Cancel`, each with its own identifier and each referencing its predecessors.

```text
alert_messages   one row per message; nothing is ever deleted or rewritten
      |
      |  an Update or Cancel stamps superseded_by_id / superseded_at
      |  on the messages it references
      v
"in force now"   a query: Actual + Public + Alert/Update,
                 not superseded, inside the validity window
```

Keeping the chain is what lets the portal answer both "what is in force now" and
"what did we publish yesterday". Expiry needs no write at all.

`AlertMessage::scopeActiveAt()` is the single definition of "active", used by
the public map, the API and the admin filter, so those three cannot drift apart.

Geometry is GeoJSON in `jsonb` plus four derived `bbox_*` columns rather than a
PostGIS geometry column: PostGIS would mean choosing an SRID and topology for
boundary data that has not been supplied, and would split spatial behaviour
between the PostgreSQL and SQLite suites. Promoting it later is additive.

### Repeated identifiers and append-only history

A repeat that restates the stored message is `unchanged`; a repeat carrying
different content is quarantined as `identifier_conflict` and writes nothing.
`AlertMessageComparison` decides which of the two it is, normalising the things
that carry no meaning — JSON key order, the element order of the CAP sets
`categories` and `references`, the order of the affected areas and of the
geocodes inside one area, and `69` versus `69.0` in a coordinate. Order is
normalised by sorting lists, never by keying them, so two identical areas are
still not one.

Supersession is resolved from the stored message's own `message_type` and
`references`. Reading them from the incoming repeat is how a stored `Alert`
resent as a `Cancel` would withdraw warnings it never referenced.

Both tables are append-only at the Eloquent boundary and at the database
boundary. A message may never be deleted or rewritten; its one permitted change
is a supersession stamp, and both halves move together — half a stamp, a
self-supersession, and any later change to a stamp already set are all refused,
identically on PostgreSQL and SQLite. Areas are write-once outright. See
`docs/03-data-contracts.md` section 7.2.

## User accounts

`UserAccountManager` is the only writer of `users`. It normalises the name and
e-mail, checks uniqueness, hashes the password through the model's cast, applies
role and activation changes, records the audit event and moves the account's
security stamp — all inside one transaction, so a failed audit write takes the
account change and the revocation with it.

Sessions end through that stamp (`users.session_version`), not by deleting rows
from the `sessions` table: `.env.example` selects the Redis session driver,
where those rows do not exist and where finding one account's sessions would
mean scanning the keyspace. `EnforceAccountSessionVersion`, in the panel's
authenticated middleware, stamps a session on its first authenticated request
and compares it against the stored column on every one after that, so a session
opened before a deactivation, a role change or a password change is signed out
on its next request — on any session driver. Nothing is deleted from any
session store: the stale session can no longer reach anything, so it is left to
the driver's own lifetime and garbage collection.

The first administrator is the one account this service does not create for an
administrator, because on an empty installation there is none. `handle` in
`users:bootstrap-administrator` calls `bootstrapFirstAdministrator`, which
refuses the moment any account exists and is reachable from nowhere else.

That refusal needs a lock no row can provide, since the condition it protects is
that there are no rows. The transaction therefore serializes first and reads
after: a PostgreSQL transaction-scoped advisory lock on a fixed constant, or on
SQLite a no-op write that upgrades the deferred transaction to SQLite's write
lock. A driver offering neither is refused before the transaction opens rather
than run unprotected.

Filament calls into it rather than saving the model itself. Every rule is
re-checked there, because hiding a button is a courtesy and the check behind it
is the control: only an authenticated, active administrator may manage accounts,
and the resource asks the service the same question.

Two invariants keep the panel reachable: the last active administrator cannot be
deactivated or demoted, and an administrator cannot deactivate themselves or
change their own role. The first is checked before the second, so the only
administrator left is told the useful thing. The active administrator rows are
locked with `lockForUpdate` and re-counted inside the transaction, so two
concurrent demotions cannot both believe the other survives.

Three audit actions exist: `identity.user.created`, `identity.user.updated` and
`identity.user.credentials_changed`. The last is deliberately valueless — that a
password changed is evidence, what it changed to is a credential.

Accounts are deactivated, never deleted. See `docs/03-data-contracts.md`,
section 9a.

## Shared canonical utilities

`App\Support\Canonical` holds the pieces every import needs and no capability
owns: `CanonicalReader` (strict typing of an untrusted row, including the ISO
8601 rules), `RejectedRow`, `RejectionReason` and `InvalidCanonicalRow`.

They live in `Support` rather than in `Stations` so that `Measurements` does not
have to depend on another capability to parse a timestamp. They carry no
business rules, so sharing them couples nothing. One `RejectionReason`
vocabulary is shared deliberately: an operator should read the same code for the
same kind of problem, wherever it was found.

## Parameter catalogue ownership

The parameter catalogue (`docs/03-data-contracts.md`, section 4) lives in
`Stations`, because `Stations` owns "available parameters" and the registry
import is what writes it. It covers pollutant, meteorological and derived
parameters, so it is broader than `AirQuality`. `AirQuality` will own index
schemes, breakpoints and health advice, and will reference catalogue codes
rather than redefine them.

## Import direction

```text
provider payload
      |  adapter (Integrations) reads the external format
      v
canonical record (<Capability>\Data)
      |  import service (<Capability>\Services) validates and writes
      v
owned tables
```

| Adapter | Canonical record | Import service | Tables |
| --- | --- | --- | --- |
| `StationRegistryProvider` | `Stations\Data\StationRecord`, `ParameterRecord` | `Stations\Services\StationRegistryImporter` | `stations`, `parameters`, `station_parameter` |
| `MeasurementProvider` | `Measurements\Data\MeasurementRecord` | `Measurements\Services\MeasurementImporter` | `measurements`, `measurement_revisions` |
| `AlertProvider` | `Alerts\Data\AlertRecord`, `AlertAreaRecord` | `Alerts\Services\AlertImporter` | `alert_messages`, `alert_areas` |

`Integrations` never writes those tables. Each import service is the only writer
of its own, so validation and persistence rules live in one place.

`Measurements` depends on `Stations` to resolve a station by
`source` + `station_external_id` and a parameter by canonical code, and to check
the declared unit against `Parameter.canonical_unit`. It reads those models and
never writes them.

## Synchronization journal

Every import attempt is recorded, docs/03-data-contracts.md section 8.

```text
IntegrationSource              how to reach a provider; never a credential
      |
      v
SynchronizationRunner.run()    opens the run as `running`, then invokes the work
      |                        succeeded | partial | failed
      v
synchronization_runs           counters, status, safe error
synchronization_rejected_rows  one row per quarantined row, already sanitized
```

The runner is the only writer of those two tables and knows nothing about what
it is importing: callers pass a closure that performs the import and returns a
`SynchronizationOutcome`, so a new import kind needs no change to it.

Two properties it deliberately keeps:

- the run is committed as `running` **before** a provider is touched, so a
  process that dies mid-read still leaves a trace;
- the import is **not** wrapped in a transaction. Stations and measurements are
  the system of record and each row is written on its own, so a later failure
  never rolls back rows that were already accepted
  (docs/02-architecture.md, section 7).

An unexpected exception is never written down. It is not passed to `report()`
and not passed to the logger: a provider failure message routinely carries a DSN
with its password, an `Authorization` header, a slice of the raw payload or an
absolute path describing the deployment.

What is recorded instead:

| Where | What |
| --- | --- |
| `synchronization_runs` | stable `error_code`, fixed safe `sanitized_error` |
| application log | run id, source code, kind, exception class name |
| console | the same safe sentence, plus the run id |

The trade-off is deliberate and visible: the log says which run failed and how
it failed in type terms, not why. Neither the console nor `sanitized_error`
promises details that are not there.

## Operator views

Phase 2D exposes `IntegrationSource` and `SynchronizationRun` as read-only
Filament resources. They share `ReadOnlyResource`, register only `index` and
`view`, and resolve non-numeric record keys to a consistent 404 on PostgreSQL.
The run view reads rejected rows through the journal relation and displays only
their already-sanitized `reference`, `reason_code` and `safe_detail` fields.

## Reconciliation and incremental windows

`DataReconciler` captures source-scoped aggregate totals without retaining raw
payloads and compares them with an approved `ReconciliationSnapshot`. The only
expectation currently checked in is explicitly synthetic; Hydromet's signed
reference-period totals replace it for production acceptance.

`SynchronizationWindowPlanner` requires an explicit bootstrap start for the
first bounded import. Later windows start at the latest succeeded/partial
`cursor_to` minus `IntegrationSource.overlap_seconds`; failed/running attempts
cannot advance it. `MeasurementSynchronizer` verifies provider/source identity,
passes the window to the adapter and journals the exact requested bounds before
the provider is touched.

## Public measurement reads

`PublicStationOverview` selects the latest publishable reading per active
station/parameter. `PublicStationSeries` applies the four fixed public periods,
keeps 24-hour and 7-day data raw, and aggregates 30 days by hour and one year by
day. Both queries exclude invalid values and inactive parameters; missing raw
observations stay `null`. `StationExportController` applies the same station,
period, active-parameter and public-quality boundaries while streaming rows so
a full year is never materialized in PHP memory.

The same read models back `/api/v1/metadata` and the station list, detail,
series and CSV routes. API input is strictly bounded: timestamps require an
explicit ISO 8601 zone, parameters must belong to the station, raw/hour/day/
month ranges have fixed maxima, and aggregation days/months follow either UTC
or `Asia/Dushanbe` boundaries. API failures share a request-id-bearing envelope;
raw exceptions and provider details are never returned.

## Content publication and audit

`ContentItem` stores explicit `tj`, `ru` and `en` columns. Drafts may be
incomplete; a model invariant blocks publication without every title/body and a
publication time. Public queries additionally require `published_at <= now()`.
Bodies remain plain text so no untrusted HTML reaches React.

`ContentItemObserver` sends successful creates and changed business fields to
`AuditRecorder`. Audit rows are append-only at two boundaries: Eloquent throws
on update/delete, and SQLite/PostgreSQL triggers reject direct mutations. The
actor foreign key is restrictive, matching the identity rule that an account
with history is deactivated rather than deleted. Only administrators may inspect
the read-only Filament audit resource until Hydromet approves the final matrix.

Because the log cannot be corrected, evidence leaves the system by export.
`AuditEventExportRows` streams it in id order in bounded chunks, so a log of any
size exports in constant memory. Three properties are deliberate:

- the columns are stored machine values and UTC ISO 8601 timestamps, never
  translated labels, so the file does not depend on the exporting
  administrator's language;
- every cell passes through `SpreadsheetSafeText`, so a CMS title stored as
  `=HYPERLINK(...)` reaches a spreadsheet as text rather than as a formula. The
  guard is safe here because no column is a number — the measurement export
  deliberately does not use it, since a negative reading legitimately starts
  with `-`;
- taking a copy is recorded as an `audit_exported` event, and that row's id is
  the export's exclusive upper bound. The bound is an id rather than a timestamp
  because `occurred_at` is stored to the second, so an entry written in the same
  second could not otherwise be distinguished.
