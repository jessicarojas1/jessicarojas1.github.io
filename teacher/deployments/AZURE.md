# Teacher Hub — Azure (Commercial + Azure Government)

**Target:** host Teacher Hub as a static site on **Azure Static Web Apps** (or
**Blob Storage `$web` static website + Azure Front Door**), deployed by a pipeline
authenticating with **managed identity / OIDC federated credentials** — no app
secrets.

> **Applicability:** Fully applicable. Covers **Azure Commercial** and **Azure
> Government** (`*.usgovcloudapi.net`, `usgovvirginia`/`usgovtexas` regions).
> Azure Government is documented for districts that require it; Commercial is the
> realistic default for a K-12 classroom tool.

Related: [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) ·
[../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

Two supported models:

1. **Azure Static Web Apps (SWA)** — simplest: point SWA at the repo, set the app
   artifact location, get global CDN + free managed TLS. No API/functions needed
   (the site has no backend). Set `app_location` so the `teacher/` hub and the
   parent `theme.css`/`favicon.ico` are both published (publish the repo root and
   route `/` → `/teacher/index.html`).
2. **Blob `$web` + Front Door** — upload files to the `$web` container of a
   storage account with static website enabled; front with Azure Front Door for
   TLS, custom domain, caching, and a **rules engine that injects the security
   headers + CSP the HTML lacks.**

No compute, DB, login, server upload, or object write at runtime. State is browser
`localStorage`.

## 2. Topology

```
  GitHub Actions ──(OIDC federated cred)──► Entra workload identity (deploy)
       │  swa deploy   |   az storage blob upload-batch $web
       ▼
   ┌─────────────────────┐        ┌──────────────────────────┐
   │ Static Web Apps CDN │   OR   │ Storage $web + Front Door │◄─ custom domain
   │ (managed TLS)       │        │ (TLS + Rules: CSP/HSTS)   │
   └──────────┬──────────┘        └────────────┬─────────────┘
              │ 443                             │ 443
              ▼                                 ▼
                      Teacher/Student browser
                       ├─ Bootstrap/Icons ← jsDelivr (or vendor)
                       └─ localStorage (all app data, per device)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Azure subscription | Commercial **or** Azure Government |
| Azure CLI | `az version` (use `az cloud set --name AzureUSGovernment` for gov) |
| Entra ID app registration | with a **federated credential** for GitHub OIDC |
| Custom domain (optional) | + DNS you control |
| SWA or Storage account | Standard_LRS is fine for `$web` |

## 4. Identity & credentials

**Deploy-pipeline identity only** — the site has no Azure identity or secrets. Use
**OIDC federated credentials** on an Entra app / user-assigned managed identity;
**no client secrets**.

```bash
# Federated credential binding GitHub main branch to the Entra app (no secret)
az ad app federated-credential create --id <APP_OBJECT_ID> --parameters '{
  "name": "teacherhub-main",
  "issuer": "https://token.actions.githubusercontent.com",
  "subject": "repo:jessicarojas1/jessicarojas1.github.io:ref:refs/heads/main",
  "audiences": ["api://AzureADTokenExchange"]
}'
```

Grant the identity **least privilege** — scope an RBAC role to just the target
resource:

| Model | Role | Scope |
|-------|------|-------|
| SWA | `Static Web Apps Contributor` (or deploy token) | the SWA resource |
| Blob `$web` | `Storage Blob Data Contributor` | the storage account / `$web` container |
| Front Door purge | `CDN Endpoint Contributor` / Front Door purge role | the AFD profile |

## 5. Environment variables

The app reads none. Pipeline variables (Commercial vs Government differ by
endpoint/region):

| Variable | Example (Commercial) | Example (Azure Government) | Purpose |
|----------|----------------------|---------------------------|---------|
| `AZURE_CLIENT_ID` | `<app/mi client id>` | same | OIDC login identity |
| `AZURE_TENANT_ID` | `<tenant guid>` | same | Entra tenant |
| `AZURE_SUBSCRIPTION_ID` | `<sub guid>` | same | target subscription |
| `AZURE_CLOUD` | `AzureCloud` | `AzureUSGovernment` | `az cloud set` |
| `STORAGE_ACCOUNT` | `teacherhubprod` | `teacherhubgov` | `$web` host |
| Blob endpoint | `*.blob.core.windows.net` | `*.blob.core.usgovcloudapi.net` | storage endpoint |
| Front Door host | `*.azurefd.net` | Gov AFD endpoint | CDN endpoint |

## 6. Configuration references

For **Static Web Apps**, `staticwebapp.config.json` (route apex to the hub; note
SWA also lets you set global response headers):

```json
{
  "navigationFallback": { "rewrite": "/teacher/index.html" },
  "routes": [{ "route": "/", "redirect": "/teacher/", "statusCode": 302 }],
  "globalHeaders": {
    "Content-Security-Policy": "default-src 'self'; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; script-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; object-src 'none'",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "no-referrer",
    "Strict-Transport-Security": "max-age=31536000; includeSubDomains"
  }
}
```

For **Blob `$web`**, headers come from the **Front Door rules engine** (Blob static
website itself can't set arbitrary response headers). CSP keeps `'unsafe-inline'`
until inline handlers are externalized (see [../OPEN_ITEMS.md](../OPEN_ITEMS.md)).

Example blob upload (Government endpoint suffix shown as a comment):

```bash
az cloud set --name AzureUSGovernment   # omit for Commercial
az storage blob upload-batch \
  --account-name $STORAGE_ACCOUNT --auth-mode login \
  -d '$web' -s . --pattern '*' \
  # Commercial endpoint: <acct>.blob.core.windows.net
  # Government endpoint: <acct>.blob.core.usgovcloudapi.net
```

## 7. Verification

No health/login/secret/upload/DB — verify delivery, headers, and client behavior:

```bash
# SWA / Front Door entry page 200
curl -sSI https://teacherhub.school.k12.us/teacher/ | head -1              # 200
# CSP / HSTS present
curl -sSI https://teacherhub.school.k12.us/teacher/ | grep -iE 'content-security-policy|strict-transport'
# Parent-relative asset resolves
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.school.k12.us/theme.css   # 200
# Blob object exists (if using $web)
az storage blob show --account-name $STORAGE_ACCOUNT --auth-mode login -c '$web' -n teacher/index.html -o table
```

Then browser: theme persist, 10 tabs, save plan + gradebook entry survive reload,
**CSV download**, template print, branding applies
([LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) §7).

## 8. Day-2 operations

| Task | How |
|------|-----|
| Release | `swa deploy` or `az storage blob upload-batch`, then purge Front Door |
| Purge cache | `az afd endpoint purge …` after each deploy |
| Rollback | redeploy a previous git tag; enable blob versioning/soft-delete |
| Rotate credentials | none — OIDC federated login is secretless |
| Cert rotation | SWA/Front Door managed certs auto-renew |
| Logs | SWA/AFD access logs → Log Analytics if required |
| Backups | git is source of truth; enable blob soft-delete/versioning ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| OIDC login fails | federated `subject` mismatch | match `repo:…:ref:refs/heads/main` exactly; audience `api://AzureADTokenExchange` |
| 404 `/theme.css` | root files not published | publish repo-root layout; set `navigationFallback`/routes to the hub |
| Headers missing on `$web` | Blob can't set headers | inject via Front Door rules engine |
| Wrong cloud endpoints | Gov vs Commercial mix-up | `az cloud set --name AzureUSGovernment`; use `*.usgovcloudapi.net` |
| Stale content | Front Door caching | purge endpoint after deploy; short cache on HTML |
| No icons/styles | jsDelivr blocked | allowlist `cdn.jsdelivr.net` or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
