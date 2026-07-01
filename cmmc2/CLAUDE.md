# CLAUDE.md — `cmmc2` (CMMC 2.0 Readiness Assessment Platform)

Project-specific guidance for maintainers and agents working in this directory. This app
lives in the `jessicarojas1.github.io` portfolio monorepo; the repo-root `CLAUDE.md`
still applies. Where the two differ, the root rules win — this file adds the specifics
for `cmmc2`.

## What this is

A **fully client-side** static single-page app for CMMC 2.0 self-assessment. It tracks
NIST SP 800-171 Rev 2 practices (17 for Level 1, 110 for Level 2 across 14 domains) plus
24 NIST SP 800-172 Level 3 enhanced practices, computes an estimated **SPRS score**,
builds a **POA&M**, and exports to `.xlsx`. **No backend, no database, no login, no build
step.** All state is `localStorage`.

## Stack & conventions

- **Single file for the app:** `index.html` contains the markup, an inline `<style>`
  block, inline `<script>` logic, the practice datasets, SPRS + POA&M calculation, theme
  seeding, and the SheetJS export. Treat it as the source of truth for behavior.
- **`branding.js`** — the Settings → Branding module. Uses `addEventListener` only (no
  inline handlers), sanitizes logo URLs, escapes strings, persists to
  `localStorage['cmmc2.branding.v1']`, applies live. **Keep it inline-handler-free** and
  keep the sanitizer (`http(s)://` or `data:image/...` only).
- **Shared/parent assets (do not duplicate here):** `../theme.css`, `../favicon.ico`,
  `../users.js`, `../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js`, and
  the navbar brand → `../index.html`. Editing those is a **repo-root** change, not a
  `cmmc2` change. A standalone deploy of `cmmc2/` must ship copies of these.
- **CDN deps (pinned where noted):** Bootstrap **5.3.3** (CSS+JS, both with SRI),
  Bootstrap Icons **1.11.3**, SheetJS `xlsx.full.min.js` (currently **unpinned** — a
  known open item).
- **Theme:** dark by default; `data-bs-theme` is seeded from `localStorage['bsTheme']` by
  an inline head script and toggled by `#themeToggleBtn`.

## Content-Security-Policy (enforced via `<meta>`)

```
default-src 'self' blob:; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline';
style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:;
font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net blob:;
worker-src blob:; object-src 'none'; base-uri 'self';
```

Rules when editing:
- **Do not broaden** the CSP. New scripts/styles must come from `'self'` or
  `cdn.jsdelivr.net`. New external origins are not allowed without a deliberate CSP change
  documented in [`docs/SECURITY.md`](docs/SECURITY.md).
- The `'unsafe-inline'` on `script-src`/`style-src` exists **only** because `index.html`
  currently uses inline `<script>`/`<style>` and inline event handlers (`onclick=`,
  `onchange=`, `oninput=`). This departs from the repo-wide "no inline handlers / nonce'd
  scripts" ideal. **Preferred direction:** externalize handlers, then drop `'unsafe-inline'`
  and add nonces/hashes. Tracked in [`OPEN_ITEMS.md`](OPEN_ITEMS.md).
- `object-src 'none'` and `base-uri 'self'` are hardening wins — keep them.
- `worker-src blob:` and `blob:` in `default-src`/`connect-src` support blob-backed
  workers and the Blob-based JSON/`.xlsx` export (`URL.createObjectURL`) — keep them.

## UI / branding rules (from repo standard)

- **No new inline event handlers.** New interactive behavior should be wired with
  `addEventListener` (as `branding.js` does). Existing inline handlers in `index.html` are
  legacy debt to be reduced, not extended.
- **Header logo links home** — the navbar brand links to `../index.html`; keep it clickable.
- **Branding standard** — the Settings → Branding section (logo URL / upload / name /
  accent, sanitized + persisted + live-applied) must remain functional.
- **Dark mode** — use CSS custom properties (`--bs-primary`, `--bs-secondary-bg`,
  `--bs-border-color`, etc.); avoid hardcoding hex that should be the accent var.

## How to run

```bash
# Serve from the REPO ROOT so ../ assets resolve:
python3 -m http.server 8000      # then open http://localhost:8000/cmmc2/
```

Never serve from inside `cmmc2/` — the parent `../` references will 404.

## How to deploy

Static hosting only. See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) and the six
[`deployments/`](deployments/) guides. For the DoD/CUI audience the realistic targets are
**AWS GovCloud** (S3 + CloudFront OAC, partition `aws-us-gov`, FIPS endpoints) and
**Azure Government** (`*.usgovcloudapi.net`). Deploy pipelines use **OIDC IAM roles /
managed identity**, never static keys. There are **no app secrets**.

## Things that do NOT apply here (state honestly, don't invent)

- No database, no migrations, no ORM, no `schema.sql`.
- No authentication/authorization in the running app (the login modal is a shared UI stub;
  it does not gate assessment data).
- No server, no background worker/queue/cron.
- No file uploads to a server, no object-storage writes (the only "upload" is a logo read
  in-browser to a `data:` URL).
- No AI feature → **Ollama / GPU acceleration = N/A**.

## Standing rule

Keep this doc set current as the app changes. Whenever you change `index.html`,
`branding.js`, the CSP, the CDN pins, or the parent-asset coupling, update the affected
[`deployments/`](deployments/), [`docs/`](docs/), [`README.md`](README.md), and
[`OPEN_ITEMS.md`](OPEN_ITEMS.md) in the **same** change. Verify every command, path, and
version against the real files — do not invent env vars, ports, or paths.
