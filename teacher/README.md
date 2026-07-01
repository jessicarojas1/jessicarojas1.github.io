# Teacher Hub

A one-stop, **client-side** hub for a Utah 5th-grade classroom. Lesson planning,
activities, printable templates, a Utah standards browser, a gradebook, class
management, a calendar, classroom resources, student progress, and classroom
tools — all in the browser, all persisted to `localStorage`. **No backend, no
database, no login, no build step.**

![type](https://img.shields.io/badge/type-static%20site-blue)
![backend](https://img.shields.io/badge/backend-none-lightgrey)
![bootstrap](https://img.shields.io/badge/bootstrap-5.3.3-7952B3)
![icons](https://img.shields.io/badge/bootstrap--icons-1.11.3-7952B3)
![data](https://img.shields.io/badge/data-localStorage%20(private)-success)
<!-- Build status: served from the jessicarojas1.github.io GitHub Pages portfolio. -->

---

## Why it exists

Teachers juggle plans, grades, behavior, IEP notes, and a dozen small tools across
too many apps. Teacher Hub puts them in **one page that works on a single
classroom device with no accounts and no server**. Because there is no backend,
**student data never leaves the browser** — a deliberate privacy stance (see
[docs/SECURITY.md](docs/SECURITY.md)) with the tradeoff that data is per-device and
must be exported to be backed up (see [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)).

## Feature tabs

| Tab | What it does |
|-----|--------------|
| **Planner** | Lesson & unit plans (saved to `localStorage`) |
| **Activities** | Ready-made, expandable classroom activities |
| **Templates** | Printable templates via the browser print dialog |
| **Standards** | Utah standards browser: ELA, Math, Science/SEEd, Social Studies, Utah History, Health/PE; mark standards taught |
| **Gradebook** | Assignments + grades; **CSV export** (`gradebook.csv`) |
| **Class Management** | Communication log, behavior tracking, PBIS, seating chart, IEP notes, supply list |
| **Calendar** | Month view with events |
| **Resources** | Morning meeting, word wall, comments, awards |
| **Progress** | Reading levels, anecdotal notes, math fluency |
| **Tools** | Timer, noise meter, tally, dice, name picker, groups, spinner |

Plus a **Settings** modal (teacher/school/grade, student roster, PBIS goal) and a
**Branding** modal (logo, display name, accent color — persisted and applied live).

## Technology

- **HTML/CSS/JS**, static app. Markup in `index.html`; all app logic in external
  **`app.js`**; the pre-paint theme bootstrap in **`theme-init.js`**. No inline
  `<script>` and no inline `on*` handlers — every handler is a `data-*` attribute
  dispatched by a delegated `addEventListener`, so the page runs under a **strict
  CSP** (`script-src 'self' https://cdn.jsdelivr.net`, no `'unsafe-inline'`).
- **Bootstrap 5.3.3** (CSS + `bootstrap.bundle.min.js`) via jsDelivr CDN, with
  **SRI** on the CSS and JS tags.
- **Bootstrap Icons 1.11.3** via jsDelivr, with **SRI** (`integrity` +
  `crossorigin`).
- **Shared theme** `../theme.css` and favicon `../favicon.ico` from the portfolio
  root. Dark mode is the default (`data-bs-theme` from `localStorage['bsTheme']`).
- **`branding.js`** — logo/name/accent module (key `teacher.branding.v1`), no
  inline handlers, sanitizes logo URLs, escapes user strings.
- **No** SheetJS, **no** Chart.js, **no** bundler, **no** `node_modules`.

> **Parent dependency:** Teacher Hub relies on the parent `../theme.css` and
> `../favicon.ico`, and the navbar brand links to `../` (portfolio home). Serve
> from the repository **root** so those resolve. Unlike some sibling sites, it does
> **not** load `../users.js`, `../roles.js`, `../script.js`, `../analytics.js`, or
> `../siteSearch.js` — only `bootstrap.bundle.min.js` (CDN), `theme-init.js`,
> `branding.js`, and `app.js`.

## Repo layout

```
teacher/
├── index.html            markup only (all tabs; loads external scripts)
├── app.js                all app logic + handler delegation + data backup
├── theme-init.js         pre-paint dark/light theme bootstrap
├── branding.js           branding module (localStorage teacher.branding.v1)
├── nginx.conf            hardened static server config (used by Dockerfile)
├── Dockerfile            nginx static image, non-root, adds headers/CSP
├── render.yaml           Render static-site blueprint
├── README.md  OPEN_ITEMS.md  CLAUDE.md
├── tests/                Playwright smoke suite (smoke.spec.js + config)
├── docs/
│   ├── ARCHITECTURE.md   static SPA, localStorage model, CDN, security posture
│   ├── DEPLOYMENT.md     deployment models + production checklist
│   ├── DISASTER_RECOVERY.md  git = source of truth; localStorage data caveats
│   └── SECURITY.md       no auth, strict CSP, FERPA/PII posture
└── deployments/
    ├── LOCAL_DEVELOPMENT.md   SINGLE_LINUX_SERVER.md   KUBERNETES.md
    └── AWS.md   AZURE.md   AIRGAPPED.md
```
(`../theme.css` and `../favicon.ico` live at the portfolio root.)

## Supported deployment models

Local static server · GitHub Pages · Render static site · single Linux VM (nginx) ·
Kubernetes (nginx static) · AWS (S3 + CloudFront) · Azure (Static Web Apps /
Blob `$web` + Front Door) · Air-gapped (vendored assets + internal nginx). See
**[deployments/](deployments/)** and **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

## Prerequisites

- A modern browser and any static file server. **Nothing to install or build** —
  no Node, no package manager, no compiler.
- Browser outbound HTTPS to `cdn.jsdelivr.net` for Bootstrap + Icons (or vendor
  them for offline — see [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md)).

## Quick start (local)

Serve from the repository **root** so `../theme.css` resolves:

```bash
cd /path/to/jessicarojas1.github.io
python3 -m http.server 8000
# open http://localhost:8000/teacher/
```

Verify the entry page and assets:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8000/teacher/   # 200
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8000/theme.css  # 200
```

Then in the browser: the dark theme loads, all 10 tabs switch, saved plans and
gradebook entries survive reload, the Gradebook exports `gradebook.csv`, templates
print, and branding applies live. Full checklist:
[deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) §7.

## Common commands

| Task | Command |
|------|---------|
| Serve locally | `python3 -m http.server 8000` (from repo root) |
| Build container | `docker build -f teacher/Dockerfile -t teacherhub:local .` (context = repo root) |
| Run container | `docker run --rm -p 8080:8080 teacherhub:local` → http://localhost:8080/teacher/ |
| Regenerate SRI (on a version bump) | `openssl dgst -sha384 -binary FILE \| openssl base64 -A` |
| Run smoke tests | `npx playwright test --config teacher/tests/playwright.config.js` (see [tests/README.md](tests/README.md)) |
| Export classroom data | Gradebook → **Export CSV** (`gradebook.csv`), or Settings → **Export All (JSON)** for a full backup |

## Data & privacy (read this)

All classroom data — including **student names, grades, behavior, and IEP notes** —
is stored **unencrypted in the browser's `localStorage`, per device**. It is never
transmitted anywhere. It is **not backed up or synced** and is **lost if browser
site data is cleared**. Export the Gradebook CSV regularly and see
[docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) and
[docs/SECURITY.md](docs/SECURITY.md) for shared-device and FERPA guidance.

## Documentation

- **Architecture:** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **Deployment:** [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) → target guides in [deployments/](deployments/)
- **Disaster recovery:** [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)
- **Security:** [docs/SECURITY.md](docs/SECURITY.md)
- **Open items / production readiness:** [OPEN_ITEMS.md](OPEN_ITEMS.md)
- **Project guidance for contributors:** [CLAUDE.md](CLAUDE.md)
