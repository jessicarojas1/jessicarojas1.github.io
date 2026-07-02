# Azure Deployment — Sentinel QMS (Commercial + Azure Government)

> **Audience:** cloud/platform engineers deploying Sentinel QMS to Microsoft
> Azure.
> **CUI notice:** deploy CUI/ITAR/EAR workloads **only** to **Azure Government**
> (sovereign cloud; endpoints `*.usgovcloudapi.net`, portal `portal.azure.us`) —
> **never** to Azure Commercial. The Commercial column exists for non-CUI demos,
> staging, and pipelines. Azure Gov operations are restricted to screened
> **U.S. persons**.

Aligns with [`infra/terraform/azure-gov/`](../infra/terraform/azure-gov/), the
shared Terraform modules, and the
[Azure Gov runbook](../docs/deployment/azure-gov-runbook.md). Kubernetes object
details are in [`KUBERNETES.md`](KUBERNETES.md).

Sibling guides: [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) ·
[`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`KUBERNETES.md`](KUBERNETES.md) ·
[`AWS.md`](AWS.md) · [`AIRGAPPED.md`](AIRGAPPED.md)

---

## 1. Deployment architecture

Container images (`backend` FastAPI :8000, `frontend` nginx SPA :8080, or the
single-service image) run on **AKS** (default, matches the Helm chart /
Kustomize `azure-gov` overlay) or **App Service / Container Apps**, fronted by
**Application Gateway + WAF** (or Azure Front Door). Managed data services:
**Azure Database for PostgreSQL 16 (Flexible Server)** (zone-redundant HA, CMK),
**Blob Storage** for uploads (CMK, versioning, private endpoint), **Key Vault**
for the DB DSN / JWT / OIDC secret and keys, and **Azure Monitor / Log Analytics
/ Microsoft Sentinel** for logs and SIEM. Identity is **Microsoft Entra ID**
(Gov), and pods/apps authenticate to Blob + Key Vault via **Managed Identity /
Workload Identity** — no connection-string secrets baked in.

Alembic migrations run as a **Job** (`AUTO_MIGRATE=0` on the service) or via the
entrypoint.

| Cloud | Region | Portal | Endpoints |
|-------|--------|--------|-----------|
| **Commercial** | e.g. `eastus` | `portal.azure.com` | `*.core.windows.net`, `*.vault.azure.net`, Entra `login.microsoftonline.com` |
| **Azure Government** | `usgovvirginia` / `usgovtexas` | `portal.azure.us` | `*.core.usgovcloudapi.net`, `*.vault.usgovcloudapi.net`, Entra Gov `login.microsoftonline.us` |

---

## 2. Topology

```
                    Azure DNS / Front Door
                          │  TLS 1.2+
                  ┌───────▼─────────┐   WAF (OWASP) + rate limit
                  │ App Gateway +   │   cert from Key Vault
                  │ WAF (AGIC)      │
                  └───────┬─────────┘
              /api/v1     │   /            app subnet
                  ┌───────▼───────────────────────────┐
                  │ AKS                                 │
                  │  backend pods (Workload Identity)   │
                  │  frontend pods                      │
                  └───┬──────────────┬──────────────┬───┘
   psycopg (5432,     │   Key Vault  │ Private       │ Private
   TLS verify-full)   │   CSI/ESO    │ Endpoint      │ Endpoint
                      ▼              ▼               ▼
        ┌────────────────────────┐ ┌───────────┐ ┌───────────────────────┐
        │ Azure DB for Postgres  │ │ Key Vault │ │ Blob Storage (CMK,     │
        │ 16 Flexible, ZR-HA, CMK│ │ db/jwt/oidc│ │ versioning, private)  │
        │ (data subnet, private) │ │  + keys   │ │ container: sentinel-qms│
        └────────────────────────┘ └───────────┘ └───────────────────────┘
        Azure Monitor / Log Analytics / Microsoft Sentinel / Defender
```

Storage, Key Vault, ACR, and PostgreSQL are reached over **Private Endpoints**;
the data subnet has no internet egress. Keep all data **in-region** (geo-disabled).

---

## 3. Prerequisites

| Item | Notes |
|------|-------|
| Azure subscription | Dedicated subscription/MG per env; single-tenant for ITAR. |
| Azure CLI | `az cloud set --name AzureUSGovernment`; `az login`. |
| Terraform | 1.6+ — `infra/terraform/azure-gov`. |
| kubectl / helm | for AKS. |
| ACR (Premium) | image registry; content trust + Defender scanning. |
| Entra ID (Gov) | app registration for OIDC SSO; Conditional Access + MFA. |
| U.S. persons | Azure Gov operations restricted to screened U.S. persons. |

---

## 4. Identity & credentials

**Prefer Managed Identity / Workload Identity over secrets.**

- **Humans:** Entra ID (Gov) RBAC; enforce MFA + Conditional Access.
- **AKS pods:** **Workload Identity** — annotate the `sentinel-backend`
  ServiceAccount with `azure.workload.identity/client-id: <mi-client-id>` (see
  [`KUBERNETES.md`](KUBERNETES.md) §4). The managed identity is granted:
  - **Storage Blob Data Contributor** on the uploads storage account/container,
  - **Key Vault Secrets User** on the vault.
- **App Service / Container Apps:** enable a **system-assigned managed identity**
  and grant the same roles.
- **Secrets** (`DATABASE_URL`, `JWT_SECRET`, `OIDC_CLIENT_SECRET`) come from
  **Key Vault** via the **Key Vault CSI driver** (`secret-provider-class.yaml`)
  or External Secrets — not from `secret.yaml` / values files.

> The app's Azure Blob backend currently authenticates with
> `AZURE_STORAGE_CONNECTION_STRING` (see `app/services/storage.py`). Store that
> connection string in Key Vault and inject it as an env var; rotate the storage
> account keys on a schedule. Where org policy forbids account keys, front Blob
> access with the managed identity and supply a short-lived credential via Key
> Vault rotation.

Key Vault keys (Premium/HSM, purge protection + soft delete): `rds-cmk`
(PostgreSQL CMK), `blob-cmk` (storage CMK), `jwt-signing` (asymmetric, prod).

---

## 5. Environment variables

| Variable | Example — **Commercial** | Example — **Azure Government** | Purpose |
|----------|--------------------------|--------------------------------|---------|
| `ENVIRONMENT` | `production` | `production` | Hardens (JWT guard, HSTS). |
| `LOG_LEVEL` | `INFO` | `INFO` | Log level → Log Analytics. |
| `DATABASE_URL` | `postgresql+psycopg://sentinel:***@<srv>.postgres.database.azure.com:5432/sentinel_qms?sslmode=verify-full` | `postgresql+psycopg://sentinel:***@<srv>.postgres.database.usgovcloudapi.net:5432/sentinel_qms?sslmode=verify-full` | DB DSN (from Key Vault). |
| `DB_SCHEMA` | `sentinel_qms` | `sentinel_qms` | Dedicated schema. |
| `JWT_SECRET` | *(Key Vault, ≥ 32 chars)* | *(Key Vault, ≥ 32 chars)* | Token signing. |
| `STORAGE_BACKEND` | `azure_blob` | `azure_blob` | Upload backend. |
| `AZURE_STORAGE_CONNECTION_STRING` | `...EndpointSuffix=core.windows.net` (Key Vault) | `...EndpointSuffix=core.usgovcloudapi.net` (Key Vault) | Blob access (note the **endpoint suffix differs**). |
| `AZURE_STORAGE_CONTAINER` | `sentinel-qms` | `sentinel-qms` | Blob container. |
| `CORS_ORIGINS` | `https://qms.example.com` | `https://qms.example.us` | Allowed origins. |
| `OIDC_ISSUER` | `https://login.microsoftonline.com/<tenant>/v2.0` | `https://login.microsoftonline.us/<tenant>/v2.0` | Entra issuer (**different host in Gov**). |
| `OIDC_CLIENT_ID` | *(app reg)* | *(app reg, Gov tenant)* | SSO client. |
| `OIDC_CLIENT_SECRET` | *(Key Vault)* | *(Key Vault)* | SSO secret. |
| `TRUST_PROXY_HEADERS` | `true` | `true` | Behind App Gateway. |
| `TRUSTED_PROXY_COUNT` | `1` | `1` | App Gateway hop count. |
| `APP_BASE_URL` | `https://qms.example.com` | `https://qms.example.us` | Deep links. |
| `AUTO_MIGRATE` | `0` (service) / `1` (Job) | same | Migrations via Job. |
| `AUTO_SEED` | `0` (prod) | `0` (prod) | Seed once. |
| `ADMIN_AUTO_CREATE` | `false` | `false` | No auto admin. |
| `WEB_CONCURRENCY` | `4` | `4` | gunicorn workers. |

> The **most common Gov mistakes**: forgetting the Blob `EndpointSuffix=
> core.usgovcloudapi.net`, and using the Commercial Entra host
> `login.microsoftonline.com` instead of `login.microsoftonline.us`.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` | 50 MB upload cap. |
| `REDIS_URL` | `redis://<azure-cache>:6379/0` | Cross-replica rate limiting (Azure Cache for Redis). |
| `ACCESS_TOKEN_EXPIRE_MINUTES` | `30` | Access-token TTL. |
| `OIDC_GROUP_ROLE_MAP` | `{"qms-admins":"Admin"}` | Map Entra groups → Sentinel roles. |
| `RUN_SCHEDULER` | `true` (one replica) | SLA sweep + report digest. |

Terraform: `cd infra/terraform/azure-gov && terraform init && terraform apply`
provisions the resource group, VNet (ingress/app/data subnets), Key Vault, AKS,
PostgreSQL Flexible Server, Blob, ACR, private endpoints, App Gateway + WAF, and
monitoring. App config comes from `values-azure-gov.yaml` (ConfigMap) + the
SecretProviderClass.

---

## 7. Verification

```bash
# 7.1 Health via App Gateway
curl -fsS https://qms.example.us/health                      # {"status":"ok"} 200

# 7.2 Secrets resolved + login (creds from Key Vault)
TOKEN=$(curl -fsS -X POST https://qms.example.us/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@your-org.gov","password":"<pw>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
# A token proves DATABASE_URL + JWT_SECRET resolved from Key Vault.

# 7.3 Upload accepted + scanned (magic-byte) + object written
printf '%%PDF-1.4\n%%EOF\n' > /tmp/t.pdf
curl -fsS -X POST https://qms.example.us/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/t.pdf;type=application/pdf'                  # 201, storage_backend=azure_blob
```

Confirm the DB rows (attachment + immutable audit trail) — from a backend pod:

```bash
kubectl -n sentinel-qms exec deploy/backend -- python -c \
"from app.core.database import SessionLocal; from sqlalchemy import text; s=SessionLocal(); \
print(s.execute(text('SELECT stored_key, storage_backend FROM attachments ORDER BY id DESC LIMIT 1')).fetchone()); \
print(s.execute(text(\"SELECT action, actor_email FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1\")).fetchone())"
```

Confirm the Blob object (Azure Gov):

```bash
az cloud set --name AzureUSGovernment
az storage blob list --account-name <acct> --container-name sentinel-qms \
  --auth-mode login --query '[-1].{name:name,size:properties.contentLength}' -o table
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Image build/push | `az acr login -n <acr>`; content trust + Defender scan; admit signed images only. |
| Deploy/upgrade | `helm upgrade --install ... -f values-azure-gov.yaml`; rolling update honors PDBs. |
| Migrations | Back up PostgreSQL, then run the migration **Job** (`alembic upgrade head`) before shifting traffic. |
| Scale | AKS HPA + cluster autoscaler; PostgreSQL: scale compute / add read replica; add `REDIS_URL` for shared rate limiting. |
| Backups | PostgreSQL automated backups (35-day, in-region, geo-disabled) + PITR; Blob versioning + soft delete. |
| Restore | PITR restore of the server; Blob recovered from versions/soft delete. See `docs/DISASTER_RECOVERY.md`. |
| Cert rotation | App Gateway pulls the cert from Key Vault; rotate there. |
| Secret rotation | Rotate in Key Vault; CSI/ESO re-syncs; `kubectl rollout restart deploy/backend`. Rotate storage account keys and update the connection-string secret. |
| Logs/alerts | Azure Monitor + Log Analytics (JSON logs); Microsoft Sentinel SIEM; Defender for Cloud. Alert on 5xx, p95 latency, DB CPU/connections, pod restarts, audit-pipeline failures. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Blob 403 / `AuthorizationFailure` | Wrong endpoint suffix or missing role | Ensure `EndpointSuffix=core.usgovcloudapi.net`; grant **Storage Blob Data Contributor** to the managed identity. |
| OIDC login fails in Gov | Commercial Entra host used | Use `login.microsoftonline.us` issuer + Gov tenant app registration. |
| Secrets not mounted | CSI SecretProviderClass / Workload Identity misconfig | Check `az aks show` OIDC issuer, federated credential, and `SecretProviderClass`. |
| PostgreSQL SSL/connect error | Private endpoint / TLS mode | Reach the server via private endpoint; use `sslmode=verify-full` with the Azure CA. |
| `refusing to start ... insecure default` | Weak `JWT_SECRET` | Store a ≥ 32-char secret in Key Vault. |
| App Gateway 502 | Readiness `/health` failing | Check DB reachability, backend pool health, probe port 8000. |
| Upload 400 "contents do not match" | Not a real allowed type | Server sniffs magic bytes — upload a genuine PDF/PNG/etc. |
| Feature/region unavailable | Gov parity gap | Validate Azure Gov service availability before design. |
