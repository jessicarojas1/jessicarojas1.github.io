# AEGIS GRC — AWS Deployment (Commercial + GovCloud)

Audience: operators deploying AEGIS to **AWS Commercial** (`aws` partition) or **AWS
GovCloud (US)** (`aws-us-gov` partition) on **ECS Fargate** or **EKS**, backed by
**RDS PostgreSQL 16**, **S3**, **Secrets Manager**, and **KMS**, with IAM roles /
IRSA for identity. This is the operator-facing summary; the deep, step-by-step
GovCloud CLI runbook lives in **[`../deploy/deploy-aws-govcloud.md`](../deploy/deploy-aws-govcloud.md)**
and is authoritative for exact commands.

> Sibling guides: [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AIRGAPPED.md](AIRGAPPED.md)

---

## 1. Deployment architecture

AEGIS ships as one container (PHP 8.3 / Apache on **:8080**, health `/healthz`+
`/readyz`). On AWS you run:

| AWS resource | AEGIS role |
|--------------|------------|
| ECS Fargate service **or** EKS Deployment | runs the `aegis` web container (2+ tasks/replicas) |
| ECS/EKS worker (1 task) | runs the scheduled scripts (`run_workflows`, `dispatch_webhooks`, notifications, reports, metrics, email queue) |
| **RDS PostgreSQL 16** (Multi-AZ) | primary datastore; schema `aegis` |
| **S3** (SSE-KMS, versioned, TLS-only) | evidence/upload object storage (`s3` storage driver) |
| **Secrets Manager** | `JWT_SECRET`, `AUDIT_HMAC_KEY`, `APP_ENCRYPTION_KEY`, `DB_PASS`, `SMTP_*`, AI key |
| **KMS** | CMKs for RDS, S3, EFS, Secrets Manager |
| **ALB** (+ WAF) | HTTPS ingress → app :8080; target-group health check on `/healthz` |
| **ECR** | image registry (scan-on-push, immutable tags) |
| **CloudWatch / CloudTrail / GuardDuty / Config** | logs, alarms, API audit, compliance |
| **EFS** (optional) | only if you keep the local storage driver across tasks; **prefer S3** and skip EFS |

## 2. Topology

```
Internet ─HTTPS─► ALB (443, WAF) ──► Target Group (/healthz) ──► ECS Fargate / EKS
                                                                   │  aegis web :8080 (x2+)
                                                                   │  aegis worker  x1
                    private subnets (3 AZ)                         ▼
   ┌───────────────────────────────────────────────────────────────────────────┐
   │  RDS PostgreSQL 16 (Multi-AZ, SSE-KMS, force_ssl)                          │
   │  S3 bucket (SSE-KMS, versioning, BlockPublicAccess, DenyNonHTTPS)          │
   │  Secrets Manager (all secrets)   KMS CMKs   CloudWatch Logs   VPC endpoints│
   └───────────────────────────────────────────────────────────────────────────┘
Identity: ECS task role / IRSA ServiceAccount → GetSecretValue, S3 R/W, KMS decrypt,
          (optional) rds-db:connect for RDS IAM auth.
```

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| AWS CLI v2 | 2.x | GovCloud endpoint support; use a `--profile govcloud` |
| Docker | 24+ | build/push the image |
| jq | 1.6+ | parse JSON |
| eksctl / kubectl / Helm | latest | EKS path only |
| psql | 16 | migrations via bastion/SSM |

Accounts/quotas: a Commercial account **or** a GovCloud (US) account (separate signup,
linked commercial account required). Confirm region service coverage:
`us-east-1`/`us-west-2` (Commercial) or `us-gov-west-1`/`us-gov-east-1` (GovCloud —
`us-gov-west-1` has the broadest coverage). Raise Fargate/RDS/ENI quotas as needed.

## 4. Identity & credentials (prefer IAM roles / IRSA — no static keys)

**Static AWS keys are a fallback only.** AEGIS's S3 client uses SigV4; on AWS you avoid
committing `S3_ACCESS_KEY`/`S3_SECRET_KEY` by attaching an IAM role to the workload and
letting the credential chain resolve.

- **ECS Fargate:** `taskRoleArn` (app permissions) + `executionRoleArn` (pull image,
  read secrets, write logs).
- **EKS:** IRSA — associate an IAM role with the pod's ServiceAccount via an OIDC
  provider (`eksctl create iamserviceaccount …`).

Least-privilege **task/IRSA role** policy (Commercial ARNs shown; swap `aws` →
`aws-us-gov` for GovCloud):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ReadSecrets", "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": ["arn:aws:secretsmanager:REGION:ACCT:secret:aegis/*"] },
    { "Sid": "DecryptKeys", "Effect": "Allow",
      "Action": ["kms:Decrypt","kms:GenerateDataKey"],
      "Resource": ["arn:aws:kms:REGION:ACCT:key/RDS_KEY_ID",
                   "arn:aws:kms:REGION:ACCT:key/S3_KEY_ID"] },
    { "Sid": "UploadsBucket", "Effect": "Allow",
      "Action": ["s3:GetObject","s3:PutObject","s3:DeleteObject","s3:ListBucket"],
      "Resource": ["arn:aws:s3:::aegis-uploads-ACCT-REGION",
                   "arn:aws:s3:::aegis-uploads-ACCT-REGION/*"] }
  ]
}
```
Add `"rds-db:connect"` on `arn:aws:rds-db:REGION:ACCT:dbuser:DBI/aegis_app` if you use
**RDS IAM database authentication** (no DB password at all — recommended).

**Execution role** needs `AmazonECSTaskExecutionRolePolicy` plus
`secretsmanager:GetSecretValue` + `kms:Decrypt` for the secret CMK.

> **GovCloud partition note:** every ARN must use `arn:aws-us-gov:` — commercial
> `arn:aws:` ARNs are rejected. STS, KMS, S3, and Secrets Manager use the
> `*.us-gov-west-1.amazonaws.com` regional endpoints; use **FIPS endpoints**
> (`*-fips.us-gov-west-1.amazonaws.com`) where FedRAMP/DoD mandates FIPS 140-2/3
> validated crypto.

## 5. Environment variables — Commercial vs GovCloud

Set non-secret values as ECS `environment` / EKS env; inject secrets via ECS `secrets`
(`valueFrom` a Secrets Manager ARN) or EKS `*_FILE` from a Secrets Store CSI mount.

| Variable | Commercial example | GovCloud example | Purpose |
|----------|--------------------|--------------------|---------|
| `APP_ENV` | `production` | `production` | prod hardening + HSTS |
| `APP_URL` | `https://grc.example.com` | `https://grc.agency.gov` | canonical URL / redirect allowlist |
| `DB_HOST` | `aegis-db.abc.us-east-1.rds.amazonaws.com` | `aegis-db.xyz.us-gov-west-1.rds.amazonaws.com` | RDS endpoint |
| `DB_PORT` / `DB_NAME` / `DB_USER` | `5432` / `aegis` / `aegis_app` | same | connection |
| `DB_PASS` (secret) | from `aegis/db` | from `aegis/db` (`aws-us-gov` ARN) | DB password (or use RDS IAM auth) |
| `JWT_SECRET` (secret) | from `aegis/jwt` | from `aegis/jwt` | auth token signing |
| `AUDIT_HMAC_KEY` (secret) | from `aegis/keys` | from `aegis/keys` | audit hash chain |
| `APP_ENCRYPTION_KEY` (secret) | from `aegis/keys` | from `aegis/keys` | settings encryption at rest |
| `ADMIN_EMAIL`/`ADMIN_PASSWORD` | migration task only | migration task only | first admin seed |
| `SMTP_HOST` | `email-smtp.us-east-1.amazonaws.com` (SES) | `email-smtp.us-gov-west-1.amazonaws.com` | mail relay |
| `SMTP_PORT`/`SMTP_USER`/`SMTP_PASS`/`SMTP_FROM` | 587 / SES creds | 587 / SES creds | mail auth |
| `TRUSTED_PROXY_IPS` | ALB subnet CIDRs | same | trust `X-Forwarded-*` |
| `SESSION_DRIVER` | `pg` | `pg` | shared sessions for >1 task |

**S3 storage is configured in the app's `settings` table (Admin → Storage), not env
vars.** Set these settings keys:

| Setting key (settings table) | Commercial | GovCloud |
|------------------------------|-----------|----------|
| `storage_driver` | `s3` | `s3` |
| `s3_bucket` | `aegis-uploads-ACCT-us-east-1` | `aegis-uploads-ACCT-us-gov-west-1` |
| `s3_region` | `us-east-1` | `us-gov-west-1` |
| `s3_endpoint` | *(blank → `https://s3.us-east-1.amazonaws.com`)* | `https://s3.us-gov-west-1.amazonaws.com` (or `-fips`) |
| `s3_access_key`/`s3_secret_key` | *(leave blank when using an IAM role via a proxy; else static keys)* | same |

> **Storage & IAM roles:** `src/Storage.php` signs SigV4 with the access/secret keys in
> the `settings` table. To use an IAM **role** (no static keys), front S3 with a small
> sidecar/gateway that adds the instance role's credentials, or provision scoped-down
> static keys via Secrets Manager and load them into the settings during bootstrap.
> The bucket must enforce SSE-KMS + `aws:SecureTransport` and block public access.

## 6. Configuration references

| Setting | Where | Purpose |
|---------|-------|---------|
| ALB health check | Target group `/healthz`, 200–299 | task health |
| RDS `rds.force_ssl=1` | parameter group | require TLS to DB |
| S3 bucket policy `DenyNonHTTPS` + SSE-KMS + versioning + BlockPublicAccess | S3 | data protection |
| Immutable ECR tags + scan-on-push | ECR | supply-chain integrity |
| WAF `AWSManagedRulesCommonRuleSet` | ALB | perimeter filtering |
| Session/upload/rate-limit tuning | `config/app.php`, `.htaccess` (55M) | app behavior |
| Branding | `settings` table (Admin → Branding) | per-org logo/name/accent |

## 7. Deploy & migrate

1. **Build + push** to ECR (see govcloud runbook §4). Tag by git SHA; `latest` fails on
   immutable repos after the first push.
2. **Provision** RDS, S3, Secrets Manager, KMS, ALB, ECR (CLI in
   `../deploy/deploy-aws-govcloud.md`, or EKS via `deploy/k8s/aegis.yaml`).
3. **Migrate** — run a one-shot ECS task / EKS Job with `command: php install.php` and
   `ADMIN_EMAIL`/`ADMIN_PASSWORD` set, using the DB **owner** role (DDL). `install.php`
   applies `schema.sql` + all migrations idempotently and seeds the admin. Verify with
   `php scripts/verify_migrations.php`.
4. **Run** the web service (2+ tasks, ALB attached) and the worker service (1 task,
   the scheduled-script loop). On ECS use the `secrets` block; on EKS use the hardened
   image + `*_FILE` secret mounts.

## 8. Verification

```bash
B=https://grc.example.com   # or https://grc.agency.gov (GovCloud)
# 1. Liveness / readiness via the ALB
curl -fsS $B/healthz          # {"status":"ok",...}
curl -fsS $B/readyz           # {"status":"ready","checks":{"database":"ok"}}
# 2. Secrets resolved — exec into a task (ECS exec / kubectl exec) and verify audit key
aws ecs execute-command --cluster aegis --task <id> --container app --interactive \
  --command "php /var/www/html/scripts/verify_audit_log.php"     # exit 0 = chain intact
# 3. Login (CSRF-protected form)
JAR=$(mktemp); CSRF=$(curl -sc "$JAR" $B/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sb "$JAR" -i -X POST $B/login --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=$ADMIN_PASSWORD" | head -n1  # 302
# 4. Upload accepted + indexed (evidence_files row) — from a bastion/SSM psql session
psql "host=$DB_HOST dbname=aegis user=aegis_app sslmode=require" -c \
  "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash FROM evidence_files ORDER BY id DESC LIMIT 1;"
# 5. Object written to S3
aws s3api list-objects-v2 --bucket aegis-uploads-ACCT-REGION --prefix uploads/evidence/ \
  --query 'reverse(sort_by(Contents,&LastModified))[0]'    # newest object = write confirmed
```

## 9. Day-2 operations

- **Upgrade (ECS):** push new image, register a new task-def revision, `aws ecs
  update-service` — circuit breaker rolls back on failure; run the migration task
  first. **EKS:** `set image` after the migration Job.
- **Scaling:** ECS Service Auto Scaling / EKS HPA on CPU/memory (target 70%). Requires
  `SESSION_DRIVER=pg` and the S3 storage driver so tasks are stateless.
- **Backups:** RDS automated backups (35-day retention) + snapshots; S3 versioning +
  cross-region replication for evidence; Secrets Manager is the source of truth for
  keys — **never lose `APP_ENCRYPTION_KEY`** (encrypted settings become unrecoverable).
- **Secret rotation:** rotate the Secrets Manager secret; redeploy tasks to pick up new
  values. Rotating `AUDIT_HMAC_KEY` invalidates verification of pre-rotation audit rows
  — keep the old key. Use KMS key rotation on the CMKs.
- **Certs:** ACM auto-renews the ALB cert (DNS validation).
- **Logs/observability:** CloudWatch Logs `/aegis/app` + `/aegis/cron`, alarms on
  ALB 5xx, CPU/mem, RDS memory; CloudTrail for API audit; GuardDuty/Config for the
  FedRAMP baseline. Schedule `verify_audit_log.php` and alert on failure.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Target group unhealthy | health check path wrong | point it at `/healthz` (not `/health`); AEGIS uses `/healthz`+`/readyz` |
| Task can't read secret | execution/task role lacks `GetSecretValue` or KMS `Decrypt` | add both; on GovCloud use `aws-us-gov` ARNs |
| `AccessDenied` on S3 | bucket policy / role scope / wrong partition ARN | grant `s3:*Object`+`ListBucket`; verify `arn:aws-us-gov:` on GovCloud |
| DB connect fails | SG blocks 5432 / no TLS / wrong endpoint | open app-SG→db-SG:5432; `sslmode=require`; check RDS endpoint |
| Random logouts across tasks | file sessions | set `SESSION_DRIVER=pg` |
| GovCloud CLI rejects ARN | commercial `arn:aws:` used | switch to `arn:aws-us-gov:` and gov regional/FIPS endpoints |
| Migration task fails on DDL | ran as least-privilege `aegis_app` | run migration as the DB owner role |
| Emails not sent | SES sandbox / SMTP creds / egress | verify SES identities/limits; open 587 egress; check `drain_email_queue.php` |
| FIPS compliance finding | non-FIPS endpoints in a FIPS-mandated boundary | use `*-fips.us-gov-west-1.amazonaws.com` endpoints |
