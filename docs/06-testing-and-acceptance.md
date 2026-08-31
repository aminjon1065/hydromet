# Testing and acceptance

## 1. Test layers

### Unit tests

- unit conversion;
- station and parameter mapping;
- missing-value handling;
- AQI breakpoint boundaries and rounding;
- alert severity/event mapping;
- CAP Update and Cancel resolution;
- stale-source calculation;
- authorization policies.

### Contract tests

Each integration has immutable sanitized fixtures supplied by Hydromet:

- station registry response;
- current observations;
- historical batch;
- missing and invalid observations;
- revised observation;
- CAP/WFS Alert, Update, Cancel and expired warning;
- SmartMet Timeseries response with sensor number and quality flag;
- WMS GetCapabilities document if WMS is used.

Contract tests fail when an upstream response can no longer be mapped.

### Integration tests

- imports are idempotent;
- late values are captured by cursor overlap;
- one rejected row does not discard a valid batch;
- corrections preserve the original value and audit trail;
- queue retry does not create duplicates;
- public queries exclude invalid values by default;
- database indexes support agreed history ranges.

### Browser tests

- Tajik, Russian and English navigation;
- map marker selection and keyboard access;
- station charts for 24h/7d/30d/1y;
- CSV download;
- alert polygons and details;
- SILAM iframe and unavailable fallback;
- mobile layouts;
- login, role restrictions and correction workflow.

## 2. Acceptance scenarios

| ID | Scenario | Pass condition |
| --- | --- | --- |
| ST-01 | Registry import | All agreed active stations appear once with correct ID and coordinates |
| ST-02 | Current sync | New valid data becomes public within the agreed source interval plus five minutes |
| ST-03 | Missing value | Missing source value is displayed as unavailable, never zero |
| ST-04 | Duplicate import | Repeating the same batch does not increase measurement count |
| ST-05 | Source revision | Revised value is effective and the previous source revision remains traceable |
| ST-06 | Manual correction | Only authorized role can correct; reason and before/after values are audited |
| HIST-01 | Historical charts | Agreed fixture values match charts for all four periods |
| HIST-02 | CSV | Export matches the approved columns, timezone, units and values |
| AQI-01 | Breakpoints | Approved boundary-value fixture produces the signed expected category |
| ALERT-01 | Alert | Actual public warning appears with correct polygon, severity and languages |
| ALERT-02 | Update | Updated warning supersedes its referenced predecessor |
| ALERT-03 | Cancel | Cancelled warning disappears from active view without history loss |
| ALERT-04 | Expiry | Expired warning is not active after `expires_at` |
| INT-01 | Upstream outage | Last data remains visible with clear stale/unavailable state |
| I18N-01 | Languages | No required public page contains untranslated system strings |
| SEC-01 | Authorization | Operator/editor/admin permissions match the approved matrix |
| PERF-01 | Concurrency | Main read flows succeed for 100 concurrent virtual users under agreed data volume |
| PERF-02 | Mobile render | Meaningful content appears within the target under the agreed network/browser test profile |
| OPS-01 | Backup | A fresh environment is restored from backup using the runbook |

## 3. Data reconciliation acceptance

For a mutually selected reference period, Hydromet provides expected totals:

```text
station count
measurement count by station and parameter
minimum/maximum observation time
missing-value count
invalid/suspect count
revision count
active alert count
```

The portal import report must match these totals or contain an approved explanation for every difference.

## 4. Performance profile

The parties must fix the test profile before measuring the 7-second requirement:

- device/browser;
- viewport;
- network latency and bandwidth;
- number of stations and active alerts;
- cold or warm cache;
- target page and definition of meaningful render.

Without this profile, the requirement is subjective and not repeatable.

## 5. Security verification

- dependency vulnerability scan for PHP and JavaScript packages;
- static analysis and code style checks;
- OWASP-oriented dynamic scan of the deployed test environment;
- authorization tests for every administrative action;
- rate-limit verification;
- CSP verification, including explicit SILAM frame permission;
- secret scan before release;
- backup encryption/access review;
- no production stack traces or credentials in logs/responses.

## 6. Definition of done

A feature is done only when:

- its acceptance behaviour is documented;
- automated tests cover normal and important failure paths;
- all three languages are supplied or an approved fallback exists;
- role/permission behaviour is implemented;
- logging and data-source failure behaviour are defined;
- user/operator documentation is updated;
- it runs in the production-like deployment configuration.

