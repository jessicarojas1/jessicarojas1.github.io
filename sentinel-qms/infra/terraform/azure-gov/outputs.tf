output "app_gateway_public_ip" {
  description = "Public IP of the Application Gateway — point agency DNS here."
  value       = azurerm_public_ip.appgw.ip_address
}

output "resource_group" {
  description = "Resource group name."
  value       = azurerm_resource_group.this.name
}

output "acr_login_server" {
  description = "Container registry login server (push images here)."
  value       = azurerm_container_registry.this.login_server
}

output "container_app_environment_id" {
  value = module.compute.container_app_environment_id
}

output "backend_fqdn" {
  description = "Backend Container App internal FQDN."
  value       = module.compute.backend_fqdn
}

output "db_host" {
  description = "Flexible Server FQDN (private)."
  value       = module.database.db_host
}

output "storage_account" {
  description = "Uploads storage account name."
  value       = module.storage.storage_account_name
}

output "key_vault_uri" {
  description = "Key Vault URI."
  value       = module.secrets.key_vault_uri
}

output "managed_identity_client_id" {
  description = "Client id of the app managed identity."
  value       = azurerm_user_assigned_identity.app.client_id
}
