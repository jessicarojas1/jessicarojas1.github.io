# AWS Deployment — Sentinel QMS (Commercial + GovCloud)

> **Audience:** cloud/platform engineers deploying Sentinel QMS to AWS.
> **CUI notice:** Sentinel QMS is engineered to store, process, and transmit
> **CUI** inside a U.S. government cloud boundary. Deploy CUI/ITAR/EAR workloads
> **only** to **AWS GovCloud (US)** (partition `aws-us-gov`) with FIPS endpoints —
> **never** to commercial AWS. The Commercial column exists for non-CUI demos,
> staging, and pipelines.

Aligns with [`infra/terraform/aws-govcloud/`](../infra/terraform/aws-govcloud/),
the shared Terraform modules, and the
[AWS GovCloud runbook](../docs/deployment/aws-govcloud-runbook.md). Kubernetes
object details are in [`KUBERNETES.md`](KUBERNETES.md).

Sibling guides: [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) ·
[`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`KUBERNETES.md`](KUBERNETES.md) ·
[`AZURE.md`](AZURE.md) · [`AIRGAPPED.md`](AIRGAPPED.md)

---

## 1. Deployment architecture

Container images (`backend` FastAPI :8000, `frontend` nginx SPA :8080, or the
single-service image) run on **EKS** (default, matches the Helm chart /
Kustomize overlays) or **ECS/Fargate**, fronted by an **ALB + WAF**. Managed
data services: **RDS PostgreSQL 16** (Multi-AZ, CMK), **S3** for uploads
(SSE-KMS, versioned, Block Public Access), **Secrets Manager** for the DB DSN /
JWT / OIDC secret, **KMS** CMKs, and **CloudWatch** for logs/metrics.

Alembic migrations run as a Kubernetes/ECS **Job** (`AUTO_MIGRATE=0` on the
service) or via the entrypoint. Workload pods/tasks use **IRSA** (EKS) or a
**task role** (ECS) — no static keys.

| Partition | Region | Console | STS/KMS/S3 |
|-----------|--------|---------|------------|
| **Commercial** (`aws`) | e.g. `us-east-1` | `console.aws.amazon.com` | standard (optionally FIPS `*-fips`) |
| **GovCloud** (`aws-us-gov`) | `us-gov-west-1` (or `us-gov-east-1`) | `console.amazonaws-us-gov.com` | **FIPS** `*-fips.us-gov-west-1.amazonaws.com`; ARNs `arn:aws-us-gov:…` |

---

## 2. Topology

```
                       Route 53  ─────────────► ACM cert (region-bound)
                          │
                    ┌─────▼──────┐   TLS 1.2+, WAF managed rules + rate limit
                    │ ALB + WAF  │
                    └─────┬──────┘
                /api/v1   │   /            (public subnets, per-AZ NAT)
                ┌─────────▼──────────────────────────┐  app (private) subnets
                │ EKS nodes / Fargate                 │
                │  backend pods (IRSA)  frontend pods │
                └───┬──────────────┬──────────────┬───┘
   psycopg (5432,   │   Secrets Mgr│ VPC          │ S3 gateway endpoint
   TLS verify-full) │   interface  │ endpoint     │
                    ▼   endpoint ▼               ▼
        ┌───────────────────┐ ┌──────────────┐ ┌────────────────────────┐
        │ RDS PostgreSQL 16 │ │ Secrets Mgr  │ │ S3 uploads (SSE-KMS,    │
        │ Multi-AZ, CMK     │ │ db/jwt/oidc  │ │ versioned, BPA on)      │
        │ (isolated subnet) │ └──────────────┘ └────────────────────────┘
        └───────────────────┘        KMS CMKs: rds · s3 · secrets · jwt
        CloudWatch Logs / GuardDuty / Security Hub / CloudTrail → SIEM
```

Data subnets have **no NAT/internet route**; S3, Secrets Manager, KMS, ECR, STS,
CloudWatch are reached via **VPC endpoints** (FIPS in GovCloud).

---

## 3. Prerequisites

| Item | Notes |
|------|-------|
| AWS account / Org OU | Dedicated GovCloud account per env; single-tenant account for ITAR programs. |
| AWS CLI v2 | `aws configure --profile govcloud` (region `us-gov-west-1`); `export AWS_USE_FIPS_ENDPOINT=true`. |
| Terraform | 1.6+ — `infra/terraform/aws-govcloud`. |
| kubectl / helm | for EKS. |
| Docker + ECR access | build/push images. |
| Quotas | vCPU (EKS/Fargate), RDS instances, Elastic IPs (NAT), NAT gateways. |
| U.S. persons | GovCloud operations restricted to vetted U.S. persons. |

---

## 4. Identity & credentials

**Prefer IAM roles / IRSA over static keys everywhere.**

- **Humans:** federate via IAM Identity Center / SAML; enforce MFA; no long-lived
  IAM user keys.
- **EKS pods:** **IRSA** — annotate the `sentinel-backend` ServiceAccount with the
  role ARN (see [`KUBERNETES.md`](KUBERNETES.md) §4). GovCloud ARN example:
  `arn:aws-us-gov:iam::<acct>:role/sentinel-qms-irsa`.
- **ECS tasks:** assign a **task role** with the same least-privilege policy.
- **Secrets:** the app reads `DATABASE_URL` / `JWT_SECRET` / `OIDC_CLIENT_SECRET`
  from Secrets Manager (via External Secrets Operator on EKS, or ECS
  `secrets` valueFrom). Static `AWS_ACCESS_KEY_ID` is **not** set — the SDK uses
  the role.

Least-privilege backend role policy (GovCloud partition; swap `aws-us-gov`→`aws`
and the region for Commercial):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "Uploads", "Effect": "Allow",
      "Action": ["s3:GetObject","s3:PutObject","s3:DeleteObject"],
      "Resource": "arn:aws-us-gov:s3:::sentinel-qms-prod-uploads/*" },
    { "Sid": "List", "Effect": "Allow", "Action": ["s3:ListBucket"],
      "Resource": "arn:aws-us-gov:s3:::sentinel-qms-prod-uploads" },
    { "Sid": "KmsData", "Effect": "Allow",
      "Action": ["kms:GenerateDataKey","kms:Decrypt"],
      "Resource": "arn:aws-us-gov:kms:us-gov-west-1:<acct>:key/<s3-cmk-id>" },
    { "Sid": "Secrets", "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:aws-us-gov:secretsmanager:us-gov-west-1:<acct>:secret:sentinel-qms/prod/*" }
  ]
}
```

KMS CMKs (annual rotation): `sentinel-qms/rds`, `sentinel-qms/s3`,
`sentinel-qms/secrets`, `sentinel-qms/jwt` (asymmetric signing for RS256/ES256 in
prod). Key policies grant use only to the workload role + required services.

---

## 5. Environment variables

App keys, with Commercial vs GovCloud differences called out.

| Variable | Example — **Commercial** (`aws`) | Example — **GovCloud** (`aws-us-gov`) | Purpose |
|----------|----------------------------------|----------------------------------------|---------|
| `ENVIRONMENT` | `production` | `production` | Hardens (JWT guard, HSTS). |
| `LOG_LEVEL` | `INFO` | `INFO` | Log level → CloudWatch. |
| `DATABASE_URL` | `postgresql+psycopg://sentinel:***@<rds>.us-east-1.rds.amazonaws.com:5432/sentinel_qms?sslmode=verify-full` | `postgresql+psycopg://sentinel:***@<rds>.us-gov-west-1.rds.amazonaws.com:5432/sentinel_qms?sslmode=verify-full` | DB DSN (from Secrets Manager). |
| `DB_SCHEMA` | `sentinel_qms` | `sentinel_qms` | Dedicated schema. |
| `JWT_SECRET` | *(Secrets Manager, ≥ 32 chars)* | *(Secrets Manager, ≥ 32 chars)* | Token signing. |
| `STORAGE_BACKEND` | `s3` | `s3` | Upload backend. |
| `S3_BUCKET` | `sentinel-qms-prod-uploads` | `sentinel-qms-prod-uploads` | Bucket. |
| `S3_REGION` | `us-east-1` | `us-gov-west-1` | Region. |
| `S3_ENDPOINT_URL` | *(blank → default)* | *(blank → default; FIPS via `AWS_USE_FIPS_ENDPOINT`)* | Leave blank for real S3; only set for MinIO. |
| `AWS_USE_FIPS_ENDPOINT` | *(optional)* | `true` | Route STS/KMS/S3 to FIPS endpoints. |
| `CORS_ORIGINS` | `https://qms.example.com` | `https://qms.example.gov` | Allowed origins. |
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` | your IdP | your Gov IdP | SSO (empty = disabled). |
| `OIDC_CLIENT_SECRET` | *(Secrets Manager)* | *(Secrets Manager)* | SSO secret. |
| `TRUST_PROXY_HEADERS` | `true` | `true` | Behind ALB. |
| `TRUSTED_PROXY_COUNT` | `1` | `1` | ALB hop count. |
| `APP_BASE_URL` | `https://qms.example.com` | `https://qms.example.gov` | Deep links. |
| `AUTO_MIGRATE` | `0` (service) / `1` (Job) | same | Migrations via Job. |
| `AUTO_SEED` | `0` (prod) | `0` (prod) | Seed once, not per rollout. |
| `ADMIN_AUTO_CREATE` | `false` | `false` | No auto admin. |
| `WEB_CONCURRENCY` | `4` | `4` | gunicorn workers per pod/task. |

> **The app never needs `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY` in AWS** —
> boto3 uses the IRSA/task role. Only local/MinIO dev sets them.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` | 50 MB upload cap. |
| `REDIS_URL` | `redis://<elasticache>:6379/0` | Cross-replica rate limiting (optional; ElastiCache). |
| `RATE_LIMIT_PER_MINUTE` | `300` | Per-principal budget (WAF also rate-limits at the edge). |
| `ACCESS_TOKEN_EXPIRE_MINUTES` | `30` | Access-token TTL. |
| `RUN_SCHEDULER` | `true` (one replica) | In-process SLA sweep + digest. |

Terraform: `cd infra/terraform/aws-govcloud && terraform init && terraform apply`
provisions VPC (public/app/data subnets), KMS, EKS, RDS, S3, Secrets Manager,
ALB/WAF, and observability from the shared modules. Set the app config via the
Helm `values-aws-govcloud.yaml` ConfigMap and the External Secret.

Storage note: with real S3 (`S3_ENDPOINT_URL` blank) the backend sets
`ServerSideEncryption=aws:kms` on every `put_object` (see
`app/services/storage.py`), so the bucket CMK is enforced at write time.

---

## 7. Verification

```bash
# 7.1 Health via the ALB
curl -fsS https://qms.example.gov/health                    # {"status":"ok"} 200

# 7.2 Secrets resolved + login (creds from Secrets Manager)
TOKEN=$(curl -fsS -X POST https://qms.example.gov/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@your-org.gov","password":"<pw>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
# A token proves DATABASE_URL + JWT_SECRET resolved from Secrets Manager.

# 7.3 Upload accepted + scanned (magic-byte) + object written
printf '%%PDF-1.4\n%%EOF\n' > /tmp/t.pdf
curl -fsS -X POST https://qms.example.gov/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/t.pdf;type=application/pdf'                 # 201, storage_backend=s3
```

Confirm the DB rows (attachment + immutable audit trail) — from a backend pod:

```bash
kubectl -n sentinel-qms exec deploy/backend -- python -c \
"from app.core.database import SessionLocal; from sqlalchemy import text; s=SessionLocal(); \
print(s.execute(text('SELECT stored_key, storage_backend, checksum_sha256 FROM attachments ORDER BY id DESC LIMIT 1')).fetchone()); \
print(s.execute(text(\"SELECT action, actor_email FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1\")).fetchone())"
```

Confirm the S3 object (GovCloud FIPS endpoint):

```bash
aws s3api list-objects-v2 --bucket sentinel-qms-prod-uploads \
  --region us-gov-west-1 \
  --endpoint-url https://s3-fips.us-gov-west-1.amazonaws.com \
  --query 'Contents[-1].[Key,Size]' --output text
# verify server-side encryption is aws:kms:
aws s3api head-object --bucket sentinel-qms-prod-uploads --key <uuid>.pdf \
  --region us-gov-west-1 --query 'ServerSideEncryption'      # "aws:kms"
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Image build/push | `aws ecr get-login-password | docker login <acct>.dkr.ecr.us-gov-west-1.amazonaws.com`; scan-on-push + immutable tags; admit cosign-verified only. |
| Deploy/upgrade | `helm upgrade --install ... -f values-aws-govcloud.yaml`; rolling update honors PDBs. |
| Migrations | Snapshot RDS, then run the migration **Job** (`alembic upgrade head`) before shifting traffic. |
| Scale | EKS HPA (CPU) + cluster autoscaler; RDS: enlarge instance / add read replica; add `REDIS_URL` for shared rate limiting. |
| Backups | RDS automated backups (35-day retention) + PITR; final snapshot on delete; S3 versioning for uploads. |
| Restore | Restore RDS snapshot/PITR; uploads recovered from S3 version history. See `docs/DISASTER_RECOVERY.md`. |
| Cert rotation | ACM auto-renews the ALB cert. |
| Secret rotation | Rotate in Secrets Manager (enable rotation for DB); ExternalSecret re-syncs; `kubectl rollout restart deploy/backend`. Rotating `JWT_SECRET` invalidates live access tokens. |
| Logs/alarms | CloudWatch Logs (JSON); GuardDuty + Security Hub; CloudTrail org trail → SIEM. Alarm on 5xx, p95 latency, DB CPU/connections, pod restarts, audit-pipeline failures. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod `AccessDenied` writing S3 / KMS | IRSA role not assumed or missing KMS perms | Check SA role-ARN annotation, role trust policy, and `kms:GenerateDataKey` on the S3 CMK. |
| `Could not connect to the endpoint URL` | Commercial endpoint referenced in GovCloud | Set GovCloud region + `AWS_USE_FIPS_ENDPOINT=true`; use `aws-us-gov` ARNs. |
| Secrets not injected | External Secrets Operator misconfigured | Verify SecretStore + IRSA for ESO; check `kubectl describe externalsecret`. |
| RDS connection refused / SSL error | SG or TLS mode | Allow node SG → RDS 5432; use `sslmode=verify-full` with the RDS CA. |
| `refusing to start ... insecure default` | Weak `JWT_SECRET` | Store a ≥ 32-char secret in Secrets Manager. |
| ALB 502 | Readiness on `/health` failing | Check DB reachability, target group health, and probe port 8000. |
| Upload 400 "contents do not match" | Not a real allowed file type | Server sniffs magic bytes — upload a genuine PDF/PNG/etc. |
| Service exists in Commercial but not GovCloud | Partition parity gap | Validate GovCloud service availability before design. |
