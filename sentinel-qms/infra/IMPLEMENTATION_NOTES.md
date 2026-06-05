# Sentinel QMS — Infrastructure Implementation Notes

This document summarizes the topology delivered under `infra/` and
`.github/workflows/`, plus the deliberate simplifications a reviewer should know
about.

## Topology

### AWS GovCloud (`terraform/aws-govcloud`)
```
Internet
  │  HTTPS (FIPS TLS policy)
  ▼
WAFv2 (Common + SQLi managed rules + IP rate limit)
  ▼
Application Load Balancer  (public subnets, HTTP→HTTPS redirect)
  ├── /api/*, /health, /docs → backend target group  (:8000)
  └── /*                      → frontend target group (:8080)
  ▼  private subnets
ECS Fargate cluster (Container Insights)
  ├── backend service  (autoscaled 3–12 on CPU, readonly rootfs, exec via CMK)
  └── frontend service (2 tasks)
  │
  ├──► RDS PostgreSQL 16  (Multi-AZ, SSE-KMS, force_ssl, isolated data subnets,
  │                        no public access, 35-day backups, enhanced monitoring)
  ├──► S3 uploads bucket  (SSE-KMS CMK, versioned, public-access-block, TLS-only
  │                        + KMS-required bucket policy, lifecycle expiry)
  └──► Secrets Manager    (JWT_SECRET, DATABASE_URL, OIDC_CLIENT_SECRET; CMK)

Cross-cutting: KMS CMK (rotation on) for RDS/S3/Secrets/Logs · VPC flow logs →
CloudWatch · CloudWatch log groups + ALB 5xx / RDS CPU / RDS storage alarms →
SNS · IAM least-privilege execution + task roles · remote state in encrypted S3
+ DynamoDB lock.
```

### Azure Government (`terraform/azure-gov`)
```
Internet
  │  HTTPS
  ▼
Application Gateway WAF_v2 (OWASP 3.2, Prevention) + Public IP
  ├── /api/*, /health, /docs → backend pool
  └── /*                      → frontend pool
  ▼  delegated app subnet (internal)
Container Apps Environment (internal load balancer)
  ├── backend container app  (autoscaled, liveness/readiness on /health)
  └── frontend container app (probes on /)
  │
  ├──► PostgreSQL Flexible Server (VNet-injected, private DNS, ZoneRedundant HA,
  │                                require_secure_transport, geo-redundant backups)
  ├──► Storage Account + Blob     (GZRS, TLS1_2, private, infra-encryption,
  │                                key-auth disabled → Managed Identity only)
  └──► Key Vault (Premium/HSM)     (RBAC, private, purge protection)

Cross-cutting: User-Assigned Managed Identity (AcrPull + Key Vault Secrets User
+ Storage Blob Data Contributor) · Premium ACR (private) · Log Analytics +
Monitor metric alerts + action group · NSGs default-deny · remote state in
Azure Storage with AAD auth.
```

## Module design

A single set of dual-cloud modules (`network`, `database`, `storage`, `secrets`,
`compute`, `observability`) is selected by a `cloud = "aws" | "azure"` variable;
non-selected resources are guarded with `count`/`for_each`. Providers are pinned
(`aws ~5.40`, `azurerm ~3.100`, `terraform >= 1.6`). Every resource is tagged with
`Project`, `Environment`, `Owner`, `data-classification = CUI`, `Compliance`.

## Kubernetes

EKS/AKS path provided as both Kustomize (`base` + `overlays/{aws-govcloud,
azure-gov}`) and a Helm chart. Hardened: PSA `restricted`, non-root,
`readOnlyRootFilesystem`, dropped capabilities, `seccompProfile: RuntimeDefault`,
default-deny NetworkPolicies (IMDS blocked), HPA, PDB, ResourceQuota/LimitRange.
Secrets are sourced from External Secrets Operator (AWS) or Secrets Store CSI
Driver (Azure) — the in-repo Secret is a non-functional placeholder.

## CI/CD

- `ci.yml` — backend ruff/mypy/pytest (with a postgres service), frontend
  lint/typecheck/test/build, docker build (no push).
- `security-scan.yml` — gitleaks, checkov + tfsec (IaC), pip-audit + npm audit,
  Trivy image scan, CodeQL; SARIF uploaded to code scanning.
- `cd-aws-govcloud.yml` / `cd-azure-gov.yml` — OIDC auth (no long-lived keys),
  manual approval via protected `*-prod` environments, build/push to ECR/ACR,
  `terraform apply`, alembic migration, rolling deploy.

## Simplifications / follow-ons

1. **TLS certificates** are referenced, not minted (ACM ARN / Key Vault cert
   secret id passed as variables). The Azure App Gateway listener starts as an
   HTTP placeholder until `appgw_keyvault_cert_secret_id` is set, then flips to
   HTTPS (guarded with `lifecycle.ignore_changes`).
2. **Secret rotation**: secrets are CMK-encrypted and rotation-ready, but the
   rotation Lambda/Function is left out.
3. **Org-wide guardrails** (GuardDuty, AWS Config/Security Hub, Defender for
   Cloud, centralized CloudTrail/Activity Logs) are assumed provided by the
   landing zone and only noted, not provisioned here.
4. **Backend/frontend Dockerfiles** live in `backend/` and `frontend/` (outside
   this layer's scope); the frontend deployment assumes an unprivileged-nginx
   image listening on 8080 with writable cache/run/tmp mounts.
5. **Remote-state backend values** are placeholders supplied via
   `-backend-config` (bootstrapped by `scripts/bootstrap-remote-state.sh`).
6. The dual-cloud modules trade a little per-cloud purity for one shared
   interface; on a single-cloud deploy the unused provider's resources are
   simply never instantiated (`count = 0`).

## Validation performed

- All non-template Kubernetes/Actions YAML parsed cleanly (PyYAML multi-doc).
- Terraform module argument names and `module.*.output` references were
  cross-checked against module `variables.tf` / `outputs.tf` — all resolve.
- Brace/bracket balance verified across every `.tf` file.
- `terraform`/`kubectl`/`helm`/`docker` were intentionally **not executed** per
  task constraints; run `terraform validate`, `kubeconform`, and
  `helm template` in CI before a real deploy.
