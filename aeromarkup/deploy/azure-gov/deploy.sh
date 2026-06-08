#!/usr/bin/env bash
# AeroMarkup — build & push to ACR, then deploy the Container App in
# Azure Government. Prereqs: az CLI logged in to an Azure Gov cloud
# (`az cloud set --name AzureUSGovernment`), an ACR, a resource group,
# and an Azure Database for PostgreSQL Flexible Server.
# Run from the aeromarkup/ directory.
set -euo pipefail

RG="${AZ_RESOURCE_GROUP:-aeromarkup-rg}"
ACR="${AZ_ACR:-aeromarkupacr}"          # ACR name (without .azurecr.us)
IMAGE_TAG="${ACR}.azurecr.us/aeromarkup:latest"
DB_URL="${DATABASE_URL:?Set DATABASE_URL to your Azure Gov Postgres connection string}"

echo ">> Building & pushing image via ACR Tasks"
az acr build --registry "$ACR" --image aeromarkup:latest .

echo ">> Deploying Container App"
az deployment group create \
  --resource-group "$RG" \
  --template-file deploy/azure-gov/containerapp.bicep \
  --parameters image="$IMAGE_TAG" databaseUrl="$DB_URL"

echo ">> Done. The app URL is in the deployment 'url' output."
