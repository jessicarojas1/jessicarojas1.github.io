# Deployment — `cmmc2`

Deployment guide for the CMMC 2.0 Readiness Assessment Platform. `cmmc2` is a **static,
client-side** site with **no build step, no database, no migrations, and no background
worker**. "Deploying" means publishing static files (`index.html`, `branding.js`, and the
parent shared assets) to a host or CDN and setting the right TLS/cache/security headers.

## Contents
1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [The parent-asset constraint](#3-the-parent-asset-constraint)
4. [Configuration & secrets](#4-configuration--secrets)
5. [Database migrations](#5-database-migrations)
6. [Worker / background process](#6-worker--background-process)
7. [Ollama configuration](#7-ollama-configuration)
8. [GPU acceleration](#8-gpu-acceleration)
9. [Production checklist](#9-production-checklist)
10. [Target guides](#10-target-guides)

## 1. Deployment models

| Model | Summary | Guide |
|---|---|---|
| Managed PaaS (Render) | Static site from `render.yaml`; or the Docker image as a web service | [`render.yaml`](../render.yaml) |
| Local / dev | `python3 -m http.server` from repo root | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | nginx/Apache + TLS + headers | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | nginx-static Deployment + Ingress | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| Azure (Commercial + Gov) | Static Web Apps / Blob `$web` + Front Door | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| AWS (Commercial + GovCloud) | S3 + CloudFront (OAC) + ACM + Route 53 | [`../deployments/AWS.md`](../deployments/AWS.md) |
| Air-gapped | Vendored assets, internal nginx | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |

For the DoD/CUI audience, **AWS GovCloud** and **Azure Government** are the realistic
production targets.

## 2. Prerequisites

- The static files: `cmmc2/index.html`, `cmmc2/branding.js`, and the parent assets
  (`theme.css`, `favicon.ico`, `users.js`, `roles.js`, `script.js`, `analytics.js`,
  `siteSearch.js`) plus the portfolio `index.html` for the Home link.
- A static host / object store / CDN, or Docker + nginx.
- TLS certificate (ACM, Key Vault, Let's Encrypt, or platform-managed).
- Deploy identity: a **federated OIDC role / managed identity** (no static keys).
- Outbound HTTPS to `cdn.jsdelivr.net` from the **client** (unless using the air-gapped
  vendored build). The host itself needs no outbound access.

## 3. The parent-asset constraint

`index.html` uses parent-relative references (`../theme.css`, `../favicon.ico`, `../users.js`,
`../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js`) and the navbar brand
links to `../index.html`. Two supported layouts:

- **Publish the repo root** and serve the app at `/cmmc2/` (what `render.yaml` and the
  Dockerfile do). Simplest; `../` resolves naturally.
- **Publish a self-contained bundle**: copy the parent assets alongside `cmmc2/` so the
  relative paths resolve, and (optionally) rewrite the brand/Home target. Each deployment
  guide shows the exact `cp`/sync commands.

## 4. Configuration & secrets

- **App runtime env vars: none.** The site reads no environment variables.
- **Secrets: none in the app.** The running site holds no secrets. The only secrets are the
  deploy pipeline's — and those should be **short-lived OIDC**, not static keys.
- **Client config** (theme, branding, assessment) is `localStorage` only — see
  [`ARCHITECTURE.md`](ARCHITECTURE.md) §5.

| Variable | Example | Purpose |
|---|---|---|
| _(none — app)_ | — | The app has no runtime environment variables. |
| `CDN pin` (source, not env) | `bootstrap@5.3.3` | Version pin in `index.html` `<link>/<script>` |
| `Cache-Control` (host header) | `no-cache` (html) / `max-age=86400` (assets) | Edge cache policy |
| `Content-Security-Policy` (host header) | see [`SECURITY.md`](SECURITY.md) §3 | Edge CSP mirroring the `<meta>` |

## 5. Database migrations

**N/A — there is no database.** There is nothing to migrate. Do not create a
`database/schema.sql`; it would be meaningless for this app.

## 6. Worker / background process

**N/A — there is no server-side worker, queue, or cron.** All processing (SPRS calculation,
POA&M build, Excel export) happens synchronously in the browser. The CSP permits
`worker-src blob:` for optional blob-backed Web Workers in the client export path, but there
is no server process to run or schedule.

## 7. Ollama configuration

**N/A.** `cmmc2` has **no AI/LLM feature**, so there is no hosted AI API to replace with a
self-hosted Ollama instance. No Ollama endpoint, model, or GPU is required for any
deployment target, including air-gapped.

## 8. GPU acceleration

**N/A.** No compute-intensive/AI workload exists; the app runs entirely on the browser's
main thread (plus optional blob workers). No CUDA, no device plugin, no GPU nodes.

## 9. Production checklist

### Secrets & identity
- [ ] Deploy pipeline uses a **federated OIDC role / managed identity**, least-privilege
      (S3 put/delete + CloudFront invalidation, or Blob write + Front Door purge). No static keys.
- [ ] No secrets committed; `.env` never committed (there is nothing secret to store anyway).

### Transport & exposure
- [ ] Served over **TLS only**; HTTP → HTTPS redirect.
- [ ] `Strict-Transport-Security` (HSTS) enabled.
- [ ] Custom domain + valid cert (ACM / Key Vault / platform-managed).

### Hardening
- [ ] **CSP as an HTTP header** at the edge (in addition to the `<meta>`), including
      `frame-ancestors 'none'`.
- [ ] `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`,
      minimal `Permissions-Policy`.
- [x] **SRI** present on all CDN assets — Bootstrap CSS + JS bundle, Bootstrap Icons, and SheetJS (pinned `xlsx@0.18.5`).
- [ ] Consider self-hosting/vendoring CDN assets for regulated environments.

### Resilience & operations
- [ ] `Cache-Control`: `no-cache` for `index.html`, long cache for static assets.
- [ ] Edge access logs enabled and retained; CSP `report-to` wired if feasible.
- [ ] Rollback = redeploy the previous git commit / previous artifact (see
      [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)).
- [ ] CDN/object-store versioning enabled where available.

## 10. Target guides

Deep, per-target runbooks (topology, identity/least-privilege policy, config tables,
verification, day-2, troubleshooting) live in [`../deployments/`](../deployments/):
[LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) ·
[SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) ·
[KUBERNETES](../deployments/KUBERNETES.md) ·
[AZURE](../deployments/AZURE.md) ·
[AWS](../deployments/AWS.md) ·
[AIRGAPPED](../deployments/AIRGAPPED.md).
