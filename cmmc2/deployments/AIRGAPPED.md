# Air-Gapped — `cmmc2`

Deploy the CMMC 2.0 Readiness Assessment Platform in a fully disconnected environment with
**zero external network calls**. This is a natural fit for the DoD/CUI audience: **vendor
the CDN assets locally** (Bootstrap, Bootstrap Icons, SheetJS), fold in the parent shared
assets, **tighten the CSP to `'self'`**, bundle the directory, and serve it from an internal
nginx.

> **Ollama / AI:** `cmmc2` has **no AI/LLM feature**, so there is **no hosted AI API to
> replace with self-hosted Ollama**. No inference server, model, or GPU is required for the
> air-gapped build. (This is the honest answer — do not stand up Ollama for this site.)

## 1. Deployment architecture

On a connected staging host you download the exact-pinned third-party assets, rewrite
`index.html` to reference local copies instead of `cdn.jsdelivr.net`, tighten the CSP to
remove the jsDelivr origin (leaving only `'self'` + `blob:`/`data:` as needed), assemble a
self-contained tree (app + parent assets), and `tar` it. Inside the enclave, the bundle is
transferred via approved media and served by an internal nginx over TLS. **No client makes
any outbound call** — everything is same-origin.

## 2. Topology

```
  [ CONNECTED STAGING ]                         [ AIR-GAPPED ENCLAVE ]
  download pinned assets                        approved media / one-way transfer
  Bootstrap 5.3.3, Icons 1.11.3, SheetJS  ──▶   ┌──────────────────────────────┐
  rewrite index.html → ./vendor/...             │  Internal nginx (TLS, headers)│
  tighten CSP to 'self'                         │   docroot: cmmc2 bundle       │
  tar cmmc2-airgap.tgz  ───── sneakernet ─────▶ │   ├── cmmc2/index.html        │
                                                │   ├── cmmc2/branding.js       │
                                                │   ├── cmmc2/vendor/{bootstrap,│
                                                │   │      bootstrap-icons,xlsx}│
                                                │   ├── theme.css, favicon.ico  │
                                                │   └── *.js, index.html        │
                                                └───────────▲──────────────────┘
                                                   Browser (no internet) ── HTTPS
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Connected staging host | to fetch + repackage assets (one-time / per-update) |
| Approved transfer media | for crossing the air gap |
| Internal nginx host | inside the enclave (see [`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md)) |
| Internal CA / cert | for TLS inside the enclave |
| `curl`, `tar`, `sha384sum` | for vendoring + integrity |

## 4. Identity & credentials

- **Running site:** none.
- **Transfer/deploy:** governed by the enclave's media-control and change procedures; the
  internal nginx deploy user has least-privilege write to the docroot only. No cloud
  identities apply.

## 5. Environment variables

**None for the app.** Air-gapped build/serve variables:

| Variable | Example | Purpose |
|---|---|---|
| _(none — app)_ | — | No runtime env vars |
| `VENDOR_DIR` (build) | `cmmc2/vendor` | Where local copies of CDN assets land |
| `BUNDLE` (build) | `cmmc2-airgap.tgz` | Offline bundle name |
| `WEBROOT` (enclave) | `/var/www/portfolio` | Internal nginx docroot |

## 6. Configuration references — vendoring & CSP tightening

### 6.1 Fetch the exact pinned assets (connected staging)
```bash
mkdir -p cmmc2/vendor/bootstrap cmmc2/vendor/bootstrap-icons/font cmmc2/vendor/xlsx
# Bootstrap 5.3.3 (matches the SRI already in index.html)
curl -Lo cmmc2/vendor/bootstrap/bootstrap.min.css https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -Lo cmmc2/vendor/bootstrap/bootstrap.bundle.min.js https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js
# Bootstrap Icons 1.11.3 (CSS + font files it references)
curl -Lo cmmc2/vendor/bootstrap-icons/bootstrap-icons.min.css https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css
curl -Lo cmmc2/vendor/bootstrap-icons/font/bootstrap-icons.woff2 https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2
curl -Lo cmmc2/vendor/bootstrap-icons/font/bootstrap-icons.woff  https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff
# SheetJS — pinned to the same version index.html now uses (xlsx@0.18.5, the last npm release)
curl -Lo cmmc2/vendor/xlsx/xlsx.full.min.js https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js
# Record integrity for the manifest
sha384sum cmmc2/vendor/bootstrap/* cmmc2/vendor/bootstrap-icons/*.css cmmc2/vendor/xlsx/*.js
```
> Fix the Icons CSS `url(...)` font paths to point at the vendored `font/` copies. SheetJS is
> now pinned to `xlsx@0.18.5` in `index.html` (see [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md)).

### 6.2 Rewrite `index.html` references (staging copy only)
Repoint the four CDN references to local paths and drop the CDN hints:

| From | To |
|---|---|
| `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css` | `vendor/bootstrap/bootstrap.min.css` |
| `https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css` | `vendor/bootstrap-icons/bootstrap-icons.min.css` |
| `https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js` | `vendor/bootstrap/bootstrap.bundle.min.js` |
| `https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js` | `vendor/xlsx/xlsx.full.min.js` |

Also remove the `<link rel="preconnect"/dns-prefetch" href="https://cdn.jsdelivr.net">`
hints (no external origin anymore).

### 6.3 Tighten the CSP (staging copy `<meta>` + edge header)
Remove `https://cdn.jsdelivr.net`; keep only what same-origin + local features need:

```
default-src 'self' blob:; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self' blob:; worker-src blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'
```

(`'unsafe-inline'` still required until the inline scripts/handlers/styles in `index.html`
are externalized — tracked in [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md). `img-src` drops `https:`
since logos are now local/`data:`; if operators still paste remote HTTPS logo URLs, re-add
`https:` to `img-src` only.)

### 6.4 Assemble + bundle (self-contained; parent assets included)
```bash
mkdir -p airgap/cmmc2
cp -r cmmc2/index.html cmmc2/branding.js cmmc2/vendor airgap/cmmc2/
cp theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js index.html airgap/
tar czf cmmc2-airgap.tgz -C airgap .
sha384sum cmmc2-airgap.tgz > cmmc2-airgap.tgz.sha384    # transfer manifest
```

### 6.5 Serve inside the enclave
Transfer, verify, extract into the nginx docroot, and serve with the tightened CSP header
(reuse the [`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) nginx config but replace the
CSP value with the `'self'` version above):
```bash
sha384sum -c cmmc2-airgap.tgz.sha384
sudo tar xzf cmmc2-airgap.tgz -C /var/www/portfolio
sudo systemctl reload nginx
```

## 7. Verification

Confirm there are **no external calls** and the client behaviors still work:

```bash
# Entry 200; tightened CSP has NO jsdelivr origin
curl -sI https://cmmc.internal/cmmc2/ | head -n1
curl -sI https://cmmc.internal/cmmc2/ | grep -i content-security-policy   # must NOT contain cdn.jsdelivr.net

# Vendored assets resolve locally
for u in cmmc2/vendor/bootstrap/bootstrap.min.css \
         cmmc2/vendor/bootstrap/bootstrap.bundle.min.js \
         cmmc2/vendor/bootstrap-icons/bootstrap-icons.min.css \
         cmmc2/vendor/xlsx/xlsx.full.min.js \
         theme.css users.js roles.js script.js; do
  printf '%-52s ' "$u"; curl -so /dev/null -w '%{http_code}\n' "https://cmmc.internal/$u"
done

# No egress: in the browser devtools Network tab, confirm ZERO requests to cdn.jsdelivr.net
```

Browser (offline): page renders styled; no CSP violations; branding applies (use local/
`data:` logos); theme persists; marking a control updates the SPRS score; `.xlsx` export
downloads — all with the network cable pulled.

## 8. Day-2 operations

| Task | Action |
|---|---|
| Update the site | Re-run §6 on staging, re-bundle, re-transfer, verify checksum, extract |
| Dependency updates (Bootstrap/Icons/SheetJS) | Re-fetch pinned versions on staging, update SRI/manifest, re-bundle |
| Integrity | Keep the `sha384` manifest with each bundle; verify on ingest |
| Backups | Store the versioned `.tgz` + manifest with offline media/config management |
| CVE tracking | Track Bootstrap/SheetJS advisories via your offline feed; roll a new bundle when needed |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Unstyled page offline | CDN references not rewritten | Repoint all four `<link>/<script>` to `vendor/…` |
| Icons missing (boxes) | Icons CSS `url()` points at CDN font paths | Fix `url(...)` to the vendored `font/` files |
| CSP blocks vendored asset | CSP still references jsdelivr / too strict | Use the `'self'` CSP from §6.3; ensure assets are same-origin |
| Export button dead | SheetJS not vendored/loaded | Confirm `vendor/xlsx/xlsx.full.min.js` present and referenced |
| Any request to `cdn.jsdelivr.net` | A reference was missed | Grep `index.html` for `jsdelivr`; rewrite it |
| Checksum mismatch on ingest | Corrupt/altered bundle | Re-transfer; re-verify `sha384sum -c` |

See also: [`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`../docs/SECURITY.md`](../docs/SECURITY.md) · [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md).
