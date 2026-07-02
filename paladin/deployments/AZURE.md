# PALADIN — Azure Deployment (Commercial + Azure Government)

Operator guide for deploying PALADIN on Microsoft Azure, covering both **Azure
Commercial** and **Azure Government** (US Gov). Two hosting shapes are documented:
**Azure Container Apps / App Service** (simplest) and **AKS** (see also
[KUBERNETES.md](KUBERNETES.md)). Managed services: **Azure Database for
PostgreSQL Flexible Server**, **Blob Storage**, **Key Vault**, **Entra ID**,
**Managed Identity**.

Related: [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) · [../docs/SECURITY.md](../docs/SECURITY.md)

---

## 1. Deployment architecture

- **Compute**: PALADIN container image (`php:8.3-apache`) on **Azure Container
  Apps** (or App Service for Containers / AKS). `startup.sh` binds Apache to the
  platform-provided `$PORT`, runs `install.php` migrations, then serves.
- **Database**: **Azure Database for PostgreSQL Flexible Server** 16, private
  access (VNet-integrated), TLS required.
- **Storage**: PALADIN's S3-compatible driver targets **Blob Storage** via an
  S3 gateway/MinIO-in-front, **or** run the `local` driver on an Azure Files
  mount for single-instance. (Native Blob SDK is not built in; the storage layer
  speaks S3 SigV4 — front Blob with an S3-compatible endpoint, or use a MinIO
  gateway, or use Azure Files for `local`.)
- **Secrets**: **Key Vault** + **Managed Identity** (no static keys). App reads
  `JWT_SECRET`, DB connection, admin creds as Key Vault references.
- **Identity/SSO**: **Entra ID** via PALADIN's built-in **SAML** (`/saml/*`) or
  **OIDC** (`/oidc/*`); **SCIM** provisioning at `/scim/`.
- **Jobs**: **Container Apps Jobs** (or App Service WebJobs / AKS CronJob) run
  `cli/send_digests.php` and `cli/send_review_reminders.php`.

## 2. Topology

```
   Entra ID ──SAML/OIDC──►  PALADIN /saml/* /oidc/*        SCIM ► /scim/
        │
   Front Door / App Gateway (TLS, WAF)
        │  :443
   ┌────▼─────────────────────┐
   │ Container App: paladin    │  Managed Identity
   │ Apache on $PORT           │──Key Vault ref──► JWT_SECRET, DB creds
   └───┬───────────────┬───────┘
       │ TLS PDO        │ S3 SigV4 / Azure Files
   ┌───▼──────────┐ ┌───▼───────────────┐
   │ PostgreSQL   │ │ Blob (S3 gateway) │
   │ Flexible Svr │ │  or Azure Files   │
   └──────────────┘ └───────────────────┘
   Container Apps Jobs: digests, review reminders (cron)
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Azure CLI | 2.55+ (`az cloud set --name AzureUSGovernment` for gov) |
| Subscription | Contributor + User Access Administrator on target RG |
| Providers | `Microsoft.App`, `Microsoft.DBforPostgreSQL`, `Microsoft.Storage`, `Microsoft.KeyVault` |
| Registry | Azure Container Registry (ACR) with the `paladin` image pushed |
| VNet | For private PostgreSQL / Container Apps environment |
| Entra ID | App registration (SAML/OIDC) if using SSO |

## 4. Identity & credentials

**Use a user-assigned Managed Identity** — no static secrets in app config.

```bash
az identity create -g paladin-rg -n paladin-mi
# Grant Key Vault secret read
az role assignment create --assignee <mi-principal-id> \
  --role "Key Vault Secrets User" --scope <kv-resource-id>
# Grant Blob access (if using Blob directly / gateway auth)
az role assignment create --assignee <mi-principal-id> \
  --role "Storage Blob Data Contributor" --scope <storage-resource-id>
```

Store app secrets in Key Vault and reference them from the Container App:

```bash
az keyvault secret set --vault-name paladin-kv -n JWT-SECRET \
  --value "$(php -r 'echo bin2hex(random_bytes(32));')"
az keyvault secret set --vault-name paladin-kv -n DATABASE-URL \
  --value "postgres://paladin:pw@pg.postgres.database.azure.com:5432/paladin?sslmode=require"
```

SMTP/S3 secrets entered in **Admin → Settings** are additionally
**AES-256-GCM encrypted at rest** by PALADIN.

## 5. Environment variables

| Variable | Commercial example | Azure Government example | Purpose |
|---|---|---|---|
| `APP_URL` | `https://paladin.azurewebsites.net` | `https://paladin.azurewebsites.us` | Base URL (note `.us` gov suffix) |
| `JWT_SECRET` | *(KV ref)* | *(KV ref)* | Token signing (**required**) |
| `DATABASE_URL` | `…@x.postgres.database.azure.com…` | `…@x.postgres.database.usgovcloudapi.net…` | Gov DB endpoint suffix differs |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(KV ref)* | *(KV ref)* | First-run admin |
| `APP_ENV` | `production` | `production` | Prod behavior |
| `STORAGE_DRIVER` | `s3` (Blob gateway) or `local` | same | Object vs mounted |
| `S3_ENDPOINT`* | `https://<gw-host>` | `https://<gw-host>` | S3 gateway URL fronting Blob |
| `S3_REGION`* | `usgovarizona`/`eastus` | gov region | Region label for SigV4 |
| `TRUSTED_PROXY_IPS` | Front Door/App GW CIDR | same | Trust `X-Forwarded-Proto` |
| `MAIL_TRANSPORT` | `smtp` | `smtp` | Delivery vs outbox |
| `PORT` | *(platform sets)* | *(platform sets)* | Apache binds to it via `startup.sh` |

\* S3_* are typically set in **Admin → Settings** (encrypted), not env; env values
seed defaults only.

**Government cloud notes**: set `az cloud set --name AzureUSGovernment`; endpoint
suffixes: PostgreSQL `*.postgres.database.usgovcloudapi.net`, Blob
`*.blob.core.usgovcloudapi.gov`... wait — Blob gov suffix is
`*.blob.core.usgovcloudapi.net`; App Service `*.azurewebsites.us`; Entra login
`https://login.microsoftonline.us`. Ensure SAML/OIDC issuer/metadata URLs use the
`.us` login host.

## 6. Configuration references

| Setting (location) | Example | Purpose |
|---|---|---|
| Container App ingress `targetPort` | `80` | Matches Apache/`$PORT` |
| Container App min/max replicas | `1` / `4` | Requires `s3`/Blob storage if >1 |
| PostgreSQL `require_secure_transport` | `ON` | Enforce TLS; PALADIN DSN honors it |
| SAML metadata (Admin → SAML) | Entra federation metadata URL | SSO config; import via `/admin/saml/import` |
| OIDC issuer (Admin → OIDC) | `https://login.microsoftonline.us/<tenant>/v2.0` | Gov login host |
| SCIM base URL | `https://<app>/scim/v2` | Entra provisioning target |

## 7. Verification

```bash
APP=https://paladin.azurewebsites.us      # or .net commercial

curl -fsS $APP/health      # {"status":"healthy","checks":{"database":"ok"}}
curl -fsS $APP/healthz     # {"status":"ok"}

# Secrets resolved (Key Vault refs → install ran)
az containerapp logs show -n paladin -g paladin-rg | grep "Installation complete"

# SSO login: browse $APP/login → "Sign in with SSO" → Entra → returns authenticated
# SCIM reachable (expects auth token):
curl -si $APP/scim/v2/Users | head -1

# DB rows (via psql through a jump host / az postgres connect)
psql "host=pg.postgres.database.usgovcloudapi.net dbname=paladin user=paladin sslmode=require" -c \
  "SET search_path TO paladin; SELECT email FROM users WHERE role='admin';"

# Upload accepted + object written: attach a file in UI, then
psql "$PGCONN" -c "SET search_path TO paladin; SELECT original_name, stored_path FROM attachments ORDER BY id DESC LIMIT 1;"
# Blob/S3-gateway object present:
az storage blob list --account-name paladinstore -c uploads --prefix attachments/ --auth-mode login -o table | tail

# Audit chained
psql "$PGCONN" -c "SET search_path TO paladin; SELECT action, log_hash IS NOT NULL AS chained FROM activity_log ORDER BY id DESC LIMIT 1;"
```

## 8. Day-2 operations

| Task | How |
|---|---|
| Deploy new version | `az containerapp update -n paladin -g paladin-rg --image <acr>/paladin:<tag>` — new revision runs migrations |
| Migrations | Automatic on revision start (idempotent, tracked) |
| Scale | Container Apps scale rules; set `STORAGE_DRIVER=s3` before >1 replica |
| Rotate `JWT_SECRET` | New KV secret version → restart revision; invalidates sessions/tokens |
| Rotate DB creds | Update KV `DATABASE-URL` version → restart |
| DB backups | Flexible Server automated backups (PITR); geo-redundant in Commercial, regional in Gov |
| Blob durability | Enable versioning + soft delete on the storage account |
| Logs/metrics | Log Analytics workspace; Container App `logs`/`console` streams |
| Jobs | Manage cron via Container Apps Jobs schedules |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| App 500 at boot, logs show DB timeout | PostgreSQL firewall/VNet | Allow Container Apps subnet; `sslmode=require` in URL |
| Entra SSO `AADSTS` / metadata error | Wrong cloud login host | Use `login.microsoftonline.us` for gov; re-import SAML metadata |
| Key Vault ref unresolved | MI lacks `Key Vault Secrets User` | Assign role; confirm MI attached to app |
| Uploads not shared across replicas | `local` driver + scale-out | Use `s3`/Blob gateway or pin to 1 replica |
| 413 upload | Front Door/App GW body limit | Raise request body limit to ≥40 MB |
| SCIM 401 | Missing/invalid bearer token | Set the SCIM token in Admin → SCIM to match Entra |
| Gov endpoints unreachable | CLI on commercial cloud | `az cloud set --name AzureUSGovernment` |
