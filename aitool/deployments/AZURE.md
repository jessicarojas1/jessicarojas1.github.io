# Azure — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable, covering **Azure Commercial** and **Azure
Government**. Host the static files on **Azure Static Web Apps** or **Blob Storage
`$web` static website** fronted by **Azure Front Door / CDN**. There are **no app
secrets** (client-side site); the only identity is the deploy pipeline's **managed
identity** (federated with your CI via OIDC).

## 1. Deployment architecture

Two supported patterns:
- **Static Web Apps (SWA):** simplest; built-in global CDN + TLS + optional Entra ID
  auth. Deploy via GitHub Actions / Azure DevOps using OIDC.
- **Blob `$web` + Front Door:** upload files to the `$web` container; Front Door adds
  TLS, custom domain, caching, WAF, and security-header rules.

No compute, no database, no worker.

## 2. Topology

```
   Browser ──HTTPS──► Azure Front Door / SWA edge ──► origin
                        (TLS, WAF, cache, headers)      │
                                                        ├─ Static Web App, or
                                                        └─ Storage Account $web container
   CI (GitHub/DevOps) ──OIDC──► Entra workload identity ──► deploy (upload/publish)
   Browser ──HTTPS──► cdn.jsdelivr.net (Bootstrap, SRI)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Azure subscription | Commercial or **Azure Government** |
| Azure CLI | `az` 2.55+ (Gov: `az cloud set --name AzureUSGovernment`) |
| Resource group | for SWA or Storage + Front Door |
| Entra ID app / federated credential | for CI OIDC (no client secret) |
| Custom domain + DNS | optional |

## 4. Identity & credentials

- **Prefer a user-assigned managed identity or an Entra app with a federated credential**
  (OIDC from GitHub/DevOps) for deploys — **no storage account keys, no publish
  profiles committed**.
- Least-privilege role on the target: **Storage Blob Data Contributor** scoped to the
  storage account (Blob pattern), or the SWA deployment token issued to the pipeline
  (rotate/treat as secret in the CI store).
- **Runtime site has no credentials.** If content must be gated, use **SWA built-in
  auth (Entra ID)** or Front Door + Entra — an edge concern, not the app.

## 5. Environment variables

The **site consumes none.** These are **pipeline/CLI** variables:

| Variable | Example (Commercial) | Example (Azure Government) | Purpose |
|----------|----------------------|---------------------------|---------|
| `AZURE_STORAGE_ACCOUNT` | `staitoolprod` | `staitoolgov` | Target storage account (Blob pattern) |
| Blob endpoint | `https://<acct>.blob.core.windows.net` | `https://<acct>.blob.core.usgovcloudapi.net` | Object endpoint (note Gov suffix) |
| Front Door / web endpoint | `https://<name>.azurefd.net` | `https://<name>.azurefd.us` | Public edge |
| `AZURE_TENANT_ID` / `AZURE_CLIENT_ID` | GUIDs | GUIDs | Entra OIDC federated identity |
| Cloud name (CLI) | `AzureCloud` | `AzureUSGovernment` | `az cloud set --name ...` |

> **Gov endpoints differ:** `*.usgovcloudapi.net` (storage), `*.azurefd.us` (Front Door),
> Entra `login.microsoftonline.us`. Set `az cloud set --name AzureUSGovernment` first.

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| `$web` container | `$web` | Static website source (Blob pattern) |
| Index document | `index.html` | Landing page |
| Error document | `404.html` (repo root) or `index.html` | 404 handling |
| Front Door rules set | security headers + cache | Adds CSP/HSTS + cache-control |
| SWA config | `staticwebapp.config.json` (optional) | Headers, routes, auth for SWA |

Example SWA header config (`staticwebapp.config.json`, optional):

```json
{
  "globalHeaders": {
    "Content-Security-Policy": "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none'; base-uri 'self'",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "Strict-Transport-Security": "max-age=63072000; includeSubDomains"
  }
}
```

## 7. Verification

No login/DB/upload. Verify publish + edge + client behavior:

```bash
# Commercial
az cloud set --name AzureCloud
# Government
# az cloud set --name AzureUSGovernment

# Blob $web pattern — publish (whole repo so ../ assets resolve, or vendored aitool)
az storage blob upload-batch -d '$web' -s . --account-name <acct> --auth-mode login

# Verify
curl -I https://<name>.azurefd.net/aitool/index.html          # 200  (Gov: .azurefd.us)
curl -sI https://<name>.azurefd.net/aitool/index.html | grep -i content-security-policy
curl -I https://<name>.azurefd.net/theme.css                  # 200 shared asset
```
Browser: styled page; theme toggle + Settings branding persist (`localStorage`); tracker
JSON export downloads; no CSP/SRI console errors. If SWA auth is enabled, confirm the
Entra sign-in gate.

## 8. Day-2 operations

- **Deploy/update:** re-run the pipeline (`upload-batch` or SWA deploy). Purge Front
  Door cache: `az afd endpoint purge ... --content-paths '/*'`.
- **TLS/custom domain:** managed certs via SWA/Front Door; auto-renew.
- **Scaling:** handled by the platform edge; nothing to size.
- **Backups:** content is in git; optionally enable Blob soft-delete/versioning +
  GRS/GZRS for the storage account. See `../docs/DISASTER_RECOVERY.md`.
- **Logs:** Front Door / Storage diagnostic logs → Log Analytics for access auditing.
- **Bootstrap bump:** update version + SRI in HTML, redeploy, purge cache.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 404 on `../theme.css` | Only `aitool/` uploaded | Upload whole repo or vendor shared assets |
| Deploy 403 | Missing RBAC / wrong identity | Grant Storage Blob Data Contributor / fix federated cred |
| Wrong endpoint in Gov | Used commercial suffix | Use `*.usgovcloudapi.net` / `*.azurefd.us`; `az cloud set` |
| Stale content after deploy | Front Door cache | Purge endpoint (`/*`) |
| No security headers | Missing rules set / SWA config | Add Front Door rule set or `staticwebapp.config.json` |
| CSP/SRI console errors | Header mismatch / stale SRI | Fix CSP; update SRI hashes |
