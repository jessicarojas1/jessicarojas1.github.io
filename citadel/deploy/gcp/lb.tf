###############################################################################
# CITADEL — GCP Deployment
# lb.tf — Optional global external HTTPS Application Load Balancer fronting Cloud
#         Run, with a Google-managed SSL cert and a Cloud Armor security policy.
#
# Gated by var.enable_external_lb (default true). When enabled, set
# var.ingress = INGRESS_TRAFFIC_INTERNAL_LOAD_BALANCER (the default) so the bare
# run.app URL is not directly reachable and all traffic is filtered by Cloud Armor.
#
# Controls: SC-7 (boundary protection), SC-8 (TLS in transit), SC-5 (DoS rate
#           limiting), SI-4 (WAF preconfigured rules).
###############################################################################

locals {
  lb_enabled = var.enable_external_lb
}

# Fail fast if the LB is on but no domain was supplied for the managed
# certificate (managed certs require at least one domain). A `check` block
# surfaces this as a clear error/warning without blocking unrelated planning.
check "lb_domains_present" {
  assert {
    condition     = !local.lb_enabled || length(var.lb_domains) > 0
    error_message = "enable_external_lb = true requires at least one entry in lb_domains for the managed SSL certificate."
  }
}

# ---------------------------------------------------------------------------
# Serverless Network Endpoint Group (NEG) pointing at the Cloud Run service.
# ---------------------------------------------------------------------------
resource "google_compute_region_network_endpoint_group" "cloudrun" {
  count = local.lb_enabled ? 1 : 0

  project               = var.project_id
  name                  = "${local.name}-neg"
  region                = var.region
  network_endpoint_type = "SERVERLESS"

  cloud_run {
    service = google_cloud_run_v2_service.citadel.name
  }
}

# ---------------------------------------------------------------------------
# Cloud Armor — IP allowlist + per-IP rate limiting + preconfigured OWASP rules.
# ---------------------------------------------------------------------------
resource "google_compute_security_policy" "armor" {
  count = local.lb_enabled ? 1 : 0

  project     = var.project_id
  name        = "${local.name}-armor"
  description = "CITADEL edge protection: allowlist, rate limit, OWASP preconfigured rules."

  # Rule 1000: rate-limit each source IP (SC-5 DoS protection).
  rule {
    action   = "throttle"
    priority = 1000
    match {
      versioned_expr = "SRC_IPS_V1"
      config {
        src_ip_ranges = ["*"]
      }
    }
    rate_limit_options {
      conform_action = "allow"
      exceed_action  = "deny(429)"
      enforce_on_key = "IP"
      rate_limit_threshold {
        count        = var.armor_rate_limit_rpm
        interval_sec = 60
      }
    }
    description = "Per-IP request rate limit"
  }

  # Rule 1100: block well-known SQLi patterns at the edge (SI-4).
  rule {
    action   = "deny(403)"
    priority = 1100
    match {
      expr {
        expression = "evaluatePreconfiguredExpr('sqli-v33-stable')"
      }
    }
    description = "OWASP SQL injection preconfigured ruleset"
  }

  # Rule 1200: block well-known XSS patterns at the edge (SI-4).
  rule {
    action   = "deny(403)"
    priority = 1200
    match {
      expr {
        expression = "evaluatePreconfiguredExpr('xss-v33-stable')"
      }
    }
    description = "OWASP cross-site-scripting preconfigured ruleset"
  }

  # Rule 2000: allow only approved source CIDRs (SC-7). When the default
  # 0.0.0.0/0 is used this is effectively allow-all and the default rule denies
  # nothing extra; tighten armor_allowed_cidrs for production.
  rule {
    action   = "allow"
    priority = 2000
    match {
      versioned_expr = "SRC_IPS_V1"
      config {
        src_ip_ranges = var.armor_allowed_cidrs
      }
    }
    description = "Allow approved source CIDRs"
  }

  # Default rule (lowest priority): deny anything not explicitly allowed above.
  rule {
    action   = "deny(403)"
    priority = 2147483647
    match {
      versioned_expr = "SRC_IPS_V1"
      config {
        src_ip_ranges = ["*"]
      }
    }
    description = "Default deny"
  }
}

# ---------------------------------------------------------------------------
# Backend service -> NEG, with Cloud Armor attached.
# ---------------------------------------------------------------------------
resource "google_compute_backend_service" "cloudrun" {
  count = local.lb_enabled ? 1 : 0

  project               = var.project_id
  name                  = "${local.name}-backend"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  protocol              = "HTTPS"
  security_policy       = google_compute_security_policy.armor[0].id

  backend {
    group = google_compute_region_network_endpoint_group.cloudrun[0].id
  }

  log_config {
    enable      = true
    sample_rate = 1.0
  }
}

# ---------------------------------------------------------------------------
# URL map / proxy / forwarding rule with a global static IP.
# ---------------------------------------------------------------------------
resource "google_compute_url_map" "citadel" {
  count = local.lb_enabled ? 1 : 0

  project         = var.project_id
  name            = "${local.name}-urlmap"
  default_service = google_compute_backend_service.cloudrun[0].id
}

resource "google_compute_managed_ssl_certificate" "citadel" {
  count = local.lb_enabled ? 1 : 0

  project = var.project_id
  name    = "${local.name}-cert"

  managed {
    domains = var.lb_domains
  }
}

resource "google_compute_target_https_proxy" "citadel" {
  count = local.lb_enabled ? 1 : 0

  project          = var.project_id
  name             = "${local.name}-https-proxy"
  url_map          = google_compute_url_map.citadel[0].id
  ssl_certificates = [google_compute_managed_ssl_certificate.citadel[0].id]
  # Modern TLS only (SC-8/SC-13).
  ssl_policy = google_compute_ssl_policy.modern[0].id
}

resource "google_compute_ssl_policy" "modern" {
  count = local.lb_enabled ? 1 : 0

  project         = var.project_id
  name            = "${local.name}-ssl-policy"
  profile         = "MODERN"
  min_tls_version = "TLS_1_2"
}

resource "google_compute_global_address" "lb_ip" {
  count = local.lb_enabled ? 1 : 0

  project = var.project_id
  name    = "${local.name}-lb-ip"
}

resource "google_compute_global_forwarding_rule" "https" {
  count = local.lb_enabled ? 1 : 0

  project               = var.project_id
  name                  = "${local.name}-https-fr"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  port_range            = "443"
  target                = google_compute_target_https_proxy.citadel[0].id
  ip_address            = google_compute_global_address.lb_ip[0].id
}

# ---------------------------------------------------------------------------
# HTTP :80 -> HTTPS :443 redirect (no plaintext app traffic; SC-8).
# ---------------------------------------------------------------------------
resource "google_compute_url_map" "http_redirect" {
  count = local.lb_enabled ? 1 : 0

  project = var.project_id
  name    = "${local.name}-http-redirect"

  default_url_redirect {
    https_redirect         = true
    redirect_response_code = "MOVED_PERMANENTLY_DEFAULT"
    strip_query            = false
  }
}

resource "google_compute_target_http_proxy" "redirect" {
  count = local.lb_enabled ? 1 : 0

  project = var.project_id
  name    = "${local.name}-http-proxy"
  url_map = google_compute_url_map.http_redirect[0].id
}

resource "google_compute_global_forwarding_rule" "http" {
  count = local.lb_enabled ? 1 : 0

  project               = var.project_id
  name                  = "${local.name}-http-fr"
  load_balancing_scheme = "EXTERNAL_MANAGED"
  port_range            = "80"
  target                = google_compute_target_http_proxy.redirect[0].id
  ip_address            = google_compute_global_address.lb_ip[0].id
}
