###############################################################################
# CITADEL — AWS Commercial Deployment
# variables.tf — Input variables with commercial-AWS-sensible defaults
#
# Partition: aws   Default region: us-east-1
# These defaults assume the standard commercial AWS partition (not GovCloud).
###############################################################################

variable "region" {
  description = "AWS commercial region (standard partition). e.g. us-east-1, us-west-2, eu-west-1."
  type        = string
  default     = "us-east-1"

  validation {
    # Reject GovCloud / China regions — this stack targets the commercial `aws` partition.
    condition     = !startswith(var.region, "us-gov-") && !startswith(var.region, "cn-")
    error_message = "region must be a commercial AWS region (the aws partition). GovCloud (us-gov-*) and China (cn-*) regions are not supported by this stack — use deploy/aws-gov for GovCloud."
  }
}

variable "use_fips_endpoint" {
  description = "Force the AWS provider/SDK to use FIPS 140-3 validated service endpoints. Defaults to false for standard commercial deployments; set true only if your compliance scope requires FIPS endpoints."
  type        = bool
  default     = false
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
  description = "Common tags applied to all resources. Add data-classification / system owner per your policy."
  type        = map(string)
  default = {
    Project     = "CITADEL"
    Application = "Code Inspection, Threat Analysis & Deployment Evaluation Lab"
    ManagedBy   = "Terraform"
    Partition   = "aws"
  }
}

###############################################################################
# Networking
###############################################################################

variable "vpc_id" {
  description = "Existing VPC ID to deploy into. Leave empty to create a dedicated VPC (recommended)."
  type        = string
  default     = ""
}

variable "vpc_cidr" {
  description = "CIDR block for the dedicated VPC (used only when vpc_id is empty)."
  type        = string
  default     = "10.60.0.0/16"
}

variable "public_subnet_cidrs" {
  description = "CIDRs for public subnets (ALB ingress + NAT). One per AZ."
  type        = list(string)
  default     = ["10.60.0.0/24", "10.60.1.0/24"]
}

variable "private_subnet_cidrs" {
  description = "CIDRs for private subnets (ECS Fargate tasks, RDS). One per AZ."
  type        = list(string)
  default     = ["10.60.10.0/24", "10.60.11.0/24"]
}

variable "availability_zones" {
  description = "AZs to spread subnets across. Leave empty to auto-select the first two via data source."
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
  description = "Port the CITADEL server listens on inside the container (app default PORT=8080)."
  type        = number
  default     = 8080
}

variable "task_cpu" {
  description = "Fargate task CPU units (1024 = 1 vCPU). The deep-scan backend bundles heavy scanners — size generously."
  type        = number
  default     = 2048
}

variable "task_memory" {
  description = "Fargate task memory in MiB. Scanners (Trivy/Grype/ClamAV/Semgrep) are memory-hungry."
  type        = number
  default     = 4096
}

variable "ephemeral_storage_gib" {
  description = "Fargate ephemeral storage (GiB, 21-200). Used for the writable scratch volume mounted at the container's scan tmp dir."
  type        = number
  default     = 30

  validation {
    condition     = var.ephemeral_storage_gib >= 21 && var.ephemeral_storage_gib <= 200
    error_message = "ephemeral_storage_gib must be between 21 and 200 (Fargate limits)."
  }
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

variable "readonly_root_filesystem" {
  description = "Run the container with a read-only root filesystem. The app writes only to its scratch dir, which is provided by an ephemeral volume mounted at var.scratch_mount_path."
  type        = bool
  default     = true
}

variable "scratch_mount_path" {
  description = "Writable scratch path mounted into the container (the app's CITADEL_TMP). Must match where the app unpacks/scan untrusted uploads."
  type        = string
  default     = "/tmp/citadel"
}

###############################################################################
# Application configuration (non-secret env vars)
###############################################################################

variable "citadel_multitenant" {
  description = "Enable CITADEL multi-tenant mode (CITADEL_MULTITENANT). Default off."
  type        = bool
  default     = false
}

variable "citadel_base_domain" {
  description = "Base domain for CITADEL tenant routing (CITADEL_BASE_DOMAIN). Required when multitenant is on; otherwise informational."
  type        = string
  default     = ""
}

variable "citadel_admin_email" {
  description = "Bootstrap admin email (CITADEL_ADMIN_EMAIL). Non-secret; the password is generated into Secrets Manager."
  type        = string
  default     = "admin@example.com"
}

###############################################################################
# TLS / ALB
###############################################################################

variable "acm_certificate_arn" {
  description = "ARN of an ACM certificate (commercial partition) for the HTTPS listener. Required for the HTTPS listener to come up."
  type        = string
  default     = ""
}

variable "ssl_policy" {
  description = "ALB SSL negotiation policy. Modern TLS1.2/1.3 policy by default."
  type        = string
  default     = "ELBSecurityPolicy-TLS13-1-2-2021-06"
}

variable "ingress_allowed_cidrs" {
  description = "CIDRs permitted to reach the ALB on 443. Default open; restrict to your office/VPN ranges for production (SC-7)."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "enable_deletion_protection" {
  description = "Enable deletion protection on the ALB. Disable for ephemeral/non-prod stacks so terraform destroy works."
  type        = bool
  default     = true
}

###############################################################################
# RDS PostgreSQL
###############################################################################

variable "db_engine_version" {
  description = "PostgreSQL engine version for RDS."
  type        = string
  default     = "16.4"
}

variable "db_instance_class" {
  description = "RDS instance class. Scale up for production write/scan throughput."
  type        = string
  default     = "db.t3.medium"
}

variable "db_allocated_storage" {
  description = "Initial RDS allocated storage (GiB)."
  type        = number
  default     = 50
}

variable "db_max_allocated_storage" {
  description = "Upper bound for RDS storage autoscaling (GiB)."
  type        = number
  default     = 200
}

variable "db_name" {
  description = "Initial database name created in the RDS instance; also the DB path in DATABASE_URL."
  type        = string
  default     = "citadel"
}

variable "db_username" {
  description = "Master username for the RDS PostgreSQL instance. The password is generated into Secrets Manager."
  type        = string
  default     = "citadel"
}

variable "db_multi_az" {
  description = "Deploy RDS as Multi-AZ for HA failover. Recommended for production."
  type        = bool
  default     = true
}

variable "db_backup_retention_days" {
  description = "Number of days to retain automated RDS backups (CP-9)."
  type        = number
  default     = 14
}

variable "db_deletion_protection" {
  description = "Enable deletion protection on the RDS instance. Disable for ephemeral/non-prod stacks."
  type        = bool
  default     = true
}

###############################################################################
# Logging / Retention
###############################################################################

variable "log_retention_days" {
  description = "CloudWatch Logs retention in days (AU-11)."
  type        = number
  default     = 90
}
