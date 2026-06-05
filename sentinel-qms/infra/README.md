# Sentinel QMS — Infrastructure & Deployment

Infrastructure-as-Code and CI/CD for **Sentinel QMS**, an enterprise Quality
Management System for aerospace, manufacturing, and U.S. DoD work. The stack is
fully deployable to **AWS GovCloud (us-gov-west-1)** and **Azure Government**
with FedRAMP Moderate / NIST SP 800-171 / CMMC 2.0 Level 2 alignment.

## Application shape (what the infra serves)

| Component | Tech                         | Port | Notes                                   |
|-----------|------------------------------|------|-----------------------------------------|
| backend   | Python FastAPI               | 8000 | `/health` probe; PostgreSQL 16 + storage |
| frontend  | React SPA via nginx          | 8080 | `/` probe; static assets                |
| database  | PostgreSQL 16 (`sentinel_qms`) | 5432 | managed, private, encrypted             |
| storage   | S3 / Azure Blob              | —    | document/attachment uploads             |

Backend environment variables: `DATABASE_URL`, `JWT_SECRET`,
`OIDC_ISSUER`/`OIDC_CLIENT_ID`/`OIDC_CLIENT_SECRET`, `CORS_ORIGINS`,
`STORAGE_BACKEND` (`s3`|`azure_blob`), `S3_BUCKET` or
`AZURE_STORAGE_CONNECTION_STRING`/`AZURE_STORAGE_ACCOUNT`, `ENVIRONMENT`,
`LOG_LEVEL`.

## Directory map

```
infra/
  docker-compose.yml        Local full stack (postgres, minio, backend, frontend)
  .env.example              Compose env template
  terraform/                AWS GovCloud + Azure Gov IaC (modules + root stacks)
  kubernetes/               Kustomize base/overlays + Helm chart (EKS / AKS)
  scripts/                  Deploy, build/push, remote-state bootstrap
.github/workflows/          ci, security-scan, cd-aws-govcloud, cd-azure-gov
```

## Local development

```bash
cd infra
cp .env.example .env          # edit secrets (localhost only)
docker compose up --build
# frontend  -> http://localhost:8080
# backend   -> http://localhost:8000  (/health, /docs)
# minio     -> http://localhost:9001  (console)
```

MinIO emulates S3 locally so `STORAGE_BACKEND=s3` works without AWS.

## Deploy to AWS GovCloud

```bash
# 1. one-time remote state
infra/scripts/bootstrap-remote-state.sh aws

# 2. configure
cd infra/terraform/aws-govcloud
cp terraform.tfvars.example terraform.tfvars   # edit account, certs, images
export TF_VAR_oidc_client_secret='***'

# 3. deploy (build+push, terraform apply, migrate, roll services)
export ECR_REGISTRY="<acct>.dkr.ecr.us-gov-west-1.amazonaws.com"
infra/scripts/deploy-aws-govcloud.sh
```

In CI this is the `cd-aws-govcloud.yml` workflow — OIDC auth, manual approval
on the `aws-govcloud-prod` environment, FIPS STS endpoint.

## Deploy to Azure Government

```bash
infra/scripts/bootstrap-remote-state.sh azure
cd infra/terraform/azure-gov
cp terraform.tfvars.example terraform.tfvars
export TF_VAR_oidc_client_secret='***'
export ARM_SUBSCRIPTION_ID=... ARM_TENANT_ID=...
infra/scripts/deploy-azure-gov.sh
```

CI equivalent: `cd-azure-gov.yml` (Entra OIDC, `environment = AzureUSGovernment`,
`azure-gov-prod` approval gate).

## Kubernetes (alternative to ECS/Container Apps)

EKS/AKS manifests live in `kubernetes/`. Use Kustomize overlays or the Helm
chart — see `kubernetes/README.md`.

## Environment variable mapping

| App env var               | AWS source                              | Azure source                         |
|---------------------------|-----------------------------------------|--------------------------------------|
| `DATABASE_URL`            | Secrets Manager `…/DATABASE_URL`        | Key Vault `database-url`             |
| `JWT_SECRET`              | Secrets Manager `…/JWT_SECRET`          | Key Vault `jwt-secret`              |
| `OIDC_CLIENT_SECRET`      | Secrets Manager `…/OIDC_CLIENT_SECRET`  | Key Vault `oidc-client-secret`      |
| `STORAGE_BACKEND`         | `s3` (ConfigMap/task env)               | `azure_blob`                        |
| `S3_BUCKET` / storage acct| `module.storage.bucket_name`            | `AZURE_STORAGE_ACCOUNT` + container |
| `CORS_ORIGINS`,`OIDC_*`   | task env / ConfigMap                    | Container App env / ConfigMap       |

## Security notes (compliance alignment)

- **FIPS 140-2**: GovCloud uses FIPS endpoints (`use_fips_endpoint`), the ALB a
  FIPS SSL policy, and Key Vault Premium (HSM) on Azure.
- **Encryption at rest**: customer-managed KMS CMK / CMK-capable Key Vault on
  databases, object storage, secrets, and logs.
- **Encryption in transit**: TLS enforced end to end (DB `force_ssl` /
  `require_secure_transport`, S3/Blob TLS-only, ALB/App Gateway HTTPS).
- **Private data tier**: databases have no public endpoint and sit in isolated
  subnets / VNet-injected delegated subnets.
- **Least privilege**: scoped ECS task roles / managed identities; the app
  reads only its own bucket and secrets.
- **Edge protection**: WAFv2 / WAF_v2 (OWASP CRS) + rate limiting.
- **Audit logging**: VPC flow logs, RDS/Postgres log exports, Container
  Insights / Log Analytics, CloudWatch / Azure Monitor alarms.
- **No secrets in git**: only `*.example` and placeholders; real values come
  from the secret store or `TF_VAR_*` / GitHub OIDC-scoped secrets.
- **Org-level controls** (assumed provided by the landing zone): GuardDuty,
  AWS Config / Security Hub, Microsoft Defender for Cloud, centralized CloudTrail.

## Cost notes (rough, prod defaults, monthly)

- AWS: RDS `db.m6g.large` Multi-AZ (~$350), 2x NAT (~$70), ALB (~$25),
  Fargate (~$120 for 5 tasks), S3/KMS/CloudWatch (~$30). ~**$600/mo**.
- Azure: Flexible Server GP D2ds_v5 ZR-HA (~$320), App Gateway WAF_v2
  (~$300), Container Apps (~$120), Storage/Key Vault/Monitor (~$40). ~**$780/mo**.

Use a single NAT gateway (`single_nat_gateway = true`) and disable Multi-AZ in
non-prod to cut cost substantially.
