# CITADEL — Disaster Recovery & Business Continuity

How to recover CITADEL after data loss, corruption, or a region outage. Pairs
with [DEPLOYMENT.md](DEPLOYMENT.md) and [SECURITY.md](SECURITY.md).

> **Design reality — CITADEL is largely stateless by design.** Scanning is
> request-driven and ephemeral: uploaded code is read, scanned, and deleted; no
> scan artifact is retained by default. What *does* hold state is small and
> rebuildable. This makes DR simple, but the **free-tier / no-database mode has
> no durable state at all** — see the caveat below.

---

## 1. What holds state

| State | Where it lives | Durable? | Rebuildable? |
|---|---|---|---|
| **User store** — accounts, roles, permission overrides, password hashes, MFA seeds | Postgres `citadel_users` (if `DATABASE_URL`) **or** JSON file under `CITADEL_DATA_DIR` **or** in-memory | Only with Postgres or a persisted `CITADEL_DATA_DIR` | Partially — recreate admins from SSO/IdP; local users must be re-provisioned |
| **Sessions & revocations** | `citadel_sessions`, `citadel_revoked` (or in-memory) | With Postgres | Yes — users re-authenticate; revocations are safety, not business data |
| **Scan history & reports** | `citadel_scans` (report JSON + gate decision + score) | With Postgres | Yes — re-scan the source (reports are derived, not source-of-truth) |
| **Triage / dispositions** | `citadel_dispositions`, `citadel_dep_approvals`, `citadel_threatmodel`, `citadel_projects` | With Postgres | No — human decisions; **must** be backed up |
| **App settings & branding** | `citadel_settings` | With Postgres | Yes — re-enter |
| **Audit log** | `citadel_audit` (hash-chained) + optional external SIEM sink | With Postgres and/or SIEM | No — compliance evidence; **must** be preserved |
| **Uploaded artifacts** | Per-scan tmpfs workdir under `CITADEL_TMP` | **No — deleted after every scan by design** | N/A (never retained) |
| **Scanner signature DBs** | ClamAV / Trivy / Grype DBs in the container/volume | Ephemeral | Yes — re-download (`freshclam`, `trivy --download-db-only`, `grype db update`) or restore from an update bundle (air-gapped) |
| **Secrets** | Secret manager / env (`CITADEL_JWT_SECRET`, `CITADEL_DATA_KEY`, `ANTHROPIC_API_KEY`, OIDC) | Managed externally | Yes — from the secret manager |

> **Free-tier / ephemeral-store caveat.** With **no `DATABASE_URL` and no
> persisted `CITADEL_DATA_DIR`** (e.g. Render free tier, plain container), the
> user store, sessions, scan history, triage, and audit log are **in-memory and
> reset on every restart/redeploy**. This is acceptable for demos and CI gating
> but is **not** a durable posture. For any environment where losing users,
> history, or audit matters, provision **Postgres** (and back it up). Setting a
> stable `CITADEL_JWT_SECRET` only preserves session *validity* across restarts,
> not the data.

---

## 2. RPO / RTO targets

| Deployment | RPO (max data loss) | RTO (time to restore) |
|---|---|---|
| Postgres-backed, automated backups | ≤ 24 h (or ≤ 5 min with PITR / WAL archiving) | ≤ 1 h (redeploy image + restore DB) |
| Postgres-backed, multi-AZ replica | ~0 (sync/near-sync replica) | ≤ 15 min (failover) |
| File-store (`CITADEL_DATA_DIR` on a volume) | = last volume snapshot | ≤ 1 h |
| Ephemeral / free-tier (no DB) | **N/A — no durable state** | ≤ 15 min (redeploy; re-provision users, re-scan) |

The **container image and IaC are the recovery unit** for compute — no golden
disk to restore; rebuild from the image + config + secret manager.

---

## 3. Backups

| Item | How | Where | Retention | Encryption |
|---|---|---|---|---|
| Postgres | `pg_dump` nightly + PITR/WAL archiving (managed: RDS/Cloud SQL/Azure DB automated backups) | Object storage in the same partition/region (S3 / Blob / GCS), cross-region copy for DR | 30–90 days (align to audit retention policy) | At rest (KMS/Key Vault/CMEK) + in transit (TLS) |
| File store (`CITADEL_DATA_DIR`) | Volume snapshot | Encrypted snapshot store | 30 days | Volume/KMS encryption |
| Audit log | Preserve `citadel_audit` in the DB backup **and** forward live to SIEM (`CITADEL_AUDIT_SINK_URL`) | DB + SIEM (two copies) | Per compliance mandate (often ≥ 1 yr) | KMS + SIEM controls |
| Secrets | Secret manager's own backup/versioning | Secret manager | Per policy | Native |
| Scanner DBs | **Not backed up** — re-downloaded, or shipped as an offline update bundle for air-gapped installs | — | — | — |

Verify backups are encrypted at rest and that KMS/key material is itself
recoverable (a backup you cannot decrypt is not a backup).

---

## 4. Restore runbook

```bash
# ── Prerequisites ──────────────────────────────────────────────────────────
#  • Access to the secret manager (CITADEL_JWT_SECRET, CITADEL_DATA_KEY, OIDC…)
#  • The target CITADEL image tag that was running (or a known-good one)
#  • The latest good Postgres backup (or PITR target time)

# 1. Provision / confirm a clean Postgres instance and network reachability.
psql "$DATABASE_URL" -c 'select 1;'

# 2. Restore the database.
#    Managed (RDS/Cloud SQL/Azure DB): restore-to-point-in-time from the console/CLI.
#    Self-managed dump:
pg_restore --clean --if-exists --no-owner -d "$DATABASE_URL" citadel-YYYYMMDD.dump
#    (or)  psql "$DATABASE_URL" < citadel-YYYYMMDD.sql

# 3. Re-apply the idempotent schema so any new columns exist (safe on a restore).
psql "$DATABASE_URL" -f citadel/database/schema.sql

# 4. Restore secrets into the runtime from the secret manager (do NOT hardcode).
#    Ensure CITADEL_JWT_SECRET and CITADEL_DATA_KEY are the SAME values used
#    before the incident — a changed CITADEL_DATA_KEY cannot unseal existing
#    JWT-secret/TOTP material and would lock out MFA users.

# 5. Redeploy the CITADEL container/image with DATABASE_URL + secrets set.
docker run -d --env-file citadel.env -p 8080:8080 <registry>/citadel-server:<tag>

# 6. Re-hydrate scanner signature DBs (they are not part of any backup).
freshclam ; trivy --download-db-only ; grype db update    # or restore the air-gap bundle

# 7. Verify (see §5 and DEPLOYMENT.md §9).
curl -fsS http://localhost:8080/api/health | jq '{ok, store}'
```

> If restoring **file-store** state instead of Postgres: restore the
> `CITADEL_DATA_DIR` volume snapshot and skip steps 2–3.

---

## 5. Restore verification

After a restore, confirm:

- [ ] `GET /api/health` → `ok:true`, `store.durable:true`, scanners `available`.
- [ ] An admin can log in and `GET /api/auth/me` resolves (JWT secret unsealed).
- [ ] `GET /api/scans` returns pre-incident history; triage/dispositions present.
- [ ] `GET /api/audit/verify` confirms the audit **hash-chain is intact** (no
      tamper gap across the restore).
- [ ] A fresh `POST /api/scan` produces a graded report and lands in
      `citadel_scans`.
- [ ] MFA-enabled users can still complete MFA (proves `CITADEL_DATA_KEY` matches).

---

## 6. Verification cadence (restore drills)

| Activity | Cadence |
|---|---|
| Backup integrity check (restore into a scratch DB, run §5) | Monthly |
| Full DR drill (redeploy from image + restore + verify) | Quarterly |
| Audit-chain verification (`/api/audit/verify`) | Weekly + after any restore |
| Secret-manager recovery test (rotate + confirm unseal) | Semi-annually |
| Scanner-DB refresh + air-gap update-bundle rebuild | Weekly / per release |

Record each drill (date, RPO/RTO achieved, gaps) as evidence.

---

## 7. High availability

- **Compute:** stateless containers — run **≥ 2 replicas** behind a load balancer;
  `/api/health` drives readiness. Use `REDIS_URL` so rate-limit/lockout state is
  shared (otherwise limits are per-replica). Add a **PodDisruptionBudget** and
  HPA on Kubernetes (see [`../deploy/kubernetes/`](../deploy/kubernetes/)).
- **Database:** managed **multi-AZ** Postgres with automated failover and a
  read replica; enable PITR/WAL archiving for near-zero RPO.
- **Cross-region DR:** copy DB backups + the image to a second
  region/partition (Commercial ↔ GovCloud requires re-tooling, not a copy);
  keep IaC ready to stand up the standby.
- **Scratch space is not HA-relevant** — it is per-request tmpfs and never shared.
- **Air-gapped HA:** replicate the private registry + DB within the enclave;
  scanner-DB update bundles distributed on the enclave's normal media pipeline.
