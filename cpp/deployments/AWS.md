# AWS (Commercial + GovCloud) — CPP Tool Collection

**Applicability:** Reframed. These are CLI tools, not a hosted service. On AWS
the real pattern is: **build in CI with an OIDC IAM role**, store the binaries/
image as artifacts (S3 / ECR), and **run tools as batch** (AWS Batch or ECS/
Fargate one-off tasks, or EventBridge-scheduled tasks). No ALB, RDS, or public
endpoint. These are defense/aerospace tools, so **GovCloud (partition
`aws-us-gov`) with FIPS endpoints** is a first-class target — both partitions
are covered.

## 1. Deployment architecture

CI (GitHub Actions / CodeBuild) assumes a short-lived IAM **role via OIDC**,
runs `make -j` (or `docker build`), and publishes the image to **ECR** and/or a
binary bundle to **S3**. Batch/Fargate tasks pull the image and run a tool with
args against input in S3 (synced to the task) or an EFS mount, emitting reports
to S3 and logs to CloudWatch. Nothing listens.

## 2. Topology

```
 GitHub OIDC ─▶ sts:AssumeRoleWithWebIdentity ─▶ CI role (least-priv)
      │                                              │
   make -j / docker build                            ├─▶ ECR  (image)
      │                                              └─▶ S3   (binary bundle)
      ▼
 EventBridge (cron) ─▶ AWS Batch / ECS Fargate task ─▶ pull ECR image
      │                         │  input  ◀── S3 (sync) / EFS mount
      │                         │  report ──▶ S3
      └─────────────────────────┴─ logs ──▶ CloudWatch Logs ; exit code ─▶ task status
```

## 3. Prerequisites

- AWS account (Commercial) and/or **AWS GovCloud (US)** account.
- ECR repository; S3 bucket(s) for artifacts + I/O; CloudWatch Logs.
- AWS Batch compute environment or an ECS cluster (Fargate).
- CI with OIDC federation (GitHub Actions OIDC, or CodeBuild service role).
- `aws` CLI v2; Docker for image builds.

## 4. Identity & credentials

Prefer **IAM roles / OIDC**, never long-lived keys.

- **CI role** (federated via OIDC) — least-privilege push:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "EcrPush", "Effect": "Allow",
      "Action": ["ecr:GetAuthorizationToken","ecr:BatchCheckLayerAvailability",
                 "ecr:InitiateLayerUpload","ecr:UploadLayerPart",
                 "ecr:CompleteLayerUpload","ecr:PutImage"],
      "Resource": "*" },
    { "Sid": "S3Artifacts", "Effect": "Allow",
      "Action": ["s3:PutObject","s3:GetObject"],
      "Resource": "arn:aws:s3:::cpp-tools-artifacts/*" }
  ]
}
```
  For GovCloud, the ARN partition is **`arn:aws-us-gov:s3:::...`**.

- **Task role** (what the running tool uses) — usually just read input + write
  reports:

```json
{ "Version": "2012-10-17", "Statement": [
  { "Effect": "Allow", "Action": ["s3:GetObject"],
    "Resource": "arn:aws:s3:::cpp-tools-input/*" },
  { "Effect": "Allow", "Action": ["s3:PutObject"],
    "Resource": "arn:aws:s3:::cpp-tools-reports/*" },
  { "Effect": "Allow", "Action": ["logs:CreateLogStream","logs:PutLogEvents"],
    "Resource": "*" } ] }
```
  If `aes-vault` is used, add `secretsmanager:GetSecretValue` for the passphrase
  secret only. Use **KMS** CMKs for S3 SSE and Secrets Manager encryption.

## 5. Environment variables

The tools read no env vars; these are for the CI/task wrapper. **Commercial vs
GovCloud** differ mainly in region, partition, and FIPS endpoints:

| Variable | Commercial example | GovCloud example | Purpose |
|----------|--------------------|------------------|---------|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Region |
| `AWS_PARTITION` | `aws` | `aws-us-gov` | ARN partition |
| `ECR_REGISTRY` | `1234.dkr.ecr.us-east-1.amazonaws.com` | `1234.dkr.ecr.us-gov-west-1.amazonaws.com` | Image registry |
| `S3_INPUT` | `s3://cpp-tools-input` | same scheme, gov bucket | Tool input |
| `S3_REPORTS` | `s3://cpp-tools-reports` | same | Report output |
| `AWS_USE_FIPS_ENDPOINT` | `false` (optional) | **`true`** | Use FIPS endpoints (`s3-fips`, `sts.us-gov-west-1.amazonaws.com`, KMS FIPS) |
| `TOOL_CMD` | `entropy-scanner /data --min 7.2 --json` | same | The tool + args to run |

GovCloud endpoint notes: STS `sts.us-gov-west-1.amazonaws.com`, S3 FIPS
`s3-fips.us-gov-west-1.amazonaws.com`, KMS/Secrets Manager FIPS regional
endpoints. Set `AWS_USE_FIPS_ENDPOINT=true` in the SDK/CLI.

## 6. Configuration references

No app config files. The unit of configuration is the container `command`/args
(the `TOOL_CMD`), stored in the Batch job definition / ECS task definition under
version control.

## 7. Verification

No health/login/DB/upload. Verify the pipeline + a task run:

```bash
# Image built & pushed
aws ecr describe-images --repository-name cpp-tools --region "$AWS_REGION"

# Smoke: run a demo tool as a one-off Fargate task, check exit + logs
aws ecs run-task --cluster cpp --task-definition cpp-tools-smoke --launch-type FARGATE ...
aws logs tail /ecs/cpp-tools --since 5m       # expect "Bus Monitor Transcript" (mil1553-sim)

# Batch sweep: submit, wait, confirm report object written to S3
aws batch submit-job --job-name cui-sweep --job-queue cpp --job-definition cpp-cui
aws s3 ls s3://cpp-tools-reports/            # report object present (object written)
```

"Object written to storage" = the report object in S3 (the CLI-tool analogue of
the standard's DB-row/S3-object check). There is no login or upload endpoint.

## 8. Day-2 operations

- **Build/publish:** CI on tag → `docker build` → `ecr:PutImage`; optionally
  `aws s3 cp bin/ s3://cpp-tools-artifacts/<tag>/ --recursive`.
- **Scheduled sweeps:** EventBridge rule (cron) → Batch `SubmitJob` / ECS
  `RunTask` with the tool command. Set job timeouts + retries.
- **Scaling:** Batch array jobs to shard inputs; or the tool's own
  `--parallel`/`--threads` per task. No autoscaling group serving traffic.
- **Backups:** artifacts are rebuildable from git; enable **S3 versioning** on
  report/artifact buckets; ECR image tag immutability. See
  `docs/DISASTER_RECOVERY.md`.
- **Secret rotation:** `aes-vault` passphrase in Secrets Manager with rotation;
  KMS key rotation enabled. No TLS certs (no service).
- **Patching:** rebuild the image when the base image / OpenSSL has a CVE
  (affects `aes-vault`).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `AccessDenied` assuming CI role | OIDC trust/audience mismatch | fix the role trust policy `sub`/`aud`; correct partition ARN |
| Task fails with exit `2` on a clean detection | tool found something (by design) | treat `2` as success in the job wrapper (`sh -c '...; [ $rc = 0 ] || [ $rc = 2 ]'`) |
| `no basic auth credentials` on ECR pull | task role lacks ECR perms | attach ECR pull to the execution role |
| GovCloud calls hit Commercial endpoints | partition/endpoint not set | set `AWS_REGION=us-gov-*`, `AWS_USE_FIPS_ENDPOINT=true`, gov ARNs |
| `aes-vault` task hangs | waiting for interactive passphrase | pipe the Secrets Manager value to stdin via a wrapper; never a flag |
| Report not in S3 | task wrote to stdout only, or perms | add `s3:PutObject`; redirect tool output to a file then `aws s3 cp` |
