# VERITY — AWS

Operator guidance for hosting the Verity Docker image on AWS. **No
AWS-specific files ship in this repo** — this build has no AWS-specific code
at all; it is a plain Docker image + PostgreSQL application that runs on any
platform that can run a container and reach a Postgres database. The guidance
below is how an operator would deploy it to AWS, not a description of
infrastructure already present.

## 1. Deployment architecture

A single stateless container (ECS Fargate task, or an App Runner service)
behind a load balancer/managed ingress, talking to Amazon RDS for PostgreSQL.
No queue, no worker task, no ElastiCache/Redis — the app has none of those
dependencies.

## 2. Topology

```
            AWS
  ┌─────────────────────────────────────────────────────────┐
  │ [ALB / App Runner] ──TLS──> [ECS Fargate task: verity]   │
  │                                      │                    │
  │                            [IAM Task Role]                │
  │                                      │                    │
  │                            [Secrets Manager: DATABASE_URL]│
  │                                      │                    │
  │                            [RDS for PostgreSQL]           │
  └─────────────────────────────────────────────────────────┘
```

## 3. Prerequisites

- An AWS account (Commercial or GovCloud — see §6) and the AWS CLI.
- Either an ECS cluster (Fargate launch type) with an Application Load
  Balancer, or an App Runner service if you want the simplest possible
  managed-container path.
- Amazon RDS for PostgreSQL, version 14+.
- AWS Secrets Manager for `DATABASE_URL`, and an IAM task role scoped to
  read only that secret.
- The Verity image pushed to Amazon ECR (or any registry ECS/App Runner can
  pull from).

## 4. Identity & credentials

Prefer the **IAM task role** over static AWS access keys — the container
never needs a long-lived AWS credential of its own:

1. Create a Secrets Manager secret holding the full `DATABASE_URL` connection
   string.
2. Create an ECS task role (or App Runner instance role) with a
   least-privilege policy granting only `secretsmanager:GetSecretValue` on
   that specific secret ARN — not `*`.
3. Inject the secret into the task definition's container `secrets` block
   (ECS resolves it at task start and sets it as a plain environment
   variable `DATABASE_URL`, which is all `app/Support/Config.php` reads) —
   never bake it into the task definition's `environment` block in
   plaintext.
4. If RDS IAM database authentication is enabled on the Postgres instance,
   you can generate a short-lived auth token instead of a static password —
   this requires a small startup wrapper to fetch the token and assemble the
   DSN before the app starts, since the app itself only reads a finished
   `DATABASE_URL`; a Secrets-Manager-held static least-privilege credential
   is the simpler default and what is described above.

There is no AWS identity provider integration relevant to Verity's own
sign-in — local email/password only (`/auth/sso` returns 503; see
`LOCAL_DEVELOPMENT.md` §4). IAM here only protects the database credential,
not end-user authentication.

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Runtime mode |
| `PORT` | `8080` | Container listen port — matches the Dockerfile's `EXPOSE 8080`; configure the ALB target group/App Runner port to match |
| `DATABASE_URL` | Secrets Manager reference resolving to `postgresql://verity_app:***@<rds-endpoint>.rds.amazonaws.com:5432/verity?sslmode=require` | PDO Postgres DSN — inject via the task's `secrets` block, not plaintext `environment` |

## 6. AWS Commercial vs. GovCloud

This build has **no AWS-specific code** — nothing in `app/` or `public/`
reads a partition, region, or GovCloud-specific endpoint. The only reason to
pick one partition over the other is the data/compliance posture of the
workload you run on it, not anything in Verity itself:

| Concern | AWS Commercial (`aws` partition) | AWS GovCloud (`aws-us-gov` partition) |
|---------|-----------------------------------|----------------------------------------|
| This build (Phases 1-3, local auth only, no CUI data) | **Works fine today** — the app doesn't know or care which partition it runs in | Also works, but there is no GCC-High-adjacent or regulated workload in this build yet to justify it |
| Future state | N/A | Only matters once real regulated/GCC-High-adjacent data is actually in scope — this build does not have that today |
| Service endpoints | `secretsmanager.<region>.amazonaws.com`, `rds.<region>.amazonaws.com` | `secretsmanager.<region>.amazonaws.com` resolves within the `aws-us-gov` partition; ARNs use the `arn:aws-us-gov:...` prefix instead of `arn:aws:...` |
| FIPS endpoints | Available as opt-in (`*-fips.<region>.amazonaws.com`) if required by policy | Typically the default expectation for GovCloud workloads |

**Recommendation:** deploy to AWS Commercial for this build's current phase.
Move to GovCloud only when a specific compliance driver (e.g., a real
GCC-High-adjacent integration or regulated data) requires it — consistent
with the same boundary note already in `render.yaml` for the Render target.

## 7. Verification

```bash
curl -fsS https://<alb-or-app-runner-url>/health
# {"status":"ok","app":"verity","db":true}
```
- Confirm the running task's environment shows a resolved `DATABASE_URL`
  (ECS: `aws ecs describe-tasks` / task definition `secrets` section
  resolved at runtime — not visible in the task definition JSON itself,
  which is correct).
- Sign in at `/auth/login`; confirm dashboard KPIs render.
- Confirm the task role's policy does not grant broader Secrets Manager or
  RDS access than the one secret/connection it needs.

## 8. Day-2 operations

- Rotate the Secrets Manager secret value on a schedule (or enable automatic
  rotation against the RDS master credential); ECS re-resolves secrets on
  task restart, not live — plan a rolling deployment after rotation.
- RDS automated backups + point-in-time restore; test a restore periodically
  (see the general restore guidance pattern — this build has no
  `docs/DISASTER_RECOVERY.md`-specific RDS runbook yet; use RDS's standard
  PITR restore procedure).
- Monitor CloudWatch Logs for the ECS task (stdout/stderr, which is where
  PHP's `error_log()` output lands per `app/bootstrap.php`'s `log_errors=1`).

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Task fails health check and is cycled | `/health` unreachable — app crashed on boot | Check CloudWatch Logs for the PDO/`Db::connection()` error (it will say "Database connection failed" without leaking the DSN — check the actual Secrets Manager value next) |
| `/health` returns `"db":false` | Secret not actually injected as `DATABASE_URL` | Check the task definition's `secrets` block maps the ARN to the exact env var name `DATABASE_URL` |
| `AccessDeniedException` reading the secret | Task role policy too narrow or wrong ARN | Confirm the task role has `secretsmanager:GetSecretValue` on the exact secret ARN |
| RDS connection timeout | Security group doesn't allow the ECS task's SG/subnet | Add an inbound rule on the RDS security group for the ECS task's security group on port 5432 |
| Deployed to the wrong partition for a compliance requirement | Default partition assumed without a decision | See §6 — confirm with the compliance/security owner before provisioning in GovCloud |
