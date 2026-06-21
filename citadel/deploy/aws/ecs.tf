###############################################################################
# CITADEL — AWS Commercial Deployment
# ecs.tf — IAM roles, ECS cluster, Fargate task definition, service
#
# The container listens on PORT=8080 (var.container_port), health probe
# GET /api/health, runs non-root UID 10001, read-only root FS with a writable
# ephemeral scratch volume mounted at the app's CITADEL_TMP (var.scratch_mount_path).
# Secret-class env vars come from Secrets Manager via `secrets` (valueFrom);
# only non-sensitive config is passed as plaintext `environment` (IA-5).
###############################################################################

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

# All Secrets Manager ARNs the execution role is allowed to read for injection.
locals {
  injected_secret_arns = [
    aws_secretsmanager_secret.jwt_secret.arn,
    aws_secretsmanager_secret.admin_password.arn,
    aws_secretsmanager_secret.superadmin_token.arn,
    aws_secretsmanager_secret.metrics_token.arn,
    aws_secretsmanager_secret.database_url.arn,
  ]
}

# Execution role: pulls image from ECR, writes logs, reads the specific secrets.
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
        # are scoped to this repository only.
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
        Sid      = "ReadSecrets"
        Effect   = "Allow"
        Action   = ["secretsmanager:GetSecretValue"]
        Resource = local.injected_secret_arns
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

# Task role: identity of the running app. CITADEL stores state in RDS and writes
# scratch to the local ephemeral volume, so it needs no extra AWS data-plane
# permissions by default. SSM messages are granted for ECS Exec debugging.
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
        # ssmmessages:* actions do not support resource-level permissions; AWS
        # requires Resource="*" for the ECS Exec data/control channels.
        Sid      = "SsmExecChannel"
        Effect   = "Allow"
        Action   = ["ssmmessages:CreateControlChannel", "ssmmessages:CreateDataChannel", "ssmmessages:OpenControlChannel", "ssmmessages:OpenDataChannel"]
        Resource = "*"
      }
    ]
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

  # Larger ephemeral storage for unpacking/scanning untrusted uploads.
  ephemeral_storage {
    size_in_gib = var.ephemeral_storage_gib
  }

  # Named ephemeral volume mounted as the app's writable scratch dir so the
  # root filesystem can stay read-only (CM-7, AC-6).
  volume {
    name = "scratch"
  }

  container_definitions = jsonencode([{
    name      = local.container_name
    image     = "${aws_ecr_repository.this.repository_url}:${var.image_tag}"
    essential = true

    # Hardening: non-root, optional read-only root FS, no privilege escalation.
    readonlyRootFilesystem = var.readonly_root_filesystem
    user                   = "10001:10001"
    linuxParameters = {
      capabilities = { drop = ["ALL"] }
    }

    # Mount the ephemeral scratch volume at the app's CITADEL_TMP path.
    mountPoints = [{
      sourceVolume  = "scratch"
      containerPath = var.scratch_mount_path
      readOnly      = false
    }]

    portMappings = [{ containerPort = var.container_port, protocol = "tcp" }]

    # Secret-class values injected from Secrets Manager (never plaintext).
    secrets = [
      { name = "CITADEL_JWT_SECRET", valueFrom = aws_secretsmanager_secret.jwt_secret.arn },
      { name = "CITADEL_ADMIN_PASSWORD", valueFrom = aws_secretsmanager_secret.admin_password.arn },
      { name = "CITADEL_SUPERADMIN_TOKEN", valueFrom = aws_secretsmanager_secret.superadmin_token.arn },
      { name = "CITADEL_METRICS_TOKEN", valueFrom = aws_secretsmanager_secret.metrics_token.arn },
      { name = "DATABASE_URL", valueFrom = aws_secretsmanager_secret.database_url.arn },
    ]

    # Non-secret runtime config.
    environment = [
      { name = "NODE_ENV", value = "production" },
      { name = "PORT", value = tostring(var.container_port) },
      { name = "PGSSL", value = "1" },
      { name = "CITADEL_TMP", value = var.scratch_mount_path },
      { name = "CITADEL_ADMIN_EMAIL", value = var.citadel_admin_email },
      { name = "CITADEL_MULTITENANT", value = var.citadel_multitenant ? "1" : "0" },
      { name = "CITADEL_BASE_DOMAIN", value = var.citadel_base_domain },
      { name = "CITADEL_ENV", value = var.environment },
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        "awslogs-group"         = aws_cloudwatch_log_group.app.name
        "awslogs-region"        = var.region
        "awslogs-stream-prefix" = "citadel"
      }
    }

    # Container-level health probe mirrors the ALB target group (/api/health).
    healthCheck = {
      command     = ["CMD-SHELL", "node -e \"require('http').get('http://127.0.0.1:${var.container_port}/api/health',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))\""]
      interval    = 30
      timeout     = 5
      retries     = 3
      startPeriod = 30
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

  # ECS Exec for break-glass debugging (logged + KMS-encrypted via the cluster).
  enable_execute_command = true

  # Tasks run in private subnets with NO public IP (SC-7). NAT provides egress.
  network_configuration {
    subnets          = local.create_vpc ? aws_subnet.private[*].id : []
    security_groups  = [aws_security_group.ecs.id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.this.arn
    container_name   = local.container_name
    container_port   = var.container_port
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  depends_on = [aws_lb_listener.https]
  tags       = { Name = "${local.name}-svc" }
}
