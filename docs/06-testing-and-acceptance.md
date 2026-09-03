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

`ALERT-01` to `ALERT-04` are exercised end to end by
`tests/Feature/Alerts/AlertImportTest.php` and `tests/Feature/Api/AlertApiTest.php`
against the synthetic warning feed. They demonstrate that the lifecycle rules
hold; they are not acceptance, because acceptance needs Hydromet's real `Alert`,
`Update`, `Cancel`, expired and multi-area samples
(`docs/08-hydromet-input-checklist.md`, section 3). Re-running the same
scenarios against those samples is what converts a covered case into a passed
one.

`tests/Feature/Alerts/AlertFixtureFeedTest.php` guards the fixture itself: it
fails once the demonstration feed's validity dates fall into the past, so the
warning map cannot silently empty as time passes.

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

Implemented for the synthetic fixture dataset by `data:reconcile-fixture`, which
compares every total above — `active_alert_count` included — against
`app/Domain/Integrations/Fixtures/data/reconciliation.fixture.json` and exits
non-zero on any difference. The expected totals describe the **complete**
dataset, so the command fails until the station registry, both measurement
scenarios and both warning scenarios have been imported; its failure output
names the commands.

`active_alert_count` is the only total that depends on an instant: it is counted
through `AlertMessage::scopeActiveAt()`, so reconciliation, the public list, the
API and the panel cannot disagree about what "in force" means. `DataReconciler`
therefore takes the moment as an argument, and the tests freeze the clock rather
than letting the wall clock decide.

## 4. Performance profile

The parties must fix the test profile before measuring the 7-second requirement:

- device/browser;
- viewport;
- network latency and bandwidth;
- number of stations and active alerts;
- cold or warm cache;
- target page and definition of meaningful render.

Without this profile, the requirement is subjective and not repeatable.

## 5. Test execution and isolation

A test run must never depend on the machine it starts on, and must never reach
the application's own data.

`tests/TestEnvironment.php` applies that guarantee before PHPUnit loads a test.
It is applied in PHP rather than through `<env>` entries in `phpunit.xml`,
because Compose gives every service `env_file: .env` and Laravel resolves `env()`
through `$_SERVER`, which an `<env>` entry cannot reach. Cache, session, queue,
mail and broadcasting are pinned to isolated drivers on every run, whatever the
surrounding environment supplies.

The database is chosen the same way everywhere:

| Run | Database |
| --- | --- |
| Default, including inside Compose | SQLite in memory |
| `HYDROMET_TEST_DB=pgsql` | PostgreSQL, scratch database `hydromet_testing` |

Only that opt-in moves the suite off SQLite; an inherited `DB_CONNECTION` or
`DB_DATABASE` never does, so an environment-only host cannot be talked into
running the suite against production. `DB_HOST`, `DB_PORT`, `DB_USERNAME` and
`DB_PASSWORD` are still taken from the environment, which is how CI and a
developer machine reach their own PostgreSQL. `HYDROMET_TEST_DATABASE` renames
the scratch database; naming the application's own database, or none, aborts the
run before it connects. "The application's own database" is the one the
environment actually resolves to — an exported `DB_DATABASE` outranks `.env`,
exactly as it does for the application itself, so a stale file cannot hide a
collision.

Some guarantees — `CHECK` constraints, trigger-enforced immutability, foreign-key
behaviour and the PostGIS extension — exist only on PostgreSQL and report
themselves as skipped on SQLite. The PostgreSQL run therefore uses
`--fail-on-skipped`, so a guarantee that stops executing fails the build instead
of disappearing quietly.

```bash
composer test          # SQLite in memory
composer test:pgsql    # PostgreSQL/PostGIS, no skipped tests
```

`.github/workflows/ci.yml` runs exactly those commands, plus `composer lint`,
`composer analyse` and the frontend checks, on every push to `master` and every
pull request. It installs from `composer.lock` and `package-lock.json`, publishes
no artefact and deploys nothing.

`.github/workflows/dependency-security.yml` is a separate workflow for the
dependency audits, described in section 6.2. It is separate because it also runs
weekly: an advisory appears without the code changing.

## 6. Security verification

- dependency vulnerability scan for PHP and JavaScript packages — see 6.2;
- static analysis and code style checks;
- OWASP-oriented dynamic scan of the deployed test environment;
- authorization tests for every administrative action;
- rate-limit verification;
- CSP verification, including explicit SILAM frame permission;
- secret scan before release;
- backup encryption/access review;
- no production stack traces or credentials in logs/responses.

### 6.1 Implemented state

| Item | State | Where |
| --- | --- | --- |
| Response security headers | Automated | `tests/Feature/SecurityHeadersTest.php` |
| Baseline CSP, including on error and unmatched-route responses | Automated | `tests/Feature/SecurityHeadersTest.php` |
| Per-request `script-src` nonce on public pages, never repeated between responses | Automated | `tests/Feature/SecurityHeadersTest.php` |
| The nonce in the header is the one Vite stamps on the page's tags | Automated | `tests/Feature/SecurityHeadersTest.php` |
| The panel's inline/eval concession never reaches a public response | Automated | `tests/Feature/SecurityHeadersTest.php` |
| `CSP_STYLE_NONCE` switches styles to a nonce and adds its required companion directive | Automated | `tests/Feature/SecurityHeadersTest.php` |
| SILAM frame permission, derived from configuration and failing closed | Automated | `tests/Feature/SilamPageTest.php` |
| Rate-limit headers on success and on 429 | Automated | `tests/Feature/Api/ApiErrorEnvelopeTest.php` |
| No stack trace, exception class or credential in an API failure, with `APP_DEBUG` on and off | Automated | `tests/Feature/Security/FailureDisclosureTest.php` |
| Failure responses are `no-store` and `nosniff` | Automated | `tests/Feature/Security/FailureDisclosureTest.php` |
| No panel page reachable by a guest or a deactivated user, swept over the registered routes | Automated | `tests/Feature/Security/PanelAuthorizationTest.php` |
| No `/api` route behind a session guard | Automated | `tests/Feature/Security/PanelAuthorizationTest.php` |
| Per-resource role matrix | Automated | `tests/Feature/*AdminResource*Test.php` |
| Only an active administrator may manage accounts, at the URL as well as in the menu | Automated | `tests/Feature/Identity/UserAdminResourceTest.php` |
| An administrator cannot deactivate themselves or change their own role | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| The last active administrator cannot be deactivated or demoted | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| Deactivation, a role change and a password change move the account's security stamp; a rename or a corrected address does not | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| A failing audit write rolls the security stamp back with the change | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| A signed-in person is sent to `/admin/login` on their next request after a role change, a password change or a deactivation, over a real sign-in and a page open to both roles | Automated | `tests/Feature/Identity/SessionRevocationTest.php` |
| The same sign-out on the `array`, `file` and `database` session drivers, with the session read back through its cookie | Automated | `tests/Feature/Identity/SessionRevocationTest.php` |
| A rename or a new address leaves the session working, and a bystander's session is untouched | Automated | `tests/Feature/Identity/SessionRevocationTest.php` |
| The session stamp holds only an account id and a version — no password, hash or token | Automated | `tests/Feature/Identity/SessionRevocationTest.php` |
| A deactivated user cannot open the next admin page | Automated | `tests/Feature/Identity/UserAdminFormTest.php` |
| The first administrator is created only on an empty `users` table, active, hashed, normalized and audited with no actor | Automated | `tests/Feature/Identity/BootstrapAdministratorCommandTest.php` |
| A second bootstrap run is refused before asking anything and changes nothing | Automated | `tests/Feature/Identity/BootstrapAdministratorCommandTest.php` |
| The bootstrap holds a lock for its whole transaction, the lock is transaction-scoped, and a competing run waits and is then refused | Automated, PostgreSQL | `tests/Feature/Identity/BootstrapSerializationTest.php` |
| The SQLite lock statement really takes SQLite's write lock and changes nothing | Automated, both drivers | `tests/Feature/Identity/BootstrapSerializationTest.php` |
| The lock identifier is a fixed constant, not a derived value | Automated | `tests/Feature/Identity/BootstrapSerializationTest.php` |
| A database the bootstrap cannot serialize is refused instead of run unprotected | Automated | `tests/Feature/Identity/BootstrapSerializationTest.php` |
| The bootstrap command applies the panel's password policy, and a failing audit write rolls the account back | Automated | `tests/Feature/Identity/BootstrapAdministratorCommandTest.php` |
| Account changes are audited, and no credential reaches the audit payload | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| A failing audit write rolls the account change back | Automated | `tests/Feature/Identity/UserAccountManagerTest.php` |
| Accounts cannot be deleted through the model, a raw statement, `TRUNCATE` or any Filament route | Automated | `tests/Feature/Identity/UserAdminResourceTest.php` |
| PostgreSQL refuses a role outside the portal vocabulary | Automated | `tests/Feature/Identity/UserAdminResourceTest.php` |
| `/api/v1/system/status` state machine, including the threshold boundary, tied run timestamps and a running import not erasing the last result | Automated, both drivers | `tests/Feature/Api/SystemStatusApiTest.php` |
| The status response exposes no base URL, producer, authentication type, counters or error text | Automated | `tests/Feature/Api/SystemStatusApiTest.php` |
| The status query cost does not grow with the number of sources | Automated | `tests/Feature/Api/SystemStatusApiTest.php` |
| PostgreSQL refuses a staleness threshold under a minute | Automated | `tests/Feature/Integrations/SynchronizationSchemaConstraintsTest.php` |
| A warning starts at `effective_at ?? sent_at`, with the SQL scope and the object method agreeing at every boundary | Automated | `tests/Feature/Alerts/AlertActivationRuleTest.php` |
| A message that has not started is shown as `scheduled`, never as in force | Automated | `tests/Feature/Alerts/AlertAdminResourcesTest.php` |
| A repeated identifier with different content is quarantined and writes nothing | Automated | `tests/Feature/Alerts/AlertIdentifierConflictTest.php` |
| A stored `Alert` resent as `Cancel`/`Update` supersedes nothing | Automated | `tests/Feature/Alerts/AlertIdentifierConflictTest.php` |
| Warning messages and areas cannot be deleted, rewritten or truncated, on either driver | Automated | `tests/Feature/Alerts/AlertHistoryImmutabilityTest.php` |
| A supersession stamp is written once and never cleared or reassigned | Automated | `tests/Feature/Alerts/AlertHistoryImmutabilityTest.php` |
| The whole `Alert → Update → Update → Cancel` chain is returned from any of its links | Automated | `tests/Feature/Alerts/AlertMessageChainTest.php` |
| A warning from a non-fixture source is addressable, and the same identifier in two sources resolves separately | Automated | `tests/Feature/Api/AlertDetailContractTest.php` |
| A warning is readable on the public web at its own shareable address, in all three languages | Automated | `tests/Feature/Alerts/PublicAlertPageTest.php` |
| An expired or superseded warning stays readable and is marked as no longer in force, rather than answering `404` | Automated | `tests/Feature/Alerts/PublicAlertPageTest.php`, `tests/frontend/alert-show.test.tsx` |
| The whole message chain is reachable from the public page, from any of its links | Automated | `tests/Feature/Alerts/PublicAlertPageTest.php` |
| A non-public message is reported as missing identically on the web page and the API | Automated | `tests/Feature/Alerts/PublicAlertPageTest.php` |
| An address outside the allowed source/identifier shape never reaches the query | Automated | `tests/Feature/Alerts/PublicAlertPageTest.php` |
| CAP urgency and certainty are translated rather than printed as English tokens | Automated | `tests/frontend/alert-show.test.tsx` |
| The published list is ordered by CAP severity, not alphabetically | Automated | `tests/Feature/Api/AlertDetailContractTest.php` |
| `<html lang>` follows an Inertia language switch | Automated | `tests/frontend/document-language.test.tsx` |
| Audit export is administrator-only, language-neutral and formula-safe | Automated | `tests/Feature/Audit/AuditExportTest.php` |
| Exception message and trace never reach the synchronization journal or the log | Automated | `tests/Feature/Integrations/SynchronizationRunnerTest.php` |
| Dependency audit thresholds cannot be loosened silently | Automated | `tests/Feature/Security/DependencyAuditPolicyTest.php` |
| The dependency workflow is read-only and never repairs or suppresses a finding | Automated | `tests/Feature/Security/DependencyAuditPolicyTest.php` |
| Dependency vulnerability scan | Implemented, provisional policy | section 6.2 below; the release gate is still an owner decision |
| OWASP-oriented dynamic scan | Blocked | needs a deployed test environment, which does not exist yet |
| Backup encryption and access review | Blocked | needs the backup destination in `docs/09-runbooks.md`, section 5 |

The panel sweep is the guard against a resource added later without its own
role tests: it enumerates the registered `admin` routes rather than a written
list, and asserts a lower bound on how many it found, so a matching bug that
silently covered nothing fails instead of passing.

Two items are known gaps rather than oversights.

The administration panel is served `script-src 'self' 'unsafe-inline'
'unsafe-eval'` instead of the public nonce policy, because Filament's Blade
views render inline scripts that accept no nonce and Alpine compiles its
expressions with `new AsyncFunction`. This was confirmed rather than assumed:
serving the panel the public policy produced a login page that rendered
unstyled and non-functional, with 21 `EvalError` exceptions from Livewire's
evaluator. A test asserts the concession cannot leak onto a public response.

And nginx, not PHPUnit, is what actually emits the static headers in the Compose
topology; the application sets them too so the guarantee is provable here and
survives a different front end. The CSP is the exception — it carries a
per-request nonce, so only the application can send it.

Account administration is exercised against invented accounts. A fixture or
test account is never a production user: Hydromet has supplied no user list
and approved no role matrix, and the portal ships with no default
administrator. Password delivery and password-reset e-mail are out of scope
until SMTP is configured, so an initial password is handed over out of band.

`/api/v1/system/status` is exercised against synthetic sources only. A fixture
run finishing on time proves the state machine, not a production service level:
no real threshold has been approved and no real feed is connected, so nothing in
that suite is evidence of an SLA. The endpoint is also not a replacement for
`/health` — a monitoring system must keep watching `/up` and `/health`, which
answer whether the application itself is alive and ready.

Browser verification performed for the nonce policy, against the Compose stack:
the station map with its tiles, markers and warning polygon; the ECharts station
charts; the language dropdown, whose scroll lock injects a `<style>` element at
runtime; and Inertia's navigation progress bar. Zero `securitypolicyviolation`
events in Chrome, with `CSP_STYLE_NONCE` both off and on. Firefox and Safari
have not been checked, which is why the style nonce ships off by default.

### 6.2 Dependency scanning

Dependencies are audited from the lock files, so what is judged is the tree that
is actually deployed rather than whatever a fresh resolution would pick today.

| Ecosystem | Scope | Fails on | Command | CI trigger |
| --- | --- | --- | --- | --- |
| PHP | Whole locked tree | Any advisory; any abandoned package | `composer security` (`composer audit --locked --abandoned=fail`) | Pull request, push to `master`, weekly schedule, manual |
| npm | Runtime dependencies | `moderate`, `high`, `critical` | `npm run audit:production` (`npm audit --omit=dev --audit-level=moderate`) | Pull request, push to `master`, weekly schedule, manual |
| npm | Runtime and development | `high`, `critical` | `npm run audit:all` (`npm audit --audit-level=high`) | Pull request, push to `master`, weekly schedule, manual |
| Both | Dependencies a pull request **adds**, in every scope: `runtime`, `development`, `unknown` | `moderate` and above | `actions/dependency-review-action@v4` | Pull request only |
| PHP | Manifest against lock file | Any drift | `composer validate --strict` | Pull request, push to `master`, weekly schedule, manual |

The two kinds of check answer different questions and neither replaces the
other. The lockfile audits ask whether anything in the tree is known to be
vulnerable **today** — an answer that changes without a line of this repository
changing, which is why they also run on a schedule. Dependency Review asks
whether a pull request is **adding** something that should not be added, and
sees licence and provenance information an audit does not look at.

Its settings are all stated in the workflow rather than inherited, because the
defaults are weaker than this policy in one place that matters:

| Setting | Value | Why it is written down |
| --- | --- | --- |
| `fail-on-severity` | `moderate` | Matches the npm production threshold |
| `fail-on-scopes` | `runtime, development, unknown` | **The action's own default is `runtime` alone.** A vulnerable build tool still runs on developer machines and in CI, where it can read the source and reach whatever those machines reach; and an `unknown` scope is not a safe dependency, it is an unclassified one |
| `vulnerability-check` | `true` | The point of the job, named so that turning it off has to be deliberate |
| `warn-only` | `false` | A warning nobody must act on is not a gate |
| `comment-summary-in-pr` | `never` | A comment needs `pull-requests: write`, which this workflow does not have |

`allow-ghsas` and `config-file` are deliberately absent: the first is an
advisory allowlist, the second would move the policy into a file the contract
test cannot see. **There is no allowlist and no exception today**, in either
the action or `composer.json`, and
`tests/Feature/Security/DependencyAuditPolicyTest.php` fails if one appears.

Acceptance criteria for this item:

- `composer security`, `npm run audit:production` and `npm run audit:all` exist
  as named commands in the manifests, and CI runs those same names, so a local
  pass and a CI pass mean the same thing;
- each command exits non-zero at its documented threshold;
- the audits run without installing either dependency tree;
- `.github/workflows/dependency-security.yml` holds `contents: read` and no
  write permission, uses no secret, publishes no artefact and writes no
  pull-request comment;
- Dependency Review blocks `moderate` and above in all three scopes, with the
  vulnerability check on and `warn-only` off;
- no script or workflow runs `npm audit fix`, `composer update`, or any
  advisory suppression;
- there is no ignore list and no allowlist, in the manifests or in the action;
- the thresholds are asserted by
  `tests/Feature/Security/DependencyAuditPolicyTest.php`, so loosening one fails
  the suite rather than passing quietly. It compares each audit command exactly
  after collapsing whitespace, and reads the workflow with comments stripped, so
  neither an appended `|| true` nor a directive that only appears in a comment
  can satisfy it. It also fails on `continue-on-error`, `warn-only: true`,
  `allow-ghsas`, `config-file`, `--ignore-severity`, `--ignore-unreachable`,
  `--no-dev`, `--audit-level=none` and a `config.audit.ignore` block in
  `composer.json`.

State on 2026-09-03: `composer audit --locked --abandoned=fail`, `npm audit
--omit=dev --audit-level=moderate` and `npm audit --audit-level=high` each
report zero findings, and there are no abandoned packages.

**Still to be confirmed on GitHub.** Everything above has been run locally and
its configuration is asserted by an automated test, but no run of
`dependency-security.yml` has executed on GitHub yet. Dependency Review in
particular depends on the repository's dependency graph being enabled, which
cannot be verified from a working copy. Until the first remote run, treat the
workflow as configured-but-unobserved.

The severities themselves are a **provisional** policy, chosen to be safe rather
than convenient. Whether a dependency scan gates a release, and at what
severity, is an owner decision that is still open
(`docs/08-hydromet-input-checklist.md`, section 6).

## 7. Definition of done

A feature is done only when:

- its acceptance behaviour is documented;
- automated tests cover normal and important failure paths;
- all three languages are supplied or an approved fallback exists;
- role/permission behaviour is implemented;
- logging and data-source failure behaviour are defined;
- user/operator documentation is updated;
- it runs in the production-like deployment configuration.

