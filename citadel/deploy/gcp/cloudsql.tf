###############################################################################
# CITADEL — GCP Deployment
# cloudsql.tf — Cloud SQL for PostgreSQL: private IP, automated backups + PITR,
#               require SSL, optional CMEK, generated app-user password.
#
# Controls: SC-7 (private IP / no public surface), SC-8 (require SSL in transit),
#           SC-12/SC-28 (encryption at rest; CMEK toggle), CP-9/CP-10 (backups + HA).
###############################################################################

# Strong random password for the application database user. Stored only in
# Secret Manager (see secrets.tf) and assembled into DATABASE_URL — never logged.
resource "random_password" "db_user" {
  length  = 32
  special = true
  # Exclude characters that are awkward inside a URL userinfo component.
  override_special = "-_.~"
}

# Random suffix keeps the instance name unique: Cloud SQL reserves a deleted
# instance name for ~7 days, which otherwise blocks re-creation during testing.
resource "random_id" "db_suffix" {
  byte_length = 3
}

resource "google_sql_database_instance" "citadel" {
  project          = var.project_id
  name             = "${local.name}-pg-${random_id.db_suffix.hex}"
  region           = var.region
  database_version = var.db_version

  deletion_protection = var.db_deletion_protection

  # CMEK toggle (SC-12/SC-28). Null => Google-managed default encryption key.
  encryption_key_name = var.enable_cmek ? var.cmek_key_name : null

  settings {
    tier              = var.db_tier
    availability_type = var.db_availability_type # REGIONAL = synchronous HA standby
    disk_type         = "PD_SSD"
    disk_size         = var.db_disk_size_gb
    disk_autoresize   = true

    # Network: private IP only — no public IPv4, no authorized networks (SC-7).
    ip_configuration {
      ipv4_enabled    = false
      private_network = google_compute_network.vpc.id
      ssl_mode        = "ENCRYPTED_ONLY" # reject non-TLS connections (SC-8)
    }

    # Automated daily backups + point-in-time recovery via WAL retention (CP-9).
    backup_configuration {
      enabled                        = true
      point_in_time_recovery_enabled = true
      start_time                     = "03:00"
      transaction_log_retention_days = 7
      backup_retention_settings {
        retained_backups = var.db_backup_retention_days
        retention_unit   = "COUNT"
      }
    }

    # Operational hygiene + audit: log connections/disconnections (AU-2, AU-12).
    database_flags {
      name  = "log_connections"
      value = "on"
    }
    database_flags {
      name  = "log_disconnections"
      value = "on"
    }

    maintenance_window {
      day          = 7 # Sunday
      hour         = 4
      update_track = "stable"
    }

    user_labels = local.labels
  }

  # Cloud SQL needs the private services peering live before it can claim a
  # private IP, and the CMEK grant (iam.tf) in place before it can use the key.
  depends_on = [
    google_service_networking_connection.private_services,
    google_project_service.apis,
  ]
}

resource "google_sql_database" "citadel" {
  project   = var.project_id
  name      = var.db_name
  instance  = google_sql_database_instance.citadel.name
  charset   = "UTF8"
  collation = "en_US.UTF8"
}

resource "google_sql_user" "citadel" {
  project  = var.project_id
  name     = var.db_user
  instance = google_sql_database_instance.citadel.name
  password = random_password.db_user.result
}

locals {
  # Assembled libpq/Node-style connection string. Cloud Run reaches the instance
  # over the private IP via the VPC connector; PGSSL=1 + sslmode=require enforce TLS.
  # This value is written to Secret Manager (secrets.tf), NOT exposed as an output.
  database_url = format(
    "postgresql://%s:%s@%s:5432/%s?sslmode=require",
    var.db_user,
    urlencode(random_password.db_user.result),
    google_sql_database_instance.citadel.private_ip_address,
    var.db_name,
  )
}
