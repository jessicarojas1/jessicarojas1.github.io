# =============================================================================
# audit-sink — outputs
# =============================================================================

output "bucket_name" {
  description = "Name of the immutable (Object Lock) audit archive bucket."
  value       = aws_s3_bucket.audit.id
}

output "bucket_arn" {
  description = "ARN of the immutable audit archive bucket."
  value       = aws_s3_bucket.audit.arn
}

output "kms_key_arn" {
  description = "ARN of the CMK encrypting the bucket, log groups, and Firehose."
  value       = local.kms_key_arn
}

output "log_group_names" {
  description = "Map of source-app key -> CloudWatch Log group name that each app ships its audit log to."
  value       = { for k, g in aws_cloudwatch_log_group.audit : k => g.name }
}

output "log_group_arns" {
  description = "ARNs of the central audit CloudWatch Log groups (with :* suffix for IAM)."
  value       = local.log_group_arns
}

output "firehose_stream_arn" {
  description = "ARN of the Firehose delivery stream forwarding CloudWatch Logs to the locked bucket."
  value       = aws_kinesis_firehose_delivery_stream.audit.arn
}

output "writer_policy_arn" {
  description = "ARN of the append-only IAM policy to attach to source-app task/instance roles."
  value       = aws_iam_policy.writer.arn
}

output "legal_hold_role_arn" {
  description = "ARN of the separation-of-duties role permitted to toggle S3 Object Lock legal holds (null if disabled)."
  value       = var.enable_legal_hold_role ? aws_iam_role.legal_hold[0].arn : null
}
