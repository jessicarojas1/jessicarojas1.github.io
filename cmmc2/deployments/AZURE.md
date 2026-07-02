# Azure — `cmmc2` (Commercial + Azure Government)

Host the CMMC 2.0 Readiness Assessment Platform on **Azure Static Web Apps** (or **Blob
`$web` static website + Azure Front Door**). Deploy via a **workload-identity / OIDC
federated credential** (Managed Identity / Entra app) — **no app secrets**, no static keys.

> **Realistic target:** for DoD/CUI users, **Azure Government** (`*.usgovcloudapi.net`,
> Entra ID Gov, Gov regions like `usgovvirginia`/`usgovtexas`) is the production cloud.
> Commercial is documented for dev/demo. Differences are called out per section.

## 1. Deployment architecture

Two supported patterns:

- **Static Web Apps (SWA):** simplest — SWA hosts the files and provides TLS + global CDN;
  deploy from GitHub Actions with an OIDC/federated credential. App at `/cmmc2/`.
- **Blob `$web` + Front Door:** upload the repo tree to the `$web` container of a storage
  account's static-website feature; front it with **Azure Front Door** for TLS, custom
  domain, caching, and a security-headers/WAF policy.

No compute, database, or secret is involved. The browser fetches Bootstrap/Icons/SheetJS
from jsDelivr (or the vendored air-gapped build).

## 2. Topology

```
 Developer ──git push──▶ GitHub Actions ──OIDC federated cred──▶ Entra app / Managed Identity
                                   │ swa deploy  OR  az storage blob upload-batch
                                   ▼
   Custom domain ─▶ Front Door / SWA (TLS, CDN, headers/WAF) ─▶ Blob $web  or  SWA content
             │                                                    cmmc2/index.html, branding.js,
        Browser ◀── HTTPS ── edge cache                           theme.css, *.js, index.html
             └── fetches Bootstrap/Icons/SheetJS from cdn.jsdelivr.net
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Azure subscription | Commercial and/or **Azure Government** |
| Resource group | in the target cloud/region |
| Storage account (Blob pattern) | static website enabled → `$web` container |
| Front Door profile (Blob pattern) | TLS, custom domain, rules |
| Entra ID app / User-Assigned Managed Identity | for OIDC federation |
| Azure CLI | `az cloud set` to `AzureUSGovernment` for Gov |
| GitHub repo | for federated credentials |

## 4. Identity & credentials

**Use an Entra federated credential (OIDC) — no client secrets, no storage keys in CI.**

1. Create an Entra app (or user-assigned managed identity) and add a **federated credential**
   trusting `token.actions.githubusercontent.com` scoped to `repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main`.
2. Grant **least-privilege RBAC** on the storage account / SWA only:

| Role | Scope | Why |
|---|---|---|
| **Storage Blob Data Contributor** | the storage account (Blob pattern) | Upload to `$web` |
| **CDN/Front Door Endpoint Contributor** (or `Microsoft.Cdn/profiles/afdEndpoints/purge/action`) | the Front Door profile | Purge cache |
| **Static Web Apps Contributor** | the SWA resource (SWA pattern) | Deploy content |

Prefer **Azure AD (Entra) authorization** over storage account keys (`--auth-mode login`).
There are **no app secrets** to store.

## 5. Environment variables

The app has **no runtime env vars**. CI/deploy variables:

| Variable | Commercial example | Government example | Purpose |
|---|---|---|---|
| `AZURE_CLOUD` | `AzureCloud` | `AzureUSGovernment` | `az cloud set --name` |
| `AZURE_TENANT_ID` | `<guid>` | `<gov-guid>` | Entra tenant |
| `AZURE_CLIENT_ID` | `<app-guid>` | `<app-guid>` | Federated app/identity |
| `STORAGE_ACCOUNT` | `cmmc2site` | `cmmc2sitegov` | Blob pattern |
| `BLOB_ENDPOINT` | `...blob.core.windows.net` | `...blob.core.usgovcloudapi.net` | Gov suffix differs |
| `AFD_PROFILE` / `AFD_ENDPOINT` | `cmmc2-fd` | `cmmc2-fd-gov` | Front Door |
| `SWA_NAME` (SWA pattern) | `cmmc2-swa` | `cmmc2-swa-gov` | Static Web App |

**Gov endpoint differences:** Blob `*.blob.core.usgovcloudapi.net`; Entra login
`login.microsoftonline.us`; ARM `management.usgovcloudapi.net`. Set `az cloud set --name
AzureUSGovernment` before any `az` call.

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Static website index | `index.html` | Portfolio home; app at `/cmmc2/` |
| Cache (html) | `no-cache` | Prompt updates |
| Cache (assets) | `max-age=86400` | Cache css/js/ico |
| Front Door Rules Set / SWA `staticwebapp.config.json` | CSP + HSTS + nosniff headers | Edge security headers |
| TLS | Managed cert (SWA/Front Door) | HTTPS |
| Blob encryption | SSE (default) | At-rest |
| Blob versioning / soft delete | Enabled | Rollback / DR |

**SWA header config** (`staticwebapp.config.json` at repo root):

```json
{
  "globalHeaders": {
    "Content-Security-Policy": "default-src 'self' blob:; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; img-src 'self' https: data: blob:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self' https://cdn.jsdelivr.net blob:; worker-src blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'",
    "Strict-Transport-Security": "max-age=63072000; includeSubDomains",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "X-Frame-Options": "DENY"
  }
}
```

**Blob upload (from repo root, Entra auth):**
```bash
az cloud set --name AzureUSGovernment       # or AzureCloud
az storage blob upload-batch --auth-mode login \
  --account-name $STORAGE_ACCOUNT -d '$web' -s . --overwrite \
  --content-cache "public, max-age=86400"
# purge Front Door
az afd endpoint purge -g <rg> --profile-name $AFD_PROFILE \
  --endpoint-name $AFD_ENDPOINT --content-paths '/*'
```

## 7. Verification

No login/DB/user-upload/object-write-by-users; the "object written" step is the **deploy**
writing blobs. Verify deploy + client behavior:

```bash
# Blob present
az storage blob show --auth-mode login --account-name $STORAGE_ACCOUNT \
  -c '$web' -n cmmc2/index.html -o table

# Entry 200 + CSP header via Front Door / SWA
curl -sI https://cmmc.example.com/cmmc2/ | head -n1                 # HTTP/2 200
curl -sI https://cmmc.example.com/cmmc2/ | grep -i content-security-policy

# Parent assets resolve
for u in theme.css favicon.ico users.js roles.js script.js analytics.js siteSearch.js; do
  printf '%-14s ' "$u"; curl -so /dev/null -w '%{http_code}\n' "https://cmmc.example.com/$u"
done
```

Browser: no CSP violations; branding applies; theme persists; marking a control updates the
SPRS score; `.xlsx` export downloads.

## 8. Day-2 operations

| Task | Action |
|---|---|
| Deploy | `upload-batch` + Front Door purge (or SWA deploy) via CI on merge |
| Rollback | Restore prior blob versions (versioning/soft-delete) or redeploy previous commit |
| Cert rotation | SWA/Front Door managed certs auto-renew |
| Header/CSP change | Edit Front Door Rules Set / `staticwebapp.config.json`; redeploy config |
| Logs | Front Door access logs / Storage analytics → Log Analytics |
| DR | Blob versioning + git (see [`../docs/DISASTER_RECOVERY.md`](../docs/DISASTER_RECOVERY.md)) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| 404 on `/cmmc2/` | Uploaded from `cmmc2/` not repo root | Upload from **repo root** so blob paths include `cmmc2/` |
| Unstyled page | Parent assets missing at `$web` root | Ensure `theme.css`/`*.js`/`index.html` uploaded to root |
| Stale content | Front Door cache | `az afd endpoint purge` |
| CSP header absent | No Rules Set / config not applied | Add Front Door rule or `staticwebapp.config.json` |
| `az` hits wrong cloud | Cloud not set to Gov | `az cloud set --name AzureUSGovernment` |
| Upload auth denied | Missing RBAC / using keys | Grant **Storage Blob Data Contributor**; use `--auth-mode login` |
| Federated login fails in CI | `subject`/`issuer` mismatch | Fix the federated credential subject to repo/branch |

See also: [`AWS.md`](AWS.md) · [`AIRGAPPED.md`](AIRGAPPED.md) · [`../docs/SECURITY.md`](../docs/SECURITY.md).
