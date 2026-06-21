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

echo ">> Deploying Container App (+ Application Gateway v2 / WAF_v2 by default)"
# The default posture provisions Application Gateway v2 + WAF_v2 in front of an
# internal, VNet-integrated Container App. The gateway serves HTTPS only and so
# needs a TLS certificate:
#   - Preferred: a Key Vault certificate
#       APPGW_KV_CERT_SECRET_ID  (Key Vault cert secret id)
#       APPGW_IDENTITY_ID        (user-assigned identity that can read it)
#   - Lab/test: a base64 PFX
#       APPGW_SSL_CERT_DATA / APPGW_SSL_CERT_PASSWORD
# To skip the gateway and expose the Container App ingress directly, set
#   FRONT_WITH_APPGW=false
SESSION_SECRET="${AEROMARKUP_SECRET:?Set AEROMARKUP_SECRET to a >=32 char session signing key}"
FRONT_WITH_APPGW="${FRONT_WITH_APPGW:-true}"

EXTRA_PARAMS=( "frontWithAppGateway=${FRONT_WITH_APPGW}" )
[ -n "${APPGW_KV_CERT_SECRET_ID:-}" ] && EXTRA_PARAMS+=( "appGwKeyVaultCertSecretId=${APPGW_KV_CERT_SECRET_ID}" )
[ -n "${APPGW_IDENTITY_ID:-}" ]       && EXTRA_PARAMS+=( "appGwIdentityId=${APPGW_IDENTITY_ID}" )
[ -n "${APPGW_SSL_CERT_DATA:-}" ]     && EXTRA_PARAMS+=( "appGwSslCertData=${APPGW_SSL_CERT_DATA}" )
[ -n "${APPGW_SSL_CERT_PASSWORD:-}" ] && EXTRA_PARAMS+=( "appGwSslCertPassword=${APPGW_SSL_CERT_PASSWORD}" )

az deployment group create \
  --resource-group "$RG" \
  --template-file deploy/azure-gov/containerapp.bicep \
  --parameters image="$IMAGE_TAG" databaseUrl="$DB_URL" sessionSecret="$SESSION_SECRET" \
               "${EXTRA_PARAMS[@]}"

echo ">> Done. The public entry-point URL is in the deployment 'url' output."
