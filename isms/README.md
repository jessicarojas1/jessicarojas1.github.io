# ISMS Document Library

A complete, audit-ready collection of **ISO/IEC 27001:2022** Information Security
Management System (ISMS) documents — **18 policies, 12 procedures, and 12
templates** — presented as a fast, client-side static website with search,
type filtering, dark mode, print/PDF-friendly layouts, and live branding.

![type](https://img.shields.io/badge/type-static%20site-blue)
![framework](https://img.shields.io/badge/UI-Bootstrap%205.3.3-7952b3)
![standard](https://img.shields.io/badge/standard-ISO%2FIEC%2027001%3A2022-0dcaf0)
![backend](https://img.shields.io/badge/backend-none-lightgrey)
![build](https://img.shields.io/badge/build-none%20(static)-success)

---

## What it is

The ISMS Document Library is a browsable reference set for a mature ISMS. Each
document is a standalone HTML page styled for both screen and print, with a
document-control header (ID, owner, version, control mapping) and ISO 27001:2022
Annex A / clause references. The landing page (`index.html`) is a searchable,
filterable hub over all documents.

It is a **Type A static website**: pure HTML/CSS/JS, served from any static host.
There is **no backend, no database, no build step, no server-side code, and no
server-side authentication.** All state (theme + branding) lives in the visitor's
browser via `localStorage`.

## Why it exists

- Give practitioners a ready-to-adapt, control-mapped ISMS document set aligned
  to ISO/IEC 27001:2022 (clauses 4–10 + Annex A themes 5–8).
- Demonstrate a governance documentation system that is auditable, printable, and
  trivially hostable (no infrastructure to run or secure server-side).
- Serve as a portfolio piece within the larger `jessicarojas1.github.io` site.

## Supported deployment models

| Model | Guide |
|-------|-------|
| Local static server (dev) | [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux VM (nginx/Apache + TLS) | [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (nginx-static Deployment) | [deployments/KUBERNETES.md](deployments/KUBERNETES.md) |
| Azure (Static Web Apps / Blob `$web` + Front Door; Commercial + Gov) | [deployments/AZURE.md](deployments/AZURE.md) |
| AWS (S3 + CloudFront OAC; Commercial + GovCloud) | [deployments/AWS.md](deployments/AWS.md) |
| Air-gapped (vendored assets, offline bundle) | [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) |
| Managed PaaS (Render Static Site) | [`render.yaml`](render.yaml) |
| Container (nginx-unprivileged) | [`Dockerfile`](Dockerfile) |

## Repository layout

```
isms/
├── index.html                     # Searchable/filterable document hub (entry page)
├── isms.css                       # Document + hub styling (loads after ../theme.css)
├── branding.js                    # Settings → Branding (localStorage: isms_branding)
├── nginx.conf                     # Server block for the container image
├── Dockerfile                     # nginx-unprivileged static image (non-root, :8080)
├── render.yaml                    # Render static-site blueprint
├── README.md · OPEN_ITEMS.md · CLAUDE.md
├── docs/                          # ARCHITECTURE · DEPLOYMENT · DISASTER_RECOVERY · SECURITY
├── deployments/                   # 6 operator guides (see table above)
│
├── pol-001-information-security-policy.html … pol-018-privacy-data-protection-policy.html   # 18 policies
├── pro-001-risk-assessment-procedure.html … pro-012-supplier-assessment-procedure.html      # 12 procedures
└── tmp-001-statement-of-applicability.html … tmp-012-information-security-objectives.html    # 12 templates
```

### Naming scheme

Document filenames encode type + sequence + slug, so the set self-sorts and the
type is obvious from the name:

| Prefix | Type | Count | Example |
|--------|------|-------|---------|
| `pol-NNN-*.html` | Policy | 18 | `pol-003-access-control-policy.html` |
| `pro-NNN-*.html` | Procedure | 12 | `pro-007-incident-response-procedure.html` |
| `tmp-NNN-*.html` | Template | 12 | `tmp-002-risk-register.html` |

The hub cards in `index.html` carry `data-type` (`policy` / `procedure` /
`template`) and `data-title` attributes that drive the client-side filter/search.

## Technology

- **HTML5** static pages (42 document pages + the hub = 43 HTML files).
- **Bootstrap 5.3.3** (CSS + JS bundle) loaded from **jsDelivr CDN** with
  **Subresource Integrity** (`integrity="sha384-…" crossorigin="anonymous"`).
- **Custom CSS**: `isms.css` (this app) layered over the shared portfolio
  `../theme.css`.
- **Vanilla JavaScript** — no framework, no bundler:
  - inline hub filter/search module in `index.html`;
  - `branding.js` (this app);
  - shared portfolio scripts `../script.js` (theme toggle, reveal, footer year),
    `../roles.js` + `../users.js` (a **client-side RBAC demo** login modal).
- **Dark mode**: pre-paint inline snippet reads `localStorage['bsTheme']`
  (default `dark`) and sets `data-bs-theme` before first paint.
- **Branding**: `branding.js` persists `{name, logoUrl, accent}` under
  `localStorage['isms_branding']` and applies it live (accent → `--bs-primary`,
  name → title + brand mark, logo → navbar + print header). Logo URLs are
  sanitized (`http(s)://` or `data:image/…` only); the name is HTML-escaped.

## Prerequisites

- **To view/develop:** any static file server (Python 3, Node `http-server`,
  nginx, or a Live Server extension) and a modern browser. Internet access is
  needed for the Bootstrap/devicon CDN assets unless you vendor them
  (see [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md)).
- **To build the container:** Docker 24+.
- **To deploy to cloud:** the relevant CLI (`az`, `aws`, `kubectl`) and a deploy
  identity — **prefer CI OIDC roles / managed identity over static keys** (see the
  deployment guides).
- **No** language runtime, package manager, database, or environment variables
  are required to run the site.

## Quick start (local development)

```bash
# From the repository root (so ../theme.css and ../script.js resolve):
cd /path/to/jessicarojas1.github.io
python3 -m http.server 8000
# then open:
#   http://localhost:8000/isms/index.html
```

> Serve from the **repository root**, not from inside `isms/`. The pages link to
> shared assets one level up (`../theme.css`, `../script.js`, `../roles.js`,
> `../users.js`, `../favicon.ico`); serving `isms/` in isolation 404s those.

### Container

```bash
# From the repository root:
docker build -f isms/Dockerfile -t isms-library:latest .
docker run --rm -p 8080:8080 isms-library:latest
# open http://localhost:8080/isms/index.html
```

## Common commands

| Task | Command |
|------|---------|
| Serve locally | `python3 -m http.server 8000` (from repo root) |
| Serve with Node | `npx http-server . -p 8000` (from repo root) |
| Build image | `docker build -f isms/Dockerfile -t isms-library:latest .` |
| Run image | `docker run --rm -p 8080:8080 isms-library:latest` |
| Smoke test entry page | `curl -I http://localhost:8080/isms/index.html` |
| Count document pages | `ls isms/pol-*.html isms/pro-*.html isms/tmp-*.html | wc -l` → `42` |
| Find CDN references | `grep -R "cdn.jsdelivr.net" isms/*.html` |
| Verify SRI hashes present | `grep -R "integrity=" isms/index.html` |

## Dependencies

**Runtime (loaded by the browser):**

| Dependency | Version | Source | Integrity |
|------------|---------|--------|-----------|
| Bootstrap CSS | 5.3.3 | `cdn.jsdelivr.net/npm/bootstrap@5.3.3` | SRI `sha384-…` + `crossorigin=anonymous` |
| Bootstrap JS bundle | 5.3.3 | `cdn.jsdelivr.net/npm/bootstrap@5.3.3` | SRI `sha384-…` + `crossorigin=anonymous` |
| Devicon SVG icons (GitHub/LinkedIn) | pinned repo path | `cdn.jsdelivr.net/gh/devicons/devicon` | none (static images) |

**Shared parent-portfolio assets (relative `../`):** `theme.css`, `script.js`,
`roles.js`, `users.js`, `favicon.ico`. These must be present one directory above
the library (they are, in this repo) or vendored for standalone hosting.

**Build/deploy tooling (optional):** Docker (container), `az`/`aws`/`kubectl`
(cloud). **No** npm/pip packages, no compiler, no server extensions.

## Documentation

- **Architecture:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **Deployment:** [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
- **Disaster recovery:** [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)
- **Security:** [docs/SECURITY.md](docs/SECURITY.md)
- **Production readiness:** [OPEN_ITEMS.md](OPEN_ITEMS.md)
- **Project guidance:** [CLAUDE.md](CLAUDE.md)
