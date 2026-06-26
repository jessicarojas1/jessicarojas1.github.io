# `audit-sink` — Central Immutable Audit Log Sink (AWS)

A reusable Terraform module that provisions a **tamper-evident, write-once central audit log sink** for the platform. It is the infrastructure half of the Enterprise Security Review's §25 finding ("per-app audit exists, but no centralized, tamper-evident, time-synced aggregation"). The companion design doc is [`../../CENTRAL_AUDIT.md`](../../CENTRAL_AUDIT.md).

## What it builds

```
  per-app audit log (aegis / paladin / sentinel-qms)
        │  CreateLogStream + PutLogEvents (append-only IAM)
        ▼
  CloudWatch Logs group   /audit/<prefix>/<app>   (SSE-KMS, retained)   ← hot/queryable tier
        │  subscription filter (forward all)
        ▼
  Kinesis Firehose        (SSE-KMS, GZIP, dated prefixes)
        │
        ▼
  S3 bucket               Object Lock COMPLIANCE + versioning + SSE-KMS  ← immutable archive
                          + bucket policy DENYING all deletes / lock-weakening
```

| Resource | Hardening |
|---|---|
| **S3 archive** | Object Lock **COMPLIANCE** (write-once, not removable even by root), versioning, SSE-KMS (CMK), public access blocked, `BucketOwnerEnforced`, `force_destroy=false`, explicit `Deny` on every `Delete*` / lock-weakening action for all principals, TLS-only + KMS-only-upload conditions. |
| **CloudWatch Logs** | One group per source app under `/audit/<prefix>/`, KMS-encrypted, retention configurable. |
| **Firehose** | Customer-managed CMK, error-output prefix + dedicated error log group, dated S3 prefixes, GZIP. |
| **KMS CMK** | Created if not supplied; rotation enabled; CloudWatch Logs grant scoped via `EncryptionContext` (avoids the AU-9 finding of a `Resource=*` KMS grant). |
| **IAM** | Least privilege: writers get **only** `CreateLogStream`+`PutLogEvents` on the audit groups (never S3); Firehose role scoped to the bucket+CMK; a separate MFA-gated **legal-hold role** (separation of duties). |

## Compliance mapping

- **NIST 800-53 AU-9** — Protection of Audit Information (immutability, least-privilege access).
- **NIST 800-171 3.3.x** — centralized, protected audit records.
- **CMMC 2.0 AU.L2-3.3.1/.2** — audit creation, protection, retention.
- **HIPAA §164.312(b)** — audit controls / write-once record-keeping.
- **NIST AU-11** — retention (default 3 years; tune `object_lock_retention_days`).

## Usage

```hcl
module "audit_sink" {
  source = "../../platform/audit-sink"

  name_prefix     = "platform-prod"
  log_group_names = ["aegis", "paladin", "sentinel-qms"]

  object_lock_mode           = "COMPLIANCE"
  object_lock_retention_days = 1095 # 3 years

  # Append-only writers: the task/instance roles of each source app.
  writer_principal_arns = [
    aws_iam_role.aegis_task.arn,
    aws_iam_role.sentinel_backend_task.arn,
  ]

  tags = {
    DataClassification = "CUI"
    Owner              = "platform-security"
  }
}
```

Each source app then ships its existing per-app audit log to the matching CloudWatch group (`module.audit_sink.log_group_names["aegis"]`, etc.) — see `CENTRAL_AUDIT.md` for the per-app shipping path. Attach `module.audit_sink.writer_policy_arn` to each app's runtime role.

## Inputs (selected)

| Name | Default | Description |
|---|---|---|
| `name_prefix` | — | Prefix for all resource names. |
| `log_group_names` | `["aegis","paladin","sentinel-qms"]` | One CloudWatch group per source app. |
| `object_lock_mode` | `COMPLIANCE` | `COMPLIANCE` (write-once, recommended) or `GOVERNANCE` (non-prod). |
| `object_lock_retention_days` | `1095` | Default WORM retention per object. |
| `log_retention_days` | `400` | CloudWatch hot-tier retention. |
| `kms_key_arn` | `""` | Existing CMK; empty = module creates a rotated CMK. |
| `writer_principal_arns` | `[]` | App roles granted append-only log access. |
| `enable_legal_hold_role` | `true` | Create the MFA-gated legal-hold role. |

See `variables.tf` for the full list and `terraform.tfvars.example` for a starting point.

## Outputs

`bucket_name`, `bucket_arn`, `kms_key_arn`, `log_group_names` (map), `log_group_arns`, `firehose_stream_arn`, `writer_policy_arn`, `legal_hold_role_arn`.

## Operational notes

- **COMPLIANCE mode is irreversible per object.** Test with `GOVERNANCE` in a sandbox; production must be `COMPLIANCE`.
- `force_destroy = false` and the delete-deny policy mean `terraform destroy` will **not** remove locked objects — this is intentional. Decommissioning requires waiting out retention or a documented, approved process.
- Set `object_lock_retention_days` to meet the **longest** applicable framework (CUI/CMMC often ≥ 3 years).
- Deploy in the same partition as the apps (`aws` or `aws-us-gov`); the module is partition-aware via `data.aws_partition`.
- Region: SSE-KMS, Object Lock, and Firehose-to-S3 are available in GovCloud — consistent with `sentinel-qms/infra/terraform/aws-govcloud` and `citadel/deploy/aws-gov`.

## Validation

`terraform fmt` passes clean. `terraform validate`/`plan` require provider download from the Terraform Registry (network-restricted in this build environment); run them in your own pipeline against the `hashicorp/aws >= 5.40` provider before applying.
