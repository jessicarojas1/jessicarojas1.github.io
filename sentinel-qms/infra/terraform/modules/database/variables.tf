variable "cloud" {
  description = "Target cloud: aws | azure."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to database resource names."
  type        = string
}

variable "tags" {
  description = "Common tags/labels."
  type        = map(string)
  default     = {}
}

variable "db_name" {
  description = "Initial database name."
  type        = string
  default     = "sentinel_qms"
}

variable "db_admin_username" {
  description = "Master/admin username."
  type        = string
  default     = "sentinel_admin"
}

variable "db_admin_password" {
  description = "Master/admin password (sourced from Secrets Manager / Key Vault, never hardcoded)."
  type        = string
  sensitive   = true
}

variable "engine_version" {
  description = "PostgreSQL major version."
  type        = string
  default     = "16"
}

variable "instance_class" {
  description = "AWS RDS instance class."
  type        = string
  default     = "db.m6g.large"
}

variable "azure_sku_name" {
  description = "Azure Flexible Server SKU."
  type        = string
  default     = "GP_Standard_D2ds_v5"
}

variable "allocated_storage_gb" {
  description = "Storage size in GB."
  type        = number
  default     = 100
}

variable "max_allocated_storage_gb" {
  description = "AWS storage autoscaling cap in GB."
  type        = number
  default     = 500
}

variable "multi_az" {
  description = "Enable multi-AZ / zone-redundant HA."
  type        = bool
  default     = true
}

variable "backup_retention_days" {
  description = "Automated backup retention in days."
  type        = number
  default     = 35
}

variable "kms_key_arn" {
  description = "KMS CMK ARN for storage encryption at rest (AWS)."
  type        = string
  default     = null
}

# ── AWS networking ────────────────────────────────────────────────────────────
variable "data_subnet_ids" {
  description = "Isolated data subnet ids for the DB subnet group (AWS)."
  type        = list(string)
  default     = []
}

variable "vpc_security_group_ids" {
  description = "Security groups attached to the DB instance (AWS)."
  type        = list(string)
  default     = []
}

variable "monitoring_role_arn" {
  description = "Enhanced monitoring IAM role ARN (AWS)."
  type        = string
  default     = null
}

# ── Azure networking ──────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group for the database (Azure)."
  type        = string
  default     = ""
}

variable "azure_delegated_subnet_id" {
  description = "Delegated subnet id for VNet-injected Flexible Server (Azure)."
  type        = string
  default     = null
}

variable "azure_private_dns_zone_id" {
  description = "Private DNS zone id for Flexible Server (Azure)."
  type        = string
  default     = null
}

variable "azure_customer_managed_key_id" {
  description = "Key Vault key id for CMK encryption (Azure). Null = service-managed key."
  type        = string
  default     = null
}

variable "azure_cmk_identity_id" {
  description = "User-assigned identity id used to access the CMK (Azure)."
  type        = string
  default     = null
}
