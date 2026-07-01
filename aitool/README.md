# AI Tool Evaluation Framework (`aitool`)

An audit-ready, **static** framework for evaluating and onboarding new AI tools in
aerospace and defense environments. It maps the adoption workflow to **CMMC 2.0
(L2/L3)**, **NIST SP 800-171**, **ISO/IEC 27001:2022**, and **DFARS 252.204-7012**,
and ships the governing policy, the evaluation procedure, the working templates, and
a client-side pipeline tracker.

> **What it is technically:** a client-side, CDN-delivered Bootstrap 5 website. There
> is **no backend, no database, no server-side auth, and no build step**. All state
> (theme, branding, tracker data) lives in the browser's `localStorage`. It deploys as
> plain static files behind any web server, object store, or static-hosting platform.

---

## Why it exists

Defense contractors must vet every new AI tool against CUI-handling obligations before
adoption. This framework gives an ISSO/CISO a repeatable, documented, and demonstrable
process — the policy that governs it, the procedure that runs it, the templates that
capture the evidence, and a tracker that shows tools moving through the six-phase
lifecycle.

## Contents

| File | Doc ID | Purpose |
|------|--------|---------|
| `index.html` | — | Framework landing page: lifecycle, document index, regulatory quick reference. **Entry point.** |
| `pol-ai-001-ai-tool-adoption-policy.html` | POL-AI-001 | Governing AI Tool Adoption Policy (roles, prohibited uses, CUI rules). |
| `pro-ai-001-ai-tool-evaluation-process.html` | PRO-AI-001 | Step-by-step evaluation procedure (intake → monitoring). |
| `tmp-ai-001-evaluation-checklist.html` | TMP-AI-001 | Interactive compliance checklist (CMMC/NIST/DFARS/ISO). |
| `tmp-ai-002-risk-assessment.html` | TMP-AI-002 | Risk assessment template with likelihood/impact scoring. |
| `tmp-ai-003-vendor-questionnaire.html` | TMP-AI-003 | Vendor security due-diligence questionnaire. |
| `vendor-tracker.html` | LIVE | Kanban-style evaluation pipeline tracker (localStorage, JSON export). |
| `branding.js` | — | Settings/Branding module (logo, name, accent) persisted to `localStorage`. |

## Supported deployment models

Because the artifact is static files, every hosting model is a static-hosting model:

| Model | Guide |
|-------|-------|
| Local development (any static server) | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server (nginx/Apache + TLS) | [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static Deployment) | [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) |
| Azure (Static Web Apps / Blob `$web` + Front Door; Commercial + Gov) | [`deployments/AZURE.md`](deployments/AZURE.md) |
| AWS (S3 + CloudFront OAC; Commercial + GovCloud) | [`deployments/AWS.md`](deployments/AWS.md) |
| Air-gapped (vendored assets, internal nginx) | [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md) |
| Managed PaaS (Render static site / Docker) | [`render.yaml`](render.yaml), [`Dockerfile`](Dockerfile) |

## Repo layout

`aitool/` is one project inside the `jessicarojas1.github.io` GitHub Pages monorepo.

```
aitool/
├── index.html                              # entry page
├── pol-ai-001-*.html                       # policy
├── pro-ai-001-*.html                       # procedure
├── tmp-ai-00{1,2,3}-*.html                 # templates
├── vendor-tracker.html                     # pipeline tracker
├── branding.js                             # branding module (local)
├── Dockerfile                              # nginx:alpine static image
├── render.yaml                             # Render static-site blueprint
├── README.md / OPEN_ITEMS.md / CLAUDE.md
├── deployments/  (6 operator guides)
└── docs/         (ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY)
```

> **Shared parent assets:** the pages reference assets from the parent repo —
> `../theme.css`, `../isms/isms.css`, `../users.js`, `../roles.js`, `../script.js`,
> `../favicon.ico`. `aitool/` is **not fully self-contained**; deploying it standalone
> requires either deploying the whole site repo or vendoring those files next to the
> pages. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and
> [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md).

## Technology

- **HTML5 + Bootstrap 5.3.3** (CSS + `bootstrap.bundle.min.js`) delivered from
  `cdn.jsdelivr.net` with **Subresource Integrity** (`integrity=` + `crossorigin`).
- **Vanilla JavaScript** (no framework, no bundler). `branding.js` is an IIFE; theme
  and RBAC-demo helpers come from parent `script.js` / `roles.js` / `users.js`.
- **Browser `localStorage`** for all persistence (`bsTheme`, `aitool.branding.v1`,
  vendor-tracker store). No cookies, no server sessions.
- **No backend, no database, no server-side language, no package manager, no build.**

## Prerequisites

- Any static file server (development: Python 3, Node, or nginx).
- A modern evergreen browser.
- **Outbound HTTPS to `cdn.jsdelivr.net`** at runtime for Bootstrap — unless you
  vendor the assets (see [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md)).
- For cloud/CDN deploys: a CI identity (OIDC role / managed identity) with permission
  to publish objects and invalidate caches — **no static keys**.

## Quick start (local development)

```bash
# from the repo root so ../theme.css, ../script.js, etc. resolve
cd /path/to/jessicarojas1.github.io
python3 -m http.server 8000
# open http://localhost:8000/aitool/index.html
```

Serving only the `aitool/` directory works for the pages themselves but the shared
`../` assets 404 — serve from the repo root, or vendor the shared files. See
[`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md).

## Common commands

| Task | Command |
|------|---------|
| Serve locally (Python) | `python3 -m http.server 8000` (from repo root) |
| Serve locally (Node) | `npx http-server -p 8000 .` |
| Build container image | `docker build -t aitool ./aitool` |
| Run container | `docker run --rm -p 8080:8080 aitool` → http://localhost:8080 |
| Smoke test | `curl -I http://localhost:8080/` (expect `200`) |

## Build status

No CI pipeline is defined for this subproject yet (static site, no tests/build). See
[`OPEN_ITEMS.md`](OPEN_ITEMS.md). Recommended: an HTML-lint + link-check + a headers/CSP
check job, plus OIDC-based deploy — badges to be added when the workflow lands.

## Dependencies & extensions required

| Dependency | Version | Delivery | Notes |
|------------|---------|----------|-------|
| Bootstrap CSS | 5.3.3 | jsDelivr CDN (SRI) | `bootstrap.min.css` |
| Bootstrap JS bundle | 5.3.3 | jsDelivr CDN (SRI) | `bootstrap.bundle.min.js` (includes Popper) |
| Parent `theme.css` | repo | local `../theme.css` | site theme |
| Parent `isms/isms.css` | repo | local `../isms/isms.css` | card/grid styling |
| Parent `script.js` / `roles.js` / `users.js` | repo | local `../*.js` | theme toggle + client-side RBAC demo |
| `branding.js` | this project | local | branding settings |

No server-side runtime, no OS packages, no database extensions, no scanner binaries.

## Documentation

- Architecture — [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Deployment — [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- Disaster recovery — [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md)
- Security — [`docs/SECURITY.md`](docs/SECURITY.md)
- Per-target operator guides — [`deployments/`](deployments/)
- Open items — [`OPEN_ITEMS.md`](OPEN_ITEMS.md)

---

© 2025 Jessica Rojas — AI Tool Evaluation Framework.
