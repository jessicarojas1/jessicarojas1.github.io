# `audit-sink/replica` — cross-region replication destination

Creates the **destination bucket** for the audit archive's optional cross-region
replication (CRR): a second, independently **Object-Locked** (COMPLIANCE),
versioned, SSE-KMS, deny-delete bucket in a **different region** from the primary
sink. Regional durability / belt-and-braces for the immutable archive.

## Why a submodule

Cross-region replication needs resources in **two** regions, which in Terraform
means two provider configurations. Rather than force every consumer of the primary
`audit-sink` module to wire a second (`aws.replica`) provider even when CRR is off,
the destination bucket is its own module you instantiate **only when you want CRR**,
with an `aws` provider pointed at the replica region. Both modules stay valid with a
single default provider each.

## Usage

```hcl
# Primary region provider (default) + a replica-region provider.
provider "aws" {
  region = "us-gov-west-1"
}
provider "aws" {
  alias  = "replica"
  region = "us-gov-east-1"
}

# 1. Primary sink (enable CRR; destination ARNs filled in below).
module "audit_sink" {
  source      = "../audit-sink"        # adjust to your path
  name_prefix = "platform-prod"

  enable_crr                 = true
  crr_destination_bucket_arn = module.audit_sink_replica.bucket_arn
  crr_replica_kms_key_arn    = module.audit_sink_replica.kms_key_arn
}

# 2. Replica destination bucket (in the replica region).
module "audit_sink_replica" {
  source    = "../audit-sink/replica"
  providers = { aws = aws.replica }

  name_prefix                 = "platform-prod"
  source_replication_role_arn = module.audit_sink.replication_role_arn
}
```

> There is a deliberate two-way reference (primary needs the replica bucket/CMK
> ARNs; the replica bucket policy grants the primary's replication role). Terraform
> resolves this because the replication role and bucket are created before the
> `aws_s3_bucket_replication_configuration` that binds them. If your provider
> version flags a cycle, split the apply: create the replica bucket and primary
> role first (`-target`), then apply the replication configuration.

## What it builds

| Resource | Hardening |
|---|---|
| `aws_s3_bucket.replica` | `object_lock_enabled`, `force_destroy = false` |
| versioning / SSE-KMS / ownership / public-access-block | Enabled; `aws:kms` (replica CMK), `BucketOwnerEnforced`, all public access blocked |
| `aws_s3_bucket_object_lock_configuration.replica` | Default retention `var.object_lock_mode` / `var.object_lock_retention_days` |
| `aws_s3_bucket_policy.replica` | TLS-only, KMS-only uploads, deny-all-deletes/lock-weakening, explicit replication grant to the primary role |
| `aws_kms_key.this[0]` (optional) | Rotated CMK in the replica region (created when `kms_key_arn == ""`) |

## Inputs

| Name | Default | Description |
|---|---|---|
| `name_prefix` | — | Match the primary sink. |
| `source_replication_role_arn` | — | Primary sink `replication_role_arn`. |
| `object_lock_mode` | `COMPLIANCE` | Keep write-once in prod. |
| `object_lock_retention_days` | `1095` | Match/exceed the primary. |
| `kms_key_arn` | `""` | Replica CMK, or empty to create one in this region. |

## Outputs

`bucket_arn` (→ `crr_destination_bucket_arn`), `kms_key_arn`
(→ `crr_replica_kms_key_arn`), `bucket_name`.

> **GovCloud / isolated partitions:** cross-**partition** replication is not
> available. Within GovCloud you can still replicate `us-gov-west-1 ↔ us-gov-east-1`.
> Across partitions (`aws` ↔ `aws-us-gov`), plan retention within the partition
> instead (see [../../docs/DISASTER_RECOVERY.md](../../docs/DISASTER_RECOVERY.md)).
