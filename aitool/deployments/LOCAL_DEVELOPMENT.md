# Local Development — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable. This is a static site; local development means
serving the files and editing HTML/CSS/JS with live reload in the browser.

## 1. Deployment architecture

A local static file server (Python `http.server`, Node `http-server`, or an `nginx`
container) serves the repo. The browser is the runtime. No backend, no database, no
build. Bootstrap loads from `cdn.jsdelivr.net` at runtime, so an internet connection is
required unless you vendor it.

## 2. Topology

```
  Developer browser ──HTTP──► localhost static server ──► repo files (aitool/*.html, branding.js)
        │                                                   ../theme.css, ../script.js, ...
        └──HTTPS──► cdn.jsdelivr.net  (Bootstrap 5.3.3, SRI)
  State: browser localStorage (bsTheme, aitool.branding.v1, tracker store)
```

## 3. Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| Python 3 **or** Node.js | 3.8+ / 18+ | Static file server |
| Modern browser | current | Runtime |
| Docker (optional) | 24+ | Run the `nginx` container path |
| Internet access | — | Bootstrap CDN (unless vendored) |

## 4. Identity & credentials

**None.** Local dev needs no cloud identity, no API keys, no secrets. The site consumes
no environment variables. (Deploy identity — CI OIDC — only matters for the cloud
targets.)

## 5. Environment variables

**None consumed by the site.** The only variable is your chosen local port.

| Variable | Example | Purpose |
|----------|---------|---------|
| `PORT` (your shell, optional) | `8000` | Port for the local static server |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Serve root | repo root (`jessicarojas1.github.io/`) | So `../theme.css`, `../script.js` etc. resolve |
| Entry URL | `/aitool/index.html` | Landing page |
| `localStorage: bsTheme` | `dark` | Theme preference |
| `localStorage: aitool.branding.v1` | `{}` | Branding settings |

## 7. Verification

There is **no login, database, or server upload to verify.** Verify the static + client
behavior:

```bash
# Serve from the REPO ROOT so ../ shared assets resolve
cd /path/to/jessicarojas1.github.io
python3 -m http.server 8000
#   or: npx http-server -p 8000 .
#   or (container): docker build -t aitool ./aitool && docker run --rm -p 8080:8080 aitool

curl -I http://localhost:8000/aitool/index.html     # expect: 200
curl -I http://localhost:8000/aitool/branding.js    # expect: 200 (javascript)
curl -I http://localhost:8000/theme.css             # expect: 200 (shared asset)
```

In the browser at `http://localhost:8000/aitool/index.html`:
- Page renders styled (Bootstrap loaded); DevTools console shows no CSP/SRI errors.
- **Theme toggle** (☀️) flips dark/light and persists on reload (`bsTheme`).
- **Settings ⚙️** opens; set a name/accent/logo → brand updates and persists
  (`aitool.branding.v1`); a bad logo URL falls back to the text mark.
- **`vendor-tracker.html`:** add a card, then **Export JSON** downloads a file.

> Serving only `aitool/` (not the repo root) makes the pages load but `../` assets 404
> (unstyled/partly-broken). Serve from the repo root, or vendor the shared files.

## 8. Day-2 operations

- **Edit → refresh.** No build/watch needed; HTML is served as-is. Use the browser's
  disable-cache (DevTools) while iterating.
- **Bootstrap bump:** change the version in the `<link>`/`<script>` URLs **and** update
  the `integrity=` SRI hashes together (get hashes from jsDelivr/SRI Hash generator).
- **Branding/theme reset:** clear site data in DevTools (removes `localStorage` keys).
- **Container path:** rebuild the image after edits (`docker build -t aitool ./aitool`).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Page unstyled, `../theme.css` 404 | Served `aitool/` instead of repo root | Serve from repo root, or vendor shared assets |
| Blank/partly-broken layout, console SRI error | Bootstrap version changed but SRI hash not updated | Update `integrity=` to match the new file |
| No Bootstrap at all (offline) | CDN unreachable | Connect to internet, or vendor Bootstrap (`AIRGAPPED.md`) |
| Theme doesn't persist | `localStorage` blocked/incognito restrictions | Use a normal profile; allow site data |
| Container returns 404 for `../` assets | Image ships only `aitool/` | Vendor shared assets or build from repo root (see `Dockerfile` note) |
| Port already in use | Another process on the port | Use a different `PORT` |
