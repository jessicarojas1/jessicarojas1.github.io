output "log_group_names" {
  description = "Created CloudWatch log group names (AWS)."
  value       = local.is_aws ? { for k, g in aws_cloudwatch_log_group.this : k => g.name } : {}
}

output "alarm_topic_arn" {
  description = "SNS topic ARN alarms publish to (AWS)."
  value       = local.is_aws ? local.topic_arn : null
}

output "log_analytics_workspace_id" {
  description = "Log Analytics workspace id (Azure)."
  value       = local.is_azure ? azurerm_log_analytics_workspace.this[0].id : null
}

output "action_group_id" {
  description = "Monitor action group id (Azure)."
  value       = local.is_azure ? azurerm_monitor_action_group.this[0].id : null
}
