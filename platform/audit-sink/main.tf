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
  name                 = "${var.name_prefix}-audit-firehose"
  permissions_boundary = var.permissions_boundary_arn != "" ? var.permissions_boundary_arn : null
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
  name                 = "${var.name_prefix}-audit-cwl-to-firehose"
  permissions_boundary = var.permissions_boundary_arn != "" ? var.permissions_boundary_arn : null
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
  count                = var.enable_legal_hold_role ? 1 : 0
  name                 = "${var.name_prefix}-audit-legal-hold"
  permissions_boundary = var.permissions_boundary_arn != "" ? var.permissions_boundary_arn : null
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

# -----------------------------------------------------------------------------
# Cross-region replication (CRR) — optional regional durability for the archive
#
# Replicates every new object to a second, independently Object-Locked bucket in
# another region (create it with the ../replica submodule and pass its ARN via
# crr_destination_bucket_arn). Delete markers are NOT replicated (the source is
# WORM; there are no legitimate deletes to propagate). Requires source versioning
# (already enabled). The replica bucket keeps its own Object Lock, so a regional
# loss of the primary still leaves a tamper-evident copy.
# -----------------------------------------------------------------------------
resource "aws_iam_role" "replication" {
  count                = var.enable_crr ? 1 : 0
  name                 = "${var.name_prefix}-audit-replication"
  permissions_boundary = var.permissions_boundary_arn != "" ? var.permissions_boundary_arn : null
  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "s3.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
  tags = local.tags
}

resource "aws_iam_role_policy" "replication" {
  count = var.enable_crr ? 1 : 0
  name  = "${var.name_prefix}-audit-replication"
  role  = aws_iam_role.replication[0].id
  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "ReadSourceForReplication"
        Effect   = "Allow"
        Action   = ["s3:GetReplicationConfiguration", "s3:ListBucket"]
        Resource = aws_s3_bucket.audit.arn
      },
      {
        Sid    = "ReadSourceObjectsAndLockMetadata"
        Effect = "Allow"
        Action = [
          "s3:GetObjectVersionForReplication",
          "s3:GetObjectVersionAcl",
          "s3:GetObjectVersionTagging",
          "s3:GetObjectRetention",
          "s3:GetObjectLegalHold"
        ]
        Resource = "${aws_s3_bucket.audit.arn}/*"
      },
      {
        Sid    = "WriteToReplicaBucket"
        Effect = "Allow"
        Action = [
          "s3:ReplicateObject",
          "s3:ReplicateDelete",
          "s3:ReplicateTags",
          "s3:ObjectOwnerOverrideToBucketOwner"
        ]
        Resource = "${var.crr_destination_bucket_arn}/*"
      },
      {
        Sid      = "DecryptSourceCmk"
        Effect   = "Allow"
        Action   = ["kms:Decrypt"]
        Resource = local.kms_key_arn
      },
      {
        Sid      = "EncryptWithReplicaCmk"
        Effect   = "Allow"
        Action   = ["kms:Encrypt", "kms:GenerateDataKey"]
        Resource = var.crr_replica_kms_key_arn
      }
    ]
  })
}

resource "aws_s3_bucket_replication_configuration" "audit" {
  count      = var.enable_crr ? 1 : 0
  role       = aws_iam_role.replication[0].arn
  bucket     = aws_s3_bucket.audit.id
  depends_on = [aws_s3_bucket_versioning.audit]

  rule {
    id       = "replicate-all-audit-objects"
    status   = "Enabled"
    priority = 0
    filter {} # all objects

    # WORM source: never propagate deletes/delete-markers to the replica.
    delete_marker_replication {
      status = "Disabled"
    }

    # Replicate SSE-KMS-encrypted objects (they all are).
    source_selection_criteria {
      sse_kms_encrypted_objects {
        status = "Enabled"
      }
    }

    destination {
      bucket        = var.crr_destination_bucket_arn
      storage_class = "STANDARD"

      # Replica objects are re-encrypted with the replica-region CMK. No
      # access_control_translation: same-account replication into a
      # BucketOwnerEnforced destination already lands under the bucket owner.
      encryption_configuration {
        replica_kms_key_id = var.crr_replica_kms_key_arn
      }
    }
  }
}

# -----------------------------------------------------------------------------
# Delivery monitoring — alarm on Firehose delivery errors / stalls -> SNS
#
# Silent audit-delivery gaps are an availability risk for the audit trail. Two
# alarms cover it: (1) any record written to the dedicated _firehose-errors log
# group (delivery failures), and (2) Firehose DeliveryToS3.DataFreshness rising
# above the threshold (records buffered but not landing — a silent stall). Both
# notify the SNS topic. The topic is encrypted with the AWS-managed SNS key so
# the tightly-scoped audit CMK policy is not widened for CloudWatch/SNS.
# -----------------------------------------------------------------------------
resource "aws_sns_topic" "alarms" {
  count             = var.enable_delivery_alarm ? 1 : 0
  name              = "${var.name_prefix}-audit-delivery-alarms"
  kms_master_key_id = "alias/aws/sns"
  tags              = local.tags
}

resource "aws_sns_topic_subscription" "alarms_email" {
  count     = var.enable_delivery_alarm && var.alarm_email != "" ? 1 : 0
  topic_arn = aws_sns_topic.alarms[0].arn
  protocol  = "email"
  endpoint  = var.alarm_email
}

# Turn every event in the errors-only log group into a metric data point.
resource "aws_cloudwatch_log_metric_filter" "firehose_errors" {
  count          = var.enable_delivery_alarm ? 1 : 0
  name           = "${var.name_prefix}-audit-firehose-errors"
  log_group_name = aws_cloudwatch_log_group.firehose_errors.name
  pattern        = "" # any line in this group is a delivery error

  metric_transformation {
    name          = "FirehoseDeliveryErrors"
    namespace     = "Platform/AuditSink"
    value         = "1"
    default_value = "0"
    unit          = "Count"
  }
}

resource "aws_cloudwatch_metric_alarm" "firehose_errors" {
  count               = var.enable_delivery_alarm ? 1 : 0
  alarm_name          = "${var.name_prefix}-audit-firehose-delivery-errors"
  alarm_description   = "Audit records failed to deliver to the S3 archive (events present in /audit/${var.name_prefix}/_firehose-errors). Investigate Firehose role/KMS/bucket-policy."
  namespace           = "Platform/AuditSink"
  metric_name         = "FirehoseDeliveryErrors"
  statistic           = "Sum"
  period              = 300
  evaluation_periods  = 1
  threshold           = 1
  comparison_operator = "GreaterThanOrEqualToThreshold"
  treat_missing_data  = "notBreaching" # default_value=0 emits points; missing == no errors
  alarm_actions       = [aws_sns_topic.alarms[0].arn]
  ok_actions          = [aws_sns_topic.alarms[0].arn]
  tags                = local.tags
}

resource "aws_cloudwatch_metric_alarm" "firehose_freshness" {
  count               = var.enable_delivery_alarm ? 1 : 0
  alarm_name          = "${var.name_prefix}-audit-firehose-delivery-stalled"
  alarm_description   = "Firehose DeliveryToS3.DataFreshness exceeded ${var.delivery_freshness_alarm_seconds}s — audit records are buffered but not landing in S3 (silent stall)."
  namespace           = "AWS/Firehose"
  metric_name         = "DeliveryToS3.DataFreshness"
  dimensions          = { DeliveryStreamName = aws_kinesis_firehose_delivery_stream.audit.name }
  statistic           = "Maximum"
  period              = 300
  evaluation_periods  = 1
  threshold           = var.delivery_freshness_alarm_seconds
  comparison_operator = "GreaterThanThreshold"
  treat_missing_data  = "notBreaching" # no records flowing == nothing to deliver
  alarm_actions       = [aws_sns_topic.alarms[0].arn]
  ok_actions          = [aws_sns_topic.alarms[0].arn]
  tags                = local.tags
}
