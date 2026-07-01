# CITADEL — Azure Deployment (Commercial + Azure Government)

**Audience:** operators deploying CITADEL to Azure. This guide covers **Azure Commercial** and
**Azure Government**, aligned with the Bicep IaC and runbook under
[`../deploy/azure-gov/`](../deploy/azure-gov/) (FedRAMP-High / IL4–IL5).

CITADEL runs the **same container image** everywhere — Node 20 / Express on **:8080**,
health-check `GET /api/health`, **non-root**, **read-only root FS**, all capabilities dropped,
**≥ 2 GB RAM** (ClamAV ~1.4 GB signature DB). Build from the **repo root**:
`docker build -f citadel/server/Dockerfile -t citadel-server .`.

> **Image note.** The `deploy/azure-gov/` Bicep ships a hardened **front-end (nginx SPA)** image
> on **Azure Container Apps** with Key Vault (Premium/HSM), a private quarantine Storage
> account, and a user-assigned managed identity. To run the **deep-scan backend** (real
> scanners), deploy the `citadel-server` image on the same Container Apps topology and size the
> app to **≥ 2 vCPU / 2–4 GiB** (the front-end default of 0.5 vCPU / 1 GiB is not enough for
> ClamAV/Semgrep/Trivy).

Related: [KUBERNETES.md](KUBERNETES.md) (AKS + Workload Identity) · [AWS.md](AWS.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Env: [`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

| Layer | Commercial | Azure Government (`deploy/azure-gov/`) |
|---|---|---|
| Cloud | `AzureCloud` | `AzureUSGovernment` |
| Compute | **Azure Container Apps** (or App Service / AKS) | **Azure Container Apps**, internal-only environment |
| Registry | **ACR** (Premium for private endpoints) | **ACR** Premium, public access disabled, quarantine + trust policies |
| Database | **Azure Database for PostgreSQL** (Flexible Server) | Azure Database for PostgreSQL (in-boundary) |
| Secrets | **Key Vault** (Standard/Premium) | **Key Vault Premium (HSM)**, purge-protection, RBAC |
| Identity | **User-assigned managed identity** (Entra ID) | **UAMI** `id-citadel-prod` (no stored creds) |
| Edge / WAF | Front Door / App Gateway WAF v2 | **Front Door Gov / App Gateway WAF v2** in front of the internal env |
| Uploads | Blob storage (private) | **Storage GRS**, versioning + soft delete, no public/shared-key access |
| Logging | Log Analytics + Defender | **Log Analytics** (365-day) + **Defender for Cloud** |
| Regions | e.g. `eastus` | `usgovvirginia` / `usgovarizona` (paired for DR) |

## 2. Topology

```
                  Internet ──► Front Door Gov / App Gateway WAF v2 (TLS 1.2+)
                                       │  Private Link
                        ┌──────────────▼───────────────┐
                        │ Container Apps Environment     │  internal: true (no public ingress)
                        │  cae-citadel-prod (VNet)       │
                        │  ┌──────────────────────────┐  │
                        │  │ ca-citadel-*-web :8080    │  │  ingress external:false, allowInsecure:false
                        │  │  citadel-server           │  │  scale 1→5 (HTTP concurrency 50)
                        │  │  /api/health              │  │  UAMI: AZURE_CLIENT_ID
                        │  └────┬──────────────┬───────┘  │
                        └───────┼──────────────┼──────────┘
             Key Vault Secrets  │              │  Storage Blob Data Contributor
                  User (RBAC)   │              │  (quarantine container)
              ┌─────────────────▼──┐    ┌──────▼─────────────────────────┐
              │ Key Vault Premium  │    │ Storage (GRS, private endpoint)│
              │  (HSM) — secrets   │    │  quarantine container (no pub) │
              └────────────────────┘    └────────────────────────────────┘
             AcrPull │                     Postgres Flexible Server (private) ◄─ DATABASE_URL
              ┌──────▼──────┐              Diagnostics ──► Log Analytics (365d) + Defender
              │ ACR Premium │
              └─────────────┘
```

## 3. Prerequisites

| Requirement | Commercial | Azure Government |
|---|---|---|
| Azure CLI | logged in, subscription selected | `az cloud set --name AzureUSGovernment` first |
| Bicep | `az bicep` current | same |
| Docker / ACR Tasks | build from repo root, or `az acr build` in-boundary | **`az acr build`** (image built inside the Gov boundary) |
| RBAC | Contributor + User Access Administrator (role assignments) | same, in the Gov tenant |
| DNS | record → Front Door / App Gateway | record → Front Door Gov |
| Providers | `Microsoft.App`, `ContainerRegistry`, `KeyVault`, `Storage`, `DBforPostgreSQL`, `OperationalInsights` registered | same |

## 4. Identity & credentials (managed identity, no keys)

- **User-assigned managed identity** `id-citadel-prod` is the app's Entra ID workload identity —
  **no credentials are stored anywhere**. The container reads `AZURE_CLIENT_ID` and uses
  `DefaultAzureCredential`. Least-privilege role assignments:

  | Role | Scope | Role definition ID | Purpose |
  |---|---|---|---|
  | **AcrPull** | ACR | `7f951dda-4ed3-4680-a7ca-43fe172d538d` | Pull the image (no admin creds) |
  | **Key Vault Secrets User** | Key Vault | `4633458b-17de-408a-b874-0445c86b69e6` | Read runtime secrets |
  | **Storage Blob Data Contributor** | Storage account | `ba92f5b4-2d11-453d-a403-e96b0029c9fe` | Read/write the quarantine container |

- **Key Vault** uses **RBAC authorization** (not legacy access policies), **soft-delete +
  purge-protection**, and **public network access disabled** (Premium/HSM in Gov for FIPS
  140-3 key storage).
- **ACR** has **admin disabled** — pulls are via the UAMI + AcrPull; images are digest-pinned in
  production.
- **App users** authenticate to CITADEL with JWT/OIDC; map SSO via Entra ID
  (`OIDC_ISSUER=https://login.microsoftonline.com/<tenant>/v2.0`, or the Gov login endpoint).

## 5. Environment variables

Non-secret env set on the container; secrets are **Key Vault references** resolved by the UAMI.

| Variable | Commercial example | Azure Gov example | Purpose |
|---|---|---|---|
| `NODE_ENV` | `production` | `production` | Prod hardening |
| `PORT` | `8080` | `8080` | Ingress `targetPort` |
| `CITADEL_TMP` | `/tmp/citadel` | `/tmp/citadel` | Scratch mount |
| `AZURE_CLIENT_ID` | UAMI client id | UAMI client id | `DefaultAzureCredential` |
| `CITADEL_ENV` | `prod` | `prod` | Environment tag |
| `CITADEL_QUARANTINE_ACCOUNT` | storage acct name | storage acct name | Blob quarantine target |
| `CITADEL_QUARANTINE_CONTAINER` | `quarantine` | `quarantine` | Quarantine container |
| `PGSSL` / `PGSSL_VERIFY` | `1` / `1` | `1` / `1` | TLS to Postgres |

Secrets (Key Vault → env, via UAMI **Key Vault Secrets User**):

| Env var | Key Vault secret | Purpose |
|---|---|---|
| `CITADEL_JWT_SECRET` | `citadel-jwt-secret` | HS256 session signing key |
| `CITADEL_ADMIN_PASSWORD` | `citadel-admin-password` | First-boot admin |
| `DATABASE_URL` | `citadel-database-url` | `postgres://…@<pg>.postgres.database.<suffix>:5432/citadel?sslmode=verify-full` |
| `CITADEL_SUPERADMIN_TOKEN` | `citadel-superadmin-token` | Tenant provisioning (optional) |
| `CITADEL_METRICS_TOKEN` | `citadel-metrics-token` | `/metrics` bearer (optional) |

**Gov endpoint notes:** cloud `AzureUSGovernment`; endpoints under `*.usgovcloudapi.net`
(Key Vault `*.vault.usgovcloudapi.net`, Storage `*.blob.core.usgovcloudapi.net`); ACR login
server `<acr>.azurecr.us`; Entra ID login `https://login.microsoftonline.us`. Storage enforces
**TLS 1.2 min**, HTTPS-only, **shared-key access disabled** (OAuth/Entra only), infrastructure
(double) encryption.

## 6. Configuration references (Bicep parameters — `parameters.json`)

| Parameter | Example | Purpose |
|---|---|---|
| `location` | `usgovvirginia` / `eastus` | Region |
| `namePrefix` | `citadel` | Resource name prefix |
| `environmentName` | `prod` | Env + `CITADEL_ENV` |
| `containerImage` | `<acr>.azurecr.us/citadel/web:1.0.0` | Image (override to the deep-scan image) |
| `containerPort` | `8080` | Ingress target |
| `cpuCores` | `0.5` (front-end) → **`2.0`** (deep-scan) | vCPU |
| `memorySize` | `1.0Gi` (front-end) → **`4.0Gi`** (deep-scan) | Memory (≥ 2 GiB for ClamAV) |
| `minReplicas` / `maxReplicas` | `1` / `5` | Autoscale bounds (HTTP concurrency 50) |
| `internalOnly` | `true` | No public ingress (WAF fronts it) |
| `logRetentionDays` | `365` | Log Analytics retention |
| `classification` / `impactLevel` | `CUI` / `IL4` | Compliance tags |

## 7. Deploy

```bash
cd citadel/deploy/azure-gov       # commercial: use az cloud set --name AzureCloud

# deploy.sh: sets AzureUSGovernment, verifies subscription + Gov endpoint, creates
# rg-citadel-prod, ensures ACR (Premium, public access disabled, admin disabled),
# builds the image IN-BOUNDARY with `az acr build`, deploys main.bicep with parameters.json,
# then prints appUrl + keyVaultUri. Remember to override containerImage + size for deep-scan.
./deploy.sh
```

Manual equivalent:

```bash
az group create -n rg-citadel-prod -l usgovvirginia
az acr build -r acrcitadelprod<suffix> \
  -f citadel/server/Dockerfile -t citadel/server:1.0.0 .     # build deep-scan image in-boundary
az deployment group create -g rg-citadel-prod -f main.bicep -p parameters.json \
  -p containerImage=acrcitadelprod<suffix>.azurecr.us/citadel/server:1.0.0 \
  -p cpuCores=2.0 -p memorySize=4.0Gi
```

Front the **internal** Container Apps environment with **Front Door Gov / App Gateway WAF v2**
before exposing it to users (the environment is `internal: true`).

## 8. Verification

```bash
APP=$(az deployment group show -g rg-citadel-prod -n <deployment> \
  --query properties.outputs.appUrl.value -o tsv)
# Reach it via Front Door / App Gateway (the app FQDN is private).
URL="https://citadel.example.gov"    # your WAF front-end hostname

# 1. Health
curl -fsS "$URL/api/health" | jq          # {"ok":true,"engine":"deep",...,"scanners":[…]}

# 2. Login (JWT) + secrets resolved from Key Vault (via UAMI)
PW=$(az keyvault secret show --vault-name kv-citadel-prod-<suffix> \
  --name citadel-admin-password --query value -o tsv)
TOKEN=$(curl -sS -X POST "$URL/api/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"admin@example.gov\",\"password\":\"$PW\"}" | jq -r .token)
curl -fsS "$URL/api/auth/me" -H "Authorization: Bearer $TOKEN" | jq .email

# 3. Upload accepted + SCANNED (field "files")
zip -r /tmp/s.zip citadel/js >/dev/null
curl -sS -X POST "$URL/api/scan" -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/s.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length)}' /tmp/report.json

# 4. Report persisted to Postgres + object written to quarantine Blob
curl -fsS "$URL/api/scans" -H "Authorization: Bearer $TOKEN" | jq 'length'
az storage blob list --account-name <quarantine-acct> --container-name quarantine \
  --auth-mode login -o table
```

**Secrets resolved check:** the container starts only when the UAMI can read Key Vault; a role
gap surfaces as a crash-looping revision — check the revision's console/system logs in Log
Analytics.

## 9. Day-2 operations

- **Scanner signature / DB updates.** Container Apps replicas are ephemeral — DBs refresh per
  revision. Keep freshness by rebuilding the image (`az acr build`) on a schedule and rolling a
  new revision, or run `freshclam` / `trivy --download-db-only` / `grype db update` at startup.
  For **Gov/air-gapped** mirror DBs in-boundary — see [AIRGAPPED.md](AIRGAPPED.md).
- **Upgrades / rollback.** New immutable image tag → `az containerapp update`. Single-revision
  mode swaps traffic on health; keep the previous revision to roll back.
- **DB migrations.** None — schema created on boot (idempotent
  [`../database/schema.sql`](../database/schema.sql)).
- **Scaling.** Raise `maxReplicas` / `cpuCores` / `memorySize`; scale rule is HTTP concurrency.
- **Backups.** Azure Database for PostgreSQL automated backups (geo-redundant in Gov); Storage
  is **GRS** with versioning + 30-day soft delete. Test restores.
- **Secret rotation.** Rotate in Key Vault (soft-delete/purge-protection on); revision picks up
  new values on restart. Rotating `CITADEL_JWT_SECRET` invalidates sessions.
- **Logs / monitoring.** Diagnostic settings stream Key Vault/Storage/ACR/Container Apps logs to
  **Log Analytics** (365-day); enable **Defender for Cloud**. Ship CITADEL audit via
  `CITADEL_AUDIT_SINK_URL`.
- **Image posture.** ACR **quarantine + content-trust** policies gate images; Defender scans on
  push; pin digests in production.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Revision crash-loops at start | UAMI lacks Key Vault Secrets User / AcrPull | Assign the roles at the right scope; confirm `AZURE_CLIENT_ID` set |
| Cannot pull image | ACR admin disabled + no AcrPull | Grant UAMI **AcrPull**; ensure the app references the UAMI as registry identity |
| `502` / OOM during scans | Front-end sizing (0.5 vCPU/1 GiB) too small | Set `cpuCores=2.0`, `memorySize=4.0Gi` (≥ 2 GiB); `SCAN_CONCURRENCY=1` |
| App unreachable | Environment is `internal: true` | Front with Front Door / App Gateway WAF; resolve the private FQDN via Private Link |
| Postgres TLS failure | `verify-full` without CA / private endpoint | Provide `PGSSL_CA`, ensure private DNS to the Flexible Server |
| Storage write denied | Missing Storage Blob Data Contributor / shared-key disabled | Grant the role; use OAuth (`--auth-mode login`), not account keys |
| First deep scan slow | Trivy/Grype/ClamAV DBs downloading | Pre-seed at build/startup; mirror in Gov |
| Sessions reset on revision swap | New random JWT secret | Keep `citadel-jwt-secret` stable in Key Vault |

## 11. Compliance cross-walk (Azure Gov)

Non-root + RO root FS + caps dropped (**AC-6/CM-7**); TLS-only ingress, `internal:true` +
WAF-fronted (**SC-7/SC-8**); Key Vault Premium/HSM secrets via UAMI (**IA-5/SC-12**, FIPS 140-3
key storage); Storage encryption + infra double-encryption + private endpoints + WORM-like
soft-delete/versioning quarantine (**SC-28**); Log Analytics 365-day + Defender (**AU-2/AU-6/AU-12/SI-4**);
ACR quarantine/trust + Defender scan (**RA-5/SI-2**). Tags `classification=CUI`,
`impactLevel=IL4`, `fedramp=High`. See [`../deploy/azure-gov/README.md`](../deploy/azure-gov/README.md)
and [`../deploy/README.md`](../deploy/README.md).

## 12. Teardown

```bash
az group delete -n rg-citadel-prod --yes --no-wait
# Key Vault has purge-protection (cannot be force-purged before the retention window);
# Storage soft-delete/versioning retains quarantined blobs for their retention period.
```
