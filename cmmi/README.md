# CMMI v2.0 — Full Practice Reference (All Levels)

A complete, **client-side** CMMI v2.0 practice reference covering maturity levels
**ML2–ML3**, all **21 practice areas**, every practice group, and every practice
statement with elaboration and compliance examples. Users filter by level
(ML2/ML3), domain, and free-text search, then record per-practice
status / notes / flags / evidence, and export to **Excel (.xlsx)** or **print**.

No backend. No database. No authentication. No build step. It is a single
static HTML page (`index.html`) plus a small branding module (`branding.js`),
driven by a large shared data/logic file that lives at the **repository root**
(`../cmmidev3.js`, ~227 KB).

> **Live entry point (in the portfolio site):** `/cmmi/index.html`
> The navbar brand links back to the portfolio home at `../index.html`.

---

## Why it exists

Practitioners preparing for or maintaining a CMMI v2.0 appraisal need a fast,
offline-capable way to browse every practice, see the model text (statement +
elaboration + example activities/work products), and keep a lightweight
self-assessment (status, owner, target date, notes, evidence checklist) without
standing up a GRC platform. This tool does that entirely in the browser: your
annotations never leave your device (they live in `localStorage`), and the whole
reference exports to a spreadsheet for sharing.

---

## Supported deployment models

This is a genuine static site. It deploys anywhere static files can be served.

| Model | Guide |
|-------|-------|
| Local development (static server) | [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server (nginx/Apache + TLS) | [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static Deployment) | [deployments/KUBERNETES.md](deployments/KUBERNETES.md) |
| Azure (Static Web Apps / Blob `$web` + Front Door; Commercial + Gov) | [deployments/AZURE.md](deployments/AZURE.md) |
| AWS (S3 + CloudFront OAC; Commercial + GovCloud) | [deployments/AWS.md](deployments/AWS.md) |
| Air-gapped (vendored assets, internal nginx) | [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) |

Deep-dive docs: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) ·
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) ·
[docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) ·
[docs/SECURITY.md](docs/SECURITY.md)

---

## Repository layout (this project)

```
cmmi/
├── index.html                 # The entire app UI + inline <style>/<script> glue (~2200 lines)
├── branding.js                # Settings → Branding module (logo/name/accent), localStorage-persisted
├── README.md                  # This file
├── OPEN_ITEMS.md              # Honest production-readiness register
├── CLAUDE.md                  # Project guidance / standing rules
├── Dockerfile                 # nginx static image (non-root, healthcheck)
├── render.yaml                # Render static-site blueprint
├── deployments/               # 6 operator guides (local, VM, k8s, Azure, AWS, airgapped)
└── docs/                      # 4 canonical docs (arch, deploy, DR, security)
```

**Critical parent-relative dependencies (live at the repo ROOT, one level up):**

```
../cmmidev3.js     # ~227 KB — the CMMI practice dataset + render/filter/export logic (REQUIRED)
../theme.css       # shared portfolio theme (dark-mode variables)
../favicon.ico     # site favicon
../users.js        # shared portfolio nav/role helpers
../roles.js        # shared portfolio nav/role helpers
../script.js       # shared portfolio site script (navbar, theme toggle)
../analytics.js    # shared portfolio analytics shim
../siteSearch.js   # shared portfolio site search
```

> **This directory is a subfolder of the `jessicarojas1.github.io` portfolio.**
> `cmmi/` does **not** render standalone from its own folder alone — it needs the
> parent `../` assets, and it will not render or export at all without
> `../cmmidev3.js`. When deploying `cmmi/` as its own artifact you **must** also
> ship those parent files (see [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and
> each deployment guide).

---

## Technology (exact versions)

| Component | Version | Source | SRI |
|-----------|---------|--------|-----|
| Bootstrap (CSS + JS bundle) | 5.3.3 | jsDelivr CDN | ✅ CSS + JS bundle |
| Bootstrap Icons | 1.11.3 | jsDelivr CDN | ❌ (see OPEN_ITEMS) |
| SheetJS (`xlsx.full.min.js`) | latest `xlsx` dist tag | jsDelivr CDN | ❌ (see OPEN_ITEMS) |
| Excel export | SheetJS `XLSX.writeFile` | — | — |
| Print output | `window.print()` | browser | — |
| Theme | `data-bs-theme` from `localStorage['bsTheme']`, dark default | inline head script | — |

No Node build, no bundler, no transpile step. The only "dependencies" are the
three CDN assets above plus the parent portfolio assets listed earlier.

---

## Prerequisites

- Any static file server (for local dev, `python3` ≥ 3.7 gives you `http.server`).
- A modern browser (Chromium, Firefox, Safari) with `localStorage` enabled.
- **Network access to `cdn.jsdelivr.net`** for Bootstrap / Icons / SheetJS —
  unless you vendor them (see [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md)).

There is **no** database, migration, secret, worker, or login to configure.

---

## Quick start (local development)

Serve from the **repository root** so the parent `../` assets resolve:

```bash
# from the repo root: /home/user/jessicarojas1.github.io
python3 -m http.server 8000
# then open:
#   http://localhost:8000/cmmi/
```

Opening `cmmi/index.html` directly via `file://` will **fail** to load
`../cmmidev3.js` and the CDN assets under the CSP — always serve over HTTP from
the repo root. Full walkthrough: [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md).

---

## Common commands

| Task | Command |
|------|---------|
| Serve locally (from repo root) | `python3 -m http.server 8000` |
| Verify entry page returns 200 | `curl -I http://localhost:8000/cmmi/` |
| Verify parent data file resolves | `curl -I http://localhost:8000/cmmidev3.js` |
| Build container image | `docker build -f cmmi/Dockerfile -t cmmi-ref .` (context = repo root) |
| Run container | `docker run --rm -p 8080:8080 cmmi-ref` then open `http://localhost:8080/cmmi/` |
| Lint HTML (optional) | `npx html-validate cmmi/index.html` |

---

## Build status

<!-- badges -->
![type](https://img.shields.io/badge/type-static%20site-blue)
![build](https://img.shields.io/badge/build-none%20(no%20build%20step)-lightgrey)
![bootstrap](https://img.shields.io/badge/bootstrap-5.3.3-7952b3)
![license](https://img.shields.io/badge/license-portfolio-lightgrey)

> No CI pipeline is wired for this subfolder yet (see
> [OPEN_ITEMS.md](OPEN_ITEMS.md)). Badges are placeholders.

---

## Dependencies & extensions required

- **Runtime (browser):** Bootstrap 5.3.3, Bootstrap Icons 1.11.3, SheetJS
  (`xlsx`) — all from `cdn.jsdelivr.net`.
- **Parent portfolio assets (bundled at deploy):** `../cmmidev3.js`,
  `../theme.css`, `../favicon.ico`, `../users.js`, `../roles.js`, `../script.js`,
  `../analytics.js`, `../siteSearch.js`.
- **Server:** any static web server (nginx/Apache/`http.server`/S3/Blob). No
  language runtime, no PHP/Python/Node extensions, no database drivers.

---

## Documentation map

- Architecture & internals → [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Deploy models & production checklist → [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
- Backup / rebuild / RPO-RTO → [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)
- CSP / SRI / client-side data model → [docs/SECURITY.md](docs/SECURITY.md)
- Honest open items → [OPEN_ITEMS.md](OPEN_ITEMS.md)
- Contributor rules → [CLAUDE.md](CLAUDE.md)
