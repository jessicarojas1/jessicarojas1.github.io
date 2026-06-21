###############################################################################
# CITADEL — AWS Commercial Deployment
# rds.tf — RDS PostgreSQL for durable, multi-instance/multi-tenant state
#
# Private (no public access), encrypted at rest with the CMK (SC-28), in the
# private subnets, reachable only from the Fargate SG (SC-7). A custom parameter
# group forces SSL (rds.force_ssl=1) so all client connections use TLS (SC-8),
# matching the app's PGSSL=1 and the DATABASE_URL sslmode=require.
###############################################################################

resource "aws_db_subnet_group" "this" {
  name       = "${local.name}-db-subnets"
  subnet_ids = local.create_vpc ? aws_subnet.private[*].id : []
  tags       = { Name = "${local.name}-db-subnets" }
}

# Parameter group: enforce TLS for every connection (rds.force_ssl) and log
# connections/disconnections for audit (AU-2). family must match the engine.
resource "aws_db_parameter_group" "this" {
  name        = "${local.name}-pg16"
  family      = "postgres16"
  description = "CITADEL PostgreSQL params — force SSL + connection logging"

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

  tags = { Name = "${local.name}-pg16" }
}

resource "aws_db_instance" "this" {
  identifier     = "${local.name}-pg"
  engine         = "postgres"
  engine_version = var.db_engine_version
  instance_class = var.db_instance_class

  # Storage: gp3, KMS-encrypted at rest, with autoscaling headroom.
  allocated_storage     = var.db_allocated_storage
  max_allocated_storage = var.db_max_allocated_storage
  storage_type          = "gp3"
  storage_encrypted     = true
  kms_key_id            = aws_kms_key.this.arn

  db_name  = var.db_name
  username = var.db_username
  password = random_password.db.result
  port     = 5432

  # Networking: private only, attached to the RDS SG.
  db_subnet_group_name   = aws_db_subnet_group.this.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  publicly_accessible    = false
  multi_az               = var.db_multi_az

  parameter_group_name = aws_db_parameter_group.this.name

  # Backups / maintenance (CP-9). Final snapshot on destroy for safety.
  backup_retention_period   = var.db_backup_retention_days
  copy_tags_to_snapshot     = true
  deletion_protection       = var.db_deletion_protection
  skip_final_snapshot       = false
  final_snapshot_identifier = "${local.name}-pg-final"
  apply_immediately         = true

  # Audit/observability: ship Postgres + upgrade logs to CloudWatch (AU-2).
  enabled_cloudwatch_logs_exports = ["postgresql", "upgrade"]

  # Encrypt performance insights with the CMK as well.
  performance_insights_enabled    = true
  performance_insights_kms_key_id = aws_kms_key.this.arn
  auto_minor_version_upgrade      = true

  tags = { Name = "${local.name}-pg" }
}
