# =============================================================================
# Sentinel QMS — AWS GovCloud (us-gov-west-1) root stack.
#
# Topology:
#   Internet -> WAF -> ALB (HTTPS/FIPS) -> ECS Fargate (backend:8000, frontend:8080)
#                                          in private subnets
#   ECS -> RDS PostgreSQL 16 (Multi-AZ, encrypted) in isolated data subnets
#   ECS -> S3 (SSE-KMS, versioned) for uploads
#   Secrets in Secrets Manager (CMK-encrypted); logs/alarms in CloudWatch.
# =============================================================================

data "aws_caller_identity" "current" {}
data "aws_partition" "current" {}

locals {
  name_prefix = "${var.project}-${var.environment}"
  common_tags = {
    Project             = var.project
    Environment         = var.environment
    Owner               = var.owner
    "data-classification" = "CUI"
    Compliance          = "NIST-800-171;CMMC-L2;FedRAMP-Moderate"
    ManagedBy           = "terraform"
  }
  s3_bucket_name = "${local.name_prefix}-uploads-${data.aws_caller_identity.current.account_id}"
}

# ── Randomly generated secrets (never hardcoded) ─────────────────────────────
resource "random_password" "db" {
  length  = 32
  special = true
  # RDS disallows a handful of characters in the master password.
  override_special = "!#$%^*()-_=+[]{}"
}

resource "random_password" "jwt" {
  length  = 64
  special = false
}

# ── KMS CMK (encryption at rest for RDS, S3, Secrets, Logs) ──────────────────
resource "aws_kms_key" "main" {
  description             = "${local.name_prefix} CMK for data at rest"
  deletion_window_in_days = 30
  enable_key_rotation     = true
  tags                    = local.common_tags
}

resource "aws_kms_alias" "main" {
  name          = "alias/${local.name_prefix}"
  target_key_id = aws_kms_key.main.key_id
}

# Allow CloudWatch Logs to use the CMK.
resource "aws_kms_key_policy" "main" {
  key_id = aws_kms_key.main.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "EnableRoot"
        Effect    = "Allow"
        Principal = { AWS = "arn:${data.aws_partition.current.partition}:iam::${data.aws_caller_identity.current.account_id}:root" }
        Action    = "kms:*"
        Resource  = "*"
      },
      {
        Sid       = "AllowCloudWatchLogs"
        Effect    = "Allow"
        Principal = { Service = "logs.${var.aws_region}.amazonaws.com" }
        Action    = ["kms:Encrypt", "kms:Decrypt", "kms:GenerateDataKey*", "kms:Describe*"]
        Resource  = "*"
      }
    ]
  })
}

# ── VPC flow-log IAM role ─────────────────────────────────────────────────────
resource "aws_cloudwatch_log_group" "flow_logs" {
  name              = "/sentinel-qms/${local.name_prefix}/vpc-flow-logs"
  retention_in_days = 365
  kms_key_id        = aws_kms_key.main.arn
  tags              = local.common_tags
}

data "aws_iam_policy_document" "flow_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["vpc-flow-logs.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "flow_logs" {
  name               = "${local.name_prefix}-flow-logs"
  assume_role_policy = data.aws_iam_policy_document.flow_assume.json
  tags               = local.common_tags
}

resource "aws_iam_role_policy" "flow_logs" {
  name = "${local.name_prefix}-flow-logs"
  role = aws_iam_role.flow_logs.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["logs:CreateLogStream", "logs:PutLogEvents", "logs:DescribeLogGroups", "logs:DescribeLogStreams"]
      Resource = "${aws_cloudwatch_log_group.flow_logs.arn}:*"
    }]
  })
}

# ── RDS enhanced-monitoring role ──────────────────────────────────────────────
data "aws_iam_policy_document" "rds_monitoring_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["monitoring.rds.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "rds_monitoring" {
  name               = "${local.name_prefix}-rds-monitoring"
  assume_role_policy = data.aws_iam_policy_document.rds_monitoring_assume.json
  tags               = local.common_tags
}

resource "aws_iam_role_policy_attachment" "rds_monitoring" {
  role       = aws_iam_role.rds_monitoring.name
  policy_arn = "arn:${data.aws_partition.current.partition}:iam::aws:policy/service-role/AmazonRDSEnhancedMonitoringRole"
}

# ── Modules ───────────────────────────────────────────────────────────────────
module "network" {
  source = "../modules/network"

  cloud                    = "aws"
  name_prefix              = local.name_prefix
  tags                     = local.common_tags
  cidr_block               = var.vpc_cidr
  aws_availability_zones   = var.availability_zones
  ingress_allowed_cidrs    = var.ingress_allowed_cidrs
  flow_log_destination_arn = aws_cloudwatch_log_group.flow_logs.arn
  flow_log_role_arn        = aws_iam_role.flow_logs.arn
}

module "storage" {
  source = "../modules/storage"

  cloud       = "aws"
  name_prefix = local.name_prefix
  tags        = local.common_tags
  bucket_name = local.s3_bucket_name
  kms_key_arn = aws_kms_key.main.arn
}

module "database" {
  source = "../modules/database"

  cloud                  = "aws"
  name_prefix            = local.name_prefix
  tags                   = local.common_tags
  db_admin_password      = random_password.db.result
  instance_class         = var.db_instance_class
  allocated_storage_gb   = var.db_allocated_storage_gb
  multi_az               = var.db_multi_az
  kms_key_arn            = aws_kms_key.main.arn
  data_subnet_ids        = module.network.data_subnet_ids
  vpc_security_group_ids = [module.network.database_security_group_id]
  monitoring_role_arn    = aws_iam_role.rds_monitoring.arn
}

module "observability" {
  source = "../modules/observability"

  cloud           = "aws"
  name_prefix     = local.name_prefix
  tags            = local.common_tags
  kms_key_arn     = aws_kms_key.main.arn
  alarm_email     = var.alarm_email
  log_group_names = ["backend", "frontend"]
  alb_arn_suffix  = module.compute.alb_arn_suffix
  db_instance_id  = module.database.db_identifier
}

# DATABASE_URL is assembled from the DB host + generated password and stored
# whole in Secrets Manager so the container only ever reads one secret.
locals {
  database_url = replace(module.database.database_url_template, "{{password}}", random_password.db.result)

  app_secrets = {
    JWT_SECRET   = random_password.jwt.result
    DATABASE_URL = local.database_url
    OIDC_CLIENT_SECRET = var.oidc_client_secret
  }
}

module "secrets" {
  source = "../modules/secrets"

  cloud       = "aws"
  name_prefix = local.name_prefix
  tags        = local.common_tags
  kms_key_arn = aws_kms_key.main.arn
  secrets     = local.app_secrets
}

module "compute" {
  source = "../modules/compute"

  cloud          = "aws"
  name_prefix    = local.name_prefix
  environment    = var.environment
  tags           = local.common_tags
  backend_image  = var.backend_image
  frontend_image = var.frontend_image

  vpc_id                = module.network.vpc_id
  public_subnet_ids     = module.network.public_subnet_ids
  private_subnet_ids    = module.network.private_subnet_ids
  alb_security_group_id = module.network.alb_security_group_id
  app_security_group_id = module.network.app_security_group_id
  acm_certificate_arn   = var.acm_certificate_arn
  kms_key_arn           = aws_kms_key.main.arn

  log_group_backend  = module.observability.log_group_names["backend"]
  log_group_frontend = module.observability.log_group_names["frontend"]

  non_secret_env = {
    ENVIRONMENT     = var.environment
    LOG_LEVEL       = var.log_level
    CORS_ORIGINS    = var.cors_origins
    STORAGE_BACKEND = "s3"
    S3_BUCKET       = module.storage.bucket_name
    S3_REGION       = var.aws_region
    OIDC_ISSUER     = var.oidc_issuer
    OIDC_CLIENT_ID  = var.oidc_client_id
  }

  secret_env_arns = {
    JWT_SECRET         = module.secrets.secret_arns["JWT_SECRET"]
    DATABASE_URL       = module.secrets.secret_arns["DATABASE_URL"]
    OIDC_CLIENT_SECRET = module.secrets.secret_arns["OIDC_CLIENT_SECRET"]
  }
}

# ── Grant the ECS task role least-privilege access to the uploads bucket ─────
data "aws_iam_policy_document" "task_s3" {
  statement {
    sid       = "ObjectRW"
    actions   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
    resources = ["${module.storage.bucket_arn}/*"]
  }
  statement {
    sid       = "ListBucket"
    actions   = ["s3:ListBucket"]
    resources = [module.storage.bucket_arn]
  }
  statement {
    sid       = "UseCmk"
    actions   = ["kms:Encrypt", "kms:Decrypt", "kms:GenerateDataKey"]
    resources = [aws_kms_key.main.arn]
  }
}

resource "aws_iam_role_policy" "task_s3" {
  name   = "${local.name_prefix}-task-s3"
  role   = element(split("/", module.compute.task_role_arn), length(split("/", module.compute.task_role_arn)) - 1)
  policy = data.aws_iam_policy_document.task_s3.json
}
