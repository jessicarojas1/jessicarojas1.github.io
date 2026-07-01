# =============================================================================
# audit-sink/replica — variables
#
# Creates the CROSS-REGION REPLICATION DESTINATION for the audit archive: a
# second, independently Object-Locked bucket (versioned, SSE-KMS, deny-delete)
# in a DIFFERENT region from the primary. Instantiate it with an aws provider
# configured for the replica region, then feed its outputs into the primary
# audit-sink module (enable_crr, crr_destination_bucket_arn, crr_replica_kms_key_arn).
# =============================================================================

variable "name_prefix" {
  description = "Prefix for the replica resource names (match the primary sink, e.g. \"platform-prod\")."
  type        = string
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,40}$", var.name_prefix))
    error_message = "name_prefix must be lowercase alphanumeric/hyphen, 2-41 chars."
  }
}

variable "source_replication_role_arn" {
  description = "ARN of the primary sink's replication role (output replication_role_arn). Granted ReplicateObject on this destination bucket via the bucket policy for defence-in-depth (identity policy already grants it within the same account)."
  type        = string
}

variable "object_lock_mode" {
  description = "Object Lock retention mode for the replica bucket. Keep COMPLIANCE in prod so the replica is equally write-once."
  type        = string
  default     = "COMPLIANCE"
  validation {
    condition     = contains(["COMPLIANCE", "GOVERNANCE"], var.object_lock_mode)
    error_message = "object_lock_mode must be COMPLIANCE or GOVERNANCE."
  }
}

variable "object_lock_retention_days" {
  description = "Default Object Lock retention (days) on replicated objects. Match or exceed the primary."
  type        = number
  default     = 1095
  validation {
    condition     = var.object_lock_retention_days >= 1
    error_message = "object_lock_retention_days must be at least 1."
  }
}

variable "noncurrent_version_expiration_days" {
  description = "Days before noncurrent replica object versions are expired. Must exceed the lock retention."
  type        = number
  default     = 1460
}

variable "kms_key_arn" {
  description = "Existing CMK ARN (in the replica region) to encrypt the replica bucket. If empty, a dedicated rotated CMK is created in this region."
  type        = string
  default     = ""
}

variable "kms_deletion_window_days" {
  description = "Deletion window for the created replica CMK (only used when kms_key_arn is empty)."
  type        = number
  default     = 30
}

variable "tags" {
  description = "Tags applied to every replica resource."
  type        = map(string)
  default = {
    DataClassification = "CUI"
    Compliance         = "NIST-800-171;NIST-AU-9;CMMC-AU-L2;HIPAA-164.312b"
    ManagedBy          = "terraform"
    Component          = "central-audit-sink-replica"
  }
}
