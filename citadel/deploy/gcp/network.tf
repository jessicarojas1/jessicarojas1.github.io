###############################################################################
# CITADEL — GCP Deployment
# network.tf — VPC, Private Services Access (for Cloud SQL private IP),
#              and the Serverless VPC Access connector for Cloud Run egress.
#
# Data flow: Cloud Run -> VPC connector -> VPC -> peered Service Networking range
#            -> Cloud SQL private IP. No public IP is exposed on the database (SC-7).
###############################################################################

resource "google_compute_network" "vpc" {
  project                 = var.project_id
  name                    = var.network_name
  auto_create_subnetworks = false
  description             = "CITADEL private VPC: Cloud Run egress + Cloud SQL private IP"

  depends_on = [google_project_service.apis]
}

# Primary regional subnet. Cloud Run does not attach here directly (it uses the
# connector), but a real subnet keeps the network well-formed and ready for any
# future VM / GKE workloads. Private Google Access lets resources reach Google
# APIs without external IPs (SC-7).
resource "google_compute_subnetwork" "primary" {
  project                  = var.project_id
  name                     = "${local.name}-subnet"
  region                   = var.region
  network                  = google_compute_network.vpc.id
  ip_cidr_range            = var.subnet_cidr
  private_ip_google_access = true
}

###############################################################################
# Private Services Access — reserved range + VPC peering with Google so Cloud SQL
# can receive a private IP inside our VPC (CA-3, SC-7).
###############################################################################

resource "google_compute_global_address" "private_services" {
  project       = var.project_id
  name          = "${local.name}-psa-range"
  purpose       = "VPC_PEERING"
  address_type  = "INTERNAL"
  prefix_length = 16
  network       = google_compute_network.vpc.id
}

resource "google_service_networking_connection" "private_services" {
  network                 = google_compute_network.vpc.id
  service                 = "servicenetworking.googleapis.com"
  reserved_peering_ranges = [google_compute_global_address.private_services.name]

  depends_on = [google_project_service.apis]
}

###############################################################################
# Serverless VPC Access connector — bridges Cloud Run (serverless) into the VPC
# so it can reach the Cloud SQL private IP. Requires a dedicated /28 (SC-7).
###############################################################################

resource "google_vpc_access_connector" "connector" {
  project       = var.project_id
  name          = "${var.project}-${var.environment}-conn"
  region        = var.region
  ip_cidr_range = var.connector_cidr
  network       = google_compute_network.vpc.name
  min_instances = var.connector_min_instances
  max_instances = var.connector_max_instances
  machine_type  = var.connector_machine_type

  depends_on = [google_project_service.apis]
}

###############################################################################
# Firewall — deny ingress by default is GCP's implied behaviour; we add an
# explicit egress allow for the connector to reach the Cloud SQL private range
# on the Postgres port only (least privilege, SC-7).
###############################################################################

resource "google_compute_firewall" "connector_to_sql" {
  project     = var.project_id
  name        = "${local.name}-conn-to-sql"
  network     = google_compute_network.vpc.name
  description = "Allow the Serverless VPC Access connector to reach Cloud SQL (5432) over the peered private range."
  direction   = "EGRESS"
  priority    = 1000

  destination_ranges = ["${google_compute_global_address.private_services.address}/${google_compute_global_address.private_services.prefix_length}"]

  allow {
    protocol = "tcp"
    ports    = ["5432"]
  }
}
