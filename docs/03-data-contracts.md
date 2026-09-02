# Canonical data contracts

## 1. Purpose

Hydromet's actual payloads may use different field names and formats. Source adapters must convert them into these canonical contracts. Public pages and business rules depend only on the canonical model, never directly on a provider payload.

All examples are contract proposals until Hydromet supplies real samples.

## 2. Shared conventions

| Rule | Value |
| --- | --- |
| Timestamp exchange | ISO 8601 with offset, preferably UTC `Z` |
| Internal timestamp storage | UTC |
| Public timezone | `Asia/Dushanbe` |
| Coordinates | WGS84, longitude/latitude, EPSG:4326 |
| Missing value | JSON `null`, never `0`, empty string or `-9999` |
| Decimal separator | Dot |
| Text encoding | UTF-8 |
| Application language codes | `tj`, `ru`, `en` |
| Standards mapping | Internal `tj` maps to ISO/BCP 47 `tg` or `tg-TJ` only for HTML/CAP/external APIs |
| IDs | Stable strings; display names are never used as identifiers |
| Units | Explicit UCUM-like code or a documented source-to-canonical mapping |

## 3. Station registry

### 3.1 Required canonical fields

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `source` | string | yes | Provider key, for example `hydromet` or `smartmet` |
| `external_id` | string | yes | Immutable ID in Hydromet's system |
| `code` | string | yes | Human-readable station code; unique within source |
| `name.tj` | string | yes | Tajik name |
| `name.ru` | string | yes | Russian name |
| `name.en` | string | yes | English name |
| `latitude` | number | yes | `-90..90` |
| `longitude` | number | yes | `-180..180` |
| `elevation_m` | number/null | no | Metres above sea level |
| `region_code` | string | yes | Stable administrative region code |
| `district_code` | string/null | no | Stable district code |
| `timezone` | string | yes | Normally `Asia/Dushanbe` |
| `status` | enum | yes | `active`, `maintenance`, `offline`, `decommissioned` |
| `station_type` | enum/string | yes | For example `air_quality`, `meteorological`, `combined` |
| `owner` | string/null | no | Operating institution |
| `installed_at` | date/null | no | Commissioning date |
| `parameters` | string[] | yes | Canonical parameter codes available at the station |
| `updated_at` | datetime | yes | Source record update time |

### 3.2 Example

```json
{
  "source": "hydromet",
  "external_id": "station-00017",
  "code": "DUS-017",
  "name": {
    "tj": "Душанбе — марказ",
    "ru": "Душанбе — центр",
    "en": "Dushanbe — Centre"
  },
  "latitude": 38.5737,
  "longitude": 68.7738,
  "elevation_m": 807.0,
  "region_code": "DUSHANBE",
  "district_code": null,
  "timezone": "Asia/Dushanbe",
  "status": "active",
  "station_type": "air_quality",
  "owner": "Hydromet Tajikistan",
  "installed_at": "2025-01-20",
  "parameters": ["PM25", "PM10", "NO2", "SO2", "CO", "O3"],
  "updated_at": "2026-08-31T06:00:00Z"
}
```

## 4. Parameter catalogue

The database must not assume a unit from the parameter name. Units and averaging periods are explicit.

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `code` | string | yes | Canonical stable code |
| `kind` | enum | yes | `pollutant`, `meteorological`, `derived` |
| `name` | localized object | yes | `tj`, `ru`, `en` |
| `canonical_unit` | string | yes | For example `ug/m3`, `mg/m3`, `degC`, `%` |
| `precision` | integer | yes | Public decimal places |
| `default_averaging_period` | duration/null | no | ISO 8601 duration such as `PT1H` |
| `plausible_min` | number/null | no | QC aid, not a legal threshold |
| `plausible_max` | number/null | no | QC aid, not a legal threshold |
| `active` | boolean | yes | Whether displayed publicly |

Initial pollutant codes:

```text
PM25, PM10, NO2, SO2, CO, O3
```

Optional meteorological codes are added only if supplied, for example `TA`, `RH`, `WS`, `WD`, `P` and `PRECIP`.

## 5. Measurements

### 5.1 Required canonical fields

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `source` | string | yes | Source configuration key |
| `source_measurement_id` | string/null | no | Preferred if provider has immutable IDs |
| `station_external_id` | string | yes | Maps to the station source ID |
| `parameter_code` | string | yes | Maps through the parameter catalogue |
| `sensor_no` | string/null | no | Required when multiple sensors measure the same parameter |
| `observed_at` | datetime | yes | Actual observation time |
| `received_at` | datetime/null | no | When Hydromet received it |
| `value` | number/null | yes | `null` means missing |
| `unit` | string | yes | Source unit, mapped to canonical unit |
| `averaging_period` | duration/null | no | For example `PT10M`, `PT1H`, `P1D` |
| `quality` | enum | yes | `valid`, `suspect`, `invalid`, `missing`, `corrected` |
| `quality_flags` | string[] | yes | Source and portal QC flags |
| `revision` | integer | yes | Starts at `1`; increases on source revision |
| `is_manual` | boolean | yes | Distinguishes manual entry |
| `source_updated_at` | datetime/null | no | Provider revision timestamp |

### 5.2 Batched example

```json
{
  "source": "hydromet",
  "generated_at": "2026-08-31T06:05:00Z",
  "measurements": [
    {
      "source_measurement_id": "DUS-017-PM25-20260831T060000Z",
      "station_external_id": "station-00017",
      "parameter_code": "PM25",
      "sensor_no": "1",
      "observed_at": "2026-08-31T06:00:00Z",
      "received_at": "2026-08-31T06:02:11Z",
      "value": 23.4,
      "unit": "ug/m3",
      "averaging_period": "PT1H",
      "quality": "valid",
      "quality_flags": [],
      "revision": 1,
      "is_manual": false,
      "source_updated_at": "2026-08-31T06:02:11Z"
    }
  ]
}
```

### 5.3 Corrections

The imported record keeps the original value. A separate revision record contains:

| Field | Type |
| --- | --- |
| `measurement_id` | UUID/bigint |
| `previous_value` | decimal/null |
| `corrected_value` | decimal/null |
| `previous_quality` | enum |
| `corrected_quality` | enum |
| `reason_code` | string |
| `reason_text` | string |
| `changed_by` | user ID |
| `changed_at` | datetime |

Public queries return the effective value and expose a `corrected` flag. Administrative views can inspect the full revision history.

## 6. Air-quality index configuration

No AQI formula is hard-coded until formal approval. A versioned configuration supports national and optional comparison schemes.

### 6.1 Scheme

| Field | Type | Example |
| --- | --- | --- |
| `code` | string | `TJ_NATIONAL` |
| `version` | string | `2026-01` |
| `name` | localized object | National air-quality index |
| `valid_from` | date | `2026-01-01` |
| `valid_until` | date/null | `null` |
| `aggregation_rule` | enum | `maximum_subindex` |
| `approved_by` | string | Authority/document reference |
| `approved_at` | datetime | Approval time |

### 6.2 Breakpoint

| Field | Type |
| --- | --- |
| `scheme_code` | string |
| `parameter_code` | string |
| `averaging_period` | duration |
| `concentration_low` | decimal |
| `concentration_high` | decimal |
| `index_low` | integer |
| `index_high` | integer |
| `category_code` | string |
| `color` | CSS hex |
| `advice.tj/ru/en` | localized text |
| `rounding_rule` | string |

Every published index stores the scheme/version and input observation IDs so it can be reproduced after rules change.

## 7. Canonical warning contract

The internal warning model follows CAP 1.2 semantics even when the source is WFS GeoJSON.

Implementation status: implemented against synthetic fixtures as
`alert_messages` + `alert_areas`, written only by
`App\Domain\Alerts\Services\AlertImporter`. Deviations from the table below,
each deliberate and each tied to a blocked input:

| Field | Status |
| --- | --- |
| `raw_payload` | Column created, never written. "Sanitized authoritative message" has no sanitization rule until the source type is known, and storing an unsanitized upstream document would breach the untrusted-input rule. |
| `parameters` | Stored as a flat string-to-string map. The portal carries provider values verbatim and never parses them into numbers it would have to justify. |
| `category` | Stored as `categories` (JSON array). |
| geometry | Stored as GeoJSON in `jsonb` plus four derived `bbox_*` columns, not as a PostGIS geometry column. Committing to PostGIS would mean choosing an SRID and topology for boundary data that has not been supplied, and would split spatial behaviour between the PostgreSQL and SQLite suites. Promoting it later is an additive migration. |
| geocode-only areas | Accepted and stored, but not drawable and never matched by a `bbox` filter, until Hydromet supplies the administrative boundary dataset. |

Lifecycle resolution is stored, not recomputed: an `Update` or `Cancel` stamps
`superseded_by_id` / `superseded_at` on the messages it references. Nothing is
deleted, so "what is in force now" is a query and the published history stays
reconstructable. Expiry needs no write at all.

Publication is deliberately narrow: only `Actual` + `Public` + `Alert`/`Update`,
not superseded, inside the validity window. `Test`, `Exercise`, `Draft`,
`System`, `Restricted` and `Private` messages are stored so an operator can see
that they arrived, and are excluded from every public read.

The validity window starts at `effective_at ?? sent_at`. CAP makes `effective`
optional and says a message takes effect when it was sent if it is absent — not
that it has always been in force — so a message with no `effective_at` and a
`sent_at` in the future is **scheduled**, not active. That rule has one
definition (`AlertMessage::startsAt()`), used by the SQL scope, the object
method, the public API, the map and the panel, and a test asserts the scope and
the method agree at every boundary on both drivers. The panel shows a message
that has not started as `scheduled`, never as in force.

### 7.1 Repeated identifiers

`source` + `identifier` is the published identity of a warning, and a stored
message is authoritative. CAP corrects a warning by sending a new message with a
new identifier, never by resending an old one with different content, so:

- a repeat that restates the stored message is `unchanged`, and may still finish
  a supersession an earlier run left half-done;
- a repeat carrying different content is a provider or feed fault. It is
  quarantined as `identifier_conflict` and **nothing** is written: not the
  message, not its areas, and not the supersession of any other message.

The comparison is semantic. Normalised, because none of them is a content
change: JSON object key order; the element order of `categories` and
`references` (CAP sets, not sequences); the order of a message's affected areas
and of the geocodes inside one area; and the difference between `69` and `69.0`
in a coordinate. Normalising order does not collapse duplicates — the lists are
sorted, never keyed — so a feed that drops one of two identical areas has still
changed what it published. Coordinate order stays significant: reversing a
polygon ring is a different shape.

Supersession is always resolved from the **stored** message's own
`message_type` and `references`, never from the record that arrived with it.
Trusting the incoming copy is how a stored `Alert` resent as a `Cancel` would
withdraw warnings it never referenced.

### 7.2 Append-only history

The history is enforced at two boundaries, matching the audit log:

| Rule | Eloquent | Database |
| --- | --- | --- |
| An `alert_messages` row is never deleted | `deleting` throws | `BEFORE DELETE` trigger; `BEFORE TRUNCATE` on PostgreSQL |
| Message content is never rewritten | `updating` rejects any dirty column outside the supersession stamp | PostgreSQL compares the row with `to_jsonb` minus three columns; SQLite names each column |
| A supersession stamp is written once, and both halves move together | `updating` rejects half a stamp, a self-supersession and any change to an already-set stamp | Dedicated triggers on both engines |
| An `alert_areas` row is never changed or deleted | `updating` and `deleting` throw | `BEFORE UPDATE OR DELETE` trigger; `BEFORE TRUNCATE` on PostgreSQL |

The only permitted write to a stored message is `superseded_by_id` +
`superseded_at` (plus the technical `updated_at`), once. The stamp has exactly
one legal transition:

| From | To | Verdict |
| --- | --- | --- |
| `(null, null)` | `(null, null)` | allowed — only `updated_at` moved |
| `(null, null)` | `(id, timestamp)` | allowed, unless the id is the row's own |
| `(null, null)` | `(id, null)` or `(null, timestamp)` | refused |
| `(id, timestamp)` | anything different | refused |

That transition is spelled out in the triggers on both engines rather than left
to PostgreSQL's `CHECK`, because the `CHECK` has no SQLite counterpart: SQLite
used to accept a `superseded_by_id` with a null `superseded_at`, and a message
superseding itself, on both the insert and the update path. A test environment
that refuses less than production proves less than it appears to, so the
supersession tests run on both drivers and none of them is skipped.

Both boundaries are needed: model events catch the assignment a developer would
actually write, the triggers catch the mass update or raw statement that never
loads a model — which is the path the importer itself uses to stamp supersession.

Migration `2026_09_02_120011_add_alert_history_immutability_guards` installs the
database half and its `down()` removes it.

Required translations are enforced, and there is no fallback between `tj`, `ru`
and `en`: a warning shown in the wrong language is worse than one reported as
unavailable. `instruction` is optional for a whole message but never in one
language only.

| Field | Type | Required | CAP origin |
| --- | --- | --- | --- |
| `source` | string | yes | Portal metadata |
| `identifier` | string | yes | `alert.identifier` |
| `sender` | string | yes | `alert.sender` |
| `sent_at` | datetime | yes | `alert.sent` |
| `status` | enum | yes | `Actual`, `Exercise`, `System`, `Test`, `Draft` |
| `message_type` | enum | yes | `Alert`, `Update`, `Cancel`, `Ack`, `Error` |
| `scope` | enum | yes | `Public`, `Restricted`, `Private` |
| `references` | string[] | yes | Alerts updated/cancelled by this message |
| `event_code` | string | yes | Stable event key |
| `category` | string[] | yes | CAP categories |
| `severity` | enum | yes | `Unknown`, `Minor`, `Moderate`, `Severe`, `Extreme` |
| `urgency` | enum | yes | CAP urgency |
| `certainty` | enum | yes | CAP certainty |
| `effective_at` | datetime/null | no | Start of effective message |
| `onset_at` | datetime/null | no | Expected event onset |
| `expires_at` | datetime | yes | Required for public lifecycle |
| `headline` | localized object | yes | `tj`, `ru`, `en` |
| `description` | localized object | yes | `tj`, `ru`, `en` |
| `instruction` | localized object | no | `tj`, `ru`, `en` |
| `parameters` | object | yes | Source-specific physical values |
| `areas` | array | yes | One or more affected areas |
| `raw_payload` | text/json | yes | Sanitized authoritative message |

Area fields:

```json
{
  "description": {
    "tj": "Душанбе",
    "ru": "Душанбе",
    "en": "Dushanbe"
  },
  "geocodes": [
    { "name": "TJ_REGION", "value": "DUSHANBE" }
  ],
  "geometry": {
    "type": "MultiPolygon",
    "coordinates": []
  },
  "altitude_m": null,
  "ceiling_m": null
}
```

If CAP supplies only `areaDesc` or geocodes, Hydromet must also provide a stable administrative-boundary dataset so the portal can resolve it to a polygon.

## 8. Integration source and synchronization run

### 8.1 Source configuration

```text
code, type, base_url, authentication_type, producer,
timezone, enabled, polling_interval_seconds, timeout_seconds,
cursor_strategy, overlap_seconds, parameter_mapping, unit_mapping
```

Secrets are not stored in ordinary JSON/database configuration fields.

### 8.2 Synchronization run

```text
id, source_id, kind, started_at, finished_at, status,
cursor_from, cursor_to, received_count, accepted_count,
updated_count, rejected_count, error_code, sanitized_error,
response_checksum
```

Allowed status values: `running`, `succeeded`, `partial`, `failed`.

## 9. CMS translation contract

Content records use explicit translations rather than embedding HTML from external systems:

```json
{
  "slug": "air-quality-health-advice",
  "title": { "tj": "...", "ru": "...", "en": "..." },
  "summary": { "tj": "...", "ru": "...", "en": "..." },
  "body": { "tj": "...", "ru": "...", "en": "..." },
  "status": "published",
  "published_at": "2026-08-31T06:00:00Z"
}
```

Publication is blocked when a required language is missing unless an administrator explicitly uses an approved fallback policy.

Current implementation details:

- `title` and `body` are required in `tj`, `ru` and `en` before publication;
- `summary` is optional in each language;
- drafts may remain incomplete;
- `published_at` is mandatory for published records and may schedule a future publication;
- bodies are stored and rendered as plain text, never trusted HTML;
- no fallback publication policy is enabled before Hydromet approves one.

## 10. Administrative audit contract

Sensitive administrative changes append an immutable event:

```text
occurred_at, actor_id, action,
subject_type, subject_id, subject_label,
changes.fields, changes.before, changes.after
```

The application and database both reject updates and deletes. Users referenced
as actors are deactivated rather than deleted. The initial implementation
records CMS creation and changed business fields; manual-measurement correction
will append its own audited action only after Hydromet approves the role and
reason workflow.
