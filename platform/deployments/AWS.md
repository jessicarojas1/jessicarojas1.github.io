# AWS — `platform` audit-sink (Commercial + GovCloud)

**Applicability:** This is the primary cloud target. The [`audit-sink/`](../audit-sink/)
Terraform module **is** AWS IaC. This guide documents the **real** resources,
providers, and variables in `main.tf` / `variables.tf` / `outputs.tf` / `versions.tf`
and how to deploy them in both **AWS Commercial** (partition `aws`) and **AWS
GovCloud** (partition `aws-us-gov`). The module is partition-aware via
`data.aws_partition`, so the same code deploys to both.

There is **no application, health endpoint, or login** — verification is
`terraform validate/plan` clean plus an end-to-end "audit object written to the
locked bucket" check.

---

## 1. Deployment architecture (real resources)

Provider: `hashicorp/aws >= 5.40.0, < 6.0.0`; Terraform `>= 1.6.0` (`versions.tf`).
Data sources: `aws_caller_identity`, `aws_partition`, `aws_region`.

| Terraform resource | Purpose |
|---|---|
| `aws_kms_key.this[0]` + `aws_kms_alias.this[0]` | CMK (created only when `kms_key_arn == ""`), `enable_key_rotation = true`; key policy grants CloudWatch Logs use scoped by `kms:EncryptionContext:aws:logs:arn` to `/audit/<prefix>/*` |
| `aws_s3_bucket.audit` | Immutable archive; `object_lock_enabled = true`, `force_destroy = false` |
| `aws_s3_bucket_public_access_block.audit` | All four public-access blocks on |
| `aws_s3_bucket_versioning.audit` | Enabled (required for Object Lock) |
| `aws_s3_bucket_server_side_encryption_configuration.audit` | `aws:kms` with the CMK, `bucket_key_enabled` |
| `aws_s3_bucket_ownership_controls.audit` | `BucketOwnerEnforced` (ACLs disabled) |
| `aws_s3_bucket_object_lock_configuration.audit` | `default_retention { mode = var.object_lock_mode, days = var.object_lock_retention_days }` |
| `aws_s3_bucket_lifecycle_configuration.audit` | Expire noncurrent versions after `noncurrent_version_expiration_days`; abort incomplete MPU after 7 days |
| `aws_s3_bucket_policy.audit` | `DenyInsecureTransport`, `DenyUnEncryptedObjectUploads` (must be `aws:kms`), `DenyAllDeletesAndLockWeakening` (Delete*, PutBucketObjectLockConfiguration, PutObjectRetention, BypassGovernanceRetention, DeleteBucket/Policy, PutBucketVersioning, PutLifecycleConfiguration) — all principals |
| `aws_cloudwatch_log_group.audit` (for_each `log_group_names`) | `/audit/<prefix>/<app>`, KMS-encrypted, `retention_in_days = log_retention_days` |
| `aws_cloudwatch_log_group.firehose_errors` + `aws_cloudwatch_log_stream.firehose_errors` | Firehose delivery error logging |
| `aws_iam_role.firehose` + `aws_iam_role_policy.firehose` | Firehose delivery role; S3 write + `kms:Decrypt/GenerateDataKey`; assume-role gated by `sts:ExternalId = account_id` |
| `aws_kinesis_firehose_delivery_stream.audit` | `extended_s3`, CMK SSE, GZIP, `prefix audit/!{timestamp:yyyy/MM/dd}/`, error prefix, buffering from vars |
| `aws_iam_role.cwl_to_firehose` + `aws_iam_role_policy.cwl_to_firehose` | Lets CloudWatch Logs put records to Firehose |
| `aws_cloudwatch_log_subscription_filter.audit` (for_each) | Forwards all log events (`subscription_filter_pattern`) to Firehose |
| `aws_iam_policy.writer` + `aws_iam_role_policy_attachment.writer` (for_each `writer_principal_arns`) | Append-only `logs:CreateLogStream`+`logs:PutLogEvents` on the audit groups; attached to each source-app role |
| `aws_iam_role.legal_hold[0]` + `aws_iam_role_policy.legal_hold[0]` | (when `enable_legal_hold_role`) MFA-gated role that may `s3:PutObjectLegalHold`/`GetObjectLegalHold` — separation of duties |
| `aws_sns_topic.alarms[0]` + `aws_sns_topic_subscription.alarms_email[0]` | (when `enable_delivery_alarm`) SNS topic (AWS-managed-key encrypted) + optional email sub for delivery alarms |
| `aws_cloudwatch_log_metric_filter.firehose_errors[0]` + `aws_cloudwatch_metric_alarm.firehose_errors[0]` | Metric filter on `_firehose-errors` → alarm on any delivery error → SNS |
| `aws_cloudwatch_metric_alarm.firehose_freshness[0]` | Alarm on `DeliveryToS3.DataFreshness > var.delivery_freshness_alarm_seconds` (silent stall) → SNS |
| `aws_iam_role.replication[0]` + `aws_iam_role_policy.replication[0]` + `aws_s3_bucket_replication_configuration.audit[0]` | (when `enable_crr`) CRR role (min `Get*ForReplication`/`ReplicateObject` + source/replica KMS) and replication rule to the `replica/` destination bucket; delete markers not replicated |
| `permissions_boundary` on all module roles | Attached when `permissions_boundary_arn` is set |

Outputs: `bucket_name`, `bucket_arn`, `kms_key_arn`, `log_group_names` (map),
`log_group_arns`, `firehose_stream_arn`, `writer_policy_arn`, `legal_hold_role_arn`,
`delivery_alarm_topic_arn`, `replication_role_arn`.

The remote-state backend (S3 + DynamoDB lock + KMS) is provisioned by the
[`../bootstrap/`](../bootstrap) module and bound via
`terraform init -backend-config=backend.hcl` (see [DEPLOYMENT.md](../docs/DEPLOYMENT.md)).
Cross-region replication uses the [`../audit-sink/replica/`](../audit-sink/replica)
submodule for the Object-Locked destination bucket.

## 2. Topology

```
  source app task/instance role (writer_policy_arn)
     |  logs:CreateLogStream + logs:PutLogEvents  (append-only; NO S3 access)
     v
  CloudWatch Logs  /audit/<prefix>/<app>   (SSE-KMS CMK, retention=log_retention_days)
     |  subscription filter (forward all)  --assume--> cwl_to_firehose role
     v
  Kinesis Firehose  <prefix>-audit-sink  (SSE CMK, GZIP)  --assume--> firehose role
     |  prefix audit/YYYY/MM/DD/ , errors/... 
     v
  S3  <prefix>-audit-sink-<account_id>
     Object Lock (var.object_lock_mode, var.object_lock_retention_days),
     versioning, SSE-KMS, BucketOwnerEnforced, public access blocked,
     bucket policy DENY deletes/lock-weakening + TLS-only + KMS-only-upload
        ^
        | s3:PutObjectLegalHold (MFA)  <-- legal_hold role (separation of duties)
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Terraform | `>= 1.6.0` |
| AWS provider | `>= 5.40.0, < 6.0.0` |
| AWS account | Commercial or GovCloud; Object Lock, Firehose→S3, SSE-KMS all available in GovCloud |
| A Terraform state backend | S3 + DynamoDB lock + KMS — provision with [`../bootstrap/`](../bootstrap), bind via `backend.tf` + `terraform init -backend-config=backend.hcl` (see §8) |
| Deploy identity | An OIDC-assumed **role** (§4), not static keys |

## 4. Identity & credentials

**Prefer IAM roles / OIDC over static keys.**

- **Terraform apply role** — assumed via CI OIDC (e.g. GitHub Actions →
  `sts:AssumeRoleWithWebIdentity`) or an instance/SSO role. It needs create/manage
  permissions for the resource set above: `kms:*` (create key/alias/policy), `s3:*`
  bucket config actions, `logs:*` group/stream/subscription, `firehose:*`, and
  `iam:CreateRole/PutRolePolicy/CreatePolicy/AttachRolePolicy` for the Firehose,
  CWL→Firehose, writer, and legal-hold identities. Scope with a permissions
  boundary; do not use account root.
- **Source-app writer roles** — supplied via `writer_principal_arns`; the module
  attaches the least-privilege append-only `writer_policy_arn` to each. These roles
  get **no** S3 access.
- **Legal-hold role** — `enable_legal_hold_role = true` creates an MFA-gated role
  (`aws:MultiFactorAuthPresent = true`) separate from writers/operators.

Static access keys are a fallback only for environments without OIDC/roles; if used,
store in Secrets Manager, never in the repo, and rotate frequently.

## 5. Environment variables (Commercial vs GovCloud)

The module is configured by Terraform variables, not env vars. Env vars only steer
the AWS provider/CLI; partition/endpoint differences:

| Variable | AWS Commercial | AWS GovCloud | Purpose |
|---|---|---|---|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` / `us-gov-east-1` | Target region |
| partition (derived) | `aws` | `aws-us-gov` | `data.aws_partition` — all ARNs built from it |
| `AWS_USE_FIPS_ENDPOINT` | `false` (optional) | `true` (recommended) | FIPS 140-2/3 endpoints |
| STS endpoint | `sts.<region>.amazonaws.com` | `sts.<region>.us-gov.amazonaws.com` (FIPS: `sts.<region>.amazonaws.com` w/ FIPS flag) | Role assumption |
| S3 endpoint | `s3.<region>.amazonaws.com` | `s3.<region>.amazonaws.com` / `s3-fips.<region>.amazonaws.com` | Object storage |
| KMS endpoint | `kms.<region>.amazonaws.com` | `kms.<region>.amazonaws.com` / `kms-fips.<region>.amazonaws.com` | CMK operations |
| CloudWatch Logs endpoint | `logs.<region>.amazonaws.com` | `logs.<region>.amazonaws.com` / `logs-fips...` | Log groups |

The KMS key policy and log-group ARNs are built from `data.aws_partition.current.partition`
and `data.aws_region.current.name`, so they render correctly for `aws-us-gov`
automatically — no code change needed to switch partitions.

## 6. Configuration references (Terraform variables)

| Variable | Example | Purpose |
|---|---|---|
| `name_prefix` | `platform-prod` | Prefix for all resources (`^[a-z0-9][a-z0-9-]{1,40}$`); bucket becomes `<prefix>-audit-sink-<account_id>` |
| `tags` | `{DataClassification="CUI", …}` | Applied to every resource (default sets NIST/CMMC/HIPAA compliance tags) |
| `object_lock_mode` | `COMPLIANCE` | `COMPLIANCE` (write-once, prod) or `GOVERNANCE` (non-prod override-able) |
| `object_lock_retention_days` | `1095` | Default per-object WORM retention (AU-11; CUI/CMMC often ≥ 3 yr) |
| `noncurrent_version_expiration_days` | `1460` | Expire superseded versions (must exceed lock retention) |
| `log_group_names` | `["aegis","paladin","sentinel-qms"]` | One CloudWatch group per source app |
| `log_retention_days` | `400` | CloudWatch hot-tier retention |
| `subscription_filter_pattern` | `""` | Empty = forward ALL events (recommended) |
| `kms_key_arn` | `""` | Existing CMK, or empty to create a rotated CMK |
| `kms_deletion_window_days` | `30` | Deletion window for a created CMK |
| `firehose_buffer_size_mb` | `5` | Firehose buffer (MB) before S3 flush (1–128) |
| `firehose_buffer_interval_seconds` | `300` | Firehose buffer (s) before flush (60–900) |
| `writer_principal_arns` | `["arn:aws-us-gov:iam::123…:role/aegis-task"]` | App roles granted append-only log access. **Plain role ARNs only** — no path/user/STS session (enforced by a `validation` block; the module extracts the role name with `regex("role/(.+)$", …)`) |
| `enable_legal_hold_role` | `true` | Create the MFA-gated legal-hold role |
| `permissions_boundary_arn` | `""` | IAM boundary attached to every role the module creates |
| `enable_delivery_alarm` | `true` | CloudWatch delivery-error + freshness alarms → SNS |
| `alarm_email` | `""` | Email subscribed to the alarm SNS topic (optional) |
| `delivery_freshness_alarm_seconds` | `900` | Data-freshness alarm threshold (seconds) |
| `enable_crr` | `false` | Cross-region replication to the `replica/` Object-Locked bucket |
| `crr_destination_bucket_arn` / `crr_replica_kms_key_arn` | (from `replica/`) | Destination bucket + replica CMK (required when `enable_crr = true`) |

## 7. Verification

```bash
cd platform/audit-sink
terraform init -backend-config=backend.hcl   # downloads hashicorp/aws >= 5.40; remote state
terraform validate        # "Success! The configuration is valid."
terraform plan            # review the resource graph — clean
terraform apply

# Outputs resolved
terraform output          # bucket_name, kms_key_arn, log_group_names, writer_policy_arn, legal_hold_role_arn ...

# Object written to the immutable archive (end-to-end)
BUCKET=$(terraform output -raw bucket_name)
GROUP=$(terraform output -json log_group_names | python3 -c 'import sys,json;print(list(json.load(sys.stdin).values())[0])')
aws logs create-log-stream  --log-group-name "$GROUP" --log-stream-name verify
aws logs put-log-events     --log-group-name "$GROUP" --log-stream-name verify \
  --log-events "timestamp=$(($(date +%s)*1000)),message=verify"
sleep 320   # >= firehose_buffer_interval_seconds
aws s3 ls "s3://$BUCKET/audit/" --recursive | tail          # object present under audit/YYYY/MM/DD/

# Immutability holds — delete must be DENIED
aws s3api delete-object --bucket "$BUCKET" --key <some/audit/object>   # -> AccessDenied (bucket policy)
aws s3api get-object-lock-configuration --bucket "$BUCKET"             # COMPLIANCE, days=1095

# Encryption in transit enforced
aws s3api head-bucket --bucket "$BUCKET"   # over TLS; plain-HTTP is denied by DenyInsecureTransport
```

There is **no login and no app health check** — state this in runbooks; the security
control being verified is *write-once immutability and least-privilege*, not uptime.

## 8. Day-2 operations

- **Remote state:** provision the backend with `../bootstrap` (S3 versioned + SSE-KMS,
  DynamoDB `LockID` lock, rotated CMK) **before** prod applies, then
  `terraform init -backend-config=backend.hcl`. `backend.tf` sets `key` + `encrypt`;
  `backend.hcl` (git-ignored) supplies bucket/region/table/key.
- **Delivery monitoring:** `enable_delivery_alarm` (default) creates the error + stall
  alarms and an SNS topic; subscribe `delivery_alarm_topic_arn` to your on-call.
- **Regional durability:** set `enable_crr = true` with the `../audit-sink/replica/`
  submodule to replicate the archive to a second Object-Locked bucket/region.
- **Provider upgrades:** stay within `>= 5.40.0, < 6.0.0`; `terraform init -upgrade`,
  re-`plan`.
- **Adding a source app:** append its base name to `log_group_names` and its role
  ARN to `writer_principal_arns`, then `apply` — a new group + subscription filter +
  policy attachment are created.
- **Retention changes:** you can *lengthen* retention; COMPLIANCE mode forbids
  shortening/removing locks on existing objects.
- **CMK rotation:** automatic (`enable_key_rotation = true`).
- **Decommissioning:** `force_destroy = false` + delete-deny policy mean
  `terraform destroy` will **not** remove locked objects — intentional. Wait out
  retention or follow a documented, approved process.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `terraform destroy` fails on the bucket | `force_destroy=false` + locked objects | Expected; do not force. Retention must lapse |
| Firehose delivery failures | Role/KMS/bucket-policy mismatch | Inspect `/audit/<prefix>/_firehose-errors`; ensure uploads use `aws:kms` |
| `PutObject` denied for the app | App tried to write S3 directly | Apps write to **CloudWatch Logs**, never S3; only Firehose writes S3 |
| KMS `AccessDenied` for CloudWatch | EncryptionContext scope mismatch | Log group must live under `/audit/<prefix>/*` (the key policy scopes to it) |
| Wrong-partition ARNs in a plan | Provider region set to the other partition | Set `AWS_REGION` to a `us-gov-*` region for GovCloud; partition is derived |
| Cannot toggle legal hold | Missing MFA | Assume `legal_hold_role_arn` with an MFA session (`aws:MultiFactorAuthPresent`) |

See also: [AZURE.md](AZURE.md) · [DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [SECURITY.md](../docs/SECURITY.md) · [DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)
