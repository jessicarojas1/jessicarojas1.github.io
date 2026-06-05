# Network module outputs — symmetric across clouds where it makes sense.

# ── AWS ──────────────────────────────────────────────────────────────────────
output "vpc_id" {
  description = "AWS VPC id (null on Azure)."
  value       = local.is_aws ? aws_vpc.this[0].id : null
}

output "public_subnet_ids" {
  description = "Public subnet ids (AWS)."
  value       = local.is_aws ? aws_subnet.public[*].id : []
}

output "private_subnet_ids" {
  description = "Private/app subnet ids (AWS)."
  value       = local.is_aws ? aws_subnet.private[*].id : []
}

output "data_subnet_ids" {
  description = "Isolated data subnet ids (AWS)."
  value       = local.is_aws ? aws_subnet.data[*].id : []
}

output "alb_security_group_id" {
  description = "Security group for the public ALB (AWS)."
  value       = local.is_aws ? aws_security_group.alb[0].id : null
}

output "app_security_group_id" {
  description = "Security group for the app tier (AWS)."
  value       = local.is_aws ? aws_security_group.app[0].id : null
}

output "database_security_group_id" {
  description = "Security group for the database (AWS)."
  value       = local.is_aws ? aws_security_group.database[0].id : null
}

# ── Azure ────────────────────────────────────────────────────────────────────
output "vnet_id" {
  description = "Azure VNet id (null on AWS)."
  value       = local.is_azure ? azurerm_virtual_network.this[0].id : null
}

output "app_subnet_id" {
  description = "App/private subnet id (Azure)."
  value       = local.is_azure ? azurerm_subnet.private[0].id : null
}

output "data_subnet_id" {
  description = "Delegated data subnet id for Flexible Server (Azure)."
  value       = local.is_azure ? azurerm_subnet.data[0].id : null
}

output "public_subnet_id" {
  description = "Public subnet id for Application Gateway (Azure)."
  value       = local.is_azure ? azurerm_subnet.public[0].id : null
}
