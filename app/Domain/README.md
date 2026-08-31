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
| `Integrations` | Phase 2A: `StationRegistryProvider` contract and a fixture adapter |

The remaining directories are placeholders so that later phases add code in the
agreed location.

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
canonical record (Stations\Data)
      |  import service (Stations\Services) validates and writes
      v
stations / parameters / station_parameter
```

`Integrations` never writes those tables. The import service is their only
writer, so validation and persistence rules live in one place.
