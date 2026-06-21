###############################################################################
# CITADEL — Google Cloud Platform (GCP) Deployment
# main.tf — Providers, project data, shared locals, and required-API enablement
#
# The rest of the stack is split by concern:
#   network.tf  — VPC, private services access, Serverless VPC Access connector
#   cloudsql.tf — Cloud SQL for PostgreSQL (private IP, backups, SSL, CMEK toggle)
#   secrets.tf  — Secret Manager secrets + versions (JWT, admin pw, tokens, DB URL)
#   iam.tf      — dedicated runtime service account + least-privilege bindings
#   cloudrun.tf — Cloud Run v2 service (probes, secret env vars, autoscaling)
#   lb.tf       — optional external HTTPS LB + Cloud Armor (gated, default on)
#   outputs.tf  — service URL, LB IP, registry, secret IDs
#
# This config is coherent and production-appropriate. Review your SSP / control
# tailoring before apply.
###############################################################################

provider "google" {
  project = var.project_id
  region  = var.region
}

provider "google-beta" {
  project = var.project_id
  region  = var.region
}

# Project metadata (number is needed to construct service-agent principals).
data "google_project" "current" {
  project_id = var.project_id
}

locals {
  name    = "${var.project}-${var.environment}"
  project = var.project_id
  region  = var.region

  # Merge the immutable identity labels onto the caller-supplied label map.
  labels = merge(var.labels, {
    app         = var.project
    environment = var.environment
  })

  # Artifact Registry image reference consumed by the Cloud Run service.
  image_repo = "${var.region}-docker.pkg.dev/${var.project_id}/${var.artifact_repo_id}"
  image_url  = "${local.image_repo}/${var.image_name}:${var.image_tag}"
}

###############################################################################
# Required Google APIs (services) — enable before any dependent resource.
# Each dependent resource carries depends_on = [google_project_service.apis]
# so the first apply does not race API activation.
###############################################################################

locals {
  required_services = toset([
    "run.googleapis.com",                  # Cloud Run v2 service
    "sqladmin.googleapis.com",             # Cloud SQL for PostgreSQL
    "secretmanager.googleapis.com",        # Secret Manager (IA-5, SC-12)
    "artifactregistry.googleapis.com",     # Artifact Registry (image store)
    "vpcaccess.googleapis.com",            # Serverless VPC Access connector
    "compute.googleapis.com",              # VPC, LB, Cloud Armor, networking
    "servicenetworking.googleapis.com",    # Private Services Access for Cloud SQL
    "iam.googleapis.com",                  # Service accounts / role bindings
    "cloudresourcemanager.googleapis.com", # Project-level IAM management
  ])
}

resource "google_project_service" "apis" {
  for_each = local.required_services

  project = var.project_id
  service = each.value

  # Keep dependent APIs that other workloads may rely on; controlled by a var.
  disable_on_destroy         = var.disable_services_on_destroy
  disable_dependent_services = false
}

###############################################################################
# Artifact Registry — Docker repository for the citadel-server image (SI-2/RA-5)
###############################################################################

resource "google_artifact_registry_repository" "citadel" {
  project       = var.project_id
  location      = var.region
  repository_id = var.artifact_repo_id
  description   = "CITADEL server container images"
  format        = "DOCKER"
  labels        = local.labels

  # Reclaim storage: drop untagged images older than 30 days.
  cleanup_policies {
    id     = "delete-untagged"
    action = "DELETE"
    condition {
      tag_state  = "UNTAGGED"
      older_than = "2592000s" # 30 days
    }
  }

  depends_on = [google_project_service.apis]
}
