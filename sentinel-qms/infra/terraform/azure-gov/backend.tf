# =============================================================================
# Remote state — Azure Storage (Government). Bootstrap the storage account +
# container once with scripts/bootstrap-remote-state.sh, then `terraform init`.
# Supply storage_account_name / container_name / resource_group_name via
# -backend-config at init time, or uncomment below.
# =============================================================================
terraform {
  backend "azurerm" {
    environment = "usgovernment"
    # resource_group_name  = "sentinel-qms-tfstate-rg"
    # storage_account_name = "sentinelqmstfstate"
    container_name = "tfstate"
    key            = "azure-gov/sentinel-qms.tfstate"
    use_azuread_auth = true
  }
}
