# Air-Gapped — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable and, for aerospace/defense, a natural fit. The site
already stores all data in the browser and makes **no backend calls**. The only external
runtime dependency is **Bootstrap from `cdn.jsdelivr.net`** — which must be **vendored**
(pulled local, CDN removed) for a truly offline deployment. Everything then runs with
**no external calls**.

## 1. Deployment architecture

Vendor Bootstrap into the project, drop the CDN `<link>`/`<script>` in favor of local
paths, bundle the whole repo (so the shared `../` assets are included), transfer the
bundle across the air gap, and serve it from an internal nginx (bare-metal, VM, or a
container in a private registry). No internet, no CDN, no secrets service, no LLM.

## 2. Topology

```
   Internal user browser ──HTTP(S)──► Internal nginx ──► /var/www/site (bundled repo)
                                          (TLS via internal CA)   ├─ aitool/*.html + branding.js
                                                                  ├─ vendor/bootstrap/*  (local)
                                                                  └─ theme.css, script.js, ... (shared)
   No egress. No cdn.jsdelivr.net. No external calls at all.
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Bundle host (online, one-time) | to fetch Bootstrap + build the tarball |
| Transfer medium | approved cross-domain / removable media |
| Internal web host | nginx on a VM, or an image in a private registry |
| Internal CA / cert | for HTTPS on the enclave |

## 4. Identity & credentials

- **Runtime site: none** (no backend, no secrets, no external services).
- **Deploy identity:** internal-only — a least-privilege account that can write the web
  root or push to the private registry. No cloud/OIDC needed inside the enclave.
- TLS key issued by the **internal CA**, stored `0600` on the host.

## 5. Environment variables

**None consumed by the site.** No secrets, no external endpoints, no CVE-feed URLs at
runtime.

| Variable | Example | Purpose |
|----------|---------|---------|
| (none for the app) | — | Fully offline static content |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Vendored Bootstrap CSS | `vendor/bootstrap/bootstrap.min.css` | Replaces CDN `<link>` |
| Vendored Bootstrap JS | `vendor/bootstrap/bootstrap.bundle.min.js` | Replaces CDN `<script>` |
| Web root | `/var/www/site` | Whole bundled repo |
| CSP (offline) | `default-src 'self'` only | No CDN host needed — tighten it |

**Vendoring steps (on an online host, one-time):**

```bash
# 1. Fetch the pinned Bootstrap 5.3.3 assets used by the pages
mkdir -p aitool/vendor/bootstrap
curl -Lo aitool/vendor/bootstrap/bootstrap.min.css \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -Lo aitool/vendor/bootstrap/bootstrap.bundle.min.js \
  https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js

# 2. In every aitool/*.html, replace the two CDN URLs with local paths.
#    CSS:  href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
#      →   href="vendor/bootstrap/bootstrap.min.css"
#    JS:   src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
#      →   src="vendor/bootstrap/bootstrap.bundle.min.js"
#    Keep the integrity= (SRI) attributes — they still validate the local files.
#    Also remove the jsDelivr preconnect/dns-prefetch <link>s in <head>.

# 3. Tighten CSP to drop the CDN host entirely (offline):
#    Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline';
#      style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'

# 4. Bundle the WHOLE repo (so ../theme.css, ../script.js, ../roles.js, ../users.js,
#    ../isms/isms.css, ../favicon.ico travel with it):
tar -C /path/to -czf jessicarojas-site-offline.tgz jessicarojas1.github.io
sha256sum jessicarojas-site-offline.tgz > jessicarojas-site-offline.tgz.sha256
```

**Ollama / self-hosted LLM:** **not applicable.** `aitool` has **no AI/LLM feature at
runtime** (it is documentation and evaluation tooling *about* adopting AI tools). There
is no model call to replace, so no Ollama/GPU inference is deployed. (Kept for doc-set
uniformity.)

## 7. Verification (inside the enclave)

No login/DB/upload, **and now no external calls at all** — verify offline serving:

```bash
# Transfer + verify the bundle
sha256sum -c jessicarojas-site-offline.tgz.sha256
tar -xzf jessicarojas-site-offline.tgz -C /var/www/site --strip-components=1

# Serve via internal nginx, then:
curl -I https://aitool.enclave.local/aitool/index.html         # 200
curl -I https://aitool.enclave.local/aitool/vendor/bootstrap/bootstrap.min.css  # 200
curl -I https://aitool.enclave.local/theme.css                 # 200 shared asset
```
Browser (offline): open DevTools **Network** and confirm **zero external requests** (no
`cdn.jsdelivr.net`); page is styled from local Bootstrap; theme toggle + Settings
branding persist (`localStorage`); tracker JSON export downloads; no CSP/SRI errors.

## 8. Day-2 operations

- **Updates:** produce a new bundle on the online host (re-vendor if Bootstrap changes,
  updating SRI), transfer, checksum, extract, reload nginx.
- **Backups:** the bundle + git mirror inside the enclave are the source of truth.
  See `../docs/DISASTER_RECOVERY.md`.
- **Registry path:** build the image from the (vendored) repo, push to the internal
  registry, run behind internal TLS.
- **No CVE feeds needed at runtime** — the site has no server packages; track Bootstrap
  advisories out-of-band and re-vendor when patching.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Page unstyled offline | CDN `<link>`/`<script>` still present | Repoint to `vendor/bootstrap/*`; remove preconnect |
| Network tab shows jsDelivr calls | A URL was missed in one page | Grep all `*.html` for `cdn.jsdelivr.net` and replace |
| SRI mismatch on local file | Vendored file differs from pinned hash | Re-download the exact 5.3.3 asset, or update `integrity=` |
| 404 on `../theme.css` | Bundled only `aitool/` | Bundle the whole repo (or vendor shared files into `aitool/`) |
| CSP blocks local Bootstrap | CSP still lists CDN host / too strict | Use offline CSP (`'self'` only) from step 3 |
| Checksum mismatch on transfer | Corrupted/truncated media | Re-transfer; re-verify `sha256sum -c` |
