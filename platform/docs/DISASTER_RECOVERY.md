# Disaster Recovery — `platform` shared infrastructure

`platform` holds little mutable state of its own, but the state it does hold is
critical: **Terraform state** (the source of truth for the audit sink) and the
**image registry** (the published base images). The audit **data** is protected by
design — S3 Object Lock COMPLIANCE makes it non-deletable — so the DR focus is on the
*control plane* (state, provider config, registry), not on recovering audit records.

---

## What holds state

| State | Where | Criticality | Recoverable from |
|---|---|---|---|
| **Terraform state** | Remote S3 backend + DynamoDB lock, SSE-KMS — `audit-sink/backend.tf` (partial config) + `platform/bootstrap/` provisions it | **Critical** | Versioned state bucket (RPO 0) / restore a prior object version |
| Module source of truth | Git (`platform/audit-sink/`) | High | Git remote |
| Audit archive (data) | S3 `<prefix>-audit-sink-<account_id>`, Object Lock COMPLIANCE | Protected (WORM) | Not deletable; versioned + SSE-KMS |
| CMK | KMS (created by module or supplied) | Critical for reads | KMS key material / key policy |
| Hot logs | CloudWatch `/audit/<prefix>/<app>` (retention `log_retention_days`, default 400d) | Medium (archive is durable) | Firehose already delivered to S3 |
| Base images | Container registry | High | Rebuildable from Git Dockerfiles (digest-pinned) |

## RPO / RTO targets

| Component | RPO | RTO | Rationale |
|---|---|---|---|
| Terraform state | 0 (versioned backend) | < 1 h | Restore latest state object; re-`plan` to confirm drift-free |
| Audit archive (S3) | 0 | 0 | Object Lock + versioning; nothing to "restore" — it can't be lost to deletion |
| CMK | 0 | < 1 h | Key + policy restored/re-imported so ciphertext stays readable |
| Base images | 0 (in Git) | < 30 min | Rebuild from pinned Dockerfiles + push |
| CloudWatch hot tier | ≤ 400 d window | N/A | Ephemeral SIEM feed; the durable copy is in S3 |

## Backups

| What | How | Where | Retention | Encryption |
|---|---|---|---|---|
| Terraform state | Versioned S3 state bucket + DynamoDB lock table; enable bucket versioning | Separate account/region ideally | ≥ 90 days of versions | SSE-KMS |
| CMK | Document key ARN + policy; for supplied keys, ensure the key's own backup/rotation policy | KMS | Life of the archive + retention | n/a |
| Base image Dockerfiles | Git | Git remote (+ mirror) | Forever | n/a |
| Published base images | Registry replication / periodic `docker save` bundle | Second registry / offline bundle | ≥ 2 patch cycles | registry-managed |
| tfvars (sanitized) | `terraform.tfvars.example` in Git; real tfvars in a secret store | Secrets Manager | current | KMS |

> The audit archive itself needs **no** backup job: Object Lock COMPLIANCE +
> versioning + `force_destroy=false` + the delete-deny bucket policy make it
> non-destroyable, and SSE-KMS + `bucket_key_enabled` protect it at rest. Cross-region
> replication (to a bucket that is itself Object-Locked) is the optional belt-and-braces
> for regional durability — enable it with `enable_crr = true` on the sink plus the
> `audit-sink/replica/` submodule (a hardened, Object-Locked destination bucket + CMK
> in a second region). Delete markers are **not** replicated (the source is WORM), so
> the replica cannot be "caught up" into a deletion.

### Terraform state backend

The state backend is itself provisioned by `platform/bootstrap/` (S3 versioned +
SSE-KMS + TLS-only, DynamoDB `LockID` table with PITR, a rotated CMK). It is bound via
`terraform init -backend-config=backend.hcl`. Because the state bucket is versioned,
any bad/deleted state object is recoverable by restoring a prior version (see the
restore runbook §A below); the DynamoDB PITR covers the lock table.

## Restore runbook

### A. Recover Terraform state
```bash
# 1. Confirm the backend/state object is intact
aws s3api list-object-versions --bucket <tfstate-bucket> --prefix platform/audit-sink/terraform.tfstate

# 2. If corrupted/deleted, restore the last good version
aws s3api copy-object --bucket <tfstate-bucket> \
  --copy-source "<tfstate-bucket>/platform/audit-sink/terraform.tfstate?versionId=<good>" \
  --key platform/audit-sink/terraform.tfstate

# 3. Re-init against the backend and confirm no drift
cd platform/audit-sink
terraform init -backend-config=backend.hcl
terraform plan          # expect "No changes" — state matches reality
```

### B. Rebuild from scratch (state lost entirely)
```bash
# The audit DATA is safe (Object Lock). Re-adopt existing resources into fresh state:
cd platform/audit-sink
terraform init -backend-config=backend.hcl
terraform import aws_s3_bucket.audit <prefix>-audit-sink-<account_id>
terraform import aws_kinesis_firehose_delivery_stream.audit <prefix>-audit-sink
for app in $(echo aegis paladin sentinel-qms); do
  terraform import "aws_cloudwatch_log_group.audit[\"$app\"]" "/audit/<prefix>/$app"
done
# ...import KMS key/alias, IAM roles/policies similarly, then:
terraform plan          # reconcile until clean
```

### C. Rebuild base images
```bash
docker build -f platform/base-images/Dockerfile.php-apache -t <registry>/platform/php-apache:<tag> .
docker build -f platform/base-images/Dockerfile.node       -t <registry>/platform/node:<tag> .
docker push <registry>/platform/php-apache:<tag>
docker push <registry>/platform/node:<tag>
# Pinned @sha256: digests guarantee byte-identical rebuilds until you deliberately re-pin.
```

### D. CMK recovery
- If the module created the CMK: it lives in KMS with rotation; restoring state (A/B)
  re-references it. Never schedule the key for deletion while retention is active — the
  archive becomes unreadable.
- If an external `kms_key_arn` was supplied: ensure that key's own DR covers the full
  retention window.

## Verification cadence (restore drills)

| Drill | Frequency |
|---|---|
| Terraform state restore + `plan` clean | Quarterly |
| Import-from-scratch dry run (non-prod) | Semi-annually |
| Base image rebuild reproducibility check | Each patch cycle |
| Confirm audit `delete-object` still denied + Object Lock config intact | Quarterly |
| Registry replication / bundle restore test | Semi-annually |

## High availability

- **S3 / KMS / CloudWatch / Firehose** are regional, multi-AZ managed services — no
  single-AZ component to fail over.
- **Regional durability:** enable S3 cross-region replication (`enable_crr = true` +
  the `audit-sink/replica/` submodule) to a second Object-Locked bucket with its own
  replica-region CMK. The replication role carries the minimal
  `Get*ForReplication`/`ReplicateObject` permissions and the destination keeps its own
  Object Lock, so the replica is equally write-once.
- **Terraform state backend:** use a versioned S3 bucket with a DynamoDB lock table;
  consider cross-region replication of the state bucket.
- **Registry:** enable geo-replication or maintain a mirror so base-image pulls
  survive a registry outage.
- **GovCloud/isolated partitions:** the same design applies within the partition;
  cross-partition replication is generally not available — plan retention within the
  partition.

See also: [SECURITY.md](SECURITY.md) · [AWS.md](../deployments/AWS.md) · [DEPLOYMENT.md](DEPLOYMENT.md)
