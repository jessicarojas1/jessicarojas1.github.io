# CLAUDE.md — Teacher Hub (teacher)

Project-specific guidance for working in `teacher/`. Read this before making
changes. It complements the repo-root `../CLAUDE.md` (AEGIS GRC project rules);
where this file describes the *current* state, honor the repo rules as the
*target* state and close the gaps noted below.

## What this is

**Teacher Hub** — a one-stop, **client-side** hub for a Utah 5th-grade classroom.
Everything runs in the browser and persists to `localStorage`. **No backend, no
database, no authentication, no build step, no package manager.** It lives at
`teacher/` in the `jessicarojas1.github.io` portfolio monorepo and is served by
GitHub Pages (and any static host — see [deployments/](deployments/)).

## Stack & conventions

- **Markup + external JS:** `index.html` holds all ten tab sections (markup only);
  the entire app lives in external **`app.js`**, with the pre-paint theme bootstrap
  in **`theme-init.js`**. Tabs toggle via `switchTab('tab<Name>', this)`; sub-views
  via `showStd/showMgmt/showRes/showProg(...)`. **No inline `<script>` and no inline
  `on*` handlers** — every handler is a `data-onclick`/`data-onchange`/
  `data-oninput` attribute dispatched by one delegated `addEventListener` per event
  type (safe expression parser in `app.js`, no `eval`; looks up whitelisted global
  functions). Add new interactivity the same way — never `onclick=`.
- **Branding:** `branding.js` (key `teacher.branding.v1`; default name
  "Teacher Hub", default accent `#ff5811`). It sanitizes logo URLs
  (`http(s)://` / `data:image/...` only), escapes user strings, overrides
  `--bs-primary`, and degrades gracefully on a broken logo. It uses **no inline
  handlers** — follow its `addEventListener` pattern for any new JS.
- **CSS:** Bootstrap **5.3.3** + Bootstrap Icons **1.11.3** from jsDelivr, plus
  shared `../theme.css`. Dark mode default via `data-bs-theme` from
  `localStorage['bsTheme']`.
- **Scripts loaded:** `theme-init.js` (head, pre-paint), `bootstrap.bundle.min.js`
  (CDN), `branding.js`, and `app.js` (all `defer`). Do **not** add `../users.js`,
  `../roles.js`, `../script.js`, `../analytics.js`, or `../siteSearch.js` — teacher
  intentionally omits them.
- **Parent dependency:** references `../theme.css` and `../favicon.ico`; the navbar
  brand links to `../` (portfolio home). Serve from the repo **root** so these
  resolve. Keep the header logo linking home (repo rule).
- **Output:** printables via `window.print()`; Gradebook CSV via a `Blob`
  download (`download='gradebook.csv'`). No SheetJS, no Chart.js.

## Where things live

| Thing | File / key |
|-------|-----------|
| App UI (markup) | `index.html` |
| App logic + handler delegation + data backup | `app.js` |
| Pre-paint theme bootstrap | `theme-init.js` |
| Branding module | `branding.js` (`teacher.branding.v1`) |
| Smoke tests | `tests/smoke.spec.js`, `tests/playwright.config.js` |
| Shared theme / favicon | `../theme.css`, `../favicon.ico` (repo root) |
| Settings (teacher/school/grade, roster, PBIS goal) | `localStorage`: `teacher_settings`, `pbis_goal` |
| Plans | `teacher_plans`, `teacher_units` |
| Gradebook | `gb_assignments`, `gb_grades` |
| Class mgmt | `comm_log`, `behavior_data`, `pbis_data`, `seating_data`, `iep_notes`, `supply_list` |
| Calendar / resources / progress / tools | `cal_events`, `word_wall`, `student_levels`, `anecdotal_notes`, `fluency_data`, `fluency_op`, `std_taught`, `tallies` |
| Theme | `bsTheme` |

## Security & UI rules — target vs current (honest)

The repo requires **no inline event handlers** and **CSP compliance**. Teacher Hub
now **meets** these; keep it that way — do not add new violations.

- ✅ **Strict CSP** ships as a `<meta>` in `index.html` and as edge headers in
  `nginx.conf`/`render.yaml`: `script-src 'self' https://cdn.jsdelivr.net` (no
  `'unsafe-inline'`); `style-src` keeps `'unsafe-inline'` for inline `style=""` +
  the `<style>` block. Keep meta and edge in sync when assets change.
- ✅ **No inline handlers**: zero `on*` attributes and zero inline `<script>` in
  `index.html`. New interactivity must use `data-*` + delegation (see `app.js`),
  not `onclick=`. Any HTML you generate in JS `innerHTML` must also use `data-*`.
- ✅ **SRI** present on **all three** CDN assets (Bootstrap CSS, Bootstrap JS
  bundle, Bootstrap Icons). Regenerate hashes on any version bump
  (`openssl dgst -sha384 -binary FILE | openssl base64 -A`).
- ⚠️ **Popup print windows** (`window.open` + `document.write`) inherit this page's
  CSP, so they must **not** contain inline `<script>`/`on*`. They now auto-print via
  an opener-side `w.print()`. Keep new printables the same way.
- Follow the rest of the repo UI rules for any new UI (page-header/breadcrumb
  patterns where applicable, dark-mode-safe CSS via `var(--…)`, no hardcoded
  colors that should be the accent var, graceful empty states).

Full analysis: [docs/SECURITY.md](docs/SECURITY.md) and [OPEN_ITEMS.md](OPEN_ITEMS.md).

## Student-data privacy stance

All classroom data — **including student names, grades, behavior, and IEP notes** —
is stored **unencrypted in `localStorage`, per device**, and **never transmitted
anywhere**. This is FERPA-relevant. Treat it accordingly:

- **Never** add analytics, trackers, telemetry, or any code that sends classroom
  data off the device — it would break the core privacy guarantee.
- Keep new inputs sanitized/escaped like `branding.js` does.
- Data is **not backed up or synced**; clearing site data is unrecoverable. See
  [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md).

## How to run / deploy

```bash
# Local (from repo ROOT so ../theme.css resolves):
python3 -m http.server 8000    # → http://localhost:8000/teacher/

# Container (build context = repo ROOT):
docker build -f teacher/Dockerfile -t teacherhub:local .
docker run --rm -p 8080:8080 teacherhub:local   # → http://localhost:8080/teacher/
```

Deploy targets: [deployments/](deployments/) (Local, Single Linux, Kubernetes,
AWS, Azure, Air-gapped), plus Render ([render.yaml](render.yaml)) and GitHub Pages.
Overview + production checklist: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

**Verification (no login/DB/upload — say so):** entry page 200, assets resolve
(Bootstrap/Icons + `../theme.css`/favicon), branding applies, theme toggle persists
via `bsTheme`, all 10 tabs switch, a plan/gradebook entry saves to `localStorage`
and survives reload, a template prints, and the Gradebook CSV downloads.

## Standing rule — keep the doc set current

This project ships the standard doc + deployment set: **`deployments/` ×6,
`docs/` ×4 (Architecture, Deployment, Disaster Recovery, Security), `README.md`,
`OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`.** Whenever a feature,
dependency, or config changes, **update the affected docs in the same change** and
keep every claim accurate to the code — do not invent env vars, commands, ports,
or paths, and do not claim CSP / no-inline-handler compliance the code lacks.
