#!/usr/bin/env bash
# =============================================================================
# deploy-aws-govcloud.sh — end-to-end deploy to AWS GovCloud:
#   1. build + push images to ECR-gov
#   2. terraform init/plan/apply (aws-govcloud stack)
#   3. run DB migrations (alembic) as a one-off Fargate task
#   4. force a new ECS deployment to pick up the new image
#
# Usage:  deploy-aws-govcloud.sh [tag]
# Env:    AWS_REGION (default us-gov-west-1), TF_VAR_oidc_client_secret,
#         ECR_REGISTRY (e.g. <acct>.dkr.ecr.us-gov-west-1.amazonaws.com)
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TF_DIR="${HERE}/../terraform/aws-govcloud"
AWS_REGION="${AWS_REGION:-us-gov-west-1}"
TAG="${1:-$(git rev-parse --short HEAD 2>/dev/null || echo latest)}"

: "${ECR_REGISTRY:?Set ECR_REGISTRY to your ECR GovCloud registry host}"

echo "==> [1/4] Build & push images (tag=${TAG})"
"${HERE}/build-and-push.sh" aws "${ECR_REGISTRY}" "${TAG}"

BACKEND_IMAGE="${ECR_REGISTRY}/sentinel-qms/backend:${TAG}"
FRONTEND_IMAGE="${ECR_REGISTRY}/sentinel-qms/frontend:${TAG}"

echo "==> [2/4] Terraform apply"
terraform -chdir="${TF_DIR}" init -input=false
terraform -chdir="${TF_DIR}" apply -input=false -auto-approve \
  -var "backend_image=${BACKEND_IMAGE}" \
  -var "frontend_image=${FRONTEND_IMAGE}"

CLUSTER="$(terraform -chdir="${TF_DIR}" output -raw ecs_cluster_name)"
BACKEND_SVC="$(terraform -chdir="${TF_DIR}" output -raw backend_service_name)"
FRONTEND_SVC="$(terraform -chdir="${TF_DIR}" output -raw frontend_service_name)"

echo "==> [3/4] Database migrations (alembic upgrade head)"
# Reuse the backend task definition; override the command to run migrations.
TASK_DEF="$(aws ecs describe-services --cluster "${CLUSTER}" \
  --services "${BACKEND_SVC}" --region "${AWS_REGION}" \
  --query 'services[0].taskDefinition' --output text)"
NETWORK_CFG="$(aws ecs describe-services --cluster "${CLUSTER}" \
  --services "${BACKEND_SVC}" --region "${AWS_REGION}" \
  --query 'services[0].networkConfiguration' --output json)"

aws ecs run-task --cluster "${CLUSTER}" --task-definition "${TASK_DEF}" \
  --launch-type FARGATE --region "${AWS_REGION}" \
  --network-configuration "${NETWORK_CFG}" \
  --overrides '{"containerOverrides":[{"name":"backend","command":["alembic","upgrade","head"]}]}' \
  --started-by "deploy-${TAG}"

echo "==> [4/4] Force new ECS deployments"
aws ecs update-service --cluster "${CLUSTER}" --service "${BACKEND_SVC}" \
  --force-new-deployment --region "${AWS_REGION}" >/dev/null
aws ecs update-service --cluster "${CLUSTER}" --service "${FRONTEND_SVC}" \
  --force-new-deployment --region "${AWS_REGION}" >/dev/null

echo "==> Done. ALB: $(terraform -chdir="${TF_DIR}" output -raw alb_dns_name)"
