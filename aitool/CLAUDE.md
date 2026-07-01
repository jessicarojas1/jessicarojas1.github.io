# CLAUDE.md — AI Tool Evaluation Framework (`aitool`)

Project guidance for this subproject. Inherits the root
`/home/user/jessicarojas1.github.io/CLAUDE.md` (AEGIS GRC rules) — this file records
what is specific to `aitool` and the standing rule to keep the doc set current.

## What this is

A **static, client-side** Bootstrap 5 website: an AI Tool Evaluation Framework for
aerospace/defense, mapped to CMMC 2.0, NIST SP 800-171, ISO/IEC 27001:2022, and DFARS
252.204-7012. It ships a policy, an evaluation procedure, three templates, and a
client-side pipeline tracker. **No backend, no database, no server auth, no build step.**

## Stack & conventions

- **HTML5 + Bootstrap 5.3.3** from `cdn.jsdelivr.net` with **SRI** (`integrity=` +
  `crossorigin`). No bundler, no package manager.
- **Vanilla JS.** `branding.js` is a local IIFE with **no inline handlers** (all
  `addEventListener`). Theme + client-side RBAC **demo** come from the parent repo's
  `../script.js`, `../roles.js`, `../users.js`.
- **Persistence = browser `localStorage`** only. Keys in use: `bsTheme` (theme),
  `aitool.branding.v1` (branding), and the vendor-tracker store. Nothing is sent to a
  server; tracker/questionnaire "uploads" are client-side (`FileReader`) only.
- **Theme:** `data-bs-theme` on `<html>`, defaulting to `dark`, set by an inline
  `<head>` bootstrap script and toggled by `#themeToggleBtn`.
- **Branding:** the Settings ⚙️ modal (built by `branding.js`) sets logo (URL or
  uploaded `data:` URL), display name, and accent (`--bs-primary`). Logo URLs are
  sanitized to `http(s)://` or `data:image/...`.

## Where things live

| Thing | Path |
|-------|------|
| Entry page | `index.html` |
| Policy / procedure / templates | `pol-ai-001-*.html`, `pro-ai-001-*.html`, `tmp-ai-00{1,2,3}-*.html` |
| Pipeline tracker | `vendor-tracker.html` |
| Branding module | `branding.js` |
| Shared parent assets | `../theme.css`, `../isms/isms.css`, `../script.js`, `../roles.js`, `../users.js`, `../favicon.ico` |
| Container / PaaS | `Dockerfile`, `nginx.conf`, `render.yaml` |
| Docs | `docs/` (Architecture, Deployment, Disaster Recovery, Security) |
| Operator guides | `deployments/` (6 targets) |

## Security/UI rules that apply

- **No inline event handlers** (root rule). ⚠️ Currently violated by
  `onclick="window.print()"` in 5 pages and the inline theme-bootstrap `<head>`
  script — tracked in `OPEN_ITEMS.md`; fix by moving to `data-*` handlers / external
  init and tightening CSP.
- **Sanitize + escape branding input** — already done in `branding.js` (`esc()`,
  `sanitizeLogoUrl()`, `isHex()`).
- **Header logo links home** — the navbar brand links to `../index.html` (site home).
- **SRI on CDN assets** — keep `integrity=` hashes correct on any Bootstrap bump.
- **No secrets in the repo** — none exist (no backend). Deploy identity is CI OIDC.

## Build / test / deploy

- **Build:** none. It's static files.
- **Run locally:** `python3 -m http.server 8000` from the **repo root** (so `../`
  assets resolve), open `/aitool/index.html`.
- **Container:** `docker build -t aitool ./aitool && docker run --rm -p 8080:8080 aitool`.
- **Deploy:** static hosting — see `deployments/` and `docs/DEPLOYMENT.md`. Prefer CI
  **OIDC roles / managed identity**, never static keys.

## Standing rule

Keep the standard doc set — `deployments/` (×6), `docs/` (×4), `README.md`,
`OPEN_ITEMS.md`, this `CLAUDE.md`, `Dockerfile`, `render.yaml` — **accurate to the
code** and updated in the same change whenever a page, asset, dependency, or deploy
target changes. Do not invent commands, env vars, ports, or paths.
