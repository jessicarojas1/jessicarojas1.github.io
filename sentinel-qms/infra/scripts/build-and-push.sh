#!/usr/bin/env bash
# =============================================================================
# build-and-push.sh — build the backend + frontend images and push to a
# registry. Works for both ECR (AWS GovCloud) and ACR (Azure Government).
#
# Usage:
#   build-and-push.sh aws  <ecr-registry> [tag]
#   build-and-push.sh azure <acr-login-server> [tag]
#
# Env:
#   AWS_REGION (aws, default us-gov-west-1)
# =============================================================================
set -euo pipefail

CLOUD="${1:?usage: build-and-push.sh <aws|azure> <registry> [tag]}"
REGISTRY="${2:?registry/login-server required}"
TAG="${3:-$(git rev-parse --short HEAD 2>/dev/null || echo latest)}"
AWS_REGION="${AWS_REGION:-us-gov-west-1}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${ROOT}/backend"
FRONTEND_DIR="${ROOT}/frontend"

BACKEND_IMAGE="${REGISTRY}/sentinel-qms/backend:${TAG}"
FRONTEND_IMAGE="${REGISTRY}/sentinel-qms/frontend:${TAG}"

echo ">> Logging into registry (${CLOUD})"
case "${CLOUD}" in
  aws)
    aws ecr get-login-password --region "${AWS_REGION}" \
      | docker login --username AWS --password-stdin "${REGISTRY}"
    # Ensure repos exist (idempotent).
    for repo in sentinel-qms/backend sentinel-qms/frontend; do
      aws ecr describe-repositories --repository-names "${repo}" --region "${AWS_REGION}" >/dev/null 2>&1 \
        || aws ecr create-repository --repository-name "${repo}" \
             --image-scanning-configuration scanOnPush=true \
             --encryption-configuration encryptionType=KMS \
             --region "${AWS_REGION}" >/dev/null
    done
    ;;
  azure)
    az acr login --name "${REGISTRY%%.*}"
    ;;
  *)
    echo "unknown cloud: ${CLOUD}" >&2; exit 1 ;;
esac

echo ">> Building backend -> ${BACKEND_IMAGE}"
docker build --pull -t "${BACKEND_IMAGE}" "${BACKEND_DIR}"

echo ">> Building frontend -> ${FRONTEND_IMAGE}"
docker build --pull -t "${FRONTEND_IMAGE}" "${FRONTEND_DIR}"

echo ">> Pushing images"
docker push "${BACKEND_IMAGE}"
docker push "${FRONTEND_IMAGE}"

echo "BACKEND_IMAGE=${BACKEND_IMAGE}"
echo "FRONTEND_IMAGE=${FRONTEND_IMAGE}"
