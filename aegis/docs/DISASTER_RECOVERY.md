# AEGIS GRC — Disaster Recovery & Business Continuity

> Audience: operators responsible for backing up, restoring, and failing over an
> AEGIS deployment. Every claim is grounded in the actual code / config
> (`Dockerfile`, `docker-compose.yml`, `render.yaml`, `install.php`,
> `database/`, `src/Storage.php`, `src/Security.php`). Where a control is a
> manual operator responsibility (AEGIS ships no built-in backup daemon), that is
> called out explicitly.

See also: [`DEPLOYMENT.md`](DEPLOYMENT.md), [`SECURITY.md`](SECURITY.md),
[`ARCHITECTURE.md`](ARCHITECTURE.md), and the target guides under
[`../deployments/`](../deployments/).

---

## Table of Contents

1. [What Holds State](#1-what-holds-state)
2. [RPO / RTO Targets](#2-rpo--rto-targets)
3. [Backups](#3-backups)
4. [Restore Runbook](#4-restore-runbook)
5. [Verification & Restore Drills](#5-verification--restore-drills)
6. [High Availability](#6-high-availability)
7. [Failure Scenarios & Response](#7-failure-scenarios--response)

---

## 1. What Holds State

AEGIS is a stateless PHP application container in front of two stateful stores.
Recovering AEGIS means recovering these — the app image itself is rebuildable
from source and holds no data.

| State store | What lives there | Where | Rebuildable? |
|---|---|---|---|
| **PostgreSQL 16** | Everything transactional: users, RBAC grants (`user_permissions`), risks, compliance packages, controls, audits, findings, policies, incidents, vendors, evidence metadata + SHA-256 hashes, the **hash-chained `activity_log`** audit trail, `settings` (incl. encrypted SMTP/S3/AI keys), `active_sessions`, `php_sessions` (if `SESSION_DRIVER=pg`), `rate_limits`, `ai_inference_log` | Managed PG (Render `aegis-db` / RDS / Azure DB) or the `db` container volume `pg_data` | **No** — authoritative source of truth |
| **Object / file storage** | Uploaded evidence & document **binaries** (`uploads/evidence`, `uploads/documents`) — the DB stores only path + hash + metadata | Local disk (`STORAGE_DRIVER=local`, compose volume `uploads_data`) **or** S3/MinIO/R2 (`STORAGE_DRIVER=s3`) | **No** — file bytes exist only here |
| **Secrets / keys** | `JWT_SECRET`, `AUDIT_HMAC_KEY`, `APP_ENCRYPTION_KEY`, DB/SMTP creds, `ADMIN_*` | Env vars / `*_FILE` mounts / Render env group `aegis-secrets` / KMS ciphertext | **No** — losing these is unrecoverable (see warning below) |
| **Config** | `render.yaml`, `docker-compose.yml`, K8s manifests, env values | Git + secret store | **Yes** (Git) |
| **Logs** | Plain-text stdout/stderr (`[AEGIS]` prefixed), `logs/` volume | Container stdout / log aggregator | **No** but non-critical for RTO |

> **Critical key-loss warning.** `APP_ENCRYPTION_KEY` (or the `JWT_SECRET` it
> derives from) decrypts the `settings` table's SMTP/S3/AI secrets
> (`src/Security.php` AES-256-GCM, `enc:` prefix). `AUDIT_HMAC_KEY` (or its
> `JWT_SECRET` fallback) keys the audit hash chain. **If you restore the database
> but lose these keys, encrypted settings become unreadable and the audit chain
> can no longer be verified.** Back up keys with the *same* rigor as the
> database, in a *separate* trust domain (secret manager / KMS / offline escrow) —
> never in the DB backup itself.

---

## 2. RPO / RTO Targets

RPO (max acceptable data loss) and RTO (max acceptable downtime) depend on the
deployment tier. AEGIS does not enforce these; they are a function of the backup
cadence and topology you choose.

| Tier | Topology | Suggested RPO | Suggested RTO | How it's achieved |
|---|---|---|---|---|
| Dev / demo | Single `docker-compose`, local uploads | 24 h | Hours | Nightly `pg_dump` + `uploads/` tar |
| Single Linux server | 1 VM, nginx/TLS, local or S3 uploads | 1–6 h | < 1 h | Hourly `pg_dump` (or WAL archiving) + S3 for uploads |
| Managed PaaS (Render) | `aegis-grc` + managed `aegis-db` | Provider-defined (Render daily PITR on paid plans) | < 30 min | Managed DB backups; redeploy image from `main` |
| Cloud (RDS / Azure DB) | Fargate/AKS + managed PG + S3/Blob | **≤ 5 min** (PITR / WAL) | < 30 min | Multi-AZ PITR + versioned object storage |
| Air-gapped / IL5 | Self-hosted PG + MinIO | Per site policy (WAL + object snapshots) | Per site policy | Site backup infra + offline restore bundle |

**Fixed recovery-time components** (independent of tier):

- Container cold start + `scripts/startup.sh` running `install.php` (idempotent —
  applies schema + all 36 migrations; a no-op against an already-migrated DB):
  seconds to low minutes.
- Health gate: traffic only routes once `/healthz` is green (Render
  `healthCheckPath`, Docker `HEALTHCHECK`, K8s liveness probe).

---

## 3. Backups

> AEGIS ships **no built-in backup scheduler**. Backups are an operator
> responsibility, wired to your platform's native tooling. The three things to
> back up are: **(1) the database, (2) upload binaries, (3) the keys.**

### 3.1 Database — `pg_dump` (logical) or WAL/PITR (physical)

**Logical dump (portable, cross-version):**

```bash
# Full logical backup of the aegis database (schema in the `aegis` schema).
pg_dump "$DATABASE_URL" \
  --format=custom --compress=9 --no-owner --no-privileges \
  --file "aegis-$(date +%Y%m%dT%H%M%SZ).dump"
```

- Use `--format=custom` so restore can be parallelized (`pg_restore -j`).
- Encrypt the artifact at rest (e.g. `age`/`gpg` or SSE on the backup bucket) —
  the dump contains user rows, the audit log, and the **encrypted** settings.
- Retention suggestion: 7 daily / 4 weekly / 12 monthly. Match your compliance
  retention (CUI / legal-hold deployments often require longer).

**Physical / PITR (low RPO):** on managed Postgres (RDS, Azure Database for
PostgreSQL, Render paid), enable **automated backups + WAL archiving / PITR**.
This gives an RPO measured in minutes and is preferred for production. Self-hosted
PG: archive WAL (`archive_mode=on`, `archive_command=...` to object storage) plus
periodic base backups (`pg_basebackup`).

### 3.2 Upload binaries

| `STORAGE_DRIVER` | What to back up | How |
|---|---|---|
| `local` (default) | `uploads/` (compose volume `uploads_data`, or the host path) | `tar czf uploads-<ts>.tgz uploads/` on the same cadence as the DB dump, or snapshot the volume/disk |
| `s3` | The configured `s3_bucket` (evidence/document objects) | Enable **bucket versioning** + lifecycle; optionally cross-region replication (CRR). No separate job needed |

> **Consistency:** the DB row (path + SHA-256) and the object must be backed up
> as a pair. On restore, `Storage` reads paths from the DB; an object missing
> from storage yields a broken download, and the recorded SHA-256 lets you detect
> corruption. Prefer taking the upload snapshot **immediately after** the DB dump.

### 3.3 Keys & secrets

Back up (in a secret manager / KMS / offline escrow, **not** in the DB dump):
`APP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, `JWT_SECRET`, DB credentials, `SMTP_*`.
If you use KMS envelope encryption (`KMS_PROVIDER=vault|exec`), back up the
`APP_ENCRYPTION_KEY_CIPHERTEXT` **and** ensure the KMS key/root remains available
in DR (replicate the KMS key to the DR region).

### 3.4 Config

`render.yaml`, `docker-compose.yml`, K8s manifests, and `.env.example` live in
Git. The **values** for `sync: false` vars (e.g. `APP_URL`, `ADMIN_EMAIL`) and
all secrets live in your secret store — document them in your runbook.

---

## 4. Restore Runbook

Copy-pasteable, numbered. Assumes a logical dump; PITR variants noted inline.

### 4.1 Restore the database

```bash
# 1. Provision a fresh, EMPTY PostgreSQL 16 target and export its URL.
export DATABASE_URL='postgres://OWNER_USER:PASS@NEW_HOST:5432/aegis'

# 2. Ensure the target database + aegis schema exist (install.php also does this,
#    but pg_restore expects the database to exist).
psql "$DATABASE_URL" -c "CREATE SCHEMA IF NOT EXISTS aegis;"

# 3. Restore the custom-format dump (parallel, continue past benign errors).
pg_restore --dbname "$DATABASE_URL" --no-owner --no-privileges \
  --jobs 4 aegis-<TIMESTAMP>.dump
#   PITR alternative: restore the managed snapshot / base backup and replay WAL
#   to the target recovery point instead of steps 2-3.
```

### 4.2 Restore upload binaries

```bash
# local driver: extract into the uploads volume/host path used by the app.
tar xzf uploads-<TIMESTAMP>.tgz -C /var/www/html/     # yields uploads/evidence, uploads/documents
chown -R www-data:www-data /var/www/html/uploads
chmod 750 uploads uploads/evidence uploads/documents

# s3 driver: nothing to copy if the bucket survived. If restoring to a NEW bucket,
# sync the versioned objects, then point s3_bucket (in Settings) at it.
aws s3 sync s3://OLD_BUCKET s3://NEW_BUCKET     # commercial
# GovCloud: add --region us-gov-west-1 and use the aws-us-gov partition bucket.
```

### 4.3 Restore keys, then boot the app

```bash
# 4. Restore the SAME keys the data was created with (or encrypted settings and
#    the audit chain will not verify). Provide via env or *_FILE mounts.
export JWT_SECRET='...'                 # >= 32 chars
export APP_ENCRYPTION_KEY='...'         # decrypts settings (SMTP/S3/AI)
export AUDIT_HMAC_KEY='...'             # verifies the audit hash chain
export APP_URL='https://grc.example.gov'
export APP_ENV='production'

# 5. Run the idempotent installer once (creates schema/migrates if the restore was
#    partial; a no-op on a fully-restored DB). ADMIN_* only used on a truly fresh DB.
php install.php

# 6. Start the app (or redeploy the image). On Render: push to main / redeploy.
#    On k8s/compose: bring the app up; it self-migrates via startup.sh.
```

### 4.4 Post-restore verification (do this every time)

```bash
# a. Liveness / readiness.
curl -fsS https://APP_URL/healthz && curl -fsS https://APP_URL/readyz

# b. Audit chain integrity (MUST be INTACT — exit 0). Requires AUDIT_HMAC_KEY.
php scripts/verify_audit_log.php            # exit 0 = intact, 1 = tampered/broken, 2 = config

# c. Secrets resolved: confirm encrypted settings decrypt (no `enc:`-looking
#    garbage in the UI). Load Settings and confirm SMTP/S3/AI show real values.

# d. Login works: authenticate as the admin (ADMIN_EMAIL) — proves password
#    hashes + sessions restored.

# e. Upload + object round-trip: upload a test evidence file, confirm the DB row
#    (path + sha256) is written and the object is retrievable (download matches
#    hash). For s3, confirm the object exists in the bucket.

# f. Row counts sanity check vs. pre-incident baseline (users, risks, activity_log).
```

If (b) fails with a chain break, restore is incomplete or the wrong
`AUDIT_HMAC_KEY` was supplied — do **not** accept the restore as clean; a genuine
break must be investigated as a tamper event.

---

## 5. Verification & Restore Drills

Backups that are never restored are not backups.

| Activity | Cadence | Success criteria |
|---|---|---|
| Backup job health (dump succeeded, object uploaded) | Every run (alert on failure) | Non-empty artifact, exit 0 |
| **Full restore drill** into an isolated environment | Quarterly (monthly for CUI/IL) | Steps 4.1–4.4 complete; `verify_audit_log.php` exits 0; test login + upload round-trip pass |
| Key-escrow test (can you retrieve `APP_ENCRYPTION_KEY`/`AUDIT_HMAC_KEY`?) | Quarterly | Keys retrieved from escrow; encrypted settings decrypt in the drill |
| RPO/RTO measurement | Each drill | Measured recovery time and data-loss window meet the tier targets in §2 |
| Object-storage integrity | Quarterly | Sampled evidence file SHA-256 matches the DB-recorded hash |

Record each drill (date, artifact used, measured RTO, pass/fail) — this is itself
audit evidence for BC/DR controls (e.g. NIST CP-4 contingency-plan testing) and
fits AEGIS's own BCP module.

---

## 6. High Availability

AEGIS is designed to scale horizontally; HA is achieved by the topology, not by
in-app clustering.

- **Stateless app tier.** The container holds no durable state, so you can run
  **N replicas** behind a load balancer. The one requirement for multiple
  replicas is **shared sessions**: set `SESSION_DRIVER=pg` so sessions live in the
  `php_sessions` table (`src/PgSessionHandler.php`, provisioned by migration 030)
  and any instance can serve any request. With the default file sessions, a user
  is pinned to one instance (use sticky sessions or a single replica).
- **Database HA.** Use a managed Multi-AZ Postgres (RDS Multi-AZ, Azure DB
  zone-redundant HA, Render's HA plans) or a self-managed primary + streaming
  replica with automated failover (Patroni/repmgr). The app reconnects on the
  next request; `Database::getInstance()` throws a catchable `RuntimeException`
  ('Database unavailable', 503) rather than crashing mid-output, so a brief
  failover degrades gracefully.
- **Object storage HA.** Prefer S3-class storage (multi-AZ by default) over local
  disk for any HA deployment — a local `uploads/` volume is a single point of
  failure and can't be shared across replicas.
- **Health probes for orchestration.** `/healthz` (liveness) and `/readyz`
  (readiness) drive rolling updates, load-balancer registration, and pod
  restarts. Configure PDB/HPA in Kubernetes (see
  [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md)).
- **Cron/worker singularity.** The background jobs (§ DEPLOYMENT) are idempotent
  and self-gate on what is due, but run them from **one** scheduler (Render `cron`
  services, one K8s `CronJob`, one compose `cron` container) — do not fan the same
  job across every app replica, to avoid duplicate sends.

There is no in-app leader election or clustering; HA relies entirely on the
external LB + managed data stores.

---

## 7. Failure Scenarios & Response

| Scenario | Impact | Response |
|---|---|---|
| App container crash / bad deploy | Downtime, no data loss | Orchestrator restarts (Docker `restart: unless-stopped` / K8s); or roll back the image tag. DB/uploads untouched |
| DB primary failure | Downtime until failover | Managed Multi-AZ auto-fails over; self-managed → promote replica. App reconnects next request |
| DB corruption / bad migration | Data integrity risk | Restore from last good dump/PITR (§4). Migrations are try/catch-tolerant, but restore is the safe path |
| Accidental data deletion | Partial data loss | PITR to just before the delete (managed PG), or logical restore into a staging DB and re-import the affected rows |
| Upload volume loss (`local`) | Evidence binaries gone (DB metadata survives) | Restore `uploads/` from snapshot (§4.2). **Prevention:** use `s3` driver with versioning for production |
| S3 bucket object deletion | Evidence binaries gone | Restore prior version (bucket versioning) or from CRR replica |
| **Key loss** (`APP_ENCRYPTION_KEY`/`AUDIT_HMAC_KEY`) | Encrypted settings unreadable; audit chain unverifiable | **Unrecoverable** for the affected data — recover keys from escrow. This is why keys are backed up separately (§3.3) |
| Audit chain break detected | Possible tampering | Investigate as a security event (`verify_audit_log.php` prints the first bad ID); correlate with `activity_log` + external logs; see [`SECURITY.md`](SECURITY.md) |
| Region outage (cloud) | Full site down | Fail over to DR region: restore DB (PITR/replica), point at replicated bucket, deploy image, set `APP_URL`, verify (§4.4) |

---

*All statements above are grounded in the repository: `Dockerfile`,
`docker-compose.yml`, `render.yaml`, `install.php`, `scripts/startup.sh`,
`scripts/verify_audit_log.php`, `src/Storage.php`, `src/Security.php`,
`src/PgSessionHandler.php`, `src/Database.php`, and `database/migrations/`.*
</content>
</invoke>
