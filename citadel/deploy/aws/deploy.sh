#!/usr/bin/env bash
# =============================================================================
# CITADEL — AWS Commercial deploy script
# Builds & pushes the production deep-scan backend image to ECR, then applies
# the Terraform stack and prints the service URL.
#
# Partition: aws | Default region: us-east-1 | Standard (non-FIPS) endpoints
#
# Prereqs: awscli v2, docker (or podman alias), terraform >= 1.5, jq
#          AWS creds for a commercial AWS account with deploy permissions.
#
# IMPORTANT: the image is built from the REPOSITORY ROOT with the server
# Dockerfile (context must include both citadel/ and citadel/server/):
#     docker build -f citadel/server/Dockerfile -t citadel-server .
# =============================================================================
set -euo pipefail

# ----------------------------------------------------------------------------
# Configuration (override via environment variables)
# ----------------------------------------------------------------------------
REGION="${REGION:-us-east-1}"                    # any commercial AWS region
PROJECT="${PROJECT:-citadel}"
ENVIRONMENT="${ENVIRONMENT:-prod}"
REPO_NAME="${REPO_NAME:-${PROJECT}-${ENVIRONMENT}}"

# Repo root = three levels up from deploy/aws (…/citadel/deploy/aws -> repo root).
# The Dockerfile lives at citadel/server/Dockerfile relative to that root.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../../.." && pwd)}"
DOCKERFILE="${DOCKERFILE:-${REPO_ROOT}/citadel/server/Dockerfile}"

# Use the git short SHA as the immutable image tag (matches ECR IMMUTABLE policy).
IMAGE_TAG="${IMAGE_TAG:-$(git -C "${REPO_ROOT}" rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)}"

# ----------------------------------------------------------------------------
# Standard commercial endpoints by default. Export AWS_USE_FIPS_ENDPOINT=true
# (and set -var use_fips_endpoint=true) only if your scope requires FIPS.
# ----------------------------------------------------------------------------
export AWS_USE_FIPS_ENDPOINT="${AWS_USE_FIPS_ENDPOINT:-false}"
export AWS_DEFAULT_REGION="${REGION}"

echo "==> CITADEL AWS (commercial) deploy"
echo "    Region     : ${REGION}"
echo "    Project    : ${PROJECT} (${ENVIRONMENT})"
echo "    Repo       : ${REPO_NAME}"
echo "    Image tag  : ${IMAGE_TAG}"
echo "    Repo root  : ${REPO_ROOT}"
echo "    Dockerfile : ${DOCKERFILE}"

# ----------------------------------------------------------------------------
# Resolve account / partition and build the ECR registry + repo URIs.
# Commercial ECR registry host: <account-id>.dkr.ecr.<region>.amazonaws.com
# ----------------------------------------------------------------------------
ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"
PARTITION="$(aws sts get-caller-identity --query 'Arn' --output text | cut -d: -f2)"
REGISTRY="${ACCOUNT_ID}.dkr.ecr.${REGION}.amazonaws.com"
REPO_URI="${REGISTRY}/${REPO_NAME}"

echo "    Account    : ${ACCOUNT_ID}"
echo "    Partition  : ${PARTITION}"      # expect aws
echo "    Registry   : ${REGISTRY}"

if [[ "${PARTITION}" != "aws" ]]; then
  echo "WARNING: partition is '${PARTITION}', not 'aws'. This stack targets commercial AWS — use deploy/aws-gov for GovCloud." >&2
fi

# ----------------------------------------------------------------------------
# Ensure the ECR repository exists (Terraform creates it, but we need it before
# the first apply so the push target is valid). Idempotent.
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
# Authenticate Docker to the ECR registry.
# ----------------------------------------------------------------------------
echo "==> Logging in to ECR registry ${REGISTRY}"
aws ecr get-login-password --region "${REGION}" \
  | docker login --username AWS --password-stdin "${REGISTRY}"

# ----------------------------------------------------------------------------
# Build & push the production image (linux/amd64 to match Fargate X86_64).
# Build context is the REPO ROOT so both citadel/ (SPA) and citadel/server/
# (backend) are available to the Dockerfile.
# ----------------------------------------------------------------------------
echo "==> Building image ${REPO_URI}:${IMAGE_TAG}"
docker build \
  --platform linux/amd64 \
  --file "${DOCKERFILE}" \
  --tag "${REPO_URI}:${IMAGE_TAG}" \
  --tag "${REPO_URI}:latest" \
  "${REPO_ROOT}"

echo "==> Pushing image to ECR"
docker push "${REPO_URI}:${IMAGE_TAG}"
docker push "${REPO_URI}:latest"

# ----------------------------------------------------------------------------
# Terraform: init / plan / apply the commercial stack.
# Pass acm_certificate_arn via ACM_CERT_ARN for the HTTPS listener.
# ----------------------------------------------------------------------------
echo "==> Terraform init"
terraform -chdir="${SCRIPT_DIR}" init -input=false

echo "==> Terraform plan"
terraform -chdir="${SCRIPT_DIR}" plan -input=false \
  -var "region=${REGION}" \
  -var "project=${PROJECT}" \
  -var "environment=${ENVIRONMENT}" \
  -var "image_tag=${IMAGE_TAG}" \
  ${ACM_CERT_ARN:+-var "acm_certificate_arn=${ACM_CERT_ARN}"} \
  -out tfplan

echo "==> Terraform apply"
terraform -chdir="${SCRIPT_DIR}" apply -input=false tfplan

# ----------------------------------------------------------------------------
# Print the resulting service URL.
# ----------------------------------------------------------------------------
SERVICE_URL="$(terraform -chdir="${SCRIPT_DIR}" output -raw service_url 2>/dev/null || true)"
echo ""
echo "============================================================"
echo " CITADEL deployed to AWS (${REGION})"
echo " Service URL : ${SERVICE_URL:-<see ALB DNS in outputs>}"
echo " Image       : ${REPO_URI}:${IMAGE_TAG}"
echo "============================================================"
echo "Secrets (JWT, admin password, superadmin/metrics tokens, DATABASE_URL) are"
echo "generated by Terraform into Secrets Manager and injected into the task."
echo "Retrieve the bootstrap admin password with:"
echo "  aws secretsmanager get-secret-value --region ${REGION} \\"
echo "    --secret-id ${PROJECT}-${ENVIRONMENT}/admin-password --query SecretString --output text"
