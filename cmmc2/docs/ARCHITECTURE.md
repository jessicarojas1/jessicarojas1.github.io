# Architecture — `cmmc2`

CMMC 2.0 Readiness Assessment Platform: a **static, client-side single-page application**.
There is no server tier, no database, no API, and no build step. This document describes
the platform it is built on, its design principles, components, data model, configuration,
the (client-side) request/error contract, security model, observability, and deployment
topology.

## 1. Platform

| Layer | Choice |
|---|---|
| Runtime | The user's web browser (Chromium, Firefox, Safari) |
| Markup/logic | A single `index.html` — HTML + inline `<style>` + inline `<script>` |
| UI framework | Bootstrap **5.3.3** (CSS + JS bundle) + Bootstrap Icons **1.11.3** (jsDelivr CDN) |
| Excel export | SheetJS `xlsx.full.min.js` (jsDelivr CDN) |
| Theme | `../theme.css` (shared portfolio theme) + Bootstrap `data-bs-theme` (dark default) |
| Persistence | Browser `localStorage` (no server) |
| Hosting | Any static file host / CDN (S3+CloudFront, Azure `$web`/SWA, nginx, GitHub Pages) |

No Node/PHP/Python runtime is involved at serve time; hosting only needs to return files.

## 2. Design principles

1. **Client-side only.** All assessment logic runs in the browser. CUI-adjacent data (the
   contractor's control status, notes) never leaves the device. This is a deliberate
   privacy/compliance stance for a DoD/CUI audience.
2. **Zero build.** The artifact is the source. Ship `index.html` + `branding.js` (+ parent
   shared assets) verbatim. No transpile/bundle step to break or to trust.
3. **Progressive dependency on the CDN.** Bootstrap/Icons/SheetJS come from jsDelivr, all
   pinned and carrying SRI. An air-gapped variant vendors them (see
   [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md)).
4. **Standards-faithful content.** The datasets encode NIST SP 800-171 Rev 2 (17 L1 / 110
   L2 across 14 domains) and NIST SP 800-172 (24 L3 enhanced) practices, with SPRS scoring
   and POA&M semantics matching DoD guidance.
5. **CSP-first hardening.** A strict `<meta>` CSP constrains where scripts, styles, images,
   fonts, and connections may originate.

## 3. Component overview

```
Browser
├── index.html
│   ├── <head>: CSP meta, theme-seed inline script, CDN <link>s (Bootstrap CSS+Icons, SRI),
│   │           ../theme.css, inline <style> (control rows, SPRS dashboard, POA&M, filters)
│   ├── <body>: navbar (brand → ../index.html), profile/level selector, filter bar,
│   │           SPRS dashboard, 14 domain accordions of control rows, POA&M view,
│   │           export buttons, login modal placeholder (shared stub)
│   └── inline <script>: practice datasets, status state machine, SPRS calc, POA&M build,
│                        localStorage read/write, SheetJS export, theme toggle
├── branding.js          (Settings → Branding: logo/name/accent; localStorage; live apply)
└── parent shared assets (../): theme.css, favicon.ico, users.js, roles.js, script.js,
                                analytics.js, siteSearch.js
CDN (cdn.jsdelivr.net): bootstrap@5.3.3 (CSS+JS), bootstrap-icons@1.11.3, xlsx.full.min.js
```

### Runtime script load order (`index.html`)
1. Inline head script seeds `data-bs-theme` from `localStorage['bsTheme']` (default `dark`).
2. CDN: `bootstrap.bundle.min.js` (SRI), `xlsx.full.min.js`.
3. Parent (deferred): `../users.js`, `../roles.js`, `../script.js`, `../analytics.js`,
   `../siteSearch.js`.
4. Local (deferred): `branding.js`.

## 4. Monorepo placement & internal layout

`cmmc2/` is one project inside the `jessicarojas1.github.io` portfolio monorepo. It sits at
the repo root as a subfolder and **shares** root-level assets via `../` references.

```
jessicarojas1.github.io/         (repo root)
├── theme.css, favicon.ico, users.js, roles.js, script.js, analytics.js, siteSearch.js
├── index.html                   (portfolio home; navbar brand target)
└── cmmc2/
    ├── index.html               (the app)
    ├── branding.js
    ├── README.md, CLAUDE.md, OPEN_ITEMS.md
    ├── Dockerfile, nginx.conf, render.yaml
    ├── deployments/  (6 guides)
    └── docs/         (this file + DEPLOYMENT, DISASTER_RECOVERY, SECURITY)
```

**Coupling note:** because of the `../` references, a deploy of *only* `cmmc2/` must also
ship the parent assets or serve from the repo root. Every deployment guide addresses this.

## 5. Configuration model

There is **no server configuration and no environment variables** for the running app.
"Configuration" is limited to:

| Setting | Where | Persistence | Notes |
|---|---|---|---|
| Theme (dark/light) | `data-bs-theme` | `localStorage['bsTheme']` | Seeded in `<head>`; toggled by `#themeToggleBtn` |
| Branding (logo/name/accent) | `branding.js` | `localStorage['cmmc2.branding.v1']` | Sanitized + escaped; live-applied; default accent `#ff5811` |
| Assessment state | inline script | `localStorage` | Per-control status/notes/flags, level selection |
| CDN version pins | `index.html` `<link>`/`<script>` | source | Bootstrap 5.3.3 (pinned+SRI), Icons 1.11.3 (pinned+SRI), SheetJS `xlsx@0.18.5` (pinned+SRI) |

Deploy-time configuration (cache-control, security headers, CSP-as-header) lives in the
hosting layer — see the `deployments/` guides and [`DEPLOYMENT.md`](DEPLOYMENT.md).

## 6. Request & error contract (client-side)

There is **no HTTP API and no server response envelope**. The relevant contracts are:

- **Asset fetches** are plain static `GET`s. A missing parent asset (e.g. `../theme.css`)
  yields a browser `404`; the app still renders the assessment but loses shared chrome.
- **Branding** degrades gracefully: a broken/invalid logo URL falls back to the text mark
  (`img` `error` handler); non-`http(s)`/`data:image` URLs are rejected by the sanitizer.
- **Export** produces a client-generated `.xlsx` (SheetJS `XLSX.writeFile`) and a JSON
  Blob download (`URL.createObjectURL(new Blob(...))`) — no network round trip.
- **State errors** (e.g. corrupt `localStorage` JSON) are swallowed with try/catch and fall
  back to defaults (`branding.js` `load()` returns `{}` on parse failure).

There are **no HTTP status/error codes to document** because the app owns no endpoints.

## 7. Security model

- **Content-Security-Policy** (`<meta http-equiv>`), enforced by the browser:

  ```
  default-src 'self' blob:;
  script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline';
  style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline';
  img-src 'self' https: data: blob:;
  font-src 'self' https://cdn.jsdelivr.net;
  connect-src 'self' https://cdn.jsdelivr.net blob:;
  worker-src blob:;
  object-src 'none';
  base-uri 'self';
  ```

  Per-directive rationale is in [`SECURITY.md`](SECURITY.md). Highlights: scripts/styles
  restricted to self + jsDelivr; `blob:` and `worker-src blob:` enable the Blob-based
  export and blob-backed workers; `object-src 'none'` and `base-uri 'self'` are hardening.
  `'unsafe-inline'` on scripts/styles is a **known limitation** driven by inline code in
  `index.html` (tracked in [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md)).
- **SRI** on all four CDN assets: Bootstrap CSS + JS bundle, Bootstrap Icons, and SheetJS (`xlsx@0.18.5`).
- **No auth, no secrets, no PII egress.** The login modal is a shared UI stub and does not
  gate data; assessment content stays in `localStorage`.
- **Input sanitization** in `branding.js` (URL allowlist + HTML escaping).

## 8. Observability

Being serverless/static, observability is edge- and client-side:

| Signal | Source |
|---|---|
| Availability / latency | CDN or web-server access logs (S3+CloudFront, Front Door, nginx) |
| Health | Static entry page returns `200`; container image exposes `/healthz` (see `nginx.conf`) |
| Client errors | Browser devtools console (CSP violations show here); optional CSP `report-to` |
| Usage analytics | `../analytics.js` (shared portfolio stub) — no assessment content collected |

There are no server logs, metrics, or traces to collect because there is no server process.

## 9. Deployment topology

```
Developer → git push → static host / CDN → Browser (runs everything)
                                   │
                                   └── jsDelivr CDN (Bootstrap, Icons, SheetJS) *
* air-gapped variant vendors these; no external calls.
```

Concrete targets and topology diagrams: the six [`../deployments/`](../deployments/) guides
and the consolidated [`DEPLOYMENT.md`](DEPLOYMENT.md).
