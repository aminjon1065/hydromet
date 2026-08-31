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
| `Integrations` | Phase 2A/2B: `StationRegistryProvider` and `MeasurementProvider` contracts with fixture adapters |

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
