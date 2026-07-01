# =============================================================================
# audit-sink — input variables
#
# Tamper-evident central audit log sink:
#   S3 (Object Lock COMPLIANCE) <- Firehose <- CloudWatch Logs subscription.
# Conventions mirror sentinel-qms/infra/terraform and citadel/deploy:
#   cloud-agnostic naming via name_prefix, CMK-by-default, deny-by-default IAM,
#   data-classification tagging.
# =============================================================================

variable "name_prefix" {
  description = "Prefix applied to all sink resource names (e.g. \"platform-prod\")."
  type        = string
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,40}$", var.name_prefix))
    error_message = "name_prefix must be lowercase alphanumeric/hyphen, 2-41 chars."
  }
}

variable "tags" {
  description = "Common tags applied to every resource."
  type        = map(string)
  default = {
    DataClassification = "CUI"
    Compliance         = "NIST-800-171;NIST-AU-9;CMMC-AU-L2;HIPAA-164.312b"
    ManagedBy          = "terraform"
    Component          = "central-audit-sink"
  }
}

# ── Object Lock / retention ──────────────────────────────────────────────────
variable "object_lock_mode" {
  description = "S3 Object Lock retention mode. COMPLIANCE is write-once and cannot be shortened or removed by anyone, including the root account (AU-9 / HIPAA write-once). GOVERNANCE permits override with a special permission (use only for non-prod)."
  type        = string
  default     = "COMPLIANCE"
  validation {
    condition     = contains(["COMPLIANCE", "GOVERNANCE"], var.object_lock_mode)
    error_message = "object_lock_mode must be COMPLIANCE or GOVERNANCE."
  }
}

variable "object_lock_retention_days" {
  description = "Default Object Lock retention applied to every audit object, in days. NIST AU-11 typically requires >= 365; CMMC/CUI commonly 3+ years."
  type        = number
  default     = 1095 # 3 years
  validation {
    condition     = var.object_lock_retention_days >= 1
    error_message = "object_lock_retention_days must be at least 1."
  }
}

variable "noncurrent_version_expiration_days" {
  description = "Days before noncurrent (superseded) object versions are expired. Must exceed the lock retention to avoid fighting the lock."
  type        = number
  default     = 1460 # 4 years
}

# ── CloudWatch Logs ──────────────────────────────────────────────────────────
variable "log_group_names" {
  description = "Base names of the central CloudWatch Log groups to create (one per source app or stream), e.g. [\"aegis\", \"paladin\", \"sentinel-qms\"]. Each app ships its per-app audit log here; a subscription filter forwards every record to Firehose -> the locked bucket."
  type        = list(string)
  default     = ["aegis", "paladin", "sentinel-qms"]
}

variable "log_retention_days" {
  description = "CloudWatch Logs retention (hot tier). The immutable archive lives in S3; CloudWatch is the queryable/SIEM-feed tier."
  type        = number
  default     = 400
}

variable "subscription_filter_pattern" {
  description = "CloudWatch Logs subscription filter pattern. Empty string forwards ALL log events (recommended for an audit sink)."
  type        = string
  default     = ""
}

# ── KMS ──────────────────────────────────────────────────────────────────────
variable "kms_key_arn" {
  description = "Existing CMK ARN to encrypt the bucket, log groups, and Firehose. If empty, a dedicated CMK with rotation is created."
  type        = string
  default     = ""
}

variable "kms_deletion_window_days" {
  description = "Deletion window for the created CMK (only used when kms_key_arn is empty)."
  type        = number
  default     = 30
}

# ── Firehose buffering ───────────────────────────────────────────────────────
variable "firehose_buffer_size_mb" {
  description = "Firehose buffering hint in MB before flushing to S3 (1-128)."
  type        = number
  default     = 5
}

variable "firehose_buffer_interval_seconds" {
  description = "Firehose buffering hint in seconds before flushing to S3 (60-900)."
  type        = number
  default     = 300
}

# ── Log-writer IAM ───────────────────────────────────────────────────────────
variable "writer_principal_arns" {
  description = "IAM principal ARNs (task roles / instance roles for aegis, paladin, sentinel-qms) granted least-privilege append-only access to the CloudWatch Log groups. They get CreateLogStream + PutLogEvents only — never Delete/Put on the S3 bucket. Each entry MUST be a plain IAM ROLE ARN (arn:<partition>:iam::<account>:role/<RoleName>) with NO path and NO session — the module derives the role name with regex(\"role/(.+)$\", …) to attach the writer policy, which only works for path-less role ARNs. Use the role ARN, not an assumed-role/STS session ARN."
  type        = list(string)
  default     = []
  validation {
    # Enforce the role-ARN assumption behind aws_iam_role_policy_attachment.writer.
    # Rejects users, sts assumed-role sessions, and paths (which would break the
    # regex("role/(.+)$", …) role-name extraction in main.tf).
    condition = alltrue([
      for a in var.writer_principal_arns :
      can(regex("^arn:aws[a-z-]*:iam::[0-9]{12}:role/[A-Za-z0-9_+=,.@-]+$", a))
    ])
    error_message = "Every writer_principal_arns entry must be a plain IAM role ARN: arn:<partition>:iam::<12-digit-account>:role/<RoleName> — no path, no user, no assumed-role/STS session ARN."
  }
}

# ── IAM hardening ────────────────────────────────────────────────────────────
variable "permissions_boundary_arn" {
  description = "Optional IAM permissions-boundary policy ARN attached to every IAM role this module creates (Firehose, CWL→Firehose, replication, legal-hold). Caps the effective permissions of these service roles. The Terraform *apply* role itself is external to this module; attach a boundary to it out-of-band (a reference boundary policy is provided in policies/apply-permissions-boundary.json)."
  type        = string
  default     = ""
  validation {
    condition     = var.permissions_boundary_arn == "" || can(regex("^arn:aws[a-z-]*:iam::[0-9]{12}:policy/.+$", var.permissions_boundary_arn))
    error_message = "permissions_boundary_arn must be empty or an IAM policy ARN (arn:<partition>:iam::<account>:policy/<name>)."
  }
}

# ── Cross-region replication (CRR) of the immutable archive ──────────────────
variable "enable_crr" {
  description = "Replicate the immutable archive to a second Object-Locked bucket in another region for regional durability (belt-and-braces on top of S3's in-region durability). When true, supply crr_destination_bucket_arn and crr_replica_kms_key_arn. Create the destination bucket with the ../audit-sink/replica submodule (a hardened, Object-Locked bucket in the replica region) and feed its outputs here."
  type        = bool
  default     = false
}

variable "crr_destination_bucket_arn" {
  description = "ARN of the destination (replica) Object-Locked bucket for CRR. Required when enable_crr = true. Typically module.audit_sink_replica.bucket_arn."
  type        = string
  default     = ""
  validation {
    condition     = var.crr_destination_bucket_arn == "" || can(regex("^arn:aws[a-z-]*:s3:::.+$", var.crr_destination_bucket_arn))
    error_message = "crr_destination_bucket_arn must be empty or an S3 bucket ARN (arn:<partition>:s3:::<bucket>)."
  }
}

variable "crr_replica_kms_key_arn" {
  description = "KMS CMK ARN in the replica region used to encrypt replicated objects (SSE-KMS). Required when enable_crr = true. Must be a key in the destination bucket's region."
  type        = string
  default     = ""
}

# ── Firehose delivery monitoring ─────────────────────────────────────────────
variable "enable_delivery_alarm" {
  description = "Create a CloudWatch alarm + SNS topic that fire when audit records fail to reach the S3 archive: (1) any errors written to the /audit/<prefix>/_firehose-errors log group, and (2) Firehose DeliveryToS3 data-freshness exceeding the threshold (silent delivery stall). Prevents silent audit-delivery gaps."
  type        = bool
  default     = true
}

variable "alarm_email" {
  description = "Optional email address subscribed to the audit-delivery SNS alarm topic. Leave empty to wire subscriptions out-of-band (e.g. to PagerDuty/OpsGenie or a shared alerting topic)."
  type        = string
  default     = ""
}

variable "delivery_freshness_alarm_seconds" {
  description = "Threshold (seconds) for the Firehose DeliveryToS3.DataFreshness alarm — the age of the oldest record not yet delivered to S3. Should exceed firehose_buffer_interval_seconds with headroom. Default 900s (15m)."
  type        = number
  default     = 900
}

# ── Legal hold ───────────────────────────────────────────────────────────────
variable "enable_legal_hold_role" {
  description = "Create a dedicated, separately-assumable role that may toggle S3 Object Lock legal holds (for litigation/investigation). Kept distinct from writers and operators (separation of duties)."
  type        = bool
  default     = true
}
