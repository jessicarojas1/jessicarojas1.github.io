# ISMS Document Library — Architecture

## Platform

A **client-side static website**. Every page is a self-contained HTML document
rendered directly by the browser; there is **no application server, no database,
no build step, and no server-side code**. Hosting is any static file server or
object store + CDN. The only runtime is the visitor's browser.

- **Markup/UI:** HTML5 + **Bootstrap 5.3.3** (CSS + JS bundle) from the jsDelivr
  CDN, loaded with **Subresource Integrity** (`integrity="sha384-…"
  crossorigin="anonymous"`).
- **App styling:** `isms.css`, layered over the shared portfolio `../theme.css`.
- **Behavior:** vanilla JavaScript, no framework/bundler — an inline hub
  filter/search module in `index.html`, `branding.js`, and shared portfolio
  scripts `../script.js` (theme toggle, reveal-on-scroll, footer year) and
  `../roles.js` + `../users.js` (a client-side RBAC demo modal).

## Design principles

1. **Zero server surface.** No backend means no server auth, no DB, no secrets,
   no patching of a runtime — the attack surface is the static content, the CDN
   dependency, and the hosting layer's headers/TLS.
2. **Progressive, graceful degradation.** A broken logo URL falls back to the
   text mark; missing `localStorage` still renders defaults; the pre-paint theme
   snippet avoids a flash of the wrong theme.
3. **Print/PDF as a first-class output.** Documents carry print styles and a
   print-only branding header so each policy/procedure/template exports cleanly.
4. **Auditability by convention.** Filenames encode type + sequence + slug; each
   document has a control-mapping header keyed to ISO/IEC 27001:2022.
5. **Event wiring via `addEventListener`.** `branding.js` and the hub filter/
   search module use no inline handlers; the sole exception is the per-page
   `onclick="window.print()"` Print button (why the CSP still needs
   `'unsafe-inline'`). User input is escaped/sanitized before it touches the DOM.

## Component overview

| Component | File(s) | Responsibility |
|-----------|---------|----------------|
| Document hub | `index.html` | Landing page; card grid for all 42 documents; live search + type filter (`data-type`, `data-title`) |
| Documents | `pol-*.html` (18), `pro-*.html` (12), `tmp-*.html` (12) | Standalone ISO 27001 policy / procedure / template pages |
| App styles | `isms.css` | Doc headers, badges, control tags, card grid, revision tables, print styles |
| Branding | `branding.js` | Settings → Branding; persist + apply accent/name/logo; print header |
| Shared theme | `../theme.css` | Portfolio-wide theme + dark-mode tokens |
| Shared scripts | `../script.js`, `../roles.js`, `../users.js` | Theme toggle, reveal, footer year; client-side RBAC demo modal |
| Hosting config | `nginx.conf`, `Dockerfile`, `render.yaml` | Static serving + security headers |

## Monorepo structure

This project lives at `isms/` inside the `jessicarojas1.github.io` portfolio
repo. It depends on a few shared assets **one level up** and links back to the
portfolio's other pages (`../index.html`, `../projects.html`, …).

```
jessicarojas1.github.io/            # portfolio repo root (serve from here)
├── theme.css  script.js  roles.js  users.js  favicon.ico   # shared assets (../)
├── index.html … (portfolio pages)
└── isms/                            # ← THIS PROJECT
    ├── index.html                  # entry: hub
    ├── isms.css  branding.js
    ├── pol-*.html  pro-*.html  tmp-*.html
    ├── nginx.conf  Dockerfile  render.yaml
    ├── docs/  deployments/
    └── README.md  OPEN_ITEMS.md  CLAUDE.md
```

Internal layout of `isms/`:

- **Entry point:** `index.html` (served at `/isms/index.html`).
- **Documents:** flat, in the project root, named by the `pol-`/`pro-`/`tmp-`
  scheme so they self-sort and are directly linkable.
- **Docs & deploy guides:** `docs/` and `deployments/`.

## Configuration model

There is **no server/app configuration** — no env vars, no config files consumed
at runtime. All configurable state is **client-side, per-browser**:

| Key (store) | Shape | Default | Set by |
|-------------|-------|---------|--------|
| `bsTheme` (`localStorage`) | `"dark"` / `"light"` | `"dark"` | pre-paint snippet + `../script.js` toggle |
| `isms_branding` (`localStorage`) | `{ name, logoUrl, accent }` | `{ "JRojas", "", "#ff5811" }` | `branding.js` (Settings → Branding) |

Hosting-layer configuration (security headers, TLS, cache) is set at the edge/
server — see `nginx.conf`, `render.yaml`, and the `deployments/` guides.

## Request & error contract

Static file semantics only — there is no API, response envelope, or error-code
scheme.

| Concern | Behavior |
|---------|----------|
| Routing | Filesystem paths → HTML files. Entry `/isms/index.html`. Documents at `/isms/<name>.html`. |
| Success | HTTP `200` with the requested HTML/CSS/JS/SVG. |
| Not found | HTTP `404` (host default, or the portfolio `../404.html`). |
| Client "errors" | Handled in-page: no search matches → the `#isms-no-results` message; broken logo → fallback to text mark; unavailable `localStorage` → defaults. |
| Auth "errors" | None — content is public; the login modal is a UI demo, not a gate. |

## Security model

- **No server trust boundary.** All documents are public static files; the
  client-side RBAC modal is **not** an access control.
- **Supply chain:** Bootstrap pinned + SRI-verified; devicon SVGs are unpinned
  images (low risk). Vendoring removes the CDN dependency entirely
  (see [../deployments/AIRGAPPED.md](../deployments/AIRGAPPED.md)).
- **Input handling:** branding inputs are sanitized (logo URL scheme allow-list,
  hex accent validation, HTML-escaped name) before touching the DOM/storage.
- **Headers/TLS:** CSP + `X-Content-Type-Options` + `X-Frame-Options` +
  `Referrer-Policy` + `Permissions-Policy` and HTTPS are enforced at the hosting
  layer (`nginx.conf` / `render.yaml` / CDN). Full detail in
  [SECURITY.md](SECURITY.md).

## Observability

No server logs/metrics/traces exist for the app itself. Observability is provided
by the **hosting layer**:

| Signal | Source |
|--------|--------|
| Access/error logs | nginx / CloudFront / Front Door / Static Web Apps access logs |
| Health | HTTP `200` on the entry page (container `HEALTHCHECK` hits `/isms/index.html`) |
| Availability / TLS expiry | external uptime + certificate monitors (recommended — see OPEN_ITEMS) |
| Client errors | browser devtools console (no server-side telemetry is collected) |

## Deployment topology

```
                 ┌──────────── static host / CDN edge ────────────┐
Browser ─HTTPS─► │  security headers + TLS + cache                │
   │             │  serves: /isms/*.html, isms.css, branding.js   │
   │             │          ../theme.css ../script.js ../*.js     │
   │             └───────────────────────────────────────────────┘
   │  loads (SRI-verified) Bootstrap 5.3.3 CSS+JS  ◄── jsDelivr CDN
   │  loads devicon SVGs                            ◄── jsDelivr CDN
   ▼
 localStorage: bsTheme, isms_branding (per-browser; nothing sent to any server)
```

Concrete targets: [Local](../deployments/LOCAL_DEVELOPMENT.md) ·
[Single Linux Server](../deployments/SINGLE_LINUX_SERVER.md) ·
[Kubernetes](../deployments/KUBERNETES.md) ·
[Azure](../deployments/AZURE.md) · [AWS](../deployments/AWS.md) ·
[Air-gapped](../deployments/AIRGAPPED.md).
