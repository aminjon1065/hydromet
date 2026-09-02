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

## 9. Health checks

| Endpoint | Meaning |
| --- | --- |
| `GET /up` | Liveness. The framework responded. |
| `GET /health` | Readiness. Also checks the database and the cache store. |

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
