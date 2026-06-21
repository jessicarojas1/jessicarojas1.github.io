#!/usr/bin/env bash
# =============================================================================
# CITADEL — Google Cloud Platform (GCP) deploy script
# Builds & pushes the production container to Artifact Registry, then applies the
# Terraform stack (Cloud Run + Cloud SQL + Secret Manager + optional ext. LB).
#
# Default region: us-central1 | Cloud Run v2 | Cloud SQL (PostgreSQL, private IP)
#
# Prereqs: gcloud SDK (authenticated: `gcloud auth login` + `gcloud auth configure-docker`),
#          docker (or podman alias), terraform >= 1.5.
#          A GCP project with billing enabled and Owner/Editor + Project IAM Admin.
#
# Required env: PROJECT_ID  (target GCP project)
# Optional env: REGION, PROJECT, ENVIRONMENT, REPO_ID, IMAGE_NAME, IMAGE_TAG,
#               LB_DOMAINS (comma-separated, for the managed SSL cert)
# =============================================================================
set -euo pipefail

# ----------------------------------------------------------------------------
# Configuration (override via environment variables)
# ----------------------------------------------------------------------------
PROJECT_ID="${PROJECT_ID:?Set PROJECT_ID to your target GCP project}"
REGION="${REGION:-us-central1}"
PROJECT="${PROJECT:-citadel}"
ENVIRONMENT="${ENVIRONMENT:-prod}"
REPO_ID="${REPO_ID:-citadel}"
IMAGE_NAME="${IMAGE_NAME:-citadel-server}"

# Build context = repo ROOT (the image is built from there per the app facts:
#   docker build -f citadel/server/Dockerfile -t citadel-server .)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../../.." && pwd)}"
DOCKERFILE="${DOCKERFILE:-${REPO_ROOT}/citadel/server/Dockerfile}"

# Use the git short SHA as the immutable image tag.
IMAGE_TAG="${IMAGE_TAG:-$(git -C "${REPO_ROOT}" rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M%S)}"

REGISTRY_HOST="${REGION}-docker.pkg.dev"
IMAGE_REPO="${REGISTRY_HOST}/${PROJECT_ID}/${REPO_ID}"
IMAGE_URI="${IMAGE_REPO}/${IMAGE_NAME}"

echo "==> CITADEL GCP deploy"
echo "    Project     : ${PROJECT_ID}"
echo "    Region      : ${REGION}"
echo "    Name prefix : ${PROJECT}-${ENVIRONMENT}"
echo "    Image       : ${IMAGE_URI}:${IMAGE_TAG}"
echo "    Build ctx   : ${REPO_ROOT}"
echo "    Dockerfile  : ${DOCKERFILE}"

# ----------------------------------------------------------------------------
# Bootstrap APIs needed BEFORE the first terraform apply so the docker push has
# a target. Terraform also manages these (idempotent).
# ----------------------------------------------------------------------------
echo "==> Ensuring core APIs are enabled (artifactregistry, run, sqladmin, ...)"
gcloud services enable \
  artifactregistry.googleapis.com \
  run.googleapis.com \
  sqladmin.googleapis.com \
  secretmanager.googleapis.com \
  vpcaccess.googleapis.com \
  compute.googleapis.com \
  servicenetworking.googleapis.com \
  --project "${PROJECT_ID}"

# ----------------------------------------------------------------------------
# Ensure the Artifact Registry repository exists (Terraform owns it long-term,
# but we may need it before the first apply). Idempotent.
# ----------------------------------------------------------------------------
if ! gcloud artifacts repositories describe "${REPO_ID}" \
  --location "${REGION}" --project "${PROJECT_ID}" >/dev/null 2>&1; then
  echo "==> Creating Artifact Registry repo ${REPO_ID}"
  gcloud artifacts repositories create "${REPO_ID}" \
    --repository-format=docker \
    --location="${REGION}" \
    --project "${PROJECT_ID}" \
    --description="CITADEL server container images"
fi

# ----------------------------------------------------------------------------
# Authenticate Docker to Artifact Registry.
# ----------------------------------------------------------------------------
echo "==> Configuring docker auth for ${REGISTRY_HOST}"
gcloud auth configure-docker "${REGISTRY_HOST}" --quiet

# ----------------------------------------------------------------------------
# Build & push the production image (linux/amd64 to match Cloud Run).
# ----------------------------------------------------------------------------
echo "==> Building ${IMAGE_URI}:${IMAGE_TAG}"
docker build \
  --platform linux/amd64 \
  --file "${DOCKERFILE}" \
  --tag "${IMAGE_URI}:${IMAGE_TAG}" \
  --tag "${IMAGE_URI}:latest" \
  "${REPO_ROOT}"

echo "==> Pushing image to Artifact Registry"
docker push "${IMAGE_URI}:${IMAGE_TAG}"
docker push "${IMAGE_URI}:latest"

# ----------------------------------------------------------------------------
# Terraform: init / plan / apply.
# ----------------------------------------------------------------------------
TF_VARS=(
  -var "project_id=${PROJECT_ID}"
  -var "region=${REGION}"
  -var "project=${PROJECT}"
  -var "environment=${ENVIRONMENT}"
  -var "artifact_repo_id=${REPO_ID}"
  -var "image_name=${IMAGE_NAME}"
  -var "image_tag=${IMAGE_TAG}"
)

# Optional: managed-cert domains for the external LB (comma-separated -> HCL list).
if [[ -n "${LB_DOMAINS:-}" ]]; then
  HCL_LIST="[$(echo "${LB_DOMAINS}" | sed 's/[^,]*/"&"/g')]"
  TF_VARS+=(-var "lb_domains=${HCL_LIST}")
fi

echo "==> Terraform init"
terraform -chdir="${SCRIPT_DIR}" init -input=false

echo "==> Terraform plan"
terraform -chdir="${SCRIPT_DIR}" plan -input=false "${TF_VARS[@]}" -out tfplan

echo "==> Terraform apply"
terraform -chdir="${SCRIPT_DIR}" apply -input=false tfplan

# ----------------------------------------------------------------------------
# Print the resulting service URL + LB IP.
# ----------------------------------------------------------------------------
SERVICE_URL="$(terraform -chdir="${SCRIPT_DIR}" output -raw service_url 2>/dev/null || true)"
LB_IP="$(terraform -chdir="${SCRIPT_DIR}" output -raw load_balancer_ip 2>/dev/null || true)"

echo ""
echo "============================================================"
echo " CITADEL deployed to GCP (${PROJECT_ID} / ${REGION})"
echo " Service URL : ${SERVICE_URL:-<see cloud_run_url output>}"
if [[ -n "${LB_IP}" ]]; then
  echo " LB IP       : ${LB_IP}"
  echo " DNS         : create an A record for your domain(s) -> ${LB_IP}"
  echo "               then wait for the managed SSL cert to become ACTIVE."
fi
echo " Image       : ${IMAGE_URI}:${IMAGE_TAG}"
echo "============================================================"
echo "Secrets (JWT, admin password, tokens, DATABASE_URL) are generated and stored"
echo "in Secret Manager automatically. Retrieve the bootstrap admin password with:"
echo "  gcloud secrets versions access latest \\"
echo "    --secret=${PROJECT}-${ENVIRONMENT}-admin-password --project=${PROJECT_ID}"
