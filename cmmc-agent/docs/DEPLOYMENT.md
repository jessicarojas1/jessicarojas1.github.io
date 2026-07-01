# Deployment Guide — CMMC 2.0 Level 2 Compliance Agent

This guide covers how to deploy the CMMC Level 2 Compliance Agent across all supported
targets. The app is a single synchronous Flask process (`server.py`) backed by two
local JSON files — no database, no worker, no queue.

## Contents

1. [Deployment Models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & Secrets](#3-configuration--secrets)
4. [Database Migrations (there are none)](#4-database-migrations-there-are-none)
5. [The Worker / Background Process (there is none)](#5-the-worker--background-process-there-is-none)
6. [Ollama Configuration (self-hosted LLM)](#6-ollama-configuration-self-hosted-llm)
7. [GPU Acceleration](#7-gpu-acceleration)
8. [Production Checklist](#8-production-checklist)

---

## 1. Deployment Models

| Model | Target | Guide |
|-------|--------|-------|
| Managed PaaS | Render (Blueprint via `render.yaml`, healthCheckPath `/api/dashboard`, `PORT=10000`) | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) for dev; Render uses the committed `render.yaml` |
| Single Linux server | systemd + reverse proxy | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | Deployment + Service + PVC | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| Azure | App Service / container | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| AWS | ECS / EC2 / container | [`../deployments/AWS.md`](../deployments/AWS.md) |
| Airgapped / on-prem | offline registry + self-hosted LLM | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |

All targets run the same artifact: the `python:3.11.9-slim` multi-stage container
(non-root `appuser` uid 10001, `EXPOSE 5050`, `HEALTHCHECK` curls `/api/dashboard`,
`CMD python server.py`), or `python server.py` directly.

---

## 2. Prerequisites

- **Python 3.11.9** (pinned in `.python-version`) — or Docker.
- An **Anthropic API key** (`sk-ant-...`) for the hosted model, OR a self-hosted
  Anthropic-compatible LLM (see [Ollama](#6-ollama-configuration-self-hosted-llm)).
- Outbound HTTPS to `api.anthropic.com` (unless using on-prem inference).
- A writable directory or mounted volume for `status.json` and `settings.json`.

```bash
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt        # anthropic, python-dotenv, rich, flask
```

---

## 3. Configuration & Secrets

Configuration is environment variables, loaded from `.env` via python-dotenv.

| Variable | Example | Purpose |
|----------|---------|---------|
| `ANTHROPIC_API_KEY` | `sk-ant-...` | **Required.** The AI backend. Sole secret. |
| `PORT` | `5050` (local) / `10000` (Render) | Optional HTTP bind port. Default `5050`. |

**Where secrets live per target:**

| Target | Secret store |
|--------|--------------|
| Local dev | `.env` file (gitignored) |
| Render | Dashboard env var, `sync:false` in `render.yaml` |
| Single Linux server | systemd `EnvironmentFile=` or a root-owned `.env` |
| Kubernetes | `Secret` → env var / mounted |
| AWS | Secrets Manager / SSM Parameter Store, injected as env |
| Azure | Key Vault → App Settings |
| Airgapped | offline secret store; or on-prem LLM needs no Anthropic key |

Never commit `.env`; only `.env.example` (which contains only `ANTHROPIC_API_KEY`).

---

## 4. Database Migrations (there are none)

**The app has no database.** There is nothing to migrate.

State is two JSON files created on first write in the app directory / mounted volume:

| File | Contents |
|------|----------|
| `status.json` | Control implementation status (the business data) |
| `settings.json` | Branding (appName, logoUrl, accent) |

No schema, no migration tooling, no seed step. A fresh deploy starts with no files and
creates them the first time a control is marked or settings are saved.

---

## 5. The Worker / Background Process (there is none)

**There is no worker, cron, or queue.** The app is a **single synchronous Flask
process** (`app.run(debug=False, host, port)`). All work — including the agentic
tool-use loop against the Anthropic API — happens inline within the request that
triggered it. There is nothing separate to schedule, scale, or supervise.

---

## 6. Ollama Configuration (self-hosted LLM)

For airgapped or on-prem environments where chat content must not leave the boundary,
you can replace the hosted Anthropic API with a self-hosted LLM served by **Ollama**.

> **Be honest — this is a small code change, not a pure env swap.** The app is built
> around the Anthropic SDK and the model string `claude-opus-4-5`. Two supported
> approaches:

**Option A — Anthropic-compatible proxy.** Point the Anthropic SDK at a proxy that
speaks the Anthropic Messages API and forwards to your local model:

```bash
export ANTHROPIC_BASE_URL="http://your-anthropic-compatible-proxy:PORT"
export ANTHROPIC_API_KEY="dummy-value-if-proxy-requires-one"
```

The SDK honors `ANTHROPIC_BASE_URL`; no code change is needed if such a proxy exists.

**Option B — Repoint to Ollama's OpenAI-compatible endpoint.** Modify `server.py` /
`agent.py` to call Ollama directly and change the model string:

```
Base URL:  http://ollama:11434/v1
Model:     llama3.1:8b   (or your pulled model)
```

This changes the client call sites (Ollama exposes an **OpenAI-compatible** `/v1` API,
not the Anthropic API), so it is a small code edit, not just an env change.

**Bring up Ollama:**

```bash
ollama serve
ollama pull llama3.1:8b
```

See [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) for the full offline
runbook (offline registry/bundles, offline secrets, self-hosted inference).

---

## 7. GPU Acceleration

The **Flask app itself needs no GPU** — only a self-hosted LLM (Ollama) benefits.
If you use the hosted Anthropic API, skip this section entirely.

When self-hosting inference:

| Environment | How to enable GPU |
|-------------|-------------------|
| Docker / single server | NVIDIA GPU + `nvidia-container-toolkit`; run Ollama with `--gpus all` |
| Kubernetes | NVIDIA **device plugin** DaemonSet; request `nvidia.com/gpu` on the Ollama pod |
| CPU-only | Works, but **degrades to CPU** — noticeably slower inference |

**VRAM guidance (rough):**

| Model size | Approx VRAM |
|------------|-------------|
| 8B (e.g. `llama3.1:8b`) | ~8 GB |
| 70B | ~40 GB+ |

Size the LLM to your available VRAM; fall back to CPU only for light/offline use.

---

## 8. Production Checklist

The committed `render.yaml` and `Dockerfile` cover PaaS and container deploys. Before
exposing this app beyond a single local user, work through the following.

### Secrets & identity

- [ ] Store `ANTHROPIC_API_KEY` in a **secret manager** (AWS Secrets Manager, Azure
      Key Vault, K8s Secret) — not in a plain file on a shared host.
- [ ] Use **IAM roles / managed identity / IRSA** to fetch the secret rather than
      static long-lived credentials where the platform supports it.
- [ ] **Rotate** the key on a schedule and on suspected exposure (see
      [`SECURITY.md`](SECURITY.md)).

### Transport & exposure

- [ ] Terminate **TLS** at a reverse proxy (nginx / Caddy / cloud LB).
- [ ] **Do not expose the Flask dev server directly.** `server.py` uses `app.run(...)`
      (Flask's dev server). For production, put it behind a real WSGI server —
      **recommend adding Gunicorn** (it is *not* currently a dependency) plus a reverse
      proxy.
- [ ] Because there is **no auth or CSRF** on any endpoint, the reverse proxy must
      enforce authentication/authorization if the app is reachable by more than one
      trusted user.

### Hardening

- [ ] Use the provided **non-root container** (`appuser` uid 10001, already in the
      Dockerfile).
- [ ] Run the container **read-only** except for the state volume holding
      `status.json` / `settings.json`.
- [ ] **Drop Linux capabilities** and avoid privileged mode.
- [ ] **Restrict egress** to only `api.anthropic.com` (hosted) — or to nothing
      external (on-prem Ollama).

### Resilience & operations

- [ ] Wire the health check to **`GET /api/dashboard`** (no key required) — matches the
      Dockerfile `HEALTHCHECK` and `render.yaml` `healthCheckPath`.
- [ ] **Back up** `status.json` and `settings.json` (see
      [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)).
- [ ] **Scaling caveat:** the two JSON files are the only source of truth. Multiple
      replicas require a **shared RWX volume**, or each replica diverges. Prefer a
      single instance unless you provide shared storage.

---

## See Also

- [`ARCHITECTURE.md`](ARCHITECTURE.md)
- [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)
- [`SECURITY.md`](SECURITY.md)
- Deployment guides: [`../deployments/`](../deployments/)
