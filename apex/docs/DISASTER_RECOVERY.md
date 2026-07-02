# APEX — Disaster Recovery

Recovery objectives, what holds state, backup strategy, a copy-pasteable restore
runbook, verification cadence, and high-availability guidance for APEX.

> Cross-links: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md)

---

## 1. What holds state

APEX is a stateless web tier over a single stateful store. Recovery is therefore
almost entirely about PostgreSQL.

| Component            | Holds state? | Contents | Recovery source |
|----------------------|--------------|----------|-----------------|
| PostgreSQL 16        | **Yes — primary** | users, projects, members, tickets, labels, sprints, comments, `history` (audit), notifications, `app_settings` (branding) | DB backups / PITR |
| Web container (Apex) | No           | Ephemeral. Code + assets baked into the image. | Rebuild/redeploy image |
| `JWT_SECRET`         | Yes — critical config | HS256 signing key | Secret manager (versioned) |
| `DATABASE_URL`       | Yes — critical config | DB connection | Secret manager / platform binding |
| Branding assets      | Yes           | Stored *in* Postgres (`app_settings`, as text/`data:` URL) — **not** on a filesystem or object store | DB backups |
| Object storage / uploads | N/A       | APEX stores no files on disk or S3/Blob; logos are `data:` URLs in the DB. | — |

Because there is no object store and no on-disk upload directory, a full recovery
is: restore the database + re-supply the two secrets + redeploy the stateless
image.

---

## 2. RPO / RTO targets

| Tier             | RPO (data loss) | RTO (time to restore) | How achieved |
|------------------|-----------------|-----------------------|--------------|
| Managed Postgres + PITR (recommended prod) | ≤ 5 min | ≤ 30 min | Continuous WAL archiving / point-in-time restore |
| Nightly logical dump only | ≤ 24 h | ≤ 1 h | `pg_dump` cron + image redeploy |
| Dev / single-VM  | ≤ 24 h | best-effort | Local dump cron |

The stateless web tier's RTO is minutes (pull image, set env, start). The binding
constraint is always the database restore.

---

## 3. Backups

**What to back up**
1. The PostgreSQL database (all tables — this is the entire application state).
2. `JWT_SECRET` and `DATABASE_URL` (in the secret manager, with version history).
3. The container image (or the source repo + `Dockerfile` to rebuild it).

**How / where / retention / encryption**

| Method | Command / mechanism | Where | Retention | Encryption |
|--------|---------------------|-------|-----------|------------|
| Managed automated backups + PITR | Provider feature (RDS, Azure DB, Render) | Provider-managed, cross-region copy where available | 7–35 days (set per policy) | At rest via provider KMS (KMS / Key Vault) |
| Logical dump | `pg_dump -Fc "$DATABASE_URL" > apex-$(date +%F).dump` | Encrypted object storage (S3/Blob) with SSE-KMS | 30 days daily + 12 monthly | SSE-KMS; encrypt the dump file at rest |

Example nightly dump (run from an operator host or a `CronJob`):

```bash
pg_dump -Fc "$DATABASE_URL" -f "apex-$(date +%F).dump"
# then upload to encrypted object storage, e.g.:
aws s3 cp "apex-$(date +%F).dump" s3://apex-backups/ --sse aws:kms   # GovCloud: partition aws-us-gov, FIPS endpoints
```

> Rotating `JWT_SECRET` invalidates all outstanding tokens (users must re-login).
> This is safe and expected; keep the current version recoverable so you don't
> accidentally lock everyone out mid-incident.

---

## 4. Restore runbook

Numbered and copy-pasteable. Replace placeholders with your environment values.

**A. Restore the database**

```bash
# 1. Provision a clean PostgreSQL 16 target (managed instance or container).
#    Note its connection string as $RESTORE_URL.

# 2. Managed provider path (preferred): use the console/CLI point-in-time
#    restore to the desired timestamp, then read the new connection string.
#    Skip to step B once the restored instance is healthy.

# 3. Logical-dump path: restore the most recent verified dump.
createdb -h <host> -U <admin> apex            # if the DB does not exist
pg_restore --clean --if-exists --no-owner \
  -d "$RESTORE_URL" apex-YYYY-MM-DD.dump

# 4. Sanity-check row counts.
psql "$RESTORE_URL" -c "SELECT
  (SELECT count(*) FROM users)   AS users,
  (SELECT count(*) FROM tickets) AS tickets,
  (SELECT count(*) FROM history) AS history;"
```

**B. Re-supply secrets**

```bash
# 5. Point DATABASE_URL at the restored instance in the secret store.
#    Ensure JWT_SECRET is the SAME value users had (from the secret manager's
#    version history) if you want existing sessions to keep working; otherwise
#    all users simply re-login after a rotation.
```

**C. Redeploy the stateless web tier**

```bash
# 6. Deploy the APEX image with the restored DATABASE_URL + JWT_SECRET,
#    APP_ENV=production, APEX_ALLOW_DEFAULT_PINS=0.
#    bin/start.sh runs migrate.php: it sees the users table already exists
#    (restored) and SKIPS re-applying schema.sql — no data is overwritten.
```

**D. Verify** (see §5 below), then cut traffic over to the restored stack.

---

## 5. Verification cadence (restore drills)

Run a full drill on a **quarterly** cadence (monthly for high-assurance
programs). A drill is only "green" when all of these pass against the *restored*
stack:

```bash
# Health
curl -fsS "$BASE/api/health"                        # ok:true

# Login (secret + DB resolved)
curl -fsS -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' \
  -d '{"userId":"<user>","pin":"<pin>"}'            # returns a token

# Data present
psql "$RESTORE_URL" -c "SELECT count(*) FROM tickets;"   # matches pre-incident count
psql "$RESTORE_URL" -c "SELECT value FROM app_settings WHERE key='branding';"  # branding intact

# Audit trail intact
curl -fsS "$BASE/api/projects/proj_sec/history" -H "Authorization: Bearer $TOKEN"
```

Record: drill date, backup timestamp used, measured RPO/RTO vs target, and any
gaps. File corrective actions for misses.

---

## 6. High availability

- **Web tier.** Stateless — run ≥2 replicas across availability zones behind the
  load balancer/ingress. Any replica serves any request (JWT is self-contained;
  no sticky sessions). Health checks on `GET /api/health` remove unhealthy pods.
- **Database.** Use a managed multi-AZ Postgres (RDS Multi-AZ, Azure DB
  zone-redundant HA, or a primary + streaming replica) with automatic failover.
  The single Postgres instance is the only stateful component and thus the HA
  focal point.
- **Secrets.** Store `JWT_SECRET`/`DATABASE_URL` in a managed, replicated secret
  service (Secrets Manager / Key Vault) with version history for rollback.
- **Region loss.** Keep cross-region backup copies (or a cross-region read
  replica you can promote). Recovery is: restore/promote DB in the surviving
  region, repoint `DATABASE_URL`, redeploy the image there.
- **Migration safety in HA.** `migrate.php` is idempotent and no-ops when the
  `users` table exists, so rolling restarts and multi-replica boots never clobber
  data or race destructively on the schema.
