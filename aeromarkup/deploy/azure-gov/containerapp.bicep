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
        external: true
        targetPort: 8080
        transport: 'auto'
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
            { name: 'AUTO_MIGRATE', value: '1' }
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
