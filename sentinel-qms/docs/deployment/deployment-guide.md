# Deployment Guide

End-to-end guide for deploying **Sentinel QMS**: prerequisites, local `docker-compose` development, image
build, deployment to **AWS GovCloud (US)** and **Azure Government**, database migrations, seed, smoke
tests, and rollback. Cloud-specific detail lives in the runbooks:
[aws-govcloud-runbook.md](aws-govcloud-runbook.md) and [azure-gov-runbook.md](azure-gov-runbook.md).

---

## 1. Prerequisites

| Tool | Version | Purpose |
|------|---------|---------|
| Docker + Compose | 24+ | Local stack |
| Python | 3.12 | Backend dev / migrations |
| Node.js | 20+ | Frontend build |
| Terraform | 1.6+ | Infrastructure provisioning |
| kubectl | matches cluster | Kubernetes operations |
| Helm | 3.13+ | Application release |
| AWS CLI | 2.x (GovCloud profile) | GovCloud deploys |
| Azure CLI | 2.5x (AzureUSGovernment cloud) | Azure Gov deploys |
| cosign | 2.x | Verify signed images |

Access requirements: a GovCloud account or Azure Gov subscription, permission to manage VPC/VNet, EKS/AKS,
RDS/PostgreSQL, KMS/Key Vault, Secrets Manager/Key Vault, and a container registry (ECR/ACR).

---

## 2. Repository Layout

```
sentinel-qms/
├── backend/        FastAPI service (app/, tests/, pyproject.toml, .env.example, Dockerfile)
├── frontend/       React + TypeScript SPA (Dockerfile, nginx config)
├── infra/
│   ├── docker-compose.yml      local full stack
│   ├── .env.example            local env template
│   └── terraform/              modules/{network,...} + cloud stacks
├── .github/        GitHub Actions workflows
├── scripts/        helper scripts (migrate, seed, smoke)
└── docs/           this documentation set
```

---

## 3. Local Development (docker-compose)

The local stack runs PostgreSQL 16, MinIO (S3-compatible), the FastAPI backend, and the React SPA.

```bash
# from the infra/ directory
cp .env.example .env          # then edit secrets (JWT_SECRET >= 32 chars)
docker compose up --build
```

Services (bound to 127.0.0.1 only):

| Service | URL |
|---------|-----|
| Backend API | http://localhost:8000 (docs at /api/v1/docs) |
| Frontend SPA | http://localhost:8080 |
| MinIO console | http://localhost:9001 |
| PostgreSQL | localhost:5432 |

> The local `.env`, MinIO, and database credentials are for **localhost only** and must never be reused in
> any cloud environment.

---

## 4. Configuration

All runtime configuration is environment-driven (see
[configuration-reference.md](configuration-reference.md)). Key variables:

| Variable | Purpose |
|----------|---------|
| `ENVIRONMENT` | `development` \| `production` (drives stricter defaults) |
| `DATABASE_URL` | PostgreSQL DSN (`postgresql+psycopg://…`) |
| `JWT_SECRET` | Token signing secret (≥32 chars; use KMS-backed asymmetric key in prod) |
| `STORAGE_BACKEND` | `s3` \| `azure_blob` \| `local` |
| `S3_BUCKET` / `S3_REGION` | Object storage in GovCloud (`us-gov-west-1`) |
| `AZURE_STORAGE_CONNECTION_STRING` / `AZURE_STORAGE_CONTAINER` | Object storage in Azure Gov |
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | Federal SSO |
| `CORS_ORIGINS` | Allowed SPA origins |
| `MAX_UPLOAD_BYTES` | Upload size cap (default 50 MiB) |

Production secrets come from **AWS Secrets Manager** / **Azure Key Vault**, never from committed files.

---

## 5. Build & Publish Images (CI/CD)

GitHub Actions builds, tests, scans, signs, and publishes images. To build locally:

```bash
# backend
docker build -t sentinel-qms/backend:$(git rev-parse --short HEAD) backend/
# frontend
docker build --build-arg VITE_API_BASE_URL=https://qms.example.gov \
  -t sentinel-qms/frontend:$(git rev-parse --short HEAD) frontend/
```

CI pushes to **Amazon ECR** (GovCloud) or **Azure Container Registry** (Azure Gov), signs with **cosign**,
and attaches an **SBOM**. Only signed images are admitted to the cluster.

```bash
# verify a published image
cosign verify --key <cosign-pub> <registry>/sentinel-qms/backend:<tag>
```

---

## 6. Provision Infrastructure (Terraform)

The `network` module provisions a 3-tier VPC/VNet (public/app/data) and is cloud-selectable
(`cloud = "aws" | "azure"`). Higher-level stacks add EKS/AKS, RDS/PostgreSQL, KMS/Key Vault, registry, and
secrets.

```bash
cd infra/terraform/<cloud-stack>
terraform init
terraform plan  -var-file=envs/prod.tfvars
terraform apply -var-file=envs/prod.tfvars
```

Defaults: VPC/VNet `10.40.0.0/16`; public `…0.0/22,4.0/22`; app `…16.0/22,20.0/22`; data
`…32.0/22,36.0/22`; AZs `us-gov-west-1a/b` or Azure zones in `usgovvirginia`. See the cloud runbooks for
specifics.

---

## 7. Deploy the Application (Helm)

```bash
# point kubectl at the cluster
aws eks update-kubeconfig --region us-gov-west-1 --name sentinel-qms-prod   # GovCloud
# or
az aks get-credentials -g sentinel-qms-prod -n sentinel-qms-prod            # Azure Gov

helm upgrade --install sentinel-qms charts/sentinel-qms \
  --namespace sentinel-qms --create-namespace \
  -f charts/sentinel-qms/values-<cloud>-prod.yaml \
  --set image.backend.tag=<tag> \
  --set image.frontend.tag=<tag>
```

The Helm values wire: ingress (TLS/FIPS), DB DSN (from Secrets Manager/Key Vault via External Secrets),
storage backend, autoscaling, and security context.

---

## 8. Database Migrations

Migrations are versioned with **Alembic** and run as a Kubernetes Job (or one-off pod) before traffic is
shifted:

```bash
# as a job
kubectl -n sentinel-qms create job --from=cronjob/sentinel-migrate migrate-<tag>
# or directly
kubectl -n sentinel-qms run migrate-<tag> --image=<registry>/sentinel-qms/backend:<tag> \
  --restart=Never --command -- alembic upgrade head
```

Migrations are **forward-only** and reviewed in CI. Always snapshot the database before a production
migration (see [operations-runbook.md](operations-runbook.md)).

---

## 9. Seed Data

The first deploy bootstraps a single admin if `ADMIN_AUTO_CREATE=true` (recommended **only** for initial
setup, then disable). Reference data (roles, KPI definitions) is seeded idempotently:

```bash
kubectl -n sentinel-qms run seed --image=<registry>/sentinel-qms/backend:<tag> \
  --restart=Never --command -- python -m app.scripts.seed
```

After first login, the bootstrap admin must rotate its password and `ADMIN_AUTO_CREATE` should be set to
`false`.

---

## 10. Smoke Test

```bash
# liveness/readiness
curl -fsS https://qms.example.gov/health

# auth round-trip
TOKEN=$(curl -fsS -X POST https://qms.example.gov/api/v1/auth/login \
  -d 'username=admin@org.gov&password=********' | jq -r .access_token)

# authorized read
curl -fsS https://qms.example.gov/api/v1/dashboard/kpis \
  -H "Authorization: Bearer $TOKEN" | jq .
```

Verify: TLS cert valid, CUI banner renders, login works, a KPI read returns data, and an audit-log row was
written for the login.

---

## 11. Rollback

Helm tracks release revisions; rollback is immediate and the app is stateless:

```bash
helm history sentinel-qms -n sentinel-qms
helm rollback sentinel-qms <previous-revision> -n sentinel-qms
```

**Database considerations:** migrations are forward-only. If a release included a schema change, roll back
to an image compatible with the current schema, or restore the pre-migration snapshot (see
[operations-runbook.md](operations-runbook.md) §DR). Practice expand/contract migrations so the previous
app version remains compatible during a rollback window.

---

## 12. Deployment Checklist

- ☐ Images built, scanned, signed, SBOM attached
- ☐ Terraform applied; network/KMS/DB/secrets in place
- ☐ Secrets in Secrets Manager / Key Vault (no plaintext)
- ☐ DB snapshot taken
- ☐ Migrations applied (`alembic upgrade head`)
- ☐ Helm release deployed; pods healthy
- ☐ Smoke test passed (health, auth, KPI read, audit row)
- ☐ TLS/FIPS verified; CUI banner visible
- ☐ `ADMIN_AUTO_CREATE=false` after bootstrap
- ☐ Monitoring/alerts confirmed receiving data
