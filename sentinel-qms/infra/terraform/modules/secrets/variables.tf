variable "cloud" {
  description = "Target cloud: aws | azure."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to secret names."
  type        = string
}

variable "tags" {
  description = "Common tags/labels."
  type        = map(string)
  default     = {}
}

variable "kms_key_arn" {
  description = "KMS CMK ARN to encrypt secrets (AWS)."
  type        = string
  default     = null
}

variable "recovery_window_days" {
  description = "AWS Secrets Manager recovery window before permanent delete."
  type        = number
  default     = 30
}

# Map of secret short-name => value. Values must come from generated/random
# inputs or other modules — NEVER hardcoded literals in source.
variable "secrets" {
  description = "Map of secret name suffix to secret string value."
  type        = map(string)
  sensitive   = true
  default     = {}
}

# ── Azure ─────────────────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group for Key Vault (Azure)."
  type        = string
  default     = ""
}

variable "azure_tenant_id" {
  description = "Entra ID tenant id (Azure)."
  type        = string
  default     = ""
}

variable "azure_key_vault_name" {
  description = "Key Vault name (Azure). 3-24 chars, globally unique."
  type        = string
  default     = ""
}

variable "azure_subnet_ids" {
  description = "Subnets allowed to reach the Key Vault (Azure)."
  type        = list(string)
  default     = []
}

variable "azure_admin_object_ids" {
  description = "Object ids granted secret-management RBAC on the vault (Azure)."
  type        = list(string)
  default     = []
}
