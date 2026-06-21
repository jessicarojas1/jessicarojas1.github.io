###############################################################################
# CITADEL — Google Cloud Platform (GCP) Deployment
# versions.tf — Terraform & provider version constraints
#
# Architecture (high level):
#   Internet -> (optional) External HTTPS LB + Cloud Armor -> Cloud Run v2
#   Cloud Run -> Serverless VPC Access connector -> Cloud SQL (PostgreSQL, private IP)
#   Secret Manager (JWT / admin pw / superadmin & metrics tokens / DATABASE_URL)
#   Artifact Registry (Docker) | dedicated least-privilege runtime service account
#
# Pin both google and google-beta: a few Cloud Run v2 + serverless NEG / Cloud Armor
# arguments live in the beta provider, so we declare it even when unused by default.
###############################################################################

terraform {
  required_version = ">= 1.5.0"

  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 6.0"
    }
    google-beta = {
      source  = "hashicorp/google-beta"
      version = "~> 6.0"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # Recommended: remote state in a GCS bucket with object versioning + CMEK.
  # The bucket itself should live outside this stack (chicken-and-egg) and have
  # uniform bucket-level access + public access prevention enforced.
  # backend "gcs" {
  #   bucket = "citadel-tfstate-prod"
  #   prefix = "gcp/prod"
  # }
}
