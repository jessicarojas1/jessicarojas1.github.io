###############################################################################
# CITADEL — GCP Deployment
# iam.tf — Dedicated least-privilege runtime service account for Cloud Run, plus
#          the minimal role bindings it needs, and CMEK grants for Cloud SQL.
#
# Controls: AC-6 (least privilege), IA-2 (workload identity via service account).
# The runtime SA gets ONLY:
#   - roles/secretmanager.secretAccessor (scoped per-secret, not project-wide)
#   - roles/cloudsql.client              (connect to Cloud SQL)
###############################################################################

resource "google_service_account" "runtime" {
  project      = var.project_id
  account_id   = "${var.project}-${var.environment}-run"
  display_name = "CITADEL Cloud Run runtime (least privilege)"
  description  = "Identity for the CITADEL Cloud Run service: secret access + Cloud SQL client only."

  depends_on = [google_project_service.apis]
}

# ---------------------------------------------------------------------------
# Secret access — granted per secret (resource-scoped), NOT at the project level,
# so the SA can read exactly the five CITADEL secrets and nothing else (AC-6).
# ---------------------------------------------------------------------------
resource "google_secret_manager_secret_iam_member" "runtime_accessor" {
  for_each = google_secret_manager_secret.citadel

  project   = var.project_id
  secret_id = each.value.secret_id
  role      = "roles/secretmanager.secretAccessor"
  member    = "serviceAccount:${google_service_account.runtime.email}"
}

# ---------------------------------------------------------------------------
# Cloud SQL client — lets the runtime SA open authenticated connections.
# (Connectivity itself is over the private IP via the VPC connector.)
# ---------------------------------------------------------------------------
resource "google_project_iam_member" "runtime_cloudsql_client" {
  project = var.project_id
  role    = "roles/cloudsql.client"
  member  = "serviceAccount:${google_service_account.runtime.email}"
}

# ---------------------------------------------------------------------------
# CMEK grant (only when enable_cmek): the Cloud SQL service agent must be allowed
# to use the customer-managed key to encrypt/decrypt the instance (SC-12).
# ---------------------------------------------------------------------------
resource "google_kms_crypto_key_iam_member" "sql_cmek" {
  count = var.enable_cmek ? 1 : 0

  crypto_key_id = var.cmek_key_name
  role          = "roles/cloudkms.cryptoKeyEncrypterDecrypter"
  member        = "serviceAccount:service-${data.google_project.current.number}@gcp-sa-cloud-sql.iam.gserviceaccount.com"
}
