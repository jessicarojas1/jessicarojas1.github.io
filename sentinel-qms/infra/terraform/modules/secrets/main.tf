# =============================================================================
# Secrets module — centralized secret storage, CMK-encrypted.
#   AWS:   Secrets Manager (one secret per entry, KMS-encrypted)
#   Azure: Key Vault + secrets (RBAC auth, private, soft-delete + purge protect)
# =============================================================================

locals {
  is_aws   = var.cloud == "aws"
  is_azure = var.cloud == "azure"
}

# -----------------------------------------------------------------------------
# AWS Secrets Manager
# -----------------------------------------------------------------------------
resource "aws_secretsmanager_secret" "this" {
  for_each                = local.is_aws ? var.secrets : {}
  name                    = "${var.name_prefix}/${each.key}"
  kms_key_id              = var.kms_key_arn
  recovery_window_in_days = var.recovery_window_days
  tags                    = merge(var.tags, { Name = "${var.name_prefix}/${each.key}" })
}

resource "aws_secretsmanager_secret_version" "this" {
  for_each      = local.is_aws ? var.secrets : {}
  secret_id     = aws_secretsmanager_secret.this[each.key].id
  secret_string = each.value
}

# -----------------------------------------------------------------------------
# Azure Key Vault
# -----------------------------------------------------------------------------
resource "azurerm_key_vault" "this" {
  count               = local.is_azure ? 1 : 0
  name                = var.azure_key_vault_name
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  tenant_id           = var.azure_tenant_id
  sku_name            = "premium" # HSM-backed keys for CMK / FIPS 140-2 L3

  enable_rbac_authorization     = true
  purge_protection_enabled      = true
  soft_delete_retention_days    = 90
  public_network_access_enabled = false

  network_acls {
    default_action             = "Deny"
    bypass                     = "AzureServices"
    virtual_network_subnet_ids = var.azure_subnet_ids
  }

  tags = var.tags
}

resource "azurerm_role_assignment" "admins" {
  for_each             = local.is_azure ? toset(var.azure_admin_object_ids) : []
  scope                = azurerm_key_vault.this[0].id
  role_definition_name = "Key Vault Secrets Officer"
  principal_id         = each.value
}

resource "azurerm_key_vault_secret" "this" {
  for_each     = local.is_azure ? var.secrets : {}
  name         = replace(each.key, "_", "-")
  value        = each.value
  key_vault_id = azurerm_key_vault.this[0].id
  content_type = "text/plain"
  tags         = var.tags

  depends_on = [azurerm_role_assignment.admins]
}
