###############################################################################
# CITADEL — GCP Deployment
# secrets.tf — Secret Manager secrets + first versions for every secret-class
#              value, plus generators for the ones we can mint ourselves.
#
# Controls: IA-5 (authenticator management), SC-12 (key establishment),
#           SC-28 (protection of information at rest — Secret Manager is encrypted).
#
# Secret-class values referenced by Cloud Run as secretKeyRef env vars (NOT
# plaintext). Real rotation should bump versions out-of-band; we seed v1 here.
###############################################################################

# ---------------------------------------------------------------------------
# Generated secret material (never echoed; lives in state — protect your backend)
# ---------------------------------------------------------------------------
resource "random_password" "jwt_secret" {
  length  = 64
  special = false # base62 keeps it shell/header safe
}

resource "random_password" "admin_password" {
  length           = 24
  special          = true
  override_special = "!@#%^*-_=+"
  min_lower        = 2
  min_upper        = 2
  min_numeric      = 2
  min_special      = 2
}

resource "random_password" "superadmin_token" {
  length  = 48
  special = false
}

resource "random_password" "metrics_token" {
  length  = 48
  special = false
}

# ---------------------------------------------------------------------------
# Helper: the standard secret container config we reuse for each secret.
# Automatic replication keeps a Google-managed multi-region copy (CP-9).
# ---------------------------------------------------------------------------
locals {
  secrets = {
    jwt-secret       = random_password.jwt_secret.result
    admin-password   = random_password.admin_password.result
    superadmin-token = random_password.superadmin_token.result
    metrics-token    = random_password.metrics_token.result
    database-url     = local.database_url
  }
}

resource "google_secret_manager_secret" "citadel" {
  for_each = local.secrets

  project   = var.project_id
  secret_id = "${local.name}-${each.key}"
  labels    = local.labels

  replication {
    auto {}
  }

  depends_on = [google_project_service.apis]
}

resource "google_secret_manager_secret_version" "citadel" {
  for_each = local.secrets

  secret      = google_secret_manager_secret.citadel[each.key].id
  secret_data = each.value
}
