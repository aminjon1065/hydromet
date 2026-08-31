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

Only `Identity` is implemented in Phase 1 (application foundation). The remaining
directories are placeholders so that later phases add code in the agreed location.
