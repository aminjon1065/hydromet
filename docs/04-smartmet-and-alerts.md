# SmartMet and MeteoAlert analysis

## 1. Sources reviewed

- [fmidev/smartmet-alert-client](https://github.com/fmidev/smartmet-alert-client), release `4.7.2`, commit `7beff70f9de3e510fe2628d455ec6ae2e507a70b` reviewed on 2026-08-31.
- [SmartMet Timeseries examples](https://github.com/fmidev/smartmet-plugin-timeseries/blob/master/docs/Examples.md).
- [SmartMet Timeseries feature inventory](https://github.com/fmidev/smartmet-plugin-timeseries/blob/master/FEATURES.md).
- [SmartMet international deployment documentation](https://github.com/fmidev/smartmet-international-documentation).
- [smartalert-web](https://github.com/fmidev/smartalert-web).
- [OASIS CAP 1.2](https://docs.oasis-open.org/emergency/cap/v1.2/CAP-v1.2-os.html).

## 2. What `smartmet-alert-client` actually is

It is a Vue 3/Vite/TypeScript visualizer for weather and flood warnings. It is published as both a web component and a Vue component under the MIT licence.

It is not:

- a data acquisition service;
- a station observation portal;
- a CAP editor;
- a generic Leaflet/MapLibre layer;
- an air-quality/AQI component;
- a ready Tajikistan warning client.

Its UI is a five-day regional SVG warning map with warning filters, severity colours, popups and a region list.

### Repository health check

The checked-out release passed lint and TypeScript validation. Its non-watch test run passed all 824 tests in 26 test files, including unit, integration and SVG snapshot coverage.

Dependency audit still matters before reuse. On 2026-08-31, `npm audit --omit=dev` **in the upstream `smartmet-alert-client` checkout** reported three production-tree advisories: one moderate direct advisory for the locked DOMPurify version and two high transitive advisories involving Nano ID and PostCSS. Fixes were reported as available. The complete development tree reported additional advisories. A fork must update and re-lock dependencies, rerun the full test suite and preserve an audit report before deployment.

That finding is about the upstream repository and nothing else. It is **not** superseded by this portal's own dependency audits, and the portal's clean results say nothing about it: the two are separate trees with separate lock files, and `smartmet-alert-client` is not a dependency of this portal — it is a reference implementation (`CLAUDE.md`, product decisions). The portal's audits are described in `docs/06-testing-and-acceptance.md`, section 6.2, and cover `composer.lock` and `package-lock.json` in this repository only. Should any upstream code ever be reused here, it arrives with its own dependencies and must be audited on the way in.

The repository's `npm run validate` ends with `npm test`, which starts Vitest in watch mode. CI or release verification should use `npm run test:run` explicitly.

## 3. Runtime inputs

The component accepts, among others:

| Prop | Default | Meaning |
| --- | --- | --- |
| `baseUrl` | FMI GeoServer URL | Base WFS endpoint |
| `language` | `fi` | Only `fi`, `sv`, `en` are typed and translated |
| `geometryId` | `2021` | Selects an embedded geometry set |
| `refreshInterval` | `900000` | Refresh every 15 minutes |
| `selectedDay` | `0` | Selected day from `0..4` |
| `warnings` | `null` | Preloaded WFS-shaped data; skips network fetching |
| `weatherWarnings` | generated WFS query | Custom query suffix |
| `floodWarnings` | generated WFS query | Custom query suffix |
| `weatherUpdated` | generated WFS query | Update-time query suffix |
| `floodUpdated` | generated WFS query | Update-time query suffix |
| `dailyWarningTypes` | empty | Warning types treated as whole-day warnings |

The default client performs four WFS 1.0 `GetFeature` requests with `outputFormat=application/json` and `maxFeatures=1000`.

Default type names are hard-coded:

```text
weather_update_time
flood_update_time
weather_finland_active_all
flood_finland_active_all
```

## 4. Exact WFS-shaped payload expected by the client

Top-level object:

```json
{
  "weather_update_time": { "type": "FeatureCollection", "features": [] },
  "flood_update_time": { "type": "FeatureCollection", "features": [] },
  "weather_finland_active_all": { "type": "FeatureCollection", "features": [] },
  "flood_finland_active_all": { "type": "FeatureCollection", "features": [] }
}
```

Update-time feature:

```json
{
  "type": "Feature",
  "geometry": null,
  "properties": {
    "update_time": "2026-08-31T06:00:00Z"
  }
}
```

Weather-warning properties used by the implementation:

| Property | Required in practice | Notes |
| --- | --- | --- |
| `identifier` | yes | Unique warning/area ID |
| `reference` | yes unless geometry resolves coverage | Region ID is extracted after `#` |
| `warning_context` | yes | Hyphenated event type such as `wind` or `rain` |
| `context_extension` | no | Extends event type, for example high/low water |
| `severity` | yes | `level-1` through `level-4` |
| `effective_from` | yes | ISO time |
| `effective_until` | yes | ISO time |
| `physical_direction` | conditional | Wind direction |
| `physical_value` | conditional | Wind speed or another physical value |
| `info_fi` | optional | Finnish description |
| `info_sv` | optional | Swedish description |
| `info_en` | optional | English description |
| `coverage_references` | optional | Special reference list with optional coverage percentage |
| `representative_x/y` | optional | Marker reference coordinates |

Flood-warning properties use a different legacy shape:

```text
identifier, reference, severity, onset, expires,
description, language, representative_x, representative_y
```

The flood `description` is expected as a percent-encoded JSON array, which is an implementation-specific legacy detail and should not become our canonical API.

## 5. Hard-coded Finland assumptions

The reviewed release contains:

- `Europe/Helsinki` timezone and `fi-FI` formatting locale;
- fixed `NUMBER_OF_DAYS = 5`;
- a static list of Finnish counties, municipalities and sea regions;
- embedded Finland SVG paths in `src/data/geometries.json`;
- only Finnish, Swedish and English locale files;
- Finnish WFS type names;
- warning types including sea ice, sea level and Finnish traffic conditions;
- a special region-reference parser tailored to FMI identifiers;
- a 12-hour source update-delay threshold;
- filtering that normally excludes `level-1` weather warnings.

Changing only `baseUrl` is therefore insufficient.

## 6. Adaptation options

### Option A — fork `smartmet-alert-client`

Required work:

1. Add application languages `tj`, `ru`, `en`, mapping `tj` to standards-based `tg-TJ` where an external protocol requires it.
2. Replace timezone and date formatting with `Asia/Dushanbe`.
3. Produce Tajikistan region geometry in the repository's SVG-path format.
4. Define region hierarchy, centres, weights and ordering.
5. Replace WFS type names and language mapping.
6. Map Tajik warning event codes and icons.
7. Decide whether minor/level-1 warnings are public.
8. Remove or isolate Finland-specific sea/flood rules.
9. Replace `info_fi/info_sv/info_en` semantics or provide an adapter.
10. Add contract fixtures, unit tests and visual snapshots for Tajikistan.
11. Preserve the FMI MIT copyright/licence notice.

Estimated implementation: 8–12 development days after final regions, warning codes and sample payloads are available.

### Option B — own warning layer in the portal (recommended)

Parse CAP/WFS on the Laravel side into the canonical model and render affected polygons in the same Leaflet map used for stations.

Advantages:

- one map and interaction model;
- native Tajik/Russian/English support;
- no conversion to FMI's internal SVG geometry format;
- direct handling of CAP Update/Cancel/reference semantics;
- simpler use of actual polygons supplied by Hydromet;
- no dependency on Finnish region IDs or legacy flood encoding.

Estimated implementation: 5–8 development days after a real feed and boundary data are supplied.

### Option C — configure `smartalert-web`

This FMI example is designed around CAP files, Leaflet, configurable bounds, event types, languages, timezone, WMS and icons. It is faster when Hydromet's SmartAlert Editor produces the directory/feed layout expected by `capfeed.php`.

Estimated implementation: 2–4 days for a compatible ready CAP directory, plus design integration.

## 7. Decision

Start with Option B. Keep `smartmet-alert-client` as a verified reference implementation for severity display, five-day filtering, warning icons and accessibility patterns.

If the customer explicitly requires the FMI five-column national warning map, add Option A as a separately accepted work package. Do not let the Finnish WFS response shape become the portal's database contract.

### 7.1 Implemented state

Option B is implemented against a synthetic fixture feed. `smartmet-alert-client`
was used as a reference only: no FMI code, markup, geometry format or region
model was copied, and nothing was forked.

What exists:

| Piece | Where |
| --- | --- |
| Canonical warning model | `alert_messages`, `alert_areas` (`docs/03-data-contracts.md`, section 7) |
| Provider boundary | `App\Domain\Integrations\Contracts\AlertProvider` |
| Fixture adapter | `App\Domain\Integrations\Fixtures\FixtureAlertProvider`, two scenarios |
| Import service | `App\Domain\Alerts\Services\AlertImporter`, the only writer of those tables |
| Journalling | the existing `SynchronizationRunner`, kind `alerts` |
| Public read | `App\Domain\Alerts\Queries\PublicAlertOverview`, `/api/v1/alerts` |
| Public UI | warning polygons on the existing Leaflet station map, plus an accessible warning list and a legend |
| Administration | read-only `AlertMessageResource` |

What a real adapter has to do, and nothing else: read Hydromet's chosen format
and return `AlertRecord`s. The public portal, API and database contract do not
change with it. That is the whole reason the fixture is written in the canonical
shape rather than in an invented CAP or WFS document — inventing a wire format
would be inventing Hydromet's source.

Still blocked (section 3 of `docs/08-hydromet-input-checklist.md`): the source
type and endpoint, the event-code catalogue, severity/urgency/certainty
publication rules, the three-language text strategy, polygons or an
administrative-boundary dataset, and the refresh/stale thresholds. Until those
arrive the portal publishes `FIXTURE_`-prefixed event codes, a provisional and
explicitly-labelled severity palette, and no health advice at all.

## 8. SmartMet Timeseries integration

SmartMet's Timeseries plugin is a query API for observations and forecasts. A typical JSON observation request uses:

```text
/timeseries
  ?producer=<hydromet-producer>
  &format=json
  &tz=UTC
  &timestep=data
  &starttime=<ISO-UTC>
  &endtime=<ISO-UTC>
  &param=utctime,fmisid,stationname,stationlat,stationlon,<parameter>,<parameter-qc>
```

Hydromet/FMI must supply:

- base URL and authentication method;
- producer names;
- station identifier to use (`fmisid`, `wmo` or another field);
- canonical mapping for every SmartMet parameter name;
- source unit for every parameter;
- how sensor numbers are encoded;
- quality-control field syntax and values;
- maximum supported date window and result size;
- whether history can be queried directly or arrives as a separate dump;
- request limits and expected refresh frequency.

The portal adapter must always request machine-readable JSON and UTC. It must bound history queries by station and time range instead of requesting the entire archive in one call.

## 9. SmartMet WMS integration

If model layers are required, Hydromet must provide:

```text
WMS base URL
GetCapabilities URL
layer names
styles
supported CRS
time dimension syntax
forecast run/origin-time selection
units and legend URLs
authentication and CORS rules
```

The portal does not persist WMS rasters. It displays them as model layers and clearly distinguishes them from observations.

## 10. MeteoAlert source preference

Preferred order:

1. CAP 1.2 Atom/XML with polygons or geocodes.
2. WFS GeoJSON with complete fields and geometries.
3. Static CAP files produced by SmartAlert Editor.
4. A custom JSON feed with a documented mapping.

Whichever source is chosen, Hydromet must provide real examples for `Alert`, `Update` and `Cancel`, at least two languages, multiple areas and an expired alert.
