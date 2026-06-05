terraform {
  required_version = ">= 1.6.0"
  required_providers {
    aws = {
      source                = "hashicorp/aws"
      version               = ">= 5.40.0, < 6.0.0"
      configuration_aliases = []
    }
    azurerm = {
      source  = "hashicorp/azurerm"
      version = ">= 3.100.0, < 4.0.0"
    }
  }
}
