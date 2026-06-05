# AWS GovCloud (US) Runbook

Step-by-step guidance for deploying and operating Sentinel QMS in **AWS GovCloud (US)**, region
**`us-gov-west-1`**, using **FIPS endpoints**. This runbook covers accounts, networking, KMS, EKS, RDS,
object storage, secrets, and registry specifics. Pair it with the general
[deployment-guide.md](deployment-guide.md).

> GovCloud is a separate AWS partition (`aws-us-gov`). ARNs use `arn:aws-us-gov:…`, the console is at
> `console.amazonaws-us-gov.com`, and operations are restricted to vetted **U.S. persons**.

---

## 1. Accounts & Access

- Use a dedicated GovCloud account (or Organizations OU) per environment (dev/stage/prod). ITAR programs
  use a **dedicated account** (single-tenant).
- Configure the AWS CLI for GovCloud and FIPS:
  ```bash
  aws configure --profile govcloud   # region: us-gov-west-1
  export AWS_USE_FIPS_ENDPOINT=true
  export AWS_PROFILE=govcloud
  ```
- Federate console/CLI access through your IdP (IAM Identity Center / SAML). Enforce **MFA**. No long-lived
  IAM user keys for humans.

---

## 2. Networking (Terraform `network` module, `cloud = "aws"`)

| Resource | Value |
|----------|-------|
| VPC CIDR | `10.40.0.0/16` |
| Public subnets | `10.40.0.0/22`, `10.40.4.0/22` (ALB + NAT) |
| App (private) subnets | `10.40.16.0/22`, `10.40.20.0/22` (EKS nodes/pods) |
| Data (isolated) subnets | `10.40.32.0/22`, `10.40.36.0/22` (RDS) |
| AZs | `us-gov-west-1a`, `us-gov-west-1b` |
| NAT | One per AZ (HA); `single_nat_gateway=false` |

- Data subnets have **no route to an internet/NAT gateway**.
- Add **VPC gateway endpoint for S3** and **interface endpoints** (Secrets Manager, KMS, ECR, CloudWatch
  Logs, STS) so traffic stays on the AWS network and uses FIPS endpoints.
- Security groups: ALB→nodes on the app port; nodes→RDS on 5432; deny-all otherwise.

```bash
cd infra/terraform/aws
terraform init && terraform apply -var-file=envs/prod.tfvars
```

---

## 3. KMS (FIPS HSM)

- Create customer-managed CMKs (per environment; optionally per program/tenant):
  - `sentinel-qms/rds` — RDS storage encryption
  - `sentinel-qms/s3` — S3 SSE-KMS
  - `sentinel-qms/secrets` — Secrets Manager
  - `sentinel-qms/jwt` — asymmetric signing key for RS256/ES256 JWTs (prod)
- Enable **automatic annual rotation**.
- Key policies grant `decrypt`/`sign` only to the workload role (IRSA) and required services. Forward KMS
  usage via CloudTrail to the SIEM.

---

## 4. Container Registry (ECR)

```bash
aws ecr create-repository --repository-name sentinel-qms/backend
aws ecr create-repository --repository-name sentinel-qms/frontend
aws ecr get-login-password --region us-gov-west-1 | \
  docker login --username AWS --password-stdin <acct>.dkr.ecr.us-gov-west-1.amazonaws.com
```
Enable scan-on-push and image immutability. Admit only **cosign-verified** images.

---

## 5. EKS

- Provision EKS (private API endpoint or restricted public access) with managed node groups in the **app
  subnets**.
- Enable **IRSA** (IAM Roles for Service Accounts) so the API/worker pods assume scoped roles (KMS,
  Secrets Manager, S3) without static keys.
- Install: AWS Load Balancer Controller (provisions the ALB), External Secrets Operator, metrics-server,
  and the cluster autoscaler.

```bash
aws eks update-kubeconfig --region us-gov-west-1 --name sentinel-qms-prod
```

---

## 6. RDS for PostgreSQL 16

| Setting | Value |
|---------|-------|
| Engine | PostgreSQL 16 |
| Deployment | Multi-AZ |
| Subnets | Data (isolated) subnet group |
| Encryption | CMK `sentinel-qms/rds` |
| TLS | `rds.force_ssl=1`; app uses `sslmode=verify-full` |
| Backups | Automated, 35-day retention; final snapshot on delete |
| Access | Security group: only EKS node SG on 5432 |

Store the connection string in Secrets Manager (next section). Run Alembic migrations as a Job (see the
deployment guide §8).

---

## 7. Object Storage (S3)

- Create a private bucket (e.g., `sentinel-qms-prod-uploads`) with:
  - **Block Public Access** (all four settings on)
  - **SSE-KMS** with `sentinel-qms/s3`
  - **Versioning** enabled
  - TLS-only bucket policy (`aws:SecureTransport`)
  - Access via the **S3 VPC gateway endpoint**
- App config: `STORAGE_BACKEND=s3`, `S3_BUCKET`, `S3_REGION=us-gov-west-1`. Object keys are namespaced per
  tenant/program; stored filenames are randomized.

---

## 8. Secrets Manager

Store (never in images):

| Secret | Contents |
|--------|----------|
| `sentinel-qms/prod/db` | PostgreSQL DSN |
| `sentinel-qms/prod/jwt` | JWT key reference / secret |
| `sentinel-qms/prod/oidc` | OIDC client id/secret |

Inject into pods via **External Secrets Operator** → Kubernetes Secret → env vars. Enable rotation;
CloudTrail rotation events go to the SIEM.

---

## 9. Edge & TLS

- **ACM (GovCloud)** issues the TLS certificate for the ALB; enforce TLS 1.2+ and a FIPS-capable security
  policy.
- Attach **AWS WAF** (managed rule groups + rate limiting) and **Shield** to the ALB.
- Optionally front with Route 53 (private/public hosted zone) for the QMS hostname.

---

## 10. Observability

- **CloudWatch Logs** for app (structured JSON) and infra logs.
- **Security Hub** + **GuardDuty** for posture and threat detection.
- **CloudTrail** (org trail) for API/audit; forward to the SIEM.
- Alarms: 5xx rate, p95 latency, DB CPU/connections, pod restarts, audit-pipeline failures.

---

## 11. Deploy Sequence (Prod)

1. `terraform apply` (network, KMS, EKS, RDS, S3, secrets, ECR).
2. Push signed images to ECR.
3. Create/refresh secrets in Secrets Manager.
4. Snapshot RDS.
5. Run Alembic migrations (Job).
6. `helm upgrade --install` with `values-aws-prod.yaml`.
7. Smoke test (health, auth, KPI read, audit row).
8. Confirm CloudWatch/Security Hub receiving data.

---

## 12. GovCloud-Specific Gotchas

- Some AWS services/regions available in commercial AWS are **not** in GovCloud — validate service
  availability before design.
- Always set `AWS_USE_FIPS_ENDPOINT=true`; verify endpoints resolve to `*-fips.*` where applicable.
- ARNs are `aws-us-gov` partition — update IAM policies and cross-account references accordingly.
- ACM certificates and KMS keys are **region-bound**; do not reference commercial-partition resources.
