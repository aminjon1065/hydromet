# Portal API contract

## 1. Conventions

- Base path: `/api/v1`.
- JSON property names use `snake_case`.
- Timestamps are ISO 8601 UTC.
- Public translations are selected with application locale `Accept-Language: tj|ru|en`; `tg` and `tg-TJ` may be accepted as external aliases and normalized to `tj`.
- Lists use cursor pagination unless the endpoint is explicitly a small bounded catalogue.
- Numeric observations are JSON numbers; missing observations are `null`.
- Errors use one stable envelope.

```json
{
  "error": {
    "code": "invalid_time_range",
    "message": "The selected time range is not supported.",
    "details": {},
    "request_id": "01J..."
  }
}
```

## 2. Public endpoints

### `GET /api/v1/metadata`

Returns supported languages, timezone, parameter catalogue, AQI schemes, categories and update times.

### `GET /api/v1/stations`

Query parameters:

| Parameter | Type | Notes |
| --- | --- | --- |
| `bbox` | `west,south,east,north` | Optional map extent |
| `region` | string | Region code |
| `status` | enum | Defaults to publicly visible statuses |
| `parameter` | string | Station must expose this parameter |
| `updated_after` | datetime | Incremental client refresh |
| `cursor` | string | Pagination cursor |

Map response is deliberately compact:

```json
{
  "data": [
    {
      "id": "01J...",
      "code": "DUS-017",
      "name": "Душанбе — центр",
      "latitude": 38.5737,
      "longitude": 68.7738,
      "status": "active",
      "observed_at": "2026-08-31T06:00:00Z",
      "is_stale": false,
      "aqi": {
        "scheme": "TJ_NATIONAL",
        "value": 42,
        "category": "good",
        "color": "#4CAF50",
        "dominant_parameter": "PM25"
      },
      "measurements": {
        "PM25": { "value": 23.4, "unit": "ug/m3", "quality": "valid" },
        "PM10": { "value": 31.8, "unit": "ug/m3", "quality": "valid" }
      }
    }
  ],
  "meta": {
    "generated_at": "2026-08-31T06:05:00Z",
    "next_cursor": null
  }
}
```

### `GET /api/v1/stations/{station}`

Returns full public station metadata, available parameters, last observation per parameter, source attribution and last successful synchronization.

### `GET /api/v1/stations/{station}/series`

Query parameters:

| Parameter | Required | Values |
| --- | --- | --- |
| `parameters` | yes | Comma-separated canonical codes |
| `from` | yes | ISO datetime |
| `to` | yes | ISO datetime |
| `aggregation` | yes | `raw`, `hour`, `day`, `month` |
| `quality` | no | Default `valid,corrected`; allow `all` for authorized admin |
| `timezone` | no | Public default `Asia/Dushanbe`; UTC allowed |

Response:

```json
{
  "station": { "id": "01J...", "code": "DUS-017", "name": "Душанбе — центр" },
  "range": {
    "from": "2026-08-30T00:00:00Z",
    "to": "2026-08-31T00:00:00Z",
    "aggregation": "hour"
  },
  "series": [
    {
      "parameter": "PM25",
      "unit": "ug/m3",
      "points": [
        {
          "time": "2026-08-30T01:00:00Z",
          "value": 23.4,
          "quality": "valid",
          "corrected": false,
          "sample_count": 6
        }
      ]
    }
  ]
}
```

The server enforces range/aggregation limits. A one-year request must use daily or monthly aggregation unless explicitly configured otherwise.

### `GET /api/v1/stations/{station}/export.csv`

Uses the same filters as `series`. CSV begins with UTF-8 BOM only if required for target spreadsheet compatibility. The final format and delimiter are included in acceptance fixtures.

### `GET /api/v1/alerts`

Filters:

```text
active_at, from, to, severity, event_code, region, include_test=false
```

Returns canonical alert summaries and GeoJSON geometries. The default result contains only current `Actual` + `Public` alerts that have not been cancelled or expired.

### `GET /api/v1/alerts/{identifier}`

Returns localized headline, description, instruction, affected areas, validity, sender, severity, urgency, certainty, update history and attribution.

### `GET /api/v1/content/{slug}`

Returns a published static page or bulletin in the selected language.

### `GET /api/v1/system/status`

Public, non-sensitive status:

```json
{
  "status": "degraded",
  "generated_at": "2026-08-31T06:05:00Z",
  "sources": [
    {
      "code": "hydromet_observations",
      "status": "stale",
      "last_success_at": "2026-08-31T04:00:00Z",
      "stale_after_seconds": 7200
    }
  ]
}
```

Do not expose internal hostnames, credentials, stack traces or raw upstream errors.

## 3. Administrative operations

Administrative routes are session-authenticated and policy-protected. Exact Filament routes need not become public REST endpoints.

Required operations:

- manage users, roles and permissions;
- view and retry synchronization runs;
- view rejected import rows without exposing credentials;
- manage source field and unit mappings;
- manage station public metadata;
- enter and correct measurements with mandatory reason;
- manage AQI schemes and approval state;
- manage alert event display mapping and icons;
- manage translations and content;
- inspect and export audit records.

## 4. Cache policy

| Endpoint | Suggested public cache |
| --- | ---: |
| Metadata | 5–15 minutes |
| Station map/current values | 1–5 minutes |
| Historical series | 5–60 minutes depending on period |
| Active alerts | 1 minute |
| Published content | 5 minutes |
| System status | No shared cache or at most 30 seconds |

Cache keys include language, filters, aggregation and AQI scheme version.

## 5. Versioning policy

- Additive fields may be introduced within `/v1`.
- Removing or changing field meaning requires `/v2` or a documented transition.
- Source adapter changes do not change the public API when the canonical meaning remains the same.
- Units and AQI scheme versions are always explicit so historical responses remain interpretable.
