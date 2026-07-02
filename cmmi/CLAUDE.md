# CLAUDE.md — CMMI v2.0 Practice Reference (`cmmi/`)

Project-specific guidance for the `cmmi/` subfolder of the
`jessicarojas1.github.io` portfolio. Read this before changing anything here.
The portfolio-wide rules in the **repo-root `CLAUDE.md`** still apply; this file
adds cmmi-specific detail and the standing rule to keep the doc set current.

## What this is

A static, client-side **CMMI v2.0 practice reference**: ML2–ML3, all 21 practice
areas, every practice group, every practice statement with elaboration and
compliance examples. Users filter (level / domain / search), record per-practice
status, owner, target date, notes, flags, and an evidence checklist, then export
to `.xlsx` or print. No backend, no DB, no auth, no build step.

## Stack & conventions

- **Static HTML/CSS/JS.** The whole UI is `index.html` (~2200 lines) with inline
  `<style>` and inline `<script>` glue.
- **Bootstrap 5.3.3** (CSS + JS bundle) + **Bootstrap Icons 1.11.3** + **SheetJS
  (`xlsx.full.min.js`)** — all from `cdn.jsdelivr.net`.
- **Dark mode is default.** An inline head script sets
  `data-bs-theme` from `localStorage['bsTheme']` (default `'dark'`).
- **Shared parent assets** live at the repo root and are referenced with `../`:
  `../cmmidev3.js` (the ~227 KB dataset + render/filter/export engine — the app
  is inert without it), `../theme.css`, `../favicon.ico`, `../users.js`,
  `../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js`.
- **Script load order (do not reorder blindly):** CDN `bootstrap.bundle.min.js`
  → `../users.js` → `../roles.js` → `../script.js` → `../analytics.js` →
  `../siteSearch.js` → `branding.js` → `../cmmidev3.js` → CDN
  `xlsx.full.min.js`.

## Where things live

| Thing | Location |
|-------|----------|
| App UI + inline glue | `cmmi/index.html` |
| Practice dataset + render/filter/export logic | `../cmmidev3.js` (repo ROOT) |
| Branding (logo/name/accent) | `cmmi/branding.js` |
| Shared theme variables | `../theme.css` |
| Docs / deployment set | `cmmi/docs/`, `cmmi/deployments/` |

## Data model (client-side only)

All user state is in `localStorage`, per-browser, keyed per practice:

| Key pattern | Meaning |
|-------------|---------|
| `cmmi2_s_<practiceNum>`   | status (`ns`/`ip`/`done`/`na`) |
| `cmmi2_n_<practiceNum>`   | notes |
| `cmmi2_o_<practiceNum>`   | owner |
| `cmmi2_td_<practiceNum>`  | target date |
| `cmmi2_f_<practiceNum>`   | flag (`1`) |
| `cmmi2_ev_<pa>_<idx>`     | evidence checklist item checked (`1`) |
| `cmmi.branding.v1`        | branding JSON (name/logoUrl/accent) |
| `bsTheme`                 | `dark` / `light` |

## Rules that apply here

- **CSP is a real `<meta>` tag** in `index.html`. Keep it exact; if you tighten
  it, update [docs/SECURITY.md](docs/SECURITY.md) and
  [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) which reproduce it verbatim.
  Current policy keeps `'unsafe-inline'` for script/style because inline
  `<script>`/`<style>` and a few inline event handlers exist — see
  [OPEN_ITEMS.md](OPEN_ITEMS.md).
- **Branding:** `branding.js` uses `addEventListener` only (no inline handlers),
  sanitizes logo URLs to `http(s)://` or `data:image/...`, escapes strings, and
  degrades to the default `JRojas` mark on a broken logo. Preserve that.
- **Header logo links home** — the navbar brand points to `../index.html`. Keep
  it a link.
- **No new inline event handlers** beyond what already exists; prefer
  `addEventListener` / `data-*` hooks.

## How to run / deploy

```bash
# Local (from the repo ROOT so ../ assets resolve):
python3 -m http.server 8000   # → http://localhost:8000/cmmi/
```

Deploy targets are documented in `deployments/` (local, single Linux server,
Kubernetes, Azure, AWS, air-gapped). Container build uses `cmmi/Dockerfile` with
the **repo root as context** so the parent `../` assets are copied in. Render
uses `render.yaml` (static site). See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## Standing rule

Whenever you change `index.html`, `branding.js`, the CSP, the CDN pins, or the
parent-asset coupling, **update the affected files in `docs/`, `deployments/`,
`README.md`, and `OPEN_ITEMS.md` in the same change.** This doc set is part of
"done."
