# Delivery plan and estimate

## 1. Estimation basis

This estimate reflects the clarified scope:

- standalone new portal;
- Hydromet supplies station/current/history data and SmartMet/MeteoAlert endpoints;
- SILAM is iframe-only;
- no local SmartMet installation or SILAM processing;
- three public languages;
- public portal, administration, audit, production VPS, documentation and training remain required.

## 2. Work estimate

| Work package | Development days |
| --- | ---: |
| Final data contracts and UX flow | 3–5 |
| Repository/bootstrap, CI and deployment baseline | 3–4 |
| Station/parameter schema and registry import | 4–6 |
| Historical import and reconciliation | 4–7 |
| Incremental sync, retries and integration health | 4–6 |
| Public station map and current overview | 5–7 |
| Station details, charts and CSV | 5–7 |
| AQI calculation after approval | 3–5 |
| MeteoAlert/CAP adapter and Leaflet layer | 5–8 |
| SILAM iframe | 1 |
| Filament admin, roles, correction and audit | 6–8 |
| CMS and three-language completion | 4–6 |
| Security, performance, QA and accessibility | 5–8 |
| VPS, backups, documentation and training | 3–5 |

Several packages overlap. Expected total is approximately 45–60 focused development days, excluding customer waiting time.

## 3. Calendar scenarios

### Core MVP

Includes registry/history import, scheduled current data, public map, station charts/CSV, warnings, SILAM and basic administration.

- One experienced full-stack developer: 6–8 calendar weeks.
- Two developers with part-time QA: 4–6 calendar weeks.

### Full acceptance scope

Adds signed AQI, complete QC/correction audit, CMS, all translations, security/performance evidence, operations documents and training.

- One experienced full-stack developer: 9–12 calendar weeks.
- Two developers plus part-time QA/translation: 7–9 calendar weeks.

Add 1–3 weeks if historical data is inconsistent, source endpoints change, administrative boundaries are missing, or translations/approval arrive late.

Forking `smartmet-alert-client` instead of the recommended Leaflet alert layer adds approximately 3–6 net days and introduces a long-term fork to maintain.

## 4. Suggested sequence

### Phase 0 — contract freeze, 3–5 days

- receive source samples;
- agree canonical field mapping;
- approve alert rendering option;
- approve national AQI rules or defer AQI behind a feature flag;
- approve page map and UI wireframes;
- fix objective acceptance fixtures.

### Phase 1 — foundation, week 1

- scaffold Laravel/Inertia React/Filament project;
- Docker Compose development environment;
- authentication and role skeleton;
- database baseline;
- CI checks and first VPS deployment.

### Phase 2 — data platform, weeks 2–3

- registry and parameter catalogue;
- historical importer;
- incremental adapter;
- import run/rejection UI;
- reconciliation report and fixtures.

### Phase 3 — public portal, weeks 3–5

- multilingual shell;
- station map/current values;
- station detail, charts and CSV;
- stale and quality states;
- initial CMS pages.

### Phase 4 — alerts and AQI, weeks 5–7

- MeteoAlert adapter and warning map;
- Update/Cancel handling;
- SILAM iframe;
- approved AQI configuration and advice.

### Phase 5 — administration and hardening, weeks 7–9

- roles, correction workflow and audit;
- content completion;
- security, browser, load and accessibility tests;
- monitoring, backups and restore rehearsal.

### Phase 6 — acceptance, documentation and training, weeks 9–10+

- production data reconciliation;
- fixes against objective acceptance scenarios;
- runbooks and operator/admin guides;
- training and production handover.

## 5. Commercial interpretation

The contract price becomes plausible for this clarified integration-focused scope if:

- Hydromet delivers ready data and endpoints on time;
- SmartMet/SmartAlert installation and data production are not the portal developer's responsibility;
- SILAM is accepted as an iframe;
- external VPS and backup costs are budgeted explicitly;
- post-acceptance support covers defect correction, not new integrations or content work.

If any of those assumptions changes, use a written change request with added time and price.

## 6. Delivery gates

| Gate | Evidence |
| --- | --- |
| G0 Scope ready | Blocking checklist answered; sample payloads archived |
| G1 Data foundation | Registry/history reconciliation passes |
| G2 Public MVP | Map, station detail, chart and CSV pass browser tests |
| G3 Alerts/AQI | CAP lifecycle and approved AQI fixtures pass |
| G4 Release candidate | Security/load/backup tests pass; translations approved |
| G5 Production | Signed acceptance dataset, documents and training delivered |
