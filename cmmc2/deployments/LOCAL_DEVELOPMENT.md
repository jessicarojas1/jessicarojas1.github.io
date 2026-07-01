# Local Development — `cmmc2`

Run and iterate on the CMMC 2.0 Readiness Assessment Platform on a laptop. No build step,
no backend, no database — just serve the static files and open the page. The one rule that
matters: **serve from the repo root** so the parent-relative `../` assets resolve.

## 1. Deployment architecture

A local static file server (Python's `http.server`, or any equivalent) serves the portfolio
repo tree over `http://localhost`. The browser loads `/cmmc2/index.html`, which pulls
Bootstrap 5.3.3, Bootstrap Icons 1.11.3, and SheetJS from `cdn.jsdelivr.net`, and the parent
shared assets (`../theme.css`, `../*.js`, `../favicon.ico`) from the same local server. All
logic (SPRS score, POA&M, `.xlsx` export) runs in the browser; state is `localStorage`.

## 2. Topology

```
┌──────────── laptop ────────────┐          ┌── internet ──┐
│  python3 -m http.server 8000   │  <────>  │ cdn.jsdelivr │  (Bootstrap, Icons, SheetJS)
│  docroot = repo root            │          └──────────────┘
│        │                        │
│        ├── /cmmc2/index.html ───┼─┐
│        ├── /cmmc2/branding.js   │ │  browser (http://localhost:8000/cmmc2/)
│        ├── /theme.css           │ ├──> runs everything client-side
│        ├── /users.js …          │ │     state → localStorage
│        └── /index.html (home)   │ │
└─────────────────────────────────┘─┘
```

## 3. Prerequisites

| Tool | Version | Purpose |
|---|---|---|
| Python 3 | ≥ 3.6 | `python3 -m http.server` (any static server works) |
| A modern browser | current | Chromium/Firefox/Safari |
| git | any | Clone/edit the repo |
| Internet access | — | For the CDN assets (skip only with the vendored/air-gapped build) |

Alternatives to Python: `npx serve`, `php -S`, `ruby -run -e httpd`, VS Code Live Server —
all fine, as long as the **docroot is the repo root**.

## 4. Identity & credentials

**None.** Local development requires no cloud identity, API keys, or secrets. The app has no
auth and no backend. (Deploy-pipeline identity — OIDC roles — is only relevant to the cloud
guides.)

## 5. Environment variables

**None.** The app reads no environment variables. The only knob is the local server's port.

| Variable | Example | Purpose |
|---|---|---|
| _(none)_ | — | No app env vars |
| `PORT` (server arg, not env) | `8000` | Port passed to `python3 -m http.server 8000` |

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Docroot | repo root (`/home/user/jessicarojas1.github.io`) | Ensures `../` parent assets resolve |
| Entry URL | `http://localhost:8000/cmmc2/` | The app |
| Theme default | `localStorage['bsTheme'] = 'dark'` | Dark mode unless toggled |
| Branding key | `localStorage['cmmc2.branding.v1']` | Logo/name/accent |

## 7. Verification

There is **no login, no database, no server upload, and no object storage** — verify the
real client-side behaviors instead:

```bash
# From the REPO ROOT:
python3 -m http.server 8000

# 1. Entry page returns 200
curl -sI http://localhost:8000/cmmc2/ | head -n1        # HTTP/1.0 200 OK

# 2. Local app + parent assets resolve (expect 200 each)
for u in cmmc2/index.html cmmc2/branding.js theme.css favicon.ico \
         users.js roles.js script.js analytics.js siteSearch.js; do
  printf '%-22s ' "$u"; curl -so /dev/null -w '%{http_code}\n' "http://localhost:8000/$u"
done
```

Then in the browser at `http://localhost:8000/cmmc2/`:

- [ ] Page renders; **no CSP violations** in the devtools console.
- [ ] Bootstrap styling + icons load (CDN reachable); SheetJS available (`typeof XLSX` is `object` in console).
- [ ] **Branding** applies: open the gear → set a name/accent → Save → navbar + `--bs-primary` update; broken logo URL falls back to the text mark.
- [ ] **Theme** toggle persists: switch dark/light, reload — `localStorage['bsTheme']` sticks.
- [ ] **Assessment** works: mark a control `Met`/`Partial`/etc. → the **SPRS score updates**.
- [ ] **Export**: click export → an `.xlsx` (e.g. `CMMC2_Assessment_YYYY-MM-DD.xlsx`) downloads.

## 8. Day-2 operations

- **Edit loop:** change `index.html` / `branding.js`, hard-refresh (Ctrl/Cmd-Shift-R) — no
  rebuild.
- **Reset local state:** devtools → Application → Local Storage → clear, to test first-run.
- **Test standalone (no repo root):** copy parent assets into a scratch dir to confirm a
  self-contained deploy works:
  ```bash
  mkdir -p /tmp/cmmc2-standalone/cmmc2
  cp cmmc2/index.html cmmc2/branding.js /tmp/cmmc2-standalone/cmmc2/
  cp theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js index.html /tmp/cmmc2-standalone/
  (cd /tmp/cmmc2-standalone && python3 -m http.server 8001)   # open :8001/cmmc2/
  ```
- **Container parity:** build the image and serve it (see [`../Dockerfile`](../Dockerfile)):
  ```bash
  docker build -f cmmc2/Dockerfile -t cmmc2:local .    # context = repo root
  docker run --rm -p 8080:8080 cmmc2:local             # open :8080/cmmc2/
  ```

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Page unstyled / no theme | Served from inside `cmmc2/`; `../theme.css` 404s | Serve from the **repo root**; open `/cmmc2/` |
| `../users.js` etc. 404 | Wrong docroot | Same as above |
| No Bootstrap/icons; export button dead | Offline / CDN blocked | Reconnect, or use the vendored [`AIRGAPPED.md`](AIRGAPPED.md) build |
| Console CSP violation | Added a script/style/origin not allowed by the CSP | Use `'self'` or `cdn.jsdelivr.net`; don't broaden the CSP |
| `.xlsx` doesn't download | `XLSX` undefined (SheetJS failed to load) | Check the CDN `<script>`; confirm network |
| Branding logo not showing | URL not `http(s)`/`data:image` (sanitizer rejects it) | Use an allowed URL scheme |
| Port already in use | Another server on 8000 | `python3 -m http.server 8001` |

See also: [`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`../docs/DEPLOYMENT.md`](../docs/DEPLOYMENT.md).
