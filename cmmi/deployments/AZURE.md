# Azure — CMMI v2.0 Practice Reference

Host the static site on **Azure Static Web Apps** (or Blob `$web` static hosting
behind **Front Door**), for **Azure Commercial** and **Azure Government**. Deploy
via a **managed identity / OIDC federated credential** — no app secrets, because
the site is client-side.

## 1. Deployment architecture

The **repository root** is published (so `../cmmidev3.js` and the shared `../`
assets resolve), with the app served at `/cmmi/`. Two equivalent options:

- **Azure Static Web Apps (SWA):** point the app at the repo root (`app_location:
  "/"`); no build (`output_location` = repo root). Managed TLS + global CDN
  built in.
- **Blob `$web` + Front Door:** enable the `$web` static container, upload the
  repo root, front with Azure Front Door for TLS, caching, and edge headers.

No backend, database, Key Vault secret, or App Service runtime is required for
the running site.

## 2. Topology

```
                 Azure Commercial            |     Azure Government
Browser ─HTTPS─▶ Front Door / SWA edge       |  Front Door / SWA (*.usgovcloudapi.net)
                     │  managed TLS          |     managed TLS (Gov endpoints)
                     ▼                       |         ▼
             Blob $web  OR  SWA content       |  Blob $web (core.usgovcloudapi.net)
             (repo ROOT: cmmi/ + cmmidev3.js) |  (same layout)
Browser ─HTTPS─▶ cdn.jsdelivr.net (Bootstrap 5.3.3 / Icons 1.11.3 / SheetJS)
CI (GitHub Actions) ─OIDC(no secret)─▶ Entra ID → deploy role → upload + purge
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Azure subscription | Commercial or **Azure Government** |
| Azure CLI | `az` ≥ 2.55 (`az cloud set --name AzureUSGovernment` for Gov) |
| Storage account (Blob option) | with static website (`$web`) enabled |
| SWA (SWA option) | Standard or Free tier |
| Front Door (Blob option) | for TLS + custom domain + edge headers |
| Entra ID app registration | federated credential for CI OIDC |

## 4. Identity & credentials

Use **OIDC federated credentials** so CI holds **no secret**:

```bash
# App registration + federated credential for GitHub OIDC
az ad app create --display-name cmmi-deployer
az ad app federated-credential create --id <APP_ID> --parameters '{
  "name":"github-main",
  "issuer":"https://token.actions.githubusercontent.com",
  "subject":"repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main",
  "audiences":["api://AzureADTokenExchange"]
}'
```

Least-privilege role assignment (Blob option) — scope to the storage account only:

```bash
az role assignment create --assignee <APP_SP_ID> \
  --role "Storage Blob Data Contributor" \
  --scope /subscriptions/<SUB>/resourceGroups/<RG>/providers/Microsoft.Storage/storageAccounts/<ACCT>
```

For SWA, use the SWA deployment token stored as a CI secret **or** OIDC via the
`Azure/static-web-apps-deploy` action with a federated identity. No application
secrets exist.

## 5. Environment variables

The app has none. Deploy-pipeline variables differ only by cloud:

| Variable | Commercial example | Government example | Purpose |
|----------|--------------------|--------------------|---------|
| `AZURE_ENV` | `AzureCloud` | `AzureUSGovernment` | `az cloud set --name …` |
| `STORAGE_ACCOUNT` | `cmmiweb` | `cmmiwebgov` | Blob `$web` host |
| `BLOB_ENDPOINT` | `https://cmmiweb.blob.core.windows.net` | `https://cmmiwebgov.blob.core.usgovcloudapi.net` | Upload target |
| `WEB_ENDPOINT` | `https://cmmiweb.z13.web.core.windows.net` | `https://cmmiwebgov.z13.web.core.usgovcloudapi.net` | Static site URL |
| `AZURE_TENANT_ID` / `AZURE_CLIENT_ID` | GUID | GUID | OIDC login (no secret) |

## 6. Configuration references

Blob `$web` publish (repo root → `$web`):

```bash
# Commercial
az cloud set --name AzureCloud
az storage blob service-properties update --account-name cmmiweb \
  --static-website --index-document cmmi/index.html --404-document cmmi/index.html
az storage blob upload-batch --account-name cmmiweb -d '$web' -s . \
  --auth-mode login --overwrite

# Government (same commands after: az cloud set --name AzureUSGovernment)
```

`staticwebapp.config.json` (SWA option) — headers + root redirect:

```json
{
  "routes": [ { "route": "/", "redirect": "/cmmi/" } ],
  "globalHeaders": {
    "Strict-Transport-Security": "max-age=31536000; includeSubDomains",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "SAMEORIGIN",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": "geolocation=(), microphone=(), camera=()"
  }
}
```

| Setting | Example | Purpose |
|---------|---------|---------|
| `index-document` | `cmmi/index.html` | Entry page under repo root |
| Front Door route | `/*` → Blob origin | TLS + caching + headers |
| Cache rule | short on `index.html`, mind unversioned `cmmidev3.js` | Freshness |

## 7. Verification

```bash
# entry page + parent dataset (Commercial)
curl -I https://cmmiweb.z13.web.core.windows.net/cmmi/          # → 200
curl -I https://cmmiweb.z13.web.core.windows.net/cmmidev3.js    # → 200

# Government
curl -I https://cmmiwebgov.z13.web.core.usgovcloudapi.net/cmmi/ # → 200

# headers via Front Door
curl -sI https://cmmi.example.com/cmmi/ | grep -Ei 'strict-transport|x-content-type'
```

Browser: CSP clean, practices render/filter/search, status persists (`cmmi2_*`),
theme persists (`bsTheme`), branding applies, `.xlsx` export downloads, print
renders. No login/DB/upload/object-write to verify — none exist (the branding
"upload" is a client-side `data:` URL, not an Azure upload).

## 8. Day-2 operations

- **Deploy/update:** re-run `upload-batch` (Blob) or push to the SWA-linked
  branch. Purge Front Door cache after deploy so `cmmidev3.js` refreshes:
  `az afd endpoint purge … --content-paths '/*'`.
- **TLS/custom domain:** managed by SWA or Front Door; validate domain once.
- **Backups:** none needed (no state) — Git is the source of truth.
- **Rotation:** OIDC federated credentials are short-lived; nothing static to
  rotate.
- **Logs/metrics:** Front Door diagnostics / Storage metrics for request volume.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `/cmmi/` blank | Only `cmmi/` uploaded; `../cmmidev3.js` 404 | Upload the **repo root** to `$web` |
| 404 at site root | `index-document` not set / wrong | Set to `cmmi/index.html` or add `/` → `/cmmi/` redirect |
| Stale content | Front Door cache | Purge `/*` after deploy |
| Gov endpoints unreachable | CLI on wrong cloud | `az cloud set --name AzureUSGovernment` |
| Deploy 403 | Missing role assignment | Grant `Storage Blob Data Contributor` on the account |
| Unstyled page | CDN blocked | Allow `cdn.jsdelivr.net` or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
