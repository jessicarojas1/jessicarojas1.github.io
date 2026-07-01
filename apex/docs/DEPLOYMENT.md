# APEX — Deployment Guide

How to deploy APEX to every supported target, configure it, run migrations, and
harden it for production.

> Cross-links: [ARCHITECTURE.md](ARCHITECTURE.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [SECURITY.md](SECURITY.md) · [../README.md](../README.md)
> Per-target operator guides: [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) · [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) · [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) · [../deployments/AZURE.md](../deployments/AZURE.md) · [../deployments/AWS.md](../deployments/AWS.md) · [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)

---

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Database migrations](#4-database-migrations)
5. [Worker / background process](#5-worker--background-process)
6. [Ollama configuration (optional / airgapped AI)](#6-ollama-configuration-optional--airgapped-ai)
7. [GPU acceleration](#7-gpu-acceleration)
8. [Verification](#8-verification)
9. [Production checklist](#9-production-checklist)

---

## 1. Deployment models

| Model            | When to use                              | Guide |
|------------------|------------------------------------------|-------|
| Managed PaaS (Render) | Fastest path; `render.yaml` provisions web + Postgres | this doc §Render + [render.yaml](../render.yaml) |
| Single Linux server   | One VM, full control, small footprint    | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes            | Multi-replica, autoscaling, HA           | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) |
| AWS (Commercial/GovCloud) | ECS/Fargate or EKS + RDS             | [../deployments/AWS.md](../deployments/AWS.md) |
| Azure (Commercial/Government) | App Service/AKS + Azure DB for PostgreSQL | [../deployments/AZURE.md](../deployments/AZURE.md) |
| Air-gapped            | Offline / classified enclaves            | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) |
| Local development     | Laptop via docker-compose                | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |

APEX is a single stateless PHP/Apache container plus a PostgreSQL 16 database in
every model. The container image is defined by [Dockerfile](../Dockerfile)
(multi-step, non-root `www-data`, listens on `8080`, read-only-rootfs compatible
with `/tmp` writable).

### Render (managed PaaS)

The repo ships a valid Render Blueprint at [render.yaml](../render.yaml) with
`rootDir: apex`, so it deploys standalone.

1. Push the repo to GitHub.
2. Render → **New → Blueprint** → point at the repo.
3. Render provisions from `render.yaml`:
   - `apex-db` — free-tier PostgreSQL,
   - `apex` — Docker web service (built from `apex/Dockerfile`),
   - `JWT_SECRET` — auto-generated,
   - `DATABASE_URL` — auto-wired from `apex-db`,
   - `APP_ENV=production`, `APEX_ALLOW_DEFAULT_PINS=0`.
4. On first boot, `bin/start.sh` runs `scripts/migrate.php`, which applies
   `schema.sql` (schema + seed users + SEC project).
5. Sign in with a seed identity, then immediately rotate its PIN (see below).

---

## 2. Prerequisites

| Tool               | Version | Notes |
|--------------------|---------|-------|
| Docker / Compose   | 24+     | For containerized deploys and local dev |
| PHP                | 8.2     | Only for native (non-Docker) runs |
| PHP extensions     | `pdo`, `pdo_pgsql` | Installed in the image; required natively |
| PostgreSQL         | 16      | Managed (RDS/Azure DB/Render) or self-hosted |
| psql client        | 16      | For manual migrations, backups, verification |

Accounts/quotas depend on target (Render account; AWS with ECS/RDS quotas; Azure
subscription with App Service/AKS + Azure Database for PostgreSQL). See the
per-target guides.

---

## 3. Configuration & secrets

All configuration is environment-driven (no config file). Provide secrets via the
platform's secret store — **never commit `.env`** (only `.env.example` with
placeholders).

| Variable                  | Example                                              | Purpose |
|---------------------------|------------------------------------------------------|---------|
| `DATABASE_URL`            | `postgresql://apex:pass@host:5432/apex?sslmode=require` | Postgres connection URL (or raw PDO DSN). |
| `JWT_SECRET`              | `openssl rand -hex 32`                               | HS256 signing key. **Required, ≥32 chars in production** or app fails closed. |
| `APP_ENV`                 | `production`                                         | Enables fail-closed secret check + `Secure` cookies. |
| `APEX_ALLOW_DEFAULT_PINS` | `0`                                                 | Keep `0`. `1` accepts seed PINs (dev only); ignored in production. |
| `DATABASE_USER`           | `apex`                                               | Only when `DATABASE_URL` is a raw DSN. |
| `DATABASE_PASS`           | `••••`                                               | Only when `DATABASE_URL` is a raw DSN. |
| `PORT`                    | `8080`                                               | Platform listen port; container serves `8080`. |

**Secret sourcing by target (prefer identity over static keys):**

| Target            | JWT_SECRET / DATABASE_URL source | Identity |
|-------------------|----------------------------------|----------|
| Render            | Blueprint `generateValue` + `fromDatabase` | platform-managed |
| AWS Commercial    | Secrets Manager → ECS task secrets | IAM task role |
| AWS GovCloud      | Secrets Manager (partition `aws-us-gov`, FIPS STS/Secrets endpoints) | IAM task role |
| Azure Commercial  | Key Vault → App Service/AKS reference | Managed Identity / Entra ID |
| Azure Government  | Key Vault (`*.vault.usgovcloudapi.net`) | Managed Identity |
| Kubernetes        | Secret via CSI driver / External Secrets | Workload identity / IRSA |
| Air-gapped        | Offline secret file / HSM        | local only |

Generate a strong secret:

```bash
openssl rand -hex 32          # 64 hex chars — set as JWT_SECRET
```

---

## 4. Database migrations

APEX uses a single idempotent applier — there is no incremental migration
framework. The schema of record is [schema.sql](../schema.sql); the applier is
[scripts/migrate.php](../scripts/migrate.php).

**Automatic (default).** On every container start, `bin/start.sh` runs:

```bash
php /var/www/html/scripts/migrate.php   # applies schema.sql only if the users table is absent
exec apache2-foreground
```

`migrate.php` checks `to_regclass('public.users')`; if the table exists it skips,
otherwise it executes the full `schema.sql`. This is safe to run on every boot.

**Manual (native / operator-driven):**

```bash
# From the apex/ directory, against your target database:
psql "$DATABASE_URL" -f schema.sql

# Or run the applier (loads .env if present, then applies conditionally):
php scripts/migrate.php
```

> ⚠️ `schema.sql` begins with `DROP TABLE IF EXISTS … CASCADE` for a clean
> first-install and then re-seeds. `migrate.php` only invokes it when `users` is
> absent, so it will **not** wipe an existing database on restart. Do **not** run
> `psql -f schema.sql` against a populated production database — it will drop and
> reseed. For a populated DB, apply only the specific new DDL by hand. The
> `app_settings` table is additionally created idempotently at runtime by the
> settings handler.

Seed data included: 3 users (`rojas`/admin, `smith`/member, `brown`/viewer), one
`SEC` project, 8 tickets, 6 labels, sample history/comments, and default
branding.

---

## 5. Worker / background process

**None.** APEX has no queue worker, cron job, or scheduler. All work is
synchronous within the request that triggers it:

- Notifications are written inline when a comment is posted or a status changes.
- Audit `history` rows are written inline on ticket mutation.
- The only startup task is the one-shot migration in `bin/start.sh`.

Consequently there is nothing extra to schedule, scale, or supervise beyond the
web process. If future async work is added (e.g. email delivery), run it as a
separate container/`CronJob` sharing `DATABASE_URL`.

---

## 6. Ollama configuration (optional / airgapped AI)

APEX ships **no AI/LLM features** — there is no hosted-AI dependency to replace,
so Ollama is **not required** for normal operation. This section exists so any
future AI add-on (e.g. ticket summarization) can be wired to a self-hosted model
in disconnected/classified enclaves instead of a hosted API.

If/when an AI feature is added, target a local Ollama runtime:

```bash
# Run Ollama alongside APEX (own container / host / sidecar)
docker run -d --name ollama -p 11434:11434 -v ollama:/root/.ollama ollama/ollama
docker exec ollama ollama pull llama3.1:8b

# Point the (future) APEX AI feature at it:
#   OLLAMA_BASE_URL=http://ollama:11434
#   OLLAMA_MODEL=llama3.1:8b
```

For air-gapped installs, pre-pull the model on a connected host, export the
`ollama` volume, and import it into the enclave. See
[../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md).

---

## 7. GPU acceleration

Not applicable to APEX itself — the PHP/Apache web tier and PostgreSQL are
CPU-only and need no GPU. GPU guidance applies **only** to a self-hosted Ollama
inference runtime for a future AI feature:

- **When:** only if you enable a local LLM and need faster inference. CPU-only
  works (slower); GPU is an optimization, not a requirement.
- **How (Docker):** `--gpus all` with the NVIDIA Container Toolkit installed on
  the host (CUDA 12.x driver).
- **How (Kubernetes):** install the NVIDIA device plugin, request
  `nvidia.com/gpu: 1` on the Ollama pod only, and schedule it to a GPU node pool.
- **Degrade to CPU:** if no GPU is present, Ollama runs on CPU automatically — no
  APEX code path changes. The APEX web/DB tiers never require a GPU.

---

## 8. Verification

Run these after any deploy (replace `$BASE` with your URL, e.g.
`http://localhost:8080`).

**Health endpoint:**

```bash
curl -fsS "$BASE/api/health"
# → {"data":{"ok":true,"service":"apex-api","time":"2026-..."}}
```

**Login works (secrets resolved):**

```bash
curl -fsS -X POST "$BASE/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' -c cookies.txt
# → {"data":{...,"token":"<jwt>"}}  (only if APEX_ALLOW_DEFAULT_PINS=1 or you set that PIN)
```

A successful login proves `JWT_SECRET` and `DATABASE_URL` resolved (JWT signed,
user row read from Postgres). A `500`/startup crash with
`JWT_SECRET is missing or too short` proves the secret is unset in production.

**Object written to storage (DB row) — create a ticket and read it back:**

```bash
TOKEN=$(curl -fsS -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')

curl -fsS -X POST "$BASE/api/tickets" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"Deploy smoke test"}'
# → {"data":{"id":"SEC-009",...}}  (auto-incremented key confirms a DB write)
```

Or confirm directly in Postgres:

```bash
psql "$DATABASE_URL" -c "SELECT id,title,status FROM tickets ORDER BY created_at DESC LIMIT 1;"
```

**Branding upload accepted (data: logo persisted):** APEX has no file-scanning
upload; the equivalent "upload accepted + stored" check is a branding write with
a `data:image/...` logo, saved to `app_settings`:

```bash
curl -fsS -X POST "$BASE/api/settings/branding" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"displayName":"APEX","accentColor":"#6366f1","logoUrl":"data:image/png;base64,iVBORw0KGgo="}'
psql "$DATABASE_URL" -c "SELECT value FROM app_settings WHERE key='branding';"
```

A `javascript:` or `data:text/html` logo is rejected to empty string — verify the
sanitizer by posting one and confirming `logoUrl` comes back `""`.

---

## 9. Production checklist

### Secrets & identity
- [ ] `JWT_SECRET` set to ≥32 random chars from a secret manager (`openssl rand -hex 32`); never logged or committed.
- [ ] `DATABASE_URL` sourced from the platform secret store; DB user least-privilege (no superuser).
- [ ] Prefer workload identity (IAM task role / IRSA / Managed Identity) over static DB/API keys.
- [ ] `APEX_ALLOW_DEFAULT_PINS=0` and all seed PINs rotated after first login.
- [ ] `.env` never committed; only `.env.example` with placeholders.

### Transport & exposure
- [ ] TLS terminated at the LB/ingress; HTTPS-redirect + HSTS enforced (`public/.htaccess`).
- [ ] Reverse proxy sets `X-Forwarded-Proto: https` so the redirect and `Secure` cookie work.
- [ ] Database not exposed to the public internet; app→DB traffic uses TLS (`sslmode=require`/`verify-full`).
- [ ] Only port `8080` exposed from the container; DB port reachable only from the app tier.

### Hardening
- [ ] `APP_ENV=production` (activates fail-closed secret check + `Secure` cookies).
- [ ] Container runs as non-root (`www-data`), read-only root filesystem, `/tmp` tmpfs, drop-ALL caps.
- [ ] Security headers present (CSP `script-src 'self'`, HSTS, `X-Frame-Options: DENY`, `nosniff`, COOP/CORP).
- [ ] Image pinned by digest (Dockerfile already pins `php:8.2-apache` and `postgres` by SHA256); rebuild for CVE patches.
- [ ] Non-production error detail (stack traces) suppressed — confirmed by `APP_ENV=production`.

### Resilience & operations
- [ ] Health probe wired to `GET /api/health` (liveness + readiness).
- [ ] Automated Postgres backups + tested restore (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- [ ] ≥2 web replicas behind the LB (stateless tier scales freely).
- [ ] Managed Postgres with multi-AZ / replica + failover where the target supports it.
- [ ] Centralized logs (stdout/stderr → platform pipeline); alert on 5xx and DB connection failures.
- [ ] Migration behavior understood: `migrate.php` is idempotent and will not wipe a populated DB on restart.
