# Local Development — CMMI v2.0 Practice Reference

Fast path to run and live-edit the site on a laptop. It is static HTML/CSS/JS
with no build step — you serve the files and refresh the browser.

## 1. Deployment architecture

A local static file server (Python's `http.server`, `npx serve`, nginx, etc.)
serves the **repository root** over HTTP. The browser loads
`/cmmi/index.html`, which pulls:

- **Parent-relative assets** from the repo root (`../cmmidev3.js` — the practice
  dataset + engine, `../theme.css`, `../favicon.ico`, `../users.js`,
  `../roles.js`, `../script.js`, `../analytics.js`, `../siteSearch.js`), and
- **CDN assets** from `cdn.jsdelivr.net` (Bootstrap 5.3.3, Bootstrap Icons
  1.11.3, SheetJS).

> **Serve from the repo ROOT, not from `cmmi/`.** The page uses `../` paths, so
> the document root must be one level above `cmmi/`. Opening `index.html` via
> `file://` fails (CSP + `../` resolution).

## 2. Topology

```
┌─ Developer laptop ───────────────────────────────────────────┐
│  python3 -m http.server 8000   (docroot = repo ROOT)         │
│        │                                                     │
│        ▼                                                     │
│  Browser → http://localhost:8000/cmmi/                       │
│        │            ├─ ../cmmidev3.js  (dataset + engine)     │
│        │            ├─ ../theme.css, ../*.js, ../favicon.ico  │
│        └─ HTTPS ───▶ cdn.jsdelivr.net (Bootstrap/Icons/xlsx)  │
│  localStorage: cmmi2_*, cmmi.branding.v1, bsTheme            │
└──────────────────────────────────────────────────────────────┘
```

## 3. Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| Python | ≥ 3.7 | `python3 -m http.server` (or use any static server) |
| Modern browser | current Chromium/Firefox/Safari | run + DevTools console |
| Git | any | clone the repo |
| Internet to `cdn.jsdelivr.net` | — | Bootstrap/Icons/SheetJS (unless vendored) |

No Node, no database, no secrets.

## 4. Identity & credentials

**None.** Local development needs no credentials — the site has no backend, auth,
or cloud calls. (Deploy-time identity is covered in the AWS/Azure guides.)

## 5. Environment variables

**None.** The app reads no environment variables. The only tunable is the port
you pass to your static server.

| Variable | Example | Purpose |
|----------|---------|---------|
| _(none — app has no env vars)_ | — | State is `localStorage`; config is the CSP `<meta>` + CDN pins in `index.html` |

## 6. Configuration references

| Setting | Where | Example | Purpose |
|---------|-------|---------|---------|
| Server port | CLI arg | `8000` | `python3 -m http.server 8000` |
| Theme | `localStorage['bsTheme']` | `dark` | Default dark; toggle in-app |
| Branding | `localStorage['cmmi.branding.v1']` | `{"name":"…","accent":"#ff5811"}` | Logo/name/accent |
| CDN pins | `cmmi/index.html` | Bootstrap `5.3.3` | Change to test other versions |

## 7. Verification

```bash
# from the repo root:
python3 -m http.server 8000

# entry page returns 200
curl -I http://localhost:8000/cmmi/            # → HTTP/1.0 200 OK

# the required parent dataset resolves
curl -I http://localhost:8000/cmmidev3.js      # → 200, ~227 KB

# shared assets resolve
curl -I http://localhost:8000/theme.css        # → 200
```

Then in the browser at `http://localhost:8000/cmmi/`:

- [ ] DevTools console shows **no CSP violations** and no failed asset loads.
- [ ] Practices render; ML2/ML3 + domain filters and free-text search work.
- [ ] Set a practice status/notes → reload → it persists (check
      `Application → Local Storage` for `cmmi2_*` keys).
- [ ] Theme toggle persists (`bsTheme`).
- [ ] Settings → Branding: change name/accent/logo → applies live and persists.
- [ ] Trigger Excel export → an `.xlsx` downloads; print preview renders.

There is **no** login, database, server upload, or object storage to verify —
verify the client-side behaviors above instead.

## 8. Day-2 operations

- **Live edit:** change `cmmi/index.html` or `cmmi/branding.js` and refresh (hard
  reload to bypass cache). For `../cmmidev3.js` changes, hard-reload too.
- **Alternate servers:** `npx serve -l 8000 .` or `php -S localhost:8000` (from
  repo root) work equally.
- **Reset local state:** DevTools → Application → Local Storage → Clear, or run
  the in-app reset, to test the first-run experience.
- **Test offline behavior:** throttle/deny `cdn.jsdelivr.net` in DevTools to see
  the degraded (unstyled / no-export) state and validate the air-gapped need.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Blank page, no practices | Served from inside `cmmi/`, so `../cmmidev3.js` 404s | Serve from the **repo root**; open `/cmmi/` |
| Unstyled page | CDN unreachable (Bootstrap CSS failed) | Restore internet, or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| Console: `Refused to load … CSP` | Asset origin not allowed by the CSP | Use an allowed origin (`'self'`/jsDelivr) or adjust the CSP `<meta>` |
| Export does nothing | SheetJS failed to load | Check the `xlsx.full.min.js` request in Network tab |
| `file://` open fails entirely | `../` + CSP don't work off the filesystem | Use an HTTP server |
| Edits not showing | Browser cache | Hard reload (Ctrl/Cmd-Shift-R) |
