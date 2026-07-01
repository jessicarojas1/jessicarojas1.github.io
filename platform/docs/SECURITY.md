# Security — `platform` shared infrastructure

Security guide for the two platform artifacts: the `audit-sink/` Terraform module and
the `base-images/` hardened container bases. The whole reason this project exists is
security posture — a **tamper-evident central audit trail** and a **hardened,
provenance-controlled container baseline** — so nearly every property below is a
first-class control, not an add-on.

There is **no application, user login, or session** in this project. "Identity" here
means the IAM/registry identities that operate it and the app roles that write audit
records.

---

## Identity & authentication

- **Operators (Terraform):** authenticate via **OIDC-assumed IAM roles** (CI federation
  or SSO), not static keys. The module itself uses `data.aws_caller_identity` /
  `data.aws_partition` / `data.aws_region` and needs no embedded credentials.
- **Source apps (writers):** authenticate with their **task/instance role** (IRSA on
  EKS). Their ARNs are passed via `writer_principal_arns`; the module attaches the
  append-only `writer_policy_arn`.
- **Legal-hold operator:** assumes `aws_iam_role.legal_hold` which **requires MFA**
  (`aws:MultiFactorAuthPresent = true`) — a distinct principal from writers/operators.
- **Registry push:** OIDC role with scoped `ecr:PutImage` (or registry-robot identity),
  never a stored admin password.

## Authorization (least privilege)

| Principal | Granted | Explicitly denied / withheld |
|---|---|---|
| Source-app writer role (`aws_iam_policy.writer`) | `logs:CreateLogStream`, `logs:PutLogEvents` on `/audit/<prefix>/*` only | **All** S3 archive access; any Delete/Put on the bucket |
| Firehose role | S3 `PutObject`/`ListBucket`/`AbortMultipartUpload` on the archive + `kms:Decrypt`/`GenerateDataKey` on the CMK | Anything outside the bucket + CMK; assume gated by `sts:ExternalId = account_id` |
| CWL→Firehose role | `firehose:PutRecord`/`PutRecordBatch` on the stream | Everything else |
| Legal-hold role | `s3:PutObjectLegalHold`/`GetObjectLegalHold` | Object deletion, retention weakening |
| Everyone (bucket policy) | — | `DeleteObject*`, `PutBucketObjectLockConfiguration`, `PutObjectRetention`, `BypassGovernanceRetention`, `DeleteBucket`/`DeleteBucketPolicy`, `PutBucketVersioning`, `PutLifecycleConfiguration`; non-TLS access; non-KMS uploads |

Separation of duties is explicit: writers can only append, operators manage infra but
cannot delete audit data, and legal holds require a separate MFA-gated role.

## Data protection

### Encryption at rest
- **S3 archive:** SSE-KMS with a customer-managed CMK, `bucket_key_enabled`.
- **CloudWatch Logs:** every `/audit/<prefix>/<app>` group is KMS-encrypted (the key
  policy grants CloudWatch use **scoped by `kms:EncryptionContext:aws:logs:arn`** to
  `/audit/<prefix>/*`, avoiding an over-broad `Resource=*` grant — an explicit AU-9
  hardening in `main.tf`).
- **Firehose:** `server_side_encryption` with `CUSTOMER_MANAGED_CMK`.
- **CMK:** `enable_key_rotation = true`; root-account admin statement + scoped
  CloudWatch grant only.

### Encryption in transit
- Bucket policy `DenyInsecureTransport` denies any non-TLS (`aws:SecureTransport=false`)
  access to the bucket and its objects.
- `DenyUnEncryptedObjectUploads` denies any `PutObject` not using `aws:kms`.

## Auditability — immutability / WORM (the core control)

- **S3 Object Lock `COMPLIANCE`** (`object_lock_mode`, default) with a **default
  retention** of `object_lock_retention_days` (default **1095 days / 3 years**). In
  COMPLIANCE mode no principal — **including the root account** — can shorten, remove,
  or bypass the lock.
- **Versioning enabled** (required for Object Lock); noncurrent versions expire only
  after `noncurrent_version_expiration_days` (default 1460), always exceeding the lock.
- **`force_destroy = false`** and the delete-deny bucket policy mean even
  `terraform destroy` cannot remove locked objects — decommissioning requires waiting
  out retention or a documented, approved process.
- **Legal hold** can be placed (via the MFA-gated role) to preserve objects beyond
  their retention for litigation/investigation.
- **Append-only ingestion path:** apps write to CloudWatch Logs (append-only IAM) →
  subscription filter → Firehose → locked S3. Apps never write S3 directly.

This satisfies **NIST 800-53 AU-9** (Protection of Audit Information), **AU-11**
(retention), **NIST 800-171 3.3.x**, **CMMC 2.0 AU.L2-3.3.1/.2**, and **HIPAA
§164.312(b)** write-once record-keeping (per the module's own compliance mapping).

## Base image hardening / provenance / scanning

| Control | php-apache base | node base |
|---|---|---|
| Pinned provenance | `FROM php:8.3-apache@sha256:954d…` | `FROM node:20-bookworm-slim@sha256:2cf0…` |
| Non-root PID 1 | `USER www-data` (Apache on :8080) | `USER 10001:10001` (`app`) |
| Unprivileged port | 8080 (no `CAP_NET_BIND_SERVICE`) | 8080 (`PORT=8080`) |
| SUID/SGID stripped | `find / -xdev -perm /6000 … chmod a-s` | same |
| Read-only-rootfs friendly | logs → stdout/stderr; PidFile/lock in `/tmp` | writes only `/tmp` |
| Runtime hardening (php.ini) | `expose_php Off`, `display_errors Off`, `cookie_httponly`, `SameSite=Strict`, OPcache `validate_timestamps=0` | `NODE_ENV=production` |

**Provenance & scanning practices:**
- **Digest pinning** guarantees the exact upstream bytes; re-pin on patch cycles using
  the registry-API command embedded in each Dockerfile (bump `@sha256:` + `# human-tag`
  together).
- **Scan** built images (Trivy/Grype) each build; block on unaccepted criticals. In
  air-gapped enclaves, use offline vuln DBs (see [AIRGAPPED.md](../deployments/AIRGAPPED.md)).
- **Runtime contract:** consumers must run with `runAsNonRoot`, `readOnlyRootFilesystem`,
  `cap_drop: ALL`, `no-new-privileges` (documented in `base-images/README.md`).

## Classification & DLP

- The module tags every resource with `DataClassification = CUI` and a compliance
  string (`NIST-800-171;NIST-AU-9;CMMC-AU-L2;HIPAA-164.312b`) by default (`variables.tf`).
- Audit records may contain CUI; the WORM archive + KMS + TLS-only + no-public-access
  posture is the DLP boundary. Public access is fully blocked (`block_public_acls`,
  `block_public_policy`, `ignore_public_acls`, `restrict_public_buckets`) and
  `BucketOwnerEnforced` disables ACLs.

## FIPS readiness

- Deploy in **GovCloud** (`aws-us-gov`) with **FIPS endpoints** (`AWS_USE_FIPS_ENDPOINT=true`;
  `s3-fips`, `kms-fips`, `logs-fips`) for FIPS 140-validated crypto in transit; SSE-KMS
  uses FIPS-validated HSMs. The module is partition-aware (`data.aws_partition`), so it
  deploys unchanged to the gov partition.
- Base images can be rebuilt on a FIPS-enabled host/base if a downstream app requires
  FIPS-mode OpenSSL.

## Operator responsibilities

- Keep Terraform state on an encrypted remote backend with locking; never commit real
  `terraform.tfvars`.
- Keep `object_lock_mode = COMPLIANCE` and retention ≥ the longest applicable framework
  in production; use `GOVERNANCE` only in a sandbox.
- Re-pin and re-scan base images on patch cycles.
- Restrict legal-hold role membership; require MFA.
- Monitor `/audit/<prefix>/_firehose-errors` for delivery gaps (an audit-completeness
  risk).

## Secrets rotation

- **CMK:** automatic annual rotation (`enable_key_rotation = true`).
- **Operator/app credentials:** short-lived via role assumption — rotation is implicit
  (STS session expiry). Any static fallback keys must be rotated on a fixed schedule and
  stored in Secrets Manager.
- **Registry credentials:** OIDC/robot identities; no long-lived push passwords.

## Reporting

Report suspected audit tampering, IAM misconfiguration, or a base-image vulnerability to
the **platform-security** owner (the default `Owner` tag / `Component=central-audit-sink`).
Include the affected `name_prefix`, account/partition, and resource ARNs. For a suspected
compromise of the archive, place a **legal hold** immediately (MFA role) and preserve
CloudWatch + Firehose error logs before any change.

See also: [ARCHITECTURE.md](ARCHITECTURE.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [AWS.md](../deployments/AWS.md)
