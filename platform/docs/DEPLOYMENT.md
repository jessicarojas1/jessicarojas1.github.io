# Deployment — `platform` shared infrastructure

How to deploy/publish the two platform artifacts: the `audit-sink/` Terraform module
and the `base-images/` container bases. This is infrastructure, so "deployment" means
`terraform apply` (AWS) and `docker build` + tag + push (registry) — **there is no
app server, database migration, background worker, health endpoint, or login.**

## Contents

- [Deployment models](#deployment-models)
- [Prerequisites](#prerequisites)
- [Configuration & secrets](#configuration--secrets)
- [Remote state backend (bootstrap)](#remote-state-backend-bootstrap)
- [audit-sink: terraform init / plan / apply](#audit-sink-terraform-init--plan--apply)
- [base-images: build / tag / push](#base-images-build--tag--push)
- [Database migrations](#database-migrations) (N/A)
- [Worker / background process](#worker--background-process) (N/A)
- [Ollama configuration](#ollama-configuration) (N/A)
- [GPU acceleration](#gpu-acceleration) (N/A)
- [Production checklist](#production-checklist)
- [Per-target guides](#per-target-guides)

## Deployment models

| Model | What happens | Guide |
|---|---|---|
| Local | Build base images; `terraform plan` the sink (no apply) | [LOCAL_DEVELOPMENT.md](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | Base images on a VM; run Terraform from a bastion (instance role) | [SINGLE_LINUX_SERVER.md](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | Apps built FROM the bases; ship audit logs to the sink | [KUBERNETES.md](../deployments/KUBERNETES.md) |
| AWS (Commercial + GovCloud) | Apply the audit-sink IaC | [AWS.md](../deployments/AWS.md) |
| Azure | Base images on Azure; sink is AWS-only (reference mapping) | [AZURE.md](../deployments/AZURE.md) |
| Air-gapped | Mirror images + offline provider mirror | [AIRGAPPED.md](../deployments/AIRGAPPED.md) |

## Prerequisites

| Tool | Version |
|---|---|
| Terraform | **>= 1.6.0** (`versions.tf`) |
| AWS provider | `hashicorp/aws >= 5.40.0, < 6.0.0` |
| Docker / BuildKit | 24+ |
| AWS CLI v2 | latest |
| Deploy identity | OIDC-assumed IAM role (Terraform) + registry push role — not static keys |

## Configuration & secrets

- **audit-sink:** configured via Terraform variables (`variables.tf`,
  `terraform.tfvars.example`). **Never commit a real `terraform.tfvars`** with ARNs.
  No app secrets; the module creates its own CMK (or accepts an existing `kms_key_arn`).
- **base-images:** no secrets; only the pinned upstream digests. Registry push
  credentials come from an OIDC role, not a stored password.
- **Terraform state is sensitive** — it records ARNs/config. Use an encrypted remote
  backend (S3 + KMS + DynamoDB lock). See [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

## Remote state backend (bootstrap)

Terraform state for the sink is **critical state** (it records the ARNs/config of a
write-once archive) and must live in a remote, versioned, encrypted, lockable
backend — never local. The `platform/bootstrap/` module provisions that backend
once per account, then `audit-sink/backend.tf` (a **partial** `backend "s3"` config)
binds to it via `-backend-config`.

```bash
# 1. Create the state backend (S3 + DynamoDB lock + KMS), one-time, with local state.
cd platform/bootstrap
terraform init
terraform apply                                  # creates <prefix>-tfstate-<account_id>,
                                                 # <prefix>-tfstate-locks, and a rotated CMK
terraform output -raw backend_hcl > ../audit-sink/backend.hcl   # ready-to-use partial config

# 2. Point the audit-sink at the remote backend.
cd ../audit-sink
terraform init -backend-config=backend.hcl       # migrates state to S3 + DynamoDB lock
```

`backend.tf` keeps the non-secret `key = "platform/audit-sink/terraform.tfstate"` and
`encrypt = true`; the account-specific `bucket`, `region`, `dynamodb_table`, and
`kms_key_id` come from `backend.hcl` (copy of `backend.hcl.example`). **`backend.hcl`
is git-ignored** — never commit it. For CI validation without a backend or creds, use
`terraform init -backend=false`.

## audit-sink: terraform init / plan / apply

Exact commands against the real module (after the backend exists, above):

```bash
cd platform/audit-sink

cp terraform.tfvars.example terraform.tfvars     # set name_prefix, log_group_names,
                                                 # object_lock_*, writer_principal_arns,
                                                 # enable_delivery_alarm, enable_crr, …

terraform fmt -check -recursive                  # passes clean (verified, Terraform 1.9.8)
terraform init -backend-config=backend.hcl       # downloads hashicorp/aws >= 5.40
terraform validate                               # -> "Success! The configuration is valid."
terraform plan -out=tfplan # review: KMS + S3(+7 config resources) + N log groups +
                           # Firehose(+role/policy/errors) + subscription filters +
                           # writer policy/attachments + (optional) legal-hold role +
                           # (optional) delivery alarms+SNS + (optional) CRR role/config
terraform apply tfplan

terraform output           # bucket_name, kms_key_arn, log_group_names, writer_policy_arn,
                           # delivery_alarm_topic_arn, replication_role_arn ...
```

**Cross-region replication (optional).** Set `enable_crr = true` and provide the
destination bucket + replica CMK from the `audit-sink/replica/` submodule (a hardened,
Object-Locked bucket in a second region), wiring a `aws.replica` provider — see
[audit-sink/replica/README.md](../audit-sink/replica/README.md).

**Permissions boundary (optional).** Set `permissions_boundary_arn` to cap every IAM
role the module creates. Attach the reference boundary in
`audit-sink/policies/apply-permissions-boundary.json` to the external Terraform apply
role out-of-band.

Attach `writer_policy_arn` to each source app's runtime role (or pass the role ARNs
via `writer_principal_arns` and let the module attach it). Point each app's audit-log
shipper at the matching `log_group_names["<app>"]`.

## base-images: build / tag / push

```bash
# Build context is the repo root (paths per base-images/README.md)
docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:1 .
docker build -f platform/base-images/Dockerfile.node       -t platform/node:1 .

# Tag for your registry and push (auth via OIDC role, not a stored password)
docker tag platform/php-apache:1 <registry>/platform/php-apache:1
docker tag platform/node:1       <registry>/platform/node:1
docker push <registry>/platform/php-apache:1
docker push <registry>/platform/node:1
```

Re-pin the `@sha256:` upstream digests on patch cycles — each Dockerfile embeds the
exact registry-API `curl` command to fetch the current digest for its human tag
(`php:8.3-apache`, `node:20-bookworm-slim`). Bump the digest and the `# human-tag`
comment together.

## Database migrations

**N/A.** This project has no database and no schema/migrations. (The GRC apps that
consume the platform have their own databases and `database/schema.sql`.)

## Worker / background process

**N/A.** No cron, queue, or background worker. The sink's "processing" is fully
managed AWS (CloudWatch subscription filter → Kinesis Firehose → S3); there is no
process for you to run or supervise.

## Ollama configuration

**N/A.** There is no AI/LLM feature in this project, so there is nothing to serve via
Ollama. (In [AIRGAPPED.md](../deployments/AIRGAPPED.md) the usual "replace hosted AI
with Ollama" step is explicitly noted as not applicable.)

## GPU acceleration

**N/A.** No compute workload benefits from a GPU here.

## Production checklist

### Secrets & identity
- [ ] Terraform + registry push use **OIDC-assumed IAM roles**, not static keys.
- [ ] No real `terraform.tfvars` committed; no ARNs/keys in the repo.
- [ ] Remote Terraform backend with **KMS-encrypted state** + **DynamoDB locking**
      (run `platform/bootstrap`, then `init -backend-config=backend.hcl`).
- [ ] IAM **permissions boundary** on the apply role (`policies/apply-permissions-boundary.json`)
      and on module service roles (`permissions_boundary_arn`).
- [ ] `writer_principal_arns` scoped to exactly the source-app roles; those roles have
      **no** S3 archive access.
- [ ] `enable_legal_hold_role = true` and legal-hold assumption requires **MFA**.

### Transport & exposure
- [ ] Bucket policy `DenyInsecureTransport` (TLS-only) in effect.
- [ ] `DenyUnEncryptedObjectUploads` (KMS-only uploads) in effect.
- [ ] Public access fully blocked; `BucketOwnerEnforced` (ACLs disabled).
- [ ] Base images listen on **8080**; no privileged-port binding.

### Hardening
- [ ] `object_lock_mode = "COMPLIANCE"` in prod; `object_lock_retention_days` meets the
      **longest** applicable framework (CUI/CMMC often ≥ 3 years / 1095 days).
- [ ] CMK `enable_key_rotation = true` (default when the module creates the key).
- [ ] Base image `@sha256:` digests current; SUID stripped; run non-root, read-only
      rootfs, `cap_drop: ALL`, `no-new-privileges`.
- [ ] Base images scanned (Trivy/Grype) with no unaccepted criticals.

### Resilience & operations
- [ ] `force_destroy = false` understood — locked objects survive `terraform destroy`.
- [ ] Firehose error log group `/audit/<prefix>/_firehose-errors` monitored
      (`enable_delivery_alarm` wires the error-count + data-freshness alarms → SNS;
      subscribe `delivery_alarm_topic_arn` to on-call).
- [ ] Cross-region replication considered (`enable_crr` + `audit-sink/replica/`) for
      regional durability of the archive.
- [ ] Restore drill for Terraform state performed (see DISASTER_RECOVERY.md).
- [ ] Doc set (`deployments/`, `docs/`, `README`, `OPEN_ITEMS`) updated with any
      variable/resource/image change.

## Per-target guides

[LOCAL_DEVELOPMENT](../deployments/LOCAL_DEVELOPMENT.md) ·
[SINGLE_LINUX_SERVER](../deployments/SINGLE_LINUX_SERVER.md) ·
[KUBERNETES](../deployments/KUBERNETES.md) ·
[AWS](../deployments/AWS.md) ·
[AZURE](../deployments/AZURE.md) ·
[AIRGAPPED](../deployments/AIRGAPPED.md)
