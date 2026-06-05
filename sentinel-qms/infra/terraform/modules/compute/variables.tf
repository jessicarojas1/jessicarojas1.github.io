variable "cloud" {
  description = "Target cloud: aws | azure."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to compute resource names."
  type        = string
}

variable "environment" {
  description = "Deployment environment (e.g. prod, staging)."
  type        = string
  default     = "prod"
}

variable "tags" {
  description = "Common tags/labels."
  type        = map(string)
  default     = {}
}

# ── Images ────────────────────────────────────────────────────────────────────
variable "backend_image" {
  description = "Fully-qualified backend container image (registry/repo:tag)."
  type        = string
}

variable "frontend_image" {
  description = "Fully-qualified frontend container image (registry/repo:tag)."
  type        = string
}

variable "backend_port" {
  description = "Backend container port."
  type        = number
  default     = 8000
}

variable "frontend_port" {
  description = "Frontend container port."
  type        = number
  default     = 8080
}

variable "backend_cpu" {
  description = "Backend vCPU units (AWS: 256=0.25 vCPU)."
  type        = number
  default     = 1024
}

variable "backend_memory" {
  description = "Backend memory in MiB."
  type        = number
  default     = 2048
}

variable "frontend_cpu" {
  description = "Frontend vCPU units."
  type        = number
  default     = 256
}

variable "frontend_memory" {
  description = "Frontend memory in MiB."
  type        = number
  default     = 512
}

variable "backend_desired_count" {
  description = "Desired backend replica count."
  type        = number
  default     = 3
}

variable "frontend_desired_count" {
  description = "Desired frontend replica count."
  type        = number
  default     = 2
}

# ── App configuration / secrets ───────────────────────────────────────────────
variable "non_secret_env" {
  description = "Plain (non-secret) environment variables for the backend."
  type        = map(string)
  default     = {}
}

variable "secret_env_arns" {
  description = "Map ENV_VAR_NAME => Secrets Manager ARN / Key Vault secret id."
  type        = map(string)
  default     = {}
}

# ── AWS networking / TLS / observability ──────────────────────────────────────
variable "vpc_id" {
  description = "VPC id (AWS)."
  type        = string
  default     = ""
}

variable "public_subnet_ids" {
  description = "Public subnet ids for the ALB (AWS)."
  type        = list(string)
  default     = []
}

variable "private_subnet_ids" {
  description = "Private subnet ids for Fargate tasks (AWS)."
  type        = list(string)
  default     = []
}

variable "alb_security_group_id" {
  description = "Security group for the ALB (AWS)."
  type        = string
  default     = ""
}

variable "app_security_group_id" {
  description = "Security group for the tasks (AWS)."
  type        = string
  default     = ""
}

variable "acm_certificate_arn" {
  description = "ACM cert ARN for the HTTPS listener (AWS)."
  type        = string
  default     = ""
}

variable "log_group_backend" {
  description = "CloudWatch log group for backend (AWS)."
  type        = string
  default     = ""
}

variable "log_group_frontend" {
  description = "CloudWatch log group for frontend (AWS)."
  type        = string
  default     = ""
}

variable "kms_key_arn" {
  description = "KMS CMK ARN for ECS exec / log encryption (AWS)."
  type        = string
  default     = null
}

variable "enable_waf" {
  description = "Attach an AWS WAFv2 web ACL to the ALB."
  type        = bool
  default     = true
}

variable "ssl_policy" {
  description = "ALB SSL negotiation policy (FIPS for GovCloud)."
  type        = string
  default     = "ELBSecurityPolicy-FS-1-2-Res-2020-10"
}

# ── Azure ─────────────────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group for compute (Azure)."
  type        = string
  default     = ""
}

variable "azure_infra_subnet_id" {
  description = "Subnet id for the Container Apps environment (Azure)."
  type        = string
  default     = null
}

variable "azure_log_analytics_workspace_id" {
  description = "Log Analytics workspace id for Container Apps (Azure)."
  type        = string
  default     = null
}

variable "azure_acr_login_server" {
  description = "Azure Container Registry login server (Azure)."
  type        = string
  default     = ""
}

variable "azure_identity_id" {
  description = "User-assigned managed identity id for pulling images / reading Key Vault (Azure)."
  type        = string
  default     = null
}
