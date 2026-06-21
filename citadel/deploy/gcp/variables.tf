###############################################################################
# CITADEL — Google Cloud Platform (GCP) Deployment
# variables.tf — Input variables with production-sensible defaults
#
# Naming convention: all resources are prefixed "${var.project}-${var.environment}"
# Labels (GCP's equivalent of tags) are applied via local.labels in main.tf.
###############################################################################

variable "project_id" {
  description = "Target GCP project ID that owns every resource in this stack."
  type        = string

  validation {
    # GCP project IDs: 6-30 chars, lowercase letters/digits/hyphens, start with a letter.
    condition     = can(regex("^[a-z][a-z0-9-]{5,29}$", var.project_id))
    error_message = "project_id must be 6-30 chars: lowercase letters, digits, hyphens, starting with a letter."
  }
}

variable "region" {
  description = "Primary GCP region for Cloud Run, Cloud SQL, Artifact Registry and the VPC connector."
  type        = string
  default     = "us-central1"

  validation {
    condition     = can(regex("^[a-z]+-[a-z]+[0-9]$", var.region))
    error_message = "region must be a valid GCP region slug, e.g. us-central1, europe-west1."
  }
}

variable "project" {
  description = "Project short-name used as a prefix for all resource names and the 'app' label."
  type        = string
  default     = "citadel"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{0,20}$", var.project))
    error_message = "project must be lowercase alphanumeric/hyphen, starting with a letter (<= 21 chars)."
  }
}

variable "environment" {
  description = "Deployment environment (e.g. prod, staging). Used in naming and labels."
  type        = string
  default     = "prod"

  validation {
    condition     = contains(["prod", "staging", "dev", "test"], var.environment)
    error_message = "environment must be one of: prod, staging, dev, test."
  }
}

variable "labels" {
  description = "Common labels applied to all resources. Label keys/values must be lowercase. Add data-classification / owner per your SSP."
  type        = map(string)
  default = {
    application = "citadel"
    managed-by  = "terraform"
    compliance  = "nist-800-53"
  }
}

###############################################################################
# API enablement
###############################################################################

variable "disable_services_on_destroy" {
  description = "When true, `terraform destroy` disables the project APIs it enabled. Keep false in shared projects so you do not break other workloads."
  type        = bool
  default     = false
}

###############################################################################
# Container image / Artifact Registry
###############################################################################

variable "image_tag" {
  description = "Container image tag deployed to Cloud Run (set by deploy.sh, typically the git short SHA)."
  type        = string
  default     = "latest"
}

variable "artifact_repo_id" {
  description = "Artifact Registry Docker repository ID that holds the citadel-server image."
  type        = string
  default     = "citadel"

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{0,62}$", var.artifact_repo_id))
    error_message = "artifact_repo_id must be lowercase alphanumeric/hyphen, starting with a letter."
  }
}

variable "image_name" {
  description = "Image name (path) within the Artifact Registry repository."
  type        = string
  default     = "citadel-server"
}

###############################################################################
# Cloud Run v2 service sizing & autoscaling
###############################################################################

variable "container_port" {
  description = "Port the CITADEL Node server listens on inside the container."
  type        = number
  default     = 8080
}

variable "cpu_limit" {
  description = "Cloud Run CPU limit per instance (e.g. '1', '2', '4'). Strings to match the Cloud Run API."
  type        = string
  default     = "1"
}

variable "memory_limit" {
  description = "Cloud Run memory limit per instance (e.g. '512Mi', '1Gi', '2Gi')."
  type        = string
  default     = "1Gi"

  validation {
    condition     = can(regex("^[0-9]+(Mi|Gi)$", var.memory_limit))
    error_message = "memory_limit must look like '512Mi' or '1Gi'."
  }
}

variable "min_instances" {
  description = "Minimum Cloud Run instances. >=1 avoids cold starts (and keeps DB pool warm) at the cost of always-on billing."
  type        = number
  default     = 1

  validation {
    condition     = var.min_instances >= 0 && var.min_instances <= 100
    error_message = "min_instances must be between 0 and 100."
  }
}

variable "max_instances" {
  description = "Maximum Cloud Run instances (autoscaling ceiling). Keep below Cloud SQL max_connections / (pool size)."
  type        = number
  default     = 10

  validation {
    condition     = var.max_instances >= 1 && var.max_instances <= 1000
    error_message = "max_instances must be between 1 and 1000."
  }
}

variable "max_request_concurrency" {
  description = "Max concurrent requests per Cloud Run instance before scaling out."
  type        = number
  default     = 80
}

variable "ingress" {
  description = "Cloud Run ingress control. Use INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER with the external LB so the run.app URL is not directly reachable (SC-7)."
  type        = string
  default     = "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER"

  validation {
    condition = contains([
      "INGRESS_TRAFFIC_ALL",
      "INGRESS_TRAFFIC_INTERNAL_ONLY",
      "INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER",
    ], var.ingress)
    error_message = "ingress must be one of INGRESS_TRAFFIC_ALL, INGRESS_TRAFFIC_INTERNAL_ONLY, INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER."
  }
}

variable "allow_unauthenticated" {
  description = "Grant roles/run.invoker to allUsers so the service is publicly invokable. The app does its own JWT auth, so this is normal for a public web app; set false to require IAM-authenticated callers only."
  type        = bool
  default     = true
}

###############################################################################
# Networking — VPC + Serverless VPC Access connector
###############################################################################

variable "network_name" {
  description = "Name of the VPC network created for private Cloud SQL connectivity."
  type        = string
  default     = "citadel-vpc"
}

variable "subnet_cidr" {
  description = "Primary subnet CIDR (reserved; Cloud Run egresses via the connector subnet below)."
  type        = string
  default     = "10.8.0.0/24"
}

variable "connector_cidr" {
  description = "Dedicated /28 CIDR for the Serverless VPC Access connector (must not overlap any other subnet)."
  type        = string
  default     = "10.8.1.0/28"

  validation {
    condition     = can(cidrhost(var.connector_cidr, 0)) && tonumber(split("/", var.connector_cidr)[1]) == 28
    error_message = "connector_cidr must be a valid /28 CIDR (Serverless VPC Access requires exactly /28)."
  }
}

variable "connector_min_instances" {
  description = "Minimum VPC connector instances (throughput floor)."
  type        = number
  default     = 2
}

variable "connector_max_instances" {
  description = "Maximum VPC connector instances (throughput ceiling)."
  type        = number
  default     = 3
}

variable "connector_machine_type" {
  description = "Machine type for VPC connector instances (e2-micro / e2-standard-4 etc.)."
  type        = string
  default     = "e2-micro"
}

###############################################################################
# Cloud SQL for PostgreSQL
###############################################################################

variable "db_tier" {
  description = "Cloud SQL machine tier. Use a dedicated-core tier (e.g. db-custom-2-7680) for production HA."
  type        = string
  default     = "db-custom-2-7680"
}

variable "db_version" {
  description = "Cloud SQL PostgreSQL engine version."
  type        = string
  default     = "POSTGRES_16"

  validation {
    condition     = can(regex("^POSTGRES_[0-9]+$", var.db_version))
    error_message = "db_version must look like POSTGRES_16."
  }
}

variable "db_disk_size_gb" {
  description = "Initial Cloud SQL data disk size in GB (auto-resize is enabled)."
  type        = number
  default     = 20
}

variable "db_availability_type" {
  description = "REGIONAL for HA (synchronous standby across zones, CP-10) or ZONAL for single-zone/dev."
  type        = string
  default     = "REGIONAL"

  validation {
    condition     = contains(["REGIONAL", "ZONAL"], var.db_availability_type)
    error_message = "db_availability_type must be REGIONAL or ZONAL."
  }
}

variable "db_name" {
  description = "Application database name created inside the Cloud SQL instance."
  type        = string
  default     = "citadel"
}

variable "db_user" {
  description = "Application database user (password is generated and stored in Secret Manager)."
  type        = string
  default     = "citadel_app"
}

variable "db_backup_retention_days" {
  description = "Number of automated backups retained (CP-9). Also drives PITR transaction-log retention."
  type        = number
  default     = 14

  validation {
    condition     = var.db_backup_retention_days >= 7
    error_message = "db_backup_retention_days must be >= 7 for production data protection."
  }
}

variable "db_deletion_protection" {
  description = "Protect the Cloud SQL instance from accidental terraform/console deletion."
  type        = bool
  default     = true
}

###############################################################################
# Encryption at rest — CMEK toggle
###############################################################################

variable "enable_cmek" {
  description = "Use a Customer-Managed Encryption Key (Cloud KMS) for Cloud SQL instead of the Google-managed default key (SC-12, SC-28)."
  type        = bool
  default     = false
}

variable "cmek_key_name" {
  description = "Fully-qualified Cloud KMS CryptoKey resource name for Cloud SQL CMEK (required when enable_cmek = true). Format: projects/<p>/locations/<r>/keyRings/<kr>/cryptoKeys/<k>."
  type        = string
  default     = ""

  validation {
    condition     = var.cmek_key_name == "" || can(regex("^projects/[^/]+/locations/[^/]+/keyRings/[^/]+/cryptoKeys/[^/]+$", var.cmek_key_name))
    error_message = "cmek_key_name must be empty or a fully-qualified Cloud KMS CryptoKey resource name."
  }
}

###############################################################################
# CITADEL application configuration (non-secret env vars)
###############################################################################

variable "citadel_admin_email" {
  description = "Bootstrap admin email seeded by the app (CITADEL_ADMIN_EMAIL). Non-secret; the password is generated and stored in Secret Manager."
  type        = string
  default     = "admin@citadel.local"
}

variable "citadel_multitenant" {
  description = "Toggle CITADEL multi-tenant mode (sets CITADEL_MULTITENANT=1 when true, else 0)."
  type        = bool
  default     = false
}

variable "citadel_base_domain" {
  description = "Base domain for CITADEL (CITADEL_BASE_DOMAIN). Used for tenant subdomains in multi-tenant mode."
  type        = string
  default     = ""
}

###############################################################################
# External HTTPS Load Balancer + Cloud Armor (optional front door)
###############################################################################

variable "enable_external_lb" {
  description = "Provision a global external HTTPS Application Load Balancer with a serverless NEG, managed SSL cert and Cloud Armor in front of Cloud Run (SC-7). When false, expose Cloud Run directly per var.ingress."
  type        = bool
  default     = true
}

variable "lb_domains" {
  description = "Domain name(s) for the Google-managed SSL certificate on the external LB. Required (non-empty) when enable_external_lb = true. Point each domain's DNS at the LB IP output after apply."
  type        = list(string)
  default     = []

  validation {
    condition     = alltrue([for d in var.lb_domains : can(regex("^([a-z0-9-]+\\.)+[a-z]{2,}$", d))])
    error_message = "Each entry in lb_domains must be a valid fully-qualified domain name."
  }
}

variable "armor_allowed_cidrs" {
  description = "Source CIDRs Cloud Armor permits to reach the LB. Default open; restrict to corporate / VPN ranges for production (SC-7)."
  type        = list(string)
  default     = ["0.0.0.0/0"]
}

variable "armor_rate_limit_rpm" {
  description = "Cloud Armor per-IP rate-limit threshold (requests per minute) before throttling (SC-5 denial-of-service protection)."
  type        = number
  default     = 1200
}
