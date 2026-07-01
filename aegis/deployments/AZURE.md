# AEGIS GRC — Azure Deployment (Commercial + Azure Government)

Audience: operators deploying AEGIS to **Azure Commercial** (`AzureCloud`) or **Azure
Government** (`AzureUSGovernment`) using **Azure Container Apps** (or **AKS**) +
**Azure Database for PostgreSQL Flexible Server 16** + **Blob Storage** + **Key Vault**
+ **Managed Identity** + **Entra ID**. This is the operator summary; the exhaustive
Azure Government CLI runbook is **[`../deploy/deploy-azure-government.md`](../deploy/deploy-azure-government.md)**
and is authoritative for exact commands.

> Sibling guides: [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AIRGAPPED.md](AIRGAPPED.md)

---

## 1. Deployment architecture

AEGIS is one container (PHP 8.3 / Apache on **:8080**; health `/healthz`+`/readyz`).

| Azure resource | AEGIS role |
|----------------|------------|
| Container Apps env (internal) / AKS | runs `aegis-app` (2–10 replicas) |
| Container App `aegis-cron` / AKS worker | scheduled scripts loop |
| **PostgreSQL Flexible Server 16** (Zone-redundant HA, private) | primary datastore |
| **Blob Storage** (private endpoint, S3-compatible API) | evidence/upload storage (`s3` driver) |
| **Key Vault** (Premium/HSM, private) | all secrets + TLS cert |
| **Managed Identity** (system-assigned) | passwordless pull from ACR + Key Vault + Blob |
| **Application Gateway WAF v2** | HTTPS ingress, OWASP WAF, TLS termination |
| **ACR** (Premium, private) | image registry |
| **Log Analytics / Azure Monitor** | logs, alerts |

## 2. Topology

```
Internet ─HTTPS─► App Gateway WAF v2 (public IP, OWASP 3.2 Prevention, TLS from Key Vault)
                        │ internal HTTP → /healthz probe
        ┌───────────────▼──────────────────────────────────────┐  VNet, private endpoints
        │  Container Apps env (internal)  or  AKS               │
        │   aegis-app (2–10)   aegis-cron (1, no ingress)       │
        └───────┬───────────────────────┬──────────────────────┘
                │ 5432 (private)         │ blob (private)     Key Vault (private)
    ┌───────────▼─────────┐   ┌──────────▼─────────┐   ┌─────────────────────┐
    │ PostgreSQL Flexible │   │ Blob Storage        │   │ Key Vault (secrets, │
    │ Server 16 (HA, CMK) │   │ (S3-compat, ZRS)    │   │ TLS cert, HSM keys) │
    └─────────────────────┘   └────────────────────┘   └─────────────────────┘
 Identity: system-assigned Managed Identity → AcrPull, Key Vault Secrets User,
           Storage Blob Data Contributor (via Entra RBAC).
```

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Azure CLI | ≥ 2.55 | `az cloud set --name AzureUSGovernment` for gov |
| CLI extensions | `containerapp`, `application-gateway-waf-policy`, `log-analytics` | `az extension add` |
| Docker | ≥ 24 | local build (or use `az acr build`) |
| psql | ≥ 16 | migration validation |

Accounts: a Commercial subscription **or** an Azure Government subscription (portal
**portal.azure.us**, US gov entities only). Confirm you are in the right cloud
(`az cloud show --query name`).

## 4. Identity & credentials (Managed Identity + Entra ID — no static secrets)

Prefer **system-assigned Managed Identity** federated to Entra ID for all
service-to-service auth:

- **AcrPull** on the registry → pull images without a registry password.
- **Key Vault Secrets User** on the vault → resolve secret references at runtime.
- **Storage Blob Data Contributor** on the storage account → write uploads.
- End-user SSO to AEGIS via **Entra ID (OIDC/SAML)** using `src/SSO.php`.

Container Apps injects Key Vault secrets by reference bound to the identity:

```bash
--secrets "jwt-secret=keyvaultref:${KV_URI}secrets/JWT-SECRET,identityref:system" \
--env-vars "JWT_SECRET=secretref:jwt-secret"
```

On **AKS**, use **Entra Workload Identity**: federate the pod ServiceAccount to a
user-assigned managed identity and mount Key Vault secrets via the **Secrets Store CSI
driver** into the `*_FILE` paths AEGIS reads.

> **Government endpoint note:** Azure Government uses distinct FQDNs — ARM
> `management.usgovcloudapi.net`, ACR `*.azurecr.us`, Blob
> `*.blob.core.usgovcloudapi.net`, Key Vault `*.vault.usgovcloudapi.net`, Entra
> `login.microsoftonline.us`, PostgreSQL `*.postgres.database.usgovcloudapi.net`.
> **Azure Front Door is not available in Government** — use Application Gateway WAF v2.

## 5. Environment variables — Commercial vs Government

Secrets via Key Vault references; non-secrets as plain env.

| Variable | Commercial example | Government example | Purpose |
|----------|--------------------|--------------------|---------|
| `APP_ENV` | `production` | `production` | prod hardening + HSTS |
| `APP_URL` | `https://grc.example.com` | `https://grc.agency.gov` | canonical URL |
| `DB_HOST` | `aegis-pg.postgres.database.azure.com` | `aegis-pg.postgres.database.usgovcloudapi.net` | PG Flexible Server |
| `DB_PORT`/`DB_NAME`/`DB_USER` | `5432`/`aegis`/`aegis` | same | connection |
| `DB_PASS` (KV ref) | `secretref:db-pass` | `secretref:db-pass` | DB password |
| `JWT_SECRET` (KV ref) | `secretref:jwt-secret` | same | auth token signing |
| `AUDIT_HMAC_KEY` (KV ref) | `secretref:audit-hmac` | same | audit hash chain |
| `APP_ENCRYPTION_KEY` (KV ref) | `secretref:app-enc-key` | same | settings encryption at rest |
| `ADMIN_EMAIL`/`ADMIN_PASSWORD` | migration job only | migration job only | first admin seed |
| `SMTP_HOST` | `smtp.example.com` / ACS | `smtp.agency.gov` | mail relay |
| `SMTP_PORT`/`SMTP_USER`/`SMTP_PASS`/`SMTP_FROM` | 587 / creds | same | mail auth |
| `TRUSTED_PROXY_IPS` | App Gateway subnet | same | trust `X-Forwarded-*` |
| `SESSION_DRIVER` | `pg` | `pg` | shared sessions for >1 replica |

**Blob storage is configured in the app `settings` table (Admin → Storage), not env.**
AEGIS's S3 client talks to Blob's **S3-compatible API** in path-style mode:

| Setting key | Commercial | Government |
|-------------|-----------|-----------|
| `storage_driver` | `s3` | `s3` |
| `s3_endpoint` | `https://<acct>.blob.core.windows.net` | `https://<acct>.blob.core.usgovcloudapi.net` |
| `s3_bucket` | `aegis-uploads` (container name) | `aegis-uploads` |
| `s3_region` | e.g. `eastus` | e.g. `usgovarizona` |
| `s3_access_key`/`s3_secret_key` | storage account name / key (from Key Vault) | same |

> Path-style S3 against Blob covers the common PUT/GET/DELETE/presign operations AEGIS
> uses. If a specific operation misbehaves, front Blob with a MinIO gateway sidecar and
> point `s3_endpoint` at it. `src/Storage.php` blocks loopback/metadata endpoints
> (SSRF guard) but allows private Blob endpoints.

## 6. Configuration references

| Setting | Where | Purpose |
|---------|-------|---------|
| App Gateway health probe | `/healthz` | backend health |
| PG `require_secure_transport=on` | server parameter | TLS to DB |
| Blob `min-tls-version TLS1_2`, `allow-blob-public-access false`, `default-action Deny` | storage account | data protection |
| Key Vault Premium + purge protection + soft delete | vault | FIPS-backed keys |
| WAF OWASP 3.2 Prevention + rate limit | WAF policy | perimeter |
| Session/upload/rate-limit tuning | `config/app.php`, `.htaccess` (55M) | app behavior |
| Branding | `settings` table (Admin → Branding) | per-org logo/name/accent |

## 7. Deploy & migrate

1. **Build/push:** `az acr build --registry $ACR --image aegis:$(git rev-parse --short HEAD) --file Dockerfile .`
2. **Provision** PG Flexible Server, Blob, Key Vault, ACR, App Gateway (CLI in
   `../deploy/deploy-azure-government.md`; for AKS use `deploy/k8s/aegis.yaml`).
3. **Migrate** via a Container Apps **Job** running `php install.php` with
   `ADMIN_EMAIL`/`ADMIN_PASSWORD` and the DB owner role (idempotent: schema + all
   migrations + admin seed). Verify with `php scripts/verify_migrations.php`.
4. **Deploy** `aegis-app` (ingress external, target-port 80/8080, `/healthz` probe) and
   `aegis-cron` (ingress disabled, the scheduled-script loop).

## 8. Verification

```bash
B=https://grc.example.com   # or https://grc.agency.gov (gov)
# 1. Liveness / readiness through App Gateway
curl -fsS $B/healthz          # {"status":"ok",...}
curl -fsS $B/readyz           # {"status":"ready","checks":{"database":"ok"}}
# 2. Secrets resolved — exec into a replica, verify audit key + chain
az containerapp exec --name aegis-app --resource-group $RG \
  --command "php /var/www/html/scripts/verify_audit_log.php"      # exit 0
# 3. Login (CSRF-protected form)
JAR=$(mktemp); CSRF=$(curl -sc "$JAR" $B/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sb "$JAR" -i -X POST $B/login --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=$ADMIN_PASSWORD" | head -n1  # 302
# 4. Upload accepted + indexed (evidence_files row) — psql from Cloud Shell in-VNet
psql "host=$DB_HOST dbname=aegis user=aegis sslmode=require" -c \
  "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash FROM evidence_files ORDER BY id DESC LIMIT 1;"
# 5. Object written to Blob
az storage blob list --account-name $STORAGE_ACCOUNT --container-name aegis-uploads \
  --prefix uploads/evidence/ --auth-mode login --query "[-1].name"   # newest object
```

## 9. Day-2 operations

- **Upgrade:** push a new image tag; `az containerapp update --image …` creates a new
  revision with traffic shifting/rollback. Run the migration Job first. (AKS: migration
  Job → `set image`.)
- **Scaling:** Container Apps scales on HTTP concurrency (2–10); AKS via HPA. Requires
  `SESSION_DRIVER=pg` + Blob storage driver (stateless replicas).
- **Backups:** PG Flexible Server geo-redundant backups (35-day) + on-demand; Blob
  soft-delete + versioning + lifecycle tiering; Key Vault holds the crypto keys — losing
  `APP_ENCRYPTION_KEY` makes encrypted settings unrecoverable.
- **Secret/cert rotation:** update the Key Vault secret/cert; new Container Apps
  revisions pick it up (App Gateway auto-syncs the KV cert). Rotating `AUDIT_HMAC_KEY`
  invalidates verification of pre-rotation audit rows — retain old keys.
- **Logs/observability:** Log Analytics (ContainerAppConsoleLogs), Azure Monitor alerts
  on CPU, App Gateway 5xx, Key Vault access failures, PG connection errors; run
  `verify_audit_log.php` on a schedule.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| App Gateway backend unhealthy | probe path wrong | set probe to `/healthz` |
| Secret refs empty | identity lacks Key Vault Secrets User / KV private DNS unresolved | grant RBAC; verify private endpoint + DNS zone |
| ACR pull fails | identity lacks AcrPull / ACR public access off without private endpoint | grant AcrPull; add ACR private endpoint |
| Blob writes 403 | identity lacks Storage Blob Data Contributor / wrong endpoint | grant role; use `*.blob.core.usgovcloudapi.net` in gov |
| DB connect fails | `require_secure_transport` + no `sslmode`, or NSG blocks 5432 | `sslmode=require`; allow app-subnet→db-subnet:5432 |
| Random logouts | file sessions across replicas | `SESSION_DRIVER=pg` |
| Front Door not found (gov) | Front Door unavailable in Government | use Application Gateway WAF v2 |
| Wrong cloud endpoints | CLI still on `AzureCloud` | `az cloud set --name AzureUSGovernment` |
| Migration Job DDL denied | ran as non-owner role | use the DB owner for `install.php` |
