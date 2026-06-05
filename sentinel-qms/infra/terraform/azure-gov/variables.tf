variable "location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "tenant_id" {
  description = "Entra ID (Azure AD) tenant id."
  type        = string
}

variable "project" {
  description = "Project name (tagging + naming)."
  type        = string
  default     = "sentinel-qms"
}

variable "environment" {
  description = "Deployment environment."
  type        = string
  default     = "prod"
}

variable "owner" {
  description = "Owning team / POC for tags."
  type        = string
  default     = "qms-platform"
}

variable "vnet_cidr" {
  description = "VNet address space."
  type        = string
  default     = "10.50.0.0/16"
}

variable "public_subnet_cidr" {
  description = "Application Gateway subnet CIDR."
  type        = string
  default     = "10.50.0.0/24"
}

variable "app_subnet_cidr" {
  description = "Container Apps infrastructure subnet CIDR (>= /23)."
  type        = string
  default     = "10.50.16.0/23"
}

variable "data_subnet_cidr" {
  description = "Delegated PostgreSQL subnet CIDR."
  type        = string
  default     = "10.50.32.0/24"
}

# ── Images ────────────────────────────────────────────────────────────────────
variable "backend_image" {
  description = "Backend image (ACR repo:tag)."
  type        = string
}

variable "frontend_image" {
  description = "Frontend image (ACR repo:tag)."
  type        = string
}

# ── Database ──────────────────────────────────────────────────────────────────
variable "db_sku_name" {
  description = "Flexible Server SKU."
  type        = string
  default     = "GP_Standard_D2ds_v5"
}

variable "db_storage_gb" {
  description = "Flexible Server storage (GB)."
  type        = number
  default     = 128
}

variable "db_ha_enabled" {
  description = "Enable zone-redundant HA."
  type        = bool
  default     = true
}

# ── App config ────────────────────────────────────────────────────────────────
variable "oidc_issuer" {
  description = "OIDC issuer URL."
  type        = string
  default     = ""
}

variable "oidc_client_id" {
  description = "OIDC client id (non-secret)."
  type        = string
  default     = ""
}

variable "cors_origins" {
  description = "Allowed CORS origins."
  type        = string
}

variable "log_level" {
  description = "Backend log level."
  type        = string
  default     = "INFO"
}

variable "alarm_email" {
  description = "Email subscribed to Azure Monitor alerts."
  type        = string
  default     = ""
}

variable "admin_object_ids" {
  description = "Entra object ids granted Key Vault secret management."
  type        = list(string)
  default     = []
}

variable "oidc_client_secret" {
  description = "OIDC client secret (pass via TF_VAR_oidc_client_secret)."
  type        = string
  sensitive   = true
  default     = ""
}
