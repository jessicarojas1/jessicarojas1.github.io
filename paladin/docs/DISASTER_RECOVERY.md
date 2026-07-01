# PALADIN — Disaster Recovery

This runbook defines what holds state, the recovery objectives, how to back up and restore each
component, how to verify a restore, and how to run for high availability. Companion docs:
[`DEPLOYMENT.md`](DEPLOYMENT.md), [`SECURITY.md`](SECURITY.md), [`ARCHITECTURE.md`](ARCHITECTURE.md).

---

## 1. What holds state

| Component | Where | Criticality | Notes |
|---|---|---|---|
| **PostgreSQL (`paladin` schema)** | Managed DB (RDS / Azure DB / Postgres) | **Critical** | Single source of truth for all content, identity, workflow, and the audit log. |
| **Uploaded files** | Local volume (`uploads/documents`, `uploads/attachments`, `uploads/evidence`) **or** S3-compatible bucket | **Critical** | Referenced by `attachments`/`media`/`documents` rows; must stay consistent with the DB. |
| **`JWT_SECRET`** | Secret manager / env | **Critical** | Also the AES-256-GCM master key that decrypts encrypted `settings` (SMTP/S3/SCIM secrets). **Losing it makes those encrypted values unrecoverable.** |
| **DB & admin credentials** | Secret manager | **Critical** | Required to reach the database and bootstrap admin on a fresh install. |
| **App configuration** | Env vars + `settings` table | Important | Env is in your IaC/secret store; `settings` is inside the DB backup. |

**Audit integrity note:** `activity_log` is a **hash-chained** trail. Restore it **complete and in
`id` order** — a partial restore breaks chain verification from the truncation point forward.

**Ephemeral / reconstructable** (nice-to-keep, not DR-critical): `rate_limits`, `active_sessions`,
`recent_views`, `alerts`, `wf_status` cache-like rows. These regenerate through normal use.

## 2. RPO / RTO targets

| Tier | RPO (max data loss) | RTO (max downtime) | Mechanism |
|---|---|---|---|
| **Production (recommended)** | ≤ 5 min | ≤ 1 hour | Managed Postgres with PITR/WAL + versioned object storage; warm standby. |
| **Standard** | ≤ 24 h | ≤ 4 h | Nightly DB dump + object-store snapshot; redeploy image + restore. |
| **Minimum viable** | ≤ 24 h | ≤ 8 h | Nightly `pg_dump` + uploads tarball to off-host storage. |

Set targets to the strictest applicable regulatory requirement and document them in your BCP.

## 3. Backups

### 3.1 PostgreSQL

- **Managed (preferred):** enable automated backups + **point-in-time recovery** (WAL). Retain per
  policy (e.g. 30 days) with cross-region copies. Enable **encryption at rest** (KMS/CMK).
- **Self-managed / logical:**

```bash
# Nightly logical dump of the paladin schema (custom format, compressed)
pg_dump "$DATABASE_URL" --schema=paladin -Fc -f paladin_$(date +%F).dump
# Encrypt before shipping off-host
gpg --encrypt --recipient dr@your-org paladin_$(date +%F).dump
```

Store dumps **off the database host**, encrypted, with the same retention as the object store so DB
and files can be restored to a consistent point.

### 3.2 Uploaded files / object store

- **S3 driver (preferred):** enable **bucket versioning** + lifecycle retention + SSE-KMS; replicate
  cross-region for the production tier.

```bash
aws s3 sync s3://$S3_BUCKET s3://$S3_BUCKET_DR --source-region <r> --region <dr-r>
```

- **Local volume:** snapshot the volume or tar the uploads tree in the same window as the DB dump:

```bash
tar czf paladin_uploads_$(date +%F).tgz -C /var/www/html uploads
```

### 3.3 Secrets & configuration

- Back up `JWT_SECRET`, DB credentials, S3/SMTP secrets, and the SCIM token in your secret manager's
  own backup/versioning. **Escrow `JWT_SECRET`** — it is required to decrypt at-rest settings.
- Keep the IaC (Terraform/Bicep/manifests) and `render.yaml` / `docker/k8s.yaml` in version control.

### 3.4 Consistency

Back up the DB and object store within the **same window** (ideally a coordinated snapshot). If they
drift, some `attachments` rows may point at objects that don't exist (or vice-versa) — reconcile
after restore (§5.7).

## 4. Restore runbook

> Copy-paste, numbered. Perform in a clean target environment (new DB + storage) and cut over once
> verified. Replace placeholders (`<…>`) with your values.

1. **Provision infrastructure.** Stand up a fresh PostgreSQL instance and the object store (or attach
   a restored uploads volume). Do **not** point production traffic at it yet.

2. **Restore secrets.** Recover `JWT_SECRET` (the **same** value as the source — else encrypted
   settings won't decrypt), DB credentials, and S3/SMTP secrets into the target secret store.

3. **Restore the database.**
   - Managed PITR: restore to the target timestamp per your provider's console/CLI.
   - Logical dump:
     ```bash
     createdb paladin && psql -d paladin -c "CREATE SCHEMA IF NOT EXISTS paladin;"
     gpg --decrypt paladin_<date>.dump.gpg > paladin_<date>.dump
     pg_restore --clean --if-exists --no-owner -d paladin paladin_<date>.dump
     ```

4. **Restore uploaded files.**
   - S3: restore/replicate the bucket (or fail over to the DR bucket) and set `S3_*` to point at it.
   - Local: extract the uploads archive into `/var/www/html/uploads` (owner `www-data`, mode `750`).

5. **Deploy the application** at the matching image tag, wired to the restored DB, storage, and
   `JWT_SECRET`. On boot, `scripts/startup.sh` runs `install.php`, which re-applies
   `schema.sql` + migrations idempotently against the restored data (existing rows are untouched;
   the admin/seed step is skipped because the DB is not fresh).

6. **Verify secrets decrypt.** Sign in as an admin and open **Admin → Settings** — SMTP/S3/SCIM
   values render without an "unable to decrypt" error (confirms `JWT_SECRET` matches the source).

7. **Reconcile files ↔ DB (if drift suspected).**
   ```bash
   # Attachment rows whose stored object is missing (spot-check keys against storage)
   psql "$DATABASE_URL" -c "SET search_path TO paladin; \
     SELECT id, entity_type, entity_id, stored_name FROM attachments WHERE is_current ORDER BY id DESC LIMIT 50;"
   ```
   Re-upload or restore any missing objects; unlink rows with permanently lost objects.

8. **Verify the audit chain** (§5.6). Walk `activity_log` in `id` order and confirm the hash chain is
   intact end-to-end.

9. **Cut over.** Point DNS / the load balancer at the restored app once §5 passes. Rotate any secrets
   you consider exposed by the incident (see [`SECURITY.md`](SECURITY.md) §secrets rotation).

## 5. Verification (post-restore & drill checklist)

1. `curl -fsS https://<target>/health` → `{"status":"healthy",...}`.
2. `curl -fsS https://<target>/healthz` → `{"status":"ok"}`.
3. **Login** works for an admin and a normal user.
4. **Content present:** spot-check spaces, a published document (with version history), an approval
   history, and tasks.
5. **Storage:** download an existing attachment; upload a new file and confirm a new `attachments`
   row + a written object.
6. **Audit chain integrity:**
   ```bash
   psql "$DATABASE_URL" -c "SET search_path TO paladin; \
     SELECT count(*) AS rows, count(DISTINCT log_hash) AS hashes FROM activity_log;"
   ```
   Then recompute `SHA-256(prev | user_id | action | entity_type | entity_id | changes | ip)` in `id`
   order (genesis = `genesis`) and confirm each matches the stored `log_hash`; the first mismatch
   identifies tampering/truncation.
7. **Secrets:** Admin → Settings decrypts SMTP/S3/SCIM values.
8. **Background jobs:** run `php cli/send_digests.php daily` and
   `php cli/send_review_reminders.php` once and confirm clean exit + `mail_outbox` rows.

**Cadence:** run a full restore drill at least **quarterly** (and after any major schema change),
timing it against the RTO target and recording the result.

## 6. High availability

- **App tier:** stateless — run **≥ 2 replicas** behind a load balancer across ≥ 2 AZs/zones.
  Externalize uploads to S3 and move sessions to a shared store (Redis/DB) so any replica can serve
  any user. Liveness `/healthz`, readiness `/readyz` drive rollouts and failover.
- **Database:** use a Multi-AZ / zone-redundant managed Postgres with automatic failover; add read
  replicas for very large read workloads. The app treats the DB as the single source of truth.
- **Object store:** use a regionally-redundant, versioned bucket (S3/Blob) with cross-region
  replication for the production tier.
- **Secrets:** replicate the secret manager (or its backups) to the DR region; keep `JWT_SECRET`
  identical across primary and DR so encrypted settings remain readable.
- **Graceful degradation:** if the object store is briefly unavailable, reads/writes of DB content
  continue; if SMTP is down, mail queues in `mail_outbox` and delivers when restored.
