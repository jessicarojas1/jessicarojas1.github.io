# =============================================================================
# Remote state backend — S3 + DynamoDB lock + SSE-KMS (partial configuration)
# -----------------------------------------------------------------------------
# Terraform state for the audit sink is *critical state*: it records the ARNs
# and configuration of a tamper-evident, write-once archive. It MUST live in a
# remote, versioned, encrypted, lockable backend — never local state.
#
# A backend block cannot interpolate variables, so the account/region-specific
# values are supplied as a PARTIAL configuration at init time:
#
#     terraform init -backend-config=backend.hcl
#
# where backend.hcl is your copy of backend.hcl.example. Create the backend
# resources first with the bootstrap module (../bootstrap) — it provisions the
# state S3 bucket (versioned, SSE-KMS, TLS-only, public-access-blocked), the
# DynamoDB lock table, and the state CMK, and prints the exact backend.hcl.
#
# For `terraform validate` / CI without a backend, use:  terraform init -backend=false
# =============================================================================
terraform {
  backend "s3" {
    # Stable, non-secret defaults kept in-repo:
    key     = "platform/audit-sink/terraform.tfstate"
    encrypt = true # belt-and-braces alongside the bucket's SSE-KMS default

    # Supplied via -backend-config=backend.hcl (see backend.hcl.example):
    #   bucket         = "<state bucket, e.g. platform-tfstate-<account_id>>"
    #   region         = "<state bucket region>"
    #   dynamodb_table = "<lock table, e.g. platform-tfstate-locks>"
    #   kms_key_id     = "<state CMK ARN>"
  }
}
