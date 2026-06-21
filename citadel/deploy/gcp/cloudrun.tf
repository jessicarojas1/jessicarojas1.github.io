###############################################################################
# CITADEL — GCP Deployment
# cloudrun.tf — Cloud Run v2 service running the citadel-server container.
#
# Highlights:
#   - 2nd-gen execution environment, container port 8080, dedicated runtime SA
#   - Secret env vars sourced from Secret Manager (value_source.secret_key_ref) —
#     never plaintext (IA-5, SC-28)
#   - Startup + liveness probes against GET /api/health (SI-4)
#   - Private egress to Cloud SQL via the Serverless VPC Access connector (SC-7)
#   - Ingress controlled by var.ingress (locked to the internal LB by default)
###############################################################################

resource "google_cloud_run_v2_service" "citadel" {
  project  = var.project_id
  name     = local.name
  location = var.region
  ingress  = var.ingress

  # Reconcile the URL/IAM before serving traffic; matches the app's own startup.
  deletion_protection = false

  labels = local.labels

  template {
    service_account                  = google_service_account.runtime.email
    execution_environment            = "EXECUTION_ENVIRONMENT_GEN2"
    max_instance_request_concurrency = var.max_request_concurrency

    scaling {
      min_instance_count = var.min_instances
      max_instance_count = var.max_instances
    }

    # Egress through the connector so Cloud Run can reach the Cloud SQL private IP.
    # PRIVATE_RANGES_ONLY keeps public-internet egress off the connector.
    vpc_access {
      connector = google_vpc_access_connector.connector.id
      egress    = "PRIVATE_RANGES_ONLY"
    }

    containers {
      image = local.image_url

      ports {
        container_port = var.container_port
      }

      resources {
        limits = {
          cpu    = var.cpu_limit
          memory = var.memory_limit
        }
        # CPU only allocated during request processing keeps cost down; flip to
        # true if you need always-on CPU for background timers.
        cpu_idle = true
      }

      # -------------------------------------------------------------------
      # Non-secret environment configuration.
      # -------------------------------------------------------------------
      env {
        name  = "NODE_ENV"
        value = "production"
      }
      env {
        name  = "PORT"
        value = tostring(var.container_port)
      }
      env {
        name  = "PGSSL"
        value = "1"
      }
      env {
        name  = "CITADEL_ADMIN_EMAIL"
        value = var.citadel_admin_email
      }
      env {
        name  = "CITADEL_MULTITENANT"
        value = var.citadel_multitenant ? "1" : "0"
      }
      env {
        name  = "CITADEL_BASE_DOMAIN"
        value = var.citadel_base_domain
      }

      # -------------------------------------------------------------------
      # Secret-class environment variables — pulled from Secret Manager at
      # container start. "latest" follows version rotation automatically.
      # -------------------------------------------------------------------
      env {
        name = "DATABASE_URL"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.citadel["database-url"].secret_id
            version = "latest"
          }
        }
      }
      env {
        name = "CITADEL_JWT_SECRET"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.citadel["jwt-secret"].secret_id
            version = "latest"
          }
        }
      }
      env {
        name = "CITADEL_ADMIN_PASSWORD"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.citadel["admin-password"].secret_id
            version = "latest"
          }
        }
      }
      env {
        name = "CITADEL_SUPERADMIN_TOKEN"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.citadel["superadmin-token"].secret_id
            version = "latest"
          }
        }
      }
      env {
        name = "CITADEL_METRICS_TOKEN"
        value_source {
          secret_key_ref {
            secret  = google_secret_manager_secret.citadel["metrics-token"].secret_id
            version = "latest"
          }
        }
      }

      # -------------------------------------------------------------------
      # Probes — startup gates traffic until the app is healthy; liveness
      # restarts a wedged instance. Both hit GET /api/health (SI-4).
      # -------------------------------------------------------------------
      startup_probe {
        http_get {
          path = "/api/health"
          port = var.container_port
        }
        initial_delay_seconds = 5
        period_seconds        = 10
        timeout_seconds       = 3
        failure_threshold     = 6 # ~60s grace for cold start + DB connect
      }

      liveness_probe {
        http_get {
          path = "/api/health"
          port = var.container_port
        }
        initial_delay_seconds = 15
        period_seconds        = 30
        timeout_seconds       = 3
        failure_threshold     = 3
      }
    }
  }

  depends_on = [
    google_project_service.apis,
    google_secret_manager_secret_iam_member.runtime_accessor,
    google_project_iam_member.runtime_cloudsql_client,
    google_sql_user.citadel,
  ]
}

###############################################################################
# Invoker IAM — by default the app is a public web service (it runs its own JWT
# auth), so allUsers may invoke. Set var.allow_unauthenticated = false to require
# IAM-authenticated callers (e.g. only the LB / specific principals) instead.
###############################################################################

resource "google_cloud_run_v2_service_iam_member" "invoker" {
  count = var.allow_unauthenticated ? 1 : 0

  project  = var.project_id
  location = var.region
  name     = google_cloud_run_v2_service.citadel.name
  role     = "roles/run.invoker"
  member   = "allUsers"
}
