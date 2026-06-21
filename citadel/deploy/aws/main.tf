###############################################################################
# CITADEL — AWS Commercial Deployment
# main.tf — Data sources, locals, KMS CMK, ECR, CloudWatch log groups
#
# Architecture:
#   Internet -> AWS WAFv2 -> ALB (HTTPS, TLS1.2+) -> ECS Fargate (private)
#   ECR (scan-on-push) | KMS CMK | Secrets Manager | RDS PostgreSQL (private)
#   CloudWatch Logs | VPC (public+private subnets, NAT) across 2 AZs
#
# Partition: aws (commercial)   Default region: us-east-1
# Provider/version constraints live in versions.tf; resources are split across
# network.tf, secrets.tf, rds.tf, alb.tf, ecs.tf for readability.
###############################################################################

###############################################################################
# Data sources — partition / account / region awareness
###############################################################################

# In commercial AWS this resolves to partition = "aws", so all ARNs below are
# constructed partition-aware via data.aws_partition.current.partition.
data "aws_partition" "current" {}
data "aws_caller_identity" "current" {}
data "aws_region" "current" {}

data "aws_availability_zones" "available" {
  state = "available"
}

locals {
  partition  = data.aws_partition.current.partition # "aws" in commercial
  account_id = data.aws_caller_identity.current.account_id
  name       = "${var.project}-${var.environment}"

  azs = length(var.availability_zones) > 0 ? var.availability_zones : slice(data.aws_availability_zones.available.names, 0, 2)

  create_vpc = var.vpc_id == ""
  vpc_id     = local.create_vpc ? aws_vpc.this[0].id : var.vpc_id

  # Commercial ECR registry URI: <acct>.dkr.ecr.<region>.amazonaws.com
  ecr_registry = "${local.account_id}.dkr.ecr.${var.region}.amazonaws.com"

  container_name = "citadel"

  # DATABASE_URL is assembled from the RDS endpoint + generated password and
  # stored as a Secrets Manager secret (see secrets.tf). sslmode=require pairs
  # with the parameter group that forces SSL and the app's PGSSL=1.
  database_url = "postgresql://${var.db_username}:${urlencode(random_password.db.result)}@${aws_db_instance.this.address}:${aws_db_instance.this.port}/${var.db_name}?sslmode=require"
}

###############################################################################
# KMS Customer Managed Key (key rotation) — SC-12, SC-13, SC-28
# Encrypts ECR, Secrets Manager, RDS, and CloudWatch Logs.
###############################################################################

resource "aws_kms_key" "this" {
  description             = "CITADEL CMK for ECR, Secrets Manager, RDS, CloudWatch Logs (commercial)"
  enable_key_rotation     = true
  deletion_window_in_days = 30
  multi_region            = false

  # Allow account admins + the CloudWatch Logs service to use the key.
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "EnableRootAccountAdmin"
        Effect    = "Allow"
        Principal = { AWS = "arn:${local.partition}:iam::${local.account_id}:root" }
        Action    = "kms:*"
        # Key-policy statement: Resource="*" scopes to this CMK only (a key policy
        # can only grant access to the key it is attached to). Not over-broad.
        Resource = "*"
      },
      {
        Sid       = "AllowCloudWatchLogs"
        Effect    = "Allow"
        Principal = { Service = "logs.${var.region}.amazonaws.com" }
        Action    = ["kms:Encrypt*", "kms:Decrypt*", "kms:ReEncrypt*", "kms:GenerateDataKey*", "kms:Describe*"]
        # Key-policy statement: Resource="*" scopes to this CMK only; access is
        # further constrained by the EncryptionContext condition below.
        Resource = "*"
        Condition = {
          ArnLike = {
            "kms:EncryptionContext:aws:logs:arn" = "arn:${local.partition}:logs:${var.region}:${local.account_id}:log-group:*"
          }
        }
      }
    ]
  })

  tags = { Name = "${local.name}-cmk" }
}

resource "aws_kms_alias" "this" {
  name          = "alias/${local.name}"
  target_key_id = aws_kms_key.this.key_id
}

###############################################################################
# ECR repository — scan on push (RA-5, SI-2), immutable tags, KMS encryption
###############################################################################

resource "aws_ecr_repository" "this" {
  name                 = local.name
  image_tag_mutability = "IMMUTABLE"
  force_delete         = false

  image_scanning_configuration {
    scan_on_push = true
  }

  encryption_configuration {
    encryption_type = "KMS"
    kms_key         = aws_kms_key.this.arn
  }

  tags = { Name = "${local.name}-ecr" }
}

resource "aws_ecr_lifecycle_policy" "this" {
  repository = aws_ecr_repository.this.name
  policy = jsonencode({
    rules = [{
      rulePriority = 1
      description  = "Expire untagged images after 14 days"
      selection    = { tagStatus = "untagged", countType = "sinceImagePushed", countUnit = "days", countNumber = 14 }
      action       = { type = "expire" }
    }]
  })
}

###############################################################################
# CloudWatch Log groups (KMS-encrypted) — AU-2, AU-9, AU-11
###############################################################################

resource "aws_cloudwatch_log_group" "app" {
  name              = "/citadel/${var.environment}/app"
  retention_in_days = var.log_retention_days
  kms_key_id        = aws_kms_key.this.arn
  tags              = { Name = "${local.name}-logs-app" }
}

resource "aws_cloudwatch_log_group" "flow" {
  name              = "/citadel/${var.environment}/vpc-flow"
  retention_in_days = var.log_retention_days
  kms_key_id        = aws_kms_key.this.arn
  tags              = { Name = "${local.name}-logs-flow" }
}
