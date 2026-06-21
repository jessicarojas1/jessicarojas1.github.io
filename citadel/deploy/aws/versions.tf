###############################################################################
# CITADEL — AWS Commercial Deployment
# versions.tf — Terraform + provider version constraints
#
# Partition: aws (standard commercial)   Default region: us-east-1
# Endpoints: standard (non-FIPS) by default; flip var.use_fips_endpoint to true
#            if your account/region must use FIPS 140-3 validated endpoints.
###############################################################################

terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.40"
    }
    random = {
      source  = "hashicorp/random"
      version = "~> 3.6"
    }
  }

  # Recommended: remote state in an S3 bucket with DynamoDB locking + SSE-KMS.
  # backend "s3" {
  #   bucket         = "citadel-tfstate-us-east-1"
  #   key            = "aws/prod/terraform.tfstate"
  #   region         = "us-east-1"
  #   dynamodb_table = "citadel-tflock"
  #   encrypt        = true
  # }
}

provider "aws" {
  region = var.region

  # Standard commercial endpoints by default. Set use_fips_endpoint = true to
  # force FIPS 140-3 validated endpoints across all AWS service calls (SC-13).
  use_fips_endpoint = var.use_fips_endpoint

  default_tags {
    tags = var.tags
  }
}
