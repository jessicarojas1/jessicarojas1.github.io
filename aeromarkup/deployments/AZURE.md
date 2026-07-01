# AeroMarkup on Azure (Commercial + Azure Government)

Operator guide for deploying **AeroMarkup** to Azure. AeroMarkup is a stateless
Flask application (`server.py`) served by gunicorn on port **8080**, packaged as a
non-root (`uid 10001`) `python:3.12-slim` container that exposes `GET /api/health`.
**All state lives in PostgreSQL** — reference images and STL/OBJ 3D models are stored
as `data:` URLs in Postgres columns (`aeromarkup.drawings.background_data`,
`aeromarkup.drawings.model_data`, `aeromarkup.attachments.data`); there is **no blob /
object storage dependency**. The app owns a dedicated `aeromarkup` schema
(`search_path=aeromarkup,public`, safe to share a database) and, when `AUTO_MIGRATE=1`,
applies `db/schema.sql` at boot.

This guide covers **two deployment models**:

1. **Azure Container Apps** (primary) — matches the repo assets
   [`deploy/azure-gov/deploy.sh`](../deploy/azure-gov/deploy.sh),
   [`deploy/azure-gov/containerapp.bicep`](../deploy/azure-gov/containerapp.bicep),
   [`deploy/azure-gov/appgateway.bicep`](../deploy/azure-gov/appgateway.bicep).
2. **AKS** (alternative) — see [KUBERNETES.md](./KUBERNETES.md).

Both clouds — **Azure Commercial** (`AzureCloud`) and **Azure Government**
(`AzureUSGovernment`) — are covered side by side. See also
[../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md),
[../docs/SECURITY.md](../docs/SECURITY.md), and
[AIRGAPPED.md](./AIRGAPPED.md) for a fully offline installation.

---

## 1. Deployment architecture

| Concern | Choice |
| --- | --- |
| Compute | Azure Container Apps (single container, image from ACR). AKS as alternative. |
| Ingress / TLS | **Default posture** ([`deploy.sh`](../deploy/azure-gov/deploy.sh) `FRONT_WITH_APPGW=true`): **Application Gateway v2 + WAF_v2** in front of an internal, VNet-integrated Container App, HTTPS only. Set `FRONT_WITH_APPGW=false` to expose the Container Apps managed ingress directly (still TLS). |
| Proxy awareness | One proxy hop (App Gateway **or** Container Apps ingress) → set `TRUSTED_PROXY_HOPS=1`. |
| Database | Azure Database for PostgreSQL **Flexible Server**. AeroMarkup uses the `aeromarkup` schema only; a shared server is safe. |
| Secrets | Azure **Key Vault** holds `DATABASE_URL` and `AEROMARKUP_SECRET`, surfaced as Container App secrets (Key Vault reference) — or via the Secrets Store CSI driver on AKS. |
| Identity | **User-assigned Managed Identity** + Microsoft Entra ID: the app pulls from ACR and reads Key Vault with the managed identity — **no static keys/passwords in the image or Bicep**. |
| Encryption | Key Vault (HSM-backed keys optional) + Flexible Server encryption at rest; TLS in transit (`sslmode=require`). |
| Migrations | `AUTO_MIGRATE=1` applies `db/schema.sql` idempotently at boot. |

The container is stateless; scale to N replicas with no sticky sessions
(`am_session` is a signed cookie validated against Postgres).

---

## 2. Topology

```
                          Internet / VNet clients
                                   │  HTTPS (443)
                                   ▼
                 ┌──────────────────────────────────┐
                 │  Application Gateway v2 + WAF_v2  │  TLS (Key Vault cert)
                 │  (FRONT_WITH_APPGW=true, default) │  probe → /api/health
                 └────────────────┬──────────────────┘
                                  │  (VNet-internal)
                 ┌────────────────▼──────────────────┐
                 │   Azure Container App              │  replicas ≥ 1
                 │   gunicorn server:app :8080        │  TRUSTED_PROXY_HOPS=1
                 │   image ← ACR                      │
                 │   user-assigned Managed Identity   │
                 └───┬───────────────────┬────────────┘
   Key Vault ref /   │                   │  stdout/stderr
   managed identity  │                   ▼
                     │            Log Analytics (Container Apps env)
                     ▼
     ┌───────────────────────────┐        ┌───────────────────────────────┐
     │ Azure Key Vault           │        │ Azure DB for PostgreSQL        │
     │  aeromarkup-database-url  │────────│  Flexible Server               │
     │  aeromarkup-session-secret│  DBURL │  schema "aeromarkup"           │
     └───────────────────────────┘        │  (data URLs stored in-DB)      │
                                          └───────────────────────────────┘
```

Set `FRONT_WITH_APPGW=false` to drop the gateway and let clients hit the Container
Apps managed ingress FQDN directly.

---

## 3. Prerequisites

- Azure CLI logged in to the target cloud. **For Azure Government, first run
  `az cloud set --name AzureUSGovernment`** (Commercial is the default `AzureCloud`).
- An **Azure Container Registry** (default name `aeromarkupacr`; the deploy script
  builds via ACR Tasks, so no local Docker needed).
- A **resource group** (default `aeromarkup-rg`).
- An **Azure Database for PostgreSQL Flexible Server** reachable from the Container
  Apps environment (VNet-integrated or via a private endpoint / firewall rule).
- A **Container Apps environment** (VNet-integrated if fronting with App Gateway).
- An **Azure Key Vault** holding the two secrets (see §4).
- A **user-assigned Managed Identity** with `AcrPull` on the ACR and `get` on Key
  Vault secrets.
- For the gateway path: a **TLS certificate** — preferred as a Key Vault certificate
  (`APPGW_KV_CERT_SECRET_ID` + an identity that can read it via `APPGW_IDENTITY_ID`),
  or a base64 PFX for lab use (`APPGW_SSL_CERT_DATA` / `APPGW_SSL_CERT_PASSWORD`).

---

## 4. Identity & credentials

**Prefer Managed Identity + Entra ID — never store ACR passwords or a DB password in
the image or Bicep.** A **user-assigned managed identity** is assigned to the Container
App; it authenticates image pulls and Key Vault reads. Static keys are avoided.

### Create the secrets in Key Vault (once per cloud)

```bash
az keyvault secret set --vault-name aeromarkup-kv --name aeromarkup-database-url \
  --value 'postgresql://aeromarkup:CHANGEME@aeromarkup.postgres.database.azure.com:5432/aeromarkup?sslmode=require'
az keyvault secret set --vault-name aeromarkup-kv --name aeromarkup-session-secret \
  --value "$(openssl rand -hex 32)"
```

For Azure Government, the Postgres host suffix is `.postgres.database.usgovcloudapi.net`
and the Key Vault DNS suffix is `.vault.usgovcloudapi.net` (see §5).

### Assign least-privilege roles to the managed identity

Use Key Vault **RBAC** (recommended) instead of access policies:

```bash
MI_ID=$(az identity show -g aeromarkup-rg -n aeromarkup-mi --query principalId -o tsv)

# Read secrets only — no manage/delete
az role assignment create --assignee "$MI_ID" \
  --role "Key Vault Secrets User" \
  --scope $(az keyvault show -n aeromarkup-kv --query id -o tsv)

# Pull images only — no push
az role assignment create --assignee "$MI_ID" \
  --role "AcrPull" \
  --scope $(az acr show -n aeromarkupacr --query id -o tsv)
```

**Least-privilege custom role (JSON)** — if you prefer a scoped custom role over the
built-in `Key Vault Secrets User`:

```json
{
  "Name": "AeroMarkup Secret Reader",
  "IsCustom": true,
  "Description": "Read AeroMarkup Key Vault secrets only.",
  "Actions": [],
  "DataActions": [
    "Microsoft.KeyVault/vaults/secrets/getSecret/action",
    "Microsoft.KeyVault/vaults/secrets/readMetadata/action"
  ],
  "NotDataActions": [],
  "AssignableScopes": [
    "/subscriptions/<SUB_ID>/resourceGroups/aeromarkup-rg/providers/Microsoft.KeyVault/vaults/aeromarkup-kv"
  ]
}
```

> On **Azure Government** the resource provider actions are identical; only the ARM
> endpoint (`management.usgovcloudapi.net`) and login authority differ — `az cloud set
> --name AzureUSGovernment` handles both. Assign the same roles/JSON in the Gov
> subscription.

### App runtime identity

AeroMarkup makes **no Azure API calls at runtime** (state is Postgres + client
IndexedDB). The managed identity is used only for the ACR pull and Key Vault secret
resolution — no broader data-plane permissions are required.

### AKS alternative

On AKS, use **Workload Identity** (federated Entra credential on a Kubernetes service
account) with the same two role assignments, and the **Secrets Store CSI driver** to
project the Key Vault secrets as env vars. See [KUBERNETES.md](./KUBERNETES.md).

---

## 5. Environment variables

`DATABASE_URL` and `AEROMARKUP_SECRET` come **from Key Vault** (Container App secrets
that reference Key Vault via the managed identity); the rest are plain env vars.

| Variable | Example | Purpose |
| --- | --- | --- |
| `DATABASE_URL` | *(Key Vault ref)* `postgresql://user:pw@host:5432/aeromarkup?sslmode=require` | Postgres DSN. **From `aeromarkup-database-url`.** |
| `AEROMARKUP_SECRET` | *(Key Vault ref)* 32+ hex chars | Session/CSRF signing key. **REQUIRED in production when `DATABASE_URL` is set.** From `aeromarkup-session-secret`. |
| `PORT` | `8080` | gunicorn bind port; must match Container App ingress target port. |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` idempotently at boot. |
| `ENVIRONMENT` | `production` | Enables production hardening (secure cookies, mandatory secret). |
| `TRUSTED_PROXY_HOPS` | `1` | Trusted proxies in front (App Gateway or Container Apps ingress = 1). |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime. Optional. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed-login lockout threshold. Optional. |
| `LOGIN_WINDOW_SECONDS` | `900` | Lockout window. Optional. |
| `LOGIN_MAX_TRACKED` | `1024` | Max distinct login identities tracked for throttling. Optional. |

### Cloud-specific values — **Commercial vs Azure Government**

| Setting | Azure Commercial (`AzureCloud`) | Azure Government (`AzureUSGovernment`) |
| --- | --- | --- |
| Select cloud | default | `az cloud set --name AzureUSGovernment` |
| ACR login suffix | `<ACR>.azurecr.io` | `<ACR>.azurecr.us` |
| Image tag | `<ACR>.azurecr.io/aeromarkup:latest` | `<ACR>.azurecr.us/aeromarkup:latest` |
| Postgres host suffix | `.postgres.database.azure.com` | `.postgres.database.usgovcloudapi.net` |
| Postgres TLS | `?sslmode=require` | `?sslmode=require` |
| Key Vault DNS suffix | `.vault.azure.net` | `.vault.usgovcloudapi.net` |
| ARM / management endpoint | `management.azure.com` | `management.usgovcloudapi.net` |
| Entra (login) authority | `login.microsoftonline.com` | `login.microsoftonline.us` |
| FedRAMP / IL | Optional | **Azure Government** regions (FedRAMP High / DoD IL) |

The repo deploy script [`deploy/azure-gov/deploy.sh`](../deploy/azure-gov/deploy.sh)
targets Government: it builds `${ACR}.azurecr.us/aeromarkup:latest` and assumes
`az cloud set --name AzureUSGovernment`. For Commercial, switch the cloud, use
`.azurecr.io`, and use the `.postgres.database.azure.com` host.

---

## 6. Configuration references

| Reference | Example / value | Purpose |
| --- | --- | --- |
| Deploy script | [`deploy/azure-gov/deploy.sh`](../deploy/azure-gov/deploy.sh) | `az acr build` → `az deployment group create` against `containerapp.bicep`. Env: `AZ_RESOURCE_GROUP` (default `aeromarkup-rg`), `AZ_ACR` (default `aeromarkupacr`), `DATABASE_URL` (required), `AEROMARKUP_SECRET` (required), `FRONT_WITH_APPGW` (default `true`). |
| Container App template | [`deploy/azure-gov/containerapp.bicep`](../deploy/azure-gov/containerapp.bicep) | Provisions the Container App; params `image`, `databaseUrl`, `sessionSecret`, `frontWithAppGateway`, `appGwKeyVaultCertSecretId`, `appGwIdentityId`, `appGwSslCertData`, `appGwSslCertPassword`. |
| App Gateway template | [`deploy/azure-gov/appgateway.bicep`](../deploy/azure-gov/appgateway.bicep) | Application Gateway v2 + WAF_v2 front-door (used when `frontWithAppGateway=true`). |
| Cert (preferred) | `APPGW_KV_CERT_SECRET_ID` + `APPGW_IDENTITY_ID` | Key Vault certificate + identity that can read it, for gateway TLS. |
| Cert (lab) | `APPGW_SSL_CERT_DATA` + `APPGW_SSL_CERT_PASSWORD` | Base64 PFX + password for test TLS. |
| Dockerfile | [`../Dockerfile`](../Dockerfile) | `python:3.12-slim`, non-root uid 10001, `EXPOSE 8080`, `HEALTHCHECK` `/api/health`, `gunicorn server:app --bind 0.0.0.0:$PORT --workers 2 --timeout 120`. |
| Schema | [`../db/schema.sql`](../db/schema.sql) | Idempotent schema applied when `AUTO_MIGRATE=1`. |

---

## 7. Verification

`APP_URL` = the deployment `url` output (App Gateway public FQDN, or the Container Apps
ingress FQDN when `FRONT_WITH_APPGW=false`).

**1. Health through the front door**

```bash
curl -fsS https://$APP_URL/api/health
# → {"status":"ok",...}  (HTTP 200)
```

**2. Bootstrap the first admin (first run only) + login**

```bash
curl -fsS -X POST https://$APP_URL/api/auth/bootstrap \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"CHANGEME-strong-pw","name":"Admin"}'

curl -fsS -c cookies.txt -X POST https://$APP_URL/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"CHANGEME-strong-pw"}'
```

**3. Create a project (DB write)** — pass the CSRF header from the cookie:

```bash
CSRF=$(awk '/am_csrf/{print $7}' cookies.txt)
curl -fsS -b cookies.txt -X POST https://$APP_URL/api/projects \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Verification Project"}'
```

**4. Confirm the row was written to the Flexible Server**

```bash
psql "$DATABASE_URL" -c 'SELECT count(*) FROM aeromarkup.projects;'
# → count ≥ 1   (host: *.postgres.database.azure.com or *.postgres.database.usgovcloudapi.net)
```

**5. Confirm the secret resolved from Key Vault into the container**

```bash
az containerapp exec -g aeromarkup-rg -n aeromarkup \
  --command '/bin/sh -c "test -n \"$AEROMARKUP_SECRET\" && test -n \"$DATABASE_URL\" && echo secrets-present"'
# → secrets-present   (values injected via Key Vault reference, never printed)
```

A successful login also proves the signing secret resolved (the `am_session` cookie is
signed with `AEROMARKUP_SECRET`).

---

## 8. Day-2 operations

**Push a new image + roll out**

```bash
# From aeromarkup/ — Government defaults. For Commercial: az cloud set --name AzureCloud
#                    and use an .azurecr.io ACR.
DATABASE_URL='postgresql://...?sslmode=require' \
AEROMARKUP_SECRET="$(openssl rand -hex 32)" \
AZ_RESOURCE_GROUP=aeromarkup-rg AZ_ACR=aeromarkupacr \
  ./deploy/azure-gov/deploy.sh
```

`az acr build` builds a new image; `az deployment group create` updates the Container
App, which rolls to the new revision and shifts traffic once healthy. To ship without
the gateway, add `FRONT_WITH_APPGW=false`.

**Database migrations** — schema changes ship in `db/schema.sql` and apply at boot
(`AUTO_MIGRATE=1`). Out-of-band: `psql "$DATABASE_URL" -f db/schema.sql` (idempotent).
Keep `db/schema.sql` current with every migration.

**Scaling** — set Container Apps min/max replicas and KEDA scale rules (HTTP
concurrency); the app is stateless. Scale the DB via the Flexible Server compute tier /
storage; add a read replica only for read-heavy reporting (not required).

**Backups / snapshots** — enable Flexible Server automated backups (PITR) and
geo-redundant backup where required. Because reference images and 3D models live in
Postgres columns, a Flexible Server backup captures **all** application state — there
is no separate blob store to back up. See
[../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md).

**Key / secret rotation** — update `aeromarkup-session-secret` in Key Vault and deploy
a new revision so it re-resolves; existing sessions become invalid (users re-login).
Rotate DB credentials in `aeromarkup-database-url` the same way. Rotate the App Gateway
TLS certificate in Key Vault; the gateway picks up the new version.

**Logs** — Container Apps stream to the environment's Log Analytics workspace:

```bash
az containerapp logs show -g aeromarkup-rg -n aeromarkup --follow
```

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Revision unhealthy / gateway 502 | App failing `/api/health` or ingress target port ≠ 8080 | Check `az containerapp logs`; confirm ingress target port 8080 and probe path `/api/health`. |
| `ImagePullFailure` | Managed identity lacks `AcrPull`, or wrong ACR suffix | Assign `AcrPull`; Gov uses `.azurecr.us`, Commercial `.azurecr.io`. |
| Secret reference fails to resolve | Managed identity lacks `Key Vault Secrets User` / vault RBAC not enabled | Grant the role (§4); ensure the vault uses RBAC or has an access policy for the identity. |
| App logs `AEROMARKUP_SECRET is required` | Secret not injected (prod + `DATABASE_URL` set) | Ensure the Container App secret maps to `aeromarkup-session-secret`. |
| Users logged out / wrong scheme behind the gateway | `TRUSTED_PROXY_HOPS` unset | Set `TRUSTED_PROXY_HOPS=1` (one proxy hop). |
| `SELECT ... FROM aeromarkup.projects` errors "schema does not exist" | Migrations didn't run | Confirm `AUTO_MIGRATE=1`; check boot logs; run `psql -f db/schema.sql`. |
| DB connection fails / SSL required | Firewall/VNet blocks the app→Flexible Server, or missing `sslmode` | Allow the Container Apps subnet / add a private endpoint; use `?sslmode=require`; Gov host suffix `.postgres.database.usgovcloudapi.net`. |
| `az` commands hit the wrong endpoints | Cloud not set for Gov | Run `az cloud set --name AzureUSGovernment` before deploying to Government. |
| Gateway deploy fails on TLS | No cert supplied with `FRONT_WITH_APPGW=true` | Provide `APPGW_KV_CERT_SECRET_ID`+`APPGW_IDENTITY_ID` (preferred) or `APPGW_SSL_CERT_DATA`/`_PASSWORD`, or set `FRONT_WITH_APPGW=false`. |
| 3D model / image upload rejected as too large | Body limit at the gateway/ingress or DB column limit | Data URLs can be large; raise request limits; ensure Postgres has room (`background_data` / `model_data`). |
