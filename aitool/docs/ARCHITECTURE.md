# Architecture — AI Tool Evaluation Framework (`aitool`)

## Platform

`aitool` is a **static, client-side website**: HTML5 + Bootstrap 5.3.3 + a small amount
of vanilla JavaScript, delivered from a CDN. There is **no application server, no
database, no server-side language, no authentication server, and no build pipeline**.
The browser is the entire runtime; a web server (or object store + CDN) only needs to
serve files.

It is one project inside the `jessicarojas1.github.io` GitHub Pages monorepo.

## Design principles

1. **Zero backend.** Every capability is achievable with static files + browser APIs.
   This minimizes attack surface, cost, and operational burden.
2. **Evidence, not infrastructure.** The value is the governed content (policy,
   procedure, templates) plus lightweight interactive tools — not a platform.
3. **Progressive enhancement.** Pages are readable as static HTML; JS adds theming,
   branding, checklist scoring, and the tracker.
4. **CSP-friendliness / no inline handlers** (aspirational — see the known exceptions
   in `../OPEN_ITEMS.md`).
5. **Data stays in the browser.** No PII or CUI is transmitted; `localStorage` holds
   all state.

## Component overview

| Component | File(s) | Role |
|-----------|---------|------|
| Landing / index | `index.html` | Lifecycle overview, document index, regulatory quick reference. Entry point. |
| Policy | `pol-ai-001-ai-tool-adoption-policy.html` | Governing policy document (print/PDF-able). |
| Procedure | `pro-ai-001-ai-tool-evaluation-process.html` | Six-phase evaluation procedure. |
| Templates | `tmp-ai-001-evaluation-checklist.html`, `tmp-ai-002-risk-assessment.html`, `tmp-ai-003-vendor-questionnaire.html` | Interactive checklist (scoring), risk matrix, vendor questionnaire (client-side file refs). |
| Tracker | `vendor-tracker.html` | Kanban pipeline over the 6 phases; localStorage store + JSON export; client-side PDF-attachment tracking. |
| Branding module | `branding.js` | Settings modal: logo (URL/`data:`), display name, accent (`--bs-primary`); persists to `localStorage`. |
| Shared theme/RBAC | `../theme.css`, `../isms/isms.css`, `../script.js`, `../roles.js`, `../users.js` | Site theme, card styling, theme toggle, and a **client-side RBAC demo**. |
| External libs | Bootstrap 5.3.3 (CSS + JS bundle) via `cdn.jsdelivr.net` with SRI | Layout, components, modals. |

### Monorepo placement & internal layout

```
jessicarojas1.github.io/          # GitHub Pages repo (many projects)
├── theme.css, script.js,         # shared assets referenced by aitool via ../
│   roles.js, users.js, favicon.ico
├── isms/isms.css
└── aitool/                       # THIS project
    ├── index.html                # entry
    ├── pol-ai-001-*.html         # policy
    ├── pro-ai-001-*.html         # procedure
    ├── tmp-ai-00{1,2,3}-*.html   # templates
    ├── vendor-tracker.html       # tracker
    ├── branding.js               # local branding module
    ├── Dockerfile, nginx.conf, render.yaml
    ├── README.md, OPEN_ITEMS.md, CLAUDE.md
    ├── deployments/*.md          # 6 operator guides
    └── docs/*.md                 # this set
```

> **Self-containment caveat:** the pages use `../` paths into the parent repo. Deploying
> `aitool/` alone leaves those assets unresolved. Deploy the whole repo, or vendor the
> shared files into `aitool/` (see `../deployments/AIRGAPPED.md`).

## Configuration model

There is **no server configuration and no environment variables consumed by the site**.
All "configuration" is either build-time content (the HTML) or client-side user state:

| Setting | Mechanism | Storage key | Default |
|---------|-----------|-------------|---------|
| Theme (dark/light) | `data-bs-theme` on `<html>` + toggle | `localStorage: bsTheme` | `dark` |
| Branding (logo/name/accent) | Settings modal in `branding.js` | `localStorage: aitool.branding.v1` | built-in mark, "AI Tool Evaluation Framework", `#ff5811` |
| Tracker data | `vendor-tracker.html` | `localStorage` (tracker store) | seed data |

Operational configuration (TLS, cache, CSP, headers) lives **in the hosting layer**, not
the app — see `nginx.conf`, `render.yaml`, and each `deployments/*` guide.

## Request & error contract

Being static, the "contract" is HTTP file serving:

- **Routing:** direct file paths. `/` → `index.html`. Each document is its own `.html`.
  No client-side router, no API routes.
- **Response envelope:** raw HTML/CSS/JS/asset bytes. There is no JSON API and no
  response envelope.
- **Error shape/codes:** standard HTTP from the server — `200` (file), `301/302`
  (redirect to `index.html` if configured), `304` (revalidation), `404` (missing file).
  Missing shared `../` assets surface as `404` for those specific requests while the
  page still renders (degraded styling). A broken branding logo URL degrades gracefully
  to the text mark (handled in `branding.js` via an `error` listener).

## Security model

- **No server trust boundary to breach** — no auth, sessions, DB, or upload endpoint.
- **CDN integrity:** Bootstrap is pinned by SRI hash; a tampered CDN response is
  rejected by the browser.
- **Input handling:** branding inputs are escaped (`esc()`) and logo URLs are
  allow-listed to `http(s)://` / `data:image/...` (`sanitizeLogoUrl()`).
- **Client-side RBAC is a demo, not a control** — anyone with the URL can read all
  content. Real gating must be added at the edge (identity-aware proxy / CDN auth).
- **CSP:** shipped by the hosting layer. Because of inline print handlers + the inline
  theme script, the current CSP allows `script-src 'unsafe-inline'` — a documented gap.

See `SECURITY.md` for the full model.

## Observability

- **Logs:** whatever the serving layer emits (nginx access/error logs, CDN/S3 access
  logs, Render logs). The app produces no server logs.
- **Metrics/traces:** none in-app; use CDN/edge metrics (request counts, cache hit
  ratio, 4xx/5xx) at the hosting layer.
- **Health:** liveness = "the entry HTML returns `200`". The `Dockerfile` healthcheck
  requests `/index.html`. There is no `/health` endpoint (nothing to be unhealthy).
- **Client errors:** surfaced in the browser console only; consider adding a
  privacy-respecting client error reporter if needed (none today).

## Deployment topology

```
                 ┌──────────────────────────────────────────┐
   Browser  ───► │  Edge / CDN (TLS, cache, security hdrs)   │
   (runtime)     │  S3+CloudFront | Azure SWA/Front Door |   │
                 │  Render static | nginx VM | k8s nginx     │
                 └───────────────┬──────────────────────────┘
                                 │ serves static files
                                 ▼
                   aitool/*.html + branding.js
                                 │
        (browser also fetches)   ├─► cdn.jsdelivr.net  (Bootstrap, SRI)
                                 └─► ../theme.css, ../script.js, ...  (same origin)

   State: browser localStorage only (bsTheme, aitool.branding.v1, tracker store)
   Deploy identity: CI OIDC role / managed identity (no static keys, no app secrets)
```

See `../deployments/` for per-target detail and `DEPLOYMENT.md` for the models.
