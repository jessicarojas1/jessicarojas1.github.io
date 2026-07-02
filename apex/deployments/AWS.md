# APEX — AWS Deployment (Commercial + GovCloud)

Operator guide for running **APEX** on AWS, covering both **AWS Commercial**
(partition `aws`) and **AWS GovCloud (US)** (partition `aws-us-gov`). APEX is a
stateless PHP 8.2 + Apache container (the shipped `apex/Dockerfile`) serving a
vanilla-JS SPA and `/api/*` REST API on port **8080** as non-root `www-data`,
backed by PostgreSQL 16. Auth is CAC/PIV-simulated (bcrypt PINs + HS256 JWT).

Two supported shapes: **ECS Fargate** (recommended, simplest) or **EKS** (reuse
[KUBERNETES.md](KUBERNETES.md) with IRSA). Both use **RDS for PostgreSQL**,
**Secrets Manager**, **IAM roles** (no static keys), and **KMS**.

Related: [KUBERNETES](KUBERNETES.md) · [AZURE](AZURE.md) ·
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) · [AIRGAPPED](AIRGAPPED.md)

---

## 1. Deployment architecture

| AWS service | Role |
|-------------|------|
| ECR | Stores the `apex` image. |
| ECS Fargate service (or EKS) | Runs the stateless app task on 8080. |
| Application Load Balancer | TLS termination (ACM cert), health check on `/api/health`, forwards to task port 8080. |
| RDS for PostgreSQL 16 | All persistent state; Multi-AZ for HA. |
| Secrets Manager | `JWT_SECRET` and the DB credential/`DATABASE_URL`. |
| IAM task role + execution role | Pull image, read secrets — **no static keys**. |
| KMS | Encrypts RDS storage, Secrets Manager, ECR at rest. |
| CloudWatch Logs | Container stdout/stderr (Apache logs). |

APEX has no file-upload feature, so **no S3 bucket is required**. If you later
add object storage, use IRSA/task-role S3 access with a bucket policy scoped to
the app.

---

## 2. Topology

```
        Internet / GovCloud boundary
                  │ :443 (ACM cert)
                  ▼
            ┌───────────┐  ALB  health: GET /api/health → 200
            │    ALB    │  (public subnets)
            └─────┬─────┘
                  ▼  target group :8080
        ┌────────────────────┐   private subnets
        │ ECS Fargate task(s)│   task role (Secrets Manager read)
        │  apex :8080 (33)   │   readonly rootfs + tmpfs /tmp
        └─────────┬──────────┘
                  │ DATABASE_URL (from Secrets Manager)
                  ▼
        ┌────────────────────┐
        │ RDS PostgreSQL 16  │  Multi-AZ, KMS-encrypted
        │  (private subnets) │  SG: 5432 from task SG only
        └────────────────────┘
   Secrets Manager (JWT_SECRET, DB creds) ── KMS CMK
```

---

## 3. Prerequisites

| Item | Detail |
|------|--------|
| AWS account | Commercial **or** GovCloud (US) org |
| AWS CLI | v2, configured for the correct partition/region |
| Terraform/CDK (optional) | For repeatable infra |
| VPC | ≥2 AZs, public + private subnets, NAT for image pulls (or VPC endpoints) |
| ACM cert | For the ALB (in the same region/partition) |
| ECR repo | `apex` |
| Docker | Build/push the image |

---

## 4. Identity & credentials

**Use IAM roles exclusively.** Two roles:

- **Task execution role** (`ecsTaskExecutionRole`): pull from ECR, write
  CloudWatch Logs, and read the specific secrets for injection.
- **Task role**: runtime identity for the app (minimal — APEX only needs its
  env-injected secrets; no AWS SDK calls in-app today).

Least-privilege policy for reading APEX secrets (Commercial ARN shown; swap the
partition for GovCloud — see §5):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": [
        "arn:aws:secretsmanager:us-east-1:111122223333:secret:apex/JWT_SECRET-*",
        "arn:aws:secretsmanager:us-east-1:111122223333:secret:apex/DATABASE_URL-*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": "arn:aws:kms:us-east-1:111122223333:key/<cmk-id>"
    }
  ]
}
```

For **EKS**, use **IRSA**: annotate the app ServiceAccount with the role ARN and
let External Secrets Operator read Secrets Manager (see [KUBERNETES.md](KUBERNETES.md)).

Static IAM access keys are a documented fallback only for break-glass and must be
avoided in production.

---

## 5. Environment variables — Commercial vs GovCloud

App env is identical; only **ARNs, endpoints, partitions, and regions** differ.

### App variables (both partitions)

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:***@apex.<id>.<region>.rds.amazonaws.com:5432/apex?sslmode=require` | RDS connection; **`sslmode=require`** on AWS. Injected from Secrets Manager. |
| `JWT_SECRET` | 32+ random chars | HS256 signing key. From Secrets Manager. Fails closed if <32 in production. |
| `APP_ENV` | `production` | Secure cookie, no traces, fail-closed. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | Must be `0`. |

### Partition/endpoint differences

| Concern | AWS Commercial (`aws`) | AWS GovCloud (`aws-us-gov`) |
|---------|------------------------|-----------------------------|
| Secret ARN prefix | `arn:aws:secretsmanager:us-east-1:...` | `arn:aws-us-gov:secretsmanager:us-gov-west-1:...` |
| KMS ARN prefix | `arn:aws:kms:us-east-1:...` | `arn:aws-us-gov:kms:us-gov-west-1:...` |
| Region examples | `us-east-1`, `us-west-2` | `us-gov-west-1`, `us-gov-east-1` |
| RDS endpoint | `*.rds.amazonaws.com` | `*.rds.us-gov-west-1.amazonaws.com` |
| STS endpoint | `sts.amazonaws.com` (or regional) | `sts.us-gov-west-1.amazonaws.com` |
| FIPS endpoints | optional (`*-fips.*`) | **use FIPS endpoints** (e.g. `secretsmanager-fips.us-gov-west-1.amazonaws.com`, `kms-fips.us-gov-west-1.amazonaws.com`) |
| ACM/ALB | standard | GovCloud regional ACM |

GovCloud guidance: pin `AWS_REGION`/CLI to a `us-gov-*` region, use the
`aws-us-gov` partition in **every** ARN, and prefer FIPS 140-validated service
endpoints for Secrets Manager, KMS, and STS.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| Container port | `8080` | Task/target-group port. |
| ALB health check | `/api/health` (matcher 200) | Returns `{"data":{"ok":true,...}}`. |
| Task CPU/memory | `256`/`512` (scale up as needed) | Fargate sizing. |
| RDS engine | `postgres` 16 | Match app expectations. |
| RDS param `rds.force_ssl` | `1` | Enforce TLS; pair with `sslmode=require`. |
| Log group | `/ecs/apex` | CloudWatch destination. |

Secrets → env injection (ECS task definition `secrets` block):

```json
"secrets": [
  { "name": "JWT_SECRET",   "valueFrom": "arn:aws:secretsmanager:us-east-1:111122223333:secret:apex/JWT_SECRET" },
  { "name": "DATABASE_URL", "valueFrom": "arn:aws:secretsmanager:us-east-1:111122223333:secret:apex/DATABASE_URL" }
]
```

---

## 7. Verification

```bash
ALB=apex.example.com   # ALB DNS or Route53 record

# Health via ALB
curl -s https://$ALB/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"..."}}

# Secrets resolved + login (bcrypt verify)
TOKEN=$(curl -s -X POST https://$ALB/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] && echo "login OK (JWT_SECRET from Secrets Manager resolved)"

# Confirm the secret is actually resolvable to the task role
aws secretsmanager get-secret-value --secret-id apex/JWT_SECRET \
  --query 'Name' --output text          # (GovCloud: add --region us-gov-west-1)

# Write a DB row (ticket) → proves API→PDO→RDS path
curl -s -X POST https://$ALB/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"aws smoke test","type":"task"}'

# Confirm persistence in RDS
psql "$DATABASE_URL" -c \
  "SELECT id,title FROM tickets ORDER BY created_at DESC LIMIT 1;"
```

Verify: ALB target healthy on `/api/health` ✓ · login token (secret resolved) ✓ ·
new `tickets` row in RDS ✓ · RDS/Secrets/ECR KMS-encrypted ✓.

---

## 8. Day-2 operations

| Task | Procedure |
|------|-----------|
| Deploy new version | Push image to ECR → `aws ecs update-service --force-new-deployment`. Rolling; ALB health-gates. |
| Migrations | Fresh DB seeds via boot migrate. For an existing DB, run a one-off Fargate task with `command: php scripts/migrate.php` or apply new SQL via `psql`. |
| Scale | `--desired-count N` or Service Auto Scaling on CPU/ALB request count. Stateless. |
| Backups | RDS automated backups + snapshots (KMS-encrypted); set retention ≥7 days; enable PITR. |
| Secret rotation | Rotate in Secrets Manager (Lambda rotation for DB creds); force new deployment to re-inject `JWT_SECRET`. |
| Certs | ACM auto-renews the ALB cert. |
| Logs/metrics | CloudWatch Logs + Container Insights; alarm on 5xx and unhealthy hosts. |
| DR | See `docs/DISASTER_RECOVERY.md` — Multi-AZ RDS + cross-region snapshot copy. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Target group unhealthy | Health path/port wrong | Health check must be `GET /api/health` on port 8080. |
| Task exits, log `JWT_SECRET is missing or too short` | Secret not injected / <32 chars | Verify task `secrets` block + task role `GetSecretValue`; set 32+ char value. |
| `AccessDenied` reading secret | Wrong partition ARN / policy | In GovCloud use `arn:aws-us-gov:...`; confirm KMS `Decrypt` on the CMK. |
| DB connection failed | SG blocks 5432 / `sslmode` mismatch | Allow task SG → RDS SG:5432; set `sslmode=require`; check `rds.force_ssl`. |
| Login `Invalid credentials` | Real PINs required; defaults off | Use seed PIN; defaults disabled at `APP_ENV=production`. |
| Image pull fails in private subnet | No NAT / no VPC endpoints | Add NAT gateway or ECR/S3/Logs/Secrets VPC endpoints (interface endpoints in GovCloud). |
| FIPS endpoint errors (GovCloud) | Non-FIPS endpoint used | Point clients at `*-fips.us-gov-west-1.amazonaws.com`. |
| Redirect loop | ALB not passing `X-Forwarded-Proto` | ALB sets it by default; ensure listener is HTTPS and app trusts it. |
