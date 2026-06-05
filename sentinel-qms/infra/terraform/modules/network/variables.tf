variable "cloud" {
  description = "Target cloud: aws | azure. Selects which resource set is created."
  type        = string
  validation {
    condition     = contains(["aws", "azure"], var.cloud)
    error_message = "cloud must be one of: aws, azure."
  }
}

variable "name_prefix" {
  description = "Prefix applied to all resource names (e.g. sentinel-qms-prod)."
  type        = string
}

variable "tags" {
  description = "Common tags/labels applied to every resource."
  type        = map(string)
  default     = {}
}

# ── Addressing ────────────────────────────────────────────────────────────────
variable "cidr_block" {
  description = "Primary CIDR for the VPC/VNet."
  type        = string
  default     = "10.40.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "CIDRs for public (ingress/NAT) subnets, one per AZ/zone."
  type        = list(string)
  default     = ["10.40.0.0/22", "10.40.4.0/22"]
}

variable "private_subnet_cidrs" {
  description = "CIDRs for private (app) subnets, one per AZ/zone."
  type        = list(string)
  default     = ["10.40.16.0/22", "10.40.20.0/22"]
}

variable "data_subnet_cidrs" {
  description = "CIDRs for isolated data (database) subnets, one per AZ/zone."
  type        = list(string)
  default     = ["10.40.32.0/22", "10.40.36.0/22"]
}

# ── AWS-specific ──────────────────────────────────────────────────────────────
variable "aws_availability_zones" {
  description = "AWS AZ names (GovCloud). Length must match subnet list lengths."
  type        = list(string)
  default     = ["us-gov-west-1a", "us-gov-west-1b"]
}

variable "single_nat_gateway" {
  description = "Use a single NAT gateway (cheaper, non-HA) vs one per AZ."
  type        = bool
  default     = false
}

# ── Azure-specific ────────────────────────────────────────────────────────────
variable "azure_location" {
  description = "Azure Government region (e.g. usgovvirginia, usgovtexas)."
  type        = string
  default     = "usgovvirginia"
}

variable "azure_resource_group_name" {
  description = "Resource group the VNet is created in (Azure only)."
  type        = string
  default     = ""
}

# ── Ingress / audit ───────────────────────────────────────────────────────────
variable "ingress_allowed_cidrs" {
  description = "CIDRs permitted to reach the public load balancer on HTTPS."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "flow_log_destination_arn" {
  description = "CloudWatch Logs group ARN for VPC flow logs (AWS only)."
  type        = string
  default     = null
}

variable "flow_log_role_arn" {
  description = "IAM role ARN allowing VPC flow log delivery (AWS only)."
  type        = string
  default     = null
}
