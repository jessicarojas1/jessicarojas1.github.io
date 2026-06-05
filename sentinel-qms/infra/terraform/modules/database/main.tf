# =============================================================================
# Database module — managed PostgreSQL 16, private, encrypted, backed up.
#   AWS:   RDS PostgreSQL (Multi-AZ, SSE-KMS, no public access)
#   Azure: Database for PostgreSQL Flexible Server (VNet-injected, CMK option)
# =============================================================================

locals {
  is_aws   = var.cloud == "aws"
  is_azure = var.cloud == "azure"
}

# -----------------------------------------------------------------------------
# AWS RDS
# -----------------------------------------------------------------------------
resource "aws_db_subnet_group" "this" {
  count      = local.is_aws ? 1 : 0
  name       = "${var.name_prefix}-db-subnets"
  subnet_ids = var.data_subnet_ids
  tags       = merge(var.tags, { Name = "${var.name_prefix}-db-subnets" })
}

resource "aws_db_parameter_group" "this" {
  count  = local.is_aws ? 1 : 0
  name   = "${var.name_prefix}-pg16"
  family = "postgres16"

  # Force TLS for all connections (encryption in transit).
  parameter {
    name  = "rds.force_ssl"
    value = "1"
  }

  parameter {
    name  = "log_connections"
    value = "1"
  }

  parameter {
    name  = "log_disconnections"
    value = "1"
  }

  tags = var.tags
}

resource "aws_db_instance" "this" {
  count      = local.is_aws ? 1 : 0
  identifier = "${var.name_prefix}-pg"

  engine         = "postgres"
  engine_version = var.engine_version
  instance_class = var.instance_class

  db_name  = var.db_name
  username = var.db_admin_username
  password = var.db_admin_password
  port     = 5432

  allocated_storage     = var.allocated_storage_gb
  max_allocated_storage = var.max_allocated_storage_gb
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = var.kms_key_arn

  db_subnet_group_name   = aws_db_subnet_group.this[0].name
  vpc_security_group_ids = var.vpc_security_group_ids
  parameter_group_name   = aws_db_parameter_group.this[0].name
  publicly_accessible    = false
  multi_az               = var.multi_az

  backup_retention_period   = var.backup_retention_days
  backup_window             = "07:00-08:00"
  maintenance_window        = "Sun:08:30-Sun:09:30"
  copy_tags_to_snapshot     = true
  deletion_protection       = true
  skip_final_snapshot       = false
  final_snapshot_identifier = "${var.name_prefix}-pg-final"
  apply_immediately         = false

  performance_insights_enabled          = true
  performance_insights_kms_key_id       = var.kms_key_arn
  performance_insights_retention_period = 31

  enabled_cloudwatch_logs_exports = ["postgresql", "upgrade"]
  monitoring_interval             = 60
  monitoring_role_arn             = var.monitoring_role_arn

  auto_minor_version_upgrade  = true
  iam_database_authentication = true

  tags = merge(var.tags, { Name = "${var.name_prefix}-pg" })
}

# -----------------------------------------------------------------------------
# Azure Database for PostgreSQL Flexible Server
# -----------------------------------------------------------------------------
resource "azurerm_postgresql_flexible_server" "this" {
  count               = local.is_azure ? 1 : 0
  name                = "${var.name_prefix}-pg"
  resource_group_name = var.azure_resource_group_name
  location            = var.azure_location
  version             = var.engine_version

  administrator_login    = var.db_admin_username
  administrator_password = var.db_admin_password

  sku_name   = var.azure_sku_name
  storage_mb = var.allocated_storage_gb * 1024

  # VNet-injected (private access only — no public endpoint).
  delegated_subnet_id           = var.azure_delegated_subnet_id
  private_dns_zone_id           = var.azure_private_dns_zone_id
  public_network_access_enabled = false

  backup_retention_days        = var.backup_retention_days
  geo_redundant_backup_enabled = true
  zone                         = "1"

  high_availability {
    mode                      = var.multi_az ? "ZoneRedundant" : "SameZone"
    standby_availability_zone = var.multi_az ? "2" : null
  }

  dynamic "identity" {
    for_each = var.azure_cmk_identity_id != null ? [1] : []
    content {
      type         = "UserAssigned"
      identity_ids = [var.azure_cmk_identity_id]
    }
  }

  dynamic "customer_managed_key" {
    for_each = var.azure_customer_managed_key_id != null ? [1] : []
    content {
      key_vault_key_id                  = var.azure_customer_managed_key_id
      primary_user_assigned_identity_id = var.azure_cmk_identity_id
    }
  }

  tags = var.tags

  lifecycle {
    ignore_changes = [zone, high_availability[0].standby_availability_zone]
  }
}

resource "azurerm_postgresql_flexible_server_database" "this" {
  count     = local.is_azure ? 1 : 0
  name      = var.db_name
  server_id = azurerm_postgresql_flexible_server.this[0].id
  collation = "en_US.utf8"
  charset   = "UTF8"
}

# Require TLS connections (encryption in transit).
resource "azurerm_postgresql_flexible_server_configuration" "require_ssl" {
  count     = local.is_azure ? 1 : 0
  name      = "require_secure_transport"
  server_id = azurerm_postgresql_flexible_server.this[0].id
  value     = "ON"
}
