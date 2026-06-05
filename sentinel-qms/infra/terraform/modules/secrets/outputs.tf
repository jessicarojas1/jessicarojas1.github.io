output "secret_arns" {
  description = "Map of secret name => ARN (AWS)."
  value       = local.is_aws ? { for k, s in aws_secretsmanager_secret.this : k => s.arn } : {}
}

output "secret_names" {
  description = "Map of secret short-name => full provider name."
  value       = local.is_aws ? { for k, s in aws_secretsmanager_secret.this : k => s.name } : { for k, s in azurerm_key_vault_secret.this : k => s.name }
}

output "key_vault_id" {
  description = "Key Vault id (Azure)."
  value       = local.is_azure ? azurerm_key_vault.this[0].id : null
}

output "key_vault_uri" {
  description = "Key Vault URI (Azure)."
  value       = local.is_azure ? azurerm_key_vault.this[0].vault_uri : null
}

output "key_vault_name" {
  description = "Key Vault name (Azure)."
  value       = local.is_azure ? azurerm_key_vault.this[0].name : null
}
