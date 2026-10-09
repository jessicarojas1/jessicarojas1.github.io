# VERITY — Disaster Recovery

## What holds state

Exactly **one** PostgreSQL database. There is no other durable store:

- No file storage — logos are `data:` URLs stored as text inside the
  `app_config` table, never written to disk.
- No cache tier, no queue, no search index, no object store.
- Sessions are server-side, cookie-referenced (`VERITY_SID`) PHP sessions.
  They are **not independently durable**, and that's acceptable: a lost
  session only forces a re-login, it never loses governance data. (It is
  also, today, a constraint on running more than one app instance — see
  "High availability" below.)

If the Postgres instance is lost with no backup, every identity record,
account/entitlement inventory, correlation decision, dynamic field value,
saved view, IAM grant, branding setting, and audit event is lost with it.

## RPO / RTO targets

No high-availability or automated-backup infrastructure exists for this app
today (see `docs/DEPLOYMENT.md`'s production checklist). The targets below
are honest, conservative proposals given that baseline — not a description
of something already built:

| Target | Proposed value | Basis |
|---|---|---|
| **RPO** (Recovery Point Objective) | Time since the last backup/snapshot | With no continuous backup configured yet, this is only as good as however often an operator runs `pg_dump`, or whatever interval a managed Postgres provider's point-in-time recovery (PITR) window supports once enabled |
| **RTO** (Recovery Time Objective) | Time to provision a replacement database + restore + redeploy the app container | Realistically on the order of tens of minutes to a few hours for a single-instance deployment, dominated by database restore time and container redeploy, not by application complexity (the app itself is one stateless PHP process) |

These targets should be reassessed once automated backups and a tested
restore runbook exist (see "Decisions required" in `OPEN_ITEMS.md`).

## Backups

**Not yet automated in this build — this is an open item, not a completed
control.** Two viable paths, neither configured today:

1. **Managed Postgres point-in-time recovery (PITR).** If the production
   target is a managed Postgres offering (Render's managed database, AWS
   RDS, Azure Database for PostgreSQL, etc.), enable its built-in automated
   backups/PITR and set a retention window appropriate to the RPO above.
   This is the lower-effort path and the one to prefer.
2. **Scheduled `pg_dump`.** For a self-hosted Postgres instance (single
   Linux server, Kubernetes with a stateful Postgres, air-gapped), schedule
   `pg_dump` via cron (or the platform's equivalent) to a separate durable
   location — a different disk, object storage, or an offline medium for
   air-gapped targets — and retain enough generations to meet the RPO.

Either way: verify the backup artifact is restorable (see "Verification
cadence" below) — an unverified backup is not a backup.

## Restore runbook

Numbered, copy-pasteable steps. Adjust connection details to the actual
target; the sequence itself is target-agnostic.

1. **Provision a new PostgreSQL instance** (or confirm the existing instance
   is healthy, if this is a partial-data-loss scenario rather than a
   full-instance loss).
   ```bash
   # Managed provider: create via its console/CLI.
   # Self-hosted: initialize a fresh PostgreSQL 14+ instance.
   ```
2. **Restore the most recent backup** into it:
   ```bash
   # From a pg_dump custom-format backup:
   pg_restore -d "$NEW_DATABASE_URL" /path/to/latest.dump
   # — or, for a managed provider's PITR, use its console/CLI to restore
   #   to a point in time into a new instance.
   ```
3. **Apply any schema changes made since that backup was taken.** Diff the
   backup's known schema version against the current
   `database/schema.sql` in version control, then re-run the full idempotent
   script (safe even if most of it is already present):
   ```bash
   psql "$NEW_DATABASE_URL" -f database/schema.sql
   ```
4. **Point the application at the restored database:**
   ```bash
   # Update DATABASE_URL in the deployment target's environment/secret store
   # to $NEW_DATABASE_URL.
   ```
5. **Redeploy the application container** against the updated
   `DATABASE_URL` (e.g. trigger a Render deploy, roll a Kubernetes
   deployment, or restart the systemd service on a single Linux server —
   see the relevant `../deployments/*.md` guide for the exact command).
6. **Verify** (see the checks in `docs/DEPLOYMENT.md`'s "Verification"
   expectations and the smoke checks below) before declaring recovery
   complete:
   - `GET /health` returns `{"status":"ok", ..., "db": true}`.
   - Sign in with a known account succeeds.
   - A read-heavy page (`/app/matrix`) returns the expected row count.
   - A write path (e.g. Settings → Branding save) succeeds and the change
     persists across a reload.
7. **Resume normal backup scheduling** against the new instance immediately
   — a freshly restored database is not yet covered by the next scheduled
   backup until that schedule is reattached to it.

## Verification cadence

**No restore drill has been performed yet for this application.** A
quarterly restore drill — executing the runbook above against a disposable
target and confirming the smoke checks pass — is recommended once automated
backups exist. Until a drill has actually been run and recorded, treat the
backup/restore story as *unverified in practice*, even after the automation
in "Backups" above is configured.

## High availability

**None today.** A single application instance talks to a single database
instance; there is no failover, no read replica, and no multi-region
story. Two specific constraints to plan around before attempting horizontal
scaling of the app tier:

- **Sessions are in-process/per-instance.** PHP's native session handler
  (file-based by default) is local to whatever instance handled the login.
  Running more than one app replica behind a load balancer today would
  cause intermittent forced re-logins as requests land on a different
  instance than the one holding that session — this needs a shared session
  store (e.g. database- or Redis-backed sessions) before horizontal scaling
  of the app tier is viable.
- **The database is the single point of failure regardless of app-tier
  scaling.** Scaling the app tier alone does not add resilience; a managed
  Postgres with automated failover (or a self-managed replica + failover
  procedure) would be required to remove the database as a single point of
  failure, and none is configured today.
