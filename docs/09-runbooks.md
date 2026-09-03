# Operational runbooks

Procedures for running, releasing and recovering the portal. Every command here
has been executed against the development Compose stack; where a step depends on
a decision Hydromet or the VPS owner has not made, it says so instead of
guessing.

Scope note: the portal has never been deployed. These are the procedures to
follow at first deployment, not a record of a production system in use. Anything
marked **REQUIRES OWNER INPUT** must be answered before the first release.

## 1. Test commands

| Purpose | Command |
| --- | --- |
| Style | `composer lint` (check) / `composer format` (apply) |
| Static analysis | `composer analyse` (PHPStan level 8) |
| Backend, SQLite in memory | `composer test` |
| Backend, PostgreSQL/PostGIS | `composer test:pgsql` |
| Frontend types | `npm run types:check` |
| Frontend lint | `npm run lint` |
| Frontend format | `npm run format:check` / `npm run format` |
| Frontend tests | `npm test` |
| Production asset build | `npm run build` |
| Compose file validity | `docker compose config --quiet` |

`composer test:pgsql` sets `HYDROMET_TEST_DB=pgsql`, pins the suite to the
scratch database `hydromet_testing`, and runs PHPUnit with `--fail-on-skipped`.
That flag is the point of the job: the PostgreSQL-only guarantees — `CHECK`
constraints, immutability triggers, `timestamptz` storage, PostGIS availability —
must actually run rather than report themselves as skipped.

The suite refuses to run against the application's own database. If it does,
create a scratch database and name it in `HYDROMET_TEST_DATABASE`.

```bash
docker compose exec postgres psql -U hydromet -d postgres \
  -c "CREATE DATABASE hydromet_testing OWNER hydromet"

docker compose exec -e HYDROMET_TEST_DB=pgsql app php vendor/bin/phpunit --fail-on-skipped
```

CI (`.github/workflows/ci.yml`) runs the SQLite suite, the PostgreSQL/PostGIS
suite and the frontend job on every push.

## 2. Fixture data

All three importers are development-only, are blocked in `production`, and write
under the invented `fixture` source key.

```bash
docker compose exec app php artisan stations:import-fixture-registry
docker compose exec app php artisan measurements:import-fixture-batch --scenario=base
docker compose exec app php artisan measurements:import-fixture-batch --scenario=correction
docker compose exec app php artisan alerts:import-fixture-feed --scenario=baseline
docker compose exec app php artisan alerts:import-fixture-feed --scenario=lifecycle
docker compose exec app php artisan data:reconcile-fixture
```

Every one is idempotent: re-running reports rows as `unchanged` and changes no
data. Each invocation is journalled in `synchronization_runs`.

Run them in the order above. `data:reconcile-fixture` compares the whole fixture
dataset against its expected totals — station and measurement counts, time
bounds, missing and suspect values, revisions, and the number of warnings in
force — so it fails until every batch is imported, warnings included. Its
failure output names the commands that are missing.

A repeated identifier carrying different content is not applied: it is
quarantined in the run as `identifier_conflict` and the stored message is kept.
Inspect those rows under Administration -> Synchronization runs; the fix belongs
with the feed, not with the portal, because a warning is corrected by a new
identifier rather than by resending an old one.

## 3. Migrations

Migrations are forward-only in production. Verify on a scratch database first;
never let the first execution of a migration be the production one.

```bash
# 1. Rehearse on a scratch copy of the production schema.
docker compose exec postgres psql -U hydromet -d postgres \
  -c "CREATE DATABASE hydromet_release_check OWNER hydromet"
docker compose exec -e DB_DATABASE=hydromet_release_check app php artisan migrate --force
docker compose exec -e DB_DATABASE=hydromet_release_check app php artisan migrate:rollback --force
docker compose exec -e DB_DATABASE=hydromet_release_check app php artisan migrate --force
docker compose exec postgres psql -U hydromet -d postgres \
  -c "DROP DATABASE hydromet_release_check"

# 2. Back up (section 5), then apply.
docker compose exec app php artisan migrate --force
```

Notes that matter:

- PostgreSQL runs DDL inside a transaction, so a migration that fails part-way
  rolls back cleanly. A migration that fails on PostgreSQL may still have
  succeeded on SQLite, which is why the PostgreSQL rehearsal is not optional —
  reserved words and `CHECK` constraints exist only there.
- Down migrations are written and are exercised by the rehearsal above, but they
  are a development and rehearsal tool. Production recovery is restore from
  backup (section 6), not rollback of a migration that has already been written
  to by users.
- A migration that widens an enumeration (for example
  `2026_09_02_120010_allow_alert_synchronization_runs`) deletes the rows the
  narrower constraint would reject on the way down. Read the `down()` before
  rehearsing it against data you care about.

## 4. Queue and scheduler

```bash
docker compose ps                                   # all six services healthy
docker compose logs --tail=100 queue
docker compose logs --tail=100 scheduler
docker compose restart queue scheduler
```

`queue` runs `php artisan queue:work --tries=3 --max-time=3600 --sleep=1` with a
60-second `stop_grace_period`, so a restart lets an in-flight job finish.
`scheduler` runs `php artisan schedule:work`.

Nothing is scheduled yet: no importer is registered with the scheduler, because
Hydromet has not supplied refresh frequencies or a stale threshold
(`docs/08-hydromet-input-checklist.md`, section 3). Both containers run so the
topology is proven, not because work is queued.

After a deployment that changes queued job code, restart the worker — a running
worker holds the old code in memory.

## 5. Backup

**REQUIRES OWNER INPUT**: the external backup destination, its credentials, the
retention period, and the recovery point and recovery time objectives
(`docs/08-hydromet-input-checklist.md`, section 6). Backups must be stored off
the VPS that runs the portal; a copy on the same disk is not a backup.

What has to be captured:

| Item | How |
| --- | --- |
| Database | `pg_dump` of the application database |
| Uploaded files | `storage/app` (currently empty; the portal stores no uploads yet) |
| Secrets | `.env` — separately, encrypted, never in the same archive as the database |

```bash
# Consistent logical dump, custom format so pg_restore can be selective.
docker compose exec -T postgres pg_dump -U hydromet -Fc hydromet > hydromet-$(date +%Y%m%d-%H%M).dump
```

The dump contains every observation, warning and audit event. Treat it as
production data: encrypt at rest and restrict who can read it.

## 6. Restore rehearsal

A backup that has never been restored is a hypothesis. Rehearse into a scratch
database, never over the live one.

```bash
docker compose exec postgres psql -U hydromet -d postgres \
  -c "CREATE DATABASE hydromet_restore_check OWNER hydromet"

docker compose exec -T postgres pg_restore -U hydromet -d hydromet_restore_check --no-owner < hydromet-<stamp>.dump

# Prove the restore, do not assume it.
docker compose exec postgres psql -U hydromet -d hydromet_restore_check -c "
  SELECT 'stations' t, count(*) FROM stations
  UNION ALL SELECT 'measurements', count(*) FROM measurements
  UNION ALL SELECT 'alert_messages', count(*) FROM alert_messages
  UNION ALL SELECT 'audit_events', count(*) FROM audit_events
  UNION ALL SELECT 'synchronization_runs', count(*) FROM synchronization_runs"

docker compose exec -e DB_DATABASE=hydromet_restore_check app php artisan migrate:status

docker compose exec postgres psql -U hydromet -d postgres \
  -c "DROP DATABASE hydromet_restore_check"
```

Record the counts and the wall-clock duration. The duration is the only honest
input to a recovery time objective; the counts are what a reconciliation
conversation with Hydromet needs.

Rehearse after every schema change that alters an owned table, and at whatever
interval the owner sets once the RPO/RTO are agreed.

## 7. Rollback

Application rollback is redeploying the previous image or commit. It is safe
only while the schema is compatible with both versions.

1. Identify the previous known-good revision.
2. Confirm the schema it expects. If the newer release added a migration, the
   older code must still work against the migrated schema — additive migrations
   are safe, a dropped or renamed column is not.
3. Redeploy the previous artefact.
4. Rebuild assets (`npm run build`) if the frontend changed.
5. `docker compose restart queue scheduler` so workers pick up the old code.
6. Re-check the health endpoints (section 9).

If a migration is genuinely incompatible, the procedure is restore from backup
(section 6), not `migrate:rollback` against live data.

Prefer additive migrations so that rollback stays a code-only operation. That is
why enum widening is a separate migration from the table that owns the column.

## 8. Audit evidence

The audit log is append-only: database triggers reject `UPDATE` and `DELETE` on
`audit_events` even when code bypasses Eloquent, so there is no procedure for
correcting it. Evidence leaves the system by export, never by edit.

```text
Administration → Security → Audit log → Download CSV
GET /admin/exports/audit-events.csv[?from=<ISO 8601>&to=<ISO 8601>]
```

- Administrators only, and only active accounts. Operators and editors receive
  403; the panel does not show them the log at all.
- `from` and `to` are read as UTC, matching both the stored values and the
  exported `occurred_at_utc` column. Omit them to export everything.
- Every export is itself recorded as an `audit_exported` event naming the actor
  and the window, and is bounded so it never contains its own entry. Two exports
  of the same window return the same rows.
- The file is language-neutral: the columns are stored machine codes, so an
  export taken in Tajik and one taken in English are byte-identical.
- Rows stream in id order in bounded chunks, so a log of any size exports in
  constant memory.

Treat a downloaded file as production data: it names administrators by e-mail
and contains the before/after payload of every recorded change.

## 8a. User accounts

```text
Administration → Identity → User accounts
```

Administrators only. Create an account with a name, an e-mail address, one of
the three roles and an initial password, then pass that password to the person
over a channel you trust — the portal sends no e-mail, and there is no
self-service reset.

What the panel will refuse, and why:

| Refused | Reason |
| --- | --- |
| Deactivating or demoting the last active administrator | It is the only remaining way into the panel |
| Deactivating yourself, or changing your own role | You would lose access mid-session; ask a colleague |
| Deleting any account | Accounts are deactivated instead, so their audit history keeps its actor |

### The first administrator

**There is no default administrator, and no account is seeded.** The first one
is created deliberately, on the server, by a command that works only while the
`users` table is completely empty:

```bash
docker compose exec app php artisan users:bootstrap-administrator
```

It asks for a name, an address, a password and its confirmation. The password is
typed hidden and cannot be passed as an option, so it never reaches the shell
history or the process list; it must satisfy the same policy as the panel form
(at least 12 characters, with letters and digits). The command creates one
active `administrator`, records an `identity.user.created` event — with no actor,
because the account being created is the first one there is — and does both in a
single transaction, so a failure leaves no account behind.

Run it once. It refuses if any account already exists, including the one it just
made, and refuses before asking anything, so a password is never typed into a run
that was going to be rejected. Every account after the first is created in the
panel.

If two people run it at the same moment on the same database, one of them waits
for the other and is then refused: the command takes a database lock before it
checks whether the table is empty, so two simultaneous runs produce one
administrator, never two.

Create a second administrator early. With one, that account can no longer be
deactivated or demoted by anyone, including itself.

### When someone's access changes

Deactivating an account, changing its role or changing its password ends the
sessions that account already has: the next page the person opens takes them to
`/admin/login`, rather than the change waiting for their next sign-in.

It works like this, and it is worth knowing because the mechanism is not what
you might expect: the account carries a version number, each session records the
version it was opened against, and every authenticated request compares the two.
A session opened before the change carries the older number and is signed out on
the spot. Nothing is deleted from the session store to make this happen, and no
session store is searched — which is what makes it work identically on Redis
(the configured default), the database, files and in memory.

Three consequences to expect:

- The change lands on the person's next request, not the same instant. There is
  no active push to an open browser tab.
- Letting someone back in does not restore the sessions they had before: the
  version only moves forward, so a reactivated account has to sign in again.
- The revoked session's stored data stays in the session store until the
  driver's own lifetime expires it (`SESSION_LIFETIME`, and the driver's garbage
  collection). That is expected: it cannot be used to reach anything, and the
  portal deliberately does not reach into the session store to tidy it.

Every account change is written to the audit log
(`identity.user.created`, `identity.user.updated`,
`identity.user.credentials_changed`). A password change is recorded as having
happened and nothing more: no password, hash, confirmation, token or session
content is ever stored there.

**REQUIRES OWNER INPUT**: the approved role matrix and the list of people who
should hold each role (`docs/08-hydromet-input-checklist.md`, section 6), and
SMTP if password-reset e-mail is wanted.

## 8b. A dependency audit failed

```bash
composer security         # locked PHP tree: any advisory or abandoned package
npm run audit:production  # runtime JS: moderate and above
npm run audit:all         # runtime and dev JS: high and above
```

The same commands run in `.github/workflows/dependency-security.yml`, on every
pull request and push to `master`, weekly, and on demand. A weekly failure on a
branch nobody touched is normal and expected: advisories are published against
code that has not changed.

A pull request is additionally checked by `actions/dependency-review-action`,
which blocks a dependency it **adds** at `moderate` and above in every scope —
`runtime`, `development` and `unknown` — with the vulnerability check on and
`warn-only` off. Its finding is triaged the same way as an audit finding, with
one difference: the dependency is not in the tree yet, so the cheapest fix is
usually to choose a different version, or a different package, before merging.

Nothing repairs itself. There is no `npm audit fix` and no `composer update`
anywhere in the scripts or the workflow, because both rewrite a lock file
without anyone reading the result. Work through it instead:

1. **Read the finding.** Note the package, the advisory ID (`GHSA-…`, `CVE-…`),
   the severity and the vulnerable version range. The audit output names all
   four.
2. **Direct or transitive?** `composer why <package>` and `npm ls <package>`
   answer it. A direct dependency is yours to update. A transitive one is
   usually fixed by updating the package that pulls it in — which is a different
   change, with different risk.
3. **Runtime or development?** A development-only package cannot be reached by a
   request, so it is a smaller problem — but not a non-problem: it runs on
   developer machines and in CI, where it can see source and credentials.
4. **Find the smallest safe version.** The advisory states the first fixed
   release. Prefer the nearest patch that clears it over the newest release
   available.
5. **Read the release notes** for everything between the current and the target
   version, and judge the regression risk before touching a lock file. A
   semver-major jump to clear a moderate advisory in a development tool is
   usually the wrong trade; say so rather than doing it quietly.
6. **Update deliberately**, one concern at a time — `composer update
   vendor/package --with-dependencies`, or `npm install package@version` — never
   a blanket update, and never `npm audit fix --force`, which will install a
   semver-major release to silence a warning.
7. **Re-run the whole gate**, not only the audit: `composer check`, `composer
   test:pgsql`, `npm run format:check`, `npm run lint`, `npm run types:check`,
   `npm test`, `npm run build`. A dependency bump is a code change.
8. **Commit the lock file with the reason.** The commit message should name the
   advisory, so the next person can see why the version moved.

If no safe version exists yet, that is a decision for the owner, not a threshold
to lower. Record what is exposed, whether the vulnerable code path is reachable
from this portal, and what compensating measure applies in the meantime.

**Exceptions.** An advisory may be excluded only on an explicit owner decision,
never to make a build green. The record must carry the advisory ID, the reason,
the compensating measures, the person who approved it and an expiry date, and it
must be reviewed on that date. **There are no exceptions today**: no
`config.audit.ignore` in `composer.json`, no `allow-ghsas` on the review action,
no ignore list anywhere — which is itself asserted by
`tests/Feature/Security/DependencyAuditPolicyTest.php`, along with the rest of
the policy.

**REQUIRES OWNER INPUT**: whether a finding blocks a release or only a merge, at
which severity, and who may approve an exception
(`docs/08-hydromet-input-checklist.md`, section 6). The thresholds in use today
are provisional.

## 9. Health checks

| Endpoint | Meaning |
| --- | --- |
| `GET /up` | Liveness. The framework responded. |
| `GET /health` | Readiness. Also checks the database and the cache store. |
| `GET /api/v1/system/status` | Whether the portal's copy of each external source is current. **Not** a health check: monitor `/up` and `/health`, not this. |

```bash
curl -fsS http://127.0.0.1:8080/up
curl -fsS http://127.0.0.1:8080/health
docker compose ps    # every service must report (healthy)
```

`/health` returns `{"status":"ok","checks":{...}}` and names the resolved
database and cache drivers. It deliberately exposes no hostname, credential or
upstream error.

Compose health checks: `nginx` fetches `/up`; `app` asks PHP-FPM to serve its
own status page, so a pool that is listening but unable to answer is reported
unhealthy; `queue` and `scheduler` check their process is alive; `postgres` uses
`pg_isready`; `redis` uses `PING`.

`/api/v1/system/status` reports one entry per **enabled** source, so it is empty
today: the fixture source is deliberately disabled and no real source exists.
Once a source is enabled it reports `unknown` until an approved
`stale_after_seconds` is entered for it — the portal will not call a source
healthy on a threshold nobody approved. Set the value in the database; the
read-only panel shows it under Integration sources with the difference from the
polling interval spelled out.

**REQUIRES OWNER INPUT**: monitoring recipients and incident contacts. Nothing
currently alerts a human when a check fails.

## 10. What the VPS owner must supply

None of these can be inferred from the repository, and each blocks first
deployment (`docs/08-hydromet-input-checklist.md`, section 6).

| Value | Used for | Notes |
| --- | --- | --- |
| Browsers the portal must support | `CSP_STYLE_NONCE` | Decides whether `style-src` can use a nonce. See below. |
| Domain / subdomain and DNS control | `APP_URL`, TLS issuance | |
| TLS certificate strategy | HTTPS termination | The development Compose file terminates no TLS. |
| `APP_KEY` | Encryption and session integrity | Generate on the host with `php artisan key:generate`. Never reuse the development key, never commit it. |
| `DB_PASSWORD` | PostgreSQL | Generated per environment, stored in the secret store, never in the repository. |
| `REDIS_PASSWORD` | Redis | Required in production; the development stack runs without one. |
| SMTP credentials | Password reset, notifications | Only if email is required. |
| External backup destination and credentials | Section 5 | Must be off this VPS. |
| Monitoring recipients | Section 9 | |
| Maintenance window, RPO, RTO | Sections 3, 5, 6 | |
| Production data access policy | Who may read a database dump | |
| User list and approved role matrix | Filament roles | The current matrix is a documented provisional least-privilege placeholder. |

`CSP_STYLE_NONCE` is the one security setting with a compatibility cost. Left
off (the default), styles run on `style-src 'self' 'unsafe-inline'`, which works
everywhere. Turned on, `style-src` takes the same per-request nonce as
`script-src`, and the policy additionally sends `style-src-attr 'unsafe-inline'`
so the inline style attributes Leaflet writes on every map pane still apply.
That companion is a CSP Level 3 directive: a browser that has not implemented it
ignores it, checks the style attributes against `style-src` instead, and the map
renders as a blank box. It was verified working in Chrome; before turning it on,
check the browsers the audience actually uses, then load the station map, a
station's charts and the language menu in each of them and confirm the console
reports no CSP violation. `script-src` always carries the nonce and needs no
such decision.

Secrets reach the containers as environment variables. They are never committed,
never written into `integration_sources` (that table has no credential column by
design), and never logged — a failed synchronization records a stable error code
and safe sentence, and the exception itself reaches neither the journal nor the
log.

## 11. Production deployment gaps

`compose.yaml` is a **development** environment: it bind-mounts the working copy,
serves host-built assets and publishes database ports on loopback. It is not a
deployable artefact.

Before a first release the following must exist and be rehearsed:

- a production image that copies the application in, runs
  `composer install --no-dev --optimize-autoloader`, and includes the output of
  `npm run build`, instead of mounting sources;
- a `compose.prod.yaml` override with no bind mounts, no published PostgreSQL or
  Redis ports, pinned image tags and restart policies;
- TLS termination, `APP_DEBUG=false`, and cached config, routes and views;
- the backup destination and a completed restore rehearsal (section 6).
