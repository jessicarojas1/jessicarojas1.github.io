# =============================================================================
# bootstrap — variables for the Terraform remote-state backend
#
# Provisions the S3 state bucket (versioned, SSE-KMS, TLS-only, public-access-
# blocked), the DynamoDB lock table, and the state CMK that the audit-sink module
# (and any other platform Terraform) uses as its `backend "s3"`. Run this ONCE,
# with LOCAL state, before configuring the backend elsewhere.
# =============================================================================

variable "name_prefix" {
  description = "Prefix for the state backend resources (e.g. \"platform\"). Bucket becomes <prefix>-tfstate-<account_id>, table <prefix>-tfstate-locks."
  type        = string
  default     = "platform"
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,40}$", var.name_prefix))
    error_message = "name_prefix must be lowercase alphanumeric/hyphen, 2-41 chars."
  }
}

variable "kms_deletion_window_days" {
  description = "Deletion window for the state CMK."
  type        = number
  default     = 30
}

variable "state_noncurrent_version_expiration_days" {
  description = "Expire noncurrent (superseded) state object versions after N days. Keeps a rollback window without unbounded growth."
  type        = number
  default     = 90
}

variable "tags" {
  description = "Tags applied to every bootstrap resource."
  type        = map(string)
  default = {
    Component          = "terraform-state-backend"
    DataClassification = "CUI"
    ManagedBy          = "terraform"
  }
}
