# AeroMarkup on AWS (Commercial + GovCloud)

Operator guide for deploying **AeroMarkup** to AWS. AeroMarkup is a stateless
Flask application (`server.py`) served by gunicorn on port **8080**, packaged as a
non-root (`uid 10001`) `python:3.12-slim` container that exposes a `GET /api/health`
endpoint. **All state lives in PostgreSQL** — reference images and STL/OBJ 3D models
are stored as `data:` URLs in Postgres columns (`aeromarkup.drawings.background_data`,
`aeromarkup.drawings.model_data`, `aeromarkup.attachments.data`); there is **no S3 /
object storage dependency**. The app owns a dedicated `aeromarkup` schema
(`search_path=aeromarkup,public`, safe to share a database) and, when `AUTO_MIGRATE=1`,
applies `db/schema.sql` at boot.

This guide covers **two deployment models**:

1. **ECS / Fargate** (primary) — matches the repo asset
   [`deploy/aws-govcloud/task-definition.json`](../deploy/aws-govcloud/task-definition.json)
   and [`deploy/aws-govcloud/deploy.sh`](../deploy/aws-govcloud/deploy.sh).
2. **EKS / IRSA** (alternative) — see [KUBERNETES.md](./KUBERNETES.md).

Both partitions — **AWS Commercial** (`aws`) and **AWS GovCloud (US)**
(`aws-us-gov`) — are covered side by side. See also
[../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md),
[../docs/SECURITY.md](../docs/SECURITY.md), and
[AIRGAPPED.md](./AIRGAPPED.md) for a fully offline installation.

---

## 1. Deployment architecture

| Concern | Choice |
| --- | --- |
| Compute | ECS Fargate task (`awsvpc`, `cpu 512` / `memory 1024`), image pulled from ECR. EKS + IRSA as alternative. |
| Ingress / TLS | Application Load Balancer (ALB) terminates TLS via an ACM certificate; target group health check hits `/api/health` on container port 8080. |
| Proxy awareness | ALB is a single hop in front of the app → set `TRUSTED_PROXY_HOPS=1` so client IPs and the secure-cookie/HTTPS determination are correct. |
| Database | Amazon RDS for PostgreSQL. AeroMarkup uses the `aeromarkup` schema only; a shared RDS instance is safe. |
| Secrets | AWS Secrets Manager holds `DATABASE_URL` and `AEROMARKUP_SECRET`, injected into the task as environment variables via the task **execution role**. |
| Identity | Task **execution role** (pull image, read secrets, write logs) + task **role** (app runtime; AeroMarkup needs no AWS APIs at runtime, so keep it empty/minimal). No static AWS keys in the container. |
| Encryption | Secrets Manager + RDS encrypted with a KMS CMK; the execution role is granted `kms:Decrypt` on that CMK. |
| Migrations | `AUTO_MIGRATE=1` applies `db/schema.sql` idempotently at container boot. |

The container is stateless: you can run 2+ replicas behind the ALB with no sticky
sessions (session state is a signed `am_session` cookie validated against Postgres).

---

## 2. Topology

```
                          Internet / VPC clients
                                   │  HTTPS (443)
                                   ▼
                    ┌────────────────────────────┐
                    │  Application Load Balancer  │  ACM cert, TLS terminate
                    │  target group :8080         │  health check GET /api/health
                    └──────────────┬──────────────┘
                                   │  HTTP :8080 (private subnets)
                    ┌──────────────▼──────────────┐
                    │   ECS Fargate service       │   desiredCount ≥ 2
                    │   task family "aeromarkup"   │   awsvpc, cpu 512 / mem 1024
                    │   gunicorn server:app        │   TRUSTED_PROXY_HOPS=1
                    │   image ← ECR                │
                    └───┬───────────────┬──────────┘
        execution role  │               │  awslogs
   Secrets Manager +KMS │               ▼
                        │        CloudWatch Logs  /ecs/aeromarkup
                        ▼
       ┌───────────────────────────┐          ┌────────────────────────────┐
       │ Secrets Manager           │          │ Amazon RDS for PostgreSQL   │
       │  aeromarkup/database-url  │──────────│  schema "aeromarkup"        │
       │  aeromarkup/session-secret│  DB URL  │  (data URLs stored in-DB)   │
       └───────────────────────────┘          └────────────────────────────┘
```

---

## 3. Prerequisites

- AWS CLI v2 configured for the target partition/region (a Commercial profile, or a
  GovCloud profile — GovCloud accounts are separate from Commercial).
- Docker (for `deploy.sh` local builds) or a CI runner with ECR push rights.
- A VPC with **private subnets** for tasks + RDS and **public subnets** for the ALB;
  NAT (or VPC endpoints) so tasks can reach ECR, Secrets Manager, CloudWatch Logs.
- An **ECR repository** named `aeromarkup`.
- An **ECS cluster** and **service** (defaults used by `deploy.sh`:
  `ECS_CLUSTER=aeromarkup-cluster`, `ECS_SERVICE=aeromarkup-svc`).
- An **RDS for PostgreSQL** instance reachable from the task subnets.
- A **KMS CMK** for encrypting the secrets and RDS (or use the AWS-managed key and
  drop the explicit `kms:Decrypt` statement — a CMK is recommended for GovCloud).
- An **ACM certificate** for the ALB listener.
- The two secrets pre-created in Secrets Manager (see §4).

---

## 4. Identity & credentials

**Prefer IAM roles — never bake static access keys into the image or task.** The
container runs with no AWS credentials of its own; Fargate/ECS provides temporary
credentials for the **task role**, and the ECS agent uses the **execution role** to
pull the image and resolve secrets before the container starts.

### Create the secrets (once per partition)

Commercial:

```bash
aws secretsmanager create-secret --name aeromarkup/database-url \
  --secret-string 'postgresql://aeromarkup:CHANGEME@aeromarkup.abcd.us-east-1.rds.amazonaws.com:5432/aeromarkup?sslmode=require' \
  --kms-key-id alias/aeromarkup --region us-east-1
aws secretsmanager create-secret --name aeromarkup/session-secret \
  --secret-string "$(openssl rand -hex 32)" \
  --kms-key-id alias/aeromarkup --region us-east-1
```

GovCloud (same commands, `--region us-gov-west-1`, host suffix
`...us-gov-west-1.rds.amazonaws.com`).

### Execution-role trust policy (both partitions)

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Service": "ecs-tasks.amazonaws.com" },
    "Action": "sts:AssumeRole"
  }]
}
```

### Least-privilege execution-role policy — **Commercial** (partition `aws`)

Grants only: read the two secrets, decrypt them with the CMK, pull from ECR, write
logs. Nothing else.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadAeroMarkupSecrets",
      "Effect": "Allow",
      "Action": "secretsmanager:GetSecretValue",
      "Resource": [
        "arn:aws:secretsmanager:us-east-1:<ACCOUNT_ID>:secret:aeromarkup/database-url-*",
        "arn:aws:secretsmanager:us-east-1:<ACCOUNT_ID>:secret:aeromarkup/session-secret-*"
      ]
    },
    {
      "Sid": "DecryptWithCMK",
      "Effect": "Allow",
      "Action": "kms:Decrypt",
      "Resource": "arn:aws:kms:us-east-1:<ACCOUNT_ID>:key/<CMK_KEY_ID>"
    },
    {
      "Sid": "PullImage",
      "Effect": "Allow",
      "Action": ["ecr:GetAuthorizationToken", "ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"],
      "Resource": "*"
    },
    {
      "Sid": "WriteLogs",
      "Effect": "Allow",
      "Action": ["logs:CreateLogStream", "logs:PutLogEvents"],
      "Resource": "arn:aws:logs:us-east-1:<ACCOUNT_ID>:log-group:/ecs/aeromarkup:*"
    }
  ]
}
```

> `ecr:GetAuthorizationToken` requires `Resource: "*"` (it is an account-level
> action); the pull actions can be scoped to the repo ARN if you prefer.

### Least-privilege execution-role policy — **GovCloud** (partition `aws-us-gov`)

Identical shape; **every ARN uses `arn:aws-us-gov:`** and `us-gov-west-1`. This
matches the execution role referenced in
[`deploy/aws-govcloud/task-definition.json`](../deploy/aws-govcloud/task-definition.json)
(`arn:aws-us-gov:iam::<ACCOUNT_ID>:role/aeromarkupExecutionRole`).

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadAeroMarkupSecrets",
      "Effect": "Allow",
      "Action": "secretsmanager:GetSecretValue",
      "Resource": [
        "arn:aws-us-gov:secretsmanager:us-gov-west-1:<ACCOUNT_ID>:secret:aeromarkup/database-url-*",
        "arn:aws-us-gov:secretsmanager:us-gov-west-1:<ACCOUNT_ID>:secret:aeromarkup/session-secret-*"
      ]
    },
    {
      "Sid": "DecryptWithCMK",
      "Effect": "Allow",
      "Action": "kms:Decrypt",
      "Resource": "arn:aws-us-gov:kms:us-gov-west-1:<ACCOUNT_ID>:key/<CMK_KEY_ID>"
    },
    {
      "Sid": "PullImage",
      "Effect": "Allow",
      "Action": ["ecr:GetAuthorizationToken", "ecr:BatchCheckLayerAvailability", "ecr:GetDownloadUrlForLayer", "ecr:BatchGetImage"],
      "Resource": "*"
    },
    {
      "Sid": "WriteLogs",
      "Effect": "Allow",
      "Action": ["logs:CreateLogStream", "logs:PutLogEvents"],
      "Resource": "arn:aws-us-gov:logs:us-gov-west-1:<ACCOUNT_ID>:log-group:/ecs/aeromarkup:*"
    }
  ]
}
```

### Task role

AeroMarkup makes **no AWS API calls at runtime** (state is Postgres + client
IndexedDB). Keep the task role empty (assume-role trust only) or omit it. The GovCloud
task definition still references `aeromarkupTaskRole` for consistency.

### EKS / IRSA alternative

On EKS, replace the execution role with an **IRSA** service account annotated with an
IAM role bound via the cluster OIDC provider; the same least-privilege policy above
applies to that role. See [KUBERNETES.md](./KUBERNETES.md).

---

## 5. Environment variables

The app reads its configuration from environment variables. `DATABASE_URL` and
`AEROMARKUP_SECRET` come **from Secrets Manager** (the `secrets` block in the task
definition); the rest are plain `environment` entries.

| Variable | Example | Purpose |
| --- | --- | --- |
| `DATABASE_URL` | *(from Secrets Manager)* `postgresql://user:pw@host:5432/aeromarkup?sslmode=require` | Postgres DSN. **Injected from `aeromarkup/database-url`.** |
| `AEROMARKUP_SECRET` | *(from Secrets Manager)* 32+ hex chars | Session/CSRF signing key. **REQUIRED in production when `DATABASE_URL` is set.** Injected from `aeromarkup/session-secret`. |
| `PORT` | `8080` | gunicorn bind port; must match ALB target group + container port. |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` idempotently at boot. |
| `ENVIRONMENT` | `production` | Enables production hardening (secure cookies, mandatory secret). |
| `TRUSTED_PROXY_HOPS` | `1` | Number of trusted proxies in front (ALB = 1) for correct client IP / HTTPS detection. |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime. Optional. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed-login lockout threshold. Optional. |
| `LOGIN_WINDOW_SECONDS` | `900` | Lockout window. Optional. |
| `LOGIN_MAX_TRACKED` | `1024` | Max distinct login identities tracked for throttling. Optional. |

### Partition-specific values — **Commercial vs GovCloud**

| Setting | AWS Commercial (`aws`) | AWS GovCloud (`aws-us-gov`) |
| --- | --- | --- |
| Example region | `us-east-1` | `us-gov-west-1` (also `us-gov-east-1`) |
| ARN partition | `arn:aws:...` | `arn:aws-us-gov:...` |
| ECR image host | `<ACCOUNT_ID>.dkr.ecr.us-east-1.amazonaws.com/aeromarkup:latest` | `<ACCOUNT_ID>.dkr.ecr.us-gov-west-1.amazonaws.com/aeromarkup:latest` |
| RDS host suffix | `...us-east-1.rds.amazonaws.com` | `...us-gov-west-1.rds.amazonaws.com` |
| Secrets Manager endpoint | `secretsmanager.us-east-1.amazonaws.com` | `secretsmanager.us-gov-west-1.amazonaws.com` (FIPS: `secretsmanager-fips.us-gov-west-1.amazonaws.com`) |
| STS endpoint | `sts.us-east-1.amazonaws.com` | `sts.us-gov-west-1.amazonaws.com` (FIPS: `sts.us-gov-west-1.amazonaws.com` / `-fips`) |
| KMS endpoint | `kms.us-east-1.amazonaws.com` | `kms.us-gov-west-1.amazonaws.com` (FIPS: `kms-fips.us-gov-west-1.amazonaws.com`) |
| CloudWatch Logs group | `/ecs/aeromarkup` | `/ecs/aeromarkup` |
| FIPS 140 | Optional (FIPS endpoints available) | **Use FIPS endpoints** to meet FedRAMP/DoD requirements |

The container config itself is partition-agnostic; only the ARNs, hosts, and endpoints
in the task definition / IAM policy differ. The GovCloud task definition already uses
`us-gov-west-1` and `arn:aws-us-gov:` throughout.

---

## 6. Configuration references

| Reference | Example / value | Purpose |
| --- | --- | --- |
| Task definition | [`deploy/aws-govcloud/task-definition.json`](../deploy/aws-govcloud/task-definition.json) | ECS Fargate task: `awsvpc`, cpu 512 / mem 1024, container port 8080, secrets + awslogs. Copy and swap partition/region for Commercial. |
| Deploy script | [`deploy/aws-govcloud/deploy.sh`](../deploy/aws-govcloud/deploy.sh) | Build → ECR login → push → register task def → `update-service --force-new-deployment`. Env: `AWS_REGION` (default `us-gov-west-1`), `ECS_CLUSTER` (default `aeromarkup-cluster`), `ECS_SERVICE` (default `aeromarkup-svc`). |
| Dockerfile | [`../Dockerfile`](../Dockerfile) | `python:3.12-slim`, non-root uid 10001, `EXPOSE 8080`, `HEALTHCHECK` curls `/api/health`, runs `gunicorn server:app --bind 0.0.0.0:$PORT --workers 2 --timeout 120`. |
| Schema | [`../db/schema.sql`](../db/schema.sql) | Idempotent schema applied when `AUTO_MIGRATE=1`. |
| ALB health check | Path `/api/health`, port `8080`, protocol HTTP, 200 expected | Target group health. |
| ACM certificate | `arn:aws[-us-gov]:acm:<region>:<ACCOUNT_ID>:certificate/<id>` | TLS on the ALB HTTPS listener. |

---

## 7. Verification

Run after the service reaches a steady state. `ALB_DNS` = the ALB DNS name (or your
CNAME).

**1. Health through the load balancer**

```bash
curl -fsS https://$ALB_DNS/api/health
# → {"status":"ok",...}  (HTTP 200)
```

**2. Bootstrap the first admin (first run only) + login**

```bash
# First-run: create the initial admin
curl -fsS -X POST https://$ALB_DNS/api/auth/bootstrap \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"CHANGEME-strong-pw","name":"Admin"}'

# Log in — capture the am_session / am_csrf cookies
curl -fsS -c cookies.txt -X POST https://$ALB_DNS/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"CHANGEME-strong-pw"}'
```

**3. Create a project (exercises a DB write)** — send the CSRF header from the cookie:

```bash
CSRF=$(awk '/am_csrf/{print $7}' cookies.txt)
curl -fsS -b cookies.txt -X POST https://$ALB_DNS/api/projects \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Verification Project"}'
```

**4. Confirm the row was written to RDS**

```bash
psql "$DATABASE_URL" -c 'SELECT count(*) FROM aeromarkup.projects;'
# → count ≥ 1
```

**5. Confirm the secret resolved from Secrets Manager into the container**

```bash
TASK=$(aws ecs list-tasks --cluster aeromarkup-cluster --service-name aeromarkup-svc \
  --query 'taskArns[0]' --output text --region <region>)
aws ecs execute-command --cluster aeromarkup-cluster --task "$TASK" \
  --container aeromarkup --interactive --command \
  '/bin/sh -c "test -n \"$AEROMARKUP_SECRET\" && test -n \"$DATABASE_URL\" && echo secrets-present"' \
  --region <region>
# → secrets-present   (values are injected, never printed)
```

> `execute-command` requires ECS Exec enabled on the service and `ssmmessages:*` on
> the task role; skip it and rely on a successful login (which proves the signing
> secret resolved) if Exec is disabled.

---

## 8. Day-2 operations

**Push a new image + roll out**

```bash
# From aeromarkup/ — GovCloud defaults; export AWS_REGION for Commercial
AWS_REGION=us-east-1 ECS_CLUSTER=aeromarkup-cluster ECS_SERVICE=aeromarkup-svc \
  ./deploy/aws-govcloud/deploy.sh
```

The script builds, pushes to ECR, re-registers the task definition, and forces a new
deployment. ECS drains old tasks after the new ones pass the `/api/health` check.

**Database migrations** — schema changes ship in `db/schema.sql` and apply
automatically at boot because `AUTO_MIGRATE=1`. To apply out-of-band, run
`psql "$DATABASE_URL" -f db/schema.sql` (idempotent). Keep `db/schema.sql` current with
every migration.

**Scaling** — increase the ECS service `desiredCount` (stateless; safe). Add ECS
service auto scaling on ALB `RequestCountPerTarget` or CPU. Scale the DB via RDS
instance class / storage; enable a read replica only if you add read-heavy reporting
(the app does not require one).

**Backups / snapshots** — enable RDS automated backups (PITR) and periodic manual
snapshots. Because reference images and 3D models live in Postgres columns, an RDS
backup captures **all** application state — there is no separate object store to back
up. See [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md).

**KMS / secret rotation** — rotate `aeromarkup/session-secret`
(`aws secretsmanager rotate-secret` or update the value) and force a new deployment so
tasks pick it up; existing sessions become invalid (users re-login). Rotate DB
credentials in `aeromarkup/database-url` the same way. KMS CMK rotation is transparent.

**Logs** — CloudWatch Logs group `/ecs/aeromarkup`, stream prefix `app`:

```bash
aws logs tail /ecs/aeromarkup --follow --region <region>
```

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Tasks cycle / ALB target unhealthy | App failing `/api/health`, or health check port ≠ 8080 | Check CloudWatch logs; confirm target group port 8080 and path `/api/health`. |
| Task stops at startup with a secret/ARN error | Execution role lacks `secretsmanager:GetSecretValue` or `kms:Decrypt`, or wrong partition ARN | Verify the §4 policy; GovCloud ARNs must be `arn:aws-us-gov:`. |
| `ResourceInitializationError` pulling image | No route to ECR (missing NAT / VPC endpoint) or ECR pull perms | Add NAT or `com.amazonaws.<region>.ecr.*` + `s3` VPC endpoints; confirm ECR actions in the policy. |
| App logs `AEROMARKUP_SECRET is required` | Secret not injected (prod + `DATABASE_URL` set) | Ensure the `AEROMARKUP_SECRET` secret entry maps to `aeromarkup/session-secret`. |
| Login works over HTTP but not HTTPS / users logged out behind ALB | `TRUSTED_PROXY_HOPS` unset → wrong scheme/IP detection | Set `TRUSTED_PROXY_HOPS=1` (one ALB hop). |
| `SELECT ... FROM aeromarkup.projects` errors "schema does not exist" | Migrations didn't run | Confirm `AUTO_MIGRATE=1`; check boot logs; run `psql -f db/schema.sql`. |
| DB connection refused / SSL error | Security group blocks task→RDS, or `sslmode` mismatch | Allow 5432 from task SG; use `?sslmode=require` in `DATABASE_URL`. |
| `execute-command` fails | ECS Exec not enabled / SSM perms missing | Enable Exec on the service; add `ssmmessages:*` to the task role, or skip (login proves the secret). |
| 3D model / image upload rejected as too large | Payload/body limits at ALB or DB column limit | Data URLs can be large; ensure ALB idle timeout ≥ request time and Postgres has room; images/models are stored in `background_data` / `model_data`. |
