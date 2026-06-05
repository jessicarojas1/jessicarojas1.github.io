# CITADEL — Azure Government Deployment Runbook

**CITADEL** — *Code Inspection, Threat Analysis & Deployment Evaluation Lab* — is a
source-code and executable security & compliance review platform. This package deploys the
**production** CITADEL service (containerized Nginx front-end + optional API/worker scanner
tier) to **Azure Government** in a posture suitable for **CUI / FedRAMP High / IL4–IL5**
workloads.

> Audience: Cloud/Platform engineers and ISSOs deploying CITADEL into an Azure Government
> subscription that is in (or moving toward) a FedRAMP High / DoD IL4–IL5 authorization
> boundary. This runbook is the Infrastructure-as-Code (IaC) and operational reference for
> that deployment.

---

## 1. Overview & Target Architecture

CITADEL's production deployment is intentionally minimal in its public surface:

- A **static SPA front-end** (HTML/CSS/JS, JSZip + Chart.js) served by a hardened,
  non-root Nginx container. In Azure Gov, the front-end is hosted on **Azure Container Apps**
  (default) or **App Service for Containers** (alternative).
- An optional **API / worker tier** that runs heavier open-source scanners
  (Semgrep, Trivy, Syft/Grype, Gitleaks, ClamAV, Bandit) as a separate Container App revision
  or scale-set. Uploaded artifacts are treated as **untrusted** and quarantined in a private,
  no-public-access Storage account.
- All ingress is funneled through a **Web Application Firewall** (Azure Application Gateway
  WAF v2, or Azure Front Door Gov where available). There is **no direct public ingress** to
  the container — the Container Apps Environment is **internal-only** and reachable only via
  Private Endpoint behind the WAF.

### Azure Government regions

| Purpose            | Region (CLI name)   | Notes                                  |
| ------------------ | ------------------- | -------------------------------------- |
| Primary            | `usgovvirginia`     | Default for this package               |
| Secondary / DR     | `usgovarizona`      | Paired region for geo-redundancy / DR  |

Azure Government uses distinct ARM and data-plane endpoints (e.g. `*.usgovcloudapi.net`,
ACR login server `*.azurecr.us`, Key Vault `*.vault.usgovcloudapi.net`). Always set the CLI
cloud to `AzureUSGovernment` before any `az` command (see Prerequisites).

### Engine modules deployed

The container image bundles the CITADEL engine: **Ingest Engine, Language Classifier,
SAST Rules Engine, Secrets Scanner, SBOM & Dependency Analyzer, Binary/Executable Analyzer,
Compliance Mapping Engine, Quality & Maintainability Analyzer, Deployment & IaC Detector,
Scoring & Grading Engine, Report & Export Engine.** The static SPA runs the heuristic
pass entirely client-side; the worker tier (when enabled) runs the full open-source scanner
suite server-side.

### Architecture diagram (Azure Government)

```
                              Azure Government (usgovvirginia)
                                         Tenant / Subscription
  ┌──────────────────────────────────────────────────────────────────────────────────────┐
  │                                                                                        │
  │   Authorized          ┌───────────────────────────┐                                    │
  │   Users (CAC/PIV) ───► │  Azure Front Door Gov /    │   TLS 1.2+ (FIPS), HTTPS only      │
  │   over IL4/IL5 net      │  App Gateway WAF v2 (OWASP) │                                    │
  │                        └──────────────┬─────────────┘                                    │
  │                                       │ Private Link                                     │
  │                       ┌───────────────▼───────────────────────────────────────────┐     │
  │                       │                Virtual Network (hub/spoke)                  │     │
  │                       │  ┌──────────────────────────────────────────────────────┐  │     │
  │                       │  │   snet-apps (delegated)                               │  │     │
  │                       │  │   ┌────────────────────────────────────────────────┐ │  │     │
  │                       │  │   │  Azure Container Apps Environment (internal)    │ │  │     │
  │                       │  │   │  ┌──────────────┐    ┌───────────────────────┐  │ │  │     │
  │   Managed Identity ───┼──┼──►│  CITADEL Front  │    │  CITADEL Worker (opt.) │  │ │  │     │
  │   (UAMI, no secrets)  │  │   │  (Nginx, SPA)   │    │  Semgrep/Trivy/Syft/   │  │ │  │     │
  │                       │  │   │  non-root       │    │  Grype/Gitleaks/ClamAV │  │ │  │     │
  │                       │  │   └──────┬──────────┘    └──────────┬────────────┘  │ │  │     │
  │                       │  │          │                          │               │ │  │     │
  │                       │  └──────────┼──────────────────────────┼───────────────┘ │  │     │
  │                       │             │ Private Endpoints        │                 │  │     │
  │                       │  ┌──────────▼──────────┐  ┌────────────▼─────────────┐   │  │     │
  │                       │  │  snet-pe (private)  │  │  snet-pe (private)        │   │  │     │
  │                       │  └──────────┬──────────┘  └────────────┬─────────────┘   │  │     │
  │                       └─────────────┼──────────────────────────┼─────────────────┘  │     │
  │                                     │                          │                     │     │
  │     ┌───────────────┐  ┌────────────▼───────┐  ┌───────────────▼──────┐  ┌─────────┐ │     │
  │     │  Azure         │  │  Azure Key Vault    │  │  Storage (quarantine) │  │  Log    │ │     │
  │     │  Container      │  │  (Premium/HSM,      │  │  private blob, infra  │  │  Analy- │ │     │
  │     │  Registry       │  │   RBAC, soft-delete)│  │  encryption, no public│  │  tics   │ │     │
  │     │  (Premium, PE)  │  │  *.vault.usgov...   │  │  access, TLS1.2 min   │  │  + Defen│ │     │
  │     └───────────────┘  └─────────────────────┘  └──────────────────────┘  │  der    │ │     │
  │                                                                            └─────────┘ │     │
  │                                                                                        │     │
  │   Diagnostic settings on every resource ───────────────► Log Analytics ──► Sentinel    │     │
  └──────────────────────────────────────────────────────────────────────────────────────┘
                                       │ geo-replication / DR
                                       ▼
                              Azure Government (usgovarizona)  — paired DR region
```

---

## 2. Prerequisites

1. **Azure Government subscription** within an authorized tenant (FedRAMP High / IL4–IL5
   boundary). Confirm your subscription is in the `AzureUSGovernment` cloud, **not** commercial.
2. **Azure CLI** ≥ 2.60 with Bicep ≥ 0.27 (`az bicep install && az bicep upgrade`).
3. **RBAC roles** on the target subscription/resource group:
   - `Owner` or (`Contributor` + `User Access Administrator`) to create resources and role
     assignments for the managed identity.
   - `Key Vault Administrator` (data-plane RBAC) to seed secrets.
4. **FIPS-enabled base image** for the container. The provided `Dockerfile` uses
   `nginx:1.27-alpine`; for strict FIPS 140-3 you may substitute a FIPS-validated base
   (e.g. a Chainguard/UBI FIPS image) — see the Dockerfile comments.
5. **Network**: an existing hub VNet with connectivity to your IL4/IL5 user network, or use
   the VNet provisioned by `main.bicep`. Private DNS zones for Key Vault, Blob, and ACR must
   resolve to the Gov suffixes:
   - `privatelink.vaultcore.usgovcloudapi.net`
   - `privatelink.blob.core.usgovcloudapi.net`
   - `privatelink.azurecr.us`
6. **Defender for Cloud** enabled on the subscription (Servers, Containers, Storage,
   Key Vault plans) for IL4/IL5 continuous monitoring.

### Set the CLI to Azure Government (required first step)

```bash
# Point the Azure CLI at the Government cloud — ALL subsequent endpoints become *.usgovcloudapi.net
az cloud set --name AzureUSGovernment

# Device-code login is recommended on hardened admin workstations
az login --use-device-code

# Pin the working subscription
az account set --subscription "<YOUR-GOV-SUBSCRIPTION-ID>"

# Verify you are actually in Gov (endpoints should show usgovcloudapi.net)
az cloud show --query "{name:name, resourceManager:endpoints.resourceManager}" -o table
```

---

## 3. Step-by-Step Deployment (CLI + Bicep)

The deployment is captured in `deploy.sh`. The steps below explain what it does so you can
run it piecemeal during an authorized change window.

### 3.1 Create the resource group

```bash
LOCATION="usgovvirginia"
RG="rg-citadel-prod"

az group create \
  --name "$RG" \
  --location "$LOCATION" \
  --tags system=CITADEL classification=CUI impactLevel=IL4 fedramp=High
```

### 3.2 Build & push the image to Azure Container Registry (Gov)

ACR in Gov uses the `*.azurecr.us` login server. Use **ACR Tasks** (`az acr build`) so the
image is built inside the Gov boundary — no image crosses the commercial/Gov boundary.

```bash
ACR_NAME="acrcitadelprod"   # must be globally unique within Gov; lowercase, 5-50 alnum

# ACR is also created by main.bicep; if building before infra, create it standalone:
az acr create --resource-group "$RG" --name "$ACR_NAME" \
  --sku Premium --location "$LOCATION" \
  --public-network-enabled false --admin-enabled false

# Build the hardened image inside the Gov boundary and push it
az acr build \
  --registry "$ACR_NAME" \
  --image citadel/web:1.0.0 \
  --file Dockerfile .

# Resulting reference: ${ACR_NAME}.azurecr.us/citadel/web:1.0.0
```

### 3.3 Deploy infrastructure with Bicep

```bash
az deployment group create \
  --resource-group "$RG" \
  --template-file main.bicep \
  --parameters @parameters.json \
  --parameters location="$LOCATION" \
               containerImage="${ACR_NAME}.azurecr.us/citadel/web:1.0.0" \
  --name "citadel-$(date +%Y%m%d-%H%M%S)"
```

### 3.4 Grant the managed identity pull rights & Key Vault access

`main.bicep` creates a **user-assigned managed identity (UAMI)** and assigns:
- `AcrPull` on the registry (so the Container App pulls without admin creds), and
- `Key Vault Secrets User` on the vault (so the app reads secrets at runtime).

If you create the ACR out-of-band, re-run the role assignment:

```bash
UAMI_PRINCIPAL_ID=$(az deployment group show -g "$RG" -n <deploymentName> \
  --query "properties.outputs.uamiPrincipalId.value" -o tsv)

az role assignment create \
  --assignee-object-id "$UAMI_PRINCIPAL_ID" \
  --assignee-principal-type ServicePrincipal \
  --role AcrPull \
  --scope $(az acr show -n "$ACR_NAME" -g "$RG" --query id -o tsv)
```

### 3.5 Retrieve the application URL

```bash
az deployment group show -g "$RG" -n <deploymentName> \
  --query "properties.outputs.appUrl.value" -o tsv
```

Because the Container Apps Environment is **internal**, the FQDN resolves to a private IP and
is only reachable through the WAF / Front Door Gov front-end.

---

## 4. Security & Compliance Hardening Checklist

Mapped to **NIST SP 800-53 Rev5** control IDs and **CMMC 2.0 L2 / NIST SP 800-171**
practices. Complete and evidence each item before issuing an ATO recommendation.

### Access Control (AC) & Identification/Authentication (IA)

- [ ] **No admin credentials on ACR** (`--admin-enabled false`); pulls use UAMI + `AcrPull`. — `AC-2`, `AC-6`, `IA-2`, CMMC `AC.L2-3.1.1`
- [ ] **Managed identity only** — no secrets in app settings, no SAS keys in config. — `IA-5`, `AC-6(9)`
- [ ] **Least privilege RBAC** on RG; no `Owner` granted to runtime identities. — `AC-6`, CMMC `AC.L2-3.1.5`
- [ ] **Key Vault RBAC authorization** (not legacy access policies); `Key Vault Secrets User` only. — `AC-3`, `IA-5`
- [ ] **Front-door auth** (Entra ID Gov / CAC-PIV at the IdP) in front of the WAF. — `IA-2(1)`, `AC-17`

### Audit & Accountability (AU)

- [ ] **Diagnostic settings** on every resource → Log Analytics (created by Bicep). — `AU-2`, `AU-6`, `AU-12`
- [ ] **Activity logs** and **Container App console/system logs** retained ≥ 365 days. — `AU-11`
- [ ] **Microsoft Sentinel** (or Defender) connected to the workspace for alerting. — `AU-6(1)`, `SI-4`

### System & Communications Protection (SC)

- [ ] **TLS 1.2+ minimum, FIPS 140-3 ciphers** on Storage, Key Vault, WAF, Nginx. — `SC-8`, `SC-13`, CMMC `SC.L2-3.13.8`, `SC.L2-3.13.11`
- [ ] **No public ingress** to the container; Container Apps Environment is `internal: true`. — `SC-7`, CMMC `SC.L2-3.13.1`
- [ ] **Private Endpoints** for ACR, Key Vault, Storage; public network access disabled. — `SC-7(3)`, `AC-4`
- [ ] **Storage**: `allowBlobPublicAccess=false`, `publicNetworkAccess=Disabled`, infra encryption, OAuth default. — `SC-28`, `SC-7`
- [ ] **WAF in Prevention mode**, OWASP CRS managed ruleset. — `SC-7`, `SI-3`, OWASP Top 10
- [ ] **Quarantine bucket** for uploads is segregated and never served back to clients. — `SC-7`, `SI-3`

### System & Information Integrity (SI)

- [ ] **Defender for Cloud** (Containers, Storage, Key Vault plans) enabled. — `SI-3`, `SI-4`, `RA-5`
- [ ] **Image scanning** in ACR (Defender) + the CITADEL self-scan in CI. — `RA-5`, `SI-2`, NIST 800-218 SSDF `RV.1`
- [ ] **ClamAV** in the worker tier scans every uploaded artifact before analysis. — `SI-3`
- [ ] **Security headers** (CSP, HSTS, X-Frame-Options DENY, etc.) enforced by Nginx. — `SC-18`, OWASP

### Configuration Management (CM)

- [ ] **Everything is IaC** (`main.bicep` + `parameters.json`); no portal click-ops drift. — `CM-2`, `CM-3`, CMMC `CM.L2-3.4.1`
- [ ] **Immutable, digest-pinned images** in production; tag promotion via CI only. — `CM-2`, `SI-7`
- [ ] **Resource tags** record `classification`, `impactLevel`, `fedramp`. — `CM-8`
- [ ] **DISA STIG** baseline applied to host/runtime where applicable. — `CM-6`

---

## 5. Secrets, Identity & Cryptography

- **Key Vault** (Premium SKU, HSM-backed keys) holds any runtime secrets (e.g. scanner API
  tokens, worker queue connection if used). Soft-delete + purge protection are **enabled** and
  **cannot** be disabled — required for FedRAMP.
- The Container App references secrets via **Key Vault reference + UAMI**; secrets never appear
  in plaintext in `parameters.json`, environment variables, or logs.
- **TLS 1.2+** is the floor everywhere; the platform negotiates FIPS-validated cipher suites.
  Set the FIPS base image (Dockerfile note) for in-container crypto to be FIPS 140-3 validated.
- **No SAS tokens / account keys** are distributed; Storage access uses Entra ID (`OAuth`)
  with the UAMI and `Storage Blob Data Contributor` scoped to the quarantine container only.

---

## 6. Logging, Monitoring, Backup & DR

### Logging / Monitoring

- A **Log Analytics workspace** is provisioned by Bicep; **diagnostic settings** stream
  Container App, Key Vault, Storage, and WAF logs into it.
- **Microsoft Defender for Cloud** provides CSPM + workload protection; connect the workspace
  to **Microsoft Sentinel (Gov)** for SIEM/SOAR and `SI-4` continuous monitoring.
- Recommended KQL alerts: failed Key Vault `SecretGet`, anomalous egress from worker subnet,
  ClamAV detections, WAF blocked-request spikes.

### Backup

- **Key Vault**: soft-delete (90 days) + purge protection.
- **Storage (quarantine)**: versioning + soft-delete on blobs; lifecycle policy auto-purges
  analyzed artifacts after the retention window (default 30 days) to limit CUI sprawl.
- **ACR**: Premium SKU **geo-replication** to `usgovarizona`.
- **IaC**: `main.bicep` / `parameters.json` are the source of truth in version control.

### Disaster Recovery

- **Region pair**: `usgovvirginia` (primary) ↔ `usgovarizona` (DR).
- ACR is geo-replicated; re-run `deploy.sh` with `LOCATION=usgovarizona` to stand up the DR
  stack from the same IaC. Target **RTO ≤ 4h, RPO ≤ 1h** for the stateless front-end (the
  SPA is stateless; only the quarantine store and Key Vault carry state, both georedundant).

---

## 7. Cost Notes

Indicative, not a quote. Azure Gov pricing differs from commercial; confirm in the Gov pricing
calculator.

| Component                          | Driver                       | Relative cost |
| ---------------------------------- | ---------------------------- | ------------- |
| Container Apps Environment         | vCPU/mem-seconds, replicas   | Low–Med (scales to zero idle) |
| Azure Container Registry (Premium) | geo-replication, PE          | Med           |
| Key Vault (Premium/HSM)            | operations + HSM keys        | Low           |
| Storage (quarantine)               | GB stored + transactions     | Low           |
| Log Analytics                      | GB ingested + retention      | **Med–High**  |
| App Gateway WAF v2 / Front Door    | fixed + capacity units       | **Med–High**  |
| Defender for Cloud                 | per-resource plans           | Med           |

Cost levers: scale the front-end to zero when idle, cap Log Analytics retention/ingestion,
and run the worker tier on a separate scale rule that activates only when a scan is queued.

---

## 8. Teardown

> Destroys all CITADEL resources in the resource group. Key Vault purge-protection means the
> vault name is reserved until the soft-delete window elapses.

```bash
az cloud set --name AzureUSGovernment

RG="rg-citadel-prod"

# Delete the whole resource group (async)
az group delete --name "$RG" --yes --no-wait

# Key Vault has purge protection — it will remain soft-deleted until expiry and CANNOT be
# force-purged while purge protection is on (this is required for FedRAMP). List recoverable:
az keyvault list-deleted --query "[].{name:name, scheduledPurge:properties.scheduledPurgeDate}" -o table
```

---

## 9. Deployment Control → Compliance Mapping

| Deployment control                                    | NIST SP 800-53 Rev5        | CMMC 2.0 / 800-171     | Other                         |
| ----------------------------------------------------- | -------------------------- | ---------------------- | ----------------------------- |
| No public container ingress; internal env behind WAF  | `SC-7`, `SC-7(3)`          | `SC.L2-3.13.1`         | CIS v8 12.x; FedRAMP          |
| Private Endpoints (ACR/KV/Storage), public net off    | `SC-7`, `AC-4`             | `SC.L2-3.13.1`         | FedRAMP High                  |
| TLS 1.2+ / FIPS 140-3 ciphers everywhere              | `SC-8`, `SC-13`            | `SC.L2-3.13.8/.11`     | FIPS 140-3                    |
| Managed identity; no stored credentials/SAS           | `IA-5`, `AC-6(9)`          | `IA.L2-3.5.x`          | DFARS 252.204-7012            |
| Key Vault (HSM), soft-delete + purge protection       | `SC-12`, `SC-28`           | `SC.L2-3.13.16`        | FIPS 140-3 (HSM)              |
| Storage no public access + infra encryption           | `SC-28`, `SC-7`            | `SC.L2-3.13.16`        | FedRAMP High                  |
| Diagnostic settings → Log Analytics, ≥365d retention  | `AU-2`, `AU-6`, `AU-11/12` | `AU.L2-3.3.x`          | NIST CSF 2.0 DE.AE            |
| Defender for Cloud + Sentinel (SIEM)                  | `SI-4`, `RA-5`, `AU-6(1)`  | `SI.L2-3.14.x`         | SOC 2 CC7; NIST CSF DE        |
| Image scanning (Defender + CITADEL self-scan)         | `RA-5`, `SI-2`             | `RA.L2-3.11.2`         | NIST 800-218 SSDF `RV.1`      |
| ClamAV on all uploaded artifacts                      | `SI-3`                     | `SI.L2-3.14.2`         | DFARS 252.204-7012            |
| Security headers (CSP/HSTS/XFO) at Nginx              | `SC-18`                    | —                      | OWASP Top 10 A05              |
| All-IaC, immutable digest-pinned images               | `CM-2`, `CM-3`, `SI-7`     | `CM.L2-3.4.1/.2`       | CIS v8 4.x; ISO 27001 A.8.9   |
| Resource tags (classification/IL/fedramp)             | `CM-8`                     | `CM.L2-3.4.1`          | ISO 27001 A.5.9               |
| ACR geo-replication + region-pair DR                  | `CP-9`, `CP-10`            | `CP.L2-3.x`            | NIST CSF 2.0 RC               |

---

## 10. Files in this package

| File              | Purpose                                                            |
| ----------------- | ----------------------------------------------------------------- |
| `README.md`       | This runbook.                                                     |
| `main.bicep`      | Bicep IaC: LAW, Container Apps Env, ACR, Key Vault, Storage, UAMI, Container App, diagnostics. |
| `parameters.json` | ARM parameters with Azure Gov defaults.                          |
| `deploy.sh`       | End-to-end deploy script (Gov cloud, RG, ACR build/push, deploy). |
| `Dockerfile`      | Hardened multi-stage build for the CITADEL front-end.            |
| `nginx.conf`      | Hardened Nginx config (non-root, security headers, /healthz).    |
| `.dockerignore`   | Build-context exclusions.                                        |

---

*CITADEL — Code Inspection, Threat Analysis & Deployment Evaluation Lab.*
*Deploy responsibly. Treat every uploaded artifact as hostile.*
