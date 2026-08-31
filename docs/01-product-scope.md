# Product scope

## 1. Objective

Build a standalone, responsive and multilingual environmental monitoring portal for Tajikistan. The portal receives authoritative data from Hydromet, stores a normalized local copy where required, and publishes station observations, historical charts, air-quality indicators and official warnings.

The portal is a presentation, integration and administration platform. It is not the authoritative measurement system and does not operate measuring equipment.

## 2. Binding project clarifications

These decisions refine the original technical specification and must be reflected in the contract/addendum:

| Topic | Agreed implementation |
| --- | --- |
| Existing `meteo.tj` | Separate concern; not part of the portal architecture or main estimate |
| SILAM | Responsive iframe of `https://silam.fmi.fi/roux/TAJ/` with fallback link and attribution |
| Station data | Hydromet supplies registry, current observations and complete historical data |
| SmartMet | Hydromet/FMI supplies ready endpoints and parameter definitions; the portal consumes them |
| MeteoAlert | Hydromet supplies CAP, WFS or another documented warning feed |
| Hosting | Contractor supplies the application VPS for the first year |
| Data ownership | Hydromet remains the authoritative source; the portal stores normalized copies and audit history |

## 3. In scope

### 3.1 Public portal

- Tajik, Russian and English interface.
- Responsive pages for current desktop and mobile browsers.
- National overview map with station markers and status.
- Current station measurements with observation time, unit, quality and source.
- Station detail page with metadata and historical charts.
- Standard periods: 24 hours, 7 days, 30 days and 1 year.
- CSV export for the selected station, parameters and period.
- Air-quality category and colour after Hydromet approves the calculation rules.
- Active official warnings with affected areas, validity interval and severity.
- SmartMet model/WMS layers when endpoints and layer definitions are supplied.
- SILAM iframe with an external-link fallback.
- News, bulletins, health advice and static informational pages.
- Visible last-update and source-health status where data can become stale.

### 3.2 Administration

- Roles: administrator, operator and editor.
- Station registry management, unless Hydromet marks the registry read-only.
- Parameter and unit mapping.
- Import status, errors and last successful synchronization.
- Manual data entry where explicitly allowed.
- Correction workflow preserving original value, new value, reason, user and timestamp.
- Threshold/AQI configuration after formal approval.
- Management of translations, news, bulletins and health advice.
- Complete audit log for administrative and data-correction actions.

### 3.3 Integrations

- One primary adapter for station data, selected from HTTP JSON, SmartMet Timeseries, MQTT, CSV/SFTP or database export.
- Historical bulk import followed by incremental synchronization.
- SmartMet Timeseries adapter when its API is the station source.
- Optional SmartMet WMS layer adapter.
- MeteoAlert adapter for CAP 1.2, WFS GeoJSON or the exact format provided by Hydromet.
- Integration health checks and retry handling.

### 3.4 Operations

- HTTPS, secure administration, rate limiting and dependency scanning.
- Daily database and uploaded-file backups stored outside the application VPS.
- Application health endpoint and integration status.
- Structured logs for imports and failures.
- Deployment guide, backup/restore guide and administrator/operator instructions.

## 4. Explicitly out of scope

- Procurement, installation, calibration or maintenance of physical stations.
- Creation or correction of Hydromet's source data outside the portal correction workflow.
- Local SILAM NetCDF/GRIB processing, COG generation, GeoServer publication or pixel-value queries.
- Development of SmartMet Server, SmartMet Workstation or SmartMet Alert Editor.
- Guaranteeing availability or correctness of third-party/Hydromet endpoints.
- Native mobile applications.
- A public third-party API beyond the documented portal read API, unless separately approved.
- SMS, Telegram, push or email alerting until channels, recipients and message approval rules are specified.

## 5. Product rules

1. A missing measurement is `null`; it is never converted to zero.
2. Model data and measured station data must always be labelled separately.
3. All timestamps are stored in UTC and displayed in `Asia/Dushanbe`.
4. Source records are imported idempotently and may arrive late or be revised.
5. Manual corrections never destroy the original imported value.
6. Public AQI values are not calculated until Hydromet signs off the formula, averaging windows, breakpoints, rounding and health text.
7. Expired, cancelled, draft or non-public alerts are not shown as active.
8. When an upstream source fails, the portal shows the last successful data with a stale indicator instead of silently presenting it as current.

## 6. Minimum releasable product

The first production release is complete when it provides:

- a working three-language shell;
- imported station registry and history;
- scheduled current-data synchronization;
- current national map and station pages;
- historical graphs and CSV export;
- warnings from the real Hydromet feed;
- SILAM iframe;
- basic administration, roles and audit;
- production deployment, external backup and documented restore;
- all acceptance tests in `06-testing-and-acceptance.md`.

