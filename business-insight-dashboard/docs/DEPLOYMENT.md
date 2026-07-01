# Deployment — Business Insight Dashboard

> Canonical deployment guide.
> Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [SECURITY.md](SECURITY.md)
> Per-target operator guides live in [../deployments/](../deployments/).

---

## Table of Contents

1. [Overview](#1-overview)
2. [Deployment Models](#2-deployment-models)
3. [Prerequisites](#3-prerequisites)
4. [Configuration & Secrets](#4-configuration--secrets)
5. [Database Migrations — None](#5-database-migrations--none)
6. [Worker / Background Process — None](#6-worker--background-process--none)
7. [Ollama Configuration (Forward-Looking)](#7-ollama-configuration-forward-looking)
8. [GPU Acceleration (Not Applicable Today)](#8-gpu-acceleration-not-applicable-today)
9. [Production Checklist](#9-production-checklist)

---

## 1. Overview

The app is a single Streamlit process. Deploying it means: run the container (or the Python process), expose port **8501** (or `$PORT`), front it with a TLS + auth + WebSocket-aware proxy, and probe `GET /_stcore/health`.

**Start command:**

```bash
streamlit run app.py --server.port $PORT --server.address 0.0.0.0 --server.headless true
```

**Runtime:** Python 3.11.9. Dependencies: `streamlit>=1.58.0`, `pandas>=2.1.0`, `plotly>=5.20.0`, `numpy>=1.26.0`. No system/native deps beyond a `python:3.11-slim` base. A `Dockerfile` (non-root user `app`, `EXPOSE 8501`, `HEALTHCHECK` on `/_stcore/health`) and a `render.yaml` Blueprint ship with the project.

---

## 2. Deployment Models

| Model | When to use | Operator guide |
|-------|-------------|----------------|
| Render (managed PaaS) | Fastest path; `render.yaml` Blueprint; `$PORT` injected | see `render.yaml` + [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) for parity |
| Local development | Iterate on the app | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | One VM + nginx + systemd | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | Multi-replica, HA, autoscaling | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) |
| AWS (Commercial + GovCloud) | ECS/EKS behind ALB (OIDC/Cognito) | [../deployments/AWS.md](../deployments/AWS.md) |
| Azure (Commercial + Government) | App Service / AKS, Entra ID Easy Auth | [../deployments/AZURE.md](../deployments/AZURE.md) |
| Airgapped | Offline registry + self-hosted everything | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) |

All models require **WebSocket upgrade** support and **session affinity** at the proxy/LB (Streamlit runs over WebSockets).

---

## 3. Prerequisites

- Python **3.11.9** (or the shipped `python:3.11-slim` container).
- Ability to run one long-lived process bound to `0.0.0.0:$PORT`.
- A reverse proxy / load balancer that supports **TLS termination**, **WebSocket upgrade**, and **sticky sessions** (auth is added here — see §9 and [SECURITY.md](SECURITY.md)).
- For multi-replica deployments: a **shared volume** for `branding.json` (or treat branding as config-as-code) — see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

---

## 4. Configuration & Secrets

The application itself requires **no secrets**. All configuration is optional tuning; secrets exist only for the proxy auth layer.

| Variable | Example | Purpose |
|----------|---------|---------|
| `PORT` | `8501` | Injected by Render/PaaS; bound via `--server.port` |
| `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` | `50` | Cap CSV upload size (MB); bounds DoS surface |
| `STREAMLIT_BROWSER_GATHER_USAGE_STATS` | `false` | Disable Streamlit usage telemetry |
| `STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION` | `true` | XSRF token on forms/uploads (default on) |
| `STREAMLIT_SERVER_HEADLESS` | `true` | No browser auto-open, no email prompt |

**Secrets:** none for the app. Any OIDC/OAuth client secrets belong to the reverse proxy (oauth2-proxy, ALB OIDC, Entra ID) and must be stored in the platform secret manager (AWS Secrets Manager, Azure Key Vault, Kubernetes Secret, Render env group) — never committed. `branding.json` contains no secrets (logo/name/accent only).

---

## 5. Database Migrations — None

**There is no database.** This app has no schema, no ORM, and no migrations to run.

- Uploaded CSV data is processed **entirely in memory** with pandas and is never persisted.
- The only server-side persisted state is `branding.json` on local disk, which is created/updated at runtime by `modules/branding.py` — there is nothing to migrate.

Do not add migration steps, `alembic`/`flyway` jobs, or DB connection strings to any deployment. If they appear, they are wrong.

---

## 6. Worker / Background Process — None

**There is no worker, cron, queue, or background process.** All computation (`load_and_detect` → `compute_kpis` → chart builders → `generate_insights`) is **synchronous** within a single Streamlit rerun for the requesting session. Deploy exactly one process type: the Streamlit web server. Do not provision a worker dyno/pod/service.

---

## 7. Ollama Configuration (Forward-Looking)

> **Not used today.** The app performs **no AI/LLM calls** — insights are rule-based (`modules/insights.py`, numpy statistics). This section describes only how a future *"AI narrative generation"* enhancement *would* be wired, so operators can plan for it. Configure nothing here for the current release.

If a future narrative feature is added, it would call a **self-hosted [Ollama](https://ollama.com/)** endpoint (replacing any hosted AI API — required for airgapped and GovCloud targets):

```bash
# hypothetical future config — NOT active today
OLLAMA_BASE_URL=http://ollama:11434
OLLAMA_MODEL=llama3.1:8b
INSIGHT_NARRATIVE_ENABLED=false   # gate; remains false until the feature ships
```

Shape it would take: the rule engine continues to produce the structured `{icon, headline, detail, severity}` list; a narrative layer would summarize those structured facts via Ollama. The rule engine stays the source of truth so output remains auditable. Until then, leave `INSIGHT_NARRATIVE_ENABLED` unset/false and run no Ollama sidecar.

---

## 8. GPU Acceleration (Not Applicable Today)

**Not applicable.** The app runs **no ML inference** — pandas/numpy/Plotly are CPU-only workloads. **CPU is the only mode**; there is nothing to accelerate and no GPU should be provisioned.

*Forward-looking:* GPU acceleration would become relevant **only** alongside the optional Ollama narrative feature in §7 (to speed local LLM inference). If that feature ships, size GPU per the chosen model; otherwise provision CPU-only compute.

---

## 9. Production Checklist

### 9.1 Secrets & identity
- [ ] App requires **no secrets** — confirm none are baked into the image.
- [ ] Reverse-proxy auth configured (oauth2-proxy / ALB OIDC / Cognito / Entra ID Easy Auth / SSO). See [SECURITY.md](SECURITY.md).
- [ ] Prefer **workload identity / IAM roles / IRSA / managed identity** over static keys for any platform integration.
- [ ] OIDC client secret (proxy only) stored in the platform secret manager, not in code.

### 9.2 Transport & exposure
- [ ] **TLS** terminated at the proxy/LB (HTTPS only to clients).
- [ ] **WebSocket upgrade** headers forwarded (`Upgrade`, `Connection`) — Streamlit needs them.
- [ ] **Session affinity / sticky sessions** enabled so a client stays pinned to one replica.
- [ ] Streamlit **XSRF protection** left enabled (`enableXsrfProtection=true`).

### 9.3 Hardening
- [ ] Container runs as **non-root** user `app` (per `Dockerfile`).
- [ ] Filesystem **read-only** except the `branding.json` path (mount a small writable volume for it).
- [ ] **Max upload size** capped (`STREAMLIT_SERVER_MAX_UPLOAD_SIZE`) to bound DoS.
- [ ] **Usage stats disabled** (`gatherUsageStats=false`).

### 9.4 Resilience & operations
- [ ] Liveness/readiness probes point at `GET /_stcore/health` (expect `ok`).
- [ ] **Rolling deploys** configured (immutable image; no migration gate).
- [ ] `branding.json` **backed up** (volume snapshot or committed as config-as-code) — see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).
- [ ] For multi-replica: `branding.json` on a **shared volume** so replicas don't diverge.
