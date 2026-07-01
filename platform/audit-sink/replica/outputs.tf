# =============================================================================
# audit-sink/replica — outputs (wire these into the primary audit-sink module)
# =============================================================================

output "bucket_arn" {
  description = "ARN of the replica Object-Locked bucket. Pass to the primary module as crr_destination_bucket_arn."
  value       = aws_s3_bucket.replica.arn
}

output "bucket_name" {
  description = "Name of the replica bucket."
  value       = aws_s3_bucket.replica.id
}

output "kms_key_arn" {
  description = "ARN of the replica-region CMK. Pass to the primary module as crr_replica_kms_key_arn."
  value       = local.kms_key_arn
}
