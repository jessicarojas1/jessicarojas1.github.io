###############################################################################
# CITADEL — AWS Commercial Deployment
# alb.tf — Application Load Balancer (HTTPS), target group, listeners, WAFv2
#
# Internet -> WAFv2 -> ALB (HTTPS, modern TLS) -> ECS target group.
# HTTP:80 redirects to HTTPS:443 (SC-8). Health check hits /api/health.
###############################################################################

resource "aws_lb" "this" {
  name                       = substr("${local.name}-alb", 0, 32)
  load_balancer_type         = "application"
  internal                   = false
  security_groups            = [aws_security_group.alb.id]
  subnets                    = local.create_vpc ? aws_subnet.public[*].id : []
  drop_invalid_header_fields = true
  enable_deletion_protection = var.enable_deletion_protection

  tags = { Name = "${local.name}-alb" }
}

resource "aws_lb_target_group" "this" {
  name        = substr("${local.name}-tg", 0, 32)
  port        = var.container_port
  protocol    = "HTTP"
  target_type = "ip" # awsvpc/Fargate tasks register by IP
  vpc_id      = local.vpc_id

  # CITADEL exposes GET /api/health returning 200 (see server/README.md).
  health_check {
    path                = "/api/health"
    matcher             = "200"
    interval            = 30
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  # Give in-flight scans time to drain on deploy/scale-in.
  deregistration_delay = 30

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
  ssl_policy        = var.ssl_policy # modern TLS1.2/1.3 policy
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
    allow {
    }
  }

  visibility_config {
    cloudwatch_metrics_enabled = true
    metric_name                = "${local.name}-waf"
    sampled_requests_enabled   = true
  }

  rule {
    name     = "AWSManagedCommonRuleSet"
    priority = 1
    override_action {
      none {
      }
    }
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
    override_action {
      none {
      }
    }
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
    override_action {
      none {
      }
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
    priority = 10
    action {
      block {
      }
    }
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
