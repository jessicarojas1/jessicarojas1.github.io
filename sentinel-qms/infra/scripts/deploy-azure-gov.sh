#!/usr/bin/env bash
# =============================================================================
# deploy-azure-gov.sh — end-to-end deploy to Azure Government:
#   1. terraform apply to provision ACR + infra (first pass)
#   2. build + push images to ACR
#   3. run DB migrations as a one-off Container App job
#   4. terraform apply again to roll out the new image tag
#
# Usage:  deploy-azure-gov.sh [tag]
# Env:    ARM_SUBSCRIPTION_ID, ARM_TENANT_ID, TF_VAR_oidc_client_secret
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TF_DIR="${HERE}/../terraform/azure-gov"
TAG="${1:-$(git rev-parse --short HEAD 2>/dev/null || echo latest)}"

echo "==> [1/4] Terraform init + apply (provision ACR/infra)"
terraform -chdir="${TF_DIR}" init -input=false
terraform -chdir="${TF_DIR}" apply -input=false -auto-approve \
  -var "backend_image=sentinel-qms/backend:${TAG}" \
  -var "frontend_image=sentinel-qms/frontend:${TAG}"

ACR="$(terraform -chdir="${TF_DIR}" output -raw acr_login_server)"
RG="$(terraform -chdir="${TF_DIR}" output -raw resource_group)"

echo "==> [2/4] Build & push images to ${ACR} (tag=${TAG})"
"${HERE}/build-and-push.sh" azure "${ACR}" "${TAG}"

echo "==> [3/4] Database migrations (alembic upgrade head)"
az containerapp job create \
  --name "sentinel-qms-migrate-${TAG}" \
  --resource-group "${RG}" \
  --environment "$(terraform -chdir="${TF_DIR}" output -raw container_app_environment_id)" \
  --trigger-type Manual --replica-timeout 600 --replica-retry-limit 1 \
  --image "${ACR}/sentinel-qms/backend:${TAG}" \
  --cpu 0.5 --memory 1Gi \
  --command "alembic" "upgrade" "head" 2>/dev/null || \
az containerapp job start --name "sentinel-qms-migrate-${TAG}" --resource-group "${RG}"

echo "==> [4/4] Roll out new image tag"
terraform -chdir="${TF_DIR}" apply -input=false -auto-approve \
  -var "backend_image=sentinel-qms/backend:${TAG}" \
  -var "frontend_image=sentinel-qms/frontend:${TAG}"

echo "==> Done. App Gateway IP: $(terraform -chdir="${TF_DIR}" output -raw app_gateway_public_ip)"
