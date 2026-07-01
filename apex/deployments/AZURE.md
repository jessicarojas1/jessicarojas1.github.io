# APEX — Azure Deployment (Commercial + Azure Government)

Operator guide for running **APEX** on Azure, covering both **Azure Commercial**
and **Azure Government** (cloud `AzureUSGovernment`). APEX is a stateless PHP 8.2
+ Apache container (the shipped `apex/Dockerfile`) serving a vanilla-JS SPA and
`/api/*` REST API on port **8080** as non-root `www-data`, backed by PostgreSQL
16. Auth is CAC/PIV-simulated (bcrypt PINs + HS256 JWT).

Two supported shapes: **Azure App Service for Containers** (recommended,
simplest) or **AKS** (reuse [KUBERNETES.md](KUBERNETES.md) with Workload
Identity). Both use **Azure Database for PostgreSQL – Flexible Server**,
**Key Vault**, **Managed Identity**, and **Entra ID**.

Related: [KUBERNETES](KUBERNETES.md) · [AWS](AWS.md) ·
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) · [AIRGAPPED](AIRGAPPED.md)

---

## 1. Deployment architecture

| Azure service | Role |
|---------------|------|
| Azure Container Registry (ACR) | Stores the `apex` image. |
| App Service (Linux, container) — or AKS | Runs the stateless app on 8080. |
| Azure Database for PostgreSQL Flexible Server 16 | All persistent state; zone-redundant HA. |
| Key Vault | `JWT_SECRET` and the DB connection string. |
| Managed Identity (system- or user-assigned) | Pulls image + reads Key Vault — **no static secrets**. |
| Microsoft Entra ID | Operator/admin sign-in to Azure; optional DB Entra auth. |
| Log Analytics / App Insights | Container logs + telemetry. |

APEX has no file-upload feature, so **Blob Storage is not required**. If added
later, grant the Managed Identity a scoped `Storage Blob Data Contributor` role.

---

## 2. Topology

```
        Internet / Azure Gov boundary
                  │ :443 (App Service managed cert / AFD)
                  ▼
        ┌──────────────────────┐
        │ App Service (Linux)  │  container apex :8080 (www-data)
        │  system-assigned MI  │  WEBSITES_PORT=8080
        └──────────┬───────────┘
                   │ Key Vault reference  @Microsoft.KeyVault(...)
                   │ DATABASE_URL, JWT_SECRET
                   ▼
        ┌──────────────────────┐        ┌──────────────────────┐
        │  Key Vault           │        │ PostgreSQL Flexible   │
        │  (RBAC, MI access)   │        │  Server 16 (VNet/PE)  │
        └──────────────────────┘        │  require_secure=ON    │
                                        └──────────────────────┘
```

---

## 3. Prerequisites

| Item | Detail |
|------|--------|
| Azure subscription | Commercial **or** Azure Government |
| Azure CLI | `az` (set the right cloud — see §5) |
| ACR | For the `apex` image |
| Resource group + VNet | Private access to PostgreSQL (Private Endpoint / VNet integration) |
| Key Vault | With RBAC authorization enabled |
| Docker | Build/push |

Set the target cloud:

```bash
# Commercial
az cloud set --name AzureCloud
# Azure Government
az cloud set --name AzureUSGovernment
az login
```

---

## 4. Identity & credentials

**Use Managed Identity + Entra ID — no static secrets in config.**

- **App Service**: enable a **system-assigned Managed Identity**; grant it
  **Key Vault Secrets User** on the vault; use **Key Vault references** in app
  settings so secrets resolve at runtime without embedding values.
- **AKS**: **Microsoft Entra Workload Identity** federated to a user-assigned MI;
  read Key Vault via the Secrets Store CSI Driver (see [KUBERNETES.md](KUBERNETES.md)).
- **ACR pull**: grant the identity `AcrPull` (avoid admin credentials).

Least-privilege role assignments (Commercial IDs; Gov uses the same role names):

```bash
az role assignment create --assignee <mi-principal-id> \
  --role "Key Vault Secrets User" \
  --scope $(az keyvault show -n apex-kv --query id -o tsv)

az role assignment create --assignee <mi-principal-id> \
  --role "AcrPull" \
  --scope $(az acr show -n apexacr --query id -o tsv)
```

Optional: use **Entra authentication to PostgreSQL** (token-based) instead of a
password in `DATABASE_URL` for higher assurance.

---

## 5. Environment variables — Commercial vs Government

App env is identical; only **cloud, endpoints/domains, and regions** differ.

### App settings (both clouds)

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:***@apex-pg.postgres.database.azure.com:5432/apex?sslmode=require` | Flexible Server connection; **`sslmode=require`**. Key Vault reference. |
| `JWT_SECRET` | 32+ random chars | HS256 signing key. Key Vault reference. Fails closed if <32 in production. |
| `APP_ENV` | `production` | Secure cookie, no traces, fail-closed. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | Must be `0`. |
| `WEBSITES_PORT` | `8080` | Tell App Service which container port to route to. |

### Cloud/endpoint differences

| Concern | Azure Commercial | Azure Government |
|---------|------------------|------------------|
| CLI cloud | `az cloud set -n AzureCloud` | `az cloud set -n AzureUSGovernment` |
| Portal | `portal.azure.com` | `portal.azure.us` |
| Key Vault DNS | `*.vault.azure.net` | `*.vault.usgovcloudapi.net` |
| PostgreSQL DNS | `*.postgres.database.azure.com` | `*.postgres.database.usgovcloudapi.net` |
| ACR login server | `*.azurecr.io` | `*.azurecr.us` |
| Entra (login) | `login.microsoftonline.com` | `login.microsoftonline.us` |
| Storage (if added) | `*.blob.core.windows.net` | `*.blob.core.usgovcloudapi.net` |

Set `DATABASE_URL`/Key Vault references to the correct DNS suffix for the cloud.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `WEBSITES_PORT` | `8080` | Container listens here (non-privileged). |
| Health check path | `/api/health` | App Service Health Check + probes. |
| Key Vault reference | `@Microsoft.KeyVault(SecretUri=https://apex-kv.vault.azure.net/secrets/JWT-SECRET/)` | Runtime secret resolution via MI. |
| PostgreSQL `require_secure_transport` | `ON` | Enforce TLS; pair with `sslmode=require`. |
| Container registry | ACR (Commercial `.azurecr.io` / Gov `.azurecr.us`) | Image source. |

App setting example (App Service):

```bash
az webapp config appsettings set -g apex-rg -n apex-web --settings \
  WEBSITES_PORT=8080 APP_ENV=production APEX_ALLOW_DEFAULT_PINS=0 \
  JWT_SECRET='@Microsoft.KeyVault(SecretUri=https://apex-kv.vault.azure.net/secrets/JWT-SECRET/)' \
  DATABASE_URL='@Microsoft.KeyVault(SecretUri=https://apex-kv.vault.azure.net/secrets/DATABASE-URL/)'
```

---

## 7. Verification

```bash
HOST=apex-web.azurewebsites.net        # Gov: apex-web.azurewebsites.us

# Health
curl -s https://$HOST/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"..."}}

# Secrets resolved (Key Vault reference) + login (bcrypt verify)
TOKEN=$(curl -s -X POST https://$HOST/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] && echo "login OK — JWT_SECRET resolved from Key Vault"

# Confirm the Key Vault reference resolved (no '@Microsoft.KeyVault' left literal)
az webapp config appsettings list -g apex-rg -n apex-web \
  --query "[?name=='JWT_SECRET'].value" -o tsv

# Write a DB row (ticket) → API→PDO→Flexible Server
curl -s -X POST https://$HOST/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"azure smoke test","type":"task"}'

# Confirm persistence
psql "$DATABASE_URL" -c \
  "SELECT id,title FROM tickets ORDER BY created_at DESC LIMIT 1;"
```

Verify: `/api/health` 200 ✓ · login token (Key Vault secret resolved) ✓ ·
new `tickets` row in PostgreSQL ✓ · TLS enforced ✓.

---

## 8. Day-2 operations

| Task | Procedure |
|------|-----------|
| Deploy new version | Push to ACR → `az webapp config container set` / restart (or continuous deploy). App Service swaps the container. |
| Migrations | Fresh DB seeds via boot migrate. For an existing DB, run `php scripts/migrate.php` via SSH into the container, or apply new SQL with `psql`. |
| Scale | App Service scale-out (Premium plan autoscale) or AKS HPA. Stateless. |
| Backups | Flexible Server automated backups (geo-redundant option); set retention; enable PITR. |
| Secret rotation | Update Key Vault secret version; App Service auto-resolves the reference (restart to force). |
| Certs | App Service managed certificate or Azure Front Door. |
| Logs | App Service Log Stream + Log Analytics / Application Insights. |
| HA/DR | Zone-redundant Flexible Server + geo-restore; see `docs/DISASTER_RECOVERY.md`. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| App Service shows "Application Error" | Wrong port | Set `WEBSITES_PORT=8080` (image listens on 8080). |
| Startup log `JWT_SECRET is missing or too short` | Key Vault reference unresolved / <32 chars | Grant MI **Key Vault Secrets User**; verify SecretUri; value ≥32 chars. |
| App setting still shows `@Microsoft.KeyVault(...)` | MI has no vault access or vault RBAC off | Enable vault RBAC + assign role; check the reference syntax. |
| DB connection failed | PE/VNet not wired or `sslmode` mismatch | Verify Private Endpoint/VNet integration; `sslmode=require`; `require_secure_transport=ON`. |
| Wrong DNS in Gov | Using commercial suffixes | Use `*.usgovcloudapi.net` / `*.azurecr.us` / `login.microsoftonline.us`. |
| Login `Invalid credentials` | Real PINs required; defaults off | Use seed PIN; defaults disabled at `APP_ENV=production`. |
| ACR pull fails | Identity lacks `AcrPull` | Assign `AcrPull` to the MI; avoid admin creds. |
| Redirect loop | `X-Forwarded-Proto` not honored | App Service sets it; ensure HTTPS-only and app trusts the header. |
