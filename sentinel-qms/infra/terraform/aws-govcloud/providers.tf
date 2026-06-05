# =============================================================================
# AWS GovCloud provider — us-gov-west-1 with FIPS 140-2 endpoints enabled.
# =============================================================================
terraform {
  required_version = ">= 1.6.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = ">= 5.40.0, < 6.0.0"
    }
    random = {
      source  = "hashicorp/random"
      version = ">= 3.6.0"
    }
  }
}

provider "aws" {
  region = var.aws_region

  # GovCloud requires the aws-us-gov partition; FIPS endpoints satisfy the
  # FedRAMP / NIST SP 800-171 encryption-in-transit control for the control plane.
  use_fips_endpoint = true

  default_tags {
    tags = local.common_tags
  }

  # Guard rail: refuse to run against a commercial account by mistake.
  allowed_account_ids = var.allowed_account_ids
}

provider "random" {}
