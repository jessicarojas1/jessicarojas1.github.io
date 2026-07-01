# ISMS Document Library — Deployment Guide

## Contents

1. [Deployment models](#1-deployment-models)
2. [Prerequisites](#2-prerequisites)
3. [Configuration & secrets](#3-configuration--secrets)
4. [Database migrations](#4-database-migrations) — **N/A**
5. [Worker / background process](#5-worker--background-process) — **N/A**
6. [Ollama configuration](#6-ollama-configuration) — **N/A**
7. [GPU acceleration](#7-gpu-acceleration) — **N/A**
8. [Production checklist](#8-production-checklist)

This is a **Type A static website** (HTML/CSS/JS, no backend). "Deployment" means
publishing static files to a host/CDN and applying transport + header controls.
The per-target operator guides live in [`../deployments/`](../deployments/).

## 1. Deployment models

| Model | When | Guide |
|-------|------|-------|
| Managed PaaS (Render Static Site) | fastest hosted path | [`../render.yaml`](../render.yaml) |
| Local static server | dev / preview | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server (nginx/Apache + TLS) | one VM, full control | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static) | platform-standard, HA | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) |
| Azure Static Web Apps / Blob `$web` (+ Gov) | Azure estates | [../deployments/AZURE.md](../deployments/AZURE.md) |
| AWS S3 + CloudFront (+ GovCloud) | AWS estates | [../deployments/AWS.md](../deployments/AWS.md) |
| Air-gapped (vendored assets) | offline / high-assurance | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) |
| Container (nginx-unprivileged) | any container platform | [`../Dockerfile`](../Dockerfile) |

All models serve the same immutable artifact — the files in the repo. The only
differences are the host, TLS, headers, and cache policy.

## 2. Prerequisites

- A modern browser + a static file server to view (Python 3, Node `http-server`,
  nginx, …).
- Internet access for the Bootstrap/devicon **jsDelivr CDN** assets — unless you
  vendor them (see [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).
- For containers: **Docker 24+**. For cloud: the target CLI (`az`, `aws`,
  `kubectl`) + a deploy identity (**prefer CI OIDC role / managed identity**).
- **No** language runtime, package manager, database, or env vars are required.

> Serve from the **repository root** so the shared assets referenced as
> `../theme.css`, `../script.js`, `../roles.js`, `../users.js`, `../favicon.ico`
> resolve. Serving `isms/` in isolation 404s them (or vendor them first).

## 3. Configuration & secrets

**No application secrets exist** (no backend). The only runtime config is
**client-side, per-browser**:

| Store key | Purpose | Default |
|-----------|---------|---------|
| `localStorage['bsTheme']` | UI theme | `dark` |
| `localStorage['isms_branding']` | `{name, logoUrl, accent}` branding | `{JRojas, "", #ff5811}` |

Hosting-layer configuration (set at the edge/server, not in the files):

| Setting | Where | Value |
|---------|-------|-------|
| Security headers + CSP | `../nginx.conf`, `../render.yaml`, CDN policy | see [SECURITY.md](SECURITY.md) |
| TLS / HTTPS + HSTS | host / CDN | TLS 1.2+, redirect HTTP→HTTPS |
| Cache-Control | host / CDN | HTML `no-cache`; CSS/JS/img long-cache |

The only secrets in scope belong to the **deploy pipeline** (cloud deploy
identity) — keep them out of the repo; prefer keyless OIDC. See each cloud guide.

## 4. Database migrations

**Not applicable.** There is no database. Nothing to migrate, seed, or version.

## 5. Worker / background process

**Not applicable.** There is no worker, queue, cron, or scheduled job. The site is
fully static; all interactivity runs in the browser.

## 6. Ollama configuration

**Not applicable.** The library has **no AI feature** and makes no LLM/inference
calls. There is nothing for Ollama to serve. (Listed only to keep the standard
doc set uniform.)

## 7. GPU acceleration

**Not applicable.** No compute workload, no inference, no GPU. Static file serving
is CPU-trivial.

## 8. Production checklist

### Secrets & identity

- [ ] No secrets committed (there are none for the app; verify no cloud keys leak
      into the repo).
- [ ] Deploy uses a **CI OIDC role / managed identity**, least-privilege
      (`s3:PutObject` + CloudFront invalidation, or Blob write + CDN purge) — not
      static keys.
- [ ] Publish permission on the origin (bucket/container/repo) is restricted.

### Transport & exposure

- [ ] **HTTPS enforced**, HTTP→HTTPS redirect, **HSTS** enabled.
- [ ] TLS 1.2+ only; valid, monitored certificate (auto-renew).
- [ ] Object-store origin is **private**; served only via CDN OAC/OAI (no public
      bucket).

### Hardening

- [ ] **CSP** + `X-Content-Type-Options` + `X-Frame-Options` + `Referrer-Policy`
      + `Permissions-Policy` applied at the edge (values in [SECURITY.md](SECURITY.md)).
- [ ] Bootstrap **SRI** hashes present and correct (`grep integrity= isms/index.html`).
- [ ] Consider **vendoring** CDN assets to narrow CSP to `'self'`
      ([../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).
- [ ] Container image is non-root, pinned base, healthchecked; scanned (Trivy/Grype).
- [ ] Known inline handlers accounted for: the only ones are the per-page
      `onclick="window.print()"` Print buttons (`grep -Rc 'onclick="window.print()"'
      isms/*.html`); the hub + `branding.js` use `addEventListener`. These require
      `'unsafe-inline'` in the CSP today — externalize them to tighten it.

### Resilience & operations

- [ ] Cache-Control tuned (HTML revalidate; assets long-cache) + CDN invalidation
      on deploy.
- [ ] Access logging enabled at the host/CDN.
- [ ] Uptime + TLS-expiry monitoring configured.
- [ ] Git is the source of truth; redeploy = re-publish from a tag/commit
      ([DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- [ ] (Optional CI) link checker + inline-handler/header lint on every PR.

## Verification (all targets)

There is **no login, database, or upload** to verify. Verify the real behaviors:

```bash
# 1. Entry page serves 200
curl -I https://<host>/isms/index.html            # HTTP/2 200

# 2. Local + CDN assets resolve
curl -I https://<host>/isms/isms.css              # 200
curl -I https://<host>/theme.css                  # 200 (shared parent asset)
curl -sI https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css | head -1

# 3. Security headers present
curl -sI https://<host>/isms/index.html | grep -iE 'content-security-policy|x-frame-options|strict-transport'
```

In the browser: the card grid renders, **search + type filters** work, the
**theme toggle** persists across reload (`localStorage['bsTheme']`), and
**Settings → Branding** applies name/accent/logo and persists
(`localStorage['isms_branding']`). CSP console shows no violations.
