# =============================================================================
# Observability module — centralized logging + alerting (NIST AU controls).
#   AWS:   CloudWatch log groups, metric alarms, SNS notifications
#   Azure: Log Analytics workspace + metric alerts + action group
# =============================================================================

locals {
  is_aws       = var.cloud == "aws"
  is_azure     = var.cloud == "azure"
  create_topic = local.is_aws && var.alarm_sns_topic_arn == ""
  topic_arn    = local.create_topic ? aws_sns_topic.alarms[0].arn : var.alarm_sns_topic_arn
}

# -----------------------------------------------------------------------------
# AWS CloudWatch
# -----------------------------------------------------------------------------
resource "aws_cloudwatch_log_group" "this" {
  for_each          = local.is_aws ? toset(var.log_group_names) : []
  name              = "/sentinel-qms/${var.name_prefix}/${each.value}"
  retention_in_days = var.log_retention_days
  kms_key_id        = var.kms_key_arn
  tags              = merge(var.tags, { Name = "/sentinel-qms/${var.name_prefix}/${each.value}" })
}

resource "aws_sns_topic" "alarms" {
  count             = local.create_topic ? 1 : 0
  name              = "${var.name_prefix}-alarms"
  kms_master_key_id = var.kms_key_arn
  tags              = var.tags
}

resource "aws_sns_topic_subscription" "email" {
  count     = local.create_topic && var.alarm_email != "" ? 1 : 0
  topic_arn = aws_sns_topic.alarms[0].arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

resource "aws_cloudwatch_metric_alarm" "alb_5xx" {
  count               = local.is_aws && var.alb_arn_suffix != "" ? 1 : 0
  alarm_name          = "${var.name_prefix}-alb-5xx"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "HTTPCode_ELB_5XX_Count"
  namespace           = "AWS/ApplicationELB"
  period              = 60
  statistic           = "Sum"
  threshold           = 10
  alarm_description   = "ALB is returning elevated 5xx responses."
  dimensions          = { LoadBalancer = var.alb_arn_suffix }
  alarm_actions       = [local.topic_arn]
  ok_actions          = [local.topic_arn]
  treat_missing_data  = "notBreaching"
  tags                = var.tags
}

resource "aws_cloudwatch_metric_alarm" "db_cpu" {
  count               = local.is_aws && var.db_instance_id != "" ? 1 : 0
  alarm_name          = "${var.name_prefix}-db-cpu-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "CPUUtilization"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  threshold           = 85
  alarm_description   = "RDS CPU sustained above 85%."
  dimensions          = { DBInstanceIdentifier = var.db_instance_id }
  alarm_actions       = [local.topic_arn]
  ok_actions          = [local.topic_arn]
  treat_missing_data  = "notBreaching"
  tags                = var.tags
}

resource "aws_cloudwatch_metric_alarm" "db_storage" {
  count               = local.is_aws && var.db_instance_id != "" ? 1 : 0
  alarm_name          = "${var.name_prefix}-db-storage-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 1
  metric_name         = "FreeStorageSpace"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  threshold           = 10737418240 # 10 GiB
  alarm_description   = "RDS free storage below 10 GiB."
  dimensions          = { DBInstanceIdentifier = var.db_instance_id }
  alarm_actions       = [local.topic_arn]
  treat_missing_data  = "notBreaching"
  tags                = var.tags
}

# -----------------------------------------------------------------------------
# Azure Monitor
# -----------------------------------------------------------------------------
resource "azurerm_log_analytics_workspace" "this" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-law"
  location            = var.azure_location
  resource_group_name = var.azure_resource_group_name
  sku                 = "PerGB2018"
  retention_in_days   = var.log_retention_days
  tags                = var.tags
}

resource "azurerm_monitor_action_group" "this" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-ag"
  resource_group_name = var.azure_resource_group_name
  short_name          = "sentinel"

  dynamic "email_receiver" {
    for_each = var.alarm_email != "" ? [1] : []
    content {
      name          = "ops"
      email_address = var.alarm_email
    }
  }

  tags = var.tags
}

resource "azurerm_monitor_metric_alert" "cpu" {
  count               = local.is_azure && length(var.azure_monitored_resource_ids) > 0 ? 1 : 0
  name                = "${var.name_prefix}-cpu-high"
  resource_group_name = var.azure_resource_group_name
  scopes              = var.azure_monitored_resource_ids
  description         = "CPU sustained above 85%."
  severity            = 2
  frequency           = "PT1M"
  window_size         = "PT5M"

  criteria {
    metric_namespace = "Microsoft.DBforPostgreSQL/flexibleServers"
    metric_name      = "cpu_percent"
    aggregation      = "Average"
    operator         = "GreaterThan"
    threshold        = 85
  }

  action {
    action_group_id = azurerm_monitor_action_group.this[0].id
  }

  tags = var.tags
}
