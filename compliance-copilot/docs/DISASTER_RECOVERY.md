# Disaster Recovery — Compliance Copilot

How Compliance Copilot holds state, what to back up, recovery targets, and a
copy-pasteable restore runbook. The application tier is **stateless** — recovery
is almost entirely about the Supabase data plane.

---

## 1. What holds state

| State | Where | Notes |
|---|---|---|
| Compliance data | Supabase **Postgres**: `controls`, `evidence`, `poam_items` | Assessment status, POA&M, evidence metadata |
| App settings / branding | Supabase Postgres: `app_settings` (key `branding`) | Org logo/name/accent (JSONB) |
| Evidence files | Supabase **Storage** bucket (`evidence-files`) | Uploaded artifacts (policies, screenshots, logs) |
| Identity | Supabase **Auth** (if used) + env-configured single-tenant login | Login creds are env vars, not stored in DB |
| Secrets / config | Env vars / secret manager (`.env.local`, Vault, KV, Secrets Manager) | Not in the DB; back up the secret store separately |
| Per-browser branding | Client `localStorage` | Convenience cache only; server copy is authoritative |
| App code / build | Git + container registry | Rebuildable; no runtime state |

The Next.js app itself stores **nothing** on disk that needs backing up (the
in-memory rate-limit buckets are ephemeral and expected to reset).

---

## 2. RPO / RTO targets

| Tier | RPO (max data loss) | RTO (max downtime) | Basis |
|---|---|---|---|
| Managed (Supabase cloud, PITR) | ≤ 5 min | ≤ 1 hour | Point-in-time recovery + redeploy stateless app |
| Self-hosted (nightly `pg_dump`) | ≤ 24 h | ≤ 4 hours | Restore latest dump + bucket copy |
| Air-gapped | ≤ 24 h (or per bundle cadence) | ≤ 8 hours | Offline restore from media |

Tune to your CUI/SSP requirements. PITR gives the smallest RPO; adopt it for any
production compliance system of record.

---

## 3. Backups

| Component | Method | Where | Retention | Encryption |
|---|---|---|---|---|
| Postgres | Supabase automated backups + **PITR** (cloud); `pg_dump` cron (self-hosted) | Supabase / off-host object store | ≥ 30 days | At rest (provider KMS / disk encryption) |
| Storage bucket | Supabase Storage backup, or `rclone`/`aws s3 sync` mirror | Second bucket / off-site | ≥ 30 days | At rest + TLS in transit |
| Secrets | Export from secret manager to sealed offline copy | Secure vault | Per policy | Sealed / KMS |
| Schema | `supabase/schema.sql` in Git (idempotent) | Repo | With repo | — |

**Self-hosted Postgres dump:**

```bash
pg_dump "postgresql://postgres:<pw>@<host>:5432/postgres" \
  --format=custom --file="cc-$(date +%F).dump"
# store off-host, encrypted (e.g. age/gpg) then upload to object storage
```

**Storage bucket mirror (example, S3-compatible):**

```bash
aws s3 sync s3://<supabase-bucket>/evidence-files ./evidence-backup --sse
```

---

## 4. Restore runbook

> Perform in a maintenance window. Restores the data plane, then redeploys the
> stateless app.

1. **Declare the incident.** Freeze writes: scale the app to 0 replicas or put the
   ingress in maintenance mode so no new data is written during restore.
2. **Provision / identify the target Postgres.** New Supabase project, or the
   self-hosted Postgres instance to restore into.
3. **Restore the database.**
   - *Supabase PITR:* Dashboard → Database → Backups → **Restore** to the chosen
     timestamp (just before the incident).
   - *From dump:*
     ```bash
     pg_restore --clean --if-exists --no-owner \
       --dbname "postgresql://postgres:<pw>@<host>:5432/postgres" cc-<date>.dump
     ```
4. **Re-apply schema if needed** (idempotent — safe on a restored DB):
   ```bash
   psql "postgresql://postgres:<pw>@<host>:5432/postgres" -f supabase/schema.sql
   ```
5. **Restore Storage files.** Recreate the `evidence-files` bucket if missing, then
   sync from the backup mirror:
   ```bash
   aws s3 sync ./evidence-backup s3://<bucket>/evidence-files --sse
   ```
6. **Restore secrets/config.** Recreate env vars in the platform/secret manager
   (`NEXT_PUBLIC_SUPABASE_URL`/keys, `SUPABASE_SERVICE_ROLE_KEY`, `ANTHROPIC_API_KEY`,
   `AI_PROXY_TOKEN`, `APP_SESSION_SECRET`, `APP_AUTH_*`, `BRANDING_ADMIN_TOKEN`).
   Point them at the restored Supabase project.
7. **Redeploy the app.** `docker run` the image / `kubectl rollout` / Render or
   Vercel redeploy. Scale replicas back up.
8. **Verify** (see §5) then lift the maintenance freeze and close the incident.

---

## 5. Verification cadence

After every restore **and** on a scheduled drill (quarterly recommended):

- [ ] **Health:** `curl -sf https://<host>/api/health` returns 200 JSON with `status` and `supabase: "ok"` (confirms the app and its Supabase dependency are reachable).
- [ ] **Login:** POST valid creds to `/api/auth/login` → `{ ok: true }` + `cc_session` cookie.
- [ ] **Secrets resolved:** AI panel returns real output (not demo) — confirms `ANTHROPIC_API_KEY` present; branding save returns `persisted: 'server'` — confirms `SUPABASE_SERVICE_ROLE_KEY`.
- [ ] **DB row present:**
  ```bash
  psql "$DB_URL" -c "select key from app_settings; select count(*) from controls;"
  ```
- [ ] **Storage object present:** an evidence file downloads via its (signed) URL.
- [ ] **RLS intact:** anon key can `SELECT` but cannot `INSERT`/`UPDATE`.

**Drill cadence:** perform a full restore into a scratch project quarterly; record
actual RPO/RTO achieved and update §2 if targets drift.

---

## 6. High availability

- **App tier:** stateless — run ≥2 replicas across zones behind a load balancer;
  any instance can serve any request. Rolling deploys, no sticky sessions needed
  (session cookie is self-contained).
- **Database:** use Supabase's managed HA / read replicas, or a multi-AZ Postgres
  for self-hosted. Failover is a provider concern; app just needs the connection
  details updated.
- **Storage:** rely on provider-replicated object storage; keep the off-site mirror
  for cross-region/cross-provider recovery.
- **Caveat:** the in-memory rate limiter is per-instance. Under multiple replicas
  it does not enforce a global limit — front the app with a WAF/gateway limit or a
  shared Redis-backed limiter for consistent protection during scale-out.
