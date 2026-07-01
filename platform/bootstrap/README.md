# `bootstrap` — Terraform remote-state backend

Provisions the **remote state backend** that `audit-sink/` (and any other platform
Terraform) uses:

- **S3 state bucket** — `<name_prefix>-tfstate-<account_id>`, versioned (RPO 0),
  SSE-KMS with a rotated CMK, TLS-only, KMS-only uploads, all public access blocked,
  `BucketOwnerEnforced`, lifecycle to expire old versions after 90 days.
- **DynamoDB lock table** — `<name_prefix>-tfstate-locks` (hash key `LockID`),
  `PAY_PER_REQUEST`, point-in-time recovery, SSE with the state CMK.
- **State CMK** — dedicated KMS key with rotation.

## Chicken-and-egg

This module *creates* the backend, so it runs with **local state** itself (there is
no `backend` block here). Run it once, then wire every other module to the backend
it produced.

```bash
cd platform/bootstrap
terraform init            # local state
terraform apply           # creates bucket + table + CMK

# Emit the partial backend config for the audit-sink module:
terraform output -raw backend_hcl > ../audit-sink/backend.hcl

cd ../audit-sink
terraform init -backend-config=backend.hcl   # migrate to the remote backend
```

`backend.hcl` is git-ignored (it names your state bucket/table/CMK). `key` and
`encrypt` are already set in `audit-sink/backend.tf`.

## Where the bootstrap state lives

The bootstrap's own state is small and low-churn. Options, in order of preference:

1. Keep it local and **commit it to a private, encrypted store** (not this public
   repo) or a separate secured bucket you create by hand.
2. After apply, add a `backend "s3"` block pointing at the bucket this module just
   created (a different `key`, e.g. `platform/bootstrap/terraform.tfstate`) and
   `terraform init` to migrate it in.

## Inputs

| Name | Default | Description |
|---|---|---|
| `name_prefix` | `platform` | Prefix for bucket/table/key names. |
| `kms_deletion_window_days` | `30` | State CMK deletion window. |
| `state_noncurrent_version_expiration_days` | `90` | Rollback window for old state versions. |

## Outputs

`state_bucket`, `lock_table`, `state_kms_key_arn`, and `backend_hcl` (the
ready-to-paste partial backend configuration).
