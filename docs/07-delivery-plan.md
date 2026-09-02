# Delivery plan and estimate

## 1. Estimation basis

This estimate reflects the clarified scope:

- standalone new portal;
- Hydromet supplies station/current/history data and SmartMet/MeteoAlert endpoints;
- SILAM is iframe-only;
- no local SmartMet installation or SILAM processing;
- three public languages;
- public portal, administration, audit, production VPS, documentation and training remain required.

Implementation snapshot (2026-09-01): the mock-backed data platform, station
map/detail/charts/CSV, SILAM iframe, versioned station/content read API, initial
three-language CMS and immutable CMS audit foundation are implemented. Real
adapters, alert/AQI policy, stale thresholds, manual correction authorization,
approved content/navigation and production operations still depend on the
inputs listed in `08-hydromet-input-checklist.md`.

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

- MeteoAlert adapter and warning map — **done against a synthetic feed**: the
  canonical model, provider boundary, import, journalling, `/api/v1/alerts`,
  the map layer and the read-only admin view exist. The remaining work is one
  adapter class once Hydromet names the source type;
- Update/Cancel handling — **done**, including message history and the
  supersession rules behind `ALERT-02` and `ALERT-03`;
- SILAM iframe — **done**;
- approved AQI configuration and advice — **blocked**
  (`docs/08-hydromet-input-checklist.md`, section 4). Nothing is published.

What the fixture work does not buy: acceptance. `ALERT-01` to `ALERT-04` are
covered, not passed, until the same scenarios run against Hydromet's real
samples. The estimate for that adapter stays at the low end of the 5–8 day
Option B figure, because only the reading edge is left.

### Phase 5 — administration and hardening, weeks 7–9

- roles and audit — **done for the capabilities that exist**: the provisional
  least-privilege matrix, the append-only audit log with database-level mutation
  guards, and an administrator-only streamed CSV export of it;
- measurement correction workflow — **blocked**
  (`docs/08-hydromet-input-checklist.md`, section 3): who may correct a value,
  and the mandatory reason vocabulary, are not decided;
- content completion — **done for the local CMS**; approved navigation and
  editorial content are **blocked** (section 5);
- security tests — **done for what can be proved locally**: response headers and
  the baseline CSP, the SILAM frame permission, rate-limit headers, non-
  disclosure of exceptions and credentials in API failures, and a panel-wide
  sweep proving no administration page is reachable by a guest or a deactivated
  user. See `docs/06-testing-and-acceptance.md`, section 6.1;
- `script-src` nonce — **done** for every public response, and verified in a
  browser against the station map, the charts, the language dropdown and the
  navigation progress bar;
- `style-src` nonce — **implemented and tested, off by default**
  (`CSP_STYLE_NONCE`). It requires `style-src-attr 'unsafe-inline'` for the
  inline style attributes Leaflet sets, and that directive is CSP Level 3, so a
  browser without it would render no map. Turning it on needs a decision about
  which browsers the portal must support, and verification in each;
- CSP for the administration panel — **outstanding**, not blocked by Hydromet.
  Filament renders inline scripts with no nonce support and Alpine evaluates
  expressions with `new AsyncFunction`, so the panel runs on `'unsafe-inline'
  'unsafe-eval'`. Closing it means publishing and patching Filament's Blade
  views, or upstream nonce support;
- dependency vulnerability scanning — **outstanding**: needs a decision on the
  scanner and whether it gates a release;
- browser, load and accessibility tests — **blocked** on a deployed test
  environment;
- monitoring, backups and restore rehearsal — **procedures written**
  (`docs/09-runbooks.md`), **execution blocked** on the backup destination,
  monitoring recipients and RPO/RTO the VPS owner must supply (section 6 of the
  Hydromet input checklist).

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
| G3 Alerts/AQI | CAP lifecycle and approved AQI fixtures pass — CAP lifecycle demonstrated on a synthetic feed; the gate itself needs Hydromet's samples and an approved AQI scheme |
| G4 Release candidate | Security/load/backup tests pass; translations approved |
| G5 Production | Signed acceptance dataset, documents and training delivered |
