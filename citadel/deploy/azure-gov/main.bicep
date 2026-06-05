// =============================================================================
// CITADEL — Azure Government deployment (Bicep)
// Code Inspection, Threat Analysis & Deployment Evaluation Lab
//
// Provisions a hardened, IL4/IL5-oriented stack:
//   - Log Analytics workspace
//   - User-assigned managed identity (UAMI)  [no stored credentials]
//   - Azure Container Registry (Premium, private, admin disabled)
//   - Key Vault (Premium/HSM, RBAC, soft-delete + purge protection)
//   - Storage account (private quarantine blob, infra encryption, no public access)
//   - Container Apps Environment (internal) + Container App (CITADEL front-end)
//   - Role assignments (AcrPull, Key Vault Secrets User, Blob Data Contributor)
//   - Diagnostic settings → Log Analytics
//
// Targets Azure Government (default region: usgovvirginia).
// Designed to be coherent/parameterized; review network + DNS before production use.
// =============================================================================

targetScope = 'resourceGroup'

// ---------------------------------------------------------------------------
// Parameters
// ---------------------------------------------------------------------------

@description('Azure Government region. Primary: usgovvirginia, DR: usgovarizona.')
@allowed([
  'usgovvirginia'
  'usgovarizona'
])
param location string = 'usgovvirginia'

@description('Short name prefix used to build resource names (lowercase alphanumerics).')
@minLength(3)
@maxLength(12)
param namePrefix string = 'citadel'

@description('Deployment environment moniker.')
@allowed([
  'prod'
  'staging'
  'test'
])
param environmentName string = 'prod'

@description('Fully-qualified container image, e.g. acrcitadelprod.azurecr.us/citadel/web:1.0.0')
param containerImage string

@description('Container target/listen port for the CITADEL front-end (Nginx non-root).')
param containerPort int = 8080

@description('CPU cores for the front-end container (Container Apps fractional CPU).')
param cpuCores string = '0.5'

@description('Memory for the front-end container.')
param memorySize string = '1.0Gi'

@description('Minimum replicas (set 0 to allow scale-to-zero when idle).')
@minValue(0)
param minReplicas int = 1

@description('Maximum replicas.')
@minValue(1)
param maxReplicas int = 5

@description('Make the Container Apps Environment internal-only (no public ingress). Keep true for IL4/IL5.')
param internalOnly bool = true

@description('Log Analytics retention in days (FedRAMP: keep >= 365).')
@minValue(30)
@maxValue(730)
param logRetentionDays int = 365

@description('Data classification tag.')
param classification string = 'CUI'

@description('DoD Impact Level tag.')
param impactLevel string = 'IL4'

// ---------------------------------------------------------------------------
// Variables
// ---------------------------------------------------------------------------

var suffix = uniqueString(resourceGroup().id, namePrefix, environmentName)
var baseName = '${namePrefix}-${environmentName}'

// Storage account names: 3-24, lowercase alphanumerics only
var storageName = toLower('st${namePrefix}${environmentName}${take(suffix, 6)}')
// ACR names: 5-50, alphanumeric only
var acrName = toLower('acr${namePrefix}${environmentName}${take(suffix, 6)}')
// Key Vault names: 3-24, alphanumeric + hyphen
var keyVaultName = take('kv-${namePrefix}-${environmentName}-${take(suffix, 6)}', 24)
var lawName = 'log-${baseName}'
var uamiName = 'id-${baseName}'
var caeName = 'cae-${baseName}'
var appName = 'ca-${baseName}-web'
var quarantineContainer = 'quarantine'

var commonTags = {
  system: 'CITADEL'
  environment: environmentName
  classification: classification
  impactLevel: impactLevel
  fedramp: 'High'
  managedBy: 'bicep'
}

// Built-in role definition IDs (stable across clouds)
var roleAcrPull = '7f951dda-4ed3-4680-a7ca-43fe172d538d'
var roleKvSecretsUser = '4633458b-17de-408a-b874-0445c86b69e6'
var roleBlobDataContributor = 'ba92f5b4-2d11-453d-a403-e96b0029c9fe'

// ---------------------------------------------------------------------------
// Log Analytics workspace
// ---------------------------------------------------------------------------

resource law 'Microsoft.OperationalInsights/workspaces@2023-09-01' = {
  name: lawName
  location: location
  tags: commonTags
  properties: {
    sku: {
      name: 'PerGB2018'
    }
    retentionInDays: logRetentionDays
    features: {
      enableLogAccessUsingOnlyResourcePermissions: true
    }
    publicNetworkAccessForIngestion: 'Enabled'
    publicNetworkAccessForQuery: 'Enabled'
  }
}

// ---------------------------------------------------------------------------
// User-assigned managed identity (no stored credentials anywhere)
// ---------------------------------------------------------------------------

resource uami 'Microsoft.ManagedIdentity/userAssignedIdentities@2023-01-31' = {
  name: uamiName
  location: location
  tags: commonTags
}

// ---------------------------------------------------------------------------
// Azure Container Registry (Premium, private, admin disabled)
// ---------------------------------------------------------------------------

resource acr 'Microsoft.ContainerRegistry/registries@2023-11-01-preview' = {
  name: acrName
  location: location
  tags: commonTags
  sku: {
    name: 'Premium' // Premium required for Private Endpoints + geo-replication
  }
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    adminUserEnabled: false // SC-/IA-: no admin credentials; pulls via UAMI + AcrPull
    publicNetworkAccess: 'Disabled' // SC-7: reach only via Private Endpoint
    networkRuleBypassOptions: 'AzureServices'
    zoneRedundancy: 'Enabled'
    policies: {
      quarantinePolicy: {
        status: 'enabled' // images quarantined until scanned (RA-5/SI-2)
      }
      trustPolicy: {
        type: 'Notary'
        status: 'enabled' // content trust / signed images (SI-7)
      }
      retentionPolicy: {
        status: 'enabled'
        days: 30
      }
      exportPolicy: {
        status: 'disabled'
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Key Vault (Premium/HSM, RBAC, soft-delete + purge protection)
// ---------------------------------------------------------------------------

resource keyVault 'Microsoft.KeyVault/vaults@2023-07-01' = {
  name: keyVaultName
  location: location
  tags: commonTags
  properties: {
    tenantId: subscription().tenantId
    sku: {
      family: 'A'
      name: 'premium' // HSM-backed keys (FIPS 140-3)
    }
    enableRbacAuthorization: true // RBAC, not legacy access policies (AC-3)
    enableSoftDelete: true
    softDeleteRetentionInDays: 90
    enablePurgeProtection: true // required for FedRAMP; cannot be disabled once set
    publicNetworkAccess: 'Disabled' // SC-7: Private Endpoint only
    networkAcls: {
      defaultAction: 'Deny'
      bypass: 'AzureServices'
    }
  }
}

// ---------------------------------------------------------------------------
// Storage account — quarantine for untrusted uploads
// ---------------------------------------------------------------------------

resource storage 'Microsoft.Storage/storageAccounts@2023-05-01' = {
  name: storageName
  location: location
  tags: commonTags
  sku: {
    name: 'Standard_GRS' // geo-redundant for DR (usgovvirginia <-> usgovarizona)
  }
  kind: 'StorageV2'
  identity: {
    type: 'SystemAssigned'
  }
  properties: {
    minimumTlsVersion: 'TLS1_2' // SC-8/SC-13
    supportsHttpsTrafficOnly: true
    allowBlobPublicAccess: false // SC-7/SC-28: no anonymous blob access
    allowSharedKeyAccess: false // force Entra ID (OAuth); no account keys/SAS
    publicNetworkAccess: 'Disabled' // SC-7: Private Endpoint only
    defaultToOAuthAuthentication: true
    allowCrossTenantReplication: false
    networkAcls: {
      defaultAction: 'Deny'
      bypass: 'AzureServices'
    }
    encryption: {
      requireInfrastructureEncryption: true // double encryption at rest (SC-28)
      keySource: 'Microsoft.Storage'
      services: {
        blob: {
          enabled: true
        }
        file: {
          enabled: true
        }
      }
    }
  }
}

resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2023-05-01' = {
  parent: storage
  name: 'default'
  properties: {
    isVersioningEnabled: true
    deleteRetentionPolicy: {
      enabled: true
      days: 30
    }
    containerDeleteRetentionPolicy: {
      enabled: true
      days: 30
    }
  }
}

resource quarantine 'Microsoft.Storage/storageAccounts/blobServices/containers@2023-05-01' = {
  parent: blobService
  name: quarantineContainer
  properties: {
    publicAccess: 'None' // never publicly served back to clients (SC-7/SI-3)
  }
}

// ---------------------------------------------------------------------------
// Container Apps Environment (internal-only) + Container App
// ---------------------------------------------------------------------------

resource cae 'Microsoft.App/managedEnvironments@2024-03-01' = {
  name: caeName
  location: location
  tags: commonTags
  properties: {
    appLogsConfiguration: {
      destination: 'log-analytics'
      logAnalyticsConfiguration: {
        customerId: law.properties.customerId
        sharedKey: law.listKeys().primarySharedKey
      }
    }
    // internal: true => no public ingress; reachable only via Private Endpoint behind WAF
    vnetConfiguration: {
      internal: internalOnly
    }
    zoneRedundant: true
    workloadProfiles: [
      {
        name: 'Consumption'
        workloadProfileType: 'Consumption'
      }
    ]
  }
}

resource app 'Microsoft.App/containerApps@2024-03-01' = {
  name: appName
  location: location
  tags: commonTags
  identity: {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${uami.id}': {}
    }
  }
  properties: {
    managedEnvironmentId: cae.id
    workloadProfileName: 'Consumption'
    configuration: {
      activeRevisionsMode: 'Single'
      // Ingress stays internal; external=false keeps it off the public internet.
      ingress: {
        external: false
        targetPort: containerPort
        transport: 'auto'
        allowInsecure: false // TLS only (SC-8)
        traffic: [
          {
            latestRevision: true
            weight: 100
          }
        ]
      }
      // Pull from ACR using the UAMI (AcrPull) — no registry username/password.
      registries: [
        {
          server: split(replace(replace(containerImage, 'https://', ''), 'http://', ''), '/')[0]
          identity: uami.id
        }
      ]
      // Secrets, if any, are sourced from Key Vault via the UAMI (no plaintext here).
      secrets: []
    }
    template: {
      containers: [
        {
          name: 'citadel-web'
          image: containerImage
          resources: {
            cpu: json(cpuCores)
            memory: memorySize
          }
          env: [
            {
              name: 'CITADEL_ENV'
              value: environmentName
            }
            {
              name: 'CITADEL_QUARANTINE_ACCOUNT'
              value: storage.name
            }
            {
              name: 'CITADEL_QUARANTINE_CONTAINER'
              value: quarantineContainer
            }
            {
              name: 'AZURE_CLIENT_ID'
              value: uami.properties.clientId // for DefaultAzureCredential -> UAMI
            }
          ]
          probes: [
            {
              type: 'Liveness'
              httpGet: {
                path: '/healthz'
                port: containerPort
              }
              initialDelaySeconds: 10
              periodSeconds: 30
            }
            {
              type: 'Readiness'
              httpGet: {
                path: '/healthz'
                port: containerPort
              }
              initialDelaySeconds: 5
              periodSeconds: 15
            }
          ]
        }
      ]
      scale: {
        minReplicas: minReplicas
        maxReplicas: maxReplicas
        rules: [
          {
            name: 'http-scale'
            http: {
              metadata: {
                concurrentRequests: '50'
              }
            }
          }
        ]
      }
    }
  }
}

// ---------------------------------------------------------------------------
// Role assignments (least privilege, managed identity)
// ---------------------------------------------------------------------------

// UAMI -> AcrPull on the registry
resource raAcrPull 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(acr.id, uami.id, roleAcrPull)
  scope: acr
  properties: {
    principalId: uami.properties.principalId
    principalType: 'ServicePrincipal'
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', roleAcrPull)
  }
}

// UAMI -> Key Vault Secrets User
resource raKvSecrets 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(keyVault.id, uami.id, roleKvSecretsUser)
  scope: keyVault
  properties: {
    principalId: uami.properties.principalId
    principalType: 'ServicePrincipal'
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', roleKvSecretsUser)
  }
}

// UAMI -> Storage Blob Data Contributor (scoped to the storage account)
resource raBlob 'Microsoft.Authorization/roleAssignments@2022-04-01' = {
  name: guid(storage.id, uami.id, roleBlobDataContributor)
  scope: storage
  properties: {
    principalId: uami.properties.principalId
    principalType: 'ServicePrincipal'
    roleDefinitionId: subscriptionResourceId('Microsoft.Authorization/roleDefinitions', roleBlobDataContributor)
  }
}

// ---------------------------------------------------------------------------
// Diagnostic settings -> Log Analytics  (AU-2 / AU-6 / AU-12)
// ---------------------------------------------------------------------------

resource diagKeyVault 'Microsoft.Insights/diagnosticSettings@2021-05-01-preview' = {
  name: 'diag-keyvault'
  scope: keyVault
  properties: {
    workspaceId: law.id
    logs: [
      {
        categoryGroup: 'audit'
        enabled: true
      }
      {
        categoryGroup: 'allLogs'
        enabled: true
      }
    ]
    metrics: [
      {
        category: 'AllMetrics'
        enabled: true
      }
    ]
  }
}

resource diagStorage 'Microsoft.Insights/diagnosticSettings@2021-05-01-preview' = {
  name: 'diag-storage'
  scope: storage
  properties: {
    workspaceId: law.id
    metrics: [
      {
        category: 'Transaction'
        enabled: true
      }
    ]
  }
}

resource diagAcr 'Microsoft.Insights/diagnosticSettings@2021-05-01-preview' = {
  name: 'diag-acr'
  scope: acr
  properties: {
    workspaceId: law.id
    logs: [
      {
        categoryGroup: 'allLogs'
        enabled: true
      }
    ]
    metrics: [
      {
        category: 'AllMetrics'
        enabled: true
      }
    ]
  }
}

// ---------------------------------------------------------------------------
// Outputs
// ---------------------------------------------------------------------------

@description('Internal FQDN of the CITADEL front-end (reachable via Private Endpoint behind the WAF).')
output appUrl string = 'https://${app.properties.configuration.ingress.fqdn}'

@description('ACR login server (Gov: *.azurecr.us).')
output acrLoginServer string = acr.properties.loginServer

@description('Key Vault URI (Gov: *.vault.usgovcloudapi.net).')
output keyVaultUri string = keyVault.properties.vaultUri

@description('User-assigned managed identity principal (object) ID.')
output uamiPrincipalId string = uami.properties.principalId

@description('User-assigned managed identity client ID.')
output uamiClientId string = uami.properties.clientId

@description('Quarantine storage account name.')
output quarantineStorageAccount string = storage.name

@description('Log Analytics workspace resource ID.')
output logAnalyticsWorkspaceId string = law.id
