#!/usr/bin/env bash
# =============================================================================
# CITADEL — AWS GovCloud (US) deploy script
# Builds & pushes the production container to GovCloud ECR, then applies the
# Terraform stack and prints the service URL.
#
# Partition: aws-us-gov | Default region: us-gov-west-1 | FIPS endpoints enforced
#
# Prereqs: awscli v2, docker (or podman alias), terraform >= 1.5, jq
#          AWS creds for an AWS GovCloud (US) account with deploy permissions.
# =============================================================================
set -euo pipefail

# ----------------------------------------------------------------------------
# Configuration (override via environment variables)
# ----------------------------------------------------------------------------
REGION="${REGION:-us-gov-west-1}"                # us-gov-west-1 | us-gov-east-1
PROJECT="${PROJECT:-citadel}"
ENVIRONMENT="${ENVIRONMENT:-prod}"
REPO_NAME="${REPO_NAME:-${PROJECT}-${ENVIRONMENT}}"

# Build context = CITADEL app root (two levels up from deploy/aws-gov).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_CONTEXT="${APP_CONTEXT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
DOCKERFILE="${SCRIPT_DIR}/Dockerfile"

# Use the git short SHA as the immutable image tag (matches ECR IMMUTABLE policy).
IMAGE_TAG="${IMAGE_TAG:-$(git -C "${APP_CONTEXT}" rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)}"

# ----------------------------------------------------------------------------
# Force FIPS 140-3 validated endpoints for every AWS SDK/CLI call (SC-13).
# ----------------------------------------------------------------------------
export AWS_USE_FIPS_ENDPOINT=true
export AWS_DEFAULT_REGION="${REGION}"

echo "==> CITADEL GovCloud deploy"
echo "    Region     : ${REGION}"
echo "    Project    : ${PROJECT} (${ENVIRONMENT})"
echo "    Repo       : ${REPO_NAME}"
echo "    Image tag  : ${IMAGE_TAG}"
echo "    App context: ${APP_CONTEXT}"

# ----------------------------------------------------------------------------
# Resolve account / partition and build the GovCloud registry + repo URIs.
# GovCloud ECR registry host: <account-id>.dkr.ecr.<region>.amazonaws.com
# ----------------------------------------------------------------------------
ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"
PARTITION="$(aws sts get-caller-identity --query 'Arn' --output text | cut -d: -f2)"
REGISTRY="${ACCOUNT_ID}.dkr.ecr.${REGION}.amazonaws.com"
REPO_URI="${REGISTRY}/${REPO_NAME}"

echo "    Account    : ${ACCOUNT_ID}"
echo "    Partition  : ${PARTITION}"      # expect aws-us-gov
echo "    Registry   : ${REGISTRY}"

if [[ "${PARTITION}" != "aws-us-gov" ]]; then
  echo "WARNING: partition is '${PARTITION}', not 'aws-us-gov'. Confirm you are in GovCloud." >&2
fi

# ----------------------------------------------------------------------------
# Ensure the ECR repository exists (Terraform creates it, but we may need it
# before the first apply so the push target is valid). Idempotent.
# ----------------------------------------------------------------------------
if ! aws ecr describe-repositories --region "${REGION}" --repository-names "${REPO_NAME}" >/dev/null 2>&1; then
  echo "==> Creating ECR repository ${REPO_NAME} (scan-on-push, immutable tags)"
  aws ecr create-repository \
    --region "${REGION}" \
    --repository-name "${REPO_NAME}" \
    --image-tag-mutability IMMUTABLE \
    --image-scanning-configuration scanOnPush=true >/dev/null
fi

# ----------------------------------------------------------------------------
# Authenticate Docker to the GovCloud ECR registry.
# ----------------------------------------------------------------------------
echo "==> Logging in to ECR registry ${REGISTRY}"
aws ecr get-login-password --region "${REGION}" \
  | docker login --username AWS --password-stdin "${REGISTRY}"

# ----------------------------------------------------------------------------
# Build & push the hardened production image (linux/amd64 to match Fargate).
# ----------------------------------------------------------------------------
echo "==> Building image ${REPO_URI}:${IMAGE_TAG}"
docker build \
  --platform linux/amd64 \
  --file "${DOCKERFILE}" \
  --tag "${REPO_URI}:${IMAGE_TAG}" \
  --tag "${REPO_URI}:latest" \
  "${APP_CONTEXT}"

echo "==> Pushing image to GovCloud ECR"
docker push "${REPO_URI}:${IMAGE_TAG}"
docker push "${REPO_URI}:latest"

# ----------------------------------------------------------------------------
# Terraform: init / plan / apply the GovCloud stack.
# ----------------------------------------------------------------------------
echo "==> Terraform init"
terraform -chdir="${SCRIPT_DIR}" init -input=false

echo "==> Terraform plan"
terraform -chdir="${SCRIPT_DIR}" plan -input=false \
  -var "region=${REGION}" \
  -var "project=${PROJECT}" \
  -var "environment=${ENVIRONMENT}" \
  -var "image_tag=${IMAGE_TAG}" \
  -out tfplan

echo "==> Terraform apply"
terraform -chdir="${SCRIPT_DIR}" apply -input=false tfplan

# ----------------------------------------------------------------------------
# Print the resulting service URL.
# ----------------------------------------------------------------------------
SERVICE_URL="$(terraform -chdir="${SCRIPT_DIR}" output -raw service_url 2>/dev/null || true)"
echo ""
echo "============================================================"
echo " CITADEL deployed to AWS GovCloud (${REGION})"
echo " Service URL : ${SERVICE_URL:-<see ALB DNS in outputs>}"
echo " Image       : ${REPO_URI}:${IMAGE_TAG}"
echo "============================================================"
echo "Reminder: populate Secrets Manager secret out-of-band before go-live:"
echo "  aws secretsmanager put-secret-value --region ${REGION} \\"
echo "    --secret-id ${PROJECT}-${ENVIRONMENT}/app --secret-string file://secret.json"
