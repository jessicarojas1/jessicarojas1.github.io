output "bucket_name" {
  description = "S3 bucket name (AWS)."
  value       = local.is_aws ? aws_s3_bucket.this[0].id : null
}

output "bucket_arn" {
  description = "S3 bucket ARN (AWS)."
  value       = local.is_aws ? aws_s3_bucket.this[0].arn : null
}

output "storage_account_name" {
  description = "Storage account name (Azure)."
  value       = local.is_azure ? azurerm_storage_account.this[0].name : null
}

output "storage_account_id" {
  description = "Storage account id (Azure)."
  value       = local.is_azure ? azurerm_storage_account.this[0].id : null
}

output "container_name" {
  description = "Blob container name (Azure)."
  value       = local.is_azure ? azurerm_storage_container.uploads[0].name : null
}

output "blob_endpoint" {
  description = "Primary blob endpoint (Azure)."
  value       = local.is_azure ? azurerm_storage_account.this[0].primary_blob_endpoint : null
}
