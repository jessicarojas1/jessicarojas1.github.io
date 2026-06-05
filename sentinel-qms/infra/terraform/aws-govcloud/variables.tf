variable "aws_region" {
  description = "AWS GovCloud region."
  type        = string
  default     = "us-gov-west-1"
}

variable "allowed_account_ids" {
  description = "Account ids Terraform is permitted to operate on."
  type        = list(string)
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

variable "availability_zones" {
  description = "GovCloud AZs (two for HA)."
  type        = list(string)
  default     = ["us-gov-west-1a", "us-gov-west-1b"]
}

variable "vpc_cidr" {
  description = "VPC CIDR."
  type        = string
  default     = "10.40.0.0/16"
}

variable "ingress_allowed_cidrs" {
  description = "CIDRs allowed to reach the ALB (restrict to agency ranges in prod)."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

# ── Images ────────────────────────────────────────────────────────────────────
variable "backend_image" {
  description = "Backend image (ECR-gov repo:tag)."
  type        = string
}

variable "frontend_image" {
  description = "Frontend image (ECR-gov repo:tag)."
  type        = string
}

# ── TLS ───────────────────────────────────────────────────────────────────────
variable "acm_certificate_arn" {
  description = "ACM certificate ARN for the ALB HTTPS listener."
  type        = string
}

# ── Database sizing ───────────────────────────────────────────────────────────
variable "db_instance_class" {
  description = "RDS instance class."
  type        = string
  default     = "db.m6g.large"
}

variable "db_allocated_storage_gb" {
  description = "RDS allocated storage (GB)."
  type        = number
  default     = 100
}

variable "db_multi_az" {
  description = "Enable RDS Multi-AZ."
  type        = bool
  default     = true
}

# ── App config ────────────────────────────────────────────────────────────────
variable "oidc_issuer" {
  description = "OIDC issuer URL (federal SSO / agency IdP)."
  type        = string
  default     = ""
}

variable "oidc_client_id" {
  description = "OIDC client id (non-secret)."
  type        = string
  default     = ""
}

variable "cors_origins" {
  description = "Allowed CORS origins for the API."
  type        = string
}

variable "log_level" {
  description = "Backend log level."
  type        = string
  default     = "INFO"
}

variable "alarm_email" {
  description = "Email subscribed to CloudWatch alarms."
  type        = string
  default     = ""
}

# ── Secrets supplied out-of-band (e.g. from CI secret store), NOT in tfvars ───
variable "oidc_client_secret" {
  description = "OIDC client secret (pass via TF_VAR_oidc_client_secret, never commit)."
  type        = string
  sensitive   = true
  default     = ""
}
