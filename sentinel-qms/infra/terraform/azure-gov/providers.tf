# =============================================================================
# Azure Government provider — environment = "usgovernment".
# =============================================================================
terraform {
  required_version = ">= 1.6.0"
  required_providers {
    azurerm = {
      source  = "hashicorp/azurerm"
      version = ">= 3.100.0, < 4.0.0"
    }
    random = {
      source  = "hashicorp/random"
      version = ">= 3.6.0"
    }
  }
}

provider "azurerm" {
  environment = "usgovernment" # routes to Azure Government control plane

  features {
    key_vault {
      purge_soft_delete_on_destroy    = false # keep purge protection in gov
      recover_soft_deleted_key_vaults = true
    }
    resource_group {
      prevent_deletion_if_contains_resources = true
    }
  }

  # subscription_id / tenant_id supplied via env (ARM_SUBSCRIPTION_ID, ARM_TENANT_ID)
  # or OIDC federated credentials in CI.
}

provider "random" {}
