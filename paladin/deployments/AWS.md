# PALADIN — AWS Deployment (Commercial + GovCloud)

Operator guide for deploying PALADIN on AWS, covering both **AWS Commercial** and
**AWS GovCloud (US)**. Hosting shapes: **ECS/Fargate** (simplest) and **EKS**
(see also [KUBERNETES.md](KUBERNETES.md)). Managed services: **RDS for
PostgreSQL**, **S3**, **Secrets Manager**, **KMS**, with **IAM roles / IRSA** —
no static keys.

Explicit **partition** and **endpoint** differences for GovCloud (`aws-us-gov`)
are called out throughout.

Related: [AZURE.md](AZURE.md) · [KUBERNETES.md](KUBERNETES.md) · [../docs/SECURITY.md](../docs/SECURITY.md) · [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)

---

## 1. Deployment architecture

- **Compute**: PALADIN image (`php:8.3-apache`) on **ECS Fargate** behind an
  **ALB** (TLS via ACM), or on **EKS**. `startup.sh` binds Apache to `$PORT`
  (set to `80` for Fargate), runs `install.php` migrations, then serves.
- **Database**: **RDS for PostgreSQL 16**, Multi-AZ, private subnets, TLS
  (`rds.force_ssl=1`). App connects via `DATABASE_URL` from Secrets Manager.
- **Storage**: **S3** via PALADIN's built-in S3 SigV4 driver
  (`STORAGE_DRIVER=s3`). Presigned download URLs are generated in-app.
- **Secrets**: **Secrets Manager** (+ **KMS** CMK) inject `JWT_SECRET`,
  `DATABASE_URL`, admin creds into the task via `secrets:` valueFrom.
- **Identity**: **IAM task role** (ECS) / **IRSA** (EKS) grants S3 + Secrets +
  KMS with least privilege. No `S3_ACCESS_KEY`/`S3_SECRET_KEY` needed when a role
  is attached and the S3 endpoint uses the instance/task role. *(If the built-in
  static-key S3 driver is used instead, keep those keys in Secrets Manager.)*
- **SSO**: SAML (`/saml/*`) / OIDC (`/oidc/*`) to your IdP; SCIM at `/scim/`.
- **Jobs**: **EventBridge Scheduler → ECS RunTask** (or K8s CronJob) for
  `cli/send_digests.php` and `cli/send_review_reminders.php`.

## 2. Topology

```
   IdP ──SAML/OIDC──►  /saml/* /oidc/*         SCIM ► /scim/
        │
   Route53 ──► ALB (ACM TLS, WAF) :443
                    │
        ┌───────────▼────────────┐   Task Role / IRSA (no static keys)
        │ ECS Fargate: paladin    │──Secrets Manager──► JWT_SECRET, DATABASE_URL
        │ Apache :80 (PORT=80)    │──KMS decrypt (CMK)
        └───┬───────────────┬─────┘
            │ TLS PDO         │ S3 SigV4 (presign)
      ┌─────▼──────┐   ┌──────▼─────────┐
      │ RDS Pg 16  │   │ S3 bucket      │  SSE-KMS, versioning, Block Public Access
      │ Multi-AZ   │   │ uploads/*      │
      └────────────┘   └────────────────┘
   EventBridge Scheduler ─► RunTask: send_digests / send_review_reminders
   Partition: Commercial = aws  |  GovCloud = aws-us-gov
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| AWS CLI | v2; profile for the target partition |
| Permissions | Create ECS/EKS, RDS, S3, IAM, Secrets Manager, KMS, ALB |
| ECR | `paladin` image pushed (partition-appropriate registry) |
| VPC | Private subnets for RDS + tasks, NAT/endpoints for S3/Secrets |
| ACM cert | For the ALB host |
| GovCloud | An `aws-us-gov` account + region (`us-gov-west-1`/`us-gov-east-1`) |

## 4. Identity & credentials

**Least-privilege task role (ECS)** — grants S3 to the uploads bucket, Secrets
read, and KMS decrypt. Replace the partition (`aws` → `aws-us-gov`), account, and
region for GovCloud.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "S3Uploads", "Effect": "Allow",
      "Action": ["s3:PutObject","s3:GetObject","s3:DeleteObject","s3:ListBucket"],
      "Resource": [
        "arn:aws:s3:::paladin-uploads",
        "arn:aws:s3:::paladin-uploads/*"
      ] },
    { "Sid": "Secrets", "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:aws:secretsmanager:us-east-1:1234567890:secret:paladin/*" },
    { "Sid": "KmsDecrypt", "Effect": "Allow",
      "Action": ["kms:Decrypt","kms:GenerateDataKey"],
      "Resource": "arn:aws:kms:us-east-1:1234567890:key/<cmk-id>" }
  ]
}
```

- **GovCloud**: every ARN uses partition `arn:aws-us-gov:…`; regions
  `us-gov-west-1` / `us-gov-east-1`.
- **EKS/IRSA**: attach the same policy to an IAM role federated to the pod
  ServiceAccount (see [KUBERNETES.md](KUBERNETES.md)).

## 5. Environment variables

| Variable | Commercial example | GovCloud example | Purpose |
|---|---|---|---|
| `APP_URL` | `https://paladin.example.com` | `https://paladin.example.gov` | Base URL |
| `JWT_SECRET` | *(Secrets Manager)* | *(Secrets Manager)* | Token signing (**required**) |
| `DATABASE_URL` | `…@x.rds.amazonaws.com:5432/paladin` | `…@x.rds.us-gov-west-1.amazonaws.com:5432/paladin` | RDS endpoint (gov region host) |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(Secrets Manager)* | *(Secrets Manager)* | First-run admin |
| `APP_ENV` | `production` | `production` | Prod behavior |
| `STORAGE_DRIVER` | `s3` | `s3` | Use S3 for uploads |
| `S3_BUCKET` | `paladin-uploads` | `paladin-uploads-gov` | Uploads bucket (also settable in Admin → Settings) |
| `S3_REGION` | `us-east-1` | `us-gov-west-1` | SigV4 region |
| `S3_ENDPOINT` | *(default `s3.us-east-1.amazonaws.com`)* | `https://s3.us-gov-west-1.amazonaws.com` or FIPS `https://s3-fips.us-gov-west-1.amazonaws.com` | Regional/FIPS endpoint |
| `TRUSTED_PROXY_IPS` | ALB subnet CIDR | ALB subnet CIDR | Trust `X-Forwarded-Proto` |
| `MAIL_TRANSPORT` | `smtp` (SES) | `smtp` (SES gov) | Delivery vs outbox |
| `PORT` | `80` | `80` | Apache listen port on Fargate |

**Endpoint/partition notes (GovCloud):**
- STS: `https://sts.us-gov-west-1.amazonaws.com` (regional).
- KMS: `https://kms.us-gov-west-1.amazonaws.com`; **FIPS**:
  `kms-fips.us-gov-west-1.amazonaws.com`.
- S3: `s3.us-gov-west-1.amazonaws.com`; **FIPS**:
  `s3-fips.us-gov-west-1.amazonaws.com` — set as `S3_ENDPOINT` for FIPS-required
  environments.
- Secrets Manager: `secretsmanager.us-gov-west-1.amazonaws.com`.

## 6. Configuration references

| Setting (location) | Example | Purpose |
|---|---|---|
| ALB target group health check | `/health` (200) | Deep DB-aware check |
| ALB idle timeout / body size | ≥ upload time; 40 MB | Attachment uploads |
| RDS `rds.force_ssl` | `1` | Enforce TLS to DB |
| S3 bucket policy | Block Public Access ON; SSE-KMS | Private uploads, encrypted |
| S3 lifecycle | versioning + noncurrent expiry | Ties to attachment versioning + DR |
| `s3_bucket`/`s3_region`/`s3_endpoint` (Admin → Settings) | as above | Overrides env; secret_key encrypted at rest |

## 7. Verification

```bash
APP=https://paladin.example.gov          # or .com commercial

# Health via ALB
curl -fsS $APP/health     # {"status":"healthy","service":"paladin","checks":{"database":"ok"}}
curl -fsS $APP/healthz    # {"status":"ok"}

# Secrets resolved (Secrets Manager → install.php)
aws logs tail /ecs/paladin --region us-gov-west-1 | grep "Installation complete"

# Login / SSO: browse $APP/login → SAML/OIDC → authenticated session
curl -si $APP/scim/v2/Users | head -1     # SCIM endpoint reachable (401 without token)

# DB rows (via bastion / RDS proxy)
psql "host=x.rds.us-gov-west-1.amazonaws.com dbname=paladin user=paladin sslmode=require" -c \
  "SET search_path TO paladin; SELECT email FROM users WHERE role='admin';"

# Upload accepted + S3 object written: attach a file in the UI, then
psql "$PGCONN" -c "SET search_path TO paladin; SELECT original_name, stored_path, is_current FROM attachments ORDER BY id DESC LIMIT 1;"
aws s3 ls s3://paladin-uploads-gov/uploads/attachments/ --region us-gov-west-1 \
  --endpoint-url https://s3-fips.us-gov-west-1.amazonaws.com | tail

# Page + hash-chained audit row
psql "$PGCONN" -c "SET search_path TO paladin; SELECT title,status FROM pages ORDER BY id DESC LIMIT 1;"
psql "$PGCONN" -c "SET search_path TO paladin; SELECT action, log_hash IS NOT NULL AS chained FROM activity_log ORDER BY id DESC LIMIT 1;"
```

## 8. Day-2 operations

| Task | How |
|---|---|
| Deploy | Push image to ECR, update ECS service (new task def revision) — migrations run on task start |
| Migrations | Automatic, idempotent, tracked in `schema_migrations` |
| Scale | ECS service desired count / EKS HPA; S3 storage shared (no sticky state) |
| Rotate `JWT_SECRET` | New Secrets Manager version → force new deployment; invalidates sessions/tokens |
| Rotate DB creds | Secrets Manager rotation → new task revision |
| Rotate KMS | KMS key rotation (annual auto or manual); re-encrypt S3 as needed |
| Backups | RDS automated backups + snapshots (Multi-AZ); S3 versioning + cross-region/cross-partition copy for DR |
| Logs | CloudWatch Logs (`/ecs/paladin`); metrics + alarms on `/health` |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Task fails health, ALB 503 | DB unreachable / SG | Open task→RDS 5432 SG; check `DATABASE_URL`, `sslmode=require` |
| S3 `AccessDenied` | Task role missing S3 perms | Attach least-priv policy (correct partition ARN) |
| S3 `SignatureDoesNotMatch` in gov | Wrong region/endpoint | Set `S3_REGION=us-gov-west-1` + gov/FIPS `S3_ENDPOINT` |
| Secrets `AccessDeniedException` | Role lacks `secretsmanager:GetSecretValue`/KMS decrypt | Add both to task role |
| 413 on upload | ALB/target body limit | Raise limit; ensure PHP `post_max_size` 40M (baked in) |
| Wrong partition ARNs | Commercial ARNs in GovCloud | Replace `arn:aws:` → `arn:aws-us-gov:` everywhere |
| SSO redirect to wrong host | `APP_URL` mismatch | Set `APP_URL` to the ALB/Route53 host; re-import IdP metadata |
