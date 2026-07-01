# PALADIN — Deployment Guide

PALADIN is **cloud-agnostic** and **offline-friendly**: a single container image plus a PostgreSQL
database, with no hardcoded dependency on any commercial cloud service. All configuration is via
environment variables, file storage is abstracted (local volume or any S3-compatible endpoint), and
it runs behind a reverse proxy with TLS termination — including private-network / restricted-egress
and fully air-gapped deployments.

## Contents

- [1. Deployment models](#1-deployment-models)
- [2. Prerequisites](#2-prerequisites)
- [3. Configuration & secrets](#3-configuration--secrets)
- [4. Database migrations](#4-database-migrations)
- [5. Background & scheduled work](#5-background--scheduled-work)
- [6. Ollama (optional self-hosted LLM)](#6-ollama-optional-self-hosted-llm)
- [7. GPU acceleration](#7-gpu-acceleration)
- [8. Health & verification](#8-health--verification)
- [9. Production checklist](#9-production-checklist)
- [10. Upgrades & rollback](#10-upgrades--rollback)

Target-specific operator guides live under [`../deployments/`](../deployments/):
[`LOCAL_DEVELOPMENT`](../deployments/LOCAL_DEVELOPMENT.md) ·
[`SINGLE_LINUX_SERVER`](../deployments/SINGLE_LINUX_SERVER.md) ·
[`KUBERNETES`](../deployments/KUBERNETES.md) ·
[`AZURE`](../deployments/AZURE.md) ·
[`AWS`](../deployments/AWS.md) ·
[`AIRGAPPED`](../deployments/AIRGAPPED.md).

---

## 1. Deployment models

| Model | Summary | Guide |
|---|---|---|
| **Managed PaaS (Render)** | Deploy the Blueprint `render.yaml` (Docker web service + managed Postgres). | this doc |
| **Local / laptop** | `docker compose up --build` or PHP built-in server. | [LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) |
| **Single Linux server** | One VM, docker-compose or systemd, nginx/TLS, backups. | [SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) |
| **Kubernetes** | `docker/k8s.yaml` (Deployment/Service/Ingress/PVC/Secret) or Helm; HPA/PDB/probes. | [KUBERNETES](../deployments/KUBERNETES.md) |
| **Azure (Commercial + Government)** | Container Apps / AKS / App Service + Azure DB for PostgreSQL + Blob/Files + Key Vault + Managed Identity + Entra ID. | [AZURE](../deployments/AZURE.md) |
| **AWS (Commercial + GovCloud)** | ECS/Fargate or EKS + RDS + S3 + Secrets Manager + IAM roles/IRSA + KMS. | [AWS](../deployments/AWS.md) |
| **Air-gapped** | Offline registry mirror, bundled images, offline secrets/CVE feeds, self-hosted LLM via Ollama. | [AIRGAPPED](../deployments/AIRGAPPED.md) |

The application tier is **stateless** (only local state is uploaded files, externalizable to S3),
so all models can run multiple replicas behind a load balancer.

## 2. Prerequisites

| Requirement | Version / note |
|---|---|
| PostgreSQL | 14+ (a `paladin` schema is created automatically) |
| Container runtime | Docker 24+ / containerd (for image-based deploys) |
| PHP (native deploy only) | 8.3 with `pdo_pgsql`, `gd`, `opcache`, `zip`, `sodium`, `curl` |
| Reverse proxy | nginx / Apache / ALB / App Gateway with TLS termination |
| Object storage (optional) | Any S3-compatible endpoint for `STORAGE_DRIVER=s3` |
| SMTP (optional) | For real email; otherwise mail queues in `mail_outbox` |

`Dockerfile` and `render.yaml` are present and validated (see [§8](#8-health--verification) and
[§10](#10-upgrades--rollback)); the image is multi-arch-friendly `php:8.3-apache`, runs Apache as
`www-data`, and defines a container `HEALTHCHECK` against `/health`.

## 3. Configuration & secrets

Provide secrets via your platform's secret store (Azure Key Vault, AWS Secrets Manager, Kubernetes
Secrets) — **never** a committed `.env`. Copy `.env.example` to `.env` for local use only.

### Required

| Variable | Example | Purpose |
|---|---|---|
| `JWT_SECRET` | `<64 hex chars>` | Signs tokens **and** is the master key for AES-256-GCM at-rest encryption of sensitive settings. Generate: `php -r "echo bin2hex(random_bytes(32));"`. **Required.** |
| `DATABASE_URL` | `postgres://paladin:pw@host:5432/paladin` | PostgreSQL connection (Render/Heroku/Azure/AWS style)… |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | `localhost` / `5432` / `paladin` / `paladin` / `…` | …**or** discrete DB vars (used when `DATABASE_URL` is unset). |
| `ADMIN_EMAIL` | `admin@your-domain.gov` | First-run admin (only used on an empty database). |
| `ADMIN_PASSWORD` | `<strong password>` | First-run admin password (fresh DB only). |
| `APP_URL` | `https://paladin.your-domain.gov` | Public URL (links, emails, OIDC/SAML redirects). |

### Common / optional

| Variable | Example | Purpose |
|---|---|---|
| `APP_NAME` | `PALADIN` | Display name (overridable via Branding). |
| `APP_ENV` | `production` | `development` / `test` / `staging` / `production`. |
| `TRUSTED_PROXY_IPS` | `127.0.0.1` | Comma-separated reverse-proxy IPs trusted for `X-Real-IP` / `X-Forwarded-Proto`. |
| `PORT` | `80` | Container listen port; honored by `scripts/startup.sh` (e.g. Render, Azure App Service inject it). |
| `STORAGE_DRIVER` | `local` | `local` (mounted volume) or `s3`. |
| `S3_BUCKET` | `paladin-docs` | Bucket name (S3 driver). |
| `S3_REGION` | `us-gov-west-1` | Region for SigV4 signing. |
| `S3_ACCESS_KEY` / `S3_SECRET_KEY` | `AKIA…` / `…` | Static keys (fallback; prefer IAM roles/IRSA — see AWS guide). Encrypted at rest when set via Settings. |
| `S3_ENDPOINT` | `https://s3-fips.us-gov-west-1.amazonaws.com` | Custom/VPC/FIPS endpoint (MinIO, R2, GovCloud FIPS). |
| `S3_PUBLIC_URL` | `https://cdn.example.gov` | CDN base for public object URLs (else presigned URLs are generated). |
| `MAIL_TRANSPORT` | `queued` | `smtp` to deliver; `queued` (default) records in `mail_outbox`. |
| `SMTP_HOST` / `SMTP_PORT` | `smtp.example.gov` / `587` | SMTP server (required for `smtp`). |
| `SMTP_SECURE` | `tls` | `tls` (STARTTLS) / `ssl` / `none`. |
| `SMTP_USER` / `SMTP_PASS` | `…` | AUTH LOGIN credentials (optional). |
| `SMTP_FROM` / `SMTP_FROM_NAME` | `no-reply@example.gov` / `PALADIN` | From address / display name. |

> S3 and SMTP secrets, and the SCIM bearer token, may instead be set in **Admin → Settings**, where
> they are stored **encrypted at rest** (AES-256-GCM keyed from `JWT_SECRET`). The backend value
> wins when both env and settings are present.

## 4. Database migrations

The schema is created and migrated by **`install.php`**, which is idempotent and runs on every
container start (via `scripts/startup.sh`, with retry/backoff while the DB wakes). It:

1. `CREATE SCHEMA IF NOT EXISTS paladin` and sets `search_path`.
2. Applies `database/schema.sql` (idempotent baseline; best-effort).
3. Creates `schema_migrations` and applies each pending `database/migrations/*.sql` (001–033) once,
   in order, recording the filename on success (failures retry next boot).
4. Re-applies the baseline to settle indexes/views that depended on newly-added columns.
5. On a **fresh** database only, creates the admin user (`ADMIN_EMAIL`/`ADMIN_PASSWORD`) and seeds
   demo content.

Run manually (native or one-off container exec):

```bash
# From the project root, with DB_* / DATABASE_URL and JWT_SECRET in the environment:
php install.php
```

```bash
# Inside a running container:
docker compose exec app php /var/www/html/install.php
# or Kubernetes:
kubectl exec deploy/paladin -- php /var/www/html/install.php
```

All migrations use `CREATE TABLE IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` /
`CREATE INDEX IF NOT EXISTS` / `INSERT … ON CONFLICT DO NOTHING`, so rolling deployments are safe.
`database/schema.sql` is the authoritative combined reference and must be kept in sync with every
new migration.

## 5. Background & scheduled work

PALADIN is **cron-free by default**: scheduled publishing, document auto-expiry, and webhook retries
run opportunistically on common authenticated requests (`Scheduler::runDuePages`,
`Scheduler::runExpiredDocuments`, `Webhook::retryDue`). On a quiet site these wait for the next
request. For guaranteed timeliness, add real timers for the two CLI entrypoints:

```cron
# Document review / expiry reminders — daily 06:00 (lookAheadDays cooldownDays)
0 6 * * *  php /var/www/html/cli/send_review_reminders.php 14 7
# Notification email digests — daily 07:00, weekly Monday 07:00
0 7 * * *  php /var/www/html/cli/send_digests.php daily
0 7 * * 1  php /var/www/html/cli/send_digests.php weekly
```

Kubernetes users can run these as `CronJob`s against the same image (`command: ["php",
"/var/www/html/cli/send_digests.php","daily"]`). Both entrypoints are idempotent, guard against
double-sends (cooldowns), and log a one-line summary. Email delivery obeys `MAIL_TRANSPORT`; without
SMTP, messages are recorded in `mail_outbox` (inspect at `/admin/outbox`).

## 6. Ollama (optional self-hosted LLM)

PALADIN's core features do **not** require any hosted AI service. Where you enable optional
AI-assisted features (summaries, drafting help) or want them in a restricted/air-gapped environment,
run a **self-hosted [Ollama](https://ollama.com)** inference server instead of a hosted API — no
data leaves your boundary.

```bash
# Run Ollama alongside PALADIN (sidecar / separate host)
docker run -d --name ollama -p 11434:11434 -v ollama:/root/.ollama ollama/ollama
docker exec ollama ollama pull llama3.1        # or a FIPS-approved model of your choice
# Point the app at it (no telemetry, private network only):
#   OLLAMA_URL=http://ollama:11434
#   OLLAMA_MODEL=llama3.1
```

Keep the Ollama endpoint on a private network, reachable only from the app tier; the app's SSRF
guard (`Security::safeOutboundIp`) blocks arbitrary outbound URLs, so allowlist the Ollama host
explicitly in your network policy. In air-gapped installs, pull models where you have egress and
transfer them with the update bundle (see [AIRGAPPED](../deployments/AIRGAPPED.md)).

## 7. GPU acceleration

GPU is relevant only for self-hosted LLM inference (Ollama); the PHP app itself is CPU-only.

- **When:** enable GPU when running larger models or serving many concurrent AI requests. Small
  models run acceptably CPU-only — Ollama **degrades to CPU** automatically when no GPU is present.
- **Docker:** install the NVIDIA Container Toolkit and run Ollama with `--gpus all`.
- **Kubernetes:** install the NVIDIA device plugin and request `nvidia.com/gpu: 1` on the Ollama
  Deployment (CUDA drivers on the node). Keep PALADIN pods GPU-free.
- **Degrade path:** if the GPU is unavailable, Ollama falls back to CPU with higher latency; PALADIN
  treats AI features as optional, so their absence never blocks documentation/workflow operations.

## 8. Health & verification

| Path | Use |
|---|---|
| `GET /health` | Full check (DB `SELECT 1` + disk ≥ 100 MB) — LB / `HEALTHCHECK`. 200 healthy / 503 degraded. |
| `GET /healthz` | Liveness (process up). |
| `GET /readyz` | Readiness. |
| `GET /api/v1/health` | API health (`{status, service, version, time}`). |

Post-deploy verification:

```bash
# 1. Health + readiness
curl -fsS https://your-domain/health   # {"status":"healthy",...}
curl -fsS https://your-domain/healthz  # {"status":"ok"}

# 2. Login works (admin created on fresh DB)
#    Browse https://your-domain/login and sign in as ADMIN_EMAIL.

# 3. Secrets resolved — SMTP/S3 settings decrypt (no error banner in Admin → Settings).

# 4. Upload accepted + stored + hashed:
#    Attach a file to a document; confirm it downloads and an attachments row exists:
psql "$DATABASE_URL" -c "SET search_path TO paladin; \
  SELECT id, original_name, file_hash, version, is_current FROM attachments ORDER BY id DESC LIMIT 3;"

# 5. Object written to storage:
#    local  → ls uploads/attachments   |   s3 → aws s3 ls s3://$S3_BUCKET/attachments/

# 6. Audit chain recording:
psql "$DATABASE_URL" -c "SET search_path TO paladin; \
  SELECT action, entity_type, left(log_hash,12) FROM activity_log ORDER BY id DESC LIMIT 5;"
```

## 9. Production checklist

### Secrets & identity
- [ ] `JWT_SECRET` is ≥ 64 hex chars, unique per environment, stored in a secret manager (not `.env`).
- [ ] DB credentials and S3/SMTP secrets come from the platform secret store; prefer **IAM roles /
      IRSA / Managed Identity** over static keys (see AWS/Azure guides).
- [ ] `ADMIN_PASSWORD` rotated after first login; demo/seed users removed or disabled in production.
- [ ] SSO configured (SAML and/or OIDC) and tested with the real IdP; SCIM token set (encrypted).
- [ ] MFA policy set (`off` / `admins` / `all`); recovery codes issued.

### Transport & exposure
- [ ] TLS terminated at the proxy; HSTS active (auto-set under HTTPS); HTTP→HTTPS redirect.
- [ ] `TRUSTED_PROXY_IPS` set to the real proxy address(es) for correct client-IP attribution.
- [ ] `__Host-PALADIN` secure cookie in effect (HTTPS); `SameSite=Strict`, HttpOnly confirmed.
- [ ] Only the app port is exposed; DB, Ollama and object store are on private networks.
- [ ] Body-size limits aligned with `upload_max_size_mb` (proxy `proxy-body-size` ≈ 40 m).

### Hardening
- [ ] `APP_ENV=production` and `display_errors` off (image already enforces this).
- [ ] `installer` remains CLI-only (HTTP access returns 403 by design).
- [ ] `activity_log` DB role restricted to `INSERT`/`SELECT` (no `UPDATE`/`DELETE`) — see
      [AUDIT_TRAIL](AUDIT_TRAIL.md); ship the log to WORM/SIEM.
- [ ] Object store encryption at rest (SSE-KMS/CMK) enabled; DB encryption at rest enabled.
- [ ] Ollama (if used) reachable only from the app tier; SSRF guard allowlist reviewed.

### Resilience & operations
- [ ] PostgreSQL backups + PITR configured and **restore-tested** (see
      [DISASTER_RECOVERY](DISASTER_RECOVERY.md)).
- [ ] Uploads/object store backed up in sync with DB attachment rows.
- [ ] Health/readiness probes wired into the orchestrator; PDB/HPA set for k8s.
- [ ] Cron/systemd timers for `send_digests.php` and `send_review_reminders.php`.
- [ ] Logs shipped from `/var/www/html/logs` + stdout to an aggregator; alerts on 5xx/`degraded`.
- [ ] ≥ 2 replicas with uploads on S3 and a shared session store for HA.

## 10. Upgrades & rollback

`install.php` runs on every container start and re-applies `schema.sql` + any new
`database/migrations/*.sql`, then Apache starts. Rolling deployments are safe because every migration
is idempotent (`IF NOT EXISTS` / `ON CONFLICT DO NOTHING`).

- **Upgrade:** roll out the new image; migrations apply automatically on boot. For guaranteed
  ordering, run `php install.php` once against the new image before shifting traffic.
- **Rollback:** redeploy the previous image tag. Because migrations are additive/idempotent, older
  code tolerates the newer schema in the common case; validate on staging before relying on it.

**Artifacts verified present:** `Dockerfile` (multi-stage-friendly single-stage build, non-root
`www-data`, `HEALTHCHECK /health`), `docker-compose.yml` (app + `postgres:16-alpine`, healthchecks,
named volumes), `render.yaml` (Render Blueprint: Docker web service + managed `paladin-db`,
generated `JWT_SECRET`/`ADMIN_PASSWORD`, `healthCheckPath: /health`), and `docker/k8s.yaml`
(Deployment + Service + Ingress + PVC + Secret with liveness/readiness probes).
