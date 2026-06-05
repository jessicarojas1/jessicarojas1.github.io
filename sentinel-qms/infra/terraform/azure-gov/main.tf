# =============================================================================
# Sentinel QMS — Azure Government root stack.
#
# Topology:
#   Internet -> Application Gateway (WAF_v2, TLS) -> internal Container Apps
#               environment (backend:8000, frontend:8080) in delegated subnet
#   Container Apps -> PostgreSQL Flexible Server (VNet-injected, private)
#   Container Apps -> Storage Account / Blob (private, CMK) for uploads
#   Secrets in Key Vault (RBAC, private); logs in Log Analytics + Monitor alerts.
#   Pull + secret access via a User-Assigned Managed Identity.
# =============================================================================

data "azurerm_client_config" "current" {}

locals {
  name_prefix = "${var.project}-${var.environment}"
  # Storage account + ACR names must be globally unique, lowercase, alnum.
  sa_suffix = substr(replace(lower("${var.project}${var.environment}"), "-", ""), 0, 16)

  common_tags = {
    Project               = var.project
    Environment           = var.environment
    Owner                 = var.owner
    "data-classification" = "CUI"
    Compliance            = "NIST-800-171;CMMC-L2;FedRAMP-Moderate"
    ManagedBy             = "terraform"
  }
}

resource "random_string" "uniq" {
  length  = 6
  upper   = false
  special = false
}

resource "random_password" "db" {
  length           = 32
  special          = true
  override_special = "!#$%*()-_=+"
}

resource "random_password" "jwt" {
  length  = 64
  special = false
}

# ── Resource group ────────────────────────────────────────────────────────────
resource "azurerm_resource_group" "this" {
  name     = "${local.name_prefix}-rg"
  location = var.location
  tags     = local.common_tags
}

# ── User-assigned managed identity (image pull, KV, CMK) ─────────────────────
resource "azurerm_user_assigned_identity" "app" {
  name                = "${local.name_prefix}-id"
  resource_group_name = azurerm_resource_group.this.name
  location            = var.location
  tags                = local.common_tags
}

# ── Container registry (private, Premium for gov + private endpoints) ────────
resource "azurerm_container_registry" "this" {
  name                          = "${local.sa_suffix}acr${random_string.uniq.result}"
  resource_group_name           = azurerm_resource_group.this.name
  location                      = var.location
  sku                           = "Premium"
  admin_enabled                 = false
  public_network_access_enabled = false
  tags                          = local.common_tags
}

resource "azurerm_role_assignment" "acr_pull" {
  scope                = azurerm_container_registry.this.id
  role_definition_name = "AcrPull"
  principal_id         = azurerm_user_assigned_identity.app.principal_id
}

# ── Private DNS zone for Flexible Server ─────────────────────────────────────
resource "azurerm_private_dns_zone" "pg" {
  name                = "${local.name_prefix}.private.postgres.database.usgovcloudapi.net"
  resource_group_name = azurerm_resource_group.this.name
  tags                = local.common_tags
}

# ── Network ───────────────────────────────────────────────────────────────────
module "network" {
  source = "../modules/network"

  cloud                     = "azure"
  name_prefix               = local.name_prefix
  tags                      = local.common_tags
  cidr_block                = var.vnet_cidr
  public_subnet_cidrs       = [var.public_subnet_cidr]
  private_subnet_cidrs      = [var.app_subnet_cidr]
  data_subnet_cidrs         = [var.data_subnet_cidr]
  azure_location            = var.location
  azure_resource_group_name = azurerm_resource_group.this.name
}

resource "azurerm_private_dns_zone_virtual_network_link" "pg" {
  name                  = "${local.name_prefix}-pg-link"
  resource_group_name   = azurerm_resource_group.this.name
  private_dns_zone_name = azurerm_private_dns_zone.pg.name
  virtual_network_id    = module.network.vnet_id
  registration_enabled  = false
  tags                  = local.common_tags
}

# ── Key Vault / secrets ───────────────────────────────────────────────────────
module "secrets" {
  source = "../modules/secrets"

  cloud                     = "azure"
  name_prefix               = local.name_prefix
  tags                      = local.common_tags
  azure_location            = var.location
  azure_resource_group_name = azurerm_resource_group.this.name
  azure_tenant_id           = var.tenant_id
  azure_key_vault_name      = "${local.sa_suffix}kv${random_string.uniq.result}"
  azure_subnet_ids          = [module.network.app_subnet_id]
  azure_admin_object_ids    = var.admin_object_ids

  secrets = {
    jwt_secret         = random_password.jwt.result
    database_url        = replace(module.database.database_url_template, "{{password}}", random_password.db.result)
    oidc_client_secret = var.oidc_client_secret
  }
}

# Allow the app identity to read secrets.
resource "azurerm_role_assignment" "kv_reader" {
  scope                = module.secrets.key_vault_id
  role_definition_name = "Key Vault Secrets User"
  principal_id         = azurerm_user_assigned_identity.app.principal_id
}

# ── Storage ───────────────────────────────────────────────────────────────────
module "storage" {
  source = "../modules/storage"

  cloud                      = "azure"
  name_prefix                = local.name_prefix
  tags                       = local.common_tags
  azure_location             = var.location
  azure_resource_group_name  = azurerm_resource_group.this.name
  azure_storage_account_name = "${local.sa_suffix}st${random_string.uniq.result}"
  azure_subnet_ids           = [module.network.app_subnet_id]
}

resource "azurerm_role_assignment" "blob_contributor" {
  scope                = module.storage.storage_account_id
  role_definition_name = "Storage Blob Data Contributor"
  principal_id         = azurerm_user_assigned_identity.app.principal_id
}

# ── Database ──────────────────────────────────────────────────────────────────
module "database" {
  source = "../modules/database"

  cloud                     = "azure"
  name_prefix               = local.name_prefix
  tags                      = local.common_tags
  db_admin_password         = random_password.db.result
  azure_sku_name            = var.db_sku_name
  allocated_storage_gb      = var.db_storage_gb
  multi_az                  = var.db_ha_enabled
  azure_location            = var.location
  azure_resource_group_name = azurerm_resource_group.this.name
  azure_delegated_subnet_id = module.network.data_subnet_id
  azure_private_dns_zone_id = azurerm_private_dns_zone.pg.id

  depends_on = [azurerm_private_dns_zone_virtual_network_link.pg]
}

# ── Observability ─────────────────────────────────────────────────────────────
module "observability" {
  source = "../modules/observability"

  cloud                        = "azure"
  name_prefix                  = local.name_prefix
  tags                         = local.common_tags
  azure_location               = var.location
  azure_resource_group_name    = azurerm_resource_group.this.name
  alarm_email                  = var.alarm_email
  azure_monitored_resource_ids = [module.database.db_identifier]
}

# ── Compute (Container Apps) ─────────────────────────────────────────────────
module "compute" {
  source = "../modules/compute"

  cloud          = "azure"
  name_prefix    = local.name_prefix
  environment    = var.environment
  tags           = local.common_tags
  backend_image  = "${azurerm_container_registry.this.login_server}/${var.backend_image}"
  frontend_image = "${azurerm_container_registry.this.login_server}/${var.frontend_image}"

  azure_location                   = var.location
  azure_resource_group_name        = azurerm_resource_group.this.name
  azure_infra_subnet_id            = module.network.app_subnet_id
  azure_log_analytics_workspace_id = module.observability.log_analytics_workspace_id
  azure_acr_login_server           = azurerm_container_registry.this.login_server
  azure_identity_id                = azurerm_user_assigned_identity.app.id

  non_secret_env = {
    ENVIRONMENT                     = var.environment
    LOG_LEVEL                       = var.log_level
    CORS_ORIGINS                    = var.cors_origins
    STORAGE_BACKEND                 = "azure_blob"
    AZURE_STORAGE_ACCOUNT           = module.storage.storage_account_name
    AZURE_STORAGE_CONTAINER         = module.storage.container_name
    AZURE_CLIENT_ID                 = azurerm_user_assigned_identity.app.client_id
    OIDC_ISSUER                     = var.oidc_issuer
    OIDC_CLIENT_ID                  = var.oidc_client_id
  }

  # Container Apps reference Key Vault secrets by versionless secret id.
  secret_env_arns = {
    JWT_SECRET         = "${module.secrets.key_vault_uri}secrets/jwt-secret"
    DATABASE_URL       = "${module.secrets.key_vault_uri}secrets/database-url"
    OIDC_CLIENT_SECRET = "${module.secrets.key_vault_uri}secrets/oidc-client-secret"
  }

  depends_on = [azurerm_role_assignment.acr_pull, azurerm_role_assignment.kv_reader]
}
