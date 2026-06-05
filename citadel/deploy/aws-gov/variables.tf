###############################################################################
# CITADEL — AWS GovCloud (US) Deployment
# variables.tf — Input variables with GovCloud-sensible defaults
#
# All defaults assume the AWS GovCloud (US) partition: aws-us-gov
# Regions: us-gov-west-1 (US-West), us-gov-east-1 (US-East)
###############################################################################

variable "region" {
  description = "AWS GovCloud (US) region. Must be us-gov-west-1 or us-gov-east-1."
  type        = string
  default     = "us-gov-west-1"

  validation {
    condition     = contains(["us-gov-west-1", "us-gov-east-1"], var.region)
    error_message = "region must be an AWS GovCloud (US) region: us-gov-west-1 or us-gov-east-1."
  }
}

variable "use_fips_endpoint" {
  description = "Force the AWS provider and SDK to use FIPS 140-3 validated service endpoints (required for CUI / FedRAMP High / IL4-IL5)."
  type        = bool
  default     = true
}

variable "project" {
  description = "Project short-name used as a prefix for all resource names and tags."
  type        = string
  default     = "citadel"
}

variable "environment" {
  description = "Deployment environment (e.g. prod, staging). Used in naming and tagging."
  type        = string
  default     = "prod"
}

variable "tags" {
  description = "Common tags applied to all resources. Add data-classification / system owner per your SSP."
  type        = map(string)
  default = {
    Project            = "CITADEL"
    Application        = "Code Inspection, Threat Analysis & Deployment Evaluation Lab"
    DataClassification = "CUI"
    Compliance         = "FedRAMP-High;CMMC-L2;NIST-800-171;IL4-IL5"
    ManagedBy          = "Terraform"
    Partition          = "aws-us-gov"
  }
}

###############################################################################
# Networking
###############################################################################

variable "vpc_id" {
  description = "Existing VPC ID to deploy into. Leave empty to create a dedicated VPC."
  type        = string
  default     = ""
}

variable "vpc_cidr" {
  description = "CIDR block for the dedicated VPC (used only when vpc_id is empty)."
  type        = string
  default     = "10.80.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "CIDRs for public subnets (ALB / WAF ingress only). One per AZ."
  type        = list(string)
  default     = ["10.80.0.0/24", "10.80.1.0/24"]
}

variable "private_subnet_cidrs" {
  description = "CIDRs for private subnets (ECS Fargate tasks, VPC endpoints, no direct internet)."
  type        = list(string)
  default     = ["10.80.10.0/24", "10.80.11.0/24"]
}

variable "availability_zones" {
  description = "GovCloud AZs to spread subnets across. Leave empty to auto-select via data source."
  type        = list(string)
  default     = []
}

###############################################################################
# Container / ECS Fargate
###############################################################################

variable "image_tag" {
  description = "Container image tag deployed to ECS (set by deploy.sh, typically the git SHA)."
  type        = string
  default     = "latest"
}

variable "container_port" {
  description = "Port the CITADEL Nginx front-end listens on inside the container (non-privileged for non-root)."
  type        = number
  default     = 8080
}

variable "task_cpu" {
  description = "Fargate task CPU units (1024 = 1 vCPU). Increase for the heavier scanner/worker tier."
  type        = number
  default     = 1024
}

variable "task_memory" {
  description = "Fargate task memory in MiB."
  type        = number
  default     = 2048
}

variable "desired_count" {
  description = "Number of ECS service tasks to run (>=2 for HA across AZs)."
  type        = number
  default     = 2
}

variable "enable_container_insights" {
  description = "Enable CloudWatch Container Insights on the ECS cluster (AU-6, AU-12 monitoring)."
  type        = bool
  default     = true
}

###############################################################################
# TLS / ALB
###############################################################################

variable "acm_certificate_arn" {
  description = "ARN of an ACM certificate (GovCloud partition) for the HTTPS listener. TLS 1.2+ FIPS policy is enforced on the listener."
  type        = string
  default     = ""
}

variable "ssl_policy" {
  description = "ALB SSL negotiation policy. Use a FIPS / TLS1.2+ policy for CUI workloads."
  type        = string
  default     = "ELBSecurityPolicy-TLS13-1-2-FIPS-2023-04"
}

variable "ingress_allowed_cidrs" {
  description = "CIDRs permitted to reach the ALB on 443. Default open; restrict to agency ranges / TIC egress for production (SC-7)."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

###############################################################################
# Logging / Retention
###############################################################################

variable "log_retention_days" {
  description = "CloudWatch Logs retention in days (AU-11). FedRAMP High commonly requires >= 365 with offload to S3/Glacier."
  type        = number
  default     = 365
}

###############################################################################
# Quarantine bucket (uploads / scanned artifacts)
###############################################################################

variable "object_lock_retention_days" {
  description = "S3 Object Lock COMPLIANCE retention (days) for the quarantine bucket of uploaded/scanned artifacts (AU-9, SI-3)."
  type        = number
  default     = 30
}
