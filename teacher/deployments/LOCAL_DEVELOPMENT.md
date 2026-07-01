# Teacher Hub — Local Development

**Target:** a developer laptop/workstation serving the static site over a local
HTTP server. Teacher Hub is a **client-side-only static website** — plain
HTML/CSS/JS, no backend, no database, no build step, no authentication. "Deploy"
locally means *serve the files and open the entry page in a browser*.

> **Applicability:** Fully applicable. Teacher Hub is a real static site under
> `teacher/` in the `jessicarojas1.github.io` portfolio monorepo. The only
> subtlety is the **parent-relative dependency** on `../theme.css` and
> `../favicon.ico`, so you must serve from the **repository root**, not from the
> `teacher/` directory, for the page to look correct.

Related guides: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES.md](KUBERNETES.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md) ·
[AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

Everything runs in the browser. The local "stack" is just a static file server
handing back three local files (`teacher/index.html`, `teacher/branding.js`,
`theme.css`) plus two CDN stylesheets/scripts (Bootstrap + Bootstrap Icons) from
jsDelivr. All teacher/student state is written to the browser's `localStorage`.

| Layer | What it is | Where it lives locally |
|-------|-----------|------------------------|
| Markup + logic | `teacher/index.html` (single file; all tab sections + inline `<script>` app code) | repo working tree |
| Branding module | `teacher/branding.js` (logo/name/accent, `localStorage` key `teacher.branding.v1`) | repo working tree |
| Shared theme | `theme.css` (portfolio-wide) referenced as `../theme.css` | repo **root** |
| Favicon | `favicon.ico` referenced as `../favicon.ico` | repo **root** |
| Vendor CSS/JS | Bootstrap 5.3.3, Bootstrap Icons 1.11.3 | jsDelivr CDN (needs internet) |
| State | `localStorage` (plans, gradebook, behavior, IEP notes, etc.) | your browser profile |

There is **no server process, no API, no database, no login, no file upload to a
server, and no object storage.** "State" is per-browser `localStorage`.

## 2. Topology

```
  Developer laptop
  ┌───────────────────────────────────────────────────────────────┐
  │  python3 -m http.server 8000   (cwd = repo ROOT)               │
  │        │  serves ./teacher/index.html, ./teacher/branding.js,  │
  │        │         ./theme.css, ./favicon.ico                    │
  │        ▼                                                        │
  │  Browser  http://localhost:8000/teacher/                       │
  │   ├── GET /teacher/index.html        200 (local)               │
  │   ├── GET /theme.css                 200 (local, via ../)      │
  │   ├── GET /teacher/branding.js       200 (local)               │
  │   ├── GET cdn.jsdelivr.net bootstrap 200 (INTERNET)            │
  │   └── localStorage  ← plans, grades, behavior, IEP, branding   │
  └───────────────────────────────────────────────────────────────┘
             (Bootstrap/Icons require outbound HTTPS to jsDelivr)
```

## 3. Prerequisites

| Tool | Minimum | Purpose |
|------|---------|---------|
| Any modern browser | Chrome/Edge/Firefox/Safari (current) | run the site; `localStorage` + ES5+ |
| A static file server | Python 3.x **or** Node 18+ **or** `busybox httpd` | serve files over HTTP |
| Git | 2.x | clone the monorepo |
| Internet access | — | CDN Bootstrap + Icons (see offline note below) |

No compiler, package manager, `node_modules`, or build tool is required. There is
**nothing to `npm install`** — the repo ships the exact files that get served.

## 4. Identity & credentials

**None for running locally.** The site has no authentication, no API keys, and no
secrets. Do not add any — there is no server to hold them and any value placed in
client JS is public.

The only identity that matters is the **deploy-pipeline identity** used to publish
the site (GitHub OIDC role / managed identity), and that lives with the CI system,
not on the laptop. See [AWS.md](AWS.md) §4 and [AZURE.md](AZURE.md) §4.

## 5. Environment variables

**None.** This is a static site with no runtime configuration read from the
environment. There is no `.env`, no `process.env`, no config server.

| Variable | Example | Purpose |
|----------|---------|---------|
| _(none)_ | — | Teacher Hub reads no environment variables at runtime. |

Local server port is a CLI argument, not an env var (e.g. `python3 -m
http.server 8000`).

## 6. Configuration references

Runtime configuration is **user-driven and stored in the browser**, not in files:

| Setting | Where set | Stored in (`localStorage`) |
|---------|-----------|----------------------------|
| Theme (dark/light) | navbar toggle button | `bsTheme` (default `dark`) |
| Branding (logo/name/accent) | palette button → Branding modal | `teacher.branding.v1` |
| Teacher/school/grade, student roster, PBIS goal | gear → Teacher Settings | `teacher_settings`, `pbis_goal` |
| Vendor pin | hard-coded in `index.html` | Bootstrap `@5.3.3`, Icons `@1.11.3` |

To change the Bootstrap/Icons version, edit the `<link>`/`<script>` tags in
`teacher/index.html` (and update the SRI hash on the Bootstrap CSS/JS tags).

## 7. Verification

There is **no health endpoint, no login, no secrets to resolve, and no
server-side upload or object write** — state that explicitly. Verify the real
client-side behaviors instead.

```bash
# From the repository ROOT (so ../theme.css resolves):
cd /path/to/jessicarojas1.github.io
python3 -m http.server 8000
```

```bash
# 1) Entry page returns 200
curl -sS -o /dev/null -w '%{http_code}\n' http://localhost:8000/teacher/
# -> 200

# 2) Local assets resolve
curl -sS -o /dev/null -w 'theme.css %{http_code}\n'    http://localhost:8000/theme.css
curl -sS -o /dev/null -w 'branding   %{http_code}\n'   http://localhost:8000/teacher/branding.js
curl -sS -o /dev/null -w 'favicon    %{http_code}\n'   http://localhost:8000/favicon.ico
# -> 200, 200, 200

# 3) Confirm the CDN pins the page depends on are reachable
curl -sS -o /dev/null -w 'bootstrap css %{http_code}\n' \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -sS -o /dev/null -w 'bootstrap icons %{http_code}\n' \
  https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css
```

Then in the browser at `http://localhost:8000/teacher/`:

- [ ] Page renders with the dark theme by default; toggling the theme button
      persists across reload (`localStorage['bsTheme']`).
- [ ] All feature tabs switch: **Planner, Activities, Templates, Standards,
      Gradebook, Class Management, Calendar, Resources, Progress, Tools.**
- [ ] Create and **Save** a lesson plan (Planner) → reload → it is still listed
      (`teacher_plans`).
- [ ] Add a Gradebook assignment + a score → reload → values persist
      (`gb_assignments`, `gb_grades`).
- [ ] **Export CSV** from the Gradebook downloads `gradebook.csv` (Blob download).
- [ ] Print a Template / award via the browser print dialog (`window.print()`).
- [ ] Open the Branding modal, set an accent color and name → applied live; the
      navbar brand and document title update; reload preserves it
      (`teacher.branding.v1`).
- [ ] DevTools console is free of 404s for local assets.

> **Offline implication:** if you kill internet, Bootstrap + Bootstrap Icons fail
> to load from jsDelivr and the layout/icons break, even though the local files
> serve fine. For an offline/air-gapped workflow, vendor the CDN assets — see
> [AIRGAPPED.md](AIRGAPPED.md).

## 8. Day-2 operations

| Task | How |
|------|-----|
| Live-edit | edit `teacher/index.html` / `teacher/branding.js`, hard-reload (`Cmd/Ctrl+Shift+R`). No watcher/build needed. |
| Bump Bootstrap | change `@5.3.3` in both the CSS `<link>` and JS `<script>`; regenerate SRI (`openssl dgst -sha384 -binary file | openssl base64 -A`). |
| Reset local state | DevTools → Application → Local Storage → clear the `teacher_*`, `gb_*`, and other keys (see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)). |
| Back up your test data | Gradebook **Export CSV**; copy the `localStorage` values from DevTools. |
| Lint/format | none configured; keep to the existing style. |

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Page is unstyled / no theme | served from `teacher/` so `../theme.css` 404s | serve from repo **root**, open `/teacher/` |
| No icons, broken grid | jsDelivr unreachable (offline/proxy) | restore internet, or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| `favicon.ico` 404 in console | served from `teacher/` | serve from root; note `favicon.ico` is an empty placeholder at repo root |
| Saved plans/grades vanished | different browser/profile, or site data cleared | `localStorage` is per-browser; data is not synced or recoverable |
| Branding didn't apply | broken logo URL, or `teacher.branding.v1` malformed | modal falls back to default mark; Reset to defaults in the Branding modal |
| `curl` 200 but browser blank | opened `file://` instead of `http://` | use the HTTP server URL; `localStorage`/modules need an origin |
