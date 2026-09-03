# Inputs required from Hydromet

Items marked `BLOCKING` must be supplied before their associated implementation can be accepted. Development can begin with fixtures, but estimates assume real answers arrive during Phase 0.

## 1. Station data — BLOCKING

- [ ] Station registry export with stable IDs.
- [ ] Names in Tajik, Russian and English.
- [ ] Latitude, longitude, elevation, region and station status.
- [ ] Complete parameter list per station.
- [ ] At least one current-data response/file.
- [ ] Representative historical data covering at least one month.
- [ ] Full history size and earliest timestamp.
- [ ] Data frequency for every parameter.
- [ ] Units and averaging periods.
- [ ] Missing-value encoding.
- [ ] Sensor identifiers where a station has duplicate sensors.
- [ ] Quality flag definitions.
- [ ] Rules for source revisions/corrections.
- [ ] Authentication, IP allowlisting and request limits.
- [ ] Expected update SLA and planned maintenance behaviour.

## 2. SmartMet — BLOCKING if used as a source

- [ ] Timeseries base URL.
- [ ] Authentication method/test credentials.
- [ ] Producer names.
- [ ] Station identifier convention.
- [ ] Parameter names and unit mapping.
- [ ] Quality-control query syntax and values.
- [ ] Maximum query range/result size.
- [ ] Example JSON response for current and historical queries.
- [ ] WMS GetCapabilities URL, layer names, styles and time dimension if WMS is shown.
- [ ] CORS/proxy requirements.

## 3. MeteoAlert — BLOCKING

The canonical warning model, import service, public API, map layer and admin
view are built and tested against a synthetic feed
(`docs/04-smartmet-and-alerts.md`, section 7.1). Each item below names what is
still assumed, and what the portal does in the meantime.

| Input | Currently assumed | Consequence if the assumption is wrong |
| --- | --- | --- |
| Source type | None. The provider boundary is format-agnostic; only a fixture adapter exists. | A new adapter class. No change to the model, API or UI. |
| Event code catalogue | `FIXTURE_`-prefixed placeholders. | Display mapping and icons; no schema change. |
| Severity publication rules | CAP severity ranking only; all `Actual` + `Public` messages are published. | The publication filter in `AlertMessage::scopeActiveAt()` changes. |
| Severity colours | A provisional palette in `resources/js/lib/alert-severity.ts`, labelled as not official on every screen. | One file changes. |
| Three-language text | All three required, no fallback; `instruction` all-or-none. | If Hydromet supplies fewer languages, the no-fallback rule and the NOT NULL columns both have to be revisited — this is the assumption most likely to force a migration. |
| Geometry | GeoJSON Polygon/MultiPolygon in `jsonb` plus derived bbox columns; no PostGIS column. | Promoting to PostGIS is an additive migration once SRID and boundary data are known. |
| Geocode-only areas | Stored, but not drawable and never bbox-matched. | Needs the administrative-boundary dataset before such a warning can appear on the map. |
| Refresh / stale threshold | None. Nothing is scheduled; the fixture command is manual. | Scheduler phase. |
| `raw_payload` | Column exists, never written. | Needs a sanitization rule tied to the chosen source type. |

- [ ] Source type: CAP Atom/XML, CAP files, WFS GeoJSON or custom JSON.
- [ ] Production and test endpoint/path.
- [ ] Samples for Alert, Update, Cancel and expired alert.
- [ ] Samples with multiple affected areas.
- [ ] Exact event code catalogue.
- [ ] Severity/urgency/certainty publication rules.
- [ ] Tajik, Russian and English text strategy — specifically, whether all three are always supplied.
- [ ] Polygons in feed or administrative-boundary dataset plus geocodes.
- [ ] Refresh frequency and stale threshold.
- [ ] Approved severity colour scale, or written confirmation that the portal may keep its own provisional palette.
- [ ] Sanitization rule for retaining the authoritative message (`raw_payload`).
- [ ] Confirmation whether FMI-style five-day SVG UI is mandatory or a unified Leaflet warning layer is accepted.

## 4. AQI and health advice — BLOCKING for AQI publication

- [ ] Official index scheme name and document reference.
- [ ] Pollutants included.
- [ ] Averaging window for each pollutant.
- [ ] Concentration breakpoints and units.
- [ ] Index ranges/categories/colours.
- [ ] Aggregation rule across pollutants.
- [ ] Rounding/truncation rules.
- [ ] Minimum data completeness rule.
- [ ] Approved health advice in all three languages.
- [ ] Named authority and written approval date.

## 5. Geography and content

- [ ] Official region/district codes and GeoJSON boundaries.
- [ ] Preferred basemap and attribution.
- [ ] Hydromet logo/brand assets and usage rules.
- [ ] Approved navigation and page list.
- [ ] Initial news/bulletins/static content.
- [ ] Responsible person and response time for translation approval.
- [ ] SEO titles/descriptions for all languages or an approved fallback.

## 6. Hosting and operations — BLOCKING for production

- [ ] Domain/subdomain and DNS owner.
- [ ] VPS location and required provider restrictions.
- [ ] Estimated data volume and retention.
- [ ] External backup destination.
- [ ] SMTP/notification provider if email is required.
- [ ] Monitoring recipients and incident contacts.
- [ ] Maintenance window.
- [ ] Recovery point objective and recovery time objective.
- [ ] Staleness threshold per source, in seconds, for `/api/v1/system/status`.
      The endpoint and the column exist; until a value is approved every
      source is published as `unknown`, which is deliberately not the same
      as healthy. This is a separate question from the polling interval.
- [ ] Production data access and privacy restrictions.
- [ ] User list and approved role matrix. Account administration is
      implemented against the provisional three-role model and is ready to
      receive the real one; no account exists until someone creates it, and
      the portal ships with no default administrator.
- [ ] Password policy, and how an initial password reaches a new user. The
      portal sends no e-mail, so today it is handed over out of band.
- [ ] Who may download the audit log, and where a downloaded copy may be stored.
      The export at `GET /admin/exports/audit-events.csv` is administrator-only
      and names administrators by e-mail, so the answer is a data-handling rule,
      not a code change (`docs/09-runbooks.md`, section 8).
- [ ] Retention period for the audit log itself. The table is append-only by
      database trigger and nothing prunes it; a retention rule needs an approved
      procedure before it can be implemented, not a delete statement.
- [ ] Production API rate-limit budget. The current `120/minute` is a
      development placeholder chosen without traffic figures.
- [ ] Whether a dependency vulnerability scan must gate a release, and which
      scanner is acceptable on the VPS owner's infrastructure.

## 7. Contract/addendum confirmations

- [ ] SILAM iframe is accepted as completion of the SILAM portal requirement.
- [ ] No local NetCDF/GRIB/COG/GeoServer processing is required.
- [ ] SmartMet Server and SmartAlert Editor installation/operation are outside the portal scope, or separately described and priced.
- [ ] Hydromet delivery delays shift dependent milestones.
- [ ] Acceptance is based on the fixtures and scenarios in `06-testing-and-acceptance.md`.
- [ ] Six-month support is limited to reproducible defects in accepted functionality.
- [ ] Third-party/FMI/Open Source code remains under its original licence.
- [ ] Hosting and external backup costs for the first year are explicitly allocated.

## 8. Minimum package needed to start implementation safely

Development can start without every final content item when these are present:

1. station registry sample;
2. current and historical measurement samples;
3. parameter/unit/quality mapping;
4. one MeteoAlert feed sample;
5. region boundaries or alert polygons;
6. confirmation of Laravel/Inertia React/Filament stack;
7. confirmation that SILAM is iframe-only;
8. decision to defer AQI or an approved AQI fixture.
