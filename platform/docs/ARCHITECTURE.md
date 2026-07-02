# Architecture — `platform` shared infrastructure

`platform` is **shared infrastructure for the GRC monorepo**, not an application. It
provides two independently versioned, reusable building blocks that downstream apps
(aegis, apex, paladin, sentinel-qms, citadel) consume:

- **`audit-sink/`** — a Terraform module that provisions a tamper-evident, write-once
  **central audit log sink** on AWS.
- **`base-images/`** — hardened container **base images** (`Dockerfile.node`,
  `Dockerfile.php-apache`) that apps build *FROM*.

There is no server process, HTTP API, request router, response envelope, or login in
this project. The "interfaces" it exposes are **Terraform inputs/outputs** and
**container image contracts**.

---

## Design principles

1. **Immutability first (audit).** Audit records are write-once; deletion is denied
   for all principals, including root, via S3 Object Lock COMPLIANCE + a deny bucket
   policy.
2. **Least privilege by default.** Source apps get append-only access to their log
   group and **no** access to the archive. Firehose, CWL→Firehose, writer, and
   legal-hold identities are each scoped to exactly their function.
3. **Separation of duties.** The ability to place a legal hold is isolated in a
   distinct, MFA-gated role.
4. **Supply-chain integrity (images).** Upstreams are pinned to immutable `@sha256:`
   digests; SUID bits are stripped; images run non-root on unprivileged ports.
5. **Partition/cloud awareness.** The Terraform module derives every ARN from
   `data.aws_partition`, so the same code deploys to `aws` and `aws-us-gov`.
6. **Consumable, not monolithic.** Each artifact is used by many apps; changes are
   additive and backward-compatible where possible.

## Component overview

### audit-sink (Terraform module, AWS)

Providers/tooling: Terraform `>= 1.6.0`, `hashicorp/aws >= 5.40.0, < 6.0.0`
(`versions.tf`). Data sources: `aws_caller_identity`, `aws_partition`, `aws_region`.

Data flow (from `main.tf` / `README.md`):

```
  source app  --append-only (CreateLogStream + PutLogEvents)-->
  CloudWatch Logs  /audit/<prefix>/<app>   (SSE-KMS CMK, retention configurable)
      --subscription filter (forward all)-->
  Kinesis Firehose  (CMK SSE, GZIP, dated prefixes)
      -->
  S3  <prefix>-audit-sink-<account_id>
      Object Lock COMPLIANCE + versioning + SSE-KMS + BucketOwnerEnforced
      + public access blocked + bucket policy DENY deletes/lock-weakening,
        TLS-only, KMS-only-upload
```

Resources provisioned (real, from `main.tf`):

| Group | Resources |
|---|---|
| Encryption | `aws_kms_key.this[0]` (rotation on, EncryptionContext-scoped CloudWatch grant) + `aws_kms_alias.this[0]` — created only when `kms_key_arn == ""` |
| Archive | `aws_s3_bucket.audit` + public-access-block + versioning + SSE config + ownership controls + object-lock config + lifecycle + bucket policy |
| Hot tier | `aws_cloudwatch_log_group.audit` (one per `log_group_names`) |
| Delivery | `aws_kinesis_firehose_delivery_stream.audit` (`extended_s3`) + `aws_iam_role.firehose`/policy + `firehose_errors` log group/stream |
| Forwarding | `aws_cloudwatch_log_subscription_filter.audit` + `aws_iam_role.cwl_to_firehose`/policy |
| Writers | `aws_iam_policy.writer` (append-only) + `aws_iam_role_policy_attachment.writer` per `writer_principal_arns` |
| Legal hold | `aws_iam_role.legal_hold[0]` + policy (MFA-gated) when `enable_legal_hold_role` |

Outputs (the module's "API"): `bucket_name`, `bucket_arn`, `kms_key_arn`,
`log_group_names` (map), `log_group_arns`, `firehose_stream_arn`, `writer_policy_arn`,
`legal_hold_role_arn`.

### base-images (container bases)

| Image | Upstream (pinned) | Contract |
|---|---|---|
| `Dockerfile.php-apache` | `php:8.3-apache@sha256:954d…` | Apache on **:8080**, runs as **www-data** (no root PID 1), prod php.ini + OPcache, `pdo pdo_pgsql gd opcache zip`, `rewrite`+`headers` mods, logs to stdout/stderr, PidFile/lock in `/tmp`, SUID bits stripped |
| `Dockerfile.node` | `node:20-bookworm-slim@sha256:2cf0…` | Non-root **UID/GID 10001** (`app`), `NODE_ENV=production`, `PORT=8080`, curl+ca-certificates for healthchecks, SUID bits stripped, `CMD ["node","--init","server.js"]` |

Both are designed for `runAsNonRoot: true`, `readOnlyRootFilesystem: true`,
`cap_drop: ALL`, `no-new-privileges` — apps add their own `HEALTHCHECK` and content.

## How downstream apps use the platform

1. **Adopt a base image:** `FROM platform/php-apache:<tag>` (or `platform/node`),
   `COPY` the app, keep the non-root `USER` and port 8080. See
   [`base-images/README.md`](../base-images/README.md).
2. **Ship audit logs:** each app writes its per-app audit log to
   `module.audit_sink.log_group_names["<app>"]` using the append-only
   `writer_policy_arn` attached to its task/instance role (added via
   `writer_principal_arns`). The module forwards records to the locked archive.
3. **Never touch the archive directly:** apps have log-write access only; deletion is
   denied globally.

## Monorepo placement & internal layout

```
platform/
├── audit-sink/                # Terraform module (AWS)
│   ├── main.tf                # resources (KMS, S3, CloudWatch, Firehose, IAM)
│   ├── variables.tf           # inputs
│   ├── outputs.tf             # module "API"
│   ├── versions.tf            # TF >= 1.6.0, aws >= 5.40 < 6.0
│   ├── terraform.tfvars.example
│   └── README.md
├── base-images/               # hardened container bases
│   ├── Dockerfile.node
│   ├── Dockerfile.php-apache
│   └── README.md
├── deployments/               # 6 target operator guides (this doc set)
├── docs/                      # ARCHITECTURE / DEPLOYMENT / DISASTER_RECOVERY / SECURITY
├── README.md · OPEN_ITEMS.md · CLAUDE.md · render.yaml
```

The sink's design companion is [`../../CENTRAL_AUDIT.md`](../../CENTRAL_AUDIT.md);
prior art referenced by the code: `sentinel-qms/infra/terraform/modules/{storage,observability}`,
`citadel/server/Dockerfile`, `aegis/docker/Dockerfile.hardened`.

## Configuration model

- **audit-sink:** Terraform variables (see `variables.tf` / `terraform.tfvars.example`)
  — no environment variables, no runtime config file. Key knobs: `name_prefix`,
  `log_group_names`, `object_lock_mode`/`object_lock_retention_days`,
  `log_retention_days`, `kms_key_arn`, `writer_principal_arns`, `enable_legal_hold_role`.
- **base-images:** build-time (the Dockerfiles) + runtime `securityContext`/compose
  hardening supplied by the consuming app. Env defaults baked in: `NODE_ENV`, `PORT`.

## Request & error contract

Not applicable — no HTTP surface. The equivalent "contracts" are:

- **Terraform contract:** `terraform plan`/`apply` succeed or fail with provider
  errors; the deny bucket policy and Object Lock reject unauthorized deletes with
  `AccessDenied`.
- **Image contract:** containers must run as the baked-in non-root `USER` on port
  8080 with a writable `/tmp`; violating it (e.g. forcing root, port <1024) fails at
  runtime under the recommended securityContext.

## Security model

Summarized here, detailed in [SECURITY.md](SECURITY.md): WORM immutability
(COMPLIANCE Object Lock + deny policy), CMK encryption with rotation, least-privilege
append-only writers, separation-of-duties legal-hold role (MFA), TLS-only and
KMS-only-upload enforcement, digest-pinned non-root hardened base images with SUID
stripped.

## Observability

- **Sink:** CloudWatch Logs is the hot/queryable tier and SIEM feed; Firehose
  delivery errors land in `/audit/<prefix>/_firehose-errors`; the S3 archive is the
  long-term immutable store.
- **Images:** apps log to stdout/stderr (bases are configured for it) and define
  their own `HEALTHCHECK`; the bases deliberately do not (see the Dockerfile
  trailers).

## Deployment topology

See [DEPLOYMENT.md](DEPLOYMENT.md) and the per-target guides under
[`../deployments/`](../deployments/). Primary target is **AWS**
([AWS.md](../deployments/AWS.md)); Azure is a documented reference mapping only (the
module has no Azure resources).
