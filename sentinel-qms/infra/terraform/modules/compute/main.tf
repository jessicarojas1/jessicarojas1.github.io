# =============================================================================
# Compute module — container runtime for backend (:8000) + frontend (:8080).
#   AWS:   ECS Fargate services behind an internet-facing ALB (HTTPS + WAF)
#   Azure: Azure Container Apps in an internal environment + Application Gateway
# =============================================================================

locals {
  is_aws   = var.cloud == "aws"
  is_azure = var.cloud == "azure"
}

# =============================================================================
# AWS — ECS Fargate
# =============================================================================
data "aws_region" "current" {
  count = local.is_aws ? 1 : 0
}

# ── IAM: execution role (pull images, write logs, read secrets) ──────────────
data "aws_iam_policy_document" "ecs_assume" {
  count = local.is_aws ? 1 : 0
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "execution" {
  count              = local.is_aws ? 1 : 0
  name               = "${var.name_prefix}-ecs-exec"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume[0].json
  tags               = var.tags
}

resource "aws_iam_role_policy_attachment" "execution_managed" {
  count      = local.is_aws ? 1 : 0
  role       = aws_iam_role.execution[0].name
  policy_arn = "arn:${data.aws_partition.current[0].partition}:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

data "aws_partition" "current" {
  count = local.is_aws ? 1 : 0
}

# Least-privilege: execution role may read only the app's own secrets + CMK.
data "aws_iam_policy_document" "execution_secrets" {
  count = local.is_aws && length(var.secret_env_arns) > 0 ? 1 : 0
  statement {
    sid       = "ReadAppSecrets"
    actions   = ["secretsmanager:GetSecretValue"]
    resources = values(var.secret_env_arns)
  }
  dynamic "statement" {
    for_each = var.kms_key_arn != null ? [1] : []
    content {
      sid       = "DecryptWithCmk"
      actions   = ["kms:Decrypt"]
      resources = [var.kms_key_arn]
    }
  }
}

resource "aws_iam_role_policy" "execution_secrets" {
  count  = local.is_aws && length(var.secret_env_arns) > 0 ? 1 : 0
  name   = "${var.name_prefix}-exec-secrets"
  role   = aws_iam_role.execution[0].id
  policy = data.aws_iam_policy_document.execution_secrets[0].json
}

# ── IAM: task role (runtime app permissions — S3 uploads) ────────────────────
resource "aws_iam_role" "task" {
  count              = local.is_aws ? 1 : 0
  name               = "${var.name_prefix}-ecs-task"
  assume_role_policy = data.aws_iam_policy_document.ecs_assume[0].json
  tags               = var.tags
}

# ── ECS cluster ───────────────────────────────────────────────────────────────
resource "aws_ecs_cluster" "this" {
  count = local.is_aws ? 1 : 0
  name  = "${var.name_prefix}-cluster"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  configuration {
    execute_command_configuration {
      kms_key_id = var.kms_key_arn
      logging    = "DEFAULT"
    }
  }

  tags = var.tags
}

resource "aws_ecs_cluster_capacity_providers" "this" {
  count              = local.is_aws ? 1 : 0
  cluster_name       = aws_ecs_cluster.this[0].name
  capacity_providers = ["FARGATE"]
  default_capacity_provider_strategy {
    capacity_provider = "FARGATE"
    weight            = 1
  }
}

# ── Task definitions ──────────────────────────────────────────────────────────
locals {
  backend_env_pairs = [for k, v in var.non_secret_env : { name = k, value = v }]
  backend_secrets   = [for k, v in var.secret_env_arns : { name = k, valueFrom = v }]
}

resource "aws_ecs_task_definition" "backend" {
  count                    = local.is_aws ? 1 : 0
  family                   = "${var.name_prefix}-backend"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.backend_cpu
  memory                   = var.backend_memory
  execution_role_arn       = aws_iam_role.execution[0].arn
  task_role_arn            = aws_iam_role.task[0].arn

  runtime_platform {
    operating_system_family = "LINUX"
    cpu_architecture        = "X86_64"
  }

  container_definitions = jsonencode([
    {
      name      = "backend"
      image     = var.backend_image
      essential = true
      portMappings = [{ containerPort = var.backend_port, protocol = "tcp" }]
      environment = local.backend_env_pairs
      secrets     = local.backend_secrets
      readonlyRootFilesystem = true
      linuxParameters = { initProcessEnabled = true }
      healthCheck = {
        command     = ["CMD-SHELL", "python -c \"import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://localhost:${var.backend_port}/health').status==200 else 1)\""]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 30
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = var.log_group_backend
          "awslogs-region"        = data.aws_region.current[0].name
          "awslogs-stream-prefix" = "backend"
        }
      }
    }
  ])

  tags = var.tags
}

resource "aws_ecs_task_definition" "frontend" {
  count                    = local.is_aws ? 1 : 0
  family                   = "${var.name_prefix}-frontend"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.frontend_cpu
  memory                   = var.frontend_memory
  execution_role_arn       = aws_iam_role.execution[0].arn
  task_role_arn            = aws_iam_role.task[0].arn

  container_definitions = jsonencode([
    {
      name      = "frontend"
      image     = var.frontend_image
      essential = true
      portMappings = [{ containerPort = var.frontend_port, protocol = "tcp" }]
      readonlyRootFilesystem = false # nginx writes temp/cache dirs
      healthCheck = {
        command     = ["CMD-SHELL", "wget -q -O /dev/null http://localhost:${var.frontend_port}/ || exit 1"]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 15
      }
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = var.log_group_frontend
          "awslogs-region"        = data.aws_region.current[0].name
          "awslogs-stream-prefix" = "frontend"
        }
      }
    }
  ])

  tags = var.tags
}

# ── Load balancer ─────────────────────────────────────────────────────────────
resource "aws_lb" "this" {
  count                      = local.is_aws ? 1 : 0
  name                       = "${var.name_prefix}-alb"
  internal                   = false
  load_balancer_type         = "application"
  security_groups            = [var.alb_security_group_id]
  subnets                    = var.public_subnet_ids
  drop_invalid_header_fields = true
  enable_deletion_protection = true
  tags                       = var.tags
}

resource "aws_lb_target_group" "backend" {
  count       = local.is_aws ? 1 : 0
  name        = "${var.name_prefix}-be"
  port        = var.backend_port
  protocol    = "HTTP"
  vpc_id      = var.vpc_id
  target_type = "ip"

  health_check {
    path                = "/health"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 30
    matcher             = "200"
  }
  tags = var.tags
}

resource "aws_lb_target_group" "frontend" {
  count       = local.is_aws ? 1 : 0
  name        = "${var.name_prefix}-fe"
  port        = var.frontend_port
  protocol    = "HTTP"
  vpc_id      = var.vpc_id
  target_type = "ip"

  health_check {
    path                = "/"
    healthy_threshold   = 2
    unhealthy_threshold = 3
    interval            = 30
    matcher             = "200"
  }
  tags = var.tags
}

resource "aws_lb_listener" "https" {
  count             = local.is_aws ? 1 : 0
  load_balancer_arn = aws_lb.this[0].arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = var.ssl_policy
  certificate_arn   = var.acm_certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.frontend[0].arn
  }
  tags = var.tags
}

# /api/* goes to the backend; everything else to the SPA.
resource "aws_lb_listener_rule" "api" {
  count        = local.is_aws ? 1 : 0
  listener_arn = aws_lb_listener.https[0].arn
  priority     = 10

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.backend[0].arn
  }
  condition {
    path_pattern {
      values = ["/api/*", "/health", "/docs", "/openapi.json"]
    }
  }
}

# Redirect HTTP -> HTTPS.
resource "aws_lb_listener" "http_redirect" {
  count             = local.is_aws ? 1 : 0
  load_balancer_arn = aws_lb.this[0].arn
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

# ── ECS services ──────────────────────────────────────────────────────────────
resource "aws_ecs_service" "backend" {
  count            = local.is_aws ? 1 : 0
  name             = "${var.name_prefix}-backend"
  cluster          = aws_ecs_cluster.this[0].id
  task_definition  = aws_ecs_task_definition.backend[0].arn
  desired_count    = var.backend_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  enable_execute_command = true

  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.app_security_group_id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.backend[0].arn
    container_name   = "backend"
    container_port   = var.backend_port
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  depends_on = [aws_lb_listener.https]
  tags       = var.tags
}

resource "aws_ecs_service" "frontend" {
  count            = local.is_aws ? 1 : 0
  name             = "${var.name_prefix}-frontend"
  cluster          = aws_ecs_cluster.this[0].id
  task_definition  = aws_ecs_task_definition.frontend[0].arn
  desired_count    = var.frontend_desired_count
  launch_type      = "FARGATE"
  platform_version = "LATEST"

  network_configuration {
    subnets          = var.private_subnet_ids
    security_groups  = [var.app_security_group_id]
    assign_public_ip = false
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.frontend[0].arn
    container_name   = "frontend"
    container_port   = var.frontend_port
  }

  deployment_circuit_breaker {
    enable   = true
    rollback = true
  }

  depends_on = [aws_lb_listener.https]
  tags       = var.tags
}

# ── Autoscaling (backend) ─────────────────────────────────────────────────────
resource "aws_appautoscaling_target" "backend" {
  count              = local.is_aws ? 1 : 0
  max_capacity       = var.backend_desired_count * 4
  min_capacity       = var.backend_desired_count
  resource_id        = "service/${aws_ecs_cluster.this[0].name}/${aws_ecs_service.backend[0].name}"
  scalable_dimension = "ecs:service:DesiredCount"
  service_namespace  = "ecs"
}

resource "aws_appautoscaling_policy" "backend_cpu" {
  count              = local.is_aws ? 1 : 0
  name               = "${var.name_prefix}-backend-cpu"
  policy_type        = "TargetTrackingScaling"
  resource_id        = aws_appautoscaling_target.backend[0].resource_id
  scalable_dimension = aws_appautoscaling_target.backend[0].scalable_dimension
  service_namespace  = aws_appautoscaling_target.backend[0].service_namespace

  target_tracking_scaling_policy_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ECSServiceAverageCPUUtilization"
    }
    target_value       = 65
    scale_in_cooldown  = 120
    scale_out_cooldown = 60
  }
}

# ── WAFv2 ─────────────────────────────────────────────────────────────────────
resource "aws_wafv2_web_acl" "this" {
  count       = local.is_aws && var.enable_waf ? 1 : 0
  name        = "${var.name_prefix}-waf"
  description = "Sentinel QMS edge protection."
  scope       = "REGIONAL"

  default_action {
    allow {}
  }

  rule {
    name     = "AWSManagedCommon"
    priority = 1
    override_action {
      none {}
    }
    statement {
      managed_rule_group_statement {
        vendor_name = "AWS"
        name        = "AWSManagedRulesCommonRuleSet"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "common"
      sampled_requests_enabled   = true
    }
  }

  rule {
    name     = "AWSManagedSQLi"
    priority = 2
    override_action {
      none {}
    }
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
    priority = 3
    action {
      block {}
    }
    statement {
      rate_based_statement {
        limit              = 2000
        aggregate_key_type = "IP"
      }
    }
    visibility_config {
      cloudwatch_metrics_enabled = true
      metric_name                = "ratelimit"
      sampled_requests_enabled   = true
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${var.name_prefix}-waf"
    sampled_requests_enabled   = true
  }

  tags = var.tags
}

resource "aws_wafv2_web_acl_association" "this" {
  count        = local.is_aws && var.enable_waf ? 1 : 0
  resource_arn = aws_lb.this[0].arn
  web_acl_arn  = aws_wafv2_web_acl.this[0].arn
}

# =============================================================================
# Azure — Container Apps
# =============================================================================
resource "azurerm_container_app_environment" "this" {
  count                          = local.is_azure ? 1 : 0
  name                           = "${var.name_prefix}-cae"
  location                       = var.azure_location
  resource_group_name            = var.azure_resource_group_name
  log_analytics_workspace_id     = var.azure_log_analytics_workspace_id
  infrastructure_subnet_id       = var.azure_infra_subnet_id
  internal_load_balancer_enabled = true # private; Application Gateway fronts it
  tags                           = var.tags
}

resource "azurerm_container_app" "backend" {
  count                        = local.is_azure ? 1 : 0
  name                         = "${var.name_prefix}-backend"
  container_app_environment_id = azurerm_container_app_environment.this[0].id
  resource_group_name          = var.azure_resource_group_name
  revision_mode                = "Single"
  tags                         = var.tags

  identity {
    type         = "UserAssigned"
    identity_ids = [var.azure_identity_id]
  }

  registry {
    server   = var.azure_acr_login_server
    identity = var.azure_identity_id
  }

  dynamic "secret" {
    for_each = var.secret_env_arns
    content {
      name                = lower(replace(secret.key, "_", "-"))
      key_vault_secret_id = secret.value
      identity            = var.azure_identity_id
    }
  }

  ingress {
    external_enabled = true # external within the internal CAE only
    target_port      = var.backend_port
    transport        = "http"
    traffic_weight {
      percentage      = 100
      latest_revision = true
    }
  }

  template {
    min_replicas = var.backend_desired_count
    max_replicas = var.backend_desired_count * 4

    container {
      name   = "backend"
      image  = var.backend_image
      cpu    = var.backend_cpu / 1024
      memory = "${var.backend_memory / 1024}Gi"

      dynamic "env" {
        for_each = var.non_secret_env
        content {
          name  = env.key
          value = env.value
        }
      }

      dynamic "env" {
        for_each = var.secret_env_arns
        content {
          name        = env.key
          secret_name = lower(replace(env.key, "_", "-"))
        }
      }

      liveness_probe {
        transport = "HTTP"
        port      = var.backend_port
        path      = "/health"
      }
      readiness_probe {
        transport = "HTTP"
        port      = var.backend_port
        path      = "/health"
      }
    }

    http_scale_rule {
      name                = "http-concurrency"
      concurrent_requests = "100"
    }
  }
}

resource "azurerm_container_app" "frontend" {
  count                        = local.is_azure ? 1 : 0
  name                         = "${var.name_prefix}-frontend"
  container_app_environment_id = azurerm_container_app_environment.this[0].id
  resource_group_name          = var.azure_resource_group_name
  revision_mode                = "Single"
  tags                         = var.tags

  identity {
    type         = "UserAssigned"
    identity_ids = [var.azure_identity_id]
  }

  registry {
    server   = var.azure_acr_login_server
    identity = var.azure_identity_id
  }

  ingress {
    external_enabled = true
    target_port      = var.frontend_port
    transport        = "http"
    traffic_weight {
      percentage      = 100
      latest_revision = true
    }
  }

  template {
    min_replicas = var.frontend_desired_count
    max_replicas = var.frontend_desired_count * 3

    container {
      name   = "frontend"
      image  = var.frontend_image
      cpu    = var.frontend_cpu / 1024
      memory = "${var.frontend_memory / 1024}Gi"

      liveness_probe {
        transport = "HTTP"
        port      = var.frontend_port
        path      = "/"
      }
      readiness_probe {
        transport = "HTTP"
        port      = var.frontend_port
        path      = "/"
      }
    }
  }
}
