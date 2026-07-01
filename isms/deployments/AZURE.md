# ISMS Document Library — Azure (Commercial + Azure Government)

Audience: operators hosting the ISMS library on **Azure Static Web Apps** (or
**Blob `$web` static hosting + Front Door**) in **Azure Commercial** or **Azure
Government** (`*.usgovcloudapi.net`). It is a **Type A static website** — no
backend, no database, no server auth, **no application secrets**. The only
identity is the **deploy pipeline** (managed identity / OIDC), not app keys.

> Siblings: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [KUBERNETES.md](KUBERNETES.md) ·
> [AWS.md](AWS.md) · [AIRGAPPED.md](AIRGAPPED.md)

## 1. Deployment architecture

Two equivalent Azure paths:

- **Azure Static Web Apps (SWA)** — simplest: point SWA at the repo, publish the
  root, configure headers/routes via `staticwebapp.config.json`, get managed TLS
  + global CDN. No backend API is used.
- **Blob `$web` + Azure Front Door** — upload files to the `$web` container of a
  storage account (static website enabled); Front Door adds TLS, CDN, and a
  header/rules policy. Storage stays private behind Front Door where possible.

Either way the site is public static content; land users at `/isms/index.html`
and serve the repo root so `../` shared assets resolve.

## 2. Topology

```
                       Azure DNS / custom domain
                              │
Browser ─HTTPS─► Static Web Apps  ── OR ──  Front Door (TLS, rules/headers)
                  (managed TLS+CDN,                     │
                   staticwebapp.config.json)            ▼
                              │                   Storage $web (static website)
                              ▼                   SSE (Microsoft-managed keys)
                        serves /isms/*.html, isms.css, branding.js,
                                ../theme.css, ../*.js
   CI: GitHub Actions/Azure Pipelines → OIDC/managed identity → deploy
   Browser also loads Bootstrap 5.3.3 + devicons ──► jsDelivr CDN (or vendored)
```

## 3. Prerequisites

| Item | Version | Notes |
|------|---------|-------|
| Azure subscription | Commercial **or** Government | Gov is a separate cloud/tenant |
| Azure CLI | 2.5x+ | `az cloud set --name AzureUSGovernment` for Gov |
| SWA **or** Storage + Front Door | — | pick one path |
| Entra ID app / federated credential | — | OIDC for CI (keyless) |
| Custom domain + cert | — | managed by SWA/Front Door |

## 4. Identity & credentials

- **Running site:** none — public static files, no secrets.
- **Deploy identity:** a **federated credential (OIDC)** on an Entra ID app or a
  **user-assigned managed identity** with least privilege:
  - SWA: the SWA **deployment token** (store in the CI secret store) or OIDC.
  - Blob path: `Storage Blob Data Contributor` on the storage account **scoped**
    to it, plus Front Door purge rights.
- **Gov:** use **Azure Government** endpoints and the Gov tenant; managed identity
  works identically.

## 5. Environment variables

The app uses **no environment variables**. **CI/deploy** values, Commercial vs
Government where they differ:

| Variable | Commercial example | Government example | Purpose |
|----------|--------------------|--------------------|---------|
| `AZURE_CLOUD` | `AzureCloud` | `AzureUSGovernment` | CLI/SDK cloud |
| `AZURE_STORAGE_ACCOUNT` | `ismssite` | `ismssitegov` | Blob path account |
| Storage blob endpoint | `https://<acct>.blob.core.windows.net` | `https://<acct>.blob.core.usgovcloudapi.net` | `$web` upload target |
| ARM endpoint | `https://management.azure.com` | `https://management.usgovcloudapi.net` | control plane |
| Entra (login) endpoint | `https://login.microsoftonline.com` | `https://login.microsoftonline.us` | OIDC token |
| `SWA_DEPLOYMENT_TOKEN` | (secret) | (secret) | SWA publish (if SWA path) |
| `FRONTDOOR_PROFILE` | `isms-fd` | `isms-fd-gov` | CDN/purge (if Blob path) |

## 6. Configuration references

Static Web Apps `staticwebapp.config.json` (headers + landing route):

```json
{
  "routes": [
    { "route": "/", "redirect": "/isms/index.html" }
  ],
  "globalHeaders": {
    "Content-Security-Policy": "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": "geolocation=(), microphone=(), camera=()"
  }
}
```

| Setting | Example | Purpose |
|---------|---------|---------|
| Static website index | `index.html` | `$web` default doc |
| Front Door rules-engine | add CSP/HSTS headers | headers when using Blob path |
| Storage public access | disabled (Front Door origin) | keep origin private where possible |
| Managed TLS | on (SWA/Front Door) | HTTPS + auto-renew |

## 7. Verification

No login/DB/upload. Verify publish, serving, headers, client behavior:

```bash
# Commercial vs Gov cloud
az cloud set --name AzureUSGovernment   # (Gov only)
az login --use-device-code

# Blob $web path: upload the repo root
az storage blob upload-batch -s . -d '$web' \
  --account-name $AZURE_STORAGE_ACCOUNT --overwrite \
  --pattern '*' --destination-path .

# Entry + assets
curl -I https://isms.example.com/isms/index.html      # 200
curl -I https://isms.example.com/isms/isms.css        # 200
curl -I https://isms.example.com/theme.css            # 200 (shared asset)

# Headers
curl -sI https://isms.example.com/isms/index.html | grep -iE 'content-security-policy|x-frame-options'
```

Browser: hub renders, search/filters work, theme + branding persist, CSP clean.
"Object written" = the blobs exist (`az storage blob list --container-name '$web'
--account-name $AZURE_STORAGE_ACCOUNT -o table`).

## 8. Day-2 operations

- **Deploy:** CI `az storage blob upload-batch` (Blob) or SWA action (SWA) on
  merge; **purge Front Door** after Blob deploys.
- **Rollback:** enable blob versioning/soft-delete, or re-upload a prior git tag.
- **Certs:** managed by SWA/Front Door (auto-renew).
- **Backups:** blob soft-delete/versioning + **git as source of truth**
  ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **Monitoring:** Azure Monitor / Front Door metrics + logs; alert on 5xx and
  cert expiry.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `/theme.css` | Published `isms/` only | Upload the **repo root** so `../` assets exist |
| Wrong cloud endpoints | CLI pointed at Commercial | `az cloud set --name AzureUSGovernment` |
| Headers missing (Blob path) | `staticwebapp.config.json` ignored (only SWA reads it) | Add headers via Front Door rules engine |
| Stale content | Front Door cache | Purge the endpoint |
| Login/OIDC fails in Gov | Using `microsoftonline.com` | Use `login.microsoftonline.us` + Gov ARM endpoint |
| Unstyled pages | jsDelivr blocked | Vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
