###############################################################################
# CITADEL — AWS Commercial Deployment
# secrets.tf — Generated secrets + AWS Secrets Manager (IA-5, SC-12, SC-28)
#
# Secret-class values are GENERATED here with random_password (never hardcoded)
# and stored in Secrets Manager encrypted with the CMK. They are injected into
# the ECS task as `secrets` (valueFrom), never as plaintext `environment`.
#
# Secrets provisioned:
#   - CITADEL_JWT_SECRET        (random)
#   - CITADEL_ADMIN_PASSWORD    (random — bootstrap admin)
#   - CITADEL_SUPERADMIN_TOKEN  (random)
#   - CITADEL_METRICS_TOKEN     (random)
#   - RDS master password       (random — feeds DATABASE_URL)
#   - DATABASE_URL              (assembled from RDS endpoint + password)
###############################################################################

###############################################################################
# Generated secret material
###############################################################################

resource "random_password" "jwt_secret" {
  length  = 64
  special = false # base62 keeps it shell/URL safe for a JWT signing key
}

resource "random_password" "admin_password" {
  length           = 24
  special          = true
  override_special = "!@#$%^&*()-_=+"
}

resource "random_password" "superadmin_token" {
  length  = 48
  special = false
}

resource "random_password" "metrics_token" {
  length  = 48
  special = false
}

# RDS master password. special characters are restricted to a set RDS accepts
# and that survives URL-encoding cleanly in DATABASE_URL.
resource "random_password" "db" {
  length           = 32
  special          = true
  override_special = "!#$%&*()-_=+[]{}"
}

###############################################################################
# Per-secret Secrets Manager entries (each CMK-encrypted)
###############################################################################

resource "aws_secretsmanager_secret" "jwt_secret" {
  name        = "${local.name}/jwt-secret"
  description = "CITADEL JWT signing secret (CITADEL_JWT_SECRET)."
  kms_key_id  = aws_kms_key.this.arn
  tags        = { Name = "${local.name}-jwt-secret" }
}

resource "aws_secretsmanager_secret_version" "jwt_secret" {
  secret_id     = aws_secretsmanager_secret.jwt_secret.id
  secret_string = random_password.jwt_secret.result
}

resource "aws_secretsmanager_secret" "admin_password" {
  name        = "${local.name}/admin-password"
  description = "CITADEL bootstrap admin password (CITADEL_ADMIN_PASSWORD). Rotate after first login."
  kms_key_id  = aws_kms_key.this.arn
  tags        = { Name = "${local.name}-admin-password" }
}

resource "aws_secretsmanager_secret_version" "admin_password" {
  secret_id     = aws_secretsmanager_secret.admin_password.id
  secret_string = random_password.admin_password.result
}

resource "aws_secretsmanager_secret" "superadmin_token" {
  name        = "${local.name}/superadmin-token"
  description = "CITADEL superadmin API token (CITADEL_SUPERADMIN_TOKEN)."
  kms_key_id  = aws_kms_key.this.arn
  tags        = { Name = "${local.name}-superadmin-token" }
}

resource "aws_secretsmanager_secret_version" "superadmin_token" {
  secret_id     = aws_secretsmanager_secret.superadmin_token.id
  secret_string = random_password.superadmin_token.result
}

resource "aws_secretsmanager_secret" "metrics_token" {
  name        = "${local.name}/metrics-token"
  description = "CITADEL metrics scrape token (CITADEL_METRICS_TOKEN)."
  kms_key_id  = aws_kms_key.this.arn
  tags        = { Name = "${local.name}-metrics-token" }
}

resource "aws_secretsmanager_secret_version" "metrics_token" {
  secret_id     = aws_secretsmanager_secret.metrics_token.id
  secret_string = random_password.metrics_token.result
}

# Assembled DATABASE_URL — depends on the RDS instance address (see rds.tf and
# the local.database_url definition in main.tf). The app reads DATABASE_URL +
# PGSSL=1; the URL carries sslmode=require to enforce TLS to Postgres (SC-8).
resource "aws_secretsmanager_secret" "database_url" {
  name        = "${local.name}/database-url"
  description = "CITADEL DATABASE_URL (postgresql://… sslmode=require) assembled from RDS endpoint + generated password."
  kms_key_id  = aws_kms_key.this.arn
  tags        = { Name = "${local.name}-database-url" }
}

resource "aws_secretsmanager_secret_version" "database_url" {
  secret_id     = aws_secretsmanager_secret.database_url.id
  secret_string = local.database_url
}
