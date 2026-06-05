output "alb_dns_name" {
  description = "Public ALB DNS name (AWS)."
  value       = local.is_aws ? aws_lb.this[0].dns_name : null
}

output "alb_zone_id" {
  description = "ALB hosted zone id for Route53 alias (AWS)."
  value       = local.is_aws ? aws_lb.this[0].zone_id : null
}

output "alb_arn_suffix" {
  description = "ALB ARN suffix for CloudWatch dimensions (AWS)."
  value       = local.is_aws ? aws_lb.this[0].arn_suffix : null
}

output "ecs_cluster_name" {
  description = "ECS cluster name (AWS)."
  value       = local.is_aws ? aws_ecs_cluster.this[0].name : null
}

output "backend_service_name" {
  description = "Backend service name (AWS ECS)."
  value       = local.is_aws ? aws_ecs_service.backend[0].name : null
}

output "frontend_service_name" {
  description = "Frontend service name (AWS ECS)."
  value       = local.is_aws ? aws_ecs_service.frontend[0].name : null
}

output "task_role_arn" {
  description = "ECS task role ARN — grant S3 access to this (AWS)."
  value       = local.is_aws ? aws_iam_role.task[0].arn : null
}

# ── Azure ────────────────────────────────────────────────────────────────────
output "backend_fqdn" {
  description = "Backend Container App internal FQDN (Azure)."
  value       = local.is_azure ? azurerm_container_app.backend[0].ingress[0].fqdn : null
}

output "frontend_fqdn" {
  description = "Frontend Container App internal FQDN (Azure)."
  value       = local.is_azure ? azurerm_container_app.frontend[0].ingress[0].fqdn : null
}

output "container_app_environment_id" {
  description = "Container Apps environment id (Azure)."
  value       = local.is_azure ? azurerm_container_app_environment.this[0].id : null
}
