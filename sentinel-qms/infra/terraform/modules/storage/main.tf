# =============================================================================
# Storage module — object storage for document/attachment uploads.
#   AWS:   S3 (SSE-KMS, versioned, public access blocked, TLS-only policy)
#   Azure: Storage Account + Blob container (encrypted, private, HTTPS-only)
# =============================================================================

locals {
  is_aws   = var.cloud == "aws"
  is_azure = var.cloud == "azure"
}

# -----------------------------------------------------------------------------
# AWS S3
# -----------------------------------------------------------------------------
resource "aws_s3_bucket" "this" {
  count         = local.is_aws ? 1 : 0
  bucket        = var.bucket_name
  force_destroy = var.force_destroy
  tags          = merge(var.tags, { Name = var.bucket_name })
}

resource "aws_s3_bucket_public_access_block" "this" {
  count                   = local.is_aws ? 1 : 0
  bucket                  = aws_s3_bucket.this[0].id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "this" {
  count  = local.is_aws ? 1 : 0
  bucket = aws_s3_bucket.this[0].id
  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "this" {
  count  = local.is_aws ? 1 : 0
  bucket = aws_s3_bucket.this[0].id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = var.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_ownership_controls" "this" {
  count  = local.is_aws ? 1 : 0
  bucket = aws_s3_bucket.this[0].id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "this" {
  count  = local.is_aws ? 1 : 0
  bucket = aws_s3_bucket.this[0].id

  rule {
    id     = "expire-noncurrent-versions"
    status = "Enabled"
    filter {}
    noncurrent_version_expiration {
      noncurrent_days = var.noncurrent_version_expiration_days
    }
    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }
}

# Deny any non-TLS access (encryption in transit enforced at the bucket policy).
resource "aws_s3_bucket_policy" "tls_only" {
  count  = local.is_aws ? 1 : 0
  bucket = aws_s3_bucket.this[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource = [
          aws_s3_bucket.this[0].arn,
          "${aws_s3_bucket.this[0].arn}/*"
        ]
        Condition = {
          Bool = { "aws:SecureTransport" = "false" }
        }
      },
      {
        Sid       = "DenyUnEncryptedObjectUploads"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:PutObject"
        Resource  = "${aws_s3_bucket.this[0].arn}/*"
        Condition = {
          StringNotEquals = { "s3:x-amz-server-side-encryption" = "aws:kms" }
        }
      }
    ]
  })
}

# -----------------------------------------------------------------------------
# Azure Storage Account + Blob
# -----------------------------------------------------------------------------
resource "azurerm_storage_account" "this" {
  count                    = local.is_azure ? 1 : 0
  name                     = var.azure_storage_account_name
  resource_group_name      = var.azure_resource_group_name
  location                 = var.azure_location
  account_tier             = "Standard"
  account_replication_type = "GZRS"
  account_kind             = "StorageV2"

  min_tls_version                 = "TLS1_2"
  https_traffic_only_enabled      = true
  public_network_access_enabled   = false
  allow_nested_items_to_be_public = false
  shared_access_key_enabled       = false # force Entra ID / Managed Identity auth

  infrastructure_encryption_enabled = true

  blob_properties {
    versioning_enabled = true
    delete_retention_policy {
      days = 35
    }
    container_delete_retention_policy {
      days = 35
    }
  }

  dynamic "identity" {
    for_each = var.azure_cmk_identity_id != null ? [1] : []
    content {
      type         = "UserAssigned"
      identity_ids = [var.azure_cmk_identity_id]
    }
  }

  tags = var.tags
}

resource "azurerm_storage_account_customer_managed_key" "this" {
  count                     = local.is_azure && var.azure_cmk_key_vault_key_id != null ? 1 : 0
  storage_account_id        = azurerm_storage_account.this[0].id
  key_vault_key_id          = var.azure_cmk_key_vault_key_id
  user_assigned_identity_id = var.azure_cmk_identity_id
}

resource "azurerm_storage_account_network_rules" "this" {
  count                      = local.is_azure ? 1 : 0
  storage_account_id         = azurerm_storage_account.this[0].id
  default_action             = "Deny"
  virtual_network_subnet_ids = var.azure_subnet_ids
  bypass                     = ["AzureServices"]
}

resource "azurerm_storage_container" "uploads" {
  count                 = local.is_azure ? 1 : 0
  name                  = var.azure_container_name
  storage_account_id    = azurerm_storage_account.this[0].id
  container_access_type = "private"
}
