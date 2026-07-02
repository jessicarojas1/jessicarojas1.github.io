# Sentinel QMS — Deployment Guide

Operator-grade guide for deploying Sentinel QMS. For target-specific runbooks see
the per-target guides under [`../deployments/`](../deployments/); this document is
the model-agnostic reference (config, secrets, migrations, background process,
optional AI, and the production checklist).

Related: [`ARCHITECTURE.md`](ARCHITECTURE.md) · [`SECURITY.md`](SECURITY.md) ·
[`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Database migrations](#4-database-migrations)
5. [Background / worker process](#5-background--worker-process)
6. [Ollama (optional self-hosted LLM)](#6-ollama-optional-self-hosted-llm)
7. [GPU acceleration](#7-gpu-acceleration)
8. [Verification](#8-verification)
9. [Production checklist](#9-production-checklist)

---

## 1. Deployment models

| Model | When | Guide |
|-------|------|-------|
| **Managed PaaS (Render)** | Fast public demo | [`deployment/render-demo.md`](deployment/render-demo.md) · [`render.yaml`](../render.yaml) |
| **Local development** | Laptop / dev | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |
| **Single Linux server** | Small prod / pilot | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| **Kubernetes** | Scalable prod | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| **AWS (Commercial + GovCloud)** | CUI / production | [`../deployments/AWS.md`](../deployments/AWS.md) · [`deployment/aws-govcloud-runbook.md`](deployment/aws-govcloud-runbook.md) |
| **Azure (Commercial + Gov)** | CUI / production | [`../deployments/AZURE.md`](../deployments/AZURE.md) · [`deployment/azure-gov-runbook.md`](deployment/azure-gov-runbook.md) |
| **Air-gapped** | Offline / classified enclave | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |

> **CUI / production must run in AWS GovCloud or Azure Government.** The Render
> profile is a demo (free plans, ephemeral storage) — never load CUI into it.

### Container images

- **Single-service** — the root [`Dockerfile`](../Dockerfile) builds one image
  where FastAPI serves both `/api/v1` and the built SPA (`SERVE_FRONTEND=1`).
  Multi-stage (node build → wheel build → slim runtime), runs as **non-root**
  (`uid 1000 sentinel`), and defines a `HEALTHCHECK` hitting `/health`. **Verified
  present and valid.**
- **Two-service** — [`backend/Dockerfile`](../backend/Dockerfile) (API) and
  [`frontend/Dockerfile`](../frontend/Dockerfile) (nginx SPA), the topology used
  by docker-compose and Kubernetes.
- **Render Blueprint** — [`render.yaml`](../render.yaml) declares one Docker web
  service + a PostgreSQL database with secure defaults. **Verified present and
  valid.** From the monorepo, add `rootDir: sentinel-qms` so the build context
  includes `frontend/` and `backend/`.

---

## 2. Prerequisites

| Tool | Version | Used for |
|------|---------|----------|
| Docker / Docker Compose | 24+ / v2 | Local + single-server |
| PostgreSQL | **16+** | Data store (native enums, JSONB) |
| Python | 3.12 | Backend (only for native/dev runs) |
| Node | 20 | Frontend build |
| kubectl / Helm | 1.28+ / 3.14+ | Kubernetes |
| Terraform | 1.6+ | AWS GovCloud / Azure Gov IaC (`infra/terraform/`) |
| AWS CLI / Azure CLI | latest | Cloud auth + secrets |

Accounts/quotas: a GovCloud or Azure Government subscription for production;
KMS/Key Vault key; object storage bucket/container; managed Postgres instance.

---

## 3. Configuration & secrets

All configuration is environment-driven (`app/core/config.py`). Full reference:
[`../backend/.env.example`](../backend/.env.example) and
[`deployment/configuration-reference.md`](deployment/configuration-reference.md).

### Core

| Variable | Example | Purpose |
|----------|---------|---------|
| `ENVIRONMENT` | `production` | `development`\|`production`; production enables HSTS + JWT-secret guard |
| `LOG_LEVEL` | `INFO` | Log verbosity |
| `DATABASE_URL` | `postgresql+psycopg://u:p@host:5432/sentinel_qms` | Postgres DSN (bare `postgres://` auto-normalized) |
| `DB_SCHEMA` | `sentinel_qms` | Isolated schema (identifier only); `public` for default |
| `JWT_SECRET` | *(32+ char random secret)* | Token signing key; **must** be strong in production |
| `WEB_CONCURRENCY` | `4` | gunicorn workers (`gunicorn.conf.py`) |
| `CORS_ORIGINS` | `https://qms.example.gov` | Allowed browser origins (two-service only) |
| `TRUST_PROXY_HEADERS` | `true` | Honor `X-Forwarded-For` — **only** behind a trusted LB/proxy |
| `APP_BASE_URL` | `https://qms.example.gov` | Public URL for deep links in notifications/digests |

### Storage

| Variable | Example | Purpose |
|----------|---------|---------|
| `STORAGE_BACKEND` | `s3` | `s3` \| `azure_blob` \| `local` |
| `S3_BUCKET` / `S3_REGION` | `qms-cui` / `us-gov-west-1` | S3 target (GovCloud region for CUI) |
| `S3_ENDPOINT_URL` | *(blank; or MinIO/FIPS endpoint)* | Custom endpoint; use FIPS endpoints in GovCloud |
| `AZURE_STORAGE_CONNECTION_STRING` / `AZURE_STORAGE_CONTAINER` | *(secret)* / `sentinel-qms` | Azure Blob target |
| `MAX_UPLOAD_BYTES` | `52428800` | Max upload size (50 MiB) |

### Identity / SSO (all optional; fail closed when unset)

`OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_GROUP_ROLE_MAP`;
`SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_CERT`, `SAML_SP_ENTITY_ID`;
`CLIENT_CERT_PROXY_AUTH` (+ `TRUST_PROXY_HEADERS`) for CAC/PIV. See
[`../backend/.env.example`](../backend/.env.example) for the complete set.

### Secrets handling

- **Never** commit populated secrets. Only `.env.example` files carry placeholders.
- Source `JWT_SECRET`, DB credentials, storage keys, SMTP/webhook secrets from
  **AWS Secrets Manager** / **Azure Key Vault** and inject at runtime (IRSA /
  Managed Identity / CSI driver / External Secrets) — prefer workload identity
  over static credentials everywhere.
- `ADMIN_EMAIL` / `ADMIN_PASSWORD` are set only as secrets (never in `render.yaml`);
  keep `ADMIN_AUTO_CREATE=false` for any real deployment and create accounts
  explicitly.
- Production boot **fails** if `JWT_SECRET` is the dev default or `< 32` chars.

---

## 4. Database migrations

Migrations are **Alembic** (`backend/alembic/versions/`, currently `0001`–`0009`).

**Automatic (default).** The container entrypoint
([`backend/docker-entrypoint.sh`](../backend/docker-entrypoint.sh)) runs
`alembic upgrade head` then the seed, gated by env:

```bash
AUTO_MIGRATE=1   # run `alembic upgrade head` at startup (default)
AUTO_SEED=1      # seed reference data (non-fatal on failure)
```

Migration failures abort startup and print a filtered reason + the last 30 log
lines. Seeding is best-effort and never blocks the API.

**Manual (recommended for scaled prod — run once via a Job, then set `AUTO_MIGRATE=0`):**

```bash
# Inside the backend container / a migration Job
alembic upgrade head          # apply all pending migrations
alembic current               # show applied revision
alembic history               # list revisions
alembic downgrade -1          # roll back one revision

# From the Makefile against local compose
make migrate                  # docker compose exec backend alembic upgrade head
make seed                     # python -m app.seed
```

Always run migrations against **PostgreSQL 16+** (SQLite is tests only).

---

## 5. Background / worker process

Sentinel QMS runs its background work **in-process** by default — no external
queue or cron is required.

- **Scheduler** (`app/services/scheduler.py`) — a daemon thread started in the
  FastAPI lifespan. Each tick runs the **SLA-escalation sweep** and checks whether
  the **scheduled report digest** is due. Jobs claim their work atomically in the
  DB, so running it in every web worker is safe (no duplicate sends).
  - `RUN_SCHEDULER` (default `true`) — set `false` to disable (e.g. dedicate a
    separate worker replica to it).
  - `SCHEDULER_INTERVAL_SECONDS` (default `900` = 15 min).
- **Notification delivery** (`app/services/delivery.py`) — email/Teams/Slack sent
  on a background thread, best-effort, never blocking the request. **No retry
  queue** (see [`KNOWN_LIMITATIONS.md`](KNOWN_LIMITATIONS.md) #4).
- **Outbound webhooks** (`app/services/webhooks.py`) — HMAC-signed lifecycle
  events; enqueue is atomic with the change, dispatch is backgrounded with retries
  (`WEBHOOKS_ENABLED`).

> To run a **dedicated worker** deployment: run the same image with
> `RUN_SCHEDULER=true` on exactly one replica and `RUN_SCHEDULER=false` on the web
> replicas, or keep it in-process (safe by default). For multi-replica **rate
> limiting**, set `REDIS_URL` so counters are shared.

---

## 6. Ollama (optional self-hosted LLM)

Sentinel QMS ships **no hosted-AI dependency** — it works fully offline out of
the box. When you add or enable AI-assisted features (e.g. narrative
summarization of NCR/CAPA, RCA suggestions), run inference **on-prem / in-boundary**
with [Ollama](https://ollama.com) rather than any external API, which is
mandatory for air-gapped and CUI environments.

```bash
# Run Ollama in-boundary (co-located sidecar or dedicated node)
docker run -d --name ollama -p 11434:11434 \
  -v ollama:/root/.ollama ollama/ollama
docker exec ollama ollama pull llama3.1:8b-instruct-q4_K_M
```

Point the app at the local endpoint via env (add these when wiring AI features):

| Variable | Example | Purpose |
|----------|---------|---------|
| `OLLAMA_BASE_URL` | `http://ollama:11434` | In-boundary inference endpoint |
| `OLLAMA_MODEL` | `llama3.1:8b-instruct` | Model tag to use |
| `AI_FEATURES_ENABLED` | `false` | Master toggle (default off; fail closed) |

Keep egress to public model APIs blocked; the air-gapped guide
([`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md)) covers bundling the
Ollama image + model weights in the offline update bundle.

---

## 7. GPU acceleration

GPU is **only** relevant when self-hosted LLM inference (Ollama, §6) is enabled;
the QMS API and database are CPU-bound and never need a GPU.

- **When** — larger models / higher summarization throughput. Small quantized
  models (e.g. `8b-q4`) run acceptably on CPU; Ollama **degrades to CPU**
  automatically when no GPU is present.
- **Docker** — install the NVIDIA Container Toolkit and run the Ollama container
  with `--gpus all`.
- **Kubernetes** — install the **NVIDIA device plugin** DaemonSet and request
  `nvidia.com/gpu: 1` on the Ollama Deployment; schedule onto GPU nodes with a
  nodeSelector/taint-toleration. Ensure the base CUDA driver matches the plugin.
- **Degrade-to-CPU** — no GPU node ⇒ Ollama serves on CPU (slower, still correct);
  keep `AI_FEATURES_ENABLED=false` if latency is unacceptable.

---

## 8. Verification

After deploy, confirm each concern (adjust host/token):

```bash
# 1) Health — status ok, DB connected
curl -fsS https://$HOST/health
# → {"status":"ok","version":"1.0.0","environment":"production","database":{"connected":true}}

# 2) Login works (returns access + refresh tokens)
curl -fsS -X POST https://$HOST/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.gov","password":"…"}'

# 3) Secrets resolved — a strong JWT_SECRET; production booted (guard passed)
#    (a weak secret would have crash-looped the container at startup)

# 4) File upload accepted + indexed — attach a file to a record, then confirm the
#    attachment row is queryable back through the API
TOKEN=…  # from step 2
curl -fsS -H "Authorization: Bearer $TOKEN" \
  -F "file=@sample.pdf" https://$HOST/api/v1/attachments
curl -fsS -H "Authorization: Bearer $TOKEN" https://$HOST/api/v1/attachments

# 5) Object written to storage
#    - s3:        aws s3 ls s3://$S3_BUCKET/ --region $S3_REGION   (use FIPS endpoint in GovCloud)
#    - azure_blob: az storage blob list --container-name $AZURE_STORAGE_CONTAINER
#    - DB row:    psql "$DATABASE_URL" -c "SELECT id, filename FROM attachments ORDER BY id DESC LIMIT 5;"
```

Also verify the SPA loads (`https://$HOST/`) and the CUI banner renders.

---

## 9. Production checklist

### Secrets & identity
- [ ] `JWT_SECRET` from Secrets Manager / Key Vault, 32+ chars, unique per env
- [ ] DB credentials, storage keys, SMTP/webhook secrets injected via workload
      identity (IRSA / Managed Identity / CSI / External Secrets), not static keys
- [ ] `ADMIN_AUTO_CREATE=false`; bootstrap admin created explicitly then rotated
- [ ] Federation configured (`OIDC_*` / `SAML_*` / CAC-PIV) with group→role map;
      MFA where local passwords remain
- [ ] `ENVIRONMENT=production` (secret guard + HSTS active)

### Transport & exposure
- [ ] TLS terminated at LB/ingress; HSTS on; HTTP→HTTPS redirect
- [ ] For CAC/PIV: mTLS at the proxy with `CLIENT_CERT_PROXY_AUTH=true` +
      `TRUST_PROXY_HEADERS=true`
- [ ] `TRUST_PROXY_HEADERS`/`TRUSTED_PROXY_COUNT` correct for the proxy chain
- [ ] `CORS_ORIGINS` restricted (or same-origin single-service, which needs none)
- [ ] WAF in front; database in private subnets; default-deny NetworkPolicies

### Hardening
- [ ] Non-root container (image already runs as `uid 1000`); read-only root FS
      where possible
- [ ] `STORAGE_BACKEND=s3`/`azure_blob` (never `local` in prod) with SSE-KMS / CMK
- [ ] FIPS endpoints in GovCloud/Azure Gov (S3/KMS/STS regional gov + FIPS)
- [ ] Rate limiting: set `REDIS_URL` for a shared limiter across replicas, and/or
      enforce at the WAF/gateway
- [ ] Image scanned (Trivy) and IaC scanned (Checkov/tfsec) in CI before promote

### Resilience & operations
- [ ] Managed multi-AZ PostgreSQL with automated backups + PITR
      (see [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md))
- [ ] Migrations run as a gated Job (`AUTO_MIGRATE=0` on web replicas) or verified
      idempotent at startup
- [ ] Liveness/readiness probes wired to `/health`; HPA + PodDisruptionBudget set
- [ ] Centralized logging (JSON logs shipped); `X-Request-ID` retained for
      correlation; audit-log retention configured
- [ ] Restore drill performed and RPO/RTO validated
