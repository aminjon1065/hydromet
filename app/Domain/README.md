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
| `Measurements` | Phase 2B: canonical observations, source revision history, measurement import |
| `Integrations` | Phase 2A/2B: `StationRegistryProvider` and `MeasurementProvider` contracts with fixture adapters. Phase 2C: source configuration and the synchronization run journal |

The remaining directories are placeholders so that later phases add code in the
agreed location.

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
