// AeroMarkup — Azure Government Container App
// Deploy to an Azure US Government region (e.g. usgovvirginia).
// Pulls the image from Azure Container Registry and reads DATABASE_URL
// from a secret. Pair with Azure Database for PostgreSQL Flexible Server.
//
//   az deployment group create -g <rg> \
//     --template-file deploy/azure-gov/containerapp.bicep \
//     --parameters image=<acr>.azurecr.us/aeromarkup:latest \
//                  databaseUrl='postgres://...usgovcloudapi.net:5432/aeromarkup?sslmode=require'

@description('Container image, e.g. <registry>.azurecr.us/aeromarkup:latest')
param image string

@description('PostgreSQL connection string (stored as a secret)')
@secure()
param databaseUrl string

@description('Session signing key (>= 32 chars; stored as a secret)')
@secure()
param sessionSecret string

@description('Azure US Gov location')
param location string = resourceGroup().location

param appName string = 'aeromarkup'

// Layer-7 / edge protection recommendation (C2):
// This template exposes the Container App directly to the internet
// (ingress.external = true) with TLS enforced by the Container Apps ingress.
// For defense-in-depth, front this app with Azure Application Gateway + WAF_v2
// (OWASP CRS in Prevention mode) for layer-7 filtering, rate limiting, and a
// stable VIP — mirroring the sibling citadel / sentinel-qms Azure Gov stacks
// (see citadel/deploy/azure-gov/main.bicep which runs the Container App as
// ingress.external = false behind a gateway, and
// sentinel-qms/infra/terraform/azure-gov/appgateway.tf).
// When fronting with App Gateway, flip `external` below to false so the app is
// only reachable through the WAF. Full App Gateway provisioning is out of scope
// for this single bicep file and is tracked as a follow-up.
@description('Set false and front with Application Gateway + WAF_v2 for layer-7 filtering. Leave true to expose the Container App ingress directly (TLS still enforced).')
param ingressExternal bool = true

resource logs 'Microsoft.OperationalInsights/workspaces@2022-10-01' = {
  name: '${appName}-logs'
  location: location
  properties: { sku: { name: 'PerGB2018' }, retentionInDays: 30 }
}

resource env 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: '${appName}-env'
  location: location
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: logs.properties.customerId
        sharedKey: logs.listKeys().primarySharedKey
      }
    }
  }
}

resource app 'Microsoft.App/containerApps@2024-03-01' = {
  name: appName
  location: location
  properties: {
    managedEnvironmentId: env.id
    configuration: {
      ingress: {
        external: ingressExternal
        targetPort: 8080
        // Force HTTPS: 'http' is HTTPS-terminated by the Container Apps ingress.
        // 'auto' would also permit cleartext HTTP/2 (h2c); avoid it (SC-8).
        transport: 'http'
        // Reject any plain-HTTP request at the ingress — TLS only (C2 / SC-8).
        allowInsecure: false
      }
      secrets: [
        { name: 'database-url', value: databaseUrl }
        { name: 'session-secret', value: sessionSecret }
      ]
    }
    template: {
      containers: [
        {
          name: appName
          image: image
          resources: { cpu: json('0.5'), memory: '1Gi' }
          env: [
            { name: 'PORT', value: '8080' }
            // AUTO_MIGRATE gated off (C2): running schema migrations on every boot
            // of a publicly reachable app is an integrity/availability risk.
            // Run migrations as a one-shot job / init step (e.g. a separate
            // Container Apps job or `az containerapp job` invoking the migration
            // command) before/at deploy time — not on each app replica start.
            { name: 'AUTO_MIGRATE', value: '0' }
            { name: 'ENVIRONMENT', value: 'production' }
            { name: 'DATABASE_URL', secretRef: 'database-url' }
            { name: 'AEROMARKUP_SECRET', secretRef: 'session-secret' }
          ]
          probes: [
            {
              type: 'Liveness'
              httpGet: { path: '/api/health', port: 8080 }
              initialDelaySeconds: 15
              periodSeconds: 30
            }
            {
              type: 'Readiness'
              httpGet: { path: '/api/health', port: 8080 }
              initialDelaySeconds: 5
              periodSeconds: 10
            }
          ]
        }
      ]
      scale: { minReplicas: 1, maxReplicas: 3 }
    }
  }
}

output url string = 'https://${app.properties.configuration.ingress.fqdn}'
