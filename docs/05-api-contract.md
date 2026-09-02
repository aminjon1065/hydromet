# Portal API contract

Implementation status: the `/api/v1` metadata, station list/detail, bounded
series, CSV, published-content and alert endpoints are implemented against
canonical local data. The alert endpoints are fed by a synthetic fixture feed,
because Hydromet has not chosen a MeteoAlert source type
(`docs/08-hydromet-input-checklist.md`, section 3); the public contract does not
change when a real adapter replaces it. The system-status endpoint remains
pending its configuration inputs. `is_stale` and `stale_after_seconds` are
returned as `null`, and AQI as `null`/unavailable, until Hydromet approves those
rules.

## 1. Conventions

- Base path: `/api/v1`.
- JSON property names use `snake_case`.
- Timestamps are ISO 8601 UTC.
- Public translations are selected with application locale `Accept-Language: tj|ru|en`; `tg` and `tg-TJ` may be accepted as external aliases and normalized to `tj`.
- Lists use cursor pagination unless the endpoint is explicitly a small bounded catalogue.
- Numeric observations are JSON numbers; missing observations are `null`.
- Observation time windows are half-open `[from, to)`: `from` is included and
  `to` is excluded, so consecutive windows tile a period without returning the
  boundary observation twice. This applies to `series` and `export.csv`.
- Errors use one stable envelope. The envelope is the whole response: the
  exception class, message, file, line and stack trace never appear in it, with
  `APP_DEBUG` on or off. `X-Request-Id` is the only diagnostic channel and
  correlates the response with the server log.
- Every response carries `X-Request-Id`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options`, `Referrer-Policy` and `X-Permitted-Cross-Domain-Policies`.
  Failures are additionally `Cache-Control: no-store`.
- Requests are rate limited. A limited response is `429` in the same envelope
  with `error.code` `rate_limited`, and keeps `Retry-After` and `X-RateLimit-*`
  so a client can back off correctly. The current limit is a development
  placeholder; the production budget is an open item in
  `docs/08-hydromet-input-checklist.md`.

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
| `from` | yes | ISO datetime; inclusive lower bound |
| `to` | yes | ISO datetime; exclusive upper bound |
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

Uses the same filters as `series`, including the half-open `[from, to)` window. CSV begins with UTF-8 BOM only if required for target spreadsheet compatibility. The final format and delimiter are included in acceptance fixtures.

### `GET /api/v1/alerts`

Implemented against synthetic fixtures.

Filters:

| Parameter | Status |
| --- | --- |
| `active_at` | Implemented. ISO 8601 with an explicit timezone; defaults to now. |
| `severity` | Implemented. One of the CAP severities. |
| `event_code` | Implemented. |
| `bbox` | Implemented as `west,south,east,north`. Matches a warning whose affected area extent overlaps. A geocode-only area has no extent and is never matched. |
| `region` | Not implemented. The official region-code vocabulary is not agreed, and a geocode-containment query would behave differently on PostgreSQL and SQLite. |
| `include_test` | Not implemented. Publishing a `Test` message is a publication-rule decision Hydromet has not made; the portal never returns one. |
| `from`, `to` | Not implemented. A send-time range is only meaningful once the feed's refresh semantics are known. |

Returns canonical alert summaries and GeoJSON geometries. The default result
contains only current `Actual` + `Public` alerts that have not been superseded,
cancelled or expired, and whose validity window has started — a warning whose
`effective_at ?? sent_at` is still in the future is scheduled, not in force, and
is not returned.

Ordering is severity first (`Extreme`, `Severe`, `Moderate`, `Minor`,
`Unknown`), then `sent_at` descending, then `identifier` and finally the storage
key, so a client paging the same data twice gets the same order. The ranking is
the CAP one, not the alphabetical order of the stored strings.

Every entry carries both `source` and `identifier`, which together form the
detail URL below.

Response: `{ "data": [ ... ], "meta": { "generated_at", "active_at", "severity_order" } }`,
`Cache-Control: public, max-age=60`, `Vary: Accept-Language`. `severity_order`
is the CAP ranking and matches the order of `data` — the portal publishes no
colour scale of its own, because none is approved.

### `GET /api/v1/alerts/{source}/{identifier}`

Implemented against synthetic fixtures.

A CAP identifier is unique within its sender, not globally, so the public
identity of a warning is the pair `(source, identifier)` — the same pair the
storage layer keys on and the same pair the list returns. Both come from the
URL; the endpoint resolves no default source, so a warning from a second feed is
reachable the moment it is stored.

| Segment | Constraint |
| --- | --- |
| `source` | `[A-Za-z0-9._-]{1,32}` |
| `identifier` | `[A-Za-z0-9@._:+~-]{1,190}` — wide enough for `urn:oid:…` and `NWS-IDP-PROD-1@2026-01-01T00:00:00Z`, and excluding `/` |

Returns localized headline, description, instruction, affected areas, validity,
sender, severity, urgency, certainty and the message chain, plus `is_active` and
`superseded_at` so a client that stored an identifier can explain what happened
to it.

`history` is the **whole** supersession chain the message belongs to, not its
immediate neighbours: asking about any link of `Alert → Update → Update →
Cancel` returns all four, newest first. The chain is walked iteratively and is
bounded at 200 messages; reaching that bound is logged with the message identity
rather than truncating silently, and no real chain approaches it. Only messages
sharing the requested message's status and scope are followed, so a restricted
message never becomes readable through a public one.

A message that is not `Actual` + `Public` returns the same `404` envelope as an
unknown identifier: its existence is itself not public. An identifier that does
not match the constraints above is refused by the router, with the same envelope
and no stack trace.

### `GET /api/v1/content/{slug}`

Returns a published static page, news item, bulletin or health-advice record in
the selected language. Drafts and future publications return the same `404`
envelope as an unknown slug. The current body format is plain text: public
clients must not interpret it as trusted HTML. Responses use a five-minute
public cache and vary by `Accept-Language`.

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
- inspect audit records (implemented for administrators);
- export audit records (implemented for administrators as a streamed CSV
  download at `GET /admin/exports/audit-events.csv`, with optional UTC `from`
  and `to` bounds). It is a panel route, not a `/api/v1` endpoint: it is
  session-authenticated administrative evidence rather than a public read model.

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
