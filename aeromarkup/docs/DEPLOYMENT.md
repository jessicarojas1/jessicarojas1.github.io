# AeroMarkup — Deployment Guide

Operator-grade guide for deploying **AeroMarkup**, the offline-first aerospace
engineering-lifecycle platform (Flask API + PWA + PostgreSQL). This document is
the canonical entry point; each target has a dedicated runbook under
[`../deployments/`](../deployments/).

---

## Contents

1. [Deployment models](#deployment-models)
2. [Prerequisites](#prerequisites)
3. [Configuration & secrets](#configuration--secrets)
4. [Database migrations](#database-migrations)
5. [The worker / background process](#the-worker--background-process)
6. [Ollama configuration (optional self-hosted LLM)](#ollama-configuration-optional-self-hosted-llm)
7. [GPU acceleration](#gpu-acceleration)
8. [Container image](#container-image)
9. [Render blueprint](#render-blueprint)
10. [Production checklist](#production-checklist)
11. [Verification](#verification)

---

## Deployment models

AeroMarkup ships one stateless container that runs identically everywhere. Pick
the target that matches your environment and follow its runbook:

| Model | Runbook | When to use |
|-------|---------|-------------|
| Managed PaaS (Render) | [render.yaml](#render-blueprint) | Pilots, demos, quick stand-up. Free Postgres expires ~30 days. |
| Local / laptop | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) | Development, offline preview. |
| Single Linux VM | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) | One box, systemd or docker-compose, nginx/TLS. |
| Kubernetes | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) | Multi-replica, HPA/PDB, CSI/External Secrets. |
| AWS (Commercial + GovCloud) | [`../deployments/AWS.md`](../deployments/AWS.md) | ECS/Fargate or EKS + RDS + Secrets Manager + IAM roles. |
| Azure (Commercial + Government) | [`../deployments/AZURE.md`](../deployments/AZURE.md) | Container Apps or AKS + Azure DB + Key Vault + Managed Identity. |
| Air-gapped | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) | Offline registry/bundles, no-internet secrets, optional self-hosted LLM. |

**Common shape across all models:** a single non-root web process
(`gunicorn server:app`, 2 workers, port `8080`) fronted by a TLS-terminating
load balancer / reverse proxy, backed by a managed PostgreSQL. All durable
state lives in Postgres and in each client's IndexedDB — the container itself
holds nothing, so it scales horizontally and restarts freely.

---

## Prerequisites

| Requirement | Version / note |
|-------------|----------------|
| Python | 3.12 (matches the container base `python:3.12-slim`) |
| PostgreSQL | 13+ (Render Postgres, AWS RDS, Azure DB for PostgreSQL Flexible Server) |
| Docker / OCI runtime | For container targets (compose, ECS, Container Apps, k8s) |
| `psql` client | For manual migrations, backups, verification |
| TLS certificate | ACM (AWS), Key Vault cert (Azure), Let's Encrypt (single VM), platform-managed (Render) |

Python packages (`requirements.txt`): `Flask>=3.0`, `Werkzeug>=3.0`,
`itsdangerous>=2.1`, `psycopg[binary]>=3.1`, `gunicorn>=21.2`. No external
scanner binaries or CDN dependencies — the frontend makes **no** third-party
runtime calls.

---

## Configuration & secrets

AeroMarkup is configured entirely through environment variables. There is no
config file to mount.

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgres://user:pass@host:5432/aeromarkup?sslmode=require` | Postgres DSN. **Empty → offline-only mode** (the PWA still works; nothing syncs). |
| `PORT` | `8080` | Listen port. Render sets this (blueprint uses `10000`). |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` on boot (idempotent). Set `0` to manage migrations out-of-band. |
| `ENVIRONMENT` | `production` | Anything other than `development`/`dev`/`local`/`test` is treated as production: secure cookies required and a strong secret mandatory. |
| `AEROMARKUP_SECRET` | `<48+ url-safe chars>` | Session signing key. **≥ 32 chars, REQUIRED in production when `DATABASE_URL` is set — the app refuses to boot without it.** Must be identical across replicas. |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime (default 12h). |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed logins per `(client IP, username)` before HTTP 429. |
| `LOGIN_WINDOW_SECONDS` | `300` | Rolling window for the login throttle. |
| `LOGIN_MAX_TRACKED` | `8192` | Max distinct throttle buckets held in memory. |
| `TRUSTED_PROXY_HOPS` | `1` | Reverse-proxy hop count. Set to your real hop count (e.g. `1` behind a single ALB/App Gateway/Container Apps ingress) so the throttle keys on the real client IP. `0` = trust nothing. |

### Secrets & identity

- **Never** bake `AEROMARKUP_SECRET` or `DATABASE_URL` into the image or commit
  them. Only `.env.example` (placeholders) is committed.
- Generate a signing key: `python3 -c "import secrets; print(secrets.token_urlsafe(48))"`.
- Resolve secrets from the platform secret store and inject as env at runtime:
  - **AWS:** Secrets Manager → task/pod env (`aeromarkup/database-url`, `aeromarkup/session-secret`).
  - **Azure:** Key Vault → Container App secrets / CSI Secrets Store.
  - **Render:** `AEROMARKUP_SECRET` via `generateValue: true`; `DATABASE_URL` via `fromDatabase`.
  - Prefer **IAM roles / managed identity / workload identity** over static keys everywhere — see each cloud runbook for the least-privilege policy.

---

## Database migrations

The schema is a single **idempotent** file: [`../db/schema.sql`](../db/schema.sql).
It creates the dedicated `aeromarkup` Postgres schema and every table under it
(`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`,
`CREATE INDEX IF NOT EXISTS`), so it is safe to run against a fresh **or**
populated database, including a database shared with other apps.

**Automatic (default).** With `AUTO_MIGRATE=1` the app applies `db/schema.sql`
once at boot. This is the normal path on Render, ECS, Container Apps, and
compose.

**Manual / out-of-band.** Set `AUTO_MIGRATE=0` and run the schema yourself
(recommended when a DBA controls DDL, or in air-gapped / change-controlled
environments):

```bash
# apply the full schema (safe to re-run)
psql "$DATABASE_URL" -f db/schema.sql

# optional demo/seed data (idempotent, ON CONFLICT DO NOTHING)
psql "$DATABASE_URL" -f db/seed.sql
```

There is **no separate migration tool or version table** — the combined schema
file is the source of truth. When you add a migration, update `db/schema.sql`
so it always represents the full, current schema (see repo rule in
[`../CLAUDE.md`](../CLAUDE.md)).

In Kubernetes you can run the migration as a `Job` before the rollout instead
of relying on `AUTO_MIGRATE`; see [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md).

---

## The worker / background process

AeroMarkup has **no separate worker, queue, or cron process.** All work is
request-driven inside the single web process:

- **Offline↔online reconciliation** happens synchronously in `POST /api/sync`.
  Each client pushes a batch of `client_uid`-keyed changes and pulls back peers'
  changes from the `sync_log` change journal, using a `since`/`cursor` sequence.
  Idempotency is guaranteed by the stable client-generated `client_uid`.
- **`updated_at`** columns are maintained by PostgreSQL triggers, not a job.
- **Login throttling** is an in-process sliding window (per gunicorn worker /
  per replica). For multi-worker or multi-replica deployments, also enforce
  rate limiting at the gateway/WAF (it is best-effort per process).

Because there is no queue, the only scaling knob is the number of gunicorn
workers (`--workers`, default 2) and the number of replicas. Keep them
stateless — the only shared state is Postgres.

---

## Ollama configuration (optional self-hosted LLM)

**AeroMarkup uses no hosted AI service today.** The 3D viewer, markup engine,
measurement math, and STL/OBJ parsers are self-contained WebGL/JS with **no
external CDN, runtime, or AI calls** — this is what makes the app air-gap safe.

If you later add optional AI-assist features (e.g. NCR summarization, defect
triage suggestions), run inference **self-hosted with [Ollama](https://ollama.com)**
rather than a hosted API, so the air-gapped posture is preserved. Suggested
wiring (not required by the current app):

```bash
# run Ollama alongside the app (docker)
docker run -d --name ollama -p 11434:11434 -v ollama:/root/.ollama ollama/ollama
docker exec ollama ollama pull llama3.1:8b
```

| Variable | Example | Purpose |
|----------|---------|---------|
| `OLLAMA_HOST` | `http://ollama:11434` | Base URL of the self-hosted inference server. |
| `OLLAMA_MODEL` | `llama3.1:8b` | Model tag to use for AI-assist calls. |

In Kubernetes, deploy Ollama as its own `Deployment`/`StatefulSet` + `Service`
(`http://ollama.<ns>.svc:11434`). In air-gapped sites, pre-pull the model on a
connected host and ship the model blobs in the update bundle — see
[`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md).

---

## GPU acceleration

The app itself needs **no GPU** — client-side rendering runs in the browser's
WebGL context on the end-user device (2-in-1, iPad, Android tablet, phone). The
server does no rendering.

GPU is relevant **only** if you enable the optional self-hosted Ollama path
above and want fast inference:

- **When:** LLM-assist features enabled and latency matters. Otherwise skip.
- **How (host):** NVIDIA driver + CUDA + `nvidia-container-toolkit`; run Ollama
  with `--gpus all`. Ollama auto-detects the GPU and falls back to CPU when none
  is present.
- **How (Kubernetes):** install the NVIDIA device plugin, then request
  `nvidia.com/gpu: 1` on the Ollama pod (not on the AeroMarkup pods).
- **Degrade to CPU:** Ollama runs CPU-only automatically if no GPU is available;
  no config change is needed, only throughput drops.

---

## Container image

The [`../Dockerfile`](../Dockerfile) is verified and production-ready:

- Multi-arch base `python:3.12-slim` pinned by digest.
- Installs `requirements.txt`, copies `server.py`, `db/`, `static/`.
- Runs as **non-root** (`appuser`, uid `10001`) — FedRAMP/DoD STIG friendly.
- `HEALTHCHECK` polls `http://127.0.0.1:$PORT/api/health` every 30s.
- Entry: `gunicorn server:app --bind 0.0.0.0:${PORT} --workers 2 --timeout 120`.

Build/run locally:

```bash
docker build -t aeromarkup:latest .
docker run --rm -p 8080:8080 -e ENVIRONMENT=development aeromarkup:latest
```

Or the full stack (app + Postgres) via [`../docker-compose.yml`](../docker-compose.yml):

```bash
export POSTGRES_PASSWORD='<a strong secret>'
docker compose up --build        # → http://localhost:8080
```

---

## Render blueprint

[`../render.yaml`](../render.yaml) is a valid Render Blueprint (verified). It
provisions the web service **and** a managed Postgres, wiring `DATABASE_URL`
automatically and generating `AEROMARKUP_SECRET`:

1. Push the repo to GitHub.
2. Render → **New → Blueprint** → select the repo (`rootDir: aeromarkup`).
3. Deploy. The schema is applied on first boot. Health check: `/api/health`.

No env vars to set by hand. **Note:** Render's free Postgres expires ~30 days
after creation; move to a paid plan or a government-cloud managed database for
anything beyond a pilot (only `DATABASE_URL` changes).

---

## Production checklist

### Secrets & identity
- [ ] `AEROMARKUP_SECRET` set to a unique ≥ 32-char random value, from a secret
      store, **identical across all replicas**, never in git or the image.
- [ ] `DATABASE_URL` resolved from Secrets Manager / Key Vault / blueprint — not
      hardcoded.
- [ ] Cloud identity uses **IAM roles / managed identity / workload identity**;
      static access keys avoided (see cloud runbooks for least-privilege policies).
- [ ] First-run admin created via `POST /api/auth/bootstrap`; no default
      credential exists in the app.

### Transport & exposure
- [ ] `ENVIRONMENT=production` (enforces `Secure` cookies + strong-secret gate).
- [ ] TLS terminated at the ALB / App Gateway / ingress / nginx; HTTP redirects
      to HTTPS.
- [ ] `sslmode=require` in `DATABASE_URL` (mandatory in gov regions).
- [ ] `TRUSTED_PROXY_HOPS` set to the real proxy-hop count so the login throttle
      keys on the true client IP (never higher than the actual hop count).
- [ ] Postgres reachable only from the app's subnet/security group — not public.

### Hardening
- [ ] Container runs as non-root uid `10001` (default) with a read-only-friendly
      filesystem where the platform allows.
- [ ] No third-party CDN/runtime/AI egress (verify CSP / egress rules).
- [ ] Encryption at rest enabled on the database (KMS/CMK / Azure CMK).
- [ ] Gateway/WAF rate limiting enabled for multi-replica (the in-process login
      throttle is per-worker).
- [ ] CUI classification banners and classification columns retained.

### Resilience & operations
- [ ] Multi-AZ / zone-redundant database with automated backups + PITR.
- [ ] ≥ 2 stateless app replicas behind the load balancer; PDB in k8s.
- [ ] Health check wired to `/api/health` on the LB/ingress.
- [ ] Backups tested via a restore drill (see
      [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)).
- [ ] Logs shipped to CloudWatch / Log Analytics / platform logs and retained.
- [ ] Secret rotation runbook documented (rotating `AEROMARKUP_SECRET`
      invalidates active sessions — plan the window).

---

## Verification

Run after every deploy. Replace `$BASE` with your service URL (e.g.
`https://aeromarkup.example.gov` or `http://localhost:8080`).

**1. Health / secrets resolved.**

```bash
curl -s "$BASE/api/health"
# → {"status":"ok","database":"connected","mode":"online"}
#   "database":"connected"  = DATABASE_URL resolved + reachable
#   "offline"               = DATABASE_URL not set/resolved (offline-only)
```

If the container refused to boot with
`AEROMARKUP_SECRET is missing or too weak`, the signing secret did not resolve
from the secret store — fix the secret binding before continuing.

**2. First-run admin + login (cookie jar keeps the session + CSRF cookie).**

```bash
# bootstrap the initial admin (only works while no user has a password)
curl -s -c cj.txt -X POST "$BASE/api/auth/bootstrap" \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"ChangeMe-长-passphrase","display_name":"Admin"}'

# subsequent logins
curl -s -c cj.txt -X POST "$BASE/api/auth/login" \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"ChangeMe-长-passphrase"}'
# → {"ok":true,"user":{...,"role":"admin"},"csrf":"<token>"}
```

**3. File upload accepted + row written (data lands in Postgres).**

AeroMarkup stores uploaded reference images and STL/OBJ 3D models as data URLs
in Postgres — there is no object store. Create a project and a drawing, which
exercises the write path (state-changing calls require the CSRF header matching
the `am_csrf` cookie):

```bash
CSRF=$(grep am_csrf cj.txt | awk '{print $7}')

curl -s -b cj.txt -X POST "$BASE/api/projects" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Verify Project","classification":"CUI"}'
# → 201 with the created project JSON (row written to aeromarkup.projects)
```

**4. Confirm the object was written to storage (DB).**

```bash
psql "$DATABASE_URL" -c "SELECT count(*) FROM aeromarkup.projects;"
psql "$DATABASE_URL" -c "SELECT seq, actor, action, entity_type FROM aeromarkup.audit_log ORDER BY seq DESC LIMIT 5;"
# the create above appears in the immutable audit_log
```

**5. Authorization + CSRF are enforced (negative checks).**

```bash
# no session → 401
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/api/projects" -d '{}'   # 401

# session but missing/incorrect CSRF header → 403 csrf_failed
curl -s -b cj.txt -o /dev/null -w '%{http_code}\n' -X POST "$BASE/api/projects" \
  -H 'Content-Type: application/json' -d '{"name":"x"}'                          # 403
```

See each target runbook under [`../deployments/`](../deployments/) for
platform-specific verification (LB DNS, `kubectl port-forward`, RDS/Azure DB
`psql`, secret-resolution checks).
