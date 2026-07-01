# AWS Deployment — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent** (`cmmc-agent/`)
on **AWS**, covering both **AWS Commercial** and **AWS GovCloud (US)**. The
recommended target is **ECS on Fargate** behind an **Application Load Balancer
(ALB)**, with the single application secret (`ANTHROPIC_API_KEY`) resolved from
**AWS Secrets Manager** via the ECS task execution role, and the app's local
JSON state (`status.json`, `settings.json`) placed on an **EFS** volume.

> The app is a single synchronous Flask process: a web GUI + a Claude-powered
> agentic backend that assesses/tracks/closes gaps across all 110 NIST 800-171
> practices for CMMC Level 2. There is **no database, no object storage, no
> server-side document upload, and no login/auth**. All persistent state is two
> local JSON files.

**Siblings:** [AZURE.md](AZURE.md) · [KUBERNETES.md](KUBERNETES.md) ·
[AIRGAPPED.md](AIRGAPPED.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md)
**Canonical guide:** [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

> **CUI / CMMC note:** CMMC Level 2 protects Controlled Unclassified Information
> (CUI). For any environment actually storing or processing CUI, **AWS GovCloud
> (US)** (partition `aws-us-gov`) is the relevant partition, and FIPS 140-2/3
> validated endpoints must be used. AWS Commercial is appropriate only for
> non-CUI evaluation, demo, or internal-tracking use.

---

## 1. Deployment architecture

- **Compute:** ECS service on **Fargate** running one task from the
  `cmmc-agent` container image (`python:3.11.9-slim`, non-root uid 10001,
  `EXPOSE 5050`, `CMD python server.py`).
- **Ingress:** an **ALB** terminates TLS (ACM certificate) and forwards to the
  task on container port **5050**. The ALB **target group health check** points
  at `GET /api/dashboard`, which returns scoring JSON and requires **no API
  key** — making it a reliable readiness/liveness signal.
- **Secret:** `ANTHROPIC_API_KEY` lives in **AWS Secrets Manager**, encrypted
  with a customer-managed KMS key (CMK). It is injected into the container as an
  environment variable through the ECS task definition `secrets` block using the
  **task execution role** — no static keys in images, task defs, or env files.
- **State:** `status.json` and `settings.json` are written by the app in its
  working directory (`/app`). Because Fargate task-local storage is ephemeral,
  mount an **EFS** access point at the app's state path so program status
  survives task restarts and redeploys. If EFS is not used, you must run a
  **single task** and accept that state is lost on task replacement.
- **Egress:** the task needs outbound HTTPS to **`api.anthropic.com`** to reach
  the AI backend (model `claude-opus-4-5`). Route this via a NAT gateway (public
  subnets) or a VPC egress path. In GovCloud/CUI networks that forbid hosted-AI
  egress, use the on-prem **Ollama** alternative — see
  [AIRGAPPED.md](AIRGAPPED.md).
- **Registry:** image stored in **Amazon ECR** (`...dkr.ecr.<region>.amazonaws.com`
  in Commercial, `...dkr.ecr.<region>.amazonaws.com` in GovCloud partition).

---

## 2. Topology

```
                         AWS Commercial: partition aws     |  GovCloud: partition aws-us-gov
                         Region us-east-1 (example)         |  Region us-gov-west-1 (example)
 ┌──────────┐   HTTPS
 │ Operator │ ────────────►┌───────────────────────┐
 │ browser  │              │  Application Load       │  health check: GET /api/dashboard
 └──────────┘              │  Balancer (ACM TLS)     │
                           └───────────┬─────────────┘
                                       │ :5050
                                       ▼
                        ┌──────────────────────────────┐
                        │ ECS / Fargate task            │
                        │  cmmc-agent (Flask, uid 10001)│
                        │  python server.py :5050       │
                        │                               │
                        │  env ANTHROPIC_API_KEY  ◄──────┼──── Secrets Manager (secrets block,
                        │  (from task exec role)         │      task execution role, KMS CMK)
                        │                               │
                        │  /app/status.json  ◄──────────┼──── EFS access point (persistent state)
                        │  /app/settings.json           │
                        └───────────────┬───────────────┘
                                        │ outbound HTTPS (NAT / egress)
                                        ▼
                              api.anthropic.com  (claude-opus-4-5)
                              — OR —  on-prem Ollama (see AIRGAPPED.md)
```

State is **local JSON on an EFS volume**. There is **no RDS/DynamoDB and no S3**
in this architecture — do not provision or expect them.

---

## 3. Prerequisites

- An AWS account in the correct partition: **Commercial** or **GovCloud (US)**.
  GovCloud requires a separate, sponsored account.
- VPC with at least two private subnets (for the Fargate task + EFS mount
  targets) and public subnets (for the ALB) across two AZs.
- **Amazon ECR** repository and the `cmmc-agent` image pushed to it.
- **AWS CLI v2** authenticated to the target partition, plus permissions to
  create ECS, EFS, ELB, IAM, Secrets Manager, and KMS resources.
- An **ACM** certificate for the ALB hostname.
- A **KMS CMK** for encrypting the secret (recommended over the AWS-managed key
  for CUI environments).
- Docker (to build/push the image) — the Dockerfile pre-builds wheels in a
  builder stage, so no build-time internet is needed at runtime.

---

## 4. Identity & credentials

**Prefer IAM roles — no static access keys anywhere.**

- **Task execution role** (`ecsTaskExecutionRole`) — used by the ECS agent to
  pull the image from ECR, write logs to CloudWatch, and **resolve the Secrets
  Manager secret** referenced in the task definition `secrets` block.
- **Task role** — the application's own identity while running. This app makes
  **no AWS API calls of its own**, so the task role can be minimal/empty. (Keep
  it distinct from the execution role as a least-privilege boundary.)

### Least-privilege policy for the task execution role

Attach the AWS-managed `AmazonECSTaskExecutionRolePolicy` for ECR + CloudWatch,
then add this inline policy scoped to **the specific secret ARN and CMK**:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadAnthropicApiKeySecret",
      "Effect": "Allow",
      "Action": "secretsmanager:GetSecretValue",
      "Resource": "arn:aws:secretsmanager:us-east-1:111122223333:secret:cmmc-agent/ANTHROPIC_API_KEY-*"
    },
    {
      "Sid": "DecryptSecretWithCmk",
      "Effect": "Allow",
      "Action": "kms:Decrypt",
      "Resource": "arn:aws:kms:us-east-1:111122223333:key/00000000-1111-2222-3333-444444444444",
      "Condition": {
        "StringEquals": {
          "kms:ViaService": "secretsmanager.us-east-1.amazonaws.com"
        }
      }
    }
  ]
}
```

**GovCloud:** replace every ARN partition `aws` with `aws-us-gov`, use a GovCloud
region (e.g. `us-gov-west-1`), and set `kms:ViaService` to
`secretsmanager.us-gov-west-1.amazonaws.com`. Example secret ARN prefix:
`arn:aws-us-gov:secretsmanager:us-gov-west-1:111122223333:secret:...`.

### Task definition `secrets` block (injects the key, no static value)

```json
"secrets": [
  {
    "name": "ANTHROPIC_API_KEY",
    "valueFrom": "arn:aws:secretsmanager:us-east-1:111122223333:secret:cmmc-agent/ANTHROPIC_API_KEY"
  }
]
```

---

## 5. Environment variables

The container reads exactly two environment variables. `ANTHROPIC_API_KEY` is
**required** for the AI backend; `PORT` is optional (default 5050).

### AWS Commercial (partition `aws`)

| Variable | Example | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | injected from `arn:aws:secretsmanager:us-east-1:111122223333:secret:cmmc-agent/ANTHROPIC_API_KEY` | AI backend credential; without it `POST /api/chat` returns HTTP 500 `{"error":"ANTHROPIC_API_KEY not set"}` |
| `PORT` | `5050` | Container listen port; ALB target group targets this |
| `AWS_REGION` | `us-east-1` | Partition/region for Secrets Manager + KMS resolution |
| `ANTHROPIC_BASE_URL` | *(unset)* | Optional SDK override; only set when repointing to a proxy/Ollama front-end (see [AIRGAPPED.md](AIRGAPPED.md)) |

### AWS GovCloud (US) (partition `aws-us-gov`) — CUI/CMMC target

| Variable | Example | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | injected from `arn:aws-us-gov:secretsmanager:us-gov-west-1:111122223333:secret:cmmc-agent/ANTHROPIC_API_KEY` | AI backend credential (GovCloud partition ARN) |
| `PORT` | `5050` | Container listen port |
| `AWS_REGION` | `us-gov-west-1` | GovCloud region for secret/KMS resolution |
| `AWS_USE_FIPS_ENDPOINT` | `true` | Forces AWS SDK/CLI calls to FIPS endpoints (required for CUI) |
| `ANTHROPIC_BASE_URL` | *(unset — hosted egress often disallowed)* | In GovCloud/CUI, prefer on-prem Ollama; see [AIRGAPPED.md](AIRGAPPED.md) |

**FIPS endpoints (GovCloud):** ensure Secrets Manager/KMS/STS resolve to FIPS,
e.g. `secretsmanager-fips.us-gov-west-1.amazonaws.com`,
`kms-fips.us-gov-west-1.amazonaws.com`, `sts-fips.us-gov-west-1.amazonaws.com`.
Setting `AWS_USE_FIPS_ENDPOINT=true` on the task and using FIPS VPC endpoints
enforces this.

---

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| ALB target group health check path | `/api/dashboard` | JSON score endpoint, no API key required |
| ALB health check success codes | `200` | `/api/dashboard` returns 200 with `overall_score_pct` |
| Container port | `5050` | Matches `EXPOSE 5050` and `PORT` default |
| Image | `<acct>.dkr.ecr.<region>.amazonaws.com/cmmc-agent:<tag>` | ECR image reference (partition-correct URL) |
| Task CPU / memory | `256` / `512` (0.25 vCPU / 0.5 GB) | Single lightweight Flask process; adjust as needed |
| EFS access point mount | `/app` (or a dedicated `/app/state` if code is adjusted) | Persists `status.json` + `settings.json` |
| Secret ARN | `.../secret:cmmc-agent/ANTHROPIC_API_KEY` | Source for the `secrets` injection |
| KMS CMK | `arn:aws[-us-gov]:kms:<region>:<acct>:key/<id>` | Encrypts the secret; `kms:Decrypt` granted to exec role |
| Desired count | `1` (without EFS) / `1+` (with EFS, see §8 caveat) | Scaling is constrained by shared-state semantics |

**Alternative platform:** to run on Kubernetes/EKS instead of ECS, use the
Deployment + PVC (EFS CSI) + Secrets pattern in
[KUBERNETES.md](KUBERNETES.md).

---

## 7. Verification

Run against the ALB DNS name (`https://<alb-host>`). No database or S3 exists,
so there is nothing to verify there — persistence is proven by the JSON state
files below.

**1. Health / dashboard (no key needed):**

```bash
curl -fsS https://<alb-host>/api/dashboard
# → {"overall_score_pct": <N>, "domains": { ... }}
```

A `200` with an `overall_score_pct` field confirms the container is up and the
ALB target is healthy.

**2. Secret resolved (key present):**

```bash
curl -sS -X POST https://<alb-host>/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"history":[{"role":"user","content":"score my program"}]}'
# Expected: {"reply": "...", "tool_log": [...]}
# FAILURE (secret NOT resolved): HTTP 500 {"error":"ANTHROPIC_API_KEY not set"}
```

If you see the 500 `ANTHROPIC_API_KEY not set` error, the Secrets Manager
injection or IAM permission (`secretsmanager:GetSecretValue` / `kms:Decrypt`)
is misconfigured — not a code problem.

**3. State write proven (status.json persisted):**

```bash
# Record before
curl -sS https://<alb-host>/api/dashboard | grep -o 'overall_score_pct":[0-9]*'

# Mark a control implemented
curl -sS -X POST https://<alb-host>/api/mark \
  -H 'Content-Type: application/json' \
  -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"verified via ALB"}'

# Score should reflect the change
curl -sS https://<alb-host>/api/dashboard | grep -o 'overall_score_pct":[0-9]*'
```

A changed score (and persistence across a task restart, if EFS is mounted)
proves `status.json` is being written to the EFS volume.

---

## 8. Day-2 operations

- **Upgrades / releases:** build a new image, push to ECR, register a new task
  definition revision, and update the ECS service (rolling deploy). The ALB
  drains connections and the `/api/dashboard` health check gates the new task.
- **Scaling caveat (important):** state is **local JSON**, not a shared DB. Two
  tasks writing to the *same* EFS `status.json` will race and can corrupt/lose
  writes; two tasks *without* shared storage will diverge. Therefore run
  **desired count = 1** for a consistent program record, OR keep a single task
  and scale vertically (CPU/memory). Horizontal scaling is **not** a supported
  correctness model for this app.
- **Backups:** back up the **EFS state volume** (AWS Backup on the EFS file
  system, or periodic copies of `status.json`/`settings.json`). These two files
  are the entire recoverable state.
- **Secret rotation:** rotate `ANTHROPIC_API_KEY` by updating the Secrets
  Manager secret value, then forcing a new deployment
  (`aws ecs update-service --force-new-deployment`) so the task re-reads the
  injected value. No secret is baked into the image, so no rebuild is required.
- **Logs:** the Flask process logs to stdout/stderr → CloudWatch Logs via the
  `awslogs`/`awsfirelens` log driver. Watch for the 500 `ANTHROPIC_API_KEY not
  set` line as the primary key-resolution signal.
- **Database migrations:** **none exist.** There is no database and no schema to
  migrate — do not run or expect migration steps.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| ALB target unhealthy | `/api/dashboard` not reachable on port 5050 | Confirm container port 5050, security group allows ALB→task, health check path is exactly `/api/dashboard`, success code `200` |
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set` | Secret not injected / IAM denies read | Verify task def `secrets` block ARN, `secretsmanager:GetSecretValue` + `kms:Decrypt` on the exec role, and region/partition match |
| Chat 500 but key IS set | Egress to `api.anthropic.com` blocked | Confirm NAT/egress path and security group egress; in GovCloud/CUI switch to on-prem Ollama ([AIRGAPPED.md](AIRGAPPED.md)) |
| Score resets after redeploy | No EFS mount; state on ephemeral task storage | Mount an EFS access point at the app state path; back it up |
| Score inconsistent / flips between requests | Multiple tasks with divergent local JSON | Set desired count = 1 (or single writer with EFS); do not horizontally scale |
| KMS `AccessDeniedException` on secret resolve | Exec role missing `kms:Decrypt` / wrong `kms:ViaService` | Add the CMK Decrypt statement with the region-correct `secretsmanager.<region>.amazonaws.com` (`-fips` in GovCloud) |
| GovCloud calls hit commercial endpoints | Missing FIPS/region config | Set `AWS_REGION` to a GovCloud region, `AWS_USE_FIPS_ENDPOINT=true`, use `*-fips.us-gov-west-1.amazonaws.com` VPC endpoints |
| Image pull fails | Wrong-partition ECR URL or missing ECR perms | Use the partition-correct ECR registry host and the managed `AmazonECSTaskExecutionRolePolicy` |

---

*Return to the canonical deployment guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).*
