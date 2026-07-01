# ISMS Document Library — Air-Gapped / Offline

Audience: operators deploying the ISMS library into an **isolated network with no
internet egress**. It is a **Type A static website**, so the only real obstacle
to air-gapping is the **jsDelivr CDN** dependency (Bootstrap 5.3.3 + devicon
SVGs). Vendor those assets, bundle the directory, and serve it from an internal
web server/registry. No backend, no database, no secrets, **no external calls**.

> Siblings: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) ·
> [AZURE.md](AZURE.md) · [AWS.md](AWS.md)

## 1. Deployment architecture

On a connected staging host: pull Bootstrap CSS/JS and the two devicon SVGs
locally, rewrite the HTML `<link>`/`<script>`/`<img>` to those local paths (drop
the CDN), recompute **SRI** for the local Bootstrap files, and `tar` the site into
an offline bundle. Transfer via approved media. Inside the enclave: serve from an
internal **nginx** (VM or container in an internal registry). After vendoring, the
CSP can be narrowed to **`'self'`** — no `cdn.jsdelivr.net` origins remain.

## 2. Topology

```
[ Connected staging ]                         [ Air-gapped enclave ]
  vendor assets → rewrite HTML → SRI                internal nginx (VM/pod)
  tar bundle + checksum                              root: /var/www/jrojas
        │  approved media / one-way transfer  ─────►   ├─ isms/*.html, isms.css,
        ▼                                              │  branding.js, vendor/…
  isms-offline-<ver>.tar.gz + .sha256                  └─ theme.css, script.js…
                                                     Browser ─HTTPS─► nginx
                                              NO external calls (no jsDelivr)
```

## 3. Prerequisites

| Item | Notes |
|------|-------|
| Connected staging host | to fetch + vendor assets |
| Internal nginx (VM) or registry + k8s | to serve inside the enclave |
| Approved transfer media / data diode | move the bundle in |
| `curl`, `tar`, `sha256sum` | build + verify the bundle |
| (optional) internal container registry | if serving via k8s ([KUBERNETES.md](KUBERNETES.md)) |

## 4. Identity & credentials

- **Running site:** none — public static files, no secrets, no external identity.
- **Transfer control:** integrity + provenance of the bundle (checksums, signed
  media) per your enclave's process; internal registry push uses internal creds.

## 5. Environment variables

**None.** No app env vars online or offline. Per-browser `localStorage`
(`bsTheme`, `isms_branding`) still works fully offline.

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Vendor dir | `isms/vendor/bootstrap@5.3.3/` | local Bootstrap CSS/JS |
| Icon dir | `isms/vendor/devicon/` | local GitHub/LinkedIn SVGs |
| CSP (post-vendor) | `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'` | no CDN origin needed |
| Serve root | repo root | so `../` shared assets resolve |

**Ollama / self-hosted LLM: Not applicable.** This library has **no AI feature**
and makes no inference calls, so there is nothing to run offline. (Noted only for
parity with the standard doc set.)

## 7. Verification

No login/DB/upload, and — critically — **no external calls**. Verify offline
integrity:

```bash
# ---- On connected staging: vendor + bundle ----
BS=isms/vendor/bootstrap@5.3.3; mkdir -p "$BS" isms/vendor/devicon
curl -Lo "$BS/bootstrap.min.css" https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css
curl -Lo "$BS/bootstrap.bundle.min.js" https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js
curl -Lo isms/vendor/devicon/github-original.svg   https://cdn.jsdelivr.net/gh/devicons/devicon/icons/github/github-original.svg
curl -Lo isms/vendor/devicon/linkedin-original.svg https://cdn.jsdelivr.net/gh/devicons/devicon/icons/linkedin/linkedin-original.svg
# Recompute SRI for the vendored Bootstrap files:
openssl dgst -sha384 -binary "$BS/bootstrap.min.css" | openssl base64 -A   # → integrity="sha384-…"
# Rewrite the HTML: replace cdn.jsdelivr.net URLs with vendor/ paths (and new SRI).
# Bundle:
tar czf isms-offline.tar.gz isms/ theme.css script.js roles.js users.js favicon.ico
sha256sum isms-offline.tar.gz > isms-offline.tar.gz.sha256

# ---- Inside the enclave ----
sha256sum -c isms-offline.tar.gz.sha256
tar xzf isms-offline.tar.gz -C /var/www/jrojas
curl -I http://internal-host/isms/index.html                # 200
# Confirm NO external references remain:
grep -R "cdn.jsdelivr.net" /var/www/jrojas/isms/ && echo "STILL HAS CDN" || echo "clean"
```

Browser (offline): hub renders with full styling, search/filters work, theme +
branding persist, CSP console clean, and DevTools **Network** shows **no external
requests**.

## 8. Day-2 operations

- **Updates:** re-vendor on staging when Bootstrap or content changes, rebuild the
  bundle + checksum, transfer, verify, extract. There is no live update path in.
- **Registry (k8s path):** mirror the built `isms-library` image into the internal
  registry; deploy per [KUBERNETES.md](KUBERNETES.md).
- **Backups:** keep the bundle + checksum with the git tag it was built from —
  **git remains the source of truth** ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **CVE feeds:** track Bootstrap advisories out-of-band; re-vendor to patch.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Unstyled / no JS offline | HTML still points at jsDelivr | Rewrite `<link>/<script>` to `vendor/` paths |
| Footer icons missing | devicon SVGs not vendored/rewritten | Vendor both SVGs; update `<img src>` |
| Browser blocks vendored Bootstrap | SRI hash not recomputed for local file | Recompute `sha384` and update `integrity` (or remove SRI for same-origin) |
| CSP blocks assets | CSP still lists CDN / too strict | Use the `'self'` CSP above |
| Checksum mismatch | Corrupted transfer | Re-transfer; re-run `sha256sum -c` |
| 404 on `/theme.css` | Shared assets not in bundle | Include `theme.css script.js roles.js users.js favicon.ico` in the tar |
