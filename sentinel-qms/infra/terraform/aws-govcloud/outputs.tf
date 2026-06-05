output "alb_dns_name" {
  description = "Public ALB DNS name — point your Route53/agency DNS CNAME here."
  value       = module.compute.alb_dns_name
}

output "alb_zone_id" {
  description = "ALB hosted zone id for alias records."
  value       = module.compute.alb_zone_id
}

output "ecs_cluster_name" {
  description = "ECS cluster name (used by deploy script for force-new-deployment)."
  value       = module.compute.ecs_cluster_name
}

output "backend_service_name" {
  value = module.compute.backend_service_name
}

output "frontend_service_name" {
  value = module.compute.frontend_service_name
}

output "db_host" {
  description = "RDS endpoint (private)."
  value       = module.database.db_host
}

output "uploads_bucket" {
  description = "S3 uploads bucket name."
  value       = module.storage.bucket_name
}

output "kms_key_arn" {
  description = "Account CMK ARN."
  value       = aws_kms_key.main.arn
}

output "secret_names" {
  description = "Secrets Manager secret names for the app."
  value       = module.secrets.secret_names
}
