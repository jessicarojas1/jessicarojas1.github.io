# =============================================================================
# bootstrap — outputs
# =============================================================================

output "state_bucket" {
  description = "Name of the Terraform state S3 bucket."
  value       = aws_s3_bucket.state.id
}

output "lock_table" {
  description = "Name of the DynamoDB state-lock table."
  value       = aws_dynamodb_table.locks.name
}

output "state_kms_key_arn" {
  description = "ARN of the state-encryption CMK."
  value       = aws_kms_key.state.arn
}

# Ready-to-paste partial backend config for platform/audit-sink/backend.hcl.
output "backend_hcl" {
  description = "Copy into platform/audit-sink/backend.hcl, then: terraform init -backend-config=backend.hcl"
  value       = <<-EOT
    bucket         = "${aws_s3_bucket.state.id}"
    region         = "${data.aws_region.current.name}"
    dynamodb_table = "${aws_dynamodb_table.locks.name}"
    kms_key_id     = "${aws_kms_key.state.arn}"
  EOT
}
