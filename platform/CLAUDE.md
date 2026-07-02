# CLAUDE.md — `platform` shared infrastructure

Guidance for working in `platform/`. This directory is **shared infrastructure**, not
an application. Read this before changing anything here, and keep the standard doc set
current (see the repo-root [`../CLAUDE.md`](../CLAUDE.md) for the org-wide rules).

## What this project is

Two reusable, security-critical building blocks for the GRC monorepo:

- **`audit-sink/`** — a Terraform module (AWS) provisioning a tamper-evident,
  write-once central audit log sink: KMS CMK, S3 with Object Lock COMPLIANCE +
  versioning + SSE-KMS + deny-delete bucket policy, CloudWatch Log groups, Kinesis
  Firehose (extended_s3), and least-privilege IAM (append-only writers, scoped Firehose
  role, MFA-gated legal-hold role).
- **`base-images/`** — hardened container bases: `Dockerfile.php-apache`
  (`php:8.3-apache`, Apache on :8080 as www-data) and `Dockerfile.node`
  (`node:20-bookworm-slim`, UID 10001). Both digest-pinned, SUID-stripped, non-root,
  read-only-rootfs friendly.

There is **no app process, HTTP API, database, migration, worker, health endpoint,
login, upload, or AI/LLM feature** here. Do not invent them, and do not document them
as if they exist.

## Stack & versions (verified — do not drift)

- Terraform **>= 1.6.0**; provider `hashicorp/aws >= 5.40.0, < 6.0.0` (`versions.tf`).
- Data sources: `aws_caller_identity`, `aws_partition`, `aws_region` — the module is
  **partition-aware** (`aws` / `aws-us-gov`); never hardcode a partition or region.
- Base images pinned to `php:8.3-apache@sha256:954d…` and
  `node:20-bookworm-slim@sha256:2cf0…`.

## Conventions

- **Immutability is the point.** Never weaken Object Lock, versioning, the deny-delete
  bucket policy, `force_destroy = false`, or downgrade `object_lock_mode` from
  `COMPLIANCE` in prod. `GOVERNANCE` is sandbox-only.
- **Least privilege.** Writers get append-only `logs:CreateLogStream`+`PutLogEvents`
  and **no** S3 access. Keep the Firehose/CWL/legal-hold roles scoped exactly as in
  `main.tf`. New source apps: add to `log_group_names` **and** `writer_principal_arns`.
- **No static keys.** Terraform apply and registry push use OIDC-assumed IAM **roles**.
- **Digest pinning.** Re-pin base images with the registry-API command embedded in each
  Dockerfile; bump `@sha256:` and the `# human-tag` comment together.
- **Non-root, port 8080.** Base images run non-root and listen on 8080; consumers must
  run with `runAsNonRoot`, `readOnlyRootFilesystem`, `cap_drop: ALL`,
  `no-new-privileges`.
- **Never commit a real `terraform.tfvars`** — only `terraform.tfvars.example` with
  placeholder ARNs. No secrets in the repo.
- **Terraform state is critical state** — remote backend + locking + KMS encryption.
  `audit-sink/backend.tf` holds a partial `backend "s3"`; `bootstrap/` provisions the
  S3 bucket + DynamoDB lock + CMK; bind with `terraform init -backend-config=backend.hcl`.
  Never commit `backend.hcl` (git-ignored).

## Where things live

```
audit-sink/   main.tf variables.tf outputs.tf versions.tf backend.tf backend.hcl.example
              terraform.tfvars.example README.md
audit-sink/replica/   CRR destination submodule (Object-Locked bucket + CMK)
audit-sink/policies/  apply-permissions-boundary.json (reference IAM boundary)
bootstrap/    Terraform remote-state backend (S3 + DynamoDB lock + KMS)
base-images/  Dockerfile.node Dockerfile.php-apache README.md
deployments/  LOCAL_DEVELOPMENT SINGLE_LINUX_SERVER KUBERNETES AZURE AWS AIRGAPPED (.md)
docs/         ARCHITECTURE DEPLOYMENT DISASTER_RECOVERY SECURITY (.md)
.github/workflows/  platform-audit-sink.yml  platform-base-images.yml (repo root)
README.md OPEN_ITEMS.md CLAUDE.md render.yaml
```

## Build / test / deploy

```bash
# audit-sink (backend provisioned once via ../bootstrap)
cd audit-sink && terraform fmt -check -recursive
terraform init -backend-config=backend.hcl && terraform validate && terraform plan
terraform apply           # AWS; uses an OIDC-assumed role

# base-images (build context = repo root)
docker build -f base-images/Dockerfile.php-apache -t platform/php-apache:1 ..
docker build -f base-images/Dockerfile.node       -t platform/node:1 ..
```

Verification (there is no health/login): `terraform validate`/`plan` clean; base images
build and run non-root; end-to-end "audit object written to the locked bucket + delete
denied" (see [deployments/AWS.md](deployments/AWS.md) §7).

## Security & compliance notes

The repo-root CLAUDE.md CSP/XSS/CSRF/UI rules target the web apps; they do **not** apply
to this infra directory (no UI, no HTML, no PHP request handling here). The rules that
**do** apply: no secrets committed, prefer env/secret managers + IAM roles, least
privilege, WORM immutability, digest-pinned hardened images. Compliance mapping:
NIST 800-53 AU-9/AU-11, NIST 800-171 3.3.x, CMMC 2.0 AU.L2, HIPAA §164.312(b).

## Standing rule — keep the doc set current

Whenever a variable, resource, output, provider version, or base-image digest changes,
update the affected files in the **same** change: `audit-sink/README.md` /
`base-images/README.md`, the relevant `deployments/*.md`, `docs/*.md`, `README.md`, and
`OPEN_ITEMS.md`. Treat this doc set as part of "done." Do not invent commands, env vars,
ports, or paths — verify every claim against the real files.
