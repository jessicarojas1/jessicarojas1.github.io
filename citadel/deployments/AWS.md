# CITADEL — AWS Deployment (Commercial + GovCloud)

**Audience:** operators deploying CITADEL to AWS. This guide covers **AWS Commercial**
(partition `aws`) aligned with [`../deploy/aws/`](../deploy/aws/) (Terraform, full deep-scan
backend on ECS Fargate + RDS), and **AWS GovCloud (US)** (partition `aws-us-gov`) aligned with
[`../deploy/aws-gov/`](../deploy/aws-gov/) (FedRAMP-High / IL4–IL5).

CITADEL runs the **same container image** everywhere — Node 20 / Express on **:8080**,
health-check `GET /api/health`, **non-root UID 10001**, **read-only root FS**, all Linux
capabilities dropped, **≥ 2 GB RAM** (ClamAV ~1.4 GB signature DB). Build it from the **repo
root**: `docker build -f citadel/server/Dockerfile -t citadel-server .`.

> **Partition note.** The commercial stack (`aws`) is a full ECS Fargate + **RDS PostgreSQL** +
> Secrets Manager deployment of the **deep-scan backend**. The GovCloud stack (`aws-us-gov`)
> ships a hardened **front-end (nginx SPA) image** with FIPS endpoints, S3 Object-Lock upload
> quarantine, and VPC-endpoint-only egress; run the same deep-scan backend image on that IaC
> when you need real scanners in-boundary (give the task ≥ 2 GB RAM).

Related: [KUBERNETES.md](KUBERNETES.md) (EKS/IRSA) · [AZURE.md](AZURE.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Env: [`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

| Layer | Commercial (`deploy/aws/`) | GovCloud (`deploy/aws-gov/`) |
|---|---|---|
| Compute | ECS **Fargate**, desired count **2** (multi-AZ) | ECS **Fargate**, desired count **2** |
| Task size | **2048 CPU / 4096 MiB** (deep-scan) | **1024 CPU / 2048 MiB** default (raise for deep-scan) |
| Registry | **ECR** (IMMUTABLE, scan-on-push, KMS) | **ECR** GovCloud (IMMUTABLE, scan-on-push, KMS) |
| Database | **RDS PostgreSQL 16.4**, Multi-AZ, KMS | Wire external Postgres / RDS in-boundary |
| Edge / WAF | **ALB + ACM + WAFv2** | **ALB + WAFv2** (TLS13 FIPS policy) |
| Secrets / KMS | **Secrets Manager + KMS CMK** | **Secrets Manager + KMS CMK** |
| Uploads | tmpfs scratch (RO root FS) | + **S3 Object-Lock** (COMPLIANCE/WORM) quarantine |
| Logging | CloudWatch (KMS) + VPC Flow Logs | CloudWatch + **GuardDuty + Security Hub** |
| Egress | Per-AZ **NAT gateways** | **VPC endpoints only** (no NAT) |
| Regions | `us-east-1` (default) | `us-gov-west-1` / `us-gov-east-1` |

## 2. Topology

```
                     Internet ──► WAFv2 (Common + KnownBadInputs + SQLi + RateLimit 2000/5min)
                                     │
                        ┌────────────▼────────────┐  public subnets (10.60.0.0/24, .1.0/24)
                        │ ALB  :443 (ACM/TLS1.2+)  │  :80 → :443 redirect (301)
                        │  target group :8080      │  health /api/health (matcher 200)
                        └────────────┬─────────────┘
              private subnets (10.60.10.0/24, .11.0/24)  │  awsvpc, no public IP
                        ┌────────────▼─────────────┐
                        │ ECS Fargate task (x2)     │  non-root 10001 · RO rootfs · caps drop ALL
                        │  citadel-server :8080     │  ephemeral scratch → /tmp/citadel
                        │  container health check   │  secrets injected from Secrets Manager
                        └──────┬───────────┬────────┘
                    5432 (SG)  │           │ 443 → NAT (commercial) / VPC endpoints (gov)
                   ┌───────────▼──┐   ┌─────▼──────────────────────────────────────┐
                   │ RDS Postgres │   │ ECR · Secrets Manager · KMS · CloudWatch    │
                   │ 16.4 Multi-AZ│   │ STS · S3 (gateway). Gov: + S3 Object-Lock   │
                   │ KMS CMK      │   │ quarantine, GuardDuty, Security Hub          │
                   └──────────────┘   └─────────────────────────────────────────────┘
```

## 3. Prerequisites

| Requirement | Commercial | GovCloud |
|---|---|---|
| AWS account + CLI | credentials in partition `aws` | credentials in partition `aws-us-gov` |
| Terraform | ≥ **1.5.0**, provider `hashicorp/aws ~> 5.40` | same |
| Docker | build `linux/amd64` from repo root | same (`docker build --platform linux/amd64`) |
| ACM certificate | ARN for the ALB HTTPS listener (`acm_certificate_arn`) | ACM (Gov) certificate ARN |
| DNS | record → ALB DNS name | record → ALB DNS name |
| FIPS | opt-in `use_fips_endpoint` (default false) | **`AWS_USE_FIPS_ENDPOINT=true`** (default in `deploy.sh`) |
| Quotas | 2× Fargate tasks, RDS, 2× NAT, ALB, WAF | Fargate, ECR, WAF, S3 Object-Lock bucket |

## 4. Identity & credentials (prefer IAM roles)

No static keys in the app — **all secrets come from Secrets Manager**, injected by the ECS task
**execution role**; the app assumes the **task role** for runtime AWS access.

**Task execution role** `citadel-<env>-task-exec` — least privilege:

| Sid | Actions | Resource |
|---|---|---|
| `EcrAuth` | `ecr:GetAuthorizationToken` | `*` (required by ECR) |
| `EcrPull` | `ecr:BatchCheckLayerAvailability`, `ecr:GetDownloadUrlForLayer`, `ecr:BatchGetImage` | the ECR repo ARN |
| `Logs` | `logs:CreateLogStream`, `logs:PutLogEvents` | `<log-group-arn>:*` |
| `ReadSecrets` | `secretsmanager:GetSecretValue` | the 5 CITADEL secret ARNs |
| `KmsDecrypt` | `kms:Decrypt` | the CMK ARN |

**Task role** `citadel-<env>-task` — runtime identity:

| Sid | Actions | Resource | Notes |
|---|---|---|---|
| `SsmExecChannel` (commercial) | `ssmmessages:Create*/Open*Channel` | `*` | ECS Exec break-glass debugging |
| `QuarantineRW` (gov) | `s3:PutObject`, `s3:GetObject`, `s3:ListBucket` | quarantine bucket + `/*` | Upload quarantine |
| `KmsForS3` (gov) | `kms:GenerateDataKey`, `kms:Decrypt` | CMK ARN | Encrypt quarantined objects |

**On EKS instead of ECS**, use **IRSA** — annotate the pod ServiceAccount with
`eks.amazonaws.com/role-arn`; see [KUBERNETES.md](KUBERNETES.md). Never bake credentials into
the image.

## 5. Environment variables

Non-secret env is set in the ECS container definition; **secrets** are injected via `valueFrom`
Secrets Manager ARNs (never plaintext env).

| Variable | Commercial example | GovCloud example | Purpose |
|---|---|---|---|
| `NODE_ENV` | `production` | `production` | Prod hardening |
| `PORT` | `8080` | `8080` | Listen port (ALB target) |
| `CITADEL_TMP` | `/tmp/citadel` | `/tmp/citadel` | Scratch mount (ephemeral volume) |
| `PGSSL` | `1` | `1` | TLS to Postgres |
| `AWS_USE_FIPS_ENDPOINT` | `false` (opt-in) | **`true`** | Route AWS SDK calls to FIPS endpoints |
| `CITADEL_ADMIN_EMAIL` | `admin@example.com` | agency admin | First-boot admin |
| `CITADEL_MULTITENANT` / `CITADEL_BASE_DOMAIN` | `0` / `""` | `0` / `""` | Schema-per-tenant (needs DB) |
| `CITADEL_ENV` | `prod` | `prod` | Environment tag |

Secrets injected from Secrets Manager (`citadel-<env>/<name>`, all KMS-CMK encrypted):

| Env var | Secret name | Generated |
|---|---|---|
| `CITADEL_JWT_SECRET` | `citadel-<env>/jwt-secret` | 64-char random |
| `CITADEL_ADMIN_PASSWORD` | `citadel-<env>/admin-password` | 24-char w/ specials |
| `CITADEL_SUPERADMIN_TOKEN` | `citadel-<env>/superadmin-token` | 48-char |
| `CITADEL_METRICS_TOKEN` | `citadel-<env>/metrics-token` | 48-char |
| `DATABASE_URL` | `citadel-<env>/database-url` | `postgresql://citadel:…@<rds>:5432/citadel?sslmode=require` |

**GovCloud endpoint notes:** partition `aws-us-gov`; STS/KMS/Secrets Manager/S3 resolve to
`*.us-gov-west-1.amazonaws.com` with **FIPS** variants (`AWS_USE_FIPS_ENDPOINT=true`); ECR host
`<acct>.dkr.ecr.us-gov-west-1.amazonaws.com`; ALB HTTPS policy
`ELBSecurityPolicy-TLS13-1-2-FIPS-2023-04`.

## 6. Configuration references (key Terraform variables)

| Variable | Example | Purpose |
|---|---|---|
| `region` | `us-east-1` / `us-gov-west-1` | Deploy region (commercial rejects `us-gov-*`/`cn-*`) |
| `task_cpu` / `task_memory` | `2048` / `4096` | Fargate size (raise for heavy scans) |
| `desired_count` | `2` | Task count (HA) |
| `container_port` | `8080` | App port / target group |
| `vpc_cidr` | `10.60.0.0/16` (comm) · `10.80.0.0/16` (gov) | VPC range |
| `db_engine_version` | `16.4` | RDS PostgreSQL version |
| `db_instance_class` | `db.t3.medium` | RDS size |
| `db_multi_az` | `true` | RDS HA |
| `db_backup_retention_days` | `14` | RDS backups |
| `acm_certificate_arn` | `arn:aws:acm:…` | ALB HTTPS cert (required) |
| `ingress_allowed_cidrs` | `["0.0.0.0/0"]` | **Restrict to agency ranges in prod** |
| `use_fips_endpoint` | `false` / `true` | FIPS endpoints |
| `object_lock_retention_days` (gov) | `30` | S3 WORM quarantine retention |

## 7. Deploy

```bash
cd citadel/deploy/aws            # or: citadel/deploy/aws-gov
# GovCloud: export AWS_USE_FIPS_ENDPOINT=true AWS_DEFAULT_REGION=us-gov-west-1

# deploy.sh: resolves account/partition, ensures ECR (IMMUTABLE + scan-on-push),
# docker login, builds --platform linux/amd64 from the REPO ROOT, pushes :$IMAGE_TAG + :latest,
# then terraform init/plan/apply and prints the service URL.
./deploy.sh
```

Manual Terraform equivalent (commercial):

```bash
terraform init -input=false
terraform apply -input=false \
  -var "region=us-east-1" -var "environment=prod" \
  -var "image_tag=$(git rev-parse --short HEAD)" \
  -var "acm_certificate_arn=arn:aws:acm:us-east-1:<acct>:certificate/<id>"
terraform output service_url
```

Retrieve the seeded admin password:

```bash
aws secretsmanager get-secret-value --region $REGION \
  --secret-id citadel-prod/admin-password --query SecretString --output text
```

## 8. Verification

```bash
URL=$(terraform output -raw service_url)     # https://citadel-alb-….elb.amazonaws.com

# 1. Health (through ALB/WAF)
curl -fsS "$URL/api/health" | jq            # {"ok":true,"engine":"deep",...,"scanners":[…]}

# 2. Login (JWT) + secrets resolved from Secrets Manager
PW=$(aws secretsmanager get-secret-value --region $REGION \
  --secret-id citadel-prod/admin-password --query SecretString --output text)
TOKEN=$(curl -sS -X POST "$URL/api/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"admin@example.com\",\"password\":\"$PW\"}" | jq -r .token)
curl -fsS "$URL/api/auth/me" -H "Authorization: Bearer $TOKEN" | jq .email

# 3. Upload accepted + SCANNED (field name "files")
zip -r /tmp/s.zip citadel/js >/dev/null
curl -sS -X POST "$URL/api/scan" -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/s.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length)}' /tmp/report.json

# 4. Report persisted to RDS
curl -fsS "$URL/api/scans" -H "Authorization: Bearer $TOKEN" | jq 'length'
# Optionally connect to RDS (from a bastion/session) and:  SELECT count(*) FROM citadel_scans;
```

**Object written (GovCloud):** confirm a quarantined upload lands in the Object-Lock bucket:
`aws s3api list-objects-v2 --bucket citadel-prod-quarantine-<acct>` (WORM, COMPLIANCE mode).

**Secrets resolved check:** the task starts only when it can `GetSecretValue` + `kms:Decrypt`;
a failing execution role surfaces as a task that never reaches `RUNNING` in ECS events.

## 9. Day-2 operations

- **Scanner signature / DB updates.** Fargate tasks have ephemeral storage — each new task
  refreshes DBs. To keep deep-scan freshness without slow first scans:
  - Rebuild the image periodically (seeds Trivy/Grype/ClamAV DBs at build) and roll a new
    immutable `image_tag`, **or** run `freshclam` / `trivy --download-db-only` / `grype db
    update` from a startup hook. For **GovCloud/air-gapped** mirror DBs in-boundary — see
    [AIRGAPPED.md](AIRGAPPED.md).
- **Deploys / rollback.** `./deploy.sh` builds a new immutable tag and applies; ECS uses a
  **deployment circuit breaker with rollback** on failed health checks.
- **DB migrations.** None — schema is created on boot (idempotent
  [`../database/schema.sql`](../database/schema.sql)).
- **Scaling.** Raise `desired_count` or `task_cpu`/`task_memory`; prefer more tasks over one
  giant task (scans are bursty). Front-of-scale is bounded by RDS.
- **Backups.** RDS automated backups (14-day retention) + Multi-AZ + a final snapshot on
  destroy. Test restores.
- **Secret rotation.** Rotate values in Secrets Manager; force a new task deployment to
  re-inject. Rotating `CITADEL_JWT_SECRET` invalidates sessions.
- **Cert rotation.** Renew ACM (auto for ACM-managed) and update the listener cert ARN if changed.
- **Logs / monitoring.** CloudWatch Logs `/citadel/<env>/app` + VPC Flow Logs (KMS, 90–365 day
  retention). GovCloud adds **GuardDuty** + **Security Hub**. Ship audit events off-box via
  `CITADEL_AUDIT_SINK_URL`.
- **Image posture.** ECR scan-on-push + IMMUTABLE tags; pin the base image by digest (the
  Dockerfile does) and verify the signed release image (cosign) before deploy.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Task never reaches `RUNNING` | Execution role can't read secret / decrypt | Check `secretsmanager:GetSecretValue` + `kms:Decrypt` on the CMK; verify secret ARNs |
| ALB target unhealthy | App not answering `/api/health:8080` yet, or SG mismatch | Confirm target group port 8080, ECS SG allows ALB, allow start-period; check task logs |
| `502` under load / OOM | Task RAM too small for concurrent scanners | Raise `task_memory` (≥ 2 GB, ideally more); set `SCAN_CONCURRENCY=1`, `CITADEL_SCAN_ISOLATION=0` |
| Commercial apply rejected in gov region | `aws` partition stack won't run in `us-gov-*` | Use `deploy/aws-gov/`; commercial validates and rejects gov/cn |
| No egress for scanner DBs | Missing NAT (commercial) / VPC endpoints (gov) | Commercial: per-AZ NAT; Gov: pre-seed/mirror DBs (no NAT by design) |
| First deep scan slow | Trivy/Grype/ClamAV DBs downloading | Pre-pull at build/startup; mirror in GovCloud |
| Sessions reset on redeploy | New random JWT secret | Keep the persisted `citadel-<env>/jwt-secret` stable |
| WAF blocks legitimate large upload | RateLimit / CommonRuleSet body rules | Tune WAF rule/threshold; ALB body still bounded by `MAX_UPLOAD_BYTES` |

## 11. NIST SP 800-53 Rev5 cross-walk (Gov)

Non-root 10001 + RO root FS + caps drop ALL (**AC-6/CM-7/SI-7**); TLS1.2+ FIPS ALB policy + HTTP→HTTPS
(**SC-8/SC-13**); WAF-only public ingress, private subnets, **VPC endpoints** (**SC-7**); Secrets
Manager + KMS CMK via task role (**IA-5/SC-12**); KMS CMK at rest + **S3 Object-Lock WORM**
quarantine (**SC-28**); CloudWatch + VPC Flow Logs + **GuardDuty/Security Hub** (**AU-2/AU-9/SI-4**);
ECR scan-on-push + IMMUTABLE + digest-pinned base (**RA-5/SI-2**). See
[`../deploy/aws-gov/README.md`](../deploy/aws-gov/README.md) and
[`../deploy/README.md`](../deploy/README.md).

## 12. Teardown

```bash
cd citadel/deploy/aws            # or aws-gov
terraform destroy
# RDS has deletion_protection=true and takes a final snapshot; the S3 Object-Lock quarantine
# bucket (gov) enforces WORM retention — objects cannot be deleted before their retention lapses.
```
