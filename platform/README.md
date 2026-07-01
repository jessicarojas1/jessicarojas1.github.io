# platform — shared GRC infrastructure

`platform` is the **shared-infrastructure** directory for the GRC monorepo. It is not
an application — it packages the security-critical building blocks the apps (aegis,
apex, paladin, sentinel-qms, citadel) reuse instead of re-implementing by hand:

| Artifact | What it is |
|---|---|
| [`audit-sink/`](audit-sink/) | A Terraform module that provisions a **tamper-evident, write-once central audit log sink** on AWS (KMS + S3 Object Lock + CloudWatch Logs + Kinesis Firehose + least-privilege IAM). |
| [`base-images/`](base-images/) | **Hardened container base images** — `Dockerfile.php-apache` and `Dockerfile.node` — that apps build *FROM* (non-root, digest-pinned, port 8080, SUID-stripped, read-only-rootfs friendly). |

## Why it exists

- **Central, immutable audit trail.** Addresses the Enterprise Security Review §25
  finding: per-app audit existed, but there was no centralized, tamper-evident,
  time-synced aggregation. `audit-sink` gives every app a write-once archive that no
  one — including root — can delete (S3 Object Lock COMPLIANCE). Companion design:
  [`../CENTRAL_AUDIT.md`](../CENTRAL_AUDIT.md).
- **One hardening baseline.** `base-images` centralizes the container hardening the
  apps each duplicated, so app Dockerfiles shrink to "FROM the base + COPY app + USER".

## Supported deployment models

| Model | Guide |
|---|---|
| Local (build images + `terraform plan`) | [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | [deployments/KUBERNETES.md](deployments/KUBERNETES.md) |
| AWS (Commercial + GovCloud) | [deployments/AWS.md](deployments/AWS.md) |
| Azure (Commercial + Government) | [deployments/AZURE.md](deployments/AZURE.md) |
| Air-gapped / offline | [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) |

> **No app server / health endpoint / login / database.** This is infra: "deploy" =
> `terraform apply` (AWS) and `docker build`/push (registry). AWS is the real target
> for the sink; Azure is a documented reference mapping only (the module has no Azure
> resources).

## Repo layout

```
platform/
├── audit-sink/         # Terraform module (AWS): main.tf, variables.tf, outputs.tf,
│                       # versions.tf, terraform.tfvars.example, README.md
├── base-images/        # Dockerfile.node, Dockerfile.php-apache, README.md
├── deployments/        # 6 operator guides (LOCAL/SINGLE_LINUX/K8S/AWS/AZURE/AIRGAPPED)
├── docs/               # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
├── README.md           # this file
├── OPEN_ITEMS.md       # production-readiness register
├── CLAUDE.md           # project guidance
└── render.yaml         # Applicability: N/A (infra module) — header comment only
```

## Technology

| Area | Tech |
|---|---|
| IaC | Terraform **>= 1.6.0**, provider `hashicorp/aws >= 5.40.0, < 6.0.0` |
| AWS services | KMS (CMK, rotation), S3 (Object Lock COMPLIANCE, versioning, SSE-KMS), CloudWatch Logs, Kinesis Firehose (extended_s3), IAM |
| Base images | `php:8.3-apache` (Apache, PHP 8.3, PDO/pdo_pgsql/gd/opcache/zip), `node:20-bookworm-slim` (Node 20) — both digest-pinned |
| Container runtime | non-root, port 8080, read-only rootfs, `cap_drop: ALL`, `no-new-privileges` |

## Prerequisites

- Terraform `>= 1.6.0`; AWS provider `>= 5.40.0, < 6.0.0`
- Docker / BuildKit 24+
- AWS CLI v2
- An OIDC-assumed IAM **role** for Terraform apply and for registry push (no static keys)

## Quick start (local)

```bash
# Base images
docker build -f platform/base-images/Dockerfile.node       -t platform/node:dev .
docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:dev .
docker run --rm --entrypoint id platform/node:dev            # uid=10001 (non-root)

# Audit-sink module (plan only locally)
cd platform/audit-sink
cp terraform.tfvars.example terraform.tfvars
terraform fmt -check && terraform init && terraform validate && terraform plan
```

Full walkthrough: [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md).

## Common commands

| Task | Command |
|---|---|
| Format check | `terraform fmt -check` (in `audit-sink/`) |
| Validate | `terraform init && terraform validate` |
| Plan | `terraform plan -out=tfplan` |
| Apply (AWS) | `terraform apply tfplan` |
| Outputs | `terraform output` |
| Build base (php) | `docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:1 .` |
| Build base (node) | `docker build -f platform/base-images/Dockerfile.node -t platform/node:1 .` |
| Re-pin digest | see the registry-API `curl` inside each Dockerfile |

## Dependencies & extensions

- **Terraform providers:** `hashicorp/aws >= 5.40.0, < 6.0.0`.
- **PHP extensions baked into the php-apache base:** `pdo`, `pdo_pgsql`, `gd`
  (freetype+jpeg), `opcache`, `zip`; Apache mods `rewrite`, `headers`; system libs
  `libpq-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev curl`.
- **Node base:** Node 20; `curl` + `ca-certificates` for healthchecks;
  `NODE_ENV=production`, `PORT=8080`.

There is no `Dockerfile` at the platform root by design — the two hardened Dockerfiles
already live in [`base-images/`](base-images/). `render.yaml` is a header-comment
placeholder because this is an infra module, not a Render web service.

## Build status

No CI badge is wired for this directory yet (see [OPEN_ITEMS.md](OPEN_ITEMS.md)).
Local gate: `terraform fmt -check` + `terraform validate` clean and both base images
build.

## Documentation

- Architecture → [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Deployment → [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)
- Disaster recovery → [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)
- Security → [docs/SECURITY.md](docs/SECURITY.md)
- Module reference → [audit-sink/README.md](audit-sink/README.md), [base-images/README.md](base-images/README.md)
