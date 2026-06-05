# CITADEL — AWS GovCloud (US) Deployment Runbook

**CITADEL** — *Code Inspection, Threat Analysis & Deployment Evaluation Lab* — is a
source-code and executable security & compliance review platform. This package
deploys the **production** CITADEL service (an Nginx-served static front-end plus
an optional API/worker tier for heavier open-source scanners — Semgrep, Trivy,
Syft/Grype, Gitleaks, ClamAV, Bandit) to **AWS GovCloud (US)** for
**CUI / FedRAMP High / IL4–IL5** workloads.

> Partition: `aws-us-gov` · Regions: `us-gov-west-1` (US-West), `us-gov-east-1` (US-East)
> All AWS API calls use **FIPS 140-3 validated endpoints**. Data-in-transit uses
> **TLS 1.2+ FIPS** at the load balancer; data-at-rest uses **KMS CMKs**.

---

## 1. Overview & Target Architecture

The service runs as a container on **ECS Fargate** in **private subnets**, fronted
by an **Application Load Balancer** protected by **AWS WAFv2**. Images live in
**ECR** (scan-on-push, immutable tags). Secrets come from **Secrets Manager**
encrypted with a **KMS CMK**. Uploaded/scanned artifacts are quarantined in an
**S3 bucket** with **SSE-KMS + Object Lock (COMPLIANCE/WORM)**. Logs flow to
**CloudWatch Logs** (KMS-encrypted) and **CloudTrail**; detection is provided by
**GuardDuty**, **Security Hub**, and **Inspector** (ECR image scanning). **Shield**
(Standard, always on) protects the ALB.

Egress to AWS service APIs stays on the GovCloud backbone via **VPC interface and
gateway endpoints** — there is **no NAT gateway and no public internet egress**
from the task subnets.

### Architecture Diagram

```
                                 AWS GovCloud (US) — partition: aws-us-gov
                                 Region: us-gov-west-1 (or us-gov-east-1)
  Approved CIDRs / TIC
        │  HTTPS 443 (TLS1.2+ FIPS)
        ▼
 ┌─────────────────────┐
 │   AWS Shield (Std)  │
 │   AWS WAFv2 Web ACL │  Common + KnownBadInputs + SQLi + Rate-limit
 └──────────┬──────────┘
            ▼
   ┌──────────────────┐         Public subnets (AZ-a, AZ-b)
   │  Application LB   │  ◄────  HTTP:80 → 301 → HTTPS:443
   │  (HTTPS listener)│         drop_invalid_header_fields, deletion protection
   └────────┬─────────┘
            │  HTTP :8080 (in-VPC only)
            ▼
   ┌────────────────────────────────────────────────┐  Private subnets (AZ-a, AZ-b)
   │             ECS Fargate Service                 │  no public IP, awsvpc
   │  ┌──────────────┐   ┌──────────────┐            │  non-root UID 10001
   │  │  Task (AZ-a) │   │  Task (AZ-b) │  ...        │  read-only root FS
   │  │  nginx :8080 │   │  nginx :8080 │  drop ALL   │  Container Insights
   │  └──────┬───────┘   └──────┬───────┘  caps       │
   └─────────┼──────────────────┼────────────────────┘
             │ 443 (private DNS) │
             ▼                   ▼
   ┌───────────────────────────────────────────────┐
   │  VPC Endpoints (Interface + S3 Gateway)        │  ecr.api, ecr.dkr, logs,
   │  — keep all AWS API traffic on the backbone    │  secretsmanager, kms, ssm,
   └───────────────────────────────────────────────┘  sts, monitoring, s3

   Supporting services (all KMS-CMK encrypted):
   ┌────────┐ ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
   │  ECR   │ │ Secrets Manager  │ │  S3 Quarantine   │ │ CloudWatch Logs  │
   │ scan-  │ │  app secrets     │ │ SSE-KMS+Object   │ │ + VPC Flow Logs  │
   │ on-push│ │                  │ │ Lock (WORM)      │ │ + CloudTrail     │
   └────────┘ └──────────────────┘ └──────────────────┘ └──────────────────┘

   Detection plane: GuardDuty · Security Hub · Inspector (ECR) · Config
```

---

## 2. Prerequisites

| Requirement | Detail |
|---|---|
| AWS GovCloud (US) account | Partition `aws-us-gov`; standard commercial accounts will **not** work. |
| IAM principal | Permissions for ECS, ECR, ELBv2, WAFv2, KMS, Secrets Manager, S3, CloudWatch, IAM, VPC. Use a deploy role, not long-lived root keys. |
| AWS CLI v2 | Configured with GovCloud credentials. Verify partition with `aws sts get-caller-identity` (ARN should contain `aws-us-gov`). |
| FIPS endpoints | Export `AWS_USE_FIPS_ENDPOINT=true`. GovCloud FIPS endpoints follow `*-fips.us-gov-west-1.amazonaws.com` (e.g. `s3-fips.us-gov-west-1.amazonaws.com`, `kms.us-gov-west-1.amazonaws.com`). |
| Terraform | >= 1.5, AWS provider `~> 5.40`. |
| Docker | For building/pushing the image (`linux/amd64`). |
| ACM certificate | A certificate in the **same GovCloud region/partition** for the HTTPS listener (`acm_certificate_arn`). |
| FIPS base image | For the strictest ATO, swap the runtime base for a FIPS 140-3 validated image (Chainguard `nginx-fips` or RHEL UBI9 + OpenSSL FIPS mode). See `Dockerfile`. |

GovCloud-specific notes:
- **ECR registry host:** `<account-id>.dkr.ecr.us-gov-west-1.amazonaws.com`
- **ARNs** use the `aws-us-gov` partition (e.g. `arn:aws-us-gov:s3:::bucket`). The
  Terraform builds these partition-aware via `data.aws_partition.current`.
- Some commercial services/regions are unavailable in GovCloud; the services used
  here (ECS Fargate, ALB, WAFv2, ECR, KMS, Secrets Manager, S3, CloudWatch,
  GuardDuty, Security Hub, Inspector) are all GovCloud-supported.

---

## 3. Step-by-Step Deployment

### 3.1 One-command path

```bash
cd citadel/deploy/aws-gov
export AWS_USE_FIPS_ENDPOINT=true
export REGION=us-gov-west-1
export ACM_CERT_ARN=arn:aws-us-gov:acm:us-gov-west-1:<acct>:certificate/<id>

./deploy.sh
```

`deploy.sh` resolves the account/partition, ensures the ECR repo exists, logs in
to GovCloud ECR, builds and pushes the hardened image, then runs
`terraform init/plan/apply` and prints the service URL.

### 3.2 Manual path (for review / change control)

**Verify you are in GovCloud:**
```bash
export AWS_USE_FIPS_ENDPOINT=true
aws sts get-caller-identity --query 'Arn' --output text   # ARN must contain aws-us-gov
```

**Authenticate Docker to GovCloud ECR:**
```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
REGISTRY=${ACCOUNT_ID}.dkr.ecr.us-gov-west-1.amazonaws.com

aws ecr get-login-password --region us-gov-west-1 \
  | docker login --username AWS --password-stdin ${REGISTRY}
```

**Build & push (linux/amd64 to match Fargate):**
```bash
TAG=$(git rev-parse --short HEAD)
docker build --platform linux/amd64 -f Dockerfile -t ${REGISTRY}/citadel-prod:${TAG} ../..
docker push ${REGISTRY}/citadel-prod:${TAG}
```

**Provision infrastructure:**
```bash
terraform init
terraform plan  -var "region=us-gov-west-1" -var "image_tag=${TAG}" \
                -var "acm_certificate_arn=${ACM_CERT_ARN}" -out tfplan
terraform apply tfplan
terraform output service_url
```

**Populate secrets out-of-band (never in Terraform state / git):**
```bash
aws secretsmanager put-secret-value --region us-gov-west-1 \
  --secret-id citadel-prod/app \
  --secret-string file://secret.json
```

**Restrict ingress for production** — set `ingress_allowed_cidrs` to agency/TIC
egress ranges instead of `0.0.0.0/0`, and re-apply.

---

## 4. Security & Compliance Hardening Checklist

Mapped to **NIST SP 800-53 Rev5** control families and **CMMC 2.0 L2** practices.
Use this as the deployment evidence checklist for your SSP/POA&M.

- [ ] **AC-3 / AC-6 (CM-7) — Least privilege.** Task role grants only S3
      quarantine R/W + KMS; execution role only ECR pull, logs, secret read.
      Container runs non-root (UID 10001), read-only root FS, all caps dropped.
- [ ] **AC-4 / SC-7 — Boundary protection.** Tasks in private subnets, no public
      IP; only the ALB is internet-facing; SGs are least-privilege; no NAT —
      AWS API egress via VPC endpoints. Set `ingress_allowed_cidrs` to approved ranges.
- [ ] **AU-2 / AU-6 / AU-12 — Audit logging.** CloudWatch Logs (app), VPC Flow
      Logs, CloudTrail (org trail), Container Insights, WAF logging all enabled.
- [ ] **AU-9 / AU-11 — Audit protection & retention.** Logs KMS-encrypted;
      retention `>= 365d`; quarantine bucket Object Lock (WORM) for evidence integrity.
- [ ] **CM-2 / CM-6 — Baseline & config.** Infra is Terraform (versioned);
      immutable ECR tags; pin image digests in production.
- [ ] **IA-2 / IA-5 — Identification & authenticators.** No static creds in image
      or state; secrets in Secrets Manager (CMK); inject via task `secrets`.
- [ ] **RA-5 / SI-2 — Vuln scanning & flaw remediation.** ECR scan-on-push +
      Inspector continuous scanning; remediate Critical/High before promotion.
- [ ] **SC-7 — WAF.** WAFv2 with AWS managed CommonRuleSet, KnownBadInputs, SQLi,
      and IP rate-limiting associated to the ALB.
- [ ] **SC-8 / SC-13 — Transit protection & FIPS crypto.** HTTP→HTTPS redirect;
      HTTPS listener with `ELBSecurityPolicy-TLS13-1-2-FIPS-2023-04`; all SDK calls
      via FIPS endpoints (`AWS_USE_FIPS_ENDPOINT=true`); S3 denies non-TLS.
- [ ] **SC-12 / SC-28 — Key mgmt & rest encryption.** Customer-managed KMS key
      with rotation; ECR, Secrets Manager, S3, and Logs all CMK-encrypted.
- [ ] **SI-3 — Malicious code protection.** Quarantine bucket isolates uploaded
      artifacts; worker tier runs ClamAV/Trivy/Grype against ingested content.
- [ ] **SI-4 — System monitoring.** GuardDuty + Security Hub aggregate findings;
      WAF + Flow Logs feed detection.
- [ ] **SI-7 — Software/firmware integrity.** Multi-stage build strips VCS/IaC/secrets;
      immutable tags; pin base-image digest; sign images (cosign) where required.

**CMMC 2.0 L2 cross-reference (selected):** AC.L2-3.1.x (least privilege/boundary),
AU.L2-3.3.x (audit), CM.L2-3.4.x (baseline/config), IA.L2-3.5.x (identification),
RA.L2-3.11.x (risk/vuln scanning), SC.L2-3.13.x (boundary/crypto/FIPS),
SI.L2-3.14.x (system & info integrity).

---

## 5. Logging, Monitoring, Backup & DR

**Logging / Monitoring**
- CloudWatch Logs: `/citadel/prod/app` and `/citadel/prod/vpc-flow` (KMS-encrypted).
- CloudTrail: enable an **organization trail** to a dedicated logging account,
  log file validation on, SSE-KMS, S3 with Object Lock.
- GuardDuty, Security Hub (enable AWS FSBP + NIST 800-53 + FedRAMP standards),
  Inspector (ECR + ECS) — enable account-wide, ideally via delegated admin.
- WAF metrics + sampled requests in CloudWatch; alarm on rate-limit blocks and
  5xx surges.

**Backup**
- Quarantine bucket: versioning + Object Lock provide point-in-time recovery and
  WORM. Add cross-region replication to `us-gov-east-1` for DR if required.
- Terraform remote state: S3 (SSE-KMS, versioning) + DynamoDB lock — see the
  commented `backend "s3"` block in `main.tf`.
- ECR images are durable; keep last-known-good tags per the lifecycle policy.

**Disaster Recovery**
- Infra is fully codified — re-`terraform apply` in `us-gov-east-1` to rebuild.
- RTO is driven by image availability (replicate ECR) and ACM cert in the DR region.
- Run `terraform plan` regularly to detect drift; treat the repo as the source of truth.

---

## 6. Cost Notes

Primary cost drivers (GovCloud pricing is higher than commercial — budget accordingly):
- **ECS Fargate**: `desired_count` × (`task_cpu`/`task_memory`) per second. Default
  2 × (1 vCPU / 2 GB) for HA. Scale to 1 for non-prod.
- **ALB**: hourly + LCU. **WAFv2**: per web ACL + per rule + per request.
- **VPC interface endpoints**: hourly per endpoint per AZ + data processing —
  several endpoints across 2 AZs is a notable line item (still cheaper/safer than NAT).
- **KMS**: per CMK/month + per request. **S3**: storage + requests; Object Lock
  versions accumulate.
- **CloudWatch Logs**: ingestion + storage (365d retention).
- **GuardDuty/Inspector/Security Hub**: usage-based.

Cost-saving levers: drop `desired_count` and disable Container Insights in
non-prod; consolidate VPC endpoints; shorten non-prod log retention.

---

## 7. Teardown

Object Lock and ALB deletion protection require extra steps. Empty the quarantine
bucket (respecting any active retention) and disable deletion protection before
destroy:

```bash
# Disable ALB deletion protection (else destroy fails)
ALB_ARN=$(terraform output -raw alb_dns_name >/dev/null; \
          aws elbv2 describe-load-balancers --region us-gov-west-1 \
            --names citadel-prod-alb --query 'LoadBalancers[0].LoadBalancerArn' --output text)
aws elbv2 modify-load-balancer-attributes --region us-gov-west-1 \
  --load-balancer-arn "$ALB_ARN" \
  --attributes Key=deletion_protection.enabled,Value=false

# Object Lock COMPLIANCE objects cannot be deleted until retention expires.
# Once expired, empty the bucket, then:
terraform destroy -var "region=us-gov-west-1"
```

> Note: the KMS CMK has a 30-day deletion window; it schedules deletion rather
> than removing immediately.

---

## 8. Deployment Control → Compliance Mapping

| # | Deployment Control (this package) | NIST SP 800-53 Rev5 | CMMC 2.0 L2 |
|---|---|---|---|
| 1 | ECS tasks non-root, read-only FS, drop ALL caps, least-priv task role | AC-6, CM-7 | AC.L2-3.1.5, CM.L2-3.4.6 |
| 2 | Private subnets, no public IP, ALB-only ingress, least-priv SGs, no NAT | AC-4, SC-7 | AC.L2-3.1.3, SC.L2-3.13.1/.5 |
| 3 | VPC endpoints keep AWS API traffic on GovCloud backbone | SC-7 | SC.L2-3.13.1 |
| 4 | WAFv2 (Common, KnownBadInputs, SQLi, rate-limit) on ALB | SC-7, SI-3 | SC.L2-3.13.1, SI.L2-3.14.x |
| 5 | HTTPS-only, TLS1.2+ FIPS policy, HTTP→HTTPS redirect, S3 TLS-deny | SC-8, SC-13 | SC.L2-3.13.8/.11 |
| 6 | FIPS endpoints (`AWS_USE_FIPS_ENDPOINT=true`) for all SDK/CLI calls | SC-13 | SC.L2-3.13.11 |
| 7 | KMS CMK (rotation) for ECR, Secrets, S3, Logs | SC-12, SC-28 | SC.L2-3.13.10/.16 |
| 8 | Secrets Manager (no creds in image/state) injected via task secrets | IA-5 | IA.L2-3.5.10 |
| 9 | ECR scan-on-push + Inspector continuous scanning | RA-5, SI-2 | RA.L2-3.11.2, SI.L2-3.14.1 |
| 10 | CloudWatch Logs, VPC Flow Logs, CloudTrail, Container Insights | AU-2, AU-12 | AU.L2-3.3.1 |
| 11 | KMS-encrypted logs, retention >= 365d | AU-9, AU-11 | AU.L2-3.3.8 |
| 12 | S3 quarantine: SSE-KMS, block public, versioning, Object Lock (WORM) | SC-28, AU-9, SI-3 | SC.L2-3.13.16, SI.L2-3.14.2 |
| 13 | GuardDuty + Security Hub finding aggregation | SI-4 | SI.L2-3.14.6/.7 |
| 14 | Immutable ECR tags, multi-stage image strips VCS/IaC/secrets | CM-2, SI-7 | CM.L2-3.4.1, SI.L2-3.14.x |
| 15 | Terraform-codified, versioned infrastructure baseline | CM-2, CM-6 | CM.L2-3.4.1/.2 |

---

### File Reference (this directory)

| File | Purpose |
|---|---|
| `README.md` | This runbook. |
| `main.tf` | Core GovCloud infrastructure (VPC, ECS, ALB, WAF, ECR, KMS, Secrets, S3, logs). |
| `variables.tf` | Input variables with GovCloud defaults (`region = us-gov-west-1`). |
| `outputs.tf` | Service URL, ECR repo, log group, KMS/secret/bucket/WAF ARNs. |
| `deploy.sh` | Build & push to GovCloud ECR, then `terraform apply`. |
| `Dockerfile` | Hardened multi-stage production image (non-root, FIPS-base notes). |
| `nginx.conf` | Hardened Nginx (security headers, SPA, `/healthz`, size limits). |
| `.dockerignore` | Excludes VCS/IaC/secrets from the build context. |
