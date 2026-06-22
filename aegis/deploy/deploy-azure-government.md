# AEGIS GRC — Azure Government Deployment Guide

Production-grade, FedRAMP-aligned deployment of AEGIS on Microsoft Azure Government
(usgovarizona / usgovvirginia). This guide covers every resource from networking to
observability and includes hardening notes for IL2/IL4/FedRAMP High workloads.

> **Important:** Azure Government is a physically separate cloud from Azure Commercial.
> Every portal URL, CLI endpoint, and service FQDN differs. Always verify you are
> authenticated to `AzureUSGovernment`, not `AzureCloud`.

---

## Table of Contents

1. [Prerequisites](#1-prerequisites)
2. [Architecture Overview](#2-architecture-overview)
3. [Resource Group and Networking](#3-resource-group-and-networking)
4. [Azure Container Registry (ACR)](#4-azure-container-registry-acr)
5. [Azure Database for PostgreSQL Flexible Server](#5-azure-database-for-postgresql-flexible-server)
6. [Azure Key Vault](#6-azure-key-vault)
7. [Azure Blob Storage](#7-azure-blob-storage)
8. [Azure Container Apps](#8-azure-container-apps)
9. [Managed Identity](#9-managed-identity)
10. [Azure Application Gateway with WAF](#10-azure-application-gateway-with-waf)
11. [Azure Monitor and Log Analytics](#11-azure-monitor-and-log-analytics)
12. [Database Migration](#12-database-migration)
13. [SSL/TLS Certificate](#13-ssltls-certificate)
14. [Environment Variable Reference](#14-environment-variable-reference)
15. [FedRAMP / IL Hardening Notes](#15-fedramp--il-hardening-notes)
16. [Compliance Notes](#16-compliance-notes)
17. [Cost Estimate](#17-cost-estimate)
18. [Maintenance and Updates](#18-maintenance-and-updates)
19. [Disaster Recovery](#19-disaster-recovery)

---

## 1. Prerequisites

### Azure Government Subscription

You must have an active **Azure Government** subscription. Access is granted only to
US federal agencies, state/local governments, and approved contractors. The portal is
at **https://portal.azure.us** — not portal.azure.com.

### Configure Azure CLI for Government Cloud

```bash
# Switch the CLI to the Government cloud endpoint
az cloud set --name AzureUSGovernment

# Authenticate (browser-based login)
az login

# Confirm the active cloud
az cloud show --query name -o tsv
# Expected output: AzureUSGovernment

# Set your subscription
az account set --subscription "<YOUR_SUBSCRIPTION_ID>"
az account show --query "{subscription:name, id:id, state:state}"
```

### Required Tools

| Tool | Version | Notes |
|------|---------|-------|
| Azure CLI | ≥ 2.55 | `az version` to check |
| Docker | ≥ 24.0 | For local image builds |
| Terraform | ≥ 1.6 (optional) | For IaC-managed deployments |
| psql | ≥ 16 | For migration validation |
| openssl | any | For certificate operations |

Install CLI extensions needed for this guide:

```bash
az extension add --name containerapp --upgrade
az extension add --name application-gateway-waf-policy --upgrade
az extension add --name log-analytics --upgrade
```

### Government Cloud Endpoint Differences

Azure Government uses distinct endpoints for every service. Key differences:

| Service | Commercial | Government |
|---------|-----------|-----------|
| Portal | portal.azure.com | portal.azure.us |
| ARM | management.azure.com | management.usgovcloudapi.net |
| ACR | *.azurecr.io | *.azurecr.us |
| Blob Storage | *.blob.core.windows.net | *.blob.core.usgovcloudapi.net |
| Key Vault | *.vault.azure.net | *.vault.usgovcloudapi.net |
| Active Directory | login.microsoftonline.com | login.microsoftonline.us |
| Resource Manager | management.azure.com | management.usgovcloudapi.net |

> **Note:** Azure Front Door, Azure CDN (classic), and some preview services are
> **not available** in Azure Government. Use Application Gateway WAF v2 as the
> ingress and DDoS protection layer instead.

### Shell Variables Used Throughout This Guide

Set these once before running any commands:

```bash
export RG="aegis-rg"
export LOCATION="usgovarizona"          # or usgovvirginia
export ACR_NAME="aegisacr"              # globally unique, lowercase, alphanumeric
export VNET_NAME="aegis-vnet"
export PG_SERVER="aegis-pg"
export KV_NAME="aegis-kv"              # globally unique
export STORAGE_ACCOUNT="aegisstorage"  # globally unique, lowercase, ≤24 chars
export ACA_ENV="aegis-env"
export APP_NAME="aegis-app"
export CRON_NAME="aegis-cron"
export APPGW_NAME="aegis-appgw"
export LAW_NAME="aegis-law"
export SUBSCRIPTION=$(az account show --query id -o tsv)
```

---

## 2. Architecture Overview

```
                         ┌─────────────────────────────────────────────────────┐
Internet ──HTTPS──►      │  Azure Application Gateway WAF v2 (Public IP)       │
                         │  • OWASP 3.2 Prevention mode                        │
                         │  • HTTP → HTTPS redirect                            │
                         │  • TLS termination (cert from Key Vault)            │
                         └────────────────────┬────────────────────────────────┘
                                              │ internal HTTPS
                         ┌────────────────────▼────────────────────────────────┐
                         │  Azure Container Apps Environment (internal)        │
                         │  ┌─────────────────┐   ┌──────────────────────┐    │
                         │  │  aegis-app       │   │  aegis-cron          │    │
                         │  │  PHP 8.3/Apache  │   │  PHP 8.3, no ingress │    │
                         │  │  2–10 replicas   │   │  1 replica (singleton│    │
                         │  │  port 80         │   │  run_workflows.php   │    │
                         │  │  /health probe   │   │  dispatch_webhooks   │    │
                         │  └────────┬─────────┘   └──────────┬───────────┘   │
                         └───────────┼──────────────────────── ┼───────────────┘
                                     │                          │
              ┌──────────────────────┼──────────────────────────┘
              │                      │
   ┌──────────▼──────────┐  ┌────────▼──────────────┐  ┌────────────────────┐
   │  Azure Database for  │  │  Azure Blob Storage    │  │  Azure Key Vault   │
   │  PostgreSQL 16        │  │  (private endpoint)    │  │  (private endpoint)│
   │  Flexible Server HA  │  │  *.usgovcloudapi.net   │  │  *.usgovcloudapi   │
   │  (Zone Redundant)    │  │  STORAGE_DRIVER=s3     │  │  All secrets       │
   └─────────────────────┘  └───────────────────────┘  └────────────────────┘

   ┌─────────────────────┐  ┌───────────────────────┐
   │  Azure Container     │  │  Azure Monitor /       │
   │  Registry (ACR)      │  │  Log Analytics         │
   │  Private endpoint    │  │  90-day retention      │
   └─────────────────────┘  └───────────────────────┘
```

### Services Summary

| Service | Purpose | SKU |
|---------|---------|-----|
| Application Gateway | WAF, TLS termination, ingress | WAF_v2 |
| Container Apps | Run aegis-app and aegis-cron | Consumption / Dedicated |
| Container Registry | Store Docker images | Premium |
| PostgreSQL Flexible Server | Primary database | Standard_D4ds_v4 |
| Key Vault | Secrets and certificates | Premium (HSM-backed) |
| Blob Storage | File uploads (S3-compat) | Standard ZRS |
| Log Analytics Workspace | Centralized logging | Pay-per-GB |
| Virtual Network | Network isolation | — |
| Managed Identity | Passwordless auth to Azure services | System-assigned |

---

## 3. Resource Group and Networking

### 3.1 Create Resource Group

```bash
az group create \
  --name "$RG" \
  --location "$LOCATION" \
  --tags environment=production application=aegis classification=controlled
```

### 3.2 Virtual Network and Subnets

AEGIS requires four subnets with non-overlapping address spaces:

| Subnet | CIDR | Purpose |
|--------|------|---------|
| gateway-subnet | 10.0.0.0/24 | Application Gateway (must be named `default` is not required; just dedicated) |
| app-subnet | 10.0.1.0/23 | Container Apps Environment (needs /23 minimum) |
| db-subnet | 10.0.3.0/24 | PostgreSQL delegated subnet |
| private-endpoints-subnet | 10.0.4.0/24 | ACR, Key Vault, Blob private endpoints |

```bash
# Create the VNet
az network vnet create \
  --resource-group "$RG" \
  --name "$VNET_NAME" \
  --address-prefix 10.0.0.0/16 \
  --location "$LOCATION"

# Application Gateway subnet
az network vnet subnet create \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --name gateway-subnet \
  --address-prefix 10.0.0.0/24

# Container Apps subnet (minimum /23 for ACA environment)
az network vnet subnet create \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --name app-subnet \
  --address-prefix 10.0.1.0/23

# PostgreSQL delegated subnet
az network vnet subnet create \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --name db-subnet \
  --address-prefix 10.0.3.0/24 \
  --delegations Microsoft.DBforPostgreSQL/flexibleServers

# Private endpoints subnet
az network vnet subnet create \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --name private-endpoints-subnet \
  --address-prefix 10.0.4.0/24 \
  --disable-private-endpoint-network-policies true
```

### 3.3 Network Security Groups

```bash
# --- NSG: Application Gateway ---
az network nsg create --resource-group "$RG" --name nsg-gateway --location "$LOCATION"

# Required: allow GatewayManager (Azure infrastructure) on 65200-65535
az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-gateway --name AllowGatewayManager \
  --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --destination-port-ranges 65200-65535 \
  --source-address-prefixes GatewayManager --destination-address-prefixes '*'

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-gateway --name AllowHTTPS \
  --priority 110 --direction Inbound --access Allow \
  --protocol Tcp --destination-port-ranges 443 80 \
  --source-address-prefixes Internet --destination-address-prefixes '*'

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-gateway --name AllowAzureLoadBalancer \
  --priority 120 --direction Inbound --access Allow \
  --protocol '*' --destination-port-ranges '*' \
  --source-address-prefixes AzureLoadBalancer --destination-address-prefixes '*'

az network vnet subnet update \
  --resource-group "$RG" --vnet-name "$VNET_NAME" \
  --name gateway-subnet --network-security-group nsg-gateway

# --- NSG: App Subnet ---
az network nsg create --resource-group "$RG" --name nsg-app --location "$LOCATION"

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-app --name AllowFromGateway \
  --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --destination-port-ranges 80 443 \
  --source-address-prefixes 10.0.0.0/24 --destination-address-prefixes '*'

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-app --name DenyPublicInbound \
  --priority 4000 --direction Inbound --access Deny \
  --protocol '*' --destination-port-ranges '*' \
  --source-address-prefixes Internet --destination-address-prefixes '*'

az network vnet subnet update \
  --resource-group "$RG" --vnet-name "$VNET_NAME" \
  --name app-subnet --network-security-group nsg-app

# --- NSG: DB Subnet ---
az network nsg create --resource-group "$RG" --name nsg-db --location "$LOCATION"

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-db --name AllowPostgresFromApp \
  --priority 100 --direction Inbound --access Allow \
  --protocol Tcp --destination-port-ranges 5432 \
  --source-address-prefixes 10.0.1.0/23 --destination-address-prefixes '*'

az network nsg rule create \
  --resource-group "$RG" --nsg-name nsg-db --name DenyAll \
  --priority 4000 --direction Inbound --access Deny \
  --protocol '*' --destination-port-ranges '*' \
  --source-address-prefixes '*' --destination-address-prefixes '*'

az network vnet subnet update \
  --resource-group "$RG" --vnet-name "$VNET_NAME" \
  --name db-subnet --network-security-group nsg-db
```

### 3.4 Private DNS Zones

Private DNS zones are required for private endpoints to resolve correctly inside the VNet.

```bash
for zone in \
    "privatelink.azurecr.us" \
    "privatelink.vaultcore.usgovcloudapi.net" \
    "privatelink.blob.core.usgovcloudapi.net" \
    "privatelink.postgres.database.usgovcloudapi.net"; do

  az network private-dns zone create \
    --resource-group "$RG" --name "$zone"

  az network private-dns link vnet create \
    --resource-group "$RG" \
    --zone-name "$zone" \
    --name "link-${zone//\./-}" \
    --virtual-network "$VNET_NAME" \
    --registration-enabled false
done
```

---

## 4. Azure Container Registry (ACR)

> **Note:** Use the **Premium** SKU in Government cloud. It is required for private
> endpoints, content trust, and geo-replication. Lower SKUs do not support private
> networking.

### 4.1 Create the Registry

```bash
az acr create \
  --resource-group "$RG" \
  --name "$ACR_NAME" \
  --sku Premium \
  --location "$LOCATION" \
  --admin-enabled false \
  --public-network-enabled false \
  --zone-redundancy Enabled
```

### 4.2 Enable Vulnerability Scanning (Defender for Containers)

```bash
# Enable Defender for Containers at the subscription level
az security pricing create \
  --name Containers \
  --tier Standard
```

### 4.3 Private Endpoint for ACR

```bash
ACR_ID=$(az acr show --name "$ACR_NAME" --resource-group "$RG" --query id -o tsv)

az network private-endpoint create \
  --name pe-acr \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --subnet private-endpoints-subnet \
  --private-connection-resource-id "$ACR_ID" \
  --group-id registry \
  --connection-name acr-connection \
  --location "$LOCATION"

# Register in private DNS
az network private-endpoint dns-zone-group create \
  --resource-group "$RG" \
  --endpoint-name pe-acr \
  --name acr-dns-group \
  --private-dns-zone "privatelink.azurecr.us" \
  --zone-name acr
```

### 4.4 Build and Push the AEGIS Image

**Option A — Azure ACR Build (no local Docker required):**

```bash
# From the aegis/ directory
cd /path/to/aegis

az acr build \
  --registry "$ACR_NAME" \
  --image "aegis:latest" \
  --image "aegis:$(git rev-parse --short HEAD)" \
  --file Dockerfile \
  .
```

**Option B — Local Docker build then push:**

```bash
az acr login --name "$ACR_NAME"

docker build \
  -t "${ACR_NAME}.azurecr.us/aegis:latest" \
  -t "${ACR_NAME}.azurecr.us/aegis:$(git rev-parse --short HEAD)" \
  -f Dockerfile \
  .

docker push "${ACR_NAME}.azurecr.us/aegis:latest"
docker push "${ACR_NAME}.azurecr.us/aegis:$(git rev-parse --short HEAD)"
```

> **Note:** Tag images with the Git commit SHA in addition to `latest` so that
> deployments are traceable and rollbacks are unambiguous.

---

## 5. Azure Database for PostgreSQL Flexible Server

### 5.1 Create the Server

```bash
# DNS zone for PostgreSQL private access
az network private-dns zone create \
  --resource-group "$RG" \
  --name "${PG_SERVER}.private.postgres.database.usgovcloudapi.net"

az network private-dns link vnet create \
  --resource-group "$RG" \
  --zone-name "${PG_SERVER}.private.postgres.database.usgovcloudapi.net" \
  --name pg-dns-link \
  --virtual-network "$VNET_NAME" \
  --registration-enabled false

# Generate a strong password and store it immediately
DB_PASSWORD=$(openssl rand -base64 32)
echo "Save this password — it will be stored in Key Vault: $DB_PASSWORD"

az postgres flexible-server create \
  --resource-group "$RG" \
  --name "$PG_SERVER" \
  --location "$LOCATION" \
  --admin-user aegis_admin \
  --admin-password "$DB_PASSWORD" \
  --sku-name Standard_D4ds_v4 \
  --tier GeneralPurpose \
  --version 16 \
  --storage-size 128 \
  --storage-auto-grow Enabled \
  --high-availability ZoneRedundant \
  --vnet "$VNET_NAME" \
  --subnet db-subnet \
  --private-dns-zone "${PG_SERVER}.private.postgres.database.usgovcloudapi.net" \
  --backup-retention 35 \
  --geo-redundant-backup Enabled \
  --yes
```

> **Note:** `Standard_D4ds_v4` (4 vCores, 16 GB RAM) is the minimum recommended
> SKU for a production AEGIS instance with multiple concurrent users. Scale up to
> `Standard_D8ds_v4` for > 100 concurrent users or large POAM/SSP datasets.

### 5.2 Create the Application Database and User

```bash
# Connect via psql (from a jumpbox or Azure Cloud Shell in the same VNet)
psql "host=${PG_SERVER}.postgres.database.usgovcloudapi.net \
      port=5432 \
      dbname=postgres \
      user=aegis_admin \
      sslmode=require"
```

```sql
-- Run inside psql
CREATE DATABASE aegis;
CREATE USER aegis WITH ENCRYPTED PASSWORD '<APP_DB_PASSWORD>';
GRANT ALL PRIVILEGES ON DATABASE aegis TO aegis;
\c aegis
CREATE SCHEMA IF NOT EXISTS aegis;
GRANT ALL PRIVILEGES ON SCHEMA aegis TO aegis;
\q
```

### 5.3 SSL and Server Parameters

```bash
# Enforce SSL — already on by default; confirm:
az postgres flexible-server parameter show \
  --resource-group "$RG" \
  --server-name "$PG_SERVER" \
  --name require_secure_transport \
  --query value

# Recommended parameters for AEGIS workload
az postgres flexible-server parameter set \
  --resource-group "$RG" \
  --server-name "$PG_SERVER" \
  --name log_connections --value on

az postgres flexible-server parameter set \
  --resource-group "$RG" \
  --server-name "$PG_SERVER" \
  --name log_disconnections --value on

az postgres flexible-server parameter set \
  --resource-group "$RG" \
  --server-name "$PG_SERVER" \
  --name log_duration --value on

az postgres flexible-server parameter set \
  --resource-group "$RG" \
  --server-name "$PG_SERVER" \
  --name connection_throttling --value on
```

### 5.4 Customer-Managed Key (CMK) Encryption

```bash
# After Key Vault is created (Section 6), return here to enable CMK.
# Create a key in Key Vault:
az keyvault key create \
  --vault-name "$KV_NAME" \
  --name aegis-pg-cmk \
  --kty RSA \
  --size 4096 \
  --ops wrapKey unwrapKey get

KEY_URI=$(az keyvault key show \
  --vault-name "$KV_NAME" --name aegis-pg-cmk \
  --query key.kid -o tsv)

# Create a user-assigned managed identity for PostgreSQL encryption
az identity create --name aegis-pg-identity --resource-group "$RG"
PG_IDENTITY_ID=$(az identity show --name aegis-pg-identity --resource-group "$RG" --query id -o tsv)
PG_IDENTITY_PRINCIPAL=$(az identity show --name aegis-pg-identity --resource-group "$RG" --query principalId -o tsv)

# Grant the identity access to the key
az keyvault set-policy \
  --name "$KV_NAME" \
  --object-id "$PG_IDENTITY_PRINCIPAL" \
  --key-permissions get wrapKey unwrapKey

# Enable CMK on the PostgreSQL server
az postgres flexible-server update \
  --resource-group "$RG" \
  --name "$PG_SERVER" \
  --key "$KEY_URI" \
  --identity "$PG_IDENTITY_ID"
```

---

## 6. Azure Key Vault

> **Note:** Use the **Premium** SKU to access HSM-backed keys, required for
> FedRAMP High FIPS 140-2 Level 3 key storage.

### 6.1 Create Key Vault

```bash
az keyvault create \
  --resource-group "$RG" \
  --name "$KV_NAME" \
  --location "$LOCATION" \
  --sku Premium \
  --enable-rbac-authorization true \
  --enable-soft-delete true \
  --soft-delete-retention-days 90 \
  --enable-purge-protection true \
  --public-network-access Disabled
```

### 6.2 Private Endpoint for Key Vault

```bash
KV_ID=$(az keyvault show --name "$KV_NAME" --resource-group "$RG" --query id -o tsv)

az network private-endpoint create \
  --name pe-keyvault \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --subnet private-endpoints-subnet \
  --private-connection-resource-id "$KV_ID" \
  --group-id vault \
  --connection-name kv-connection \
  --location "$LOCATION"

az network private-endpoint dns-zone-group create \
  --resource-group "$RG" \
  --endpoint-name pe-keyvault \
  --name kv-dns-group \
  --private-dns-zone "privatelink.vaultcore.usgovcloudapi.net" \
  --zone-name vault
```

### 6.3 Store All Application Secrets

```bash
# Grant yourself rights to set secrets (replace with your AAD object ID)
MY_OBJECT_ID=$(az ad signed-in-user show --query id -o tsv)
az role assignment create \
  --role "Key Vault Secrets Officer" \
  --assignee "$MY_OBJECT_ID" \
  --scope "$KV_ID"

# Database password (use the value generated in Section 5.1)
az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "DB-PASS" \
  --value "$DB_PASSWORD"

# Application database user password (may differ from admin password)
APP_DB_PASSWORD=$(openssl rand -base64 32)
az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "APP-DB-PASS" \
  --value "$APP_DB_PASSWORD"

# JWT signing secret (minimum 256-bit / 32 bytes)
JWT_SECRET=$(openssl rand -base64 48)
az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "JWT-SECRET" \
  --value "$JWT_SECRET"

# SMTP credentials
az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "SMTP-HOST" \
  --value "smtp.youragency.gov"

az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "SMTP-USER" \
  --value "aegis-notifications@youragency.gov"

az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "SMTP-PASS" \
  --value "<SMTP_PASSWORD>"

# AI / LLM API key (if using AI-assisted features)
az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "AI-API-KEY" \
  --value "<AI_API_KEY>"

# Storage account key (populated after Section 7)
# az keyvault secret set --vault-name "$KV_NAME" --name "STORAGE-ACCOUNT-KEY" --value "<KEY>"
```

### 6.4 Enable Diagnostic Logging on Key Vault

```bash
LAW_ID=$(az monitor log-analytics workspace show \
  --resource-group "$RG" --workspace-name "$LAW_NAME" \
  --query id -o tsv 2>/dev/null || echo "create-law-first")

az monitor diagnostic-settings create \
  --name kv-diag \
  --resource "$KV_ID" \
  --workspace "$LAW_ID" \
  --logs '[{"category":"AuditEvent","enabled":true},{"category":"AzurePolicyEvaluationDetails","enabled":true}]' \
  --metrics '[{"category":"AllMetrics","enabled":true}]'
```

---

## 7. Azure Blob Storage

AEGIS uses an S3-compatible interface (`STORAGE_DRIVER=s3`). Azure Blob Storage exposes
an S3-compatible API at the Government endpoint, which is used via the MinIO Gateway
pattern or directly through the Azure Blob S3 compatibility layer.

### 7.1 Create Storage Account

```bash
az storage account create \
  --resource-group "$RG" \
  --name "$STORAGE_ACCOUNT" \
  --location "$LOCATION" \
  --sku Standard_ZRS \
  --kind StorageV2 \
  --access-tier Hot \
  --allow-blob-public-access false \
  --https-only true \
  --min-tls-version TLS1_2 \
  --default-action Deny \
  --bypass AzureServices

# Create the uploads container
az storage container create \
  --account-name "$STORAGE_ACCOUNT" \
  --name aegis-uploads \
  --public-access off \
  --auth-mode login
```

### 7.2 Private Endpoint for Blob Storage

```bash
STORAGE_ID=$(az storage account show \
  --name "$STORAGE_ACCOUNT" --resource-group "$RG" --query id -o tsv)

az network private-endpoint create \
  --name pe-storage \
  --resource-group "$RG" \
  --vnet-name "$VNET_NAME" \
  --subnet private-endpoints-subnet \
  --private-connection-resource-id "$STORAGE_ID" \
  --group-id blob \
  --connection-name storage-connection \
  --location "$LOCATION"

az network private-endpoint dns-zone-group create \
  --resource-group "$RG" \
  --endpoint-name pe-storage \
  --name storage-dns-group \
  --private-dns-zone "privatelink.blob.core.usgovcloudapi.net" \
  --zone-name blob
```

### 7.3 Retrieve Storage Key for Key Vault

```bash
STORAGE_KEY=$(az storage account keys list \
  --account-name "$STORAGE_ACCOUNT" \
  --resource-group "$RG" \
  --query "[0].value" -o tsv)

az keyvault secret set \
  --vault-name "$KV_NAME" \
  --name "STORAGE-ACCOUNT-KEY" \
  --value "$STORAGE_KEY"
```

### 7.4 Configure AEGIS for Azure Blob (S3-Compatible Interface)

Set the following environment variables in Container Apps (see Section 8). The Azure
Blob S3 compatibility endpoint in Government cloud is:

```
https://<STORAGE_ACCOUNT>.blob.core.usgovcloudapi.net
```

| Variable | Value |
|----------|-------|
| `STORAGE_DRIVER` | `s3` |
| `S3_ENDPOINT` | `https://<STORAGE_ACCOUNT>.blob.core.usgovcloudapi.net` |
| `S3_BUCKET` | `aegis-uploads` |
| `S3_ACCESS_KEY` | Storage account name (`$STORAGE_ACCOUNT`) |
| `S3_SECRET_KEY` | Storage account key (from Key Vault) |
| `S3_REGION` | `usgovarizona` (or your region) |
| `S3_USE_PATH_STYLE` | `true` (required for Azure Blob S3 compatibility) |

> **Note:** The current AEGIS codebase uses an S3 SDK. Azure Blob Storage's native
> S3-compatible API (accessed via path-style requests) handles this without any
> MinIO Gateway sidecar. If you encounter S3 SDK compatibility issues with specific
> operations (e.g., multipart uploads or server-side copy), deploy a MinIO Gateway
> container as a sidecar within the Container Apps environment pointing to Azure Blob,
> and update `S3_ENDPOINT` to the MinIO Gateway's internal URL. A future enhancement
> would replace the S3 SDK with the official Azure Blob SDK for native integration.

### 7.5 Lifecycle Policy (Optional)

```bash
az storage account management-policy create \
  --account-name "$STORAGE_ACCOUNT" \
  --resource-group "$RG" \
  --policy '{
    "rules": [{
      "name": "archive-old-evidence",
      "enabled": true,
      "type": "Lifecycle",
      "definition": {
        "filters": {"blobTypes": ["blockBlob"], "prefixMatch": ["aegis-uploads/"]},
        "actions": {
          "baseBlob": {
            "tierToCool": {"daysAfterModificationGreaterThan": 90},
            "tierToArchive": {"daysAfterModificationGreaterThan": 365}
          }
        }
      }
    }]
  }'
```

---

## 8. Azure Container Apps

### 8.1 Create Log Analytics Workspace (Required for ACA)

```bash
az monitor log-analytics workspace create \
  --resource-group "$RG" \
  --workspace-name "$LAW_NAME" \
  --location "$LOCATION" \
  --retention-time 90 \
  --sku PerGB2018

LAW_ID=$(az monitor log-analytics workspace show \
  --resource-group "$RG" --workspace-name "$LAW_NAME" --query id -o tsv)
LAW_KEY=$(az monitor log-analytics workspace get-shared-keys \
  --resource-group "$RG" --workspace-name "$LAW_NAME" --query primarySharedKey -o tsv)
```

### 8.2 Create Container Apps Environment

```bash
APP_SUBNET_ID=$(az network vnet subnet show \
  --resource-group "$RG" --vnet-name "$VNET_NAME" --name app-subnet --query id -o tsv)

az containerapp env create \
  --name "$ACA_ENV" \
  --resource-group "$RG" \
  --location "$LOCATION" \
  --infrastructure-subnet-resource-id "$APP_SUBNET_ID" \
  --internal-only true \
  --logs-workspace-id "$LAW_ID" \
  --logs-workspace-key "$LAW_KEY"

# Get the environment's internal static IP (used for Application Gateway backend)
ACA_STATIC_IP=$(az containerapp env show \
  --name "$ACA_ENV" --resource-group "$RG" \
  --query properties.staticIp -o tsv)

ACA_FQDN=$(az containerapp env show \
  --name "$ACA_ENV" --resource-group "$RG" \
  --query properties.defaultDomain -o tsv)

echo "Container Apps internal IP: $ACA_STATIC_IP"
echo "Container Apps default domain: $ACA_FQDN"
```

### 8.3 Build Key Vault Secret References

Container Apps injects Key Vault secrets via secret references. The managed identity
must have `Key Vault Secrets User` rights (granted in Section 9).

```bash
# The identity client ID is needed for Key Vault references — assign identity first
# (full identity setup in Section 9; reference back here for the create command)

# Gather Key Vault secret URIs
KV_URI=$(az keyvault show --name "$KV_NAME" --query properties.vaultUri -o tsv)

PG_HOST="${PG_SERVER}.postgres.database.usgovcloudapi.net"
```

### 8.4 Deploy aegis-app (Web Application)

```bash
az containerapp create \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --environment "$ACA_ENV" \
  --image "${ACR_NAME}.azurecr.us/aegis:latest" \
  --registry-server "${ACR_NAME}.azurecr.us" \
  --registry-identity system \
  --cpu 1.0 \
  --memory 2Gi \
  --min-replicas 2 \
  --max-replicas 10 \
  --target-port 80 \
  --ingress external \
  --transport http \
  --secrets \
    "db-pass=keyvaultref:${KV_URI}secrets/APP-DB-PASS,identityref:system" \
    "jwt-secret=keyvaultref:${KV_URI}secrets/JWT-SECRET,identityref:system" \
    "smtp-pass=keyvaultref:${KV_URI}secrets/SMTP-PASS,identityref:system" \
    "ai-api-key=keyvaultref:${KV_URI}secrets/AI-API-KEY,identityref:system" \
    "storage-key=keyvaultref:${KV_URI}secrets/STORAGE-ACCOUNT-KEY,identityref:system" \
  --env-vars \
    "DB_HOST=${PG_HOST}" \
    "DB_PORT=5432" \
    "DB_NAME=aegis" \
    "DB_USER=aegis" \
    "DB_PASS=secretref:db-pass" \
    "APP_URL=https://aegis.youragency.gov" \
    "APP_ENV=production" \
    "JWT_SECRET=secretref:jwt-secret" \
    "SMTP_HOST=$(az keyvault secret show --vault-name $KV_NAME --name SMTP-HOST --query value -o tsv)" \
    "SMTP_PORT=587" \
    "SMTP_USER=$(az keyvault secret show --vault-name $KV_NAME --name SMTP-USER --query value -o tsv)" \
    "SMTP_PASS=secretref:smtp-pass" \
    "SMTP_FROM=aegis-notifications@youragency.gov" \
    "STORAGE_DRIVER=s3" \
    "S3_ENDPOINT=https://${STORAGE_ACCOUNT}.blob.core.usgovcloudapi.net" \
    "S3_BUCKET=aegis-uploads" \
    "S3_ACCESS_KEY=${STORAGE_ACCOUNT}" \
    "S3_SECRET_KEY=secretref:storage-key" \
    "S3_REGION=${LOCATION}" \
    "S3_USE_PATH_STYLE=true" \
    "AI_API_KEY=secretref:ai-api-key" \
  --system-assigned \
  --scale-rule-name http-scaling \
  --scale-rule-type http \
  --scale-rule-metadata concurrentRequests=50
```

Add health probe:

```bash
az containerapp update \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --set-env-vars "" \
  --yaml - <<'EOF'
properties:
  template:
    containers:
      - name: aegis-app
        probes:
          - type: Liveness
            httpGet:
              path: /health
              port: 80
            initialDelaySeconds: 30
            periodSeconds: 30
            failureThreshold: 3
          - type: Readiness
            httpGet:
              path: /health
              port: 80
            initialDelaySeconds: 20
            periodSeconds: 10
            failureThreshold: 3
EOF
```

### 8.5 Deploy aegis-cron (Background Worker)

The cron container replicates the Docker Compose `cron` service: it runs
`run_workflows.php` and `dispatch_webhooks.php` in a loop every 60 seconds.

```bash
az containerapp create \
  --name "$CRON_NAME" \
  --resource-group "$RG" \
  --environment "$ACA_ENV" \
  --image "${ACR_NAME}.azurecr.us/aegis:latest" \
  --registry-server "${ACR_NAME}.azurecr.us" \
  --registry-identity system \
  --cpu 0.5 \
  --memory 1Gi \
  --min-replicas 1 \
  --max-replicas 1 \
  --ingress disabled \
  --args \
    "/bin/sh" \
    "-c" \
    "while true; do php /var/www/html/scripts/run_workflows.php >> /dev/stdout 2>&1; php /var/www/html/scripts/dispatch_webhooks.php >> /dev/stdout 2>&1; sleep 60; done" \
  --secrets \
    "db-pass=keyvaultref:${KV_URI}secrets/APP-DB-PASS,identityref:system" \
    "jwt-secret=keyvaultref:${KV_URI}secrets/JWT-SECRET,identityref:system" \
    "ai-api-key=keyvaultref:${KV_URI}secrets/AI-API-KEY,identityref:system" \
  --env-vars \
    "DB_HOST=${PG_HOST}" \
    "DB_PORT=5432" \
    "DB_NAME=aegis" \
    "DB_USER=aegis" \
    "DB_PASS=secretref:db-pass" \
    "APP_URL=https://aegis.youragency.gov" \
    "APP_ENV=production" \
    "JWT_SECRET=secretref:jwt-secret" \
    "AI_API_KEY=secretref:ai-api-key" \
  --system-assigned
```

---

## 9. Managed Identity

### 9.1 Grant ACR Pull

```bash
ACR_ID=$(az acr show --name "$ACR_NAME" --resource-group "$RG" --query id -o tsv)

APP_PRINCIPAL=$(az containerapp show \
  --name "$APP_NAME" --resource-group "$RG" \
  --query identity.principalId -o tsv)

CRON_PRINCIPAL=$(az containerapp show \
  --name "$CRON_NAME" --resource-group "$RG" \
  --query identity.principalId -o tsv)

az role assignment create \
  --role "AcrPull" \
  --assignee "$APP_PRINCIPAL" \
  --scope "$ACR_ID"

az role assignment create \
  --role "AcrPull" \
  --assignee "$CRON_PRINCIPAL" \
  --scope "$ACR_ID"
```

### 9.2 Grant Key Vault Secrets User

```bash
KV_ID=$(az keyvault show --name "$KV_NAME" --resource-group "$RG" --query id -o tsv)

az role assignment create \
  --role "Key Vault Secrets User" \
  --assignee "$APP_PRINCIPAL" \
  --scope "$KV_ID"

az role assignment create \
  --role "Key Vault Secrets User" \
  --assignee "$CRON_PRINCIPAL" \
  --scope "$KV_ID"
```

### 9.3 Grant Storage Blob Data Contributor

```bash
STORAGE_ID=$(az storage account show \
  --name "$STORAGE_ACCOUNT" --resource-group "$RG" --query id -o tsv)

az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee "$APP_PRINCIPAL" \
  --scope "$STORAGE_ID"

az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee "$CRON_PRINCIPAL" \
  --scope "$STORAGE_ID"
```

### 9.4 Verify Role Assignments

```bash
az role assignment list \
  --assignee "$APP_PRINCIPAL" \
  --output table \
  --query "[].{Role:roleDefinitionName, Scope:scope}"
```

---

## 10. Azure Application Gateway with WAF

> **Note:** Azure Front Door is **not available** in Azure Government. Application
> Gateway WAF v2 is the recommended perimeter ingress and DDoS protection layer.
> Deploy Application Gateway in the `gateway-subnet`.

### 10.1 Public IP

```bash
az network public-ip create \
  --resource-group "$RG" \
  --name aegis-appgw-pip \
  --location "$LOCATION" \
  --sku Standard \
  --allocation-method Static \
  --zone 1 2 3
```

### 10.2 WAF Policy

```bash
az network application-gateway waf-policy create \
  --name aegis-waf-policy \
  --resource-group "$RG" \
  --location "$LOCATION"

# Set OWASP 3.2 rule set in Prevention mode
az network application-gateway waf-policy managed-rule rule-set add \
  --policy-name aegis-waf-policy \
  --resource-group "$RG" \
  --type OWASP \
  --version 3.2

az network application-gateway waf-policy update \
  --name aegis-waf-policy \
  --resource-group "$RG" \
  --set policySettings.mode=Prevention \
  --set policySettings.state=Enabled \
  --set policySettings.requestBodyCheck=true \
  --set policySettings.maxRequestBodySizeInKb=128 \
  --set policySettings.fileUploadLimitInMb=100

# Rate-limiting custom rule: max 1000 req/5 min per source IP
az network application-gateway waf-policy custom-rule create \
  --policy-name aegis-waf-policy \
  --resource-group "$RG" \
  --name RateLimitPerIP \
  --priority 10 \
  --rule-type RateLimitRule \
  --action Block \
  --rate-limit-duration FiveMins \
  --rate-limit-threshold 1000 \
  --match-conditions '[{
    "matchVariables": [{"variableName": "RemoteAddr"}],
    "operator": "IPMatch",
    "matchValues": ["0.0.0.0/0"]
  }]'
```

### 10.3 SSL Certificate from Key Vault

```bash
# Store your PFX certificate in Key Vault (or use a managed certificate)
az keyvault certificate import \
  --vault-name "$KV_NAME" \
  --name aegis-tls-cert \
  --file aegis.youragency.gov.pfx \
  --password "<PFX_PASSWORD>"

CERT_SECRET_ID=$(az keyvault certificate show \
  --vault-name "$KV_NAME" --name aegis-tls-cert \
  --query sid -o tsv)
```

### 10.4 Create Application Gateway

```bash
GATEWAY_SUBNET_ID=$(az network vnet subnet show \
  --resource-group "$RG" --vnet-name "$VNET_NAME" --name gateway-subnet --query id -o tsv)

WAF_POLICY_ID=$(az network application-gateway waf-policy show \
  --name aegis-waf-policy --resource-group "$RG" --query id -o tsv)

# The backend FQDN is the Container Apps environment's internal domain
BACKEND_FQDN="${APP_NAME}.${ACA_FQDN}"

az network application-gateway create \
  --name "$APPGW_NAME" \
  --resource-group "$RG" \
  --location "$LOCATION" \
  --sku WAF_v2 \
  --capacity 2 \
  --vnet-name "$VNET_NAME" \
  --subnet gateway-subnet \
  --public-ip-address aegis-appgw-pip \
  --frontend-port 443 \
  --http-settings-port 80 \
  --http-settings-protocol Http \
  --routing-rule-type Basic \
  --priority 100 \
  --waf-policy "$WAF_POLICY_ID" \
  --servers "$BACKEND_FQDN" \
  --key-vault-secret-id "$CERT_SECRET_ID"

# HTTP → HTTPS redirect listener and rule
az network application-gateway frontend-port create \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name port80 --port 80

az network application-gateway http-listener create \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name http-listener \
  --frontend-ip appGatewayFrontendIP \
  --frontend-port port80

az network application-gateway redirect-config create \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name https-redirect \
  --type Permanent \
  --target-listener appGatewayHttpListener

az network application-gateway rule create \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name http-to-https \
  --http-listener http-listener \
  --redirect-config https-redirect \
  --rule-type Basic \
  --priority 50
```

### 10.5 Health Probe for Container Apps Backend

```bash
az network application-gateway probe create \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name aegis-health-probe \
  --protocol Http \
  --host "$BACKEND_FQDN" \
  --path /health \
  --interval 30 \
  --timeout 30 \
  --threshold 3

az network application-gateway http-settings update \
  --gateway-name "$APPGW_NAME" \
  --resource-group "$RG" \
  --name appGatewayBackendHttpSettings \
  --probe aegis-health-probe \
  --host-name-from-backend-pool true
```

---

## 11. Azure Monitor and Log Analytics

### 11.1 Diagnostic Settings for All Services

```bash
LAW_ID=$(az monitor log-analytics workspace show \
  --resource-group "$RG" --workspace-name "$LAW_NAME" --query id -o tsv)

# Application Gateway diagnostics
APPGW_ID=$(az network application-gateway show \
  --name "$APPGW_NAME" --resource-group "$RG" --query id -o tsv)

az monitor diagnostic-settings create \
  --name appgw-diag \
  --resource "$APPGW_ID" \
  --workspace "$LAW_ID" \
  --logs '[
    {"category":"ApplicationGatewayAccessLog","enabled":true},
    {"category":"ApplicationGatewayPerformanceLog","enabled":true},
    {"category":"ApplicationGatewayFirewallLog","enabled":true}
  ]' \
  --metrics '[{"category":"AllMetrics","enabled":true}]'

# PostgreSQL diagnostics
PG_ID=$(az postgres flexible-server show \
  --name "$PG_SERVER" --resource-group "$RG" --query id -o tsv)

az monitor diagnostic-settings create \
  --name pg-diag \
  --resource "$PG_ID" \
  --workspace "$LAW_ID" \
  --logs '[
    {"category":"PostgreSQLLogs","enabled":true},
    {"category":"PostgreSQLFlexSessions","enabled":true},
    {"category":"PostgreSQLFlexQueryStoreRuntime","enabled":true}
  ]' \
  --metrics '[{"category":"AllMetrics","enabled":true}]'

# Storage Account diagnostics
az monitor diagnostic-settings create \
  --name storage-diag \
  --resource "${STORAGE_ID}/blobServices/default" \
  --workspace "$LAW_ID" \
  --logs '[
    {"category":"StorageRead","enabled":true},
    {"category":"StorageWrite","enabled":true},
    {"category":"StorageDelete","enabled":true}
  ]' \
  --metrics '[{"category":"Transaction","enabled":true}]'
```

### 11.2 Alerts

```bash
# Action group for notifications
az monitor action-group create \
  --resource-group "$RG" \
  --name aegis-ops \
  --short-name aegisops \
  --email-receiver name=ops-team email=ops@youragency.gov

ACTION_GROUP_ID=$(az monitor action-group show \
  --name aegis-ops --resource-group "$RG" --query id -o tsv)

# Alert: Container App CPU > 80%
az monitor metrics alert create \
  --name "aegis-app-high-cpu" \
  --resource-group "$RG" \
  --scopes "$(az containerapp show --name $APP_NAME --resource-group $RG --query id -o tsv)" \
  --condition "avg UsageNanoCores > 800000000" \
  --window-size 5m \
  --evaluation-frequency 1m \
  --severity 2 \
  --action "$ACTION_GROUP_ID" \
  --description "AEGIS app CPU utilization exceeds 80%"

# Alert: Application Gateway 5xx rate > 1%
az monitor metrics alert create \
  --name "aegis-appgw-5xx" \
  --resource-group "$RG" \
  --scopes "$APPGW_ID" \
  --condition "avg FailedRequests > 10" \
  --window-size 5m \
  --evaluation-frequency 1m \
  --severity 1 \
  --action "$ACTION_GROUP_ID" \
  --description "Application Gateway 5xx error rate elevated"

# Alert: Key Vault secret access failures (log-based)
az monitor scheduled-query create \
  --name "aegis-kv-access-failures" \
  --resource-group "$RG" \
  --scopes "$KV_ID" \
  --condition-query \
    'AzureDiagnostics | where ResourceType == "VAULTS" and ResultType == "Failure" | count' \
  --condition-threshold 5 \
  --condition-time-aggregation Count \
  --evaluation-frequency 5m \
  --window-duration 5m \
  --severity 1 \
  --action-groups "$ACTION_GROUP_ID" \
  --description "Key Vault secret access failures detected"

# Alert: PostgreSQL connection failures
az monitor scheduled-query create \
  --name "aegis-pg-conn-failures" \
  --resource-group "$RG" \
  --scopes "$PG_ID" \
  --condition-query \
    'AzureDiagnostics | where Category == "PostgreSQLLogs" and Message has "connection" and Message has "error" | count' \
  --condition-threshold 10 \
  --condition-time-aggregation Count \
  --evaluation-frequency 5m \
  --window-duration 5m \
  --severity 2 \
  --action-groups "$ACTION_GROUP_ID" \
  --description "PostgreSQL connection failures detected"
```

### 11.3 Application Insights (Optional but Recommended)

```bash
az monitor app-insights component create \
  --app aegis-insights \
  --resource-group "$RG" \
  --location "$LOCATION" \
  --workspace "$LAW_ID" \
  --kind web \
  --application-type web

APPINSIGHTS_KEY=$(az monitor app-insights component show \
  --app aegis-insights --resource-group "$RG" \
  --query instrumentationKey -o tsv)

# Add to aegis-app environment variables
az containerapp update \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --set-env-vars "APPINSIGHTS_INSTRUMENTATIONKEY=${APPINSIGHTS_KEY}"
```

---

## 12. Database Migration

AEGIS includes 019 SQL migration files in `database/migrations/`. Apply them in order
after the Container App is running.

### Option A — Container Apps Job (Recommended for CI/CD)

```bash
az containerapp job create \
  --name aegis-migrate \
  --resource-group "$RG" \
  --environment "$ACA_ENV" \
  --trigger-type Manual \
  --replica-timeout 300 \
  --image "${ACR_NAME}.azurecr.us/aegis:latest" \
  --registry-server "${ACR_NAME}.azurecr.us" \
  --registry-identity system \
  --cpu 0.5 \
  --memory 1Gi \
  --args "/bin/sh" "-c" "php /var/www/html/install.php" \
  --secrets \
    "db-pass=keyvaultref:${KV_URI}secrets/APP-DB-PASS,identityref:system" \
  --env-vars \
    "DB_HOST=${PG_HOST}" \
    "DB_PORT=5432" \
    "DB_NAME=aegis" \
    "DB_USER=aegis" \
    "DB_PASS=secretref:db-pass" \
    "APP_ENV=production" \
  --system-assigned

# Grant identity rights
MIGRATE_PRINCIPAL=$(az containerapp job show \
  --name aegis-migrate --resource-group "$RG" \
  --query identity.principalId -o tsv)

az role assignment create --role "AcrPull" \
  --assignee "$MIGRATE_PRINCIPAL" --scope "$ACR_ID"
az role assignment create --role "Key Vault Secrets User" \
  --assignee "$MIGRATE_PRINCIPAL" --scope "$KV_ID"

# Execute the migration job
az containerapp job start \
  --name aegis-migrate \
  --resource-group "$RG"

# Monitor execution
az containerapp job execution list \
  --name aegis-migrate \
  --resource-group "$RG" \
  --output table
```

### Option B — Exec into Running Container

```bash
# List running replicas
az containerapp replica list \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --output table

# Exec into first replica
az containerapp exec \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --command "php /var/www/html/install.php"
```

### Option C — Apply Migrations Directly via psql

For cases where `install.php` is not suitable (partial re-runs):

```bash
# From a jumpbox or Azure Cloud Shell in the same VNet
PG_HOST="${PG_SERVER}.postgres.database.usgovcloudapi.net"

for migration in database/migrations/*.sql; do
  echo "Applying $migration..."
  PGPASSWORD="$APP_DB_PASSWORD" psql \
    -h "$PG_HOST" -U aegis -d aegis \
    -v ON_ERROR_STOP=1 \
    -f "$migration"
done
```

> **Note:** The `startup.sh` entrypoint already calls `install.php` each time the
> container starts. On first boot, this initializes the schema and applies all
> migrations. On subsequent starts, `install.php` is expected to be idempotent
> (check-and-skip logic). Verify this behavior in `install.php` before relying on
> it for zero-downtime updates.

---

## 13. SSL/TLS Certificate

### Option A — Managed Certificate (Simplest)

Azure Container Apps supports managed TLS certificates for custom domains at no
additional cost.

```bash
# Verify DNS: create a CNAME record pointing your domain to the Container App FQDN
# aegis.youragency.gov → <APP_NAME>.<ACA_FQDN>

# Add custom domain and managed certificate
az containerapp hostname add \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --hostname "aegis.youragency.gov"

az containerapp ssl upload \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --hostname "aegis.youragency.gov" \
  --environment "$ACA_ENV"
```

### Option B — Import Certificate from Key Vault

For agency PKI certificates or DigiCert/Entrust certs:

```bash
# Import the PFX into Key Vault (done in Section 10.3)
# Then bind to Container App:
CERT_ID=$(az keyvault certificate show \
  --vault-name "$KV_NAME" --name aegis-tls-cert --query id -o tsv)

az containerapp env certificate upload \
  --name "$ACA_ENV" \
  --resource-group "$RG" \
  --certificate-file aegis.youragency.gov.pfx \
  --password "<PFX_PASSWORD>"

# Bind to the app
az containerapp hostname bind \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --hostname "aegis.youragency.gov" \
  --environment "$ACA_ENV" \
  --validation-method CNAME
```

> **Note:** Both DigiCert and Entrust certificates are accepted in Azure Government.
> Federal PKI (FPKI) / DOD CAC certificates may require additional trust anchor
> configuration in Apache (`SSLCACertificateFile`).

---

## 14. Environment Variable Reference

All sensitive values are stored in Key Vault and injected via Container Apps secret
references (`secretref:`). Non-sensitive values are provided as plain environment
variables.

| Variable | Source | Required | Purpose |
|----------|--------|----------|---------|
| `DB_HOST` | Plain env | Yes | PostgreSQL FQDN (`.postgres.database.usgovcloudapi.net`) |
| `DB_PORT` | Plain env | Yes | PostgreSQL port (`5432`) |
| `DB_NAME` | Plain env | Yes | Database name (`aegis`) |
| `DB_USER` | Plain env | Yes | Database application user (`aegis`) |
| `DB_PASS` | Key Vault → secretref | Yes | Database user password |
| `DATABASE_URL` | Key Vault → secretref | No | Alternative DSN string (overrides individual DB_* vars if set) |
| `APP_URL` | Plain env | Yes | Public HTTPS URL (e.g., `https://aegis.youragency.gov`) |
| `APP_ENV` | Plain env | Yes | Must be `production` |
| `APP_NAME` | Plain env | No | Display name (default: `AEGIS GRC`) |
| `JWT_SECRET` | Key Vault → secretref | Yes | Minimum 32-byte random value for JWT signing |
| `SMTP_HOST` | Plain env | No | SMTP relay hostname |
| `SMTP_PORT` | Plain env | No | SMTP port (`587` for STARTTLS, `465` for SSL) |
| `SMTP_USER` | Plain env | No | SMTP authentication username |
| `SMTP_PASS` | Key Vault → secretref | No | SMTP authentication password |
| `SMTP_FROM` | Plain env | No | From address for system email |
| `STORAGE_DRIVER` | Plain env | Yes | `s3` for Azure Blob (S3-compatible), `local` for container filesystem |
| `S3_ENDPOINT` | Plain env | When `STORAGE_DRIVER=s3` | Azure Blob S3 endpoint (`https://<account>.blob.core.usgovcloudapi.net`) |
| `S3_BUCKET` | Plain env | When `STORAGE_DRIVER=s3` | Container name (`aegis-uploads`) |
| `S3_ACCESS_KEY` | Plain env | When `STORAGE_DRIVER=s3` | Storage account name |
| `S3_SECRET_KEY` | Key Vault → secretref | When `STORAGE_DRIVER=s3` | Storage account key |
| `S3_REGION` | Plain env | When `STORAGE_DRIVER=s3` | Azure region (`usgovarizona`) |
| `S3_USE_PATH_STYLE` | Plain env | When `STORAGE_DRIVER=s3` | Must be `true` for Azure Blob |
| `AI_API_KEY` | Key Vault → secretref | No | API key for AI-assisted GRC features |
| `APPINSIGHTS_INSTRUMENTATIONKEY` | Plain env | No | Azure Application Insights key for APM |

---

## 15. FedRAMP / IL Hardening Notes

### 15.1 Enable Microsoft Defender for Cloud

```bash
# Enable Defender for Cloud at subscription level
az security pricing create --name VirtualMachines --tier Standard
az security pricing create --name SqlServers --tier Standard
az security pricing create --name AppServices --tier Standard
az security pricing create --name StorageAccounts --tier Standard
az security pricing create --name Containers --tier Standard    # ACR scanning
az security pricing create --name KeyVaults --tier Standard
az security pricing create --name Arm --tier Standard
az security pricing create --name Dns --tier Standard
az security pricing create --name PostgreSql --tier Standard    # Defender for PostgreSQL

# Set security contact
az security contact create \
  --name default \
  --email "soc@youragency.gov" \
  --phone "+1-555-555-0100" \
  --alerts-admins On \
  --alert-notifications On
```

### 15.2 Apply FedRAMP High Azure Policy Initiative

```bash
# Assign the FedRAMP High built-in initiative
FEDRAMP_INITIATIVE_ID=$(az policy set-definition list \
  --query "[?displayName=='FedRAMP High'].id" -o tsv)

az policy assignment create \
  --name aegis-fedramp-high \
  --display-name "AEGIS FedRAMP High" \
  --policy-set-definition "$FEDRAMP_INITIATIVE_ID" \
  --scope "/subscriptions/${SUBSCRIPTION}/resourceGroups/${RG}" \
  --enforcement-mode Default

# Apply Azure Security Benchmark
BENCHMARK_ID=$(az policy set-definition list \
  --query "[?displayName=='Azure Security Benchmark'].id" -o tsv)

az policy assignment create \
  --name aegis-security-benchmark \
  --display-name "AEGIS Azure Security Benchmark" \
  --policy-set-definition "$BENCHMARK_ID" \
  --scope "/subscriptions/${SUBSCRIPTION}/resourceGroups/${RG}"
```

### 15.3 Checklist

| Control | Implementation | Status |
|---------|---------------|--------|
| Data at rest encryption | Customer-managed keys via Key Vault HSM for PostgreSQL; SSE-CMK for Blob Storage | Required |
| Data in transit encryption | TLS 1.2 minimum enforced on all services; HTTPS-only on Blob Storage | Required |
| Network isolation | All services accessed via private endpoints; no public IPs on app or database | Required |
| Secret management | All secrets in Key Vault with RBAC; no secrets in container image or env vars in plain text | Required |
| Identity | System-assigned managed identity; no long-lived credentials for service-to-service auth | Required |
| Audit logging | Diagnostic settings on all services sending to Log Analytics; 90-day retention | Required |
| Vulnerability scanning | Defender for Containers on ACR; automatic image scanning on push | Required |
| WAF | Application Gateway WAF v2, OWASP 3.2, Prevention mode | Required |
| Backup | PostgreSQL 35-day retention, geo-redundant; ACR geo-replication | Required |
| Soft delete | Key Vault soft delete 90 days + purge protection; Blob Storage soft delete enabled | Required |
| Rate limiting | WAF custom rate limit rule; application-level rate limiting in `app.php` (5 login attempts / 5 min) | Required |
| TLS enforcement | `min-tls-version TLS1_2` on Storage; `require_secure_transport=on` on PostgreSQL | Required |
| Patch management | ACR base image rebuild cadence; Defender for Cloud vulnerability assessment | Required |
| FedRAMP High policy | Azure Policy FedRAMP High initiative assigned to resource group | Required |
| CMK (Customer-Managed Keys) | PostgreSQL CMK via Key Vault; Blob Storage CMK optional but recommended | Recommended |

### 15.4 Additional IL4/IL5 Considerations

- **IL4** (Controlled Unclassified Information): The architecture above meets IL4 when
  deployed in usgovarizona or usgovvirginia with all services on private endpoints and
  CMK encryption enabled.
- **IL5** (National Security Systems): Requires dedicated Azure Government Secret or
  Azure Government Top Secret cloud access, which is not publicly available through
  standard Azure Government subscriptions. Contact your Microsoft account team.
- **DoD Impact Levels**: Additional requirements include dedicated hardware
  (`Microsoft.Compute/dedicated`), strict network egress controls, and CAC/PIV
  authentication integration.

---

## 16. Compliance Notes

### Azure Government Authorization Status

| Region | FedRAMP High | IL2 | IL4 | IL5 |
|--------|-------------|-----|-----|-----|
| usgovvirginia | P-ATO | Yes | Yes | Yes |
| usgovarizona | P-ATO | Yes | Yes | Yes |
| usgovtexas | P-ATO | Yes | Yes | Yes |

> **Note:** Microsoft holds a FedRAMP High Provisional Authorization to Operate (P-ATO)
> for Azure Government infrastructure. Your agency's ATO covers the AEGIS application
> layer. The Microsoft FedRAMP SSP documents which controls Microsoft is responsible
> for (inherited) versus which your agency must implement (customer-responsible).

### Key Compliance References

- **Azure Government FedRAMP SSP**: Available from Microsoft via NDA or from
  [FedRAMP Marketplace](https://marketplace.fedramp.gov/#/product/microsoft-azure-government)
- **Azure Government Compliance Documentation**: https://docs.microsoft.com/en-us/azure/azure-government/documentation-government-plan-compliance
- **DoD SRG**: Azure Government meets DoD Cloud Computing Security Requirements Guide
  (CC SRG) for IL2, IL4, and IL5 (with proper configuration)
- **NIST SP 800-53 Rev 5**: Azure Government provides control inheritance documentation
  aligned to Rev 5

### Endpoint Differences from Commercial

Always verify you are using Government endpoints. A common misconfiguration is using
commercial API URLs in automation scripts:

```bash
# Confirm ARM endpoint
az cloud show --query endpoints.resourceManager -o tsv
# Should output: https://management.usgovcloudapi.net/

# Confirm AAD endpoint
az cloud show --query endpoints.activeDirectory -o tsv
# Should output: https://login.microsoftonline.us/
```

---

## 17. Cost Estimate

Approximate **monthly** costs in USD for a production AEGIS deployment in
`usgovarizona`. Azure Government pricing is generally 20–25% higher than commercial.
Consult the [Azure Government Pricing Calculator](https://azure.microsoft.com/en-us/pricing/calculator/)
for current rates.

| Service | Configuration | Est. Monthly Cost |
|---------|--------------|-------------------|
| Application Gateway WAF v2 | 2 capacity units, ~100 GB data | ~$350–$500 |
| Container Apps — aegis-app | 2 replicas, 1 vCPU / 2 GiB each, ~730 hr/mo | ~$150–$250 |
| Container Apps — aegis-cron | 1 replica, 0.5 vCPU / 1 GiB | ~$40–$80 |
| PostgreSQL Flexible Server HA | Standard_D4ds_v4, Zone Redundant, 128 GB | ~$700–$900 |
| Azure Container Registry | Premium SKU | ~$50 |
| Key Vault | Premium SKU, ~10,000 ops/mo | ~$10–$30 |
| Blob Storage | Standard ZRS, 100 GB | ~$5–$20 |
| Log Analytics | 5 GB/day ingestion, 90-day retention | ~$150–$300 |
| Public IP (Static Standard) | 1 IP | ~$5 |
| Private DNS Zones | 4 zones | ~$5 |
| Bandwidth | 100 GB egress | ~$20–$50 |
| **Estimated Total** | | **~$1,500–$2,200/month** |

> **Note:** Costs scale with traffic, replica count, and storage growth. Enable
> Azure Cost Management budgets and alerts from day one. PostgreSQL HA (Zone Redundant)
> is the largest cost driver; consider Burstable SKU for pre-production environments.

---

## 18. Maintenance and Updates

### 18.1 Rolling Image Update

```bash
# Build and push new image (tag with commit SHA)
NEW_TAG=$(git -C /path/to/aegis rev-parse --short HEAD)

az acr build \
  --registry "$ACR_NAME" \
  --image "aegis:${NEW_TAG}" \
  --image "aegis:latest" \
  --file Dockerfile \
  .

# Update the Container App — creates a new revision
az containerapp update \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --image "${ACR_NAME}.azurecr.us/aegis:${NEW_TAG}"

az containerapp update \
  --name "$CRON_NAME" \
  --resource-group "$RG" \
  --image "${ACR_NAME}.azurecr.us/aegis:${NEW_TAG}"
```

### 18.2 Blue/Green Deployment

Container Apps supports traffic splitting between revisions:

```bash
# Set new revision to receive 10% of traffic (canary)
az containerapp ingress traffic set \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --revision-weight \
    "$(az containerapp revision list --name $APP_NAME --resource-group $RG --query '[-1].name' -o tsv)"=90 \
    "$(az containerapp revision list --name $APP_NAME --resource-group $RG --query '[-2].name' -o tsv)"=10

# After validation, shift 100% to new revision
az containerapp ingress traffic set \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --revision-weight latest=100
```

### 18.3 Database Migrations on Update

Run the migration job before shifting traffic to the new revision:

```bash
# Trigger migration job
az containerapp job start \
  --name aegis-migrate \
  --resource-group "$RG"

# Wait for completion
az containerapp job execution list \
  --name aegis-migrate \
  --resource-group "$RG" \
  --query "[?properties.status!='Succeeded']" \
  --output table

# Then update app image
az containerapp update \
  --name "$APP_NAME" \
  --resource-group "$RG" \
  --image "${ACR_NAME}.azurecr.us/aegis:${NEW_TAG}"
```

### 18.4 Backup Verification

```bash
# Trigger an on-demand backup
az postgres flexible-server backup create \
  --resource-group "$RG" \
  --name "$PG_SERVER" \
  --backup-name "manual-$(date +%Y%m%d)"

# List available backups
az postgres flexible-server backup list \
  --resource-group "$RG" \
  --name "$PG_SERVER" \
  --output table

# Test restore to a separate server (quarterly verification)
az postgres flexible-server restore \
  --resource-group "$RG" \
  --name "${PG_SERVER}-restore-test" \
  --source-server "$PG_SERVER" \
  --restore-time "$(date -u -d '1 hour ago' +%Y-%m-%dT%H:%M:%SZ)"
```

---

## 19. Disaster Recovery

### 19.1 Azure Government Paired Regions

| Primary Region | Paired Region |
|---------------|--------------|
| usgovvirginia | usgovtexas |
| usgovarizona | usgovtexas |
| usgovtexas | usgovvirginia |

> **Note:** usgovtexas is the secondary paired region for both usgovarizona and
> usgovvirginia. Plan accordingly — if both primary regions activate DR simultaneously,
> usgovtexas must absorb both workloads.

### 19.2 RTO/RPO Targets

| Component | RPO | RTO |
|-----------|-----|-----|
| PostgreSQL (Geo-Redundant Backup) | ≤ 1 hour | ≤ 4 hours |
| ACR (Geo-Replication) | Near real-time | ≤ 30 minutes |
| Blob Storage (GRS) | ≤ 15 minutes | Depends on Microsoft failover trigger |
| Container Apps (redeploy in paired region) | N/A | ≤ 2 hours |

### 19.3 PostgreSQL Geo-Restore

```bash
# In the paired region (usgovtexas), restore from geo-redundant backup
az postgres flexible-server geo-restore \
  --resource-group "${RG}-dr" \
  --name "${PG_SERVER}-dr" \
  --source-server "/subscriptions/${SUBSCRIPTION}/resourceGroups/${RG}/providers/Microsoft.DBforPostgreSQL/flexibleServers/${PG_SERVER}" \
  --location usgovtexas \
  --sku-name Standard_D4ds_v4 \
  --tier GeneralPurpose
```

### 19.4 ACR Geo-Replication

```bash
# Replicate ACR to paired region
az acr replication create \
  --registry "$ACR_NAME" \
  --location usgovtexas \
  --resource-group "$RG"

# Verify replication status
az acr replication list \
  --registry "$ACR_NAME" \
  --output table
```

### 19.5 DR Runbook Summary

1. **Declare DR event**: Notify ISSO and agency leadership; open change ticket.
2. **Assess scope**: Determine if primary region (usgovarizona/usgovvirginia) is
   partially or fully unavailable.
3. **Activate paired-region resource group**: Create `${RG}-dr` in usgovtexas.
4. **Restore PostgreSQL**: Run `geo-restore` (step 19.3). Estimated 30–90 minutes.
5. **Deploy Container Apps**: Create a new ACA environment in usgovtexas; deploy
   `aegis-app` and `aegis-cron` pointing to the restored PostgreSQL server.
6. **Verify Blob Storage**: Azure GRS automatically fails over; update `S3_ENDPOINT`
   to the secondary endpoint if needed:
   `https://${STORAGE_ACCOUNT}-secondary.blob.core.usgovcloudapi.net`
7. **Update DNS**: Point `aegis.youragency.gov` to the DR Application Gateway public IP.
8. **Smoke test**: Verify `/health` endpoint, login, and a representative GRC workflow.
9. **Notify stakeholders**: Communicate recovery status and estimated full-restoration timeline.
10. **Document**: Record the incident, recovery time, and any data loss in the POA&M.

### 19.6 Failback Procedure

Once the primary region is restored:

1. Quiesce writes in the DR environment (enable maintenance page).
2. Export any data written during DR with `pg_dump`.
3. Import the differential data into the restored primary PostgreSQL instance.
4. Rebuild and push images from source to primary ACR.
5. Redeploy Container Apps in the primary region.
6. Validate, then update DNS to point back to the primary Application Gateway.
7. Decommission DR resources (or retain as warm standby).

---

*Last updated: June 2026. Verify all Azure Government service availability and CLI
commands against the [Azure Government documentation](https://docs.microsoft.com/en-us/azure/azure-government/)
prior to execution, as service availability and CLI flags may change.*
