#!/usr/bin/env bash
# =============================================================================
# bootstrap-remote-state.sh — one-time creation of the Terraform remote-state
# backend (encrypted) for AWS GovCloud or Azure Government.
#
# Usage:
#   bootstrap-remote-state.sh aws   [bucket] [lock-table] [kms-alias]
#   bootstrap-remote-state.sh azure [resource-group] [storage-account] [container]
# =============================================================================
set -euo pipefail

CLOUD="${1:?usage: bootstrap-remote-state.sh <aws|azure> ...}"

case "${CLOUD}" in
  aws)
    REGION="${AWS_REGION:-us-gov-west-1}"
    BUCKET="${2:-sentinel-qms-tfstate-govcloud}"
    TABLE="${3:-sentinel-qms-tflock}"
    KMS_ALIAS="${4:-alias/sentinel-qms-tfstate}"

    echo ">> Creating KMS key for state encryption"
    KEY_ID="$(aws kms create-key --description 'sentinel-qms tfstate' \
      --region "${REGION}" --query KeyMetadata.KeyId --output text)"
    aws kms create-alias --alias-name "${KMS_ALIAS}" \
      --target-key-id "${KEY_ID}" --region "${REGION}" || true
    aws kms enable-key-rotation --key-id "${KEY_ID}" --region "${REGION}"

    echo ">> Creating state bucket ${BUCKET}"
    aws s3api create-bucket --bucket "${BUCKET}" --region "${REGION}" \
      --create-bucket-configuration LocationConstraint="${REGION}" || true
    aws s3api put-bucket-versioning --bucket "${BUCKET}" \
      --versioning-configuration Status=Enabled
    aws s3api put-bucket-encryption --bucket "${BUCKET}" \
      --server-side-encryption-configuration "{\"Rules\":[{\"ApplyServerSideEncryptionByDefault\":{\"SSEAlgorithm\":\"aws:kms\",\"KMSMasterKeyID\":\"${KEY_ID}\"}}]}"
    aws s3api put-public-access-block --bucket "${BUCKET}" \
      --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true

    echo ">> Creating lock table ${TABLE}"
    aws dynamodb create-table --table-name "${TABLE}" \
      --attribute-definitions AttributeName=LockID,AttributeType=S \
      --key-schema AttributeName=LockID,KeyType=HASH \
      --billing-mode PAY_PER_REQUEST --region "${REGION}" \
      --sse-specification Enabled=true || true

    cat <<EOF

Backend config:
  -backend-config="bucket=${BUCKET}"
  -backend-config="dynamodb_table=${TABLE}"
  -backend-config="kms_key_id=${KEY_ID}"
  -backend-config="region=${REGION}"
EOF
    ;;

  azure)
    LOCATION="${AZURE_LOCATION:-usgovvirginia}"
    RG="${2:-sentinel-qms-tfstate-rg}"
    SA="${3:-sentinelqmstfstate}"
    CONTAINER="${4:-tfstate}"

    echo ">> Creating resource group ${RG}"
    az group create --name "${RG}" --location "${LOCATION}" >/dev/null

    echo ">> Creating storage account ${SA}"
    az storage account create --name "${SA}" --resource-group "${RG}" \
      --location "${LOCATION}" --sku Standard_GRS --kind StorageV2 \
      --min-tls-version TLS1_2 --allow-blob-public-access false \
      --https-only true >/dev/null

    echo ">> Creating container ${CONTAINER}"
    az storage container create --name "${CONTAINER}" \
      --account-name "${SA}" --auth-mode login >/dev/null

    cat <<EOF

Backend config:
  -backend-config="resource_group_name=${RG}"
  -backend-config="storage_account_name=${SA}"
  -backend-config="container_name=${CONTAINER}"
EOF
    ;;

  *)
    echo "unknown cloud: ${CLOUD}" >&2; exit 1 ;;
esac

echo ">> Done."
