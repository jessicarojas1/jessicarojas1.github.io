# Azure Government Runbook

Step-by-step guidance for deploying and operating Sentinel QMS in **Microsoft Azure Government**, region
**`usgovvirginia`** (or `usgovtexas`). Covers subscription/tenant setup, networking, Key Vault, AKS,
PostgreSQL, Blob storage, secrets, edge/TLS, and monitoring. Pair with the general
[deployment-guide.md](deployment-guide.md).

> Azure Government is a sovereign cloud with separate endpoints (`*.usgovcloudapi.net`,
> `portal.azure.us`). Operations are restricted to screened **U.S. persons**. Set the CLI cloud to
> `AzureUSGovernment`.

---

## 1. Subscription & Access

```bash
az cloud set --name AzureUSGovernment
az login
az account set --subscription "<sub-id>"
```

- Dedicated subscription (or management group) per environment. ITAR programs use a **dedicated
  subscription** (single-tenant).
- Use **Microsoft Entra ID (Gov)** for RBAC; enforce **MFA** and Conditional Access. Prefer **Workload
  Identity** / managed identities over secrets.

---

## 2. Resource Group & Networking (Terraform `network` module, `cloud = "azure"`)

| Resource | Value |
|----------|-------|
| Location | `usgovvirginia` (`azure_location`) |
| VNet CIDR | `10.40.0.0/16` |
| Ingress subnet | `10.40.0.0/22`, `10.40.4.0/22` (App Gateway) |
| App subnet | `10.40.16.0/22`, `10.40.20.0/22` (AKS) |
| Data subnet | `10.40.32.0/22`, `10.40.36.0/22` (PostgreSQL) |
| Zones | Zone-redundant (1,2,3 where available) |

```bash
cd infra/terraform/azure
terraform init && terraform apply -var-file=envs/prod.tfvars
```

- Use **NSGs** for least-privilege (App Gateway→AKS app port; AKS→PostgreSQL 5432; deny otherwise).
- Add **Private Endpoints** for Blob Storage, Key Vault, and ACR so traffic stays on the Microsoft
  backbone. Data subnet has no internet egress (route via NAT/firewall only if required).

---

## 3. Key Vault (FIPS HSM)

- Create a Key Vault (Premium/HSM) per environment:
  - Keys: `rds-cmk` (PostgreSQL CMK), `blob-cmk` (storage CMK), `jwt-signing` (asymmetric JWT key, prod)
  - Secrets: DB DSN, OIDC client secret
- Enable purge protection and soft delete; set rotation policies.
- Grant access to AKS **managed identities** only (Key Vault RBAC / access policies). Send diagnostic logs
  to Log Analytics.

---

## 4. Container Registry (ACR)

```bash
az acr create -g sentinel-qms-prod -n sentinelqmsacr --sku Premium
az acr login -n sentinelqmsacr
```
Enable content trust / cosign verification, vulnerability scanning (Defender for Containers), and
geo-disabled (stay in-region). Admit only signed images.

---

## 5. AKS

- Provision AKS in the **app subnet** with availability zones, **Workload Identity** enabled, Azure CNI,
  and a private API server where policy requires.
- Install: Application Gateway Ingress Controller (AGIC) or nginx ingress, External Secrets / Key Vault CSI
  driver, and the cluster autoscaler.

```bash
az aks get-credentials -g sentinel-qms-prod -n sentinel-qms-prod
```

---

## 6. Azure Database for PostgreSQL 16 (Flexible Server)

| Setting | Value |
|---------|-------|
| Version | PostgreSQL 16 |
| HA | Zone-redundant high availability |
| Networking | Private access (VNet integration) in data subnet |
| Encryption | Customer-managed key (`rds-cmk`) |
| TLS | `require_secure_transport=ON`; app `sslmode=verify-full` |
| Backups | Geo-disabled, in-region; 35-day retention |
| Access | NSG: only AKS subnet on 5432 |

Run Alembic migrations as a Job before shifting traffic (deployment guide §8).

---

## 7. Blob Storage

- Storage account with:
  - Public network access **disabled**; reach via **Private Endpoint**
  - **CMK** encryption (`blob-cmk`)
  - Blob **versioning** + soft delete
  - `Secure transfer required` (TLS) and minimum TLS 1.2
- App config: `STORAGE_BACKEND=azure_blob`, `AZURE_STORAGE_CONNECTION_STRING` (from Key Vault),
  `AZURE_STORAGE_CONTAINER=sentinel-qms`. Keys namespaced per tenant; filenames randomized.

---

## 8. Secrets Injection

Use **Key Vault CSI driver** / External Secrets to mount secrets into pods as env vars:

| Key Vault item | App env |
|----------------|---------|
| DB DSN secret | `DATABASE_URL` |
| JWT signing key | `JWT_SECRET` / key ref |
| OIDC client secret | `OIDC_CLIENT_SECRET` |

No secrets in images or values files.

---

## 9. Edge & TLS

- **Application Gateway + WAF** (or Azure Front Door for global entry) terminates **TLS 1.2+** with a
  certificate from Key Vault.
- WAF policy: OWASP managed rules + rate limiting.

---

## 10. Observability

- **Azure Monitor** + **Log Analytics** for app (structured JSON) and platform logs.
- **Microsoft Sentinel** as SIEM; **Microsoft Defender for Cloud** for posture and threat protection.
- **Activity Log** forwarded for control-plane/audit.
- Alerts: 5xx rate, p95 latency, DB CPU/connections, pod restarts, audit-pipeline failures.

---

## 11. Deploy Sequence (Prod)

1. `terraform apply` (RG, VNet, Key Vault, AKS, PostgreSQL, Blob, ACR, private endpoints).
2. Push signed images to ACR.
3. Create/refresh secrets in Key Vault.
4. Snapshot/backup PostgreSQL.
5. Run Alembic migrations (Job).
6. `helm upgrade --install` with `values-azure-prod.yaml`.
7. Smoke test (health, auth, KPI read, audit row).
8. Confirm Azure Monitor/Sentinel/Defender receiving data.

---

## 12. Azure-Gov-Specific Gotchas

- Endpoints differ (`*.usgovcloudapi.net`); always set `az cloud set --name AzureUSGovernment`.
- Service/region availability and feature parity can lag commercial Azure — validate before design.
- Keep all data **in-region**; disable geo-replication for storage/DB to preserve residency.
- Use private endpoints to avoid public exposure of storage/Key Vault/ACR.
