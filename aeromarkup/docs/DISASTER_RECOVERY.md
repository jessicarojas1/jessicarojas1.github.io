# AeroMarkup — Disaster Recovery

Operator runbook for backup, restore, and continuity of the AeroMarkup platform.

Related docs: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md)
· [SECURITY.md](SECURITY.md) · operator guides under
[`../deployments/`](../deployments/).

---

## 1. What Holds State

| Tier | What | Recovery implication |
| --- | --- | --- |
| **PRIMARY — PostgreSQL** | Every server-side record: users, programs, projects, drawings, layers, strokes, annotations, **attachments**, revisions, ncrs, inspections, inspection_items, approvals, comments, `audit_log`, `sync_log`. | Single durable system of record. **Uploads (reference images + STL/OBJ 3D models) are stored as data URLs *inside* Postgres — there is NO separate object storage — so a database backup captures all uploaded content.** |
| **SECONDARY — client IndexedDB** | Each field device holds a **local, offline-authoritative copy** of the data it works with, plus a queue of un-synced edits. | Devices keep working during a server/DB outage and **re-sync on reconnect**, cushioning both RPO and RTO. Enables best-effort DB reconstruction (§6). |
| **Secrets** | `AEROMARKUP_SECRET`, `DATABASE_URL` creds. | Stored in AWS Secrets Manager / Azure Key Vault / Render `generateValue`. Back these up / know how to regenerate. |
| **Configuration** | Env vars, `render.yaml`, `deploy/` IaC. | Version-controlled in the repo; redeployable. |

Because uploads live in Postgres and sessions are stateless, **the only stateful
component to protect is the PostgreSQL database.**

---

## 2. RPO / RTO Targets

| Metric | Suggested target | How achieved | Notes |
| --- | --- | --- | --- |
| **RPO** | ≤ 24h (baseline) / **≤ 5 min** (with PITR) | Daily managed snapshots for baseline; RDS automated backups + WAL / Azure Flexible Server PITR for near-continuous. | Offline-first means recent field edits still live in device IndexedDB and re-sync, further reducing *effective* data loss. |
| **RTO** | ~1h | Provision DB from snapshot + restart stateless app replicas. | App is stateless — no app-state restore needed; only DB + secrets + redeploy. |

> **Offline-first cushion:** during a database or server outage, field devices
> continue to operate against their local IndexedDB and queue edits. When
> service is restored they replay via `/api/sync`, so a short outage typically
> results in **zero operator-visible data loss**.

---

## 3. Backups

| Aspect | Detail |
| --- | --- |
| **What** | The entire `aeromarkup` schema (all tables incl. data-URL uploads in `attachments`). |
| **How** | Managed automated snapshots + PITR (**AWS RDS automated backups + WAL**, **Azure Database for PostgreSQL Flexible Server** backups). For portable dumps: `pg_dump`. |
| **Where** | Provider backup vaults (RDS snapshots / Azure backup); optional off-region copy for DR. GovCloud/Azure Gov stay in-partition. |
| **Retention** | Suggested ≥ 35 days managed + periodic long-term `pg_dump` archive per program retention policy. |
| **Encryption** | At rest via provider KMS / Key Vault **CMK**; ship dumps only to encrypted, access-controlled storage. |

**Portable logical dump (schema-scoped):**

```bash
pg_dump "$DATABASE_URL" \
  --schema=aeromarkup \
  --no-owner --no-privileges \
  -Fc -f aeromarkup-$(date +%Y%m%d).dump
```

Store the artifact encrypted; never in the container image or a public bucket.

---

## 4. Restore Runbook (numbered, copy-pasteable)

> Assumes a managed snapshot or a `pg_dump` artifact and access to Secrets
> Manager / Key Vault. Replace placeholders in `<...>`.

1. **Provision a new database.**
   - *Managed snapshot path:* restore the snapshot into a **new** RDS instance /
     Azure Flexible Server (multi-AZ / zone-redundant in prod). Note its host.
   - *Fresh instance path (for logical restore):* create an empty PostgreSQL 13+
     instance and a database.

2. **Restore the data.**
   - *From managed snapshot:* skip to step 4 (data is already present).
   - *From logical dump:*
     ```bash
     # custom-format dump
     pg_restore --no-owner --no-privileges \
       -d "postgresql://<user>:<pass>@<newhost>:5432/<db>?sslmode=require" \
       aeromarkup-YYYYMMDD.dump
     # or, for a plain SQL dump:
     psql "postgresql://<user>:<pass>@<newhost>:5432/<db>?sslmode=require" < dump.sql
     ```

3. **Ensure the schema is current (idempotent).**
   ```bash
   psql "postgresql://<user>:<pass>@<newhost>:5432/<db>?sslmode=require" \
     -v ON_ERROR_STOP=1 -f db/schema.sql
   ```
   `db/schema.sql` is idempotent (`CREATE TABLE IF NOT EXISTS`,
   `ALTER ... ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`), so it is
   safe to run against a restored database. (Alternatively set `AUTO_MIGRATE=1`
   and let the app apply it at boot.)

4. **Point the app at the restored DB.**
   Update `DATABASE_URL` in Secrets Manager / Key Vault / Render env to the new
   host (keep `sslmode=require`). Keep `AEROMARKUP_SECRET` **unchanged** if you
   want existing sessions to remain valid.

5. **Restart / redeploy the stateless app replicas.**
   ```bash
   # examples — pick your platform
   kubectl rollout restart deployment/aeromarkup
   # or trigger a Render redeploy / restart the Container App revision
   ```

6. **Verify database connectivity.**
   ```bash
   curl -fsS https://<host>/api/health
   # expect HTTP 200 with database reported connected
   ```

7. **Verify row counts** against the pre-incident baseline:
   ```bash
   psql "$DATABASE_URL" -c "SELECT 'drawings', count(*) FROM aeromarkup.drawings
     UNION ALL SELECT 'ncrs', count(*) FROM aeromarkup.ncrs
     UNION ALL SELECT 'inspections', count(*) FROM aeromarkup.inspections
     UNION ALL SELECT 'approvals', count(*) FROM aeromarkup.approvals
     UNION ALL SELECT 'audit_log', count(*) FROM aeromarkup.audit_log;"
   ```

8. **Verify login + audit trail integrity.**
   - Log in with a known account (confirms `users` + session signing intact).
   - Read `/api/audit` (requires `audit.read`) and confirm the append-only
     `audit_log` sequence is contiguous and reflects historical actions.

9. **Have field devices re-sync.**
   Reconnected devices call `POST /api/sync` with their stored `since` cursor;
   they push any queued offline edits (idempotent via `client_uid`) and pull
   peers' deltas. Confirm a test device reconciles cleanly.

---

## 5. Total DB Loss — Rebuild From Device IndexedDB (best-effort)

If **all** database backups are unrecoverable, the offline-first design allows a
**best-effort reconstruction** from field devices that still hold data:

1. Stand up a fresh empty database and apply `db/schema.sql` (step 3 above).
2. Bring the app online pointed at the empty DB.
3. Have each surviving device connect and run its normal `/api/sync` push. Every
   locally-held record carries a `client_uid`, so pushes upsert into the fresh
   DB **without creating duplicates**, and multiple devices converge.
4. Reconcile: the union of all devices' IndexedDB stores approximates the last
   known good state. Coverage depends on which devices synced what — treat this
   as a **recovery of last resort**, not a substitute for DB backups.

> This does **not** reconstruct the immutable `audit_log` (server-authored) or
> any data that only ever existed on devices that are now unavailable. Maintain
> real DB backups (§3).

---

## 6. Verification Cadence

- **Quarterly restore drills:** restore the latest snapshot into an isolated
  environment, run steps 6–9 of the runbook, record RTO achieved and any drift
  from the row-count baseline. File results with the program's continuity
  records.
- Validate `pg_dump` artifacts restore cleanly at least once per retention
  cycle (an untested backup is not a backup).

---

## 7. High Availability

- **Database:** multi-AZ **RDS** (GovCloud) / zone-redundant **Azure Database
  for PostgreSQL Flexible Server** for automatic failover.
- **Application:** run **multiple stateless replicas** behind a load balancer;
  any replica serves any request (no sticky sessions needed — tokens are
  stateless signed cookies).
- **Cross-replica caveats:**
  - `AEROMARKUP_SECRET` **must be identical across all replicas**, or sessions
    signed by one replica will be rejected by another.
  - The login brute-force throttle is **per-process (per pod/worker)**; for
    multi-replica deployments also enforce rate limiting at the **gateway/WAF**
    so the aggregate limit holds. See [SECURITY.md](SECURITY.md).
- **Uploads** ride inside Postgres, so HA/DR of the database automatically
  covers all uploaded content — no separate object-store replication to manage.
