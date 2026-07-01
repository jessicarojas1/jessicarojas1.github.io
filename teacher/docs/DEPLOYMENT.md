# Teacher Hub — Deployment Guide

## Contents

1. [Deployment models](#deployment-models)
2. [Prerequisites](#prerequisites)
3. [Configuration & secrets](#configuration--secrets)
4. [Database migrations](#database-migrations)
5. [Worker / background process](#worker--background-process)
6. [Ollama configuration](#ollama-configuration)
7. [GPU acceleration](#gpu-acceleration)
8. [Production checklist](#production-checklist)

Teacher Hub is a **static, client-side site** — HTML/CSS/JS, no backend, no
database, no build step. "Deploy" means *publish the files behind a static host
and set good headers*. Per-target operator guides live in
[../deployments/](../deployments/); this file is the overview and the production
checklist.

---

## Deployment models

| Model | Guide | When |
|-------|-------|------|
| Local dev | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) | edit + preview on a laptop |
| Managed PaaS (Render) | [../render.yaml](../render.yaml) | one-click static site |
| GitHub Pages | (portfolio default) | zero-infra public hosting |
| Single Linux server | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) | district self-host, TLS, headers |
| Kubernetes | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) | uniform with an existing platform |
| AWS (S3+CloudFront) | [../deployments/AWS.md](../deployments/AWS.md) | Commercial + GovCloud |
| Azure (SWA / Blob+AFD) | [../deployments/AZURE.md](../deployments/AZURE.md) | Commercial + Azure Government |
| Air-gapped | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) | offline school network |

All models serve the same files. The recurring subtlety: Teacher Hub references
`../theme.css` and `../favicon.ico`, so the served layout must include those
parent files (publish the repo root, or copy them one level up).

## Prerequisites

- A static file host or web server (nginx/Apache/S3/Blob/SWA/Render/Pages).
- Outbound HTTPS from the **browser** to `cdn.jsdelivr.net` for Bootstrap +
  Bootstrap Icons — **unless vendored** for offline
  ([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).
- For cloud targets: a deploy identity (OIDC role / managed identity) — see each
  guide's §4. **No app runtime credentials exist.**
- Nothing to compile or install: **no `npm install`, no build.**

## Configuration & secrets

**There are no application secrets and no runtime configuration.** Any value placed
in client JS is public, so none exists. The only "config" is:

| Item | Where | Notes |
|------|-------|-------|
| Vendor version pins | `teacher/index.html` `<link>`/`<script>` | Bootstrap `5.3.3`, Icons `1.11.3`; update SRI if bumped |
| Security headers / CSP | host/edge (nginx, CloudFront, Front Door, SWA) | **not in the HTML** — add at the edge (see below) |
| User settings/branding/state | browser `localStorage` | per-device; not deployed |

Deploy-pipeline credentials (OIDC role / managed identity) live with CI, never in
the repo. Never commit a `.env` (there isn't one).

## Database migrations

**Not applicable.** Teacher Hub has **no database and no migrations.** All state is
per-browser `localStorage` (keys listed in [ARCHITECTURE.md](ARCHITECTURE.md)).
There are no schema files, no `install.php`, and no migration commands to run.

## Worker / background process

**Not applicable.** There is **no worker, queue, cron, or background process.** The
app is entirely event-driven in the browser (button clicks, form input). Nothing
runs server-side.

## Ollama configuration

**Not applicable — N/A.** Teacher Hub has **no AI feature** and makes no LLM/model
or external API calls. There is nothing to route to Ollama or any hosted AI API,
in any deployment model including air-gapped.

## GPU acceleration

**Not applicable — N/A.** No AI/inference, no GPU workloads, no CUDA, no device
plugin. The site runs on any CPU that can render a web page.

## Production checklist

### Secrets & identity
- [ ] Confirm **no secrets** are committed (there are none; keep it that way).
- [ ] Deploy uses an **OIDC role / managed identity**, not static keys
      ([../deployments/AWS.md](../deployments/AWS.md) §4,
      [../deployments/AZURE.md](../deployments/AZURE.md) §4).
- [ ] Deploy identity is **least-privilege** (write bucket/container + invalidate
      cache only).

### Transport & exposure
- [ ] HTTPS only; HTTP → HTTPS redirect.
- [ ] Valid TLS cert (ACM / Front Door / Let's Encrypt / SWA-managed).
- [ ] `Strict-Transport-Security` set at the edge.
- [ ] Origin bucket/container is **private** (served via OAC/Front Door, not
      public).

### Hardening
- [ ] **Add a Content-Security-Policy** at the edge — the HTML ships none. Start
      with `default-src 'self'` + jsDelivr for style/script/font; keep
      `'unsafe-inline'` only until inline handlers are externalized
      ([../OPEN_ITEMS.md](../OPEN_ITEMS.md)).
- [ ] Set `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
      `X-Frame-Options: DENY` / `frame-ancestors 'none'`.
- [ ] Keep **SRI** on Bootstrap CSS/JS; **add SRI** to the Bootstrap Icons CSS
      (currently missing).
- [ ] Prefer **vendoring** Bootstrap/Icons for offline or filtered networks
      ([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).

### Resilience & operations
- [ ] Long `Cache-Control` on `*.css/js/ico`; `no-cache` on `index.html`.
- [ ] Cache invalidation/purge step in the deploy pipeline.
- [ ] Git is the source of truth; the artifact is fully rebuildable
      ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- [ ] Advise teachers to **export Gradebook CSV** and understand that browser
      `localStorage` is per-device and not backed up or synced.
- [ ] Verify post-deploy: entry page 200, assets resolve, headers present, theme
      persists, tabs switch, plan/gradebook save survives reload, CSV downloads,
      template prints, branding applies.
