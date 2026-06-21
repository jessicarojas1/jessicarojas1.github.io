###############################################################################
# CITADEL — AWS Commercial Deployment
# outputs.tf
###############################################################################

output "partition" {
  description = "Resolved AWS partition (expect aws in commercial)."
  value       = data.aws_partition.current.partition
}

output "region" {
  description = "Deployment region."
  value       = var.region
}

output "alb_dns_name" {
  description = "Public DNS name of the Application Load Balancer."
  value       = aws_lb.this.dns_name
}

output "service_url" {
  description = "HTTPS URL to reach the CITADEL service (front the ALB with Route 53 / ACM SAN as needed)."
  value       = "https://${aws_lb.this.dns_name}"
}

output "ecr_repository_url" {
  description = "ECR repository URL for docker push (e.g. <acct>.dkr.ecr.us-east-1.amazonaws.com/citadel-prod)."
  value       = aws_ecr_repository.this.repository_url
}

output "ecr_registry" {
  description = "ECR registry host used for docker login."
  value       = local.ecr_registry
}

output "ecs_cluster_name" {
  description = "ECS cluster name."
  value       = aws_ecs_cluster.this.name
}

output "ecs_service_name" {
  description = "ECS Fargate service name."
  value       = aws_ecs_service.this.name
}

output "cloudwatch_log_group" {
  description = "CloudWatch Logs group for the CITADEL application."
  value       = aws_cloudwatch_log_group.app.name
}

output "kms_key_arn" {
  description = "Customer Managed Key ARN protecting ECR, Secrets Manager, RDS, and Logs."
  value       = aws_kms_key.this.arn
}

output "rds_endpoint" {
  description = "RDS PostgreSQL endpoint (host:port). Reachable only from the Fargate SG."
  value       = aws_db_instance.this.endpoint
}

output "rds_address" {
  description = "RDS PostgreSQL hostname."
  value       = aws_db_instance.this.address
}

output "database_url_secret_arn" {
  description = "Secrets Manager ARN holding the assembled DATABASE_URL (injected into the task)."
  value       = aws_secretsmanager_secret.database_url.arn
}

output "secret_arns" {
  description = "Map of CITADEL secret names to their Secrets Manager ARNs."
  value = {
    jwt_secret       = aws_secretsmanager_secret.jwt_secret.arn
    admin_password   = aws_secretsmanager_secret.admin_password.arn
    superadmin_token = aws_secretsmanager_secret.superadmin_token.arn
    metrics_token    = aws_secretsmanager_secret.metrics_token.arn
    database_url     = aws_secretsmanager_secret.database_url.arn
  }
}

output "admin_email" {
  description = "Bootstrap admin email. The matching password is in the admin-password secret."
  value       = var.citadel_admin_email
}

output "waf_web_acl_arn" {
  description = "WAFv2 Web ACL ARN associated with the ALB."
  value       = aws_wafv2_web_acl.this.arn
}
