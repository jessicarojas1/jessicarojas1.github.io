# AEGIS GRC — Azure Deployment Guide

Complete end-to-end instructions for deploying AEGIS on Microsoft Azure using
Docker containers, Azure Container Apps, and Azure Database for PostgreSQL.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Prerequisites](#2-prerequisites)
3. [Resource Group & Core Services](#3-resource-group--core-services)
4. [Azure Container Registry (ACR)](#4-azure-container-registry-acr)
5. [Azure Database for PostgreSQL](#5-azure-database-for-postgresql)
6. [Azure Files — Persistent Storage](#6-azure-files--persistent-storage)
7. [Azure Key Vault — Secrets](#7-azure-key-vault--secrets)
8. [Build & Push the Docker Image](#8-build--push-the-docker-image)
9. [Azure Container Apps Environment](#9-azure-container-apps-environment)
10. [Deploy the Application Container](#10-deploy-the-application-container)
11. [Deploy the Cron Container](#11-deploy-the-cron-container)
12. [Custom Domain & Managed TLS](#12-custom-domain--managed-tls)
13. [CI/CD with GitHub Actions](#13-cicd-with-github-actions)
14. [Monitoring & Alerts](#14-monitoring--alerts)
15. [Scaling & Performance](#15-scaling--performance)
16. [Backup & Disaster Recovery](#16-backup--disaster-recovery)
17. [Alternative: Azure App Service](#17-alternative-azure-app-service)
18. [Troubleshooting](#18-troubleshooting)
19. [Environment Variables Reference](#19-environment-variables-reference)

---

## 1. Architecture Overview

```
Internet
   │
   ▼
Azure Container Apps Ingress  (HTTPS, managed TLS)
   │
   ├── aegis-app   (PHP 8.3 + Apache)  ← main web container
   └── aegis-cron  (PHP 8.3, no ingress) ← background jobs
          │
          ├── Azure Database for PostgreSQL Flexible Server
          ├── Azure Files Share  (mounted at /var/www/html/uploads)
          └── Azure Key Vault    (secrets injected as env vars)
```

**Services used:**

| Service | Purpose | SKU |
|---------|---------|-----|
| Azure Container Registry | Store Docker images | Basic |
| Azure Container Apps | Run containers (serverless) | Consumption |
| Azure Database for PostgreSQL Flexible Server | PostgreSQL 16 | Burstable B1ms (dev) / General Purpose D2s_v3 (prod) |
| Azure Files | Persistent upload storage | Standard LRS |
| Azure Key Vault | Secrets management | Standard |
| Azure Log Analytics | Logs & monitoring | Pay-per-use |

---

## 2. Prerequisites

### Tools to install locally

```bash
# Azure CLI (version 2.55+)
curl -sL https://aka.ms/InstallAzureCLIDeb | sudo bash
az version

# Docker Desktop or Docker Engine
docker --version   # 24.0+

# (Optional) GitHub CLI — for CI/CD setup
gh --version
```

### Azure CLI extensions

```bash
az extension add --name containerapp --upgrade
az extension add --name rdbms-connect  --upgrade
az provider register --namespace Microsoft.App
az provider register --namespace Microsoft.OperationalInsights
az provider register --namespace Microsoft.DBforPostgreSQL
```

### Log in to Azure

```bash
az login
# List subscriptions
az account list --output table
# Set the target subscription
az account set --subscription "YOUR_SUBSCRIPTION_ID"
```

---

## 3. Resource Group & Core Services

All resources share a single resource group. Pick a region close to your users.

```bash
# ── Variables (edit these) ────────────────────────────────────────────────────
LOCATION="eastus"              # Azure region
RG="aegis-rg"                  # Resource group name
ACR_NAME="aegisacr$RANDOM"     # Must be globally unique, lowercase, 5-50 chars
PG_SERVER="aegis-pg"           # PostgreSQL server name (globally unique)
PG_DB="aegis"
PG_USER="aegisadmin"
PG_PASS="$(openssl rand -base64 24)"   # Save this — you'll need it later
KV_NAME="aegis-kv-$RANDOM"            # Key Vault name (globally unique)
STORAGE_ACCOUNT="aegisstorage$RANDOM"  # Storage account (globally unique)
APP_NAME="aegis-app"
CRON_NAME="aegis-cron"
ENV_NAME="aegis-env"
LOG_NAME="aegis-logs"
# ─────────────────────────────────────────────────────────────────────────────

# Create resource group
az group create --name $RG --location $LOCATION
```

---

## 4. Azure Container Registry (ACR)

```bash
# Create registry
az acr create \
  --resource-group $RG \
  --name $ACR_NAME \
  --sku Basic \
  --admin-enabled true

# Get login server hostname
ACR_SERVER=$(az acr show --name $ACR_NAME --query loginServer -o tsv)
echo "ACR Server: $ACR_SERVER"
```

---

## 5. Azure Database for PostgreSQL

```bash
# Create Flexible Server (Burstable B1ms = cheapest; upgrade to D2s_v3 for prod)
az postgres flexible-server create \
  --resource-group $RG \
  --name $PG_SERVER \
  --location $LOCATION \
  --admin-user $PG_USER \
  --admin-password "$PG_PASS" \
  --sku-name Standard_B1ms \
  --tier Burstable \
  --storage-size 32 \
  --version 16 \
  --public-access None     # VNet-integrated; use 0.0.0.0 during initial setup

# Create the aegis database
az postgres flexible-server db create \
  --resource-group $RG \
  --server-name $PG_SERVER \
  --database-name $PG_DB

# Allow the Container Apps environment to connect
# After the environment is created (step 9), add its outbound IPs here.
# For now, open 0.0.0.0/0 to complete the initial schema load, then restrict.
az postgres flexible-server firewall-rule create \
  --resource-group $RG \
  --name $PG_SERVER \
  --rule-name allow-all-temp \
  --start-ip-address 0.0.0.0 \
  --end-ip-address 255.255.255.255

# Get the connection string
PG_HOST=$(az postgres flexible-server show \
  --resource-group $RG --name $PG_SERVER \
  --query fullyQualifiedDomainName -o tsv)
echo "PostgreSQL host: $PG_HOST"
```

### Load the database schema

```bash
# Install psql client if needed
sudo apt-get install postgresql-client -y

# Run schema + migrations
PGPASSWORD="$PG_PASS" psql \
  -h $PG_HOST -U $PG_USER -d $PG_DB \
  -f database/schema.sql

PGPASSWORD="$PG_PASS" psql \
  -h $PG_HOST -U $PG_USER -d $PG_DB \
  -f database/migrations/001_enterprise_phase1.sql

PGPASSWORD="$PG_PASS" psql \
  -h $PG_HOST -U $PG_USER -d $PG_DB \
  -f database/migrations/002_phase2.sql

PGPASSWORD="$PG_PASS" psql \
  -h $PG_HOST -U $PG_USER -d $PG_DB \
  -f database/migrations/003_phase3.sql
```

---

## 6. Azure Files — Persistent Storage

The uploads directory must survive container restarts and scale-out events.
Azure Files provides an SMB share mountable by Container Apps.

```bash
# Create storage account
az storage account create \
  --resource-group $RG \
  --name $STORAGE_ACCOUNT \
  --location $LOCATION \
  --sku Standard_LRS \
  --kind StorageV2

# Get storage key
STORAGE_KEY=$(az storage account keys list \
  --resource-group $RG \
  --account-name $STORAGE_ACCOUNT \
  --query "[0].value" -o tsv)

# Create file share
az storage share create \
  --account-name $STORAGE_ACCOUNT \
  --account-key $STORAGE_KEY \
  --name aegis-uploads \
  --quota 100     # GB
```

---

## 7. Azure Key Vault — Secrets

Store every secret in Key Vault. Container Apps will inject them as env vars.

```bash
# Create Key Vault
az keyvault create \
  --resource-group $RG \
  --name $KV_NAME \
  --location $LOCATION \
  --enable-rbac-authorization true

# Store secrets
JWT_SECRET="$(openssl rand -hex 64)"
SESSION_SECRET="$(openssl rand -hex 64)"

az keyvault secret set --vault-name $KV_NAME --name "db-pass"        --value "$PG_PASS"
az keyvault secret set --vault-name $KV_NAME --name "jwt-secret"     --value "$JWT_SECRET"
az keyvault secret set --vault-name $KV_NAME --name "session-secret" --value "$SESSION_SECRET"

# Optional: SMTP credentials
az keyvault secret set --vault-name $KV_NAME --name "smtp-pass"      --value "YOUR_SMTP_PASSWORD"

# Save the Key Vault URI
KV_URI=$(az keyvault show --name $KV_NAME --query properties.vaultUri -o tsv)
echo "Key Vault URI: $KV_URI"
```

---

## 8. Build & Push the Docker Image

```bash
# Build the image locally and push to ACR in one step
az acr build \
  --registry $ACR_NAME \
  --image aegis:latest \
  --file Dockerfile \
  .

# Verify it's in ACR
az acr repository list --name $ACR_NAME --output table
az acr repository show-tags --name $ACR_NAME --repository aegis --output table
```

---

## 9. Azure Container Apps Environment

The environment is the shared network and logging context for all containers.

```bash
# Create Log Analytics workspace
az monitor log-analytics workspace create \
  --resource-group $RG \
  --workspace-name $LOG_NAME

LOG_ID=$(az monitor log-analytics workspace show \
  --resource-group $RG \
  --workspace-name $LOG_NAME \
  --query customerId -o tsv)

LOG_KEY=$(az monitor log-analytics workspace get-shared-keys \
  --resource-group $RG \
  --workspace-name $LOG_NAME \
  --query primarySharedKey -o tsv)

# Create Container Apps Environment
az containerapp env create \
  --name $ENV_NAME \
  --resource-group $RG \
  --location $LOCATION \
  --logs-workspace-id $LOG_ID \
  --logs-workspace-key $LOG_KEY

# Add the Azure Files storage to the environment
az containerapp env storage set \
  --name $ENV_NAME \
  --resource-group $RG \
  --storage-name aegis-uploads \
  --azure-file-account-name $STORAGE_ACCOUNT \
  --azure-file-account-key $STORAGE_KEY \
  --azure-file-share-name aegis-uploads \
  --access-mode ReadWrite

# Get the environment's outbound IPs to whitelist in PostgreSQL firewall
OUTBOUND_IPS=$(az containerapp env show \
  --name $ENV_NAME \
  --resource-group $RG \
  --query properties.staticIp -o tsv)
echo "Container Apps outbound IP: $OUTBOUND_IPS"

# Add firewall rule for Container Apps (replace the temp rule)
az postgres flexible-server firewall-rule delete \
  --resource-group $RG --name $PG_SERVER --rule-name allow-all-temp
az postgres flexible-server firewall-rule create \
  --resource-group $RG --name $PG_SERVER \
  --rule-name allow-container-apps \
  --start-ip-address $OUTBOUND_IPS \
  --end-ip-address $OUTBOUND_IPS
```

---

## 10. Deploy the Application Container

```bash
# Enable ACR admin credentials for pull
ACR_USER=$(az acr credential show --name $ACR_NAME --query username -o tsv)
ACR_PASS=$(az acr credential show --name $ACR_NAME --query passwords[0].value -o tsv)

# Get PostgreSQL host
PG_HOST=$(az postgres flexible-server show \
  --resource-group $RG --name $PG_SERVER \
  --query fullyQualifiedDomainName -o tsv)

# Create the application container
az containerapp create \
  --name $APP_NAME \
  --resource-group $RG \
  --environment $ENV_NAME \
  --image $ACR_SERVER/aegis:latest \
  --registry-server $ACR_SERVER \
  --registry-username $ACR_USER \
  --registry-password $ACR_PASS \
  --target-port 80 \
  --ingress external \
  --min-replicas 1 \
  --max-replicas 5 \
  --cpu 1.0 \
  --memory 2.0Gi \
  --env-vars \
    DB_HOST="$PG_HOST" \
    DB_PORT=5432 \
    DB_NAME="$PG_DB" \
    DB_USER="$PG_USER" \
    "DB_PASS=secretref:db-pass" \
    "APP_URL=https://PLACEHOLDER" \
    APP_ENV=production \
    "JWT_SECRET=secretref:jwt-secret" \
    "SESSION_SECRET=secretref:session-secret" \
    STORAGE_DRIVER=local \
  --secrets \
    "db-pass=keyvaultref:${KV_URI}secrets/db-pass,identityref:system" \
    "jwt-secret=keyvaultref:${KV_URI}secrets/jwt-secret,identityref:system" \
    "session-secret=keyvaultref:${KV_URI}secrets/session-secret,identityref:system" \
  --volume-mounts "aegis-uploads:/var/www/html/uploads"

# Enable system-assigned managed identity
az containerapp identity assign \
  --name $APP_NAME \
  --resource-group $RG \
  --system-assigned

# Grant managed identity access to Key Vault
APP_IDENTITY=$(az containerapp show \
  --name $APP_NAME \
  --resource-group $RG \
  --query identity.principalId -o tsv)

az role assignment create \
  --role "Key Vault Secrets User" \
  --assignee $APP_IDENTITY \
  --scope $(az keyvault show --name $KV_NAME --query id -o tsv)

# Restart to pick up Key Vault references
az containerapp revision restart \
  --name $APP_NAME \
  --resource-group $RG \
  --revision $(az containerapp revision list \
    --name $APP_NAME --resource-group $RG \
    --query "[0].name" -o tsv)

# Get the app URL
APP_URL=$(az containerapp show \
  --name $APP_NAME \
  --resource-group $RG \
  --query properties.configuration.ingress.fqdn -o tsv)
echo "Application URL: https://$APP_URL"

# Update APP_URL env var with the real hostname
az containerapp update \
  --name $APP_NAME \
  --resource-group $RG \
  --set-env-vars "APP_URL=https://$APP_URL"
```

### Test the deployment

```bash
curl -sf "https://$APP_URL/health" && echo "OK" || echo "FAIL"
```

---

## 11. Deploy the Cron Container

The cron container runs background jobs (workflows, webhooks). It has no HTTP
ingress and shares the same uploads volume.

```bash
az containerapp create \
  --name $CRON_NAME \
  --resource-group $RG \
  --environment $ENV_NAME \
  --image $ACR_SERVER/aegis:latest \
  --registry-server $ACR_SERVER \
  --registry-username $ACR_USER \
  --registry-password $ACR_PASS \
  --ingress disabled \
  --min-replicas 1 \
  --max-replicas 1 \
  --cpu 0.25 \
  --memory 0.5Gi \
  --command "/bin/sh" \
  --args "-c" "while true; do
    php /var/www/html/scripts/run_workflows.php >> /dev/stdout 2>&1;
    php /var/www/html/scripts/dispatch_webhooks.php >> /dev/stdout 2>&1;
    sleep 60;
  done" \
  --env-vars \
    DB_HOST="$PG_HOST" \
    DB_PORT=5432 \
    DB_NAME="$PG_DB" \
    DB_USER="$PG_USER" \
    "DB_PASS=secretref:db-pass" \
    APP_ENV=production \
    "JWT_SECRET=secretref:jwt-secret" \
  --secrets \
    "db-pass=keyvaultref:${KV_URI}secrets/db-pass,identityref:system" \
    "jwt-secret=keyvaultref:${KV_URI}secrets/jwt-secret,identityref:system" \
  --volume-mounts "aegis-uploads:/var/www/html/uploads"

# Assign managed identity and Key Vault access
az containerapp identity assign \
  --name $CRON_NAME \
  --resource-group $RG \
  --system-assigned

CRON_IDENTITY=$(az containerapp show \
  --name $CRON_NAME --resource-group $RG \
  --query identity.principalId -o tsv)

az role assignment create \
  --role "Key Vault Secrets User" \
  --assignee $CRON_IDENTITY \
  --scope $(az keyvault show --name $KV_NAME --query id -o tsv)
```

---

## 12. Custom Domain & Managed TLS

Azure Container Apps issues free managed TLS certificates automatically.

```bash
YOUR_DOMAIN="grc.yourcompany.com"   # Replace with your domain

# 1. Get the Container Apps environment DNS suffix
ENV_DOMAIN=$(az containerapp env show \
  --name $ENV_NAME --resource-group $RG \
  --query properties.defaultDomain -o tsv)

# 2. In your DNS provider, add a CNAME record:
#    grc.yourcompany.com  →  aegis-app.<ENV_DOMAIN>
echo "Add CNAME: $YOUR_DOMAIN → $(az containerapp show \
  --name $APP_NAME --resource-group $RG \
  --query properties.configuration.ingress.fqdn -o tsv)"

# 3. Add the custom domain to the container app
az containerapp hostname add \
  --name $APP_NAME \
  --resource-group $RG \
  --hostname $YOUR_DOMAIN

# 4. Bind a free managed certificate
az containerapp ssl upload \
  --name $APP_NAME \
  --resource-group $RG \
  --hostname $YOUR_DOMAIN \
  --environment $ENV_NAME \
  --certificate-name "aegis-cert"

# 5. Update APP_URL
az containerapp update \
  --name $APP_NAME \
  --resource-group $RG \
  --set-env-vars "APP_URL=https://$YOUR_DOMAIN"
```

---

## 13. CI/CD with GitHub Actions

### One-time GitHub setup

```bash
# Create a service principal for OIDC (no long-lived secrets)
az ad app create --display-name "aegis-github-actions"
APP_ID=$(az ad app list --display-name "aegis-github-actions" --query "[0].appId" -o tsv)

az ad sp create --id $APP_ID
SP_ID=$(az ad sp show --id $APP_ID --query id -o tsv)
SUB_ID=$(az account show --query id -o tsv)
TENANT_ID=$(az account show --query tenantId -o tsv)

# Grant Contributor on the resource group
az role assignment create \
  --role Contributor \
  --assignee $SP_ID \
  --scope /subscriptions/$SUB_ID/resourceGroups/$RG

# Grant AcrPush on the registry
az role assignment create \
  --role AcrPush \
  --assignee $SP_ID \
  --scope $(az acr show --name $ACR_NAME --query id -o tsv)

# Create federated credential for GitHub OIDC
GITHUB_ORG="your-github-org"        # Replace
GITHUB_REPO="your-github-repo"      # Replace

az ad app federated-credential create \
  --id $APP_ID \
  --parameters '{
    "name": "github-main",
    "issuer": "https://token.actions.githubusercontent.com",
    "subject": "repo:'"$GITHUB_ORG/$GITHUB_REPO"':ref:refs/heads/main",
    "audiences": ["api://AzureADTokenExchange"]
  }'
```

### Add secrets and variables to your GitHub repository

```bash
# Using GitHub CLI (gh)
gh secret set AZURE_CLIENT_ID        --body "$APP_ID"
gh secret set AZURE_TENANT_ID        --body "$TENANT_ID"
gh secret set AZURE_SUBSCRIPTION_ID  --body "$SUB_ID"

gh variable set ACR_NAME             --body "$ACR_NAME"
gh variable set ACR_LOGIN_SERVER     --body "$ACR_SERVER"
gh variable set CONTAINER_APP_NAME   --body "$APP_NAME"
gh variable set CONTAINER_APP_ENV    --body "$ENV_NAME"
gh variable set RESOURCE_GROUP       --body "$RG"
```

The workflow file is at `.github/workflows/azure-deploy.yml`. Every push to
`main` that touches the `aegis/` directory will automatically build and deploy.

### Manual trigger

```bash
gh workflow run azure-deploy.yml
```

---

## 14. Monitoring & Alerts

### View real-time logs

```bash
# App container logs (follow)
az containerapp logs show \
  --name $APP_NAME \
  --resource-group $RG \
  --follow

# Cron container logs
az containerapp logs show \
  --name $CRON_NAME \
  --resource-group $RG \
  --follow
```

### Application Insights (optional, recommended for prod)

```bash
# Create Application Insights
az monitor app-insights component create \
  --app aegis-insights \
  --location $LOCATION \
  --resource-group $RG \
  --workspace $LOG_NAME

APPINSIGHTS_KEY=$(az monitor app-insights component show \
  --app aegis-insights --resource-group $RG \
  --query instrumentationKey -o tsv)

# Add to container app
az containerapp update \
  --name $APP_NAME \
  --resource-group $RG \
  --set-env-vars "APPINSIGHTS_INSTRUMENTATIONKEY=$APPINSIGHTS_KEY"
```

### Alert rules

```bash
# Alert when app has > 5 failures in 5 minutes
az monitor metrics alert create \
  --name "aegis-5xx-alert" \
  --resource-group $RG \
  --scopes $(az containerapp show --name $APP_NAME --resource-group $RG --query id -o tsv) \
  --condition "count 'Http5xx' > 5" \
  --window-size 5m \
  --evaluation-frequency 1m \
  --severity 2 \
  --action-groups "YOUR_ACTION_GROUP_ID"
```

---

## 15. Scaling & Performance

### Horizontal scaling rules

```bash
# Scale based on HTTP requests
az containerapp update \
  --name $APP_NAME \
  --resource-group $RG \
  --min-replicas 1 \
  --max-replicas 10 \
  --scale-rule-name http-scale \
  --scale-rule-type http \
  --scale-rule-http-concurrency 50   # New replica per 50 concurrent requests
```

### Vertical scaling (CPU/memory)

```bash
# Production recommended sizes:
# - Small:   1 CPU, 2 GiB  (up to ~200 users)
# - Medium:  2 CPU, 4 GiB  (up to ~500 users)
# - Large:   4 CPU, 8 GiB  (enterprise)

az containerapp update \
  --name $APP_NAME \
  --resource-group $RG \
  --cpu 2.0 \
  --memory 4.0Gi
```

---

## 16. Backup & Disaster Recovery

### PostgreSQL automatic backups

Azure Database for PostgreSQL Flexible Server creates automatic backups
with 7-day retention by default. Increase for production:

```bash
az postgres flexible-server update \
  --resource-group $RG \
  --name $PG_SERVER \
  --backup-retention 30          # Days
  --geo-redundant-backup Enabled # Cross-region backup
```

### Manual database snapshot

```bash
PGPASSWORD="$PG_PASS" pg_dump \
  -h $PG_HOST -U $PG_USER -d $PG_DB \
  --format=custom \
  --file="aegis_backup_$(date +%Y%m%d).dump"
```

### Restore from backup

```bash
# Create a restore point (point-in-time restore)
az postgres flexible-server restore \
  --resource-group $RG \
  --name "$PG_SERVER-restore" \
  --source-server $PG_SERVER \
  --restore-time "2025-01-15T00:00:00Z"
```

### File uploads backup (Azure Files → Blob)

```bash
# Create a daily snapshot of the uploads share
az storage share snapshot \
  --account-name $STORAGE_ACCOUNT \
  --account-key $STORAGE_KEY \
  --name aegis-uploads
```

---

## 17. Alternative: Azure App Service

If you prefer Azure App Service over Container Apps:

```bash
# Create App Service Plan (Linux, containerized)
az appservice plan create \
  --resource-group $RG \
  --name aegis-plan \
  --is-linux \
  --sku B2     # B2 = 2 cores, 3.5 GB RAM (smallest prod-ready)

# Create Web App with Docker container
az webapp create \
  --resource-group $RG \
  --plan aegis-plan \
  --name "aegis-app-$RANDOM" \
  --deployment-container-image-name "$ACR_SERVER/aegis:latest"

# Configure ACR credentials
az webapp config container set \
  --resource-group $RG \
  --name aegis-app \
  --docker-custom-image-name "$ACR_SERVER/aegis:latest" \
  --docker-registry-server-url "https://$ACR_SERVER" \
  --docker-registry-server-user $ACR_USER \
  --docker-registry-server-password $ACR_PASS

# Set environment variables
az webapp config appsettings set \
  --resource-group $RG \
  --name aegis-app \
  --settings \
    DB_HOST="$PG_HOST" \
    DB_PORT=5432 \
    DB_NAME="$PG_DB" \
    DB_USER="$PG_USER" \
    DB_PASS="$PG_PASS" \
    APP_ENV=production \
    JWT_SECRET="$JWT_SECRET"

# Enable always-on and continuous deployment
az webapp config set \
  --resource-group $RG \
  --name aegis-app \
  --always-on true

# Mount Azure Files for uploads
az webapp config storage-account add \
  --resource-group $RG \
  --name aegis-app \
  --custom-id uploads \
  --storage-type AzureFiles \
  --account-name $STORAGE_ACCOUNT \
  --share-name aegis-uploads \
  --mount-path /var/www/html/uploads \
  --access-key $STORAGE_KEY
```

---

## 18. Troubleshooting

### Container won't start

```bash
# Check revision logs
az containerapp revision list \
  --name $APP_NAME --resource-group $RG --output table

az containerapp logs show \
  --name $APP_NAME --resource-group $RG \
  --type system --tail 50
```

### Database connection refused

```bash
# Verify firewall rule exists
az postgres flexible-server firewall-rule list \
  --resource-group $RG --name $PG_SERVER --output table

# Test connection directly
az postgres flexible-server connect \
  --name $PG_SERVER --admin-user $PG_USER \
  --admin-password "$PG_PASS" --database-name $PG_DB
```

### Health check failing

```bash
# Call health endpoint manually
curl -v "https://$APP_URL/health"

# Check if the app container is running
az containerapp show \
  --name $APP_NAME --resource-group $RG \
  --query properties.runningStatus -o tsv
```

### Secrets not injecting

Ensure the managed identity was assigned and the Key Vault role assignment
propagated (can take 2–5 minutes):

```bash
az role assignment list \
  --assignee $APP_IDENTITY \
  --scope $(az keyvault show --name $KV_NAME --query id -o tsv) \
  --output table
```

### Uploads not persisting

Verify the Azure Files mount:

```bash
az containerapp env storage show \
  --name $ENV_NAME --resource-group $RG \
  --storage-name aegis-uploads
```

---

## 19. Environment Variables Reference

| Variable | Required | Description |
|----------|----------|-------------|
| `DB_HOST` | ✓ | PostgreSQL server FQDN |
| `DB_PORT` | ✓ | PostgreSQL port (default `5432`) |
| `DB_NAME` | ✓ | Database name (default `aegis`) |
| `DB_USER` | ✓ | Database user |
| `DB_PASS` | ✓ | Database password — store in Key Vault |
| `APP_URL` | ✓ | Full public URL, e.g. `https://grc.company.com` |
| `APP_ENV` | ✓ | `production` or `development` |
| `JWT_SECRET` | ✓ | 64-byte hex secret — store in Key Vault |
| `SESSION_SECRET` | ✓ | 64-byte hex secret — store in Key Vault |
| `SMTP_HOST` | — | SMTP server hostname |
| `SMTP_PORT` | — | SMTP port (default `587`) |
| `SMTP_USER` | — | SMTP username |
| `SMTP_PASS` | — | SMTP password — store in Key Vault |
| `SMTP_FROM` | — | From address for outbound emails |
| `STORAGE_DRIVER` | — | `local` (default) or `s3` |
| `S3_BUCKET` | — | Blob container name (if using S3 driver) |
| `S3_ENDPOINT` | — | Custom S3-compatible endpoint |
| `S3_ACCESS_KEY` | — | Storage access key |
| `S3_SECRET_KEY` | — | Storage secret key |
| `AI_API_KEY` | — | Claude / AI provider key |

---

## Quick-Start Checklist

- [ ] `az login` — authenticated to Azure
- [ ] Resource group created
- [ ] ACR created, image pushed
- [ ] PostgreSQL created, schema loaded
- [ ] Azure Files share created
- [ ] Key Vault created, secrets stored
- [ ] Log Analytics workspace created
- [ ] Container Apps environment created with Files storage attached
- [ ] `aegis-app` container deployed, health check passing
- [ ] `aegis-cron` container deployed
- [ ] Custom domain added with managed TLS
- [ ] GitHub Actions secrets/variables configured
- [ ] First CI/CD pipeline run succeeds
- [ ] Monitoring alerts configured
- [ ] PostgreSQL backup retention set to ≥ 30 days
