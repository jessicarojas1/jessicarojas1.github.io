# CMMC 2.0 Readiness Assessment Platform (`cmmc2`)

A production-grade, **fully client-side** self-assessment tool for U.S. Department of
Defense (DoD) contractors preparing for **CMMC 2.0** certification. It tracks every
**NIST SP 800-171 Rev 2** practice, computes the **SPRS score**, builds a
**POA&M (Plan of Action & Milestones)**, and exports results to **Excel (`.xlsx`)** —
with **no backend, no database, no login, and no build step**. All assessment data
stays in the visitor's browser (`localStorage`).

- **Live entry page:** `index.html`
- **Part of:** the `jessicarojas1.github.io` portfolio monorepo (this is the `cmmc2/`
  subfolder). It **depends on parent-relative shared assets** — see
  [Parent-asset dependency](#parent-asset-dependency).

---

## What it is / why it exists

CMMC 2.0 requires DoD contractors handling Controlled Unclassified Information (CUI) to
implement and attest to the NIST SP 800-171 control set and post a self-assessment
**SPRS score** to the DoD Supplier Performance Risk System. Commercial GRC platforms
that do this are expensive and heavyweight. `cmmc2` is a free, offline-capable,
single-page tool that lets a contractor or consultant:

| Capability | Detail |
|---|---|
| **Level 1** | 17 FAR 52.204-21 basic safeguarding practices |
| **Level 2** | All **110** NIST SP 800-171 Rev 2 practices across **14 domains** |
| **Level 3** | 24 enhanced **NIST SP 800-172** practices (DIBCAC prep) |
| **Per-control status** | `Met` / `Partial` / `Not Met` / `Planned` / `N/A`, plus notes and flags |
| **SPRS score** | Live calculation (Level 2 basis; max 110, weighted deductions) |
| **POA&M** | Auto-built from non-`Met` controls, with target dates |
| **Export** | Assessment + POA&M to `.xlsx` (SheetJS); assessment JSON backup via Blob download |
| **Persistence** | `localStorage` (per-browser) — no server, nothing leaves the browser |
| **Branding** | Logo / org name / accent color via `branding.js` (see [Settings & Branding](#settings--branding)) |

> All data stays in your browser. There is no telemetry of assessment content and no
> server-side storage of CUI.

---

## Supported deployment models

This is a static site; every model below serves the same static artifact. See the
[`deployments/`](deployments/) guides:

| Target | Guide |
|---|---|
| Laptop / dev | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux VM (nginx/TLS) | [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx static) | [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) |
| Azure (Static Web Apps / Blob `$web` + Front Door; **Commercial + Gov**) | [`deployments/AZURE.md`](deployments/AZURE.md) |
| AWS (S3 + CloudFront OAC; **Commercial + GovCloud**) | [`deployments/AWS.md`](deployments/AWS.md) |
| Air-gapped (vendored assets, internal nginx) | [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md) |

Given the DoD/CUI audience, **AWS GovCloud** and **Azure Government** are the realistic
production targets. See also the consolidated [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## Repo layout

```
cmmc2/
├── index.html              # The entire single-page app: markup, inline <style>, inline <script>,
│                           #   the 110/17/24 practice datasets, SPRS + POA&M logic, xlsx export
├── branding.js             # Settings→Branding module (logo/name/accent; localStorage; no inline handlers)
├── README.md               # This file
├── CLAUDE.md               # Project guidance for this app
├── OPEN_ITEMS.md           # Production-readiness register
├── Dockerfile              # nginx:alpine static image, non-root, healthcheck
├── render.yaml             # Render static-site Blueprint
├── deployments/            # 6 operator guides (see table above)
└── docs/                   # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
```

### Parent-asset dependency

`cmmc2/` is **not fully self-contained**. `index.html` references parent-relative assets
from the portfolio root (`../`):

| Asset | Reference | Role |
|---|---|---|
| `../theme.css` | `<link rel="stylesheet">` | Shared portfolio theme (dark/light tokens) |
| `../favicon.ico` | `<link rel="icon">` | Favicon |
| `../users.js` | `<script defer>` | Shared user/session stub |
| `../roles.js` | `<script defer>` | Shared roles / login-modal stub |
| `../script.js` | `<script defer>` | Shared site chrome / theme toggle |
| `../analytics.js` | `<script defer>` | Shared analytics stub |
| `../siteSearch.js` | `<script defer>` | Shared site search |
| `../index.html` | navbar brand + Home link | Portfolio home |

A standalone deployment of **only** `cmmc2/` **must also ship these parent files** (copy
them alongside, or serve the whole repo root). If they are missing the page still loads
the assessment, but the shared theme, nav chrome, and search degrade. Each deployment
guide documents how to satisfy this.

---

## Technology & dependencies

No package manager, no bundler, no `node_modules`. Dependencies are loaded at runtime:

| Dependency | Version | Source | Integrity |
|---|---|---|---|
| Bootstrap (CSS) | **5.3.3** | `cdn.jsdelivr.net` | SRI `sha384-QWTKZ…` present |
| Bootstrap (JS bundle) | **5.3.3** | `cdn.jsdelivr.net` | SRI `sha384-Yvpcr…` present |
| Bootstrap Icons | **1.11.3** | `cdn.jsdelivr.net` | (no SRI — see OPEN_ITEMS) |
| SheetJS (`xlsx.full.min.js`) | latest (`/npm/xlsx`, unpinned) | `cdn.jsdelivr.net` | (no SRI/version pin — see OPEN_ITEMS) |
| Portfolio shared assets | repo-local | `../` | n/a |

**Runtime:** any modern browser (Chromium, Firefox, Safari). Dark mode is the default
(`data-bs-theme` seeded from `localStorage['bsTheme']`).

---

## Prerequisites

- **To run locally:** Python 3 (or any static file server) and a browser. Internet
  access is required for the CDN assets unless you vendor them
  ([`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md)).
- **To deploy:** target-specific (see the relevant guide). No compiler/toolchain.

---

## Quick start (local development)

Serve from the **repo root** so parent `../` assets resolve, then open the subpath:

```bash
# from /home/user/jessicarojas1.github.io  (the repo root, NOT cmmc2/)
python3 -m http.server 8000
# then browse to:
#   http://localhost:8000/cmmc2/
```

> Serving from inside `cmmc2/` will 404 the `../theme.css`, `../*.js`, and `../favicon.ico`
> references. Always serve from the repo root. Full detail:
> [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md).

---

## Common commands

```bash
# Serve locally from repo root
python3 -m http.server 8000

# Smoke-check the entry page returns 200
curl -sI http://localhost:8000/cmmc2/ | head -n1

# Confirm parent assets resolve (expect 200 each)
for a in theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js; do
  printf '%s ' "$a"; curl -so /dev/null -w '%{http_code}\n' "http://localhost:8000/$a"
done

# Build the container image (context = repo root; see Dockerfile header)
docker build -f cmmc2/Dockerfile -t cmmc2:local .

# Run it
docker run --rm -p 8080:8080 cmmc2:local
```

---

## Settings & Branding

Click the gear icon in the navbar to open **Settings → Branding** (`branding.js`):

- **Logo** via URL or uploaded file (stored as a `data:` URL — works offline).
- **Organization / product display name** (replaces the `JRojas` mark and document title).
- **Primary accent color** (overrides the `--bs-primary` CSS custom property live).

Branding is sanitized (logo URLs restricted to `http(s)://` or `data:image/...`), escaped
on injection, persisted to `localStorage['cmmc2.branding.v1']`, and applied live with
graceful fallback to the text mark on a broken logo. Default accent is `#ff5811`.

---

## Build status

| Item | Status |
|---|---|
| Build step | None (static; nothing to compile) |
| Automated tests | None yet — see [`OPEN_ITEMS.md`](OPEN_ITEMS.md) |
| CSP | Enforced via `<meta>` (see [`docs/SECURITY.md`](docs/SECURITY.md)) |
| SRI | Bootstrap CSS + JS pinned; Icons + SheetJS not yet — see [`OPEN_ITEMS.md`](OPEN_ITEMS.md) |

<!-- Add CI badges here once a workflow (e.g. link-check / htmlproofer) is added. -->

---

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — static SPA design, data model, CSP, CDN-vs-vendored.
- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — hosting models, production checklist (headers/TLS/SRI/CSP/cache).
- [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md) — git as source of truth; rebuildable artifact; localStorage caveat.
- [`docs/SECURITY.md`](docs/SECURITY.md) — CSP, SRI, CDN/dependency risk, client-side data handling, reporting.
- [`OPEN_ITEMS.md`](OPEN_ITEMS.md) — honest production-readiness register.
- [`CLAUDE.md`](CLAUDE.md) — project rules for maintainers/agents.

---

## License / disclaimer

`cmmc2` is a readiness aid, **not** an official CMMC/DoD tool and **not** a substitute for
a C3PAO assessment. SPRS scores it computes are estimates; the authoritative submission is
made at [https://www.sprs.csd.disa.mil](https://www.sprs.csd.disa.mil).
