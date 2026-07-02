# Deployment — CMMI v2.0 Practice Reference

## Contents

1. [Deployment models](#deployment-models)
2. [Prerequisites](#prerequisites)
3. [Configuration & secrets](#configuration--secrets)
4. [Database migrations](#database-migrations) — N/A
5. [Worker / background process](#worker--background-process) — N/A
6. [Ollama configuration](#ollama-configuration) — N/A
7. [GPU acceleration](#gpu-acceleration) — N/A
8. [Production checklist](#production-checklist)
9. [Per-target guides](#per-target-guides)

This is a **static, client-side site** (HTML/CSS/JS). Deploying it means
publishing files to a static host or CDN. There is **no build step**, no server
runtime, no database, and no secrets consumed by the running site.

> **Deployment invariant:** the page references parent-relative assets
> (`../cmmidev3.js` and the shared `../` files). The published document root must
> be the **repository root**, with the app served at `/cmmi/`. Publishing the
> `cmmi/` folder alone (without `../cmmidev3.js` and the shared assets) produces
> a blank, unstyled page.

## Deployment models

| Model | When to use | Guide |
|-------|-------------|-------|
| Local static server | Development / preview | [../deployments/LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server (nginx/Apache + TLS) | Simple self-hosted | [../deployments/SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static) | Cluster / GitOps estates | [../deployments/KUBERNETES.md](../deployments/KUBERNETES.md) |
| Azure Static Web Apps / Blob `$web` + Front Door | Azure Commercial + Gov | [../deployments/AZURE.md](../deployments/AZURE.md) |
| AWS S3 + CloudFront (OAC) | AWS Commercial + GovCloud | [../deployments/AWS.md](../deployments/AWS.md) |
| Air-gapped internal nginx (vendored assets) | No internet | [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md) |
| Managed PaaS (Render static site) | Zero-ops hosting | `render.yaml` (repo root as publish path) |

## Prerequisites

- A static web host or CDN (or `python3 -m http.server` for local).
- Outbound HTTPS to `cdn.jsdelivr.net` for Bootstrap 5.3.3, Bootstrap Icons
  1.11.3, and SheetJS — **unless** you vendor them (air-gapped).
- The **repo root** available as the publish source so `../` assets resolve.
- No language runtime, package manager, or database.

## Configuration & secrets

The running site consumes **no** environment variables and **no** secrets — it is
client-side. The only identity involved is the **deploy pipeline's** (CI OIDC
role / managed identity) used to push files to the host; those are documented in
the per-target guides, never as static keys.

Configuration you *do* control lives in the serving layer, not the app:

| Setting | Where | Example / value |
|---------|-------|-----------------|
| CSP | app `<meta>` (already set) | see [SECURITY.md](SECURITY.md) |
| Edge security headers | nginx / CloudFront / Front Door | HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` |
| Cache-control | serving layer | short TTL on `index.html`; longer on JS/CSS (mind unversioned `cmmidev3.js`) |
| CDN pins | `index.html` | Bootstrap `5.3.3`, Icons `1.11.3`, SheetJS |

## Database migrations

**N/A.** There is no database. No migration tool, no schema, no `migrate`
command. User state is `localStorage` in the visitor's browser.

## Worker / background process

**N/A.** There is no cron, queue, or background worker. All work happens
synchronously in the browser (render, filter, export, print).

## Ollama configuration

**N/A.** This site has **no AI feature**. There is no LLM call, so no Ollama or
hosted-AI configuration applies — in air-gapped deployments there is nothing to
replace.

## GPU acceleration

**N/A.** No inference or compute workload runs server-side; nothing benefits from
a GPU.

## Production checklist

### Secrets & identity
- [ ] No secrets are baked into the artifact (verified — the site has none).
- [ ] Deploy pipeline uses an **OIDC role / managed identity**, not static keys
      (see AWS/Azure guides).
- [ ] Least-privilege deploy policy (put/delete objects + CDN invalidation only).

### Transport & exposure
- [ ] HTTPS enforced end-to-end; HTTP → HTTPS redirect.
- [ ] `Strict-Transport-Security` set at the edge.
- [ ] TLS via ACM / Front Door / Static Web Apps managed cert.
- [ ] Custom domain + DNS (Route 53 / Azure DNS) as needed.

### Hardening
- [ ] CSP `<meta>` intact and unmodified (or tightened + docs updated).
- [x] SRI present on all CDN assets — Bootstrap CSS + JS bundle, Bootstrap Icons,
      and SheetJS (pinned `xlsx@0.18.5`); see [../OPEN_ITEMS.md](../OPEN_ITEMS.md).
- [ ] Edge headers: `X-Content-Type-Options: nosniff`, `Referrer-Policy`,
      `Permissions-Policy`, `X-Frame-Options`/`frame-ancestors`.
- [ ] `object-src 'none'` and `base-uri 'self'` preserved.

### Resilience & operations
- [ ] Git is the source of truth; artifact is fully rebuildable (no state).
- [ ] Cache-control tuned; CDN invalidation on deploy.
- [ ] Parent assets (`../cmmidev3.js` etc.) included in every publish/build.
- [ ] Post-deploy verification run (below).

## Verification (all targets)

Because there is no login/DB/upload, verify the real client-side behaviors:

1. **Entry page 200:** `curl -I https://<host>/cmmi/` → `HTTP/… 200`.
2. **Assets resolve:** `curl -I https://<host>/cmmidev3.js` → 200 (and the CDN
   Bootstrap/Icons/SheetJS URLs 200).
3. **CSP clean:** open `/cmmi/` in a browser, DevTools console shows **no** CSP
   violations.
4. **Branding applies:** default `JRojas` mark + accent render; Settings →
   Branding change persists after reload.
5. **Theme persists:** toggle dark/light; reload; `localStorage['bsTheme']` holds.
6. **Practices render/filter/search:** ML2/ML3 + domain filters and free-text
   search update the list.
7. **State persists:** set a practice status/notes; reload; it survives
   (`cmmi2_*` keys present).
8. **Export + print:** trigger Excel export → an `.xlsx` downloads; print preview
   renders.

## Per-target guides

See [../deployments/](../deployments/): LOCAL_DEVELOPMENT · SINGLE_LINUX_SERVER ·
KUBERNETES · AZURE · AWS · AIRGAPPED. Architecture context:
[ARCHITECTURE.md](ARCHITECTURE.md). Security detail: [SECURITY.md](SECURITY.md).
