# Architecture — CMMI v2.0 Practice Reference

## Platform

A **pure client-side static site**. There is no application server, database,
API, session, or build pipeline. The browser downloads a single HTML page and a
handful of JavaScript/CSS files and does everything locally: rendering the CMMI
model, filtering/searching, persisting the user's self-assessment in
`localStorage`, and producing an Excel export or a print view.

The site is one project inside the `jessicarojas1.github.io` portfolio monorepo.
`cmmi/` is a **subfolder** that reuses shared assets living at the repository
root via `../` references.

## Design principles

- **Zero backend.** All logic and data are shipped to the browser; nothing is
  computed server-side. This makes it trivially cacheable, CDN-friendly, and
  air-gappable.
- **Data-as-code.** The entire CMMI v2.0 model (practice areas, groups,
  statements, elaborations, examples) is embedded in `../cmmidev3.js`, which also
  contains the rendering, filtering, status, evidence, and export logic.
- **Progressive local state.** Users annotate practices; state lives in
  `localStorage` keyed per practice — never transmitted anywhere.
- **Themeable & brandable.** Dark mode by default; a small `branding.js` lets a
  user override name/logo/accent, persisted locally and applied live.
- **Defense-in-depth via CSP.** A restrictive `Content-Security-Policy` `<meta>`
  tag constrains where scripts, styles, images, fonts, and connections may come
  from.

## Component overview

| Component | File | Responsibility |
|-----------|------|----------------|
| Entry page / UI shell | `cmmi/index.html` | Markup, inline `<style>`, inline `<script>` glue (theme bootstrap, export/print wiring), navbar, filter bar, IMAP overview, practice containers |
| CMMI dataset + engine | `../cmmidev3.js` (~227 KB) | The full ML2–ML3 / 21-PA model **and** the render/filter/search/status/evidence/export logic. The page is inert without it |
| Branding module | `cmmi/branding.js` | Settings → Branding: logo (URL or `data:` upload), display name, accent color; sanitizes + persists to `localStorage['cmmi.branding.v1']`; applies live |
| Shared theme | `../theme.css` | Portfolio-wide CSS custom properties (dark-mode palette) |
| Shared portfolio scripts | `../users.js`, `../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js` | Navbar/role visibility, theme toggle, analytics shim, site-wide search |
| Excel export | SheetJS `xlsx.full.min.js` (CDN) | Serializes the current model + annotations to `.xlsx` |
| Print output | `window.print()` | Browser-native printable reference |

### Script load order (as in `index.html`)

```
1. CDN  https://cdn.jsdelivr.net/.../bootstrap@5.3.3/.../bootstrap.bundle.min.js  (SRI)
2.      ../users.js        (defer)
3.      ../roles.js        (defer)
4.      ../script.js       (defer)
5.      ../analytics.js    (defer)
6.      ../siteSearch.js   (defer)
7.      branding.js        (defer)
8.      ../cmmidev3.js         ← practice data + render/filter/export engine
9. CDN  https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js  (no SRI — see OPEN_ITEMS)
```

An inline `<script>` in `<head>` runs first to set `data-bs-theme` from
`localStorage['bsTheme']` (default `dark`) before paint, avoiding a flash.

## Monorepo structure & where this project sits

```
jessicarojas1.github.io/            # portfolio repo ROOT (the deploy/publish root)
├── cmmidev3.js                     # ← REQUIRED by cmmi/ (parent-relative ../cmmidev3.js)
├── theme.css  favicon.ico          # ← shared, referenced as ../theme.css etc.
├── users.js roles.js script.js analytics.js siteSearch.js   # ← shared parent scripts
├── index.html ...                  # portfolio pages (navbar brand links here)
└── cmmi/                           # ← THIS PROJECT
    ├── index.html                  # entry page
    ├── branding.js
    ├── README.md OPEN_ITEMS.md CLAUDE.md
    ├── Dockerfile render.yaml
    ├── deployments/  (6 guides)
    └── docs/         (this file + 3)
```

> **Deployment invariant:** because the page uses `../` references, the served
> document root must be the **repository root**, with the app reached at
> `/cmmi/`. Deploying the `cmmi/` folder in isolation without the parent assets
> yields a blank page (no dataset) and broken styles.

## Configuration model

There is **no runtime configuration** — no env vars, no config file read by the
app. The only "configuration" surfaces are:

| Surface | Where | Notes |
|---------|-------|-------|
| Theme | `localStorage['bsTheme']` | `dark` (default) / `light` |
| Branding | `localStorage['cmmi.branding.v1']` | JSON: `{ name, logoUrl, accent }` |
| CDN pins | `index.html` | Bootstrap 5.3.3, Icons 1.11.3, SheetJS |
| CSP | `index.html` `<meta>` | see below |
| Cache-control / edge headers | the serving layer | set per deployment guide |

## Request & error contract

Being static, there is no request/response envelope or API error shape. The
relevant "contract" is the **asset graph**: the entry page must resolve every
`<script>`/`<link>` (CDN + parent `../`). If `../cmmidev3.js` fails to load, the
practice containers stay empty; if a CDN asset fails, styling and/or export
break. HTTP status semantics are entirely the serving layer's (200 for the entry
page, 404 for missing assets).

### Local state model (client-side)

Per-practice keys in `localStorage` (per browser, never transmitted):

| Key pattern | Value |
|-------------|-------|
| `cmmi2_s_<practiceNum>`  | status: `ns` / `ip` / `done` / `na` |
| `cmmi2_n_<practiceNum>`  | notes text |
| `cmmi2_o_<practiceNum>`  | owner |
| `cmmi2_td_<practiceNum>` | target date |
| `cmmi2_f_<practiceNum>`  | flag (`1`) |
| `cmmi2_ev_<pa>_<idx>`    | evidence checklist item checked (`1`) |
| `cmmi.branding.v1`       | branding JSON |
| `bsTheme`                | `dark` / `light` |

A JSON snapshot export/import walks every `cmmi2_*` key so a user can back up and
restore their annotations (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).

## Security model (summary)

Full detail in [SECURITY.md](SECURITY.md). The enforced policy is the CSP
`<meta>` tag, reproduced **exactly**:

```
default-src 'self'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net; worker-src blob:; object-src 'none'; base-uri 'self';
```

Highlights: `default-src 'self'` (no top-level `blob:`, tighter than the sibling
`cmmc2` site), `object-src 'none'` and `base-uri 'self'` are hardening wins;
`'unsafe-inline'` remains for scripts/styles because inline `<script>`/`<style>`
and a few inline event handlers exist (honest limitation — see
[OPEN_ITEMS.md](../OPEN_ITEMS.md)). No auth, no PII transmission — annotations
stay in `localStorage`.

## Observability

There is no server to observe. Operationally:

- **Health** = the entry page returns HTTP 200 and assets resolve. Container
  builds expose `/healthz` (nginx) for probes.
- **Client errors** surface only in the browser console (CSP violations, failed
  asset loads). Watch for CSP `Refused to load` messages during changes.
- **Analytics** is a lightweight client shim (`../analytics.js`); no server-side
  metrics/traces exist.

## Deployment topology (at a glance)

```
Browser ──HTTPS──> Edge/CDN (CloudFront / Front Door / nginx) ──> Static origin
                                                                   (repo root incl.
                                                                    cmmi/ + ../cmmidev3.js)
        └──HTTPS──> cdn.jsdelivr.net  (Bootstrap 5.3.3, Icons 1.11.3, SheetJS)
```

Per-target detail: [../deployments/](../deployments/) and
[DEPLOYMENT.md](DEPLOYMENT.md).
