# =============================================================================
# audit-sink — tamper-evident central audit log sink (AWS)
#
#   App per-app audit log  ->  CloudWatch Logs (KMS, retained)
#                          ->  Subscription filter
#                          ->  Kinesis Firehose (KMS)
#                          ->  S3 (Object Lock COMPLIANCE, versioned, SSE-KMS,
#                                  delete-deny bucket policy)
#
# Satisfies NIST 800-53 AU-9 (Protection of Audit Information) / CMMC AU.L2 /
# HIPAA 164.312(b) write-once requirement. Style mirrors
# sentinel-qms/infra/terraform/modules/{storage,observability}.
# =============================================================================

data "aws_caller_identity" "current" {}
data "aws_partition" "current" {}
data "aws_region" "current" {}

locals {
  create_kms  = var.kms_key_arn == ""
  kms_key_arn = local.create_kms ? aws_kms_key.this[0].arn : var.kms_key_arn

  bucket_name = "${var.name_prefix}-audit-sink-${data.aws_caller_identity.current.account_id}"

  log_group_arns = [
    for n in var.log_group_names :
    "arn:${data.aws_partition.current.partition}:logs:${data.aws_region.current.name}:${data.aws_caller_identity.current.account_id}:log-group:/audit/${var.name_prefix}/${n}:*"
  ]

  tags = merge(var.tags, { Name = "${var.name_prefix}-audit-sink" })
}

# -----------------------------------------------------------------------------
# KMS CMK (created only when an ARN is not supplied)
# -----------------------------------------------------------------------------
resource "aws_kms_key" "this" {
  count                   = local.create_kms ? 1 : 0
  description             = "${var.name_prefix} central audit sink CMK (S3, CloudWatch Logs, Firehose)"
  enable_key_rotation     = true
  deletion_window_in_days = var.kms_deletion_window_days
  tags                    = local.tags

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "EnableRootAccountAdmin"
        Effect    = "Allow"
        Principal = { AWS = "arn:${data.aws_partition.current.partition}:iam::${data.aws_caller_identity.current.account_id}:root" }
        Action    = "kms:*"
        Resource  = "*"
      },
      {
        Sid       = "AllowCloudWatchLogs"
        Effect    = "Allow"
        Principal = { Service = "logs.${data.aws_region.current.name}.amazonaws.com" }
        Action = [
          "kms:Encrypt", "kms:Decrypt", "kms:ReEncrypt*",
          "kms:GenerateDataKey*", "kms:DescribeKey"
        ]
        Resource = "*"
        Condition = {
          # Scope the grant to this account's log groups (avoids the AU-9 finding
          # of a CloudWatch KMS grant on Resource=* with no EncryptionContext).
          ArnLike = {
            "kms:EncryptionContext:aws:logs:arn" = "arn:${data.aws_partition.current.partition}:logs:${data.aws_region.current.name}:${data.aws_caller_identity.current.account_id}:log-group:/audit/${var.name_prefix}/*"
          }
        }
      }
    ]
  })
}

resource "aws_kms_alias" "this" {
  count         = local.create_kms ? 1 : 0
  name          = "alias/${var.name_prefix}-audit-sink"
  target_key_id = aws_kms_key.this[0].key_id
}

# -----------------------------------------------------------------------------
# S3 — the immutable archive (Object Lock COMPLIANCE + versioning + SSE-KMS)
# -----------------------------------------------------------------------------
resource "aws_s3_bucket" "audit" {
  bucket              = local.bucket_name
  force_destroy       = false # never auto-delete an audit archive
  object_lock_enabled = true  # must be set at creation time
  tags                = local.tags
}

resource "aws_s3_bucket_public_access_block" "audit" {
  bucket                  = aws_s3_bucket.audit.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_versioning" "audit" {
  bucket = aws_s3_bucket.audit.id
  versioning_configuration {
    status = "Enabled" # required for Object Lock
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "audit" {
  bucket = aws_s3_bucket.audit.id
  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm     = "aws:kms"
      kms_master_key_id = local.kms_key_arn
    }
    bucket_key_enabled = true
  }
}

resource "aws_s3_bucket_ownership_controls" "audit" {
  bucket = aws_s3_bucket.audit.id
  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

# Default WORM retention applied to every object written to the bucket.
resource "aws_s3_bucket_object_lock_configuration" "audit" {
  bucket = aws_s3_bucket.audit.id
  rule {
    default_retention {
      mode = var.object_lock_mode
      days = var.object_lock_retention_days
    }
  }
  depends_on = [aws_s3_bucket_versioning.audit]
}

# Lifecycle only cleans up incomplete uploads + ancient noncurrent versions.
# (Object Lock prevents deleting locked current versions regardless.)
resource "aws_s3_bucket_lifecycle_configuration" "audit" {
  bucket = aws_s3_bucket.audit.id
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

# Bucket policy: TLS-only, KMS-only uploads, and an explicit DENY on every
# delete/lock-weakening action for ALL principals (AU-9 write-once).
resource "aws_s3_bucket_policy" "audit" {
  bucket = aws_s3_bucket.audit.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.audit.arn, "${aws_s3_bucket.audit.arn}/*"]
        Condition = { Bool = { "aws:SecureTransport" = "false" } }
      },
      {
        Sid       = "DenyUnEncryptedObjectUploads"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:PutObject"
        Resource  = "${aws_s3_bucket.audit.arn}/*"
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
        Resource = [aws_s3_bucket.audit.arn, "${aws_s3_bucket.audit.arn}/*"]
      }
    ]
  })
}

# -----------------------------------------------------------------------------
# CloudWatch Logs — hot/queryable tier (one group per source app)
# -----------------------------------------------------------------------------
resource "aws_cloudwatch_log_group" "audit" {
  for_each          = toset(var.log_group_names)
  name              = "/audit/${var.name_prefix}/${each.value}"
  retention_in_days = var.log_retention_days
  kms_key_id        = local.kms_key_arn
  tags              = merge(local.tags, { Name = "/audit/${var.name_prefix}/${each.value}" })
}

# -----------------------------------------------------------------------------
# Kinesis Firehose — CloudWatch Logs -> S3 delivery
# -----------------------------------------------------------------------------
resource "aws_iam_role" "firehose" {
  name = "${var.name_prefix}-audit-firehose"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "firehose.amazonaws.com" }
      Action    = "sts:AssumeRole"
      Condition = { StringEquals = { "sts:ExternalId" = data.aws_caller_identity.current.account_id } }
    }]
  })
  tags = local.tags
}

resource "aws_iam_role_policy" "firehose" {
  name = "${var.name_prefix}-audit-firehose"
  role = aws_iam_role.firehose.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "WriteToAuditBucket"
        Effect = "Allow"
        Action = [
          "s3:AbortMultipartUpload",
          "s3:GetBucketLocation",
          "s3:ListBucket",
          "s3:ListBucketMultipartUploads",
          "s3:PutObject"
        ]
        Resource = [aws_s3_bucket.audit.arn, "${aws_s3_bucket.audit.arn}/*"]
      },
      {
        Sid      = "UseSinkCmk"
        Effect   = "Allow"
        Action   = ["kms:Decrypt", "kms:GenerateDataKey"]
        Resource = local.kms_key_arn
      }
    ]
  })
}

resource "aws_cloudwatch_log_group" "firehose_errors" {
  name              = "/audit/${var.name_prefix}/_firehose-errors"
  retention_in_days = var.log_retention_days
  kms_key_id        = local.kms_key_arn
  tags              = local.tags
}

resource "aws_cloudwatch_log_stream" "firehose_errors" {
  name           = "s3-delivery"
  log_group_name = aws_cloudwatch_log_group.firehose_errors.name
}

resource "aws_kinesis_firehose_delivery_stream" "audit" {
  name        = "${var.name_prefix}-audit-sink"
  destination = "extended_s3"

  extended_s3_configuration {
    role_arn    = aws_iam_role.firehose.arn
    bucket_arn  = aws_s3_bucket.audit.arn
    kms_key_arn = local.kms_key_arn

    prefix              = "audit/!{timestamp:yyyy/MM/dd}/"
    error_output_prefix = "errors/!{firehose:error-output-type}/!{timestamp:yyyy/MM/dd}/"

    buffering_size     = var.firehose_buffer_size_mb
    buffering_interval = var.firehose_buffer_interval_seconds
    compression_format = "GZIP"

    cloudwatch_logging_options {
      enabled         = true
      log_group_name  = aws_cloudwatch_log_group.firehose_errors.name
      log_stream_name = aws_cloudwatch_log_stream.firehose_errors.name
    }
  }

  server_side_encryption {
    enabled  = true
    key_type = "CUSTOMER_MANAGED_CMK"
    key_arn  = local.kms_key_arn
  }

  tags = local.tags
}

# -----------------------------------------------------------------------------
# Subscription filters — forward every audit record to Firehose
# -----------------------------------------------------------------------------
resource "aws_iam_role" "cwl_to_firehose" {
  name = "${var.name_prefix}-audit-cwl-to-firehose"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "logs.${data.aws_region.current.name}.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
  tags = local.tags
}

resource "aws_iam_role_policy" "cwl_to_firehose" {
  name = "${var.name_prefix}-audit-cwl-to-firehose"
  role = aws_iam_role.cwl_to_firehose.id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect   = "Allow"
      Action   = ["firehose:PutRecord", "firehose:PutRecordBatch"]
      Resource = aws_kinesis_firehose_delivery_stream.audit.arn
    }]
  })
}

resource "aws_cloudwatch_log_subscription_filter" "audit" {
  for_each        = aws_cloudwatch_log_group.audit
  name            = "${var.name_prefix}-audit-to-firehose"
  log_group_name  = each.value.name
  filter_pattern  = var.subscription_filter_pattern
  destination_arn = aws_kinesis_firehose_delivery_stream.audit.arn
  role_arn        = aws_iam_role.cwl_to_firehose.arn
}

# -----------------------------------------------------------------------------
# Least-privilege log-writer policy for the source apps
# (append-only to CloudWatch Logs; NO access to the S3 archive)
# -----------------------------------------------------------------------------
resource "aws_iam_policy" "writer" {
  name        = "${var.name_prefix}-audit-writer"
  description = "Append-only access to the central audit log groups for source apps."
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid      = "AppendOnlyToAuditLogGroups"
      Effect   = "Allow"
      Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
      Resource = local.log_group_arns
    }]
  })
}

resource "aws_iam_role_policy_attachment" "writer" {
  for_each = toset(var.writer_principal_arns)
  # Attach to the role name extracted from the supplied role ARN.
  role       = regex("role/(.+)$", each.value)[0]
  policy_arn = aws_iam_policy.writer.arn
}

# -----------------------------------------------------------------------------
# Legal-hold role (separation of duties — distinct from writers/operators)
# -----------------------------------------------------------------------------
resource "aws_iam_role" "legal_hold" {
  count = var.enable_legal_hold_role ? 1 : 0
  name  = "${var.name_prefix}-audit-legal-hold"
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { AWS = "arn:${data.aws_partition.current.partition}:iam::${data.aws_caller_identity.current.account_id}:root" }
      Action    = "sts:AssumeRole"
      Condition = { Bool = { "aws:MultiFactorAuthPresent" = "true" } }
    }]
  })
  tags = local.tags
}

resource "aws_iam_role_policy" "legal_hold" {
  count = var.enable_legal_hold_role ? 1 : 0
  name  = "${var.name_prefix}-audit-legal-hold"
  role  = aws_iam_role.legal_hold[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Sid      = "ToggleLegalHold"
      Effect   = "Allow"
      Action   = ["s3:PutObjectLegalHold", "s3:GetObjectLegalHold"]
      Resource = "${aws_s3_bucket.audit.arn}/*"
    }]
  })
}
