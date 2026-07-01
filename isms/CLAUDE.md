# CLAUDE.md — ISMS Document Library

Project guidance for this app. It complies with the repo-wide rules in the root
[`../CLAUDE.md`](../CLAUDE.md); this file records what is specific to the ISMS
library and the standing rule that the standard doc set is kept current.

## What this is

A **Type A static website**: the ISO/IEC 27001:2022 **ISMS Document Library** —
18 policies (`pol-*.html`), 12 procedures (`pro-*.html`), 12 templates
(`tmp-*.html`), plus a searchable/filterable hub (`index.html`). No backend, no
database, no build step, no server-side code, no real authentication.

## Stack & conventions

- **HTML/CSS/JS only.** Bootstrap **5.3.3** from jsDelivr **with SRI**
  (`integrity` + `crossorigin="anonymous"`). App styles in `isms.css` layered
  over the shared `../theme.css`.
- **Dark mode:** pre-paint inline snippet sets `data-bs-theme` from
  `localStorage['bsTheme']` (default `dark`). Toggle wired by `../script.js`.
- **Branding:** `branding.js` persists `{name, logoUrl, accent}` under
  `localStorage['isms_branding']` and applies it live (accent → `--bs-primary` /
  `--bs-primary-rgb` / `--brand`, name → `document.title` + navbar mark, logo →
  navbar + a print-only header). Logo URLs sanitized to `http(s)://` /
  `data:image/…`; display name HTML-escaped; accent hex-validated.
- **Filenames encode type + sequence + slug** (`pol-`/`pro-`/`tmp-` + `NNN` +
  slug). Hub cards carry `data-type` and `data-title` for the filter/search.
- **Shared parent assets** (relative `../`): `theme.css`, `script.js`,
  `roles.js`, `users.js`, `favicon.ico`. Serve from the **repo root** so these
  resolve.

## Rules that apply here

- **Inline event handlers:** `branding.js` and the hub filter/search module in
  `index.html` use `addEventListener` only. The one exception is the per-page
  "Print / Save PDF" button (`onclick="window.print()"`, 42 document pages) — it
  is why the CSP still needs `'unsafe-inline'`. Do **not** add new inline
  handlers; prefer `data-*` + `addEventListener`, and externalizing the Print
  button is the first CSP-hardening step (see `OPEN_ITEMS.md`).
- **Escape user-supplied strings** before injecting into markup; **sanitize**
  logo URLs (`http(s)://` / `data:image/…` only) — already implemented in
  `branding.js`; preserve on any change.
- **No hardcoded accent colors** where the brand var should be used — accent is
  the CSS custom property `--bs-primary` / `--brand`.
- **Header logo links home** — the navbar brand (`#ismsBrand`) links to
  `../index.html` (portfolio home). Keep it a link.
- **Settings → Branding** section is present (logo URL + file→`data:` URL upload,
  display name, accent color) and persists to `localStorage`. Keep the field
  reference note under the upload input.
- **No secrets, no `.env`.** There is nothing server-side to configure.
- The client-side login modal is a **demo**, not a security control — never treat
  it as an auth boundary.

## Build / test / deploy

- **Run locally:** from the repo root, `python3 -m http.server 8000`, open
  `http://localhost:8000/isms/index.html`.
- **Container:** `docker build -f isms/Dockerfile -t isms-library:latest .`
  (context = repo root), then `docker run --rm -p 8080:8080 isms-library:latest`.
- **Managed:** [`render.yaml`](render.yaml) (Render static site).
- **Cloud/edge:** see [`deployments/`](deployments/) (Local, Single Linux Server,
  Kubernetes, Azure, AWS, Air-gapped).
- **No** migrations, no worker, no Ollama/GPU/AI — state these as N/A when asked.

## Standing rule — keep the doc set current

This project ships and maintains the standard set:
`deployments/` ×6 · `docs/` ×4 (ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY,
SECURITY) · `README.md` · `OPEN_ITEMS.md` · `CLAUDE.md` · `Dockerfile` ·
`render.yaml`. Whenever a document, asset, CDN version, branding behavior, or
hosting detail changes, **update the affected docs in the same change** and treat
it as part of "done." After any significant change, run the security & UI audits
described in the root `../CLAUDE.md` (CSP/XSS/inline-handlers; modals, filters,
dark mode, breadcrumbs, branding).
