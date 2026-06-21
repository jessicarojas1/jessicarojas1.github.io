###############################################################################
# CITADEL — GCP Deployment
# outputs.tf
#
# NOTE: secret-class values (DATABASE_URL, JWT secret, admin password, tokens) are
# deliberately NOT output. Read them from Secret Manager when you need them.
###############################################################################

output "project_id" {
  description = "GCP project the stack is deployed into."
  value       = var.project_id
}

output "region" {
  description = "Primary deployment region."
  value       = var.region
}

output "cloud_run_service_name" {
  description = "Cloud Run v2 service name."
  value       = google_cloud_run_v2_service.citadel.name
}

output "cloud_run_url" {
  description = "Cloud Run-assigned HTTPS URL (run.app). Direct reachability depends on var.ingress; with the internal LB ingress this is not publicly reachable."
  value       = google_cloud_run_v2_service.citadel.uri
}

output "runtime_service_account" {
  description = "Email of the least-privilege runtime service account used by Cloud Run."
  value       = google_service_account.runtime.email
}

output "artifact_registry_repo" {
  description = "Artifact Registry Docker repository path for docker push."
  value       = local.image_repo
}

output "image_url" {
  description = "Fully-qualified image reference deployed to Cloud Run."
  value       = local.image_url
}

output "cloudsql_instance_name" {
  description = "Cloud SQL instance name."
  value       = google_sql_database_instance.citadel.name
}

output "cloudsql_connection_name" {
  description = "Cloud SQL connection name (project:region:instance) for the Cloud SQL connector / proxy."
  value       = google_sql_database_instance.citadel.connection_name
}

output "cloudsql_private_ip" {
  description = "Private IP of the Cloud SQL instance (reachable only inside the VPC)."
  value       = google_sql_database_instance.citadel.private_ip_address
}

output "vpc_connector_id" {
  description = "Serverless VPC Access connector used by Cloud Run for private egress."
  value       = google_vpc_access_connector.connector.id
}

output "secret_ids" {
  description = "Secret Manager secret IDs created for CITADEL runtime configuration."
  value       = { for k, s in google_secret_manager_secret.citadel : k => s.secret_id }
}

output "load_balancer_ip" {
  description = "Global static IP of the external HTTPS load balancer (point your DNS A records here). Empty when enable_external_lb = false."
  value       = var.enable_external_lb ? google_compute_global_address.lb_ip[0].address : ""
}

output "load_balancer_domains" {
  description = "Domains on the managed SSL certificate. Provision/verify DNS before the cert turns ACTIVE."
  value       = var.enable_external_lb ? var.lb_domains : []
}

output "service_url" {
  description = "Primary URL to reach CITADEL: the first LB domain when the external LB is enabled, otherwise the Cloud Run URL."
  value = (var.enable_external_lb && length(var.lb_domains) > 0
    ? "https://${var.lb_domains[0]}"
  : google_cloud_run_v2_service.citadel.uri)
}
