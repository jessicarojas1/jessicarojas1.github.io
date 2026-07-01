# CITADEL — Deployment Guide

Operator-grade guide for deploying CITADEL, from a static SPA to a hardened,
FIPS-friendly, air-gapped backend. Configuration reference: [ENV.md](ENV.md).
Architecture: [ARCHITECTURE.md](ARCHITECTURE.md). Security: [SECURITY.md](SECURITY.md).
Continuity: [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

Per-target, copy-pasteable runbooks live under [`../deploy/`](../deploy/):
[compose](../deploy/compose/) · [aws](../deploy/aws/) · [aws-gov](../deploy/aws-gov/) ·
[azure-gov](../deploy/azure-gov/) · [gcp](../deploy/gcp/) · [kubernetes](../deploy/kubernetes/) ·
[ci](../deploy/ci/). A standalone Render Blueprint is at [`../render.yaml`](../render.yaml).

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Scanner signature-DB updates ("migrations")](#4-scanner-signature-db-updates-migrations)
5. [Database migrations](#5-database-migrations)
6. [The scan / worker process](#6-the-scan--worker-process)
7. [Ollama & self-hosted AI (Explain & fix / air-gapped)](#7-ollama--self-hosted-ai-explain--fix--air-gapped)
8. [GPU acceleration](#8-gpu-acceleration)
9. [Verification](#9-verification)
10. [Production checklist](#10-production-checklist)

---

## 1. Deployment models

| Model | What runs | Persistence | Use |
|---|---|---|---|
| **SPA-only (static)** | `index.html` + `js/` on any static host | Browser `localStorage` only | Quick scans, demos, education. No backend, no upload. |
| **Full backend (single container)** | `server/Dockerfile` image (SPA + all scanners) | In-memory / JSON file, or Postgres | Deep scans, auth/RBAC, history, CI gate. Render / one VM. |
| **Kubernetes** | Same image + ingress/HPA/PDB/probes | Postgres + Redis + tmpfs scratch | Scaled, HA deep scan. See [`../deploy/kubernetes/`](../deploy/kubernetes/). |
| **Cloud managed** | Container on ECS/Fargate, AKS/App Service, Cloud Run + managed PG/secrets | Managed Postgres + secret manager | AWS (Commercial + GovCloud), Azure (Commercial + Government), GCP. |
| **Air-gapped** | Bundled image in a private registry, no egress | Postgres in-enclave | CUI/ITAR review; offline CVE feeds; self-hosted LLM. See [`../deploy/`](../deploy/) + [§7](#7-ollama--self-hosted-ai-explain--fix--air-gapped). |

> **`server/Dockerfile` exists** and is the single source image for every backend
> model. **Build context is the repository root** so it can COPY both the SPA
> (`citadel/`) and the backend (`citadel/server/`):
> `docker build -f citadel/server/Dockerfile -t citadel-server .`

The reference container is Debian `bookworm-slim`, Node 20, **non-root
(uid 10001)**, read-only-root friendly (only `/tmp/citadel` writable), with a
`HEALTHCHECK` on `/api/health`.

---

## 2. Prerequisites

| Model | Needs |
|---|---|
| SPA-only | Any static host / `python3 -m http.server`. No build step. |
| Full backend | Docker (or Node ≥ 18); **≥ 2 GB RAM** (ClamAV loads a ~1.4 GB signature DB); ~4 GB disk for the image + scanner DBs; outbound HTTPS to fetch CVE DBs on first run (unless air-gapped). |
| Kubernetes | A cluster, an ingress controller, a `StorageClass`, a secrets provider (CSI / ExternalSecrets), Postgres + Redis. |
| Cloud | The provider CLI, a container registry, managed Postgres, a secret manager, and an **IAM role / workload identity** (prefer over static creds). |
| Air-gapped | A private registry mirror, pre-pulled scanner DBs, offline OSV/CVE feed, and a self-hosted LLM endpoint (optional). |

---

## 3. Configuration & secrets

Full table in [ENV.md](ENV.md). Minimum for a real deployment:

| Variable | Example | Purpose |
|---|---|---|
| `NODE_ENV` | `production` | Enables prod hardening + secure defaults. |
| `CITADEL_JWT_SECRET` | `openssl rand -hex 32` | Stable HS256 signing secret so sessions survive restarts. **Required** in prod. |
| `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD` | seeded | Initial admin (random password generated + logged once if unset). |
| `CITADEL_DATA_KEY` | 32-byte hex | AES-256-GCM-seals JWT secret + TOTP seeds at rest. |
| `DATABASE_URL` | `postgres://…` | Durable users / history / audit (else in-memory/file). |
| `REDIS_URL` | `rediss://…` | Shared rate-limit/lockout across replicas. |
| `TRUST_PROXY_HOPS` | `1` | Trusted proxy hops (prevents XFF IP spoofing). |
| `CITADEL_METRICS_TOKEN` | random | Guards `/metrics`. |
| `ANTHROPIC_API_KEY` | `sk-ant-…` | Optional AI "Explain & fix" (omit for no-egress). |
| `CITADEL_FIPS` | `1` | Forces PBKDF2-HMAC-SHA256 KDF (FIPS posture). |

**Never commit secrets.** Use the platform secret manager (AWS Secrets Manager /
Azure Key Vault / GCP Secret Manager / k8s Secrets via CSI) and prefer **IAM
roles / workload identity** over static credentials. On Render, mark
`CITADEL_JWT_SECRET` and `ANTHROPIC_API_KEY` `sync: false` (see
[`../render.yaml`](../render.yaml)).

**AWS Commercial vs GovCloud (us-gov):** in GovCloud use partition `aws-us-gov`,
regional gov endpoints for STS/KMS/S3/Secrets Manager, and **FIPS endpoints**
(`*.<region>.amazonaws.com` → `*-fips.<region>.amazonaws.com`) alongside
`CITADEL_FIPS=1`. **Azure Commercial vs Government:** use the
`.usgovcloudapi.net` / Entra ID Gov authority endpoints. See
[`../deploy/aws-gov/`](../deploy/aws-gov/) and [`../deploy/azure-gov/`](../deploy/azure-gov/).

---

## 4. Scanner signature-DB updates ("migrations")

CITADEL's "migrations" for detection quality are the **external scanner
databases**, which age quickly. Refresh them on deploy and on a schedule:

| DB | Refresh command | Notes |
|---|---|---|
| **ClamAV** malware sigs | `freshclam` | Seeded at build time; run at container start / on a cron sidecar. ~1.4 GB. |
| **Trivy** vuln DB | `trivy --download-db-only` | Downloads on first run; pre-pull to avoid a cold-start stall. |
| **Grype** vuln DB | `grype db update` | Same pattern. |
| **Semgrep** rules | bundled registry rules | Pin the Semgrep version; bump deliberately + re-test adapters. |
| **OSV.dev** (SPA quick scan) | live, keyless | Client-side; no local DB. |

`scripts/init.sh` hydrates these inside the container post-deploy. For air-gapped
installs, ship the DBs as an **update bundle** ([§7](#7-ollama--self-hosted-ai-explain--fix--air-gapped)).

---

## 5. Database migrations

CITADEL is **schema-managed by the app**: when `DATABASE_URL` is set,
`server/lib/db.js` holds the canonical `SCHEMA` constant and runs it on boot
(`init()`), fully idempotent (`CREATE … IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`,
every object prefixed `citadel_`). No separate migration step is required.

For a manual / pre-provisioned database (locked-down DBA workflow), apply the
mirror file:

```bash
psql "$DATABASE_URL" -f citadel/database/schema.sql
```

Keep `database/schema.sql` in sync with `db.js` whenever a column/table is added.
Without `DATABASE_URL`, CITADEL runs on its in-memory / JSON-file store and needs
none of this (see the ephemeral-store caveat in [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).

---

## 6. The scan / worker process

There is **no separate queue/cron worker** — scanning is request-driven and
bounded:

- Each `POST /api/scan` extracts into a fresh per-request workdir under
  `CITADEL_TMP`, fans scanners out in parallel up to `SCAN_CONCURRENCY` (memory
  cap), enforces `SCAN_TIMEOUT_MS` per external scanner, and removes the workdir
  in a `finally` block.
- Heuristic SAST runs in a **worker thread** (`lib/scanWorker.js`) when the host
  has enough memory (`CITADEL_SCAN_ISOLATION` / `CITADEL_ISOLATION_MIN_MEM_MB`),
  avoiding OOM→502 on small instances.
- The `CITADEL_METRICS_TOKEN`-guarded `/metrics` endpoint exposes active
  sessions, uptime, and RSS for autoscaling.
- Optional `CITADEL_NOTIFY_URL` posts a Slack-compatible summary when a scan's
  severity crosses `CITADEL_NOTIFY_ON`.

Scale horizontally behind a load balancer; use `REDIS_URL` so rate-limit/lockout
state is shared across replicas and `DATABASE_URL` so history/users are shared.

---

## 7. Ollama & self-hosted AI (Explain & fix / air-gapped)

AI "Explain & fix" (`POST /api/explain`) is **opt-in and egress-gated**. It uses
the official `@anthropic-ai/sdk` (`server/lib/ai.js`) and is only active when
`ANTHROPIC_API_KEY` is set **and** the instance is not air-gapped.

- **Hosted (default):** set `ANTHROPIC_API_KEY`; model via `CITADEL_AI_MODEL`
  (default `claude-opus-4-8`).
- **Air-gapped / no-egress:** set `CITADEL_AIRGAP=1` (or `CITADEL_NO_EGRESS=1`).
  This **hard-disables** AI remediation and all outbound enrichment so scanned
  source (which may be CUI / ITAR / proprietary) can never be transmitted. The
  copy-paste **AI Fix Prompt** export still works — paste it into your own
  approved, in-enclave assistant.
- **Self-hosted inference via Ollama:** run Ollama (or any OpenAI/Anthropic-
  compatible gateway) inside the enclave and point the SDK's base URL at it via
  `ANTHROPIC_BASE_URL` (honored by `@anthropic-ai/sdk`), keeping
  `CITADEL_AIRGAP` **unset** so the (now-local) endpoint is reachable but no
  traffic leaves the enclave:

  ```bash
  # in-enclave Ollama with an Anthropic-compatible shim/gateway
  ollama serve                       # e.g. models: qwen2.5-coder, llama3.1
  export ANTHROPIC_BASE_URL=http://ollama-gateway.internal:11434
  export ANTHROPIC_API_KEY=local     # any non-empty value for a local gateway
  export CITADEL_AI_MODEL=qwen2.5-coder
  ```

  Validate the endpoint is internal-only (no route to the internet) before
  enabling, and treat the model host as part of the CUI boundary.

---

## 8. GPU acceleration

CITADEL's scanners are CPU-bound; **GPU only matters for self-hosted LLM
inference** ([§7](#7-ollama--self-hosted-ai-explain--fix--air-gapped)). When
running Ollama for Explain & fix at volume:

- **Docker:** run the Ollama container with `--gpus all` and the NVIDIA Container
  Toolkit; install a CUDA-capable driver on the host.
- **Kubernetes:** install the **NVIDIA device plugin**, request
  `resources.limits."nvidia.com/gpu": 1` on the Ollama pod, and schedule it to
  GPU nodes.
- **Degrade to CPU:** Ollama runs on CPU automatically when no GPU is present —
  slower, but functional; keep CPU as the fallback so an unavailable GPU never
  breaks Explain & fix. The CITADEL backend itself needs no GPU.

---

## 9. Verification

Run after every deploy:

```bash
BASE=https://citadel.example.gov

# 1. Health — service up, scanners visible, auth mode as expected
curl -fsS $BASE/api/health | jq '{ok, engine, auth, scanners: (.scanners|length)}'

# 2. Login works — obtain an access token (returns 200 + token)
TOKEN=$(curl -fsS -X POST $BASE/api/auth/login -c cookies.txt \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.gov","password":"…"}' | jq -r .token)

# 3. Secrets resolved — /api/auth/me returns the admin identity (JWT verified)
curl -fsS $BASE/api/auth/me -H "Authorization: Bearer $TOKEN" | jq '{email,role}'

# 4. Upload accepted + scanned — POST a zip, get a report with findings
curl -fsS -X POST $BASE/api/scan -H "Authorization: Bearer $TOKEN" \
  -F "files=@./sample.zip" | jq '{grade: .scoring.grade, findings: (.findings|length)}'

# 5. Object written to storage (DB-backed installs) — the scan is in history
curl -fsS $BASE/api/scans -H "Authorization: Bearer $TOKEN" | jq '.[0] | {id, createdAt}'
# or, on Postgres:  psql "$DATABASE_URL" -c 'select count(*) from citadel_scans;'
```

Success criteria: `/api/health` `ok:true` with `available:true` scanners; login
returns a token; `/me` resolves the sealed JWT; the scan returns a graded report;
and (DB-backed) the run appears in `citadel_scans`.

---

## 10. Production checklist

### Secrets & identity
- [ ] `CITADEL_JWT_SECRET` set to a stable 32-byte random value (not the boot random).
- [ ] `CITADEL_DATA_KEY` set so JWT secret + TOTP seeds are sealed at rest.
- [ ] Initial admin password rotated; `CITADEL_ADMIN_PASSWORD` not left as a default.
- [ ] Secrets sourced from a secret manager; **IAM role / workload identity** over static creds.
- [ ] SSO (`OIDC_*`) configured with `OIDC_ALLOWED_DOMAINS`; `OIDC_ADMIN_EMAILS` scoped tightly.

### Transport & exposure
- [ ] TLS terminated in front (nginx / ALB / ingress); HSTS on.
- [ ] `TRUST_PROXY_HOPS` matches the real proxy depth (no XFF spoofing).
- [ ] `/metrics` guarded by `CITADEL_METRICS_TOKEN` (or loopback-only).
- [ ] Enforcement on (`enforce`) — not running open (`CITADEL_ALLOW_OPEN` only if truly intended).

### Hardening
- [ ] Container runs **non-root, read-only root FS**, cap-dropped, `no-new-privileges`.
- [ ] `CITADEL_TMP` mounted as a **non-persistent, `exec=false` tmpfs**.
- [ ] Upload/bomb caps reviewed (`MAX_UPLOAD_BYTES`, `CITADEL_MAX_UNZIP_*`).
- [ ] `CITADEL_FIPS=1` + FIPS endpoints for gov/CUI workloads.
- [ ] `CITADEL_AIRGAP=1` when reviewing CUI/ITAR (or a validated in-enclave LLM only).

### Resilience & operations
- [ ] `DATABASE_URL` (Postgres) + `REDIS_URL` set for durability and shared limits.
- [ ] Scanner DBs (ClamAV/Trivy/Grype) refreshed on a schedule.
- [ ] Backups + restore drills per [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).
- [ ] `/api/health` wired to the orchestrator/LB probe; autoscaling on `/metrics`.
- [ ] Audit forwarded to SIEM (`CITADEL_AUDIT_SINK_URL`); log retention set.
