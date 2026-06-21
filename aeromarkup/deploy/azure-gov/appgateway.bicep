// AeroMarkup — Azure Government Application Gateway v2 + WAF_v2 (module)
// =============================================================================
// Public, TLS-terminating, layer-7 entry point that fronts the AeroMarkup
// Container App (C2 / SC-7 / SC-8 defense-in-depth). Provisions:
//   - A Standard, static public IP (stable VIP)
//   - A WAF policy: OWASP Core Rule Set, Prevention mode
//   - An Application Gateway v2 (SKU/tier WAF_v2) with:
//       * HTTPS listener on 443 (TLS terminated at the gateway)
//       * Optional HTTP listener on 80 that redirects everything to HTTPS
//       * Backend pool pointing at the Container App FQDN
//       * HTTPS backend settings with pick-host-name-from-backend (SNI)
//       * An HTTPS health probe
//       * The WAF policy attached via firewallPolicy
//
// TLS certificate handling:
//   The gateway serves HTTPS only. In Azure Government the certificate should be
//   sourced from Key Vault (pass `keyVaultCertSecretId`) so no key material lives
//   in the template. If a Key Vault secret id is not supplied the listener falls
//   back to a caller-provided PFX (`sslCertData` + `sslCertPassword`); one of the
//   two MUST be provided — there is no plaintext-HTTP-only fallback.
// =============================================================================

@description('Azure US Gov location.')
param location string

@description('Resource name prefix (e.g. aeromarkup).')
param appName string

@description('Resource ID of the subnet dedicated to the Application Gateway. Must be empty of other resources and sized /24 or larger.')
param appGatewaySubnetId string

@description('Container App ingress FQDN (the internal/external FQDN the gateway forwards to).')
param backendFqdn string

@description('Health probe path on the backend (Container App). Defaults to the AeroMarkup health endpoint.')
param healthProbePath string = '/api/health'

@description('WAF mode. Prevention actively blocks; Detection only logs. Keep Prevention for production (SC-7).')
@allowed([
  'Prevention'
  'Detection'
])
param wafMode string = 'Prevention'

@description('OWASP Core Rule Set version for the managed rule set.')
@allowed([
  '3.2'
  '3.1'
])
param owaspRuleSetVersion string = '3.2'

@description('Key Vault certificate secret id (versioned or unversioned) for the HTTPS listener. Preferred for Azure Gov — no key material in the template. Leave empty to use sslCertData instead.')
param keyVaultCertSecretId string = ''

@description('User-assigned managed identity resource id the gateway uses to read the Key Vault certificate. Required when keyVaultCertSecretId is set.')
param gatewayIdentityId string = ''

@description('Base64-encoded PFX for the HTTPS listener when not using Key Vault. Leave empty if keyVaultCertSecretId is provided.')
@secure()
param sslCertData string = ''

@description('Password for the PFX in sslCertData (if any).')
@secure()
param sslCertPassword string = ''

@description('Minimum autoscale capacity (instances) for the gateway.')
@minValue(1)
param minCapacity int = 2

@description('Maximum autoscale capacity (instances) for the gateway.')
@minValue(2)
param maxCapacity int = 10

@description('Redirect plain HTTP (port 80) to HTTPS instead of dropping it. Recommended so bookmarks/links upgrade to TLS (SC-8).')
param enableHttpToHttpsRedirect bool = true

// --- derived names -----------------------------------------------------------
var gatewayName = '${appName}-agw'
var wafPolicyName = '${appName}-waf'
var publicIpName = '${appName}-agw-pip'
var dnsLabel = toLower('${appName}-agw-${uniqueString(resourceGroup().id, appName)}')

var useKeyVaultCert = !empty(keyVaultCertSecretId)

// Resource-id helpers (the gateway references its own child resources by id).
var gwId = resourceId('Microsoft.Network/applicationGateways', gatewayName)
var feIpName = 'agw-feip'
var fePortHttpsName = 'port-443'
var fePortHttpName = 'port-80'
var bedPoolName = 'ca-backend-pool'
var beHttpSettingsName = 'be-https'
var probeName = 'be-probe'
var httpsListenerName = 'https-listener'
var httpListenerName = 'http-listener'
var sslCertName = 'agw-cert'
var routingRuleName = 'https-rule'
var redirectRuleName = 'http-to-https-rule'
var redirectConfigName = 'http-to-https'

// =============================================================================
// WAF policy — OWASP CRS, Prevention mode
// =============================================================================
resource wafPolicy 'Microsoft.Network/ApplicationGatewayWebApplicationFirewallPolicies@2023-11-01' = {
  name: wafPolicyName
  location: location
  properties: {
    policySettings: {
      state: 'Enabled'
      mode: wafMode
      requestBodyCheck: true
      maxRequestBodySizeInKb: 128
      fileUploadLimitInMb: 100
    }
    managedRules: {
      managedRuleSets: [
        {
          ruleSetType: 'OWASP'
          ruleSetVersion: owaspRuleSetVersion
        }
      ]
    }
  }
}

// =============================================================================
// Public IP — Standard, static (stable VIP)
// =============================================================================
resource publicIp 'Microsoft.Network/publicIPAddresses@2023-11-01' = {
  name: publicIpName
  location: location
  sku: {
    name: 'Standard'
  }
  properties: {
    publicIPAllocationMethod: 'Static'
    dnsSettings: {
      domainNameLabel: dnsLabel
    }
  }
}

// =============================================================================
// Application Gateway v2 / WAF_v2
// =============================================================================
resource gateway 'Microsoft.Network/applicationGateways@2023-11-01' = {
  name: gatewayName
  location: location
  // The Key Vault cert path needs a user-assigned identity with secret-get rights.
  identity: useKeyVaultCert ? {
    type: 'UserAssigned'
    userAssignedIdentities: {
      '${gatewayIdentityId}': {}
    }
  } : null
  properties: {
    sku: {
      name: 'WAF_v2'
      tier: 'WAF_v2'
    }
    autoscaleConfiguration: {
      minCapacity: minCapacity
      maxCapacity: maxCapacity
    }
    // Attach the WAF policy (firewallPolicy) — supersedes the inline wafConfiguration.
    firewallPolicy: {
      id: wafPolicy.id
    }
    sslPolicy: {
      policyType: 'Predefined'
      // TLS 1.2+ only (SC-8/SC-13). Use the modern predefined profile.
      policyName: 'AppGwSslPolicy20220101'
    }
    gatewayIPConfigurations: [
      {
        name: 'agw-ipcfg'
        properties: {
          subnet: {
            id: appGatewaySubnetId
          }
        }
      }
    ]
    frontendIPConfigurations: [
      {
        name: feIpName
        properties: {
          publicIPAddress: {
            id: publicIp.id
          }
        }
      }
    ]
    frontendPorts: [
      {
        name: fePortHttpsName
        properties: {
          port: 443
        }
      }
      {
        name: fePortHttpName
        properties: {
          port: 80
        }
      }
    ]
    sslCertificates: [
      useKeyVaultCert ? {
        name: sslCertName
        properties: {
          keyVaultSecretId: keyVaultCertSecretId
        }
      } : {
        name: sslCertName
        properties: {
          data: sslCertData
          password: sslCertPassword
        }
      }
    ]
    backendAddressPools: [
      {
        name: bedPoolName
        properties: {
          backendAddresses: [
            {
              fqdn: backendFqdn
            }
          ]
        }
      }
    ]
    probes: [
      {
        name: probeName
        properties: {
          protocol: 'Https'
          path: healthProbePath
          interval: 30
          timeout: 10
          unhealthyThreshold: 3
          // Probe the backend using the host name from the HTTP settings (SNI to the
          // Container App FQDN), so the App service responds with the right cert/vhost.
          pickHostNameFromBackendHttpSettings: true
          minServers: 0
          match: {
            statusCodes: [
              '200-399'
            ]
          }
        }
      }
    ]
    backendHttpSettingsCollection: [
      {
        name: beHttpSettingsName
        properties: {
          port: 443
          protocol: 'Https'
          cookieBasedAffinity: 'Disabled'
          requestTimeout: 30
          // Forward to the Container App over TLS using its own FQDN as the host
          // header + SNI. Container Apps ingress routes by host name, so this is
          // required for the backend to resolve the app.
          pickHostNameFromBackendAddress: true
          probe: {
            id: '${gwId}/probes/${probeName}'
          }
        }
      }
    ]
    httpListeners: [
      {
        name: httpsListenerName
        properties: {
          frontendIPConfiguration: {
            id: '${gwId}/frontendIPConfigurations/${feIpName}'
          }
          frontendPort: {
            id: '${gwId}/frontendPorts/${fePortHttpsName}'
          }
          protocol: 'Https'
          sslCertificate: {
            id: '${gwId}/sslCertificates/${sslCertName}'
          }
          requireServerNameIndication: false
        }
      }
      {
        name: httpListenerName
        properties: {
          frontendIPConfiguration: {
            id: '${gwId}/frontendIPConfigurations/${feIpName}'
          }
          frontendPort: {
            id: '${gwId}/frontendPorts/${fePortHttpName}'
          }
          protocol: 'Http'
        }
      }
    ]
    redirectConfigurations: enableHttpToHttpsRedirect ? [
      {
        name: redirectConfigName
        properties: {
          redirectType: 'Permanent'
          targetListener: {
            id: '${gwId}/httpListeners/${httpsListenerName}'
          }
          includePath: true
          includeQueryString: true
        }
      }
    ] : []
    requestRoutingRules: concat(
      [
        {
          name: routingRuleName
          properties: {
            ruleType: 'Basic'
            priority: 100
            httpListener: {
              id: '${gwId}/httpListeners/${httpsListenerName}'
            }
            backendAddressPool: {
              id: '${gwId}/backendAddressPools/${bedPoolName}'
            }
            backendHttpSettings: {
              id: '${gwId}/backendHttpSettingsCollection/${beHttpSettingsName}'
            }
          }
        }
      ],
      enableHttpToHttpsRedirect ? [
        {
          name: redirectRuleName
          properties: {
            ruleType: 'Basic'
            priority: 110
            httpListener: {
              id: '${gwId}/httpListeners/${httpListenerName}'
            }
            redirectConfiguration: {
              id: '${gwId}/redirectConfigurations/${redirectConfigName}'
            }
          }
        }
      ] : []
    )
  }
}

@description('Public IP address of the Application Gateway (stable VIP).')
output publicIp string = publicIp.properties.ipAddress

@description('Public FQDN of the Application Gateway frontend.')
output publicFqdn string = publicIp.properties.dnsSettings.fqdn

@description('Resource ID of the Application Gateway.')
output gatewayId string = gateway.id

@description('Resource ID of the WAF policy.')
output wafPolicyId string = wafPolicy.id
