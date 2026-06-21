# =============================================================================
# Application Gateway v2 with WAF — public TLS entry point that forwards to the
# internal Container Apps environment. Routes /api/* to the backend, everything
# else to the frontend SPA.
#
# NOTE: provide a TLS certificate via Key Vault in production. For brevity this
# stack wires the listener to a Key Vault certificate secret id passed as a
# variable; supply your agency cert there.
# =============================================================================

variable "appgw_keyvault_cert_secret_id" {
  description = "Key Vault certificate secret id for the HTTPS listener. Required: the gateway only serves HTTPS — there is no plaintext-HTTP fallback."
  type        = string

  validation {
    condition     = trimspace(var.appgw_keyvault_cert_secret_id) != ""
    error_message = "appgw_keyvault_cert_secret_id must be set to a Key Vault certificate secret id. The Application Gateway serves HTTPS only; a plaintext-HTTP listener is not permitted."
  }
}

resource "azurerm_public_ip" "appgw" {
  name                = "${local.name_prefix}-agw-pip"
  resource_group_name = azurerm_resource_group.this.name
  location            = var.location
  allocation_method   = "Static"
  sku                 = "Standard"
  tags                = local.common_tags
}

locals {
  agw_name            = "${local.name_prefix}-agw"
  backend_pool_name   = "ca-backend-pool"
  frontend_pool_name  = "ca-frontend-pool"
  https_listener_name = "https-listener"
  frontend_ip_name    = "agw-feip"
  frontend_port_name  = "https-port"
}

resource "azurerm_application_gateway" "this" {
  name                = local.agw_name
  resource_group_name = azurerm_resource_group.this.name
  location            = var.location
  tags                = local.common_tags

  sku {
    name = "WAF_v2"
    tier = "WAF_v2"
  }

  autoscale_configuration {
    min_capacity = 2
    max_capacity = 10
  }

  identity {
    type         = "UserAssigned"
    identity_ids = [azurerm_user_assigned_identity.app.id]
  }

  gateway_ip_configuration {
    name      = "agw-ipcfg"
    subnet_id = module.network.public_subnet_id
  }

  frontend_port {
    name = local.frontend_port_name
    port = 443
  }

  frontend_ip_configuration {
    name                 = local.frontend_ip_name
    public_ip_address_id = azurerm_public_ip.appgw.id
  }

  # TLS cert sourced from Key Vault (managed identity reads it). Mandatory:
  # see the validation on var.appgw_keyvault_cert_secret_id.
  ssl_certificate {
    name                = "agw-cert"
    key_vault_secret_id = var.appgw_keyvault_cert_secret_id
  }

  backend_address_pool {
    name  = local.backend_pool_name
    fqdns = [module.compute.backend_fqdn]
  }

  backend_address_pool {
    name  = local.frontend_pool_name
    fqdns = [module.compute.frontend_fqdn]
  }

  backend_http_settings {
    name                                = "be-https"
    cookie_based_affinity               = "Disabled"
    port                                = 443
    protocol                            = "Https"
    request_timeout                     = 30
    pick_host_name_from_backend_address = true
    probe_name                          = "be-probe"
  }

  backend_http_settings {
    name                                = "fe-https"
    cookie_based_affinity               = "Disabled"
    port                                = 443
    protocol                            = "Https"
    request_timeout                     = 30
    pick_host_name_from_backend_address = true
    probe_name                          = "fe-probe"
  }

  probe {
    name                                      = "be-probe"
    protocol                                  = "Https"
    path                                      = "/health"
    interval                                  = 30
    timeout                                   = 10
    unhealthy_threshold                       = 3
    pick_host_name_from_backend_http_settings = true
    match {
      status_code = ["200"]
    }
  }

  probe {
    name                                      = "fe-probe"
    protocol                                  = "Https"
    path                                      = "/"
    interval                                  = 30
    timeout                                   = 10
    unhealthy_threshold                       = 3
    pick_host_name_from_backend_http_settings = true
    match {
      status_code = ["200-399"]
    }
  }

  # HTTPS-only listener. The certificate is mandatory (see the variable
  # validation), so there is no plaintext-HTTP fallback.
  http_listener {
    name                           = local.https_listener_name
    frontend_ip_configuration_name = local.frontend_ip_name
    frontend_port_name             = local.frontend_port_name
    protocol                       = "Https"
    ssl_certificate_name           = "agw-cert"
  }

  # Default route -> frontend SPA.
  request_routing_rule {
    name                       = "default-rule"
    rule_type                  = "PathBasedRouting"
    http_listener_name         = local.https_listener_name
    url_path_map_name          = "qms-paths"
    priority                   = 100
  }

  url_path_map {
    name                               = "qms-paths"
    default_backend_address_pool_name  = local.frontend_pool_name
    default_backend_http_settings_name = "fe-https"

    path_rule {
      name                       = "api"
      paths                      = ["/api/*", "/health", "/docs", "/openapi.json"]
      backend_address_pool_name  = local.backend_pool_name
      backend_http_settings_name = "be-https"
    }
  }

  waf_configuration {
    enabled          = true
    firewall_mode    = "Prevention"
    rule_set_type    = "OWASP"
    rule_set_version = "3.2"
  }

}
