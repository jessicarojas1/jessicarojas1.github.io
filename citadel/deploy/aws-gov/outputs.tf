###############################################################################
# CITADEL — AWS GovCloud (US) Deployment
# outputs.tf
###############################################################################

output "partition" {
  description = "Resolved AWS partition (expect aws-us-gov in GovCloud)."
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
  description = "GovCloud ECR repository URL for docker push (e.g. <acct>.dkr.ecr.us-gov-west-1.amazonaws.com/citadel-prod)."
  value       = aws_ecr_repository.this.repository_url
}

output "ecr_registry" {
  description = "GovCloud ECR registry host used for docker login."
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
  description = "Customer Managed Key ARN protecting ECR, Secrets Manager, S3, and Logs."
  value       = aws_kms_key.this.arn
}

output "secret_arn" {
  description = "Secrets Manager secret ARN for CITADEL runtime secrets (populate out-of-band)."
  value       = aws_secretsmanager_secret.app.arn
}

output "quarantine_bucket" {
  description = "S3 quarantine bucket (SSE-KMS + Object Lock) for uploaded/scanned artifacts."
  value       = aws_s3_bucket.quarantine.bucket
}

output "waf_web_acl_arn" {
  description = "WAFv2 Web ACL ARN associated with the ALB."
  value       = aws_wafv2_web_acl.this.arn
}
