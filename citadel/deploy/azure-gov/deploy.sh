#!/usr/bin/env bash
# =============================================================================
# CITADEL — Azure Government end-to-end deployment script
# Code Inspection, Threat Analysis & Deployment Evaluation Lab
#
# Sets the CLI to Azure Government, creates the resource group, builds & pushes
# the hardened image with ACR Tasks (inside the Gov boundary), deploys the
# Bicep stack, and prints the resulting (internal) application URL.
#
# Usage:
#   ./deploy.sh
#
# Override any variable inline, e.g.:
#   LOCATION=usgovarizona IMAGE_TAG=1.0.1 ./deploy.sh
# =============================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration (override via environment variables)
# ---------------------------------------------------------------------------
SUBSCRIPTION_ID="${SUBSCRIPTION_ID:-<YOUR-GOV-SUBSCRIPTION-ID>}"
LOCATION="${LOCATION:-usgovvirginia}"          # primary: usgovvirginia | DR: usgovarizona
RG="${RG:-rg-citadel-prod}"
ACR_NAME="${ACR_NAME:-acrcitadelprod}"          # 5-50 lowercase alnum, unique in Gov
IMAGE_REPO="${IMAGE_REPO:-citadel/web}"
IMAGE_TAG="${IMAGE_TAG:-1.0.0}"
TEMPLATE_FILE="${TEMPLATE_FILE:-main.bicep}"
PARAMS_FILE="${PARAMS_FILE:-parameters.json}"

CLASSIFICATION="${CLASSIFICATION:-CUI}"
IMPACT_LEVEL="${IMPACT_LEVEL:-IL4}"

DEPLOYMENT_NAME="citadel-$(date +%Y%m%d-%H%M%S)"
IMAGE_REF="${ACR_NAME}.azurecr.us/${IMAGE_REPO}:${IMAGE_TAG}"   # Gov registry: *.azurecr.us

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
log() { printf '\033[1;36m[CITADEL]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[CITADEL][ERROR]\033[0m %s\n' "$*" >&2; exit 1; }

command -v az >/dev/null 2>&1 || die "Azure CLI (az) is not installed."

# ---------------------------------------------------------------------------
# 0. Point the CLI at Azure Government (ALL endpoints -> *.usgovcloudapi.net)
# ---------------------------------------------------------------------------
log "Setting cloud to AzureUSGovernment..."
az cloud set --name AzureUSGovernment

# NOTE: Log in before running, or uncomment device-code login (recommended on
# hardened admin workstations):
#   az login --use-device-code
if ! az account show >/dev/null 2>&1; then
  die "Not logged in. Run: az login --use-device-code  (then re-run this script)."
fi

log "Selecting subscription ${SUBSCRIPTION_ID}..."
az account set --subscription "${SUBSCRIPTION_ID}"

# Sanity check: confirm we are actually in Gov.
RM_ENDPOINT=$(az cloud show --query "endpoints.resourceManager" -o tsv)
case "${RM_ENDPOINT}" in
  *usgovcloudapi.net*) log "Confirmed Azure Government endpoint: ${RM_ENDPOINT}" ;;
  *) die "Resource Manager endpoint is not Government (${RM_ENDPOINT}). Aborting." ;;
esac

# ---------------------------------------------------------------------------
# 1. Resource group
# ---------------------------------------------------------------------------
log "Creating resource group ${RG} in ${LOCATION}..."
az group create \
  --name "${RG}" \
  --location "${LOCATION}" \
  --tags system=CITADEL classification="${CLASSIFICATION}" \
         impactLevel="${IMPACT_LEVEL}" fedramp=High \
  --output none

# ---------------------------------------------------------------------------
# 2. Azure Container Registry (Premium, private, admin disabled) + image build
#    Built with ACR Tasks so the image never leaves the Gov boundary.
# ---------------------------------------------------------------------------
if ! az acr show --name "${ACR_NAME}" --resource-group "${RG}" >/dev/null 2>&1; then
  log "Creating ACR ${ACR_NAME} (Premium, public access disabled, admin disabled)..."
  az acr create \
    --resource-group "${RG}" \
    --name "${ACR_NAME}" \
    --sku Premium \
    --location "${LOCATION}" \
    --admin-enabled false \
    --public-network-enabled false \
    --output none
fi

log "Building and pushing image ${IMAGE_REF} via ACR Tasks (inside Gov boundary)..."
az acr build \
  --registry "${ACR_NAME}" \
  --image "${IMAGE_REPO}:${IMAGE_TAG}" \
  --file Dockerfile \
  . \
  --output none

# ---------------------------------------------------------------------------
# 3. Deploy infrastructure with Bicep
# ---------------------------------------------------------------------------
log "Deploying Bicep stack (${DEPLOYMENT_NAME})..."
az deployment group create \
  --resource-group "${RG}" \
  --name "${DEPLOYMENT_NAME}" \
  --template-file "${TEMPLATE_FILE}" \
  --parameters @"${PARAMS_FILE}" \
  --parameters location="${LOCATION}" \
               containerImage="${IMAGE_REF}" \
               classification="${CLASSIFICATION}" \
               impactLevel="${IMPACT_LEVEL}" \
  --output none

# ---------------------------------------------------------------------------
# 4. Outputs
# ---------------------------------------------------------------------------
APP_URL=$(az deployment group show \
  --resource-group "${RG}" \
  --name "${DEPLOYMENT_NAME}" \
  --query "properties.outputs.appUrl.value" -o tsv)

KV_URI=$(az deployment group show \
  --resource-group "${RG}" \
  --name "${DEPLOYMENT_NAME}" \
  --query "properties.outputs.keyVaultUri.value" -o tsv)

log "Deployment complete."
log "  Application URL (internal, reach via WAF/Private Endpoint): ${APP_URL}"
log "  Key Vault URI: ${KV_URI}"
log "  Image: ${IMAGE_REF}"
log "Reminder: the Container Apps Environment is internal-only — front it with"
log "Azure Front Door Gov / App Gateway WAF v2 before exposing to authorized users."
