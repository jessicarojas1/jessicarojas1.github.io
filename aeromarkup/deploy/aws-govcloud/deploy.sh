#!/usr/bin/env bash
# AeroMarkup — build & push to ECR, then deploy to ECS Fargate in AWS GovCloud.
# Prereqs: awscli configured for a GovCloud profile, Docker, an ECR repo,
#          an ECS cluster + service, and the DATABASE_URL secret in
#          Secrets Manager. Run from the aeromarkup/ directory.
set -euo pipefail

REGION="${AWS_REGION:-us-gov-west-1}"
ACCOUNT_ID="$(aws sts get-caller-identity --query Account --output text)"
REPO="aeromarkup"
CLUSTER="${ECS_CLUSTER:-aeromarkup-cluster}"
SERVICE="${ECS_SERVICE:-aeromarkup-svc}"
IMAGE="${ACCOUNT_ID}.dkr.ecr.${REGION}.amazonaws.com/${REPO}:latest"

echo ">> Logging in to ECR (${REGION})"
aws ecr get-login-password --region "$REGION" \
  | docker login --username AWS --password-stdin "${ACCOUNT_ID}.dkr.ecr.${REGION}.amazonaws.com"

echo ">> Ensuring ECR repository exists"
aws ecr describe-repositories --repository-names "$REPO" --region "$REGION" >/dev/null 2>&1 \
  || aws ecr create-repository --repository-name "$REPO" --region "$REGION" >/dev/null

echo ">> Building image"
docker build -t "$REPO:latest" .
docker tag "$REPO:latest" "$IMAGE"

echo ">> Pushing image"
docker push "$IMAGE"

echo ">> Registering task definition"
aws ecs register-task-definition \
  --cli-input-json file://deploy/aws-govcloud/task-definition.json \
  --region "$REGION" >/dev/null

echo ">> Forcing new deployment"
aws ecs update-service --cluster "$CLUSTER" --service "$SERVICE" \
  --force-new-deployment --region "$REGION" >/dev/null

echo ">> Done. Watch rollout in the ECS console; health check = /api/health"
