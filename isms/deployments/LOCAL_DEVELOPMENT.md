# ISMS Document Library — Local Development

Audience: developers editing or previewing the ISMS library on a laptop. It is a
**Type A static website** — no backend, no database, no build step. You serve the
files and open the page.

> Siblings: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
> [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
> [AIRGAPPED.md](AIRGAPPED.md)

## 1. Deployment architecture

A local static file server (Python `http.server`, Node `http-server`, or any
equivalent) serves the repository directory. The browser loads the ISMS pages and
pulls **Bootstrap 5.3.3** + devicon SVGs from the **jsDelivr CDN** (so you need
internet, or vendor the assets — see [AIRGAPPED.md](AIRGAPPED.md)). All state
(theme, branding) is in the browser's `localStorage`.

Serve from the **repository root**, because the pages reference shared assets one
level up: `../theme.css`, `../script.js`, `../roles.js`, `../users.js`,
`../favicon.ico`.

## 2. Topology

```
┌─────────────────── laptop ───────────────────┐
│  python3 -m http.server 8000  (repo root)     │
│      └─ serves ./isms/*.html, isms.css,       │
│         branding.js, ../theme.css, ../*.js     │
│                    ▲                           │
│   browser ─────────┘  http://localhost:8000/isms/index.html
│      │  loads Bootstrap 5.3.3 + devicons ──────┼──► jsDelivr CDN (internet)
│      ▼                                          │
│   localStorage: bsTheme, isms_branding          │
└────────────────────────────────────────────────┘
```

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Python | 3.x | `python3 -m http.server` (built-in) |
| — or Node | 18+ | `npx http-server` |
| Modern browser | current | Chrome/Firefox/Edge/Safari |
| git | 2.x | clone the repo |
| Internet | — | for CDN assets (skip by vendoring) |

No package install, no env vars, no database.

## 4. Identity & credentials

None. Local dev serves public static files — there is no login, no secret, no
cloud identity. (The in-page login modal is a client-side demo, not real auth.)

## 5. Environment variables

**None.** The app reads no environment variables. Per-browser state instead:

| Store key | Example | Purpose |
|-----------|---------|---------|
| `localStorage['bsTheme']` | `dark` | UI theme (default `dark`) |
| `localStorage['isms_branding']` | `{"name":"JRojas","logoUrl":"","accent":"#ff5811"}` | Branding |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Serve root | repo root (`jessicarojas1.github.io/`) | so `../` shared assets resolve |
| Entry URL | `http://localhost:8000/isms/index.html` | the hub page |
| Port | `8000` | any free port |

## 7. Verification

There is **no login, database, or server upload** to verify. Verify the real
client-side behaviors:

```bash
git clone https://github.com/jessicarojas1/jessicarojas1.github.io.git
cd jessicarojas1.github.io
python3 -m http.server 8000
```

- Open `http://localhost:8000/isms/index.html` → the hub renders (**200**).
- Assets resolve: `curl -I http://localhost:8000/isms/isms.css` and
  `http://localhost:8000/theme.css` → 200.
- **Search + type filters** (All/Policies/Procedures/Templates) narrow the cards.
- **Theme toggle** (☀️/🌙) flips and **persists** across reload
  (`localStorage['bsTheme']`).
- **Settings → Branding**: set a name/accent/logo → applies live and **persists**
  (`localStorage['isms_branding']`); a bad logo URL falls back to the text mark.
- Browser console: **no CSP violations** and no errors.

## 8. Day-2 operations

- **Edit live:** change any `pol-/pro-/tmp-*.html`, `isms.css`, or `branding.js`
  and hard-refresh (`Ctrl/Cmd+Shift+R`) — no rebuild.
- **Add a document:** create `pol-0NN-...html` (copy an existing one), then add a
  matching card in `index.html` with `data-type` + `data-title`.
- **Update Bootstrap:** bump the version in the `<link>`/`<script>` and **update
  the SRI `integrity` hash** to match.
- **Lint before commit:**
  ```bash
  grep -RniE 'on(click|change|submit|input)=' isms/*.html   # expect no matches
  grep -R 'integrity=' isms/index.html                       # SRI present
  ```

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Unstyled page / missing theme | Served from inside `isms/`, so `../theme.css` 404s | Serve from the **repo root** |
| No Bootstrap styling/JS | Offline / CDN blocked | Reconnect, or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| Footer icons missing | devicon CDN blocked | Same as above |
| Theme/branding won't persist | `localStorage` disabled (private mode / policy) | Use a normal window; defaults still render |
| Edits not showing | Browser cache | Hard-refresh; `http.server` sends no long cache |
| `Address already in use` | Port taken | Use another port: `python3 -m http.server 8080` |
