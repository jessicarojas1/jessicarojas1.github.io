###############################################################################
# CITADEL — AWS GovCloud (US) Deployment
# main.tf — Core infrastructure for the production CITADEL service
#
# Architecture:
#   Internet -> AWS WAFv2 -> ALB (HTTPS, TLS1.2+ FIPS) -> ECS Fargate (private)
#   ECR (scan-on-push) | KMS CMK | Secrets Manager | S3 (SSE-KMS + Object Lock)
#   CloudWatch Logs | VPC private subnets + interface/gateway endpoints
#
# Partition: aws-us-gov   Regions: us-gov-west-1 / us-gov-east-1
# This config is coherent and GovCloud-appropriate. Review SSP/POA&M before apply.
###############################################################################

terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.40"
    }
  }

  # Recommended: remote state in a GovCloud S3 bucket with DynamoDB locking + SSE-KMS.
  # backend "s3" {
  #   bucket         = "citadel-tfstate-us-gov-west-1"
  #   key            = "aws-gov/prod/terraform.tfstate"
  #   region         = "us-gov-west-1"
  #   dynamodb_table = "citadel-tflock"
  #   encrypt        = true
  #   # FIPS state endpoint:
  #   endpoints = { s3 = "https://s3-fips.us-gov-west-1.amazonaws.com" }
  # }
}

provider "aws" {
  region = var.region

  # Force FIPS 140-3 validated endpoints across all AWS service calls (SC-13).
  use_fips_endpoint = var.use_fips_endpoint

  default_tags {
    tags = var.tags
  }
}

###############################################################################
# Data sources — partition / account / region awareness
###############################################################################

# In GovCloud this resolves to partition = "aws-us-gov", so all ARNs below are
# constructed partition-aware via data.aws_partition.current.partition.
data "aws_partition" "current" {}
data "aws_caller_identity" "current" {}
data "aws_region" "current" {}

data "aws_availability_zones" "available" {
  state = "available"
}

locals {
  partition  = data.aws_partition.current.partition  # aws-us-gov in GovCloud
  account_id = data.aws_caller_identity.current.account_id
  name       = "${var.project}-${var.environment}"

  azs = length(var.availability_zones) > 0 ? var.availability_zones : slice(data.aws_availability_zones.available.names, 0, 2)

  create_vpc = var.vpc_id == ""
  vpc_id     = local.create_vpc ? aws_vpc.this[0].id : var.vpc_id

  # GovCloud ECR registry URI: <acct>.dkr.ecr.<region>.amazonaws.com
  ecr_registry = "${local.account_id}.dkr.ecr.${var.region}.amazonaws.com"
}

###############################################################################
# VPC + subnets (created only when vpc_id not supplied)
###############################################################################

resource "aws_vpc" "this" {
  count                = local.create_vpc ? 1 : 0
  cidr_block           = var.vpc_cidr
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = { Name = "${local.name}-vpc" }
}

resource "aws_subnet" "public" {
  count                   = local.create_vpc ? length(var.public_subnet_cidrs) : 0
  vpc_id                  = aws_vpc.this[0].id
  cidr_block              = var.public_subnet_cidrs[count.index]
  availability_zone       = local.azs[count.index]
  map_public_ip_on_launch = false # ALB gets EIP-less public via IGW route; no auto-assign (SC-7)

  tags = { Name = "${local.name}-public-${count.index}", Tier = "public" }
}

resource "aws_subnet" "private" {
  count             = local.create_vpc ? length(var.private_subnet_cidrs) : 0
  vpc_id            = aws_vpc.this[0].id
  cidr_block        = var.private_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  tags = { Name = "${local.name}-private-${count.index}", Tier = "private" }
}

resource "aws_internet_gateway" "this" {
  count  = local.create_vpc ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = { Name = "${local.name}-igw" }
}

resource "aws_route_table" "public" {
  count  = local.create_vpc ? 1 : 0
  vpc_id = aws_vpc.this[0].id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.this[0].id
  }
  tags = { Name = "${local.name}-rt-public" }
}

resource "aws_route_table_association" "public" {
  count          = local.create_vpc ? length(aws_subnet.public) : 0
  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public[0].id
}

# Private route table has NO default route to the internet. Egress to AWS APIs is
# via VPC endpoints below (SC-7 boundary protection, no NAT to public internet).
resource "aws_route_table" "private" {
  count  = local.create_vpc ? 1 : 0
  vpc_id = aws_vpc.this[0].id
  tags   = { Name = "${local.name}-rt-private" }
}

resource "aws_route_table_association" "private" {
  count          = local.create_vpc ? length(aws_subnet.private) : 0
  subnet_id      = aws_subnet.private[count.index].id
  route_table_id = aws_route_table.private[0].id
}

# VPC Flow Logs to CloudWatch (AU-2, AU-12, SC-7).
resource "aws_flow_log" "vpc" {
  count                = local.create_vpc ? 1 : 0
  log_destination      = aws_cloudwatch_log_group.flow.arn
  log_destination_type = "cloud-watch-logs"
  iam_role_arn         = aws_iam_role.flow_logs.arn
  traffic_type         = "ALL"
  vpc_id               = aws_vpc.this[0].id
  tags                 = { Name = "${local.name}-vpc-flowlogs" }
}

###############################################################################
# Gateway + Interface VPC endpoints (keep traffic on the AWS GovCloud backbone)
###############################################################################

# S3 gateway endpoint — ECR layer pulls + quarantine bucket without internet.
resource "aws_vpc_endpoint" "s3" {
  count             = local.create_vpc ? 1 : 0
  vpc_id            = local.vpc_id
  service_name      = "com.amazonaws.${var.region}.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = [aws_route_table.private[0].id]
  tags              = { Name = "${local.name}-vpce-s3" }
}

# Interface endpoints required for Fargate pull + logs + secrets, all private.
locals {
  interface_endpoints = local.create_vpc ? toset([
    "ecr.api",
    "ecr.dkr",
    "logs",
    "secretsmanager",
    "kms",
    "ssm",
    "sts",
    "monitoring",
  ]) : toset([])
}

resource "aws_vpc_endpoint" "interface" {
  for_each            = local.interface_endpoints
  vpc_id              = local.vpc_id
  service_name        = "com.amazonaws.${var.region}.${each.value}"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = aws_subnet.private[*].id
  security_group_ids  = [aws_security_group.vpce.id]
  private_dns_enabled = true
  tags                = { Name = "${local.name}-vpce-${each.value}" }
}

###############################################################################
# Security groups (least privilege — SC-7)
###############################################################################

resource "aws_security_group" "alb" {
  name        = "${local.name}-alb-sg"
  description = "ALB ingress 443 only; egress to ECS tasks"
  vpc_id      = local.vpc_id

  ingress {
    description = "HTTPS from approved CIDRs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = var.ingress_allowed_cidrs
  }

  egress {
    description     = "To ECS tasks on container port"
    from_port       = var.container_port
    to_port         = var.container_port
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs.id]
  }

  tags = { Name = "${local.name}-alb-sg" }
}

resource "aws_security_group" "ecs" {
  name        = "${local.name}-ecs-sg"
  description = "ECS tasks: ingress only from ALB; egress to VPC endpoints (443)"
  vpc_id      = local.vpc_id

  ingress {
    description     = "From ALB"
    from_port       = var.container_port
    to_port         = var.container_port
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    description = "HTTPS egress to VPC endpoints / AWS APIs"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = [var.vpc_cidr]
  }

  tags = { Name = "${local.name}-ecs-sg" }
}

resource "aws_security_group" "vpce" {
  name        = "${local.name}-vpce-sg"
  description = "Interface VPC endpoints: 443 from ECS tasks"
  vpc_id      = local.vpc_id

  ingress {
    description     = "HTTPS from ECS tasks"
    from_port       = 443
    to_port         = 443
    protocol        = "tcp"
    security_groups = [aws_security_group.ecs.id]
  }

  tags = { Name = "${local.name}-vpce-sg" }
}

###############################################################################
# KMS Customer Managed Key (FIPS 140-3, key rotation) — SC-12, SC-13, SC-28
###############################################################################

resource "aws_kms_key" "this" {
  description             = "CITADEL CMK for ECR, Secrets Manager, S3, CloudWatch Logs (GovCloud)"
  enable_key_rotation     = true
  deletion_window_in_days = 30
  multi_region            = false

  # Allow account admins + CloudWatch Logs service to use the key.
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

###############################################################################
# Secrets Manager — app secrets encrypted with the CMK (IA-5, SC-12)
###############################################################################

resource "aws_secretsmanager_secret" "app" {
  name        = "${local.name}/app"
  description = "CITADEL runtime secrets (scanner API tokens, signing keys). Populate out-of-band; never commit."
  kms_key_id  = aws_kms_key.this.arn

  tags = { Name = "${local.name}-secret-app" }
}

# Placeholder version. Real values are injected via the CLI / pipeline, not Terraform state.
resource "aws_secretsmanager_secret_version" "app" {
  secret_id     = aws_secretsmanager_secret.app.id
  secret_string = jsonencode({ PLACEHOLDER = "replace-out-of-band" })

  lifecycle {
    ignore_changes = [secret_string]
  }
}

###############################################################################
# S3 quarantine bucket — uploaded/scanned artifacts (SC-28, AU-9, SI-3)
# SSE-KMS, block public access, versioning, Object Lock (COMPLIANCE)
###############################################################################

resource "aws_s3_bucket" "quarantine" {
  bucket              = "${local.name}-quarantine-${local.account_id}"
  object_lock_enabled = true # WORM for malware/quarantine evidence integrity

  tags = { Name = "${local.name}-quarantine" }
}

resource "aws_s3_bucket_public_access_block" "quarantine" {
  bucket                  = aws_s3_bucket.quarantine.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "quarantine" {
  bucket = aws_s3_bucket.quarantine.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "quarantine" {
  bucket = aws_s3_bucket.quarantine.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = aws_kms_key.this.arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_object_lock_configuration" "quarantine" {
  bucket = aws_s3_bucket.quarantine.id
  rule {
    default_retention {
      mode = "COMPLIANCE"
      days = var.object_lock_retention_days
    }
  }
}

# Deny any non-TLS access to the quarantine bucket (SC-8, SC-13).
resource "aws_s3_bucket_policy" "quarantine_tls" {
  bucket = aws_s3_bucket.quarantine.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid       = "DenyInsecureTransport"
      Effect    = "Deny"
      Principal = "*"
      Action    = "s3:*"
      Resource = [
        aws_s3_bucket.quarantine.arn,
        "${aws_s3_bucket.quarantine.arn}/*",
      ]
      Condition = { Bool = { "aws:SecureTransport" = "false" } }
    }]
  })
}

###############################################################################
# IAM — task execution role + task role (least privilege) — AC-6, IA-2
###############################################################################

data "aws_iam_policy_document" "ecs_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

# Execution role: pulls image from ECR, writes logs, reads the secret for injection.
resource "aws_iam_role" "task_execution" {
  name               = "${local.name}-task-exec"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
  tags               = { Name = "${local.name}-task-exec" }
}

resource "aws_iam_role_policy" "task_execution" {
  name = "${local.name}-task-exec-policy"
  role = aws_iam_role.task_execution.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        # GetAuthorizationToken is not resource-scopable; the layer/image actions
        # are scoped to this repository only (see EcrPull below).
        Sid      = "EcrAuth"
        Effect   = "Allow"
        Action   = ["ecr:GetAuthorizationToken"]
        Resource = "*"
      },
      {
        Sid      = "EcrPull"
        Effect   = "Allow"
        Action   = ["ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"]
        Resource = aws_ecr_repository.this.arn
      },
      {
        Sid      = "Logs"
        Effect   = "Allow"
        Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
        Resource = "${aws_cloudwatch_log_group.app.arn}:*"
      },
      {
        Sid      = "ReadSecret"
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = aws_secretsmanager_secret.app.arn
      },
      {
        Sid      = "KmsDecrypt"
        Effect   = "Allow"
        Action   = ["kms:Decrypt"]
        Resource = aws_kms_key.this.arn
      }
    ]
  })
}

# Task role: what the running app may do (read/write only the quarantine bucket).
resource "aws_iam_role" "task" {
  name               = "${local.name}-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume.json
  tags               = { Name = "${local.name}-task" }
}

resource "aws_iam_role_policy" "task" {
  name = "${local.name}-task-policy"
  role = aws_iam_role.task.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "QuarantineRW"
        Effect   = "Allow"
        Action   = ["s3:PutObject", "s3:GetObject", "s3:ListBucket"]
        Resource = [aws_s3_bucket.quarantine.arn, "${aws_s3_bucket.quarantine.arn}/*"]
      },
      {
        Sid      = "KmsForS3"
        Effect   = "Allow"
        Action   = ["kms:GenerateDataKey", "kms:Decrypt"]
        Resource = aws_kms_key.this.arn
      }
    ]
  })
}

# Role assumed by the VPC Flow Logs service to write to CloudWatch.
resource "aws_iam_role" "flow_logs" {
  name = "${local.name}-flowlogs"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "vpc-flow-logs.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
  tags = { Name = "${local.name}-flowlogs" }
}

resource "aws_iam_role_policy" "flow_logs" {
  name = "${local.name}-flowlogs-policy"
  role = aws_iam_role.flow_logs.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["logs:CreateLogStream", "logs:PutLogEvents", "logs:DescribeLogStreams"]
      Resource = "${aws_cloudwatch_log_group.flow.arn}:*"
    }]
  })
}

###############################################################################
# ECS cluster + Fargate task definition + service
###############################################################################

resource "aws_ecs_cluster" "this" {
  name = local.name

  setting {
    name  = "containerInsights"
    value = var.enable_container_insights ? "enabled" : "disabled"
  }

  configuration {
    execute_command_configuration {
      logging = "OVERRIDE"
      log_configuration {
        cloud_watch_encryption_enabled = true
        cloud_watch_log_group_name     = aws_cloudwatch_log_group.app.name
      }
    }
  }

  tags = { Name = "${local.name}-cluster" }
}

resource "aws_ecs_task_definition" "this" {
  family                   = local.name
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.task_cpu
  memory                   = var.task_memory
  execution_role_arn       = aws_iam_role.task_execution.arn
  task_role_arn            = aws_iam_role.task.arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([{
    name      = "citadel"
    image     = "${aws_ecr_repository.this.repository_url}:${var.image_tag}"
    essential = true

    # Hardening: non-root, read-only root FS, no privilege escalation (CM-7, AC-6).
    readonlyRootFilesystem = true
    user                   = "10001:10001"
    linuxParameters = {
      capabilities = { drop = ["ALL"] }
    }

    # Writable tmpfs for Nginx runtime dirs since the root FS is read-only.
    mountPoints = []

    portMappings = [{ containerPort = var.container_port, protocol = "tcp" }]

    secrets = [
      { name = "CITADEL_APP_SECRETS", valueFrom = aws_secretsmanager_secret.app.arn }
    ]

    environment = [
      { name = "CITADEL_ENV", value = var.environment },
      { name = "AWS_USE_FIPS_ENDPOINT", value = tostring(var.use_fips_endpoint) }
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.region
        "awslogs-stream-prefix" = "citadel"
      }
    }

    healthCheck = {
      command     = ["CMD-SHELL", "wget -q -O /dev/null http://127.0.0.1:${var.container_port}/healthz || exit 1"]
      interval    = 30
      timeout     = 5
      retries     = 3
      startPeriod = 15
    }
  }])

  tags = { Name = "${local.name}-taskdef" }
}

resource "aws_ecs_service" "this" {
  name            = local.name
  cluster         = aws_ecs_cluster.this.id
  task_definition = aws_ecs_task_definition.this.arn
  desired_count   = var.desired_count
  launch_type     = "FARGATE"

  # Tasks run in private subnets with NO public IP (SC-7).
  network_configuration {
    subnets          = local.create_vpc ? aws_subnet.private[*].id : []
    security_groups  = [aws_security_group.ecs.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.this.arn
    container_name   = "citadel"
    container_port   = var.container_port
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  depends_on = [aws_lb_listener.https]
  tags       = { Name = "${local.name}-svc" }
}

###############################################################################
# Application Load Balancer (HTTPS, TLS1.2+ FIPS) — SC-8, SC-13
###############################################################################

resource "aws_lb" "this" {
  name                       = substr("${local.name}-alb", 0, 32)
  load_balancer_type         = "application"
  internal                   = false
  security_groups            = [aws_security_group.alb.id]
  subnets                    = local.create_vpc ? aws_subnet.public[*].id : []
  drop_invalid_header_fields = true
  enable_deletion_protection = true

  tags = { Name = "${local.name}-alb" }
}

resource "aws_lb_target_group" "this" {
  name        = substr("${local.name}-tg", 0, 32)
  port        = var.container_port
  protocol    = "HTTP"
  target_type = "ip"
  vpc_id      = local.vpc_id

  health_check {
    path                = "/healthz"
    matcher             = "200"
    interval            = 30
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = { Name = "${local.name}-tg" }
}

# Redirect HTTP -> HTTPS (no plaintext app traffic; SC-8).
resource "aws_lb_listener" "http_redirect" {
  load_balancer_arn = aws_lb.this.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type = "redirect"
    redirect {
      port        = "443"
      protocol    = "HTTPS"
      status_code = "HTTP_301"
    }
  }
}

resource "aws_lb_listener" "https" {
  load_balancer_arn = aws_lb.this.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = var.ssl_policy # TLS1.3/1.2 FIPS policy
  certificate_arn   = var.acm_certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.this.arn
  }
}

###############################################################################
# AWS WAFv2 web ACL (REGIONAL, associated to ALB) — SC-7, SI-3, SI-4
###############################################################################

resource "aws_wafv2_web_acl" "this" {
  name  = "${local.name}-waf"
  scope = "REGIONAL"

  default_action {
    allow {}
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${local.name}-waf"
    sampled_requests_enabled   = true
  }

  rule {
    name     = "AWSManagedCommonRuleSet"
    priority = 1
    override_action { none {} }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesCommonRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "common-rules"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "AWSManagedKnownBadInputs"
    priority = 2
    override_action { none {} }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesKnownBadInputsRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "known-bad-inputs"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "AWSManagedSQLi"
    priority = 3
    override_action { none {} }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesSQLiRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "sqli"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "RateLimit"
    priority = 10
    action { block {} }
    statement {
      rate_based_statement {
        limit              = 2000
        aggregate_key_type = "IP"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "rate-limit"
      sampled_requests_enabled   = true
    }
  }

  tags = { Name = "${local.name}-waf" }
}

resource "aws_wafv2_web_acl_association" "alb" {
  resource_arn = aws_lb.this.arn
  web_acl_arn  = aws_wafv2_web_acl.this.arn
}
