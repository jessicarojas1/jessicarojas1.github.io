# =============================================================================
# audit-sink/replica — cross-region replication DESTINATION bucket
#
# A hardened, Object-Locked, versioned, SSE-KMS bucket in the replica region.
# Mirrors the primary archive's immutability so a regional loss of the primary
# still leaves a tamper-evident copy. Configure the aws provider for the replica
# region when calling this module.
# =============================================================================

data "aws_caller_identity" "current" {}
data "aws_partition" "current" {}
data "aws_region" "current" {}

locals {
  create_kms  = var.kms_key_arn == ""
  kms_key_arn = local.create_kms ? aws_kms_key.this[0].arn : var.kms_key_arn
  bucket_name = "${var.name_prefix}-audit-sink-replica-${data.aws_caller_identity.current.account_id}"
  tags        = merge(var.tags, { Name = "${var.name_prefix}-audit-sink-replica" })
}

resource "aws_kms_key" "this" {
  count                   = local.create_kms ? 1 : 0
  description             = "${var.name_prefix} audit sink replica CMK (replica region S3)"
  enable_key_rotation     = true
  deletion_window_in_days = var.kms_deletion_window_days
  tags                    = local.tags
}

resource "aws_kms_alias" "this" {
  count         = local.create_kms ? 1 : 0
  name          = "alias/${var.name_prefix}-audit-sink-replica"
  target_key_id = aws_kms_key.this[0].key_id
}

resource "aws_s3_bucket" "replica" {
  bucket              = local.bucket_name
  force_destroy       = false
  object_lock_enabled = true
  tags                = local.tags
}

resource "aws_s3_bucket_public_access_block" "replica" {
  bucket                  = aws_s3_bucket.replica.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "replica" {
  bucket = aws_s3_bucket.replica.id
  versioning_configuration {
    status = "Enabled" # required for Object Lock and as a replication destination
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "replica" {
  bucket = aws_s3_bucket.replica.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = local.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_ownership_controls" "replica" {
  bucket = aws_s3_bucket.replica.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_object_lock_configuration" "replica" {
  bucket = aws_s3_bucket.replica.id
  rule {
    default_retention {
      mode = var.object_lock_mode
      days = var.object_lock_retention_days
    }
  }
  depends_on = [aws_s3_bucket_versioning.replica]
}

resource "aws_s3_bucket_lifecycle_configuration" "replica" {
  bucket = aws_s3_bucket.replica.id
  rule {
    id     = "expire-noncurrent-after-lock"
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

# TLS-only + KMS-only, deny deletes/lock-weakening for ALL principals, and an
# explicit allow for the primary sink's replication role to write replicas.
resource "aws_s3_bucket_policy" "replica" {
  bucket = aws_s3_bucket.replica.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.replica.arn, "${aws_s3_bucket.replica.arn}/*"]
        Condition = { Bool = { "aws:SecureTransport" = "false" } }
      },
      {
        Sid       = "DenyUnEncryptedObjectUploads"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:PutObject"
        Resource  = "${aws_s3_bucket.replica.arn}/*"
        Condition = { StringNotEquals = { "s3:x-amz-server-side-encryption" = "aws:kms" } }
      },
      {
        Sid       = "DenyAllDeletesAndLockWeakening"
        Effect    = "Deny"
        Principal = "*"
        Action = [
          "s3:DeleteObject",
          "s3:DeleteObjectVersion",
          "s3:PutBucketObjectLockConfiguration",
          "s3:PutObjectRetention",
          "s3:BypassGovernanceRetention",
          "s3:DeleteBucket",
          "s3:DeleteBucketPolicy",
          "s3:PutBucketVersioning",
          "s3:PutLifecycleConfiguration"
        ]
        Resource = [aws_s3_bucket.replica.arn, "${aws_s3_bucket.replica.arn}/*"]
      },
      {
        # Same-account replication is authorized by the role's identity policy;
        # this explicit grant is defence-in-depth (and required if the replica is
        # ever moved cross-account). It intentionally lists only actions NOT in the
        # deny above — an explicit Deny always overrides an Allow, so granting e.g.
        # PutBucketVersioning here would be a no-op against DenyAllDeletesAndLockWeakening.
        Sid       = "AllowReplicationFromPrimary"
        Effect    = "Allow"
        Principal = { AWS = var.source_replication_role_arn }
        Action = [
          "s3:ReplicateObject",
          "s3:ReplicateTags",
          "s3:ObjectOwnerOverrideToBucketOwner",
          "s3:GetBucketVersioning",
          "s3:ListBucket"
        ]
        Resource = [aws_s3_bucket.replica.arn, "${aws_s3_bucket.replica.arn}/*"]
      }
    ]
  })
}
