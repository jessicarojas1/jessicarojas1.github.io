output "db_host" {
  description = "Database hostname / FQDN."
  value       = local.is_aws ? aws_db_instance.this[0].address : (local.is_azure ? azurerm_postgresql_flexible_server.this[0].fqdn : null)
}

output "db_port" {
  description = "Database port."
  value       = 5432
}

output "db_name" {
  description = "Database name."
  value       = var.db_name
}

output "db_identifier" {
  description = "Provider resource id of the server/instance."
  value       = local.is_aws ? aws_db_instance.this[0].id : (local.is_azure ? azurerm_postgresql_flexible_server.this[0].id : null)
}

output "database_url_template" {
  description = "SQLAlchemy URL with the password placeholder ({{password}}) to be injected from the secret."
  value       = local.is_aws ? "postgresql+psycopg://${var.db_admin_username}:{{password}}@${aws_db_instance.this[0].address}:5432/${var.db_name}?sslmode=require" : (local.is_azure ? "postgresql+psycopg://${var.db_admin_username}:{{password}}@${azurerm_postgresql_flexible_server.this[0].fqdn}:5432/${var.db_name}?sslmode=require" : null)
  sensitive   = false
}
