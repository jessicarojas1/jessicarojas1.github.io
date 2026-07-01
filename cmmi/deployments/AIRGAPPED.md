# Air-Gapped — CMMI v2.0 Practice Reference

Run the site with **no internet**: vendor the CDN assets locally, copy in the
parent `../` assets (including `../cmmidev3.js`), tighten the CSP to `'self'`,
bundle the directory, and serve from an internal nginx.

> **Applicability / Ollama:** this site has **no AI feature**, so there is no
> hosted-AI API to replace with Ollama and no GPU/inference component. Ollama is
> **N/A** here. The only air-gap work is vendoring the three CDN assets and the
> parent files.

## 1. Deployment architecture

In a connected staging environment you fetch Bootstrap 5.3.3 (CSS + JS bundle),
Bootstrap Icons 1.11.3 (CSS + webfonts), and SheetJS into a local `vendor/`
folder, rewrite `index.html` to reference them instead of `cdn.jsdelivr.net`, and
**tighten the CSP** to drop the CDN origin. You also ensure the parent assets
(`cmmidev3.js`, `theme.css`, `favicon.ico`, `users.js`, `roles.js`, `script.js`,
`analytics.js`, `siteSearch.js`) are included. The result is `tar`'d, transferred
across the air gap, and served by an internal nginx. The running site makes
**zero** external calls.

## 2. Topology

```
[ Connected staging ]                         [ Air-gapped enclave ]
 fetch vendor assets ─┐                          internal nginx (offline)
 rewrite index.html   ├─▶ cmmi-airgap.tar ──▶    /srv/cmmi  (repo ROOT layout)
 tighten CSP          │      (sneakernet)          ├─ cmmi/index.html (CDN→vendor/)
 include ../ assets ──┘                            ├─ cmmi/vendor/bootstrap*, icons*, xlsx*
                                                   ├─ cmmidev3.js  (dataset)
                                                   └─ theme.css, *.js, favicon.ico
 Browser (enclave) ─HTTP(S)─▶ internal nginx        NO egress to the internet
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Connected staging host | to fetch vendor assets once |
| Internal nginx (or any static server) | in the enclave |
| Transfer medium | approved media for the air gap |
| `tar`, `curl`/`wget` | build the bundle |
| Internal TLS CA (optional) | for HTTPS inside the enclave |

## 4. Identity & credentials

No application credentials. Access control is the enclave's responsibility —
front nginx with the enclave's SSO/reverse proxy or basic-auth if needed. Bundle
transfers should follow the enclave's media-control procedure (checksum on both
sides).

## 5. Environment variables

The app uses none. Bundle/host variables:

| Variable | Example | Purpose |
|----------|---------|---------|
| `BUNDLE` | `cmmi-airgap.tar` | Offline artifact |
| `DOCROOT` | `/srv/cmmi` | Internal nginx docroot (repo root layout) |
| `INTERNAL_HOST` | `cmmi.enclave.local` | Internal hostname |

## 6. Configuration references

Vendor the CDN assets (connected staging), from the repo root:

```bash
mkdir -p cmmi/vendor/bootstrap cmmi/vendor/icons/fonts cmmi/vendor/xlsx

# Bootstrap 5.3.3 (CSS + JS bundle)
curl -Lo cmmi/vendor/bootstrap/bootstrap.min.css \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -Lo cmmi/vendor/bootstrap/bootstrap.bundle.min.js \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js

# Bootstrap Icons 1.11.3 (CSS + webfonts)
curl -Lo cmmi/vendor/icons/bootstrap-icons.min.css \
  https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css
curl -Lo cmmi/vendor/icons/fonts/bootstrap-icons.woff2 \
  https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2
curl -Lo cmmi/vendor/icons/fonts/bootstrap-icons.woff \
  https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff

# SheetJS (pin an explicit version for a stable offline copy)
curl -Lo cmmi/vendor/xlsx/xlsx.full.min.js \
  https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js
```

Rewrite `cmmi/index.html` references (CDN → `vendor/`) and **tighten the CSP** so
it no longer allows the CDN (self-only). Replace the `<meta>` CSP with:

```
default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; worker-src blob:; object-src 'none'; base-uri 'self';
```

> This drops `https://cdn.jsdelivr.net` from `script-src`/`style-src`/`font-src`/
> `connect-src` and removes `https:` from `img-src` — everything is now
> same-origin. (`'unsafe-inline'` still remains for the page's inline
> `<script>`/`<style>`; see [../OPEN_ITEMS.md](../OPEN_ITEMS.md).)

Then edit the asset paths in `index.html`:
`.../bootstrap@5.3.3/.../bootstrap.min.css` → `vendor/bootstrap/bootstrap.min.css`;
the JS bundle → `vendor/bootstrap/bootstrap.bundle.min.js`; icons →
`vendor/icons/bootstrap-icons.min.css`; SheetJS → `vendor/xlsx/xlsx.full.min.js`.

Bundle (from the repo root — include the parent assets):

```bash
tar -cf cmmi-airgap.tar \
  cmmi/index.html cmmi/branding.js cmmi/vendor \
  cmmidev3.js theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js
sha256sum cmmi-airgap.tar > cmmi-airgap.tar.sha256   # verify on the far side
```

Internal nginx (enclave), docroot = the extracted repo-root layout:

```nginx
server {
    listen 80;
    server_name cmmi.enclave.local;
    root /srv/cmmi;                 # extracted bundle root (repo-root layout)
    index index.html;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "no-referrer" always;
    location / { try_files $uri $uri/ =404; }
}
```

## 7. Verification

```bash
# checksum matches across the gap
sha256sum -c cmmi-airgap.tar.sha256

# entry page + dataset + vendored assets, all local
curl -I http://cmmi.enclave.local/cmmi/                         # → 200
curl -I http://cmmi.enclave.local/cmmidev3.js                   # → 200
curl -I http://cmmi.enclave.local/cmmi/vendor/xlsx/xlsx.full.min.js  # → 200

# confirm NO reference to the CDN remains
grep -c "cdn.jsdelivr.net" /srv/cmmi/cmmi/index.html            # → 0
```

Browser (offline): DevTools Network shows **no external requests**; CSP clean;
practices render/filter/search; status persists (`cmmi2_*`); theme persists
(`bsTheme`); branding applies; `.xlsx` export downloads; print renders. No
login/DB/upload/object-write exists to verify. Ollama/GPU: N/A.

## 8. Day-2 operations

- **Update bundle:** repeat the vendor + rewrite + `tar` on connected staging,
  transfer, extract, reload nginx. Re-verify the CDN-reference count is `0`.
- **Dependency refresh:** re-fetch Bootstrap/Icons/SheetJS at the pinned versions
  when patching; update SRI/hashes if you reintroduce them.
- **Backups:** keep the versioned `cmmi-airgap.tar` + checksum; Git (outside the
  enclave) is the source of truth.
- **No secrets, no rotation, no migrations, no worker.**

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Unstyled / no icons offline | A CDN reference survived the rewrite | `grep cdn.jsdelivr.net index.html` → replace with `vendor/…` |
| Icons missing but CSS loads | Webfonts not vendored | Include `bootstrap-icons.woff2/.woff` under `vendor/icons/fonts/` |
| Export broken | SheetJS not vendored / path wrong | Verify `vendor/xlsx/xlsx.full.min.js` path |
| Blank page | `cmmidev3.js` omitted from the tar | Re-bundle including the repo-root parent assets |
| CSP blocks vendored asset | CSP still points at CDN, or wrong origin | Apply the tightened self-only CSP above |
| Checksum mismatch | Corrupt transfer | Re-transfer; `sha256sum -c` before extracting |
