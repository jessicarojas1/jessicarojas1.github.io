# Sentinel QMS — Disaster Recovery

Backup, restore, and high-availability guidance for Sentinel QMS. Because the app
holds **CUI** and an **immutable audit trail**, recovery must preserve integrity
and provenance, not just availability.

Related: [`DEPLOYMENT.md`](DEPLOYMENT.md) · [`SECURITY.md`](SECURITY.md) ·
[`ARCHITECTURE.md`](ARCHITECTURE.md)

---

## 1. What holds state

| State | Where | Backup mechanism | Notes |
|-------|-------|------------------|-------|
| **Relational data** (all records, users, audit log, e-signatures) | PostgreSQL 16 (RDS / Azure DB for PostgreSQL) | Automated snapshots + PITR (WAL) | System of record; the audit log is append-only and must survive intact |
| **Uploaded files** (attachments, certificates, FAI evidence) | Object storage: S3 (SSE-KMS) / Azure Blob (CMK) / `local` | Cross-region replication + versioning | `local` is **not durable** — prod must use `s3`/`azure_blob` |
| **Secrets** | AWS Secrets Manager / Azure Key Vault | Managed replication + versioning | `JWT_SECRET`, DB creds, storage keys, SMTP/webhook secrets |
| **Config** | Env vars / IaC (`infra/terraform/`, Helm values) | Version control (no secrets committed) | Reproducible from Terraform + Helm |
| **Schema version** | Alembic `alembic_version` table (in Postgres) | Covered by DB backup | Restore must land on the matching image revision |

The application tier is **stateless** (containers) — recovery is DB + object
storage + secrets + redeploy of the pinned image.

---

## 2. RPO / RTO targets

| Tier | RPO (max data loss) | RTO (max downtime) | Basis |
|------|---------------------|--------------------|-------|
| **Production (Gov cloud)** | ≤ 5 min | ≤ 1 hour | PITR (WAL) + multi-AZ failover + object versioning |
| **Pilot / single server** | ≤ 24 h | ≤ 4 h | Nightly DB dump + object sync |
| **Demo (Render)** | Best-effort | Best-effort | Ephemeral; not a DR target |

Tune to the program's contractual continuity requirements; the numbers above are
the engineering defaults the managed-service configuration is sized for.

---

## 3. Backups

### PostgreSQL
- **Automated snapshots** — enable managed daily snapshots with **≥ 35-day**
  retention (RDS automated backups / Azure DB backups).
- **Point-in-time recovery** — enable WAL/transaction-log archiving so you can
  restore to any second within the retention window (drives the ≤ 5 min RPO).
- **Encryption** — snapshots encrypted with the same KMS/Key Vault CMK as the
  instance; in GovCloud use FIPS-validated KMS.
- **Logical dump (portability / off-cloud copy)**:
  ```bash
  pg_dump --format=custom --no-owner "$DATABASE_URL" \
    | gpg --encrypt --recipient dr@example.gov > sentinel-$(date +%F).dump.gpg
  ```

### Object storage
- Enable **versioning** + **cross-region replication** (S3 CRR to another GovCloud
  region / Azure GRS to a Gov paired region).
- Retain a lifecycle-managed cold copy; keep server-side encryption (SSE-KMS / CMK)
  on all copies.

### Secrets & config
- Secrets Manager / Key Vault versioning is the backup; export nothing to plaintext.
- IaC (`infra/terraform/`) and Helm values are the config backup — kept in VCS,
  **without** secrets.

Store backup copies **in-boundary** (same authorization boundary as CUI); never
export CUI-bearing backups to a commercial partition.

---

## 4. Restore runbook

> Restore onto the **same image revision** the backup was taken under, so the
> Alembic `alembic_version` matches. Run migrations only *forward* if intentionally
> upgrading.

1. **Freeze / isolate.** Stop the app tier (scale web replicas to 0) so nothing
   writes during recovery. Confirm the incident scope (DB, storage, or both).
2. **Provision / identify the DB target.** Restore the PostgreSQL instance:
   - PITR: `Restore to point in time` (RDS) / point-in-time restore (Azure DB) to
     just before the incident.
   - Snapshot: restore the latest good snapshot.
   - Off-cloud dump:
     ```bash
     gpg --decrypt sentinel-YYYY-MM-DD.dump.gpg > sentinel.dump
     pg_restore --no-owner --clean --if-exists -d "$DATABASE_URL" sentinel.dump
     ```
3. **Restore object storage** (if affected). Roll the bucket/container back to the
   pre-incident version, or fail over to the replicated region and repoint
   `S3_BUCKET`/`AZURE_STORAGE_CONTAINER`.
4. **Restore secrets** (if affected). Recover `JWT_SECRET`, DB creds, storage keys
   from Secrets Manager/Key Vault version history. **Do not** rotate `JWT_SECRET`
   unless compromise is suspected (rotating invalidates all live sessions).
5. **Verify schema alignment.** `alembic current` must equal the app image's head;
   run `alembic upgrade head` only if intentionally moving forward.
6. **Redeploy the app** pointed at the recovered DB/storage/secrets. Set
   `AUTO_MIGRATE=0` unless a forward migration is intended.
7. **Smoke test** (see [`DEPLOYMENT.md` §8](DEPLOYMENT.md#8-verification)):
   `/health` ok + DB connected → login → read a record → upload a file → confirm
   the attachment/object landed.
8. **Verify audit-trail integrity.** Confirm the audit log is continuous through
   the recovery point (query `/api/v1/audit-logs`); record the recovery as an
   incident entry.
9. **Unfreeze.** Scale web replicas back up; resume normal operations; notify
   stakeholders and log RPO/RTO actually achieved.

---

## 5. Verification cadence (restore drills)

| Activity | Frequency |
|----------|-----------|
| Automated backup success check (alerts on failure) | Daily |
| Restore drill to an isolated environment (DB + storage) | Quarterly |
| Full failover exercise (multi-AZ / cross-region) | Semi-annually |
| RPO/RTO re-validation against contract | Annually or on architecture change |

Each drill records: time-to-restore (RTO), data delta (RPO), and audit-trail
continuity. File results with the compliance evidence package
([`compliance/audit-readiness-checklist.md`](compliance/audit-readiness-checklist.md)).

---

## 6. High availability

- **Database** — deploy managed PostgreSQL **multi-AZ** with automatic failover;
  add read replicas if read scaling is needed. Failover is transparent to the app
  (it reconnects via `DATABASE_URL`).
- **Application** — run **≥ 2 replicas** across AZs behind the LB/ingress;
  Kubernetes **HPA** scales on load and a **PodDisruptionBudget** preserves quorum
  during node maintenance (`infra/kubernetes/`). Stateless containers ⇒ any replica
  can serve any request.
- **Object storage** — S3/Azure Blob are inherently multi-AZ; enable
  cross-region replication for regional-outage tolerance.
- **Rate limiter** — set `REDIS_URL` so the shared limiter (and its HA) matches
  the replica count; it degrades to per-process if the store is unreachable
  (never fails requests).
- **Scheduler** — safe to run in every replica (jobs claim work atomically), so
  there is no single point of failure for SLA sweeps / digests.
- **Probes** — liveness/readiness on `/health` route traffic only to healthy
  replicas and restart unhealthy ones.
