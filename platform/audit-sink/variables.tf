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
  description = "IAM principal ARNs (task roles / instance roles for aegis, paladin, sentinel-qms) granted least-privilege append-only access to the CloudWatch Log groups. They get CreateLogStream + PutLogEvents only — never Delete/Put on the S3 bucket."
  type        = list(string)
  default     = []
}

# ── Legal hold ───────────────────────────────────────────────────────────────
variable "enable_legal_hold_role" {
  description = "Create a dedicated, separately-assumable role that may toggle S3 Object Lock legal holds (for litigation/investigation). Kept distinct from writers and operators (separation of duties)."
  type        = bool
  default     = true
}
