variable "cloud" {
  description = "Target cloud: aws | azure."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to observability resource names."
  type        = string
}

variable "tags" {
  description = "Common tags/labels."
  type        = map(string)
  default     = {}
}

variable "log_retention_days" {
  description = "Log retention in days."
  type        = number
  default     = 365
}

variable "kms_key_arn" {
  description = "KMS CMK ARN for log encryption (AWS)."
  type        = string
  default     = null
}

variable "alarm_sns_topic_arn" {
  description = "SNS topic ARN that alarms notify (AWS). Empty = create one."
  type        = string
  default     = ""
}

variable "alarm_email" {
  description = "Email address subscribed to alarm notifications."
  type        = string
  default     = ""
}

variable "log_group_names" {
  description = "Additional log group base names to create (AWS), e.g. backend, frontend."
  type        = list(string)
  default     = ["backend", "frontend"]
}

# Resource handles to alarm on (passed in from the root stack).
variable "alb_arn_suffix" {
  description = "ALB ARN suffix for target/5xx alarms (AWS)."
  type        = string
  default     = ""
}

variable "db_instance_id" {
  description = "RDS instance id for DB alarms (AWS)."
  type        = string
  default     = ""
}

# ── Azure ─────────────────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group for monitoring (Azure)."
  type        = string
  default     = ""
}

variable "azure_monitored_resource_ids" {
  description = "Resource ids to attach metric alerts to (Azure)."
  type        = list(string)
  default     = []
}
