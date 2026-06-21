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

// Layer-7 / edge protection (C2):
// This template now PROVISIONS Azure Application Gateway v2 + WAF_v2 (OWASP CRS
// in Prevention mode) in front of the Container App for layer-7 filtering, a
// stable VIP, and TLS termination at the edge — mirroring the sibling citadel /
// sentinel-qms Azure Gov stacks (see sentinel-qms/infra/terraform/azure-gov/
// appgateway.tf). The gateway, WAF policy, public IP, and the VNet that makes
// the Container Apps environment internal are defined in ./appgateway.bicep and
// the VNet block below, wired as a module from this file.
//
// Default posture (secure): `frontWithAppGateway = true` =>
//   - a VNet-integrated, INTERNAL Container Apps environment (no public ingress)
//   - the Container App ingress is reachable only from inside the VNet
//   - the App Gateway (public) is the single internet entry point, with WAF
//   - HTTP(80) is redirected to HTTPS(443); the gateway serves HTTPS only.
//
// Flip `frontWithAppGateway = false` to skip the gateway and expose the
// Container App ingress directly (still TLS-only — H9/H10 hardening preserved:
// transport 'http' is HTTPS-terminated and allowInsecure = false either way).
//
// TLS cert for the gateway: prefer a Key Vault certificate (appGwKeyVaultCertSecretId
// + appGwIdentityId). For lab/test you may pass a base64 PFX (appGwSslCertData /
// appGwSslCertPassword). One of the two is required when fronting with the gateway.

@description('Provision Application Gateway v2 + WAF_v2 in front of the Container App and run the Container Apps environment internal (VNet-integrated). Set false to expose the Container App ingress directly (TLS still enforced).')
param frontWithAppGateway bool = true

@description('CIDR for the VNet created when fronting with App Gateway.')
param vnetAddressPrefix string = '10.30.0.0/16'

@description('Subnet CIDR delegated to the Container Apps environment (infrastructure subnet). Must be /23 or larger for workload-profile envs.')
param infraSubnetPrefix string = '10.30.0.0/23'

@description('Subnet CIDR dedicated to the Application Gateway (must contain only the gateway).')
param appGatewaySubnetPrefix string = '10.30.4.0/24'

@description('OWASP Core Rule Set version for the WAF managed rule set.')
@allowed([
  '3.2'
  '3.1'
])
param owaspRuleSetVersion string = '3.2'

@description('WAF mode. Keep Prevention for production.')
@allowed([
  'Prevention'
  'Detection'
])
param wafMode string = 'Prevention'

@description('Key Vault certificate secret id for the gateway HTTPS listener (preferred). Leave empty to use a PFX instead.')
param appGwKeyVaultCertSecretId string = ''

@description('User-assigned managed identity resource id the gateway uses to read the Key Vault certificate. Required when appGwKeyVaultCertSecretId is set.')
param appGwIdentityId string = ''

@description('Base64-encoded PFX for the gateway HTTPS listener (lab/test only; prefer Key Vault). Leave empty if using Key Vault.')
@secure()
param appGwSslCertData string = ''

@description('Password for the PFX in appGwSslCertData.')
@secure()
param appGwSslCertPassword string = ''

// When fronting with App Gateway, the Container App ingress is internal
// (external = false). When not, it is exposed directly (external = true).
var ingressExternal = !frontWithAppGateway

resource logs 'Microsoft.OperationalInsights/workspaces@2022-10-01' = {
  name: '${appName}-logs'
  location: location
  properties: { sku: { name: 'PerGB2018' }, retentionInDays: 30 }
}

// VNet + subnets — only created when fronting with App Gateway, so that the
// Container Apps environment can be internal and the gateway can be placed in a
// dedicated subnet. A Container App can only be truly internal (no public FQDN)
// when its environment is VNet-integrated, hence this block.
resource vnet 'Microsoft.Network/virtualNetworks@2023-11-01' = if (frontWithAppGateway) {
  name: '${appName}-vnet'
  location: location
  properties: {
    addressSpace: {
      addressPrefixes: [ vnetAddressPrefix ]
    }
    subnets: [
      {
        name: 'infra'
        properties: {
          addressPrefix: infraSubnetPrefix
        }
      }
      {
        name: 'appgateway'
        properties: {
          addressPrefix: appGatewaySubnetPrefix
        }
      }
    ]
  }
}

// Subnet ids (only meaningful when frontWithAppGateway = true).
var infraSubnetId = frontWithAppGateway ? resourceId('Microsoft.Network/virtualNetworks/subnets', '${appName}-vnet', 'infra') : ''
var appGatewaySubnetId = frontWithAppGateway ? resourceId('Microsoft.Network/virtualNetworks/subnets', '${appName}-vnet', 'appgateway') : ''

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
    // VNet-integrated + internal only when fronting with App Gateway. Without a
    // VNet the environment (and therefore the app) cannot be made internal.
    vnetConfiguration: frontWithAppGateway ? {
      infrastructureSubnetId: infraSubnetId
      internal: true
    } : null
  }
  dependsOn: frontWithAppGateway ? [ vnet ] : []
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

// =============================================================================
// Application Gateway v2 + WAF_v2 (module) — provisioned when frontWithAppGateway.
// Forwards public HTTPS traffic to the internal Container App FQDN.
// =============================================================================
module appGateway 'appgateway.bicep' = if (frontWithAppGateway) {
  name: '${appName}-appgw'
  params: {
    location: location
    appName: appName
    appGatewaySubnetId: appGatewaySubnetId
    backendFqdn: app.properties.configuration.ingress.fqdn
    healthProbePath: '/api/health'
    wafMode: wafMode
    owaspRuleSetVersion: owaspRuleSetVersion
    keyVaultCertSecretId: appGwKeyVaultCertSecretId
    gatewayIdentityId: appGwIdentityId
    sslCertData: appGwSslCertData
    sslCertPassword: appGwSslCertPassword
  }
}

@description('Direct Container App ingress URL. When fronted by App Gateway this is the INTERNAL FQDN (reachable only from inside the VNet).')
output containerAppUrl string = 'https://${app.properties.configuration.ingress.fqdn}'

@description('Public entry-point URL. App Gateway VIP/FQDN when fronted; otherwise the Container App ingress.')
output url string = frontWithAppGateway ? 'https://${appGateway.outputs.publicFqdn}' : 'https://${app.properties.configuration.ingress.fqdn}'

@description('App Gateway public IP (empty when not fronting with the gateway).')
output appGatewayPublicIp string = frontWithAppGateway ? appGateway.outputs.publicIp : ''
