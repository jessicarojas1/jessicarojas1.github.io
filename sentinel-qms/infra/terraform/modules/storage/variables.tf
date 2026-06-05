variable "cloud" {
  description = "Target cloud: aws | azure."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to storage resource names."
  type        = string
}

variable "tags" {
  description = "Common tags/labels."
  type        = map(string)
  default     = {}
}

variable "bucket_name" {
  description = "S3 bucket name (AWS). Must be globally unique."
  type        = string
  default     = ""
}

variable "kms_key_arn" {
  description = "KMS CMK ARN for SSE-KMS (AWS)."
  type        = string
  default     = null
}

variable "noncurrent_version_expiration_days" {
  description = "Days before noncurrent object versions expire."
  type        = number
  default     = 365
}

variable "force_destroy" {
  description = "Allow Terraform to delete a non-empty bucket (keep false in prod)."
  type        = bool
  default     = false
}

# ── Azure ─────────────────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group for the storage account (Azure)."
  type        = string
  default     = ""
}

variable "azure_storage_account_name" {
  description = "Storage account name (Azure). 3-24 lowercase alphanumeric."
  type        = string
  default     = ""
}

variable "azure_container_name" {
  description = "Blob container name for uploads (Azure)."
  type        = string
  default     = "uploads"
}

variable "azure_subnet_ids" {
  description = "Subnet ids allowed to reach the storage account (Azure)."
  type        = list(string)
  default     = []
}

variable "azure_cmk_key_vault_key_id" {
  description = "Key Vault key id for CMK encryption (Azure). Null = Microsoft-managed."
  type        = string
  default     = null
}

variable "azure_cmk_identity_id" {
  description = "User-assigned identity id with access to the CMK (Azure)."
  type        = string
  default     = null
}
