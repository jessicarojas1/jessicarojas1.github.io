# PAL on AWS (Commercial and GovCloud)

Reference for hosting PAL on AWS. **AWS hosting has not been selected** (the production target is on-prem Linux, [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md)); use this guide only if that decision changes.

Two shapes:

- **Amazon ECS on AWS Fargate** + Amazon RDS for PostgreSQL + Application Load Balancer + AWS Secrets Manager + Amazon ECR. Detailed below.
- **Amazon EKS**: use [KUBERNETES.md](KUBERNETES.md) with IRSA or EKS Pod Identity for the secret operator (External Secrets Operator / Secrets Store CSI driver with the AWS provider). AWS-specific notes in §4 and §6.

Scope: PAL through **Phase 2**. State is in PostgreSQL **and** the blob store (Amazon EFS here). Uploads need a ClamAV service. No preview converter yet. PAL makes no LLM calls and **calls no AWS APIs itself**: IAM matters only for the platform (image pulls, secret injection, logs, EFS mount authorization). The S3 blob backend is *Planned — Phase 6*.

Identity is Microsoft Entra ID regardless of AWS partition. Which Entra cloud (commercial or Azure Government) you use depends on **where your Entra tenant lives**, not on whether PAL runs in AWS Commercial or GovCloud.

Nothing here has been deployed or tested on AWS. Items marked *verify* must be confirmed in your account.

---

## 1. Deployment architecture

| Component | AWS service | Notes |
|---|---|---|
| `pal-web` | ECS service (Fargate), container command `["web"]`, port 8080 | 2 tasks across AZs; behind the ALB; mounts EFS at `/srv/pal/data` |
| `pal-clamav` | ECS service (Fargate), image `clamav/clamav:1.5.4` mirrored to ECR, port 3310 | `desiredCount: 1`; about 1.5 GB of RAM for signatures, 4 GB task suggested (not measured); private, discovered via Service Connect or Cloud Map. Check that the image runs under Fargate as is (verify) |
| Blob store | Amazon EFS with an access point (POSIX user/group 10001, root `/pal-data`) | Shared by all web tasks. Encryption at rest and in transit on |
| `pal-worker` | ECS service (Fargate), command `["worker"]` | `desiredCount: 1` (scheduler; the PostgreSQL advisory lock makes extra tasks safe but wasteful). Exits hourly by design; ECS replaces the task |
| `pal-migrate` | One-off ECS task (`run-task`), command `["migrate"]` | Before each rollout |
| One-off console | `run-task` with command `["console","<command>"]` | Verification and admin commands; output in CloudWatch Logs |
| Load balancer | ALB, HTTPS listener (ACM certificate), target group port 8080 (IP targets) | TLS termination |
| Database | RDS for PostgreSQL 17 (16+ supported), Multi-AZ | Private subnets, TLS enforced |
| Secrets | Secrets Manager (KMS CMK optional) | Injected by ECS as environment variables |
| Images | ECR | Scan on push |
| Logs | CloudWatch Logs (`awslogs` driver) | Forwarded to the SIEM |

## 2. Topology

```
                         Users
                           | HTTPS 443
          +----------------v-----------------+
          | ALB (public or internal subnets) |  ACM cert, appends X-Forwarded-For
          +----------------+-----------------+
                           | HTTP 8080 (target group, IP targets)
   VPC, private subnets    |
   +-----------------------v--+   +------------------+   +-------------------+
   | ECS service pal-web (2)  |   | ECS service      |   | run-task          |
   | Fargate, uid 10001       |   | pal-worker (1)   |   | pal-migrate       |
   +------------+-------------+   +--------+---------+   +---------+---------+
                |  task execution role: ECR pull, Secrets Manager, CloudWatch Logs
                v                          v                       v
   +--------------------------------------------------------------------------+
   | RDS for PostgreSQL 17 (Multi-AZ, rds.force_ssl=1)                          |
   +--------------------------------------------------------------------------+
   web + worker egress HTTPS 443 via NAT gateway / egress proxy -> Entra ID + Microsoft Graph
   pal-web -> EFS (2049) at /srv/pal/data; pal-web -> pal-clamav (3310)
   pal-clamav egress -> ClamAV signature mirror
   VPC endpoints (optional): ecr.api, ecr.dkr, s3 (gateway), secretsmanager, logs
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Account | AWS Commercial (partition `aws`) or AWS GovCloud (US) (partition `aws-us-gov`, regions `us-gov-west-1`, `us-gov-east-1`) |
| Network | VPC with private subnets in 2+ AZs, ALB subnets, NAT gateway or egress proxy allowing HTTPS to the Entra authority and Graph hosts |
| Tools | AWS CLI v2; `psql` from a host with VPC access (bastion/SSM) to run `roles.sql` |
| Image | `pal` built from `pal/Dockerfile`, pushed to ECR (`<acct>.dkr.ecr.<region>.amazonaws.com/pal:1.0.0`); pin by digest in task definitions |
| Certificates | ACM certificate for `pal.example.internal` |
| Storage | EFS file system with mount targets in the task subnets; security groups allowing 2049 from web tasks and 3310 from web tasks to clamav |
| Entra ID (only for SSO) | App registration as in [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides), including the **mandatory** *Assignment required = Yes* with only the PAL users group assigned (every account that can sign in becomes a Reader, B2B guests included) |
| Time | Fargate tasks use Amazon Time Sync; ID token validation allows 120 s skew |

## 4. Identity and credentials

### 4.1 IAM roles (least privilege)

| Role | Used by | Permissions |
|---|---|---|
| Task execution role `pal-ecs-execution` | ECS agent | Pull from the `pal` ECR repository, read the `pal/*` secrets, write to the `/ecs/pal` log group, decrypt with the secrets' KMS key (if a CMK is used) |
| Task role | PAL containers | PAL calls no AWS APIs. For the web task, attach only `elasticfilesystem:ClientMount` and `ClientWrite` on the file system **if** EFS IAM authorization is enabled. Otherwise omit it. (If you enable ECS Exec, that role needs the `ssmmessages:*` channel permissions.) |
| EKS alternative | External Secrets Operator service account via IRSA / Pod Identity | `secretsmanager:GetSecretValue` + `DescribeSecret` on `pal/*` only. PAL pods get no IAM role |

Execution role policy (replace `<partition>` with `aws` or `aws-us-gov`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": "ecr:GetAuthorizationToken", "Resource": "*" },
    { "Effect": "Allow",
      "Action": ["ecr:BatchGetImage", "ecr:GetDownloadUrlForLayer", "ecr:BatchCheckLayerAvailability"],
      "Resource": "arn:<partition>:ecr:<region>:<acct>:repository/pal" },
    { "Effect": "Allow",
      "Action": ["logs:CreateLogStream", "logs:PutLogEvents"],
      "Resource": "arn:<partition>:logs:<region>:<acct>:log-group:/ecs/pal:*" },
    { "Effect": "Allow",
      "Action": "secretsmanager:GetSecretValue",
      "Resource": "arn:<partition>:secretsmanager:<region>:<acct>:secret:pal/*" },
    { "Effect": "Allow",
      "Action": "kms:Decrypt",
      "Resource": "arn:<partition>:kms:<region>:<acct>:key/<key-id>",
      "Condition": { "StringEquals": { "kms:ViaService": "secretsmanager.<region>.amazonaws.com" } } }
  ]
}
```

Scope the migrations secret (`pal/database-url-migrations`) so that only the migrate task definition references it; a separate execution role for the migrate task is stricter.

### 4.2 What still needs a credential (verified in code)

| Credential | Why IAM cannot replace it | Storage |
|---|---|---|
| Entra app certificate + key | PAL authenticates to Entra with a client secret **or** a certificate **file** pair (`private_key_jwt`); no workload-identity federation | Secrets Manager secret `pal/oidc-pem` (combined PEM: key + certificate), written to a file at task start (§6.2) |
| `pal_app` / `pal_owner` passwords | PAL uses a static password in `DATABASE_URL`; RDS IAM authentication tokens are not supported | Secrets Manager secrets holding the full URLs |
| `APP_SECRET` | Application secret | Secrets Manager |
| `PAL_BOOTSTRAP_LOCAL_ADMIN_*` | One-off first administrator | Secret `pal/bootstrap-admin-password` referenced by the migrate task only; delete after first sign-in |

Fargate cannot mount Secrets Manager values as files, and PAL reads the certificate only from files. §6.2 shows an init container that writes the PEM to a task-local volume. With a combined PEM, `OIDC_CLIENT_CERT_PATH` and `OIDC_CLIENT_KEY_PATH` point at the same file; PHP's OpenSSL reads the matching block from it (expected to work, untested: verify sign-in in a test environment).

### 4.3 Database roles

The RDS master user is `rds_superuser`, not a true superuser. From a bastion with VPC access:

```sh
export ADMIN_URL="postgresql://<master>@pal-db.xxxx.<region>.rds.amazonaws.com:5432/pal?sslmode=require"
psql "$ADMIN_URL" -c 'CREATE ROLE pal_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT' \
                  -c 'GRANT pal_owner TO CURRENT_USER'     # lets the master transfer ownership (verify)
psql "$ADMIN_URL" -v ON_ERROR_STOP=1 -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal \
     -f deploy/sql/roles.sql
psql "$ADMIN_URL" -c '\password pal_owner' -c '\password pal_app'
```

Re-run `roles.sql` after every migration.

## 5. Environment variables

### 5.1 Values that differ between AWS Commercial and GovCloud

| Variable / setting | AWS Commercial | AWS GovCloud (US) | Purpose |
|---|---|---|---|
| ARN partition | `arn:aws:` | `arn:aws-us-gov:` | IAM policies, secret ARNs in task definitions |
| Region | e.g. `us-east-1` | `us-gov-west-1` / `us-gov-east-1` | All resources |
| `DATABASE_URL` host | `pal-db.xxxx.us-east-1.rds.amazonaws.com` | `pal-db.xxxx.us-gov-west-1.rds.amazonaws.com` | RDS endpoint |
| Image | `<acct>.dkr.ecr.us-east-1.amazonaws.com/pal` | `<acct>.dkr.ecr.us-gov-west-1.amazonaws.com/pal` | ECR |
| EFS DNS | `<fs-id>.efs.us-east-1.amazonaws.com` | `<fs-id>.efs.us-gov-west-1.amazonaws.com` | Used by the ECS EFS integration; FIPS requirements via TLS mount option (verify) |
| `AWS_USE_FIPS_ENDPOINT` (operator shell / CI, not PAL) | optional | `true` where your compliance boundary requires FIPS endpoints | Makes AWS CLI/SDK tooling use FIPS endpoints (e.g. `secretsmanager-fips`, `ecr-fips`; check each service's GovCloud endpoint list) |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | same, **unless** the tenant is in Azure Government: `https://login.microsoftonline.us` (verify) | Follows the Entra tenant's cloud |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | same, or `https://graph.microsoft.us/v1.0` (GCC High) / `https://dod-graph.microsoft.us/v1.0` (DoD) (verify) | Follows the Entra tenant's cloud |

FIPS note: the PAL image (Alpine PHP/OpenSSL) is not built on a FIPS-validated cryptographic module. FIPS endpoints protect the AWS control-plane calls made by tooling; they do not make PAL itself FIPS-validated.

### 5.2 All PAL variables

Secrets are injected through the task definition's `secrets` (`valueFrom` = secret ARN); the rest through `environment`. Full descriptions: [SINGLE_LINUX_SERVER.md §5](SINGLE_LINUX_SERVER.md#5-environment-variables).

| Variable | Example | Purpose | Tasks |
|---|---|---|---|
| `APP_SECRET` | secret `pal/app-secret` | Kernel secret; required (exit 64 if missing) | all |
| `DATABASE_URL` | secret `pal/database-url` → `postgresql://pal_app:<pw>@<rds-host>:5432/pal?serverVersion=17&charset=utf8` | Runtime connection (app role) | all |
| `DATABASE_URL_MIGRATIONS` | secret `pal/database-url-migrations` (owner role) | Owner connection | migrate only |
| `PAL_APP_DB_ROLE` | `pal_app` | Revoke `audit_event` writes after migrating | migrate |
| `PGSSLMODE` | `require` (or `verify-full` with `PGSSLROOTCERT`) | libpq TLS for both PAL DB connections. Not a PAL variable | all |
| `PGSSLROOTCERT` | `/run/pal-secrets/rds-ca.pem` | RDS CA bundle for `verify-full` (write it with the init container). Not a PAL variable | all |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Queue in PostgreSQL | web, worker |
| `PAL_BASE_URL` | `https://pal.example.internal` | Public origin; redirect URI = + `/auth/callback` (required by web) | web, worker |
| `DEFAULT_URI` | `https://pal.example.internal` | Router default URI for CLI/worker | all |
| `TRUSTED_HOSTS` | `^pal\.example\.internal$` | Accepted Host regex (required by web) | web |
| `TRUSTED_PROXIES` | `10.0.0.0/24,10.0.1.0/24` | CIDRs of the **ALB subnets** (ALB nodes connect from there), not `private_ranges`. Only these may set `X-Forwarded-*`. See §6 | web |
| `OIDC_AUTHORITY` | see §5.1 | Entra authority | web, worker |
| `OIDC_TENANT_ID` | `<tenant-guid>` | Tenant | web, worker |
| `OIDC_CLIENT_ID` | `<client-guid>` | App registration | web, worker |
| `OIDC_CLIENT_CERT_PATH` | `/run/pal-secrets/oidc.pem` | Certificate (combined PEM) | web, worker |
| `OIDC_CLIENT_KEY_PATH` | `/run/pal-secrets/oidc.pem` | Key (same file; verify) | web, worker |
| `OIDC_CLIENT_SECRET` | *(unset)* | Only one credential may be configured | — |
| `GRAPH_BASE_URL` | see §5.1 | Graph | web, worker |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | `<user-object-guid>` | Temporary System Administrators; remove after mapping | web |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | Org timezone | web, worker |
| `PAL_SESSION_IDLE_SECONDS` / `PAL_SESSION_ABSOLUTE_SECONDS` | `3600` / `43200` | Session lifetimes | web |
| `PAL_AUDIT_ANCHOR_DIR` | `/mnt/pal-anchor` | Audit anchor directory (EFS access point on the worker) | worker |
| `LOG_LEVEL` | `info` | Log level | all |
| `PAL_WORKER_TIME_LIMIT` / `PAL_WORKER_MEMORY_LIMIT` | `3600` / `256M` | Worker recycling | worker |
| `PAL_BLOB_DIR` | `/srv/pal/data/blobs` | Blob store root (image default). Must be writable by uid 10001; `/health/ready` reports `"storage":"fail"` otherwise | web |
| `PAL_MAX_UPLOAD_BYTES` | `26214400` | Largest accepted file. App default is `52428800`; set this to stay within clamd's default `StreamMaxLength` (25 MiB) | web |
| `PAL_UPLOAD_DAILY_BYTES` | `524288000` | Per-user limit on clean uploaded bytes in any 24 hours | web |
| `PAL_MAX_IMAGE_BYTES` | `10485760` (default) | Largest image for document content (images are re-encoded without metadata; never above `PAL_MAX_UPLOAD_BYTES`; 413 `file_too_large`) | web |
| `PAL_CLAMD_ADDRESS` | `tcp://pal-clamav.pal.local:3310` | ClamAV daemon (your Service Connect / Cloud Map name). Empty or unreachable: uploads are refused | web |
| `PAL_ALLOW_UNSCANNED_UPLOADS` | `0` | Leave `0`. Ignored when `APP_ENV=prod` | web |
| `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` / `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` | `admin` / secret `pal/bootstrap-admin-password` | First local administrator; temporary password. Delete after first sign-in | migrate |

Image defaults to keep: `APP_ENV=prod`, `APP_DEBUG=0`, `PAL_SESSION_COOKIE_NAME=__Host-pal_session`, `PAL_SESSION_COOKIE_SECURE=1`.

## 6. Configuration references

### 6.1 Platform settings

| Setting | Example | Purpose |
|---|---|---|
| ALB listener | HTTPS 443, ACM cert, TLS policy per your standard; HTTP 80 redirects to 443 | TLS |
| ALB `routing.http.xff_header_processing.mode` | `append` (default) | ALB appends the connecting client address. With `TRUSTED_PROXIES` = ALB subnet CIDRs, Symfony takes the right-most untrusted address, so client-supplied values to its left are ignored. Do **not** use `preserve`. Verify with check 9 in §7 |
| ALB `routing.http.drop_invalid_header_fields.enabled` | `true` | Header hygiene |
| Target group | protocol HTTP, port 8080, target type `ip`, health check `GET /health/ready`, matcher `200` | Readiness (503 on DB loss, unapplied migrations or an unwritable blob directory) |
| ALB `idle_timeout.timeout_seconds` | `120` | Default 60 s can be shorter than PAL's 120 s upload handling |
| Blob volume | `efsVolumeConfiguration` with `transitEncryption: ENABLED` and the access point | Mounted at `/srv/pal/data` on web only |
| Container health check (web) | `["CMD-SHELL","wget -qO- http://127.0.0.1:8080/health/live >/dev/null \|\| exit 1"]` | Liveness (ECS ignores the image `HEALTHCHECK`) |
| `user` | `10001` | Image user |
| `readonlyRootFilesystem` | `true` | With task volumes (no host path) mounted at `/tmp`, `/srv/pal/backend/var/cache`, `/srv/pal/backend/var/log`. Verify they are writable by uid 10001 on your Fargate platform version |
| `linuxParameters.capabilities.drop` | `["ALL"]` | No capabilities needed |
| Worker deployment | `minimumHealthyPercent: 0`, `maximumPercent: 100` | Avoids two workers during deploys (overlap would be safe, just wasteful) |
| RDS | PostgreSQL 17, Multi-AZ, `rds.force_ssl=1`, storage encrypted, automated backups 7–35 days, deletion protection. `btree_gist` and `pg_trgm` are created by the migration (verify on your engine version) | State |
| Egress proxy | `HTTPS_PROXY`, `NO_PROXY` (platform variables; Symfony HttpClient honours them, verify); include the RDS host in `NO_PROXY` | Outbound to Entra/Graph without NAT |

### 6.2 Task definition (web, abbreviated)

`OIDC_PEM` below is a task-definition variable read **only by the init container's shell**; it is not a PAL setting.

```json
{
  "family": "pal-web",
  "requiresCompatibilities": ["FARGATE"],
  "networkMode": "awsvpc",
  "cpu": "1024",
  "memory": "2048",
  "executionRoleArn": "arn:<partition>:iam::<acct>:role/pal-ecs-execution",
  "volumes": [
    { "name": "tmp" }, { "name": "cache" }, { "name": "log" }, { "name": "pal-secrets" },
    { "name": "pal-blobs", "efsVolumeConfiguration": {
        "fileSystemId": "<fs-id>", "transitEncryption": "ENABLED",
        "authorizationConfig": { "accessPointId": "<ap-id>", "iam": "DISABLED" } } }
  ],
  "containerDefinitions": [
    {
      "name": "secrets-init",
      "image": "<acct>.dkr.ecr.<region>.amazonaws.com/pal@sha256:<digest>",
      "essential": false,
      "user": "10001",
      "readonlyRootFilesystem": true,
      "entryPoint": ["/bin/sh", "-c"],
      "command": ["umask 077 && printf '%s\\n' \"$OIDC_PEM\" > /run/pal-secrets/oidc.pem"],
      "secrets": [
        { "name": "OIDC_PEM", "valueFrom": "arn:<partition>:secretsmanager:<region>:<acct>:secret:pal/oidc-pem" }
      ],
      "mountPoints": [ { "sourceVolume": "pal-secrets", "containerPath": "/run/pal-secrets" } ],
      "logConfiguration": { "logDriver": "awslogs", "options": {
        "awslogs-group": "/ecs/pal", "awslogs-region": "<region>", "awslogs-stream-prefix": "init" } }
    },
    {
      "name": "web",
      "image": "<acct>.dkr.ecr.<region>.amazonaws.com/pal@sha256:<digest>",
      "essential": true,
      "command": ["web"],
      "user": "10001",
      "readonlyRootFilesystem": true,
      "linuxParameters": { "capabilities": { "drop": ["ALL"] } },
      "dependsOn": [ { "containerName": "secrets-init", "condition": "SUCCESS" } ],
      "portMappings": [ { "containerPort": 8080, "protocol": "tcp" } ],
      "environment": [
        { "name": "PAL_BASE_URL", "value": "https://pal.example.internal" },
        { "name": "TRUSTED_HOSTS", "value": "^pal\\.example\\.internal$" },
        { "name": "TRUSTED_PROXIES", "value": "10.0.0.0/24,10.0.1.0/24" },
        { "name": "OIDC_CLIENT_CERT_PATH", "value": "/run/pal-secrets/oidc.pem" },
        { "name": "OIDC_CLIENT_KEY_PATH", "value": "/run/pal-secrets/oidc.pem" },
        { "name": "PGSSLMODE", "value": "require" },
        { "name": "PAL_MAX_UPLOAD_BYTES", "value": "26214400" },
        { "name": "PAL_CLAMD_ADDRESS", "value": "tcp://pal-clamav.pal.local:3310" }
      ],
      "secrets": [
        { "name": "APP_SECRET", "valueFrom": "arn:<partition>:secretsmanager:<region>:<acct>:secret:pal/app-secret" },
        { "name": "DATABASE_URL", "valueFrom": "arn:<partition>:secretsmanager:<region>:<acct>:secret:pal/database-url" }
      ],
      "mountPoints": [
        { "sourceVolume": "tmp", "containerPath": "/tmp" },
        { "sourceVolume": "cache", "containerPath": "/srv/pal/backend/var/cache" },
        { "sourceVolume": "log", "containerPath": "/srv/pal/backend/var/log" },
        { "sourceVolume": "pal-secrets", "containerPath": "/run/pal-secrets", "readOnly": true },
        { "sourceVolume": "pal-blobs", "containerPath": "/srv/pal/data" }
      ],
      "healthCheck": {
        "command": ["CMD-SHELL", "wget -qO- http://127.0.0.1:8080/health/live >/dev/null || exit 1"],
        "interval": 30, "timeout": 5, "retries": 3, "startPeriod": 30
      },
      "logConfiguration": { "logDriver": "awslogs", "options": {
        "awslogs-group": "/ecs/pal", "awslogs-region": "<region>", "awslogs-stream-prefix": "web" } }
    }
  ]
}
```

The remaining variables from §5.2 go into `environment`. The worker task definition is the same with `"command": ["worker"]`, no port mapping, no health check and no `pal-blobs` volume. The migrate task needs no `secrets-init` or `pal-blobs`, uses `"command": ["migrate"]`, adds `PAL_APP_DB_ROLE`, the `DATABASE_URL_MIGRATIONS` secret and, for the first run, `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` plus the password secret.

## 7. Verification

```sh
# Run migrations (one-off task) and check the exit code
TASK=$(aws ecs run-task --cluster pal --launch-type FARGATE --task-definition pal-migrate \
  --network-configuration 'awsvpcConfiguration={subnets=[subnet-aaa,subnet-bbb],securityGroups=[sg-pal],assignPublicIp=DISABLED}' \
  --query 'tasks[0].taskArn' --output text)
aws ecs wait tasks-stopped --cluster pal --tasks "$TASK"
aws ecs describe-tasks --cluster pal --tasks "$TASK" --query 'tasks[0].containers[0].exitCode'   # expect 0

# One-off console command (output in CloudWatch Logs, stream prefix of the task definition)
aws ecs run-task --cluster pal --launch-type FARGATE --task-definition pal-worker \
  --overrides '{"containerOverrides":[{"name":"worker","command":["console","pal:audit:verify"]}]}' \
  --network-configuration 'awsvpcConfiguration={subnets=[subnet-aaa],securityGroups=[sg-pal],assignPublicIp=DISABLED}'
```

| # | Check | How | Expected |
|---|---|---|---|
| 1 | Migrations | migrate task above | exit code 0; log `audit trail revokes applied to role pal_app` |
| 2 | Liveness | ECS container health status | `HEALTHY` |
| 3 | Readiness | `curl -sS https://pal.example.internal/health/ready`; target group health | 200 `"database":"ok","migrations":"ok","storage":"ok"`; targets healthy |
| 4 | Secrets resolved | Tasks reach RUNNING (no `ResourceInitializationError`); `secrets-init` exited 0; sign-in works | as stated |
| 5 | DB TLS + roles | As master: `SELECT a.usename, s.ssl FROM pg_stat_activity a JOIN pg_stat_ssl s USING (pid) WHERE a.datname='pal';` | only `pal_app` at runtime; `ssl = t` |
| 5a | Local admin sign-in + forced change | Sign in with `PAL_BOOTSTRAP_LOCAL_ADMIN_*` | Redirected to `/change-password`; other API calls answer 403 `password_change_required`; after the change, the dashboard. Then delete the values from the migrate task definition |
| 6 | Entra sign-in | Browse the site | Signed in, not on `/sign-in?auth_error=…`; unassigned account refused by Entra (AADSTS50105) |
| 7 | Directory sync | console task: `["console","pal:roles:map","PAL_ADMINISTRATOR","<group-object-id>"]`, then `["console","pal:directory:sync"]` | exit 0, `N members (+a / -r)` |
| 8 | Audit chain | console task `["console","pal:audit:verify"]` | `Audit chain valid: …` |
| 9 | Client IP not spoofable | `curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.9' 'https://pal.example.internal/auth/callback?state=x&code=y'`; check web logs | Your real egress IP, not `203.0.113.9` and not an ALB address |
| 10 | Upload accepted + scanned, object written | Upload a small PDF to a draft. As master: `SELECT status FROM upload ORDER BY created_at DESC LIMIT 1;`, `SELECT scan_engine, sha256 FROM stored_file ORDER BY created_at DESC LIMIT 1;`. Check the file on EFS via a mounted admin host | `CLEAN`; `clamd`; file at `blobs/sha256/<ab>/<cd>/<hash>` |
| 11 | Malware refused | Upload the standard EICAR test file as `eicar.txt` | 422 `malware_detected`; no new `stored_file` row |
| 12 | Indexed | Publish the document and search its title in the library | Found |

## 8. Day-2 operations

- **Upgrade:** push the image → register new task definition revisions (pin the digest) → run the migrate task and confirm exit 0 → re-run `roles.sql` → `aws ecs update-service --service pal-web --task-definition pal-web:<rev>` and the same for `pal-worker`. New tasks join the target group only when `/health/ready` passes. Keep migrations compatible with the previous release during the rolling update.
- **Backups / restore:** RDS automated backups give point-in-time restore (supports the provisional RPO 15 min, decision D10, unconfirmed); restore creates a new instance: update the `pal/database-url*` secrets, re-run `roles.sql` if needed, restore EFS from a recovery point at or after the database's, force a new deployment, then run `pal:audit:verify`, compare with the anchor, and run the blob consistency check ([DISASTER_RECOVERY.md §4](../docs/DISASTER_RECOVERY.md#4-restore-runbook) step 8a). **Back up EFS with AWS Backup, database first and blobs after, at an interval no longer than the RPO** (or use EFS replication). AWS Backup for cross-account/cross-Region copies.
- **Audit anchoring:** mount an EFS access point (POSIX user 10001) on the worker at `PAL_AUDIT_ANCHOR_DIR`, in a file system administered by another team. EFS has no write-once mode; for WORM, have that team copy `audit-anchors.jsonl` into an S3 bucket with Object Lock.
- **Logs / Sentinel:** container logs go to CloudWatch Logs `/ecs/pal`; filter `"channel":"pal_audit"`. Forward to Microsoft Sentinel through the SOC's supported AWS path (e.g. export/stream to S3 and the Sentinel AWS S3 connector; confirm with the SOC, decision D17). Never the legacy Log Analytics HTTP Data Collector API. Alarm on `Audit chain verification FAILED` with a CloudWatch metric filter; uptime via Route 53 health check or synthetic canary on `/health/ready`.
- **Secret rotation:** put a new secret value; ECS injects secrets only at task start, so `aws ecs update-service --force-new-deployment` for web and worker. Entra certificate: upload the new public cert to the app registration alongside the old one, update `pal/oidc-pem`, force new deployments, verify sign-in and sync, then remove the old cert.
- **Failed jobs:** console task `["console","messenger:failed:show"]`.
- **Local accounts:** console task `["console","pal:users:unlock","<username>"]` clears a lockout.
- **ClamAV:** signatures update in the task through `freshclam` (verify egress); a restarted task re-downloads them unless `/var/lib/clamav` is on EFS. Raise `StreamMaxLength` before raising `PAL_MAX_UPLOAD_BYTES`. Alarm on `ClamAV is unreachable`.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Task stops, log `pal: required environment variable X is not set`, exit **64** | Missing variable (web: `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; worker/migrate: `APP_SECRET`, `DATABASE_URL`) | Add it to the task definition |
| `ResourceInitializationError: unable to pull secrets` | Execution role lacks `GetSecretValue`/`kms:Decrypt`, wrong partition in the ARN, or no route to Secrets Manager | Fix the policy (`arn:aws-us-gov:` in GovCloud); add a VPC endpoint or NAT route |
| Web never starts, `secrets-init` exit non-zero | Secret empty or init cannot write the volume | Check the init log; check the volume mount |
| Target group unhealthy, `/health/ready` **503** `"migrations":"fail"` | Migrate task not run | Run it |
| 503 `"database":"fail"` | Security group, RDS TLS, password | Allow 5432 from the task SG; `PGSSLMODE`; check secret |
| All requests **400** | Host not in `TRUSTED_HOSTS` (e.g. using the ALB DNS name) | Use the custom domain or add it |
| `/sign-in?auth_error=sign_in_unavailable` | OIDC config (GUIDs, exactly one credential), PEM unreadable, egress to Entra blocked | Web log `Sign-in is unavailable` → `reason`; check NAT/proxy |
| `/sign-in?auth_error=sign_in_failed` | Redirect URI mismatch, cert not uploaded, wrong Entra cloud for the tenant | Align with §5.1 |
| Everyone throttled (`too_many_attempts`, **429**); audit `client_ip` is an ALB address | `TRUSTED_PROXIES` does not cover the ALB subnets | Set the ALB subnet CIDRs |
| `client_ip` shows values users chose | ALB XFF mode `preserve`, or `TRUSTED_PROXIES` too broad | Use `append`; narrow `TRUSTED_PROXIES`; re-run check 9 |
| Graph sync HTTP 403 | Missing application permissions/consent, or wrong Graph cloud | Grant consent; fix `GRAPH_BASE_URL` |
| HTTP **403** `self_grant_forbidden` on role mapping | Admin belongs to the group being mapped to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` | Another administrator creates the mapping |
| Branding logo URL rejected | Only `https://` or raster `data:` URLs are accepted | Use `https://` |
| `/health/ready` **503** `"storage":"fail"` | `PAL_BLOB_DIR` missing, not mounted, read-only, or not writable by uid 10001 | Check `efsVolumeConfiguration`, the access point's POSIX user 10001, security group 2049, and mount targets in the task subnets |
| Upload **503** `scanner_unavailable`; log `ClamAV is unreachable` | clamd down, still loading signatures (first start: several minutes), wrong `PAL_CLAMD_ADDRESS`, or the network path to TCP 3310 is blocked | Check the `pal-clamav` service, the service discovery name, and security group 3310. Reading and downloading are unaffected |
| Upload **503** `scanner_unavailable`; log `ClamAV returned an unexpected reply` for larger files | File exceeds clamd's `StreamMaxLength` (ClamAV default 25 MiB) | Keep `PAL_MAX_UPLOAD_BYTES=26214400`, or raise `StreamMaxLength` in clamd's configuration first (and keep the value ≤ PHP's 50M) |
| Upload **413** `file_too_large` | File above `PAL_MAX_UPLOAD_BYTES` or PHP's 50M | Expected; users see the limit from `GET /api/reference` (`maxUploadBytes`) |
| Upload **429** `too_many_uploads` / `upload_quota_exceeded` | 30 uploads/hour per user, or `PAL_UPLOAD_DAILY_BYTES` reached | Wait, or raise the quota |
| `migrate` fails `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD rejected` | Password fails the policy (12–128 characters, no username, not a common password) | Choose a passphrase; re-run |
| Local sign-in always 401 `invalid_credentials` | Wrong password, account inactive, locked (5 failures, 15 minutes), or stale login CSRF token | Wait 15 minutes or run the `pal:users:unlock` console task; reload the page for a fresh token |
| Every API call **403** `password_change_required` | Temporary password not changed yet | Change it at `/change-password` |
| **403** `role_escalation_forbidden` | Conferring a document role, or resetting the password of an account with one, without holding `PAL_ADMINISTRATOR` | Have a PAL Administrator with `admin.users` do it |
| **409** `last_administrator` | The change would leave no active System Administrator | Grant another System Administrator first |
| ALB returns **504** on larger uploads | Idle timeout below PAL's processing time | Raise `idle_timeout.timeout_seconds` to 120 |
| `Read-only file system` errors | Task volume not mounted at one of the three paths, or not writable by uid 10001 | Check `mountPoints`; verify volume permissions |
| `roles.sql` permission errors | Master user not a member of `pal_owner` | `GRANT pal_owner TO <master>` (§4.3) |

---

Related: [KUBERNETES.md](KUBERNETES.md) (EKS), [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md), [AZURE.md](AZURE.md), [AIRGAPPED.md](AIRGAPPED.md).
