# AWS Deployment — Business Insight Dashboard

Operator guide for running the **Business Insight Dashboard** on AWS, covering
both **AWS Commercial** (partition `aws`) and **AWS GovCloud (US)** (partition
`aws-us-gov`).

The app is a **Streamlit** application (Python 3.11.9). It has **no external
database**, no built-in authentication, and **makes no AI/LLM calls**. Uploaded
CSVs are parsed with pandas **in memory only** and are never persisted or
transmitted. The **only** persisted state is `branding.json` (display name,
accent color, logo) written next to `app.py`.

> Because Streamlit communicates with the browser over **WebSockets**, every
> load balancer / ingress in front of this app **must** enable **session
> affinity (sticky sessions)**, forward the WebSocket upgrade headers, and use a
> generous idle timeout. This is the single most common source of production
> issues — see [Troubleshooting](#9-troubleshooting).

Sibling guides: [AZURE.md](./AZURE.md) · [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

Two supported topologies. Pick one:

| Option | Compute | Load balancer | Image | Persistence | Auth |
|--------|---------|---------------|-------|-------------|------|
| **A. ECS Fargate** (recommended default) | ECS Fargate task | ALB with **sticky** target group + WebSocket support | ECR | EFS mount for `branding.json` | ALB OIDC (Cognito/IdP) **or** oauth2-proxy sidecar |
| **B. EKS** | Kubernetes Deployment | ALB Ingress (AWS Load Balancer Controller) or NGINX Ingress | ECR | EFS CSI PVC (`ReadWriteMany`) | oauth2-proxy / ALB OIDC / API Gateway |

Key architectural facts for this app:

- **Container:** `python:3.11-slim`, non-root user, `EXPOSE 8501`,
  `HEALTHCHECK` on `/_stcore/health`, CMD runs
  `streamlit run app.py --server.port $PORT --server.address 0.0.0.0 --server.headless true`.
- **Port:** the app listens on `8501` by default; on Fargate/EKS map the target
  group / service to the container port. Render-style `$PORT` injection is
  honored via `STREAMLIT_SERVER_PORT`.
- **Health:** `GET /_stcore/health` returns `ok`. Use this for both the ALB
  target-group health check and the container `HEALTHCHECK`.
- **State:** ephemeral by design. The **only** durable artifact is
  `branding.json`. Mount **EFS** (Fargate) or an **EFS CSI PVC** (EKS) so it
  survives task replacement and is shared across replicas. Point
  `BRANDING_FILE` at the mounted path.
- **No database, no secrets by default.** AWS Secrets Manager / KMS are needed
  **only** for the reverse-proxy authentication layer (e.g. an OIDC client
  secret) — not for the app itself.

### Commercial vs GovCloud at a glance

| Concern | AWS Commercial | AWS GovCloud (US) |
|---------|----------------|-------------------|
| ARN partition | `aws` | `aws-us-gov` |
| Example region | `us-east-1` | `us-gov-west-1`, `us-gov-east-1` |
| Console | `console.aws.amazon.com` | `console.amazonaws-us-gov.com` |
| STS endpoint | `sts.us-east-1.amazonaws.com` | `sts.us-gov-west-1.amazonaws.com` |
| S3 FIPS endpoint | `s3-fips.us-east-1.amazonaws.com` | `s3-fips.us-gov-west-1.amazonaws.com` |
| KMS FIPS endpoint | `kms-fips.us-east-1.amazonaws.com` | `kms-fips.us-gov-west-1.amazonaws.com` |
| ECR registry host | `<acct>.dkr.ecr.us-east-1.amazonaws.com` | `<acct>.dkr.ecr.us-gov-west-1.amazonaws.com` |
| Cognito Hosted UI (OIDC) | Available | **Not available in all gov regions** — prefer oauth2-proxy against your gov IdP, or ALB OIDC to an approved IdP |

> **FIPS 140-2/3:** In GovCloud, prefer the `*-fips` regional endpoints for STS,
> KMS, S3 and ECR. Set your AWS SDK/CLI to FIPS with
> `AWS_USE_FIPS_ENDPOINT=true` on the CI/runtime that talks to these services.

---

## 2. Topology

```
                          ┌────────────────────────────────────────┐
   User (browser)         │                AWS VPC                  │
        │ HTTPS/WSS       │                                         │
        ▼                 │   ┌───────────────┐   ┌──────────────┐  │
  ┌───────────┐  TLS      │   │  ALB (public) │   │   EFS        │  │
  │  Route 53 │──────────►│   │  - HTTPS:443  │   │ branding.json│  │
  └───────────┘  ACM cert │   │  - OIDC auth  │   └──────▲───────┘  │
                          │   │  - sticky TG  │          │ NFS      │
                          │   │  - WS upgrade │          │          │
                          │   └──────┬────────┘   ┌──────┴───────┐  │
                          │          │ 8501       │ ECS Fargate  │  │
                          │          └───────────►│ task         │  │
                          │                       │ Streamlit    │  │
                          │   ┌───────────────┐   │ app.py :8501 │  │
                          │   │ Secrets Mgr   │   └──────────────┘  │
                          │   │ (OIDC secret) │   image ◄── ECR     │
                          │   │  + KMS CMK    │                     │
                          │   └───────────────┘                     │
                          └────────────────────────────────────────┘
```

EKS variant: replace "ECS Fargate task" with a Deployment behind an ALB Ingress;
replace EFS mount with an EFS CSI `PersistentVolumeClaim` (`ReadWriteMany`);
IAM comes from **IRSA** on the pod service account.

---

## 3. Prerequisites

- AWS account with access to the target partition/region (Commercial or
  GovCloud). GovCloud requires an approved GovCloud account.
- AWS CLI v2 configured for the target partition/region. For GovCloud FIPS:
  `export AWS_USE_FIPS_ENDPOINT=true`.
- **ECR** repository for the image.
- **ECS Fargate**: a cluster, an ALB, ACM certificate, private subnets, and an
  EFS file system + access point.
- **EKS**: a cluster (1.28+), AWS Load Balancer Controller, EFS CSI driver, and
  an OIDC provider associated for **IRSA**.
- Docker (or `finch`/`podman`) to build the image; a build host able to reach
  the connected internet (for airgapped, see [AIRGAPPED.md](./AIRGAPPED.md)).
- (Auth) An IdP: **Cognito user pool** (Commercial), or an approved OIDC IdP
  reachable from GovCloud, or **oauth2-proxy** configuration.

---

## 4. Identity & credentials

**Prefer IAM roles. Do not bake static keys into the image or task.**

| Topology | Identity mechanism |
|----------|--------------------|
| ECS Fargate | **Task Role** (app) + **Task Execution Role** (pull image, read logs, mount EFS) |
| EKS | **IRSA** — an IAM role bound to the pod's Kubernetes service account |
| CI/build | GitHub OIDC → short-lived role (`AssumeRoleWithWebIdentity`); avoid long-lived keys |

The application itself needs **no AWS permissions** — it has no database, no
S3 access, no secrets fetch. The only IAM needs are operational:

- **Task Execution Role**: pull from ECR, write to CloudWatch Logs, and (if the
  OIDC secret is injected as a task secret) read that one secret + decrypt with
  its KMS key.
- **EFS access**: granted via the EFS access point + mount target security
  group; no IAM action needed at runtime for NFS mounts, but restrict the SG.

### Least-privilege execution role policy (ECS)

Replace `PARTITION` with `aws` (Commercial) or `aws-us-gov` (GovCloud), and set
the region/account accordingly.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "PullImage",
      "Effect": "Allow",
      "Action": [
        "ecr:GetDownloadUrlForLayer",
        "ecr:BatchGetImage",
        "ecr:GetAuthorizationToken"
      ],
      "Resource": "*"
    },
    {
      "Sid": "Logs",
      "Effect": "Allow",
      "Action": ["logs:CreateLogStream", "logs:PutLogEvents"],
      "Resource": "arn:PARTITION:logs:REGION:ACCOUNT:log-group:/ecs/business-insight-dashboard:*"
    },
    {
      "Sid": "ReadProxyOidcSecretOnly",
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:PARTITION:secretsmanager:REGION:ACCOUNT:secret:bid/oidc-client-secret-*"
    },
    {
      "Sid": "DecryptSecretKmsOnly",
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": "arn:PARTITION:kms:REGION:ACCOUNT:key/CMK-ID",
      "Condition": {
        "StringEquals": { "kms:ViaService": "secretsmanager.REGION.amazonaws.com" }
      }
    }
  ]
}
```

> If you run auth entirely at the ALB (ALB OIDC action) or in a separate
> oauth2-proxy task, the **app task role can have no policy at all**.

**Static-credential fallback (discouraged):** only if IRSA/task roles are
impossible, inject `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` via a Secrets
Manager-backed task secret, scoped to the one read above, and rotate on a
schedule. Never store them in the image or `render.yaml`.

---

## 5. Environment variables

The app's own configuration surface is small. Values that differ between
partitions are for the **surrounding infrastructure / auth proxy**, not the app.

| Variable | Example (Commercial) | Example (GovCloud) | Purpose |
|----------|----------------------|--------------------|---------|
| `PORT` / `STREAMLIT_SERVER_PORT` | `8501` | `8501` | Port Streamlit binds. Set both if the platform injects `$PORT`. |
| `STREAMLIT_SERVER_ADDRESS` | `0.0.0.0` | `0.0.0.0` | Bind on all interfaces so the container is reachable. |
| `STREAMLIT_SERVER_HEADLESS` | `true` | `true` | Disable the "open browser" prompt; required in containers. |
| `STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION` | `true` | `true` | Keep Streamlit XSRF on. |
| `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` | `50` | `50` | Max CSV upload size in MB. Tune to expected files. |
| `STREAMLIT_BROWSER_GATHER_USAGE_STATS` | `false` | `false` | Disable telemetry (mandatory for GovCloud / no-egress). |
| `BRANDING_FILE` | `/mnt/branding/branding.json` | `/mnt/branding/branding.json` | Writable, EFS-mounted path for persisted branding. |

Auth-proxy / infrastructure variables (only when you enable authentication):

| Variable | Commercial | GovCloud | Purpose |
|----------|------------|----------|---------|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Region for SDK calls made by the auth proxy. |
| `AWS_USE_FIPS_ENDPOINT` | `false` (optional) | `true` | Force FIPS endpoints (STS/KMS/S3/ECR) in GovCloud. |
| OIDC issuer URL | `https://cognito-idp.us-east-1.amazonaws.com/<pool>` | your approved gov IdP issuer | OIDC discovery for ALB action / oauth2-proxy. |
| OIDC client secret ARN | `arn:aws:secretsmanager:us-east-1:...:secret:bid/oidc-client-secret` | `arn:aws-us-gov:secretsmanager:us-gov-west-1:...:secret:bid/oidc-client-secret` | Secret consumed by the proxy, decrypted with a KMS CMK. |

---

## 6. Configuration references — Streamlit `config.toml`

Ship `.streamlit/config.toml` in the image (or mount it). Env vars above
override these at runtime.

```toml
[server]
port = 8501
address = "0.0.0.0"
headless = true
enableXsrfProtection = true
enableCORS = false            # ALB terminates TLS and fronts the origin
maxUploadSize = 50            # MB; matches STREAMLIT_SERVER_MAX_UPLOAD_SIZE

[browser]
gatherUsageStats = false      # no telemetry egress

[global]
developmentMode = false
```

| Key | Value | Purpose |
|-----|-------|---------|
| `server.port` | `8501` | Container listen port (target group target). |
| `server.address` | `0.0.0.0` | Reachable inside the task/pod network. |
| `server.headless` | `true` | Container-safe startup. |
| `server.enableXsrfProtection` | `true` | CSRF protection for the WS/session. |
| `server.enableCORS` | `false` | ALB is the single trusted origin. |
| `server.maxUploadSize` | `50` | Guardrail on in-memory CSV size. |
| `browser.gatherUsageStats` | `false` | No outbound analytics. |

---

## 7. Verification

Run after each deploy (Commercial or GovCloud):

```bash
# 1. Health endpoint returns "ok" (target-group + container health source)
curl -fsS https://<host>/_stcore/health        # -> ok

# 2. Target/health status is healthy
#    ECS: aws ecs describe-services ... --query 'services[0].deployments'
#    ALB: aws elbv2 describe-target-health --target-group-arn <arn>
```

Manual checklist:

- [ ] `curl -fsS https://<host>/_stcore/health` prints `ok`.
- [ ] Login **through the auth proxy** succeeds (OIDC redirect completes).
- [ ] Dashboard opens over **HTTPS/WSS** with no console WebSocket errors.
- [ ] Upload a sample CSV from `sample_data/` — file parses in memory.
- [ ] **KPIs, charts, and rule-based insights render** for the uploaded data.
- [ ] Change branding (name/accent/logo) → confirm `branding.json` is
      **written to the EFS mount** (`ls -l $BRANDING_FILE` on the task/pod).
- [ ] The ALB **target group** shows the task/pod **healthy**.

---

## 8. Day-2 operations

- **Upgrades:** build a new image, push a new immutable tag to ECR, update the
  ECS task definition (or EKS Deployment image) → **rolling deploy**. ECS
  circuit breaker + ALB health checks gate the rollout; keep the sticky target
  group during the swap.
- **Scaling:** scale out replicas freely — the app is stateless except
  `branding.json`. **Sticky sessions are mandatory** so a user's WebSocket stays
  pinned to one task. EFS provides shared branding across replicas.
- **Backups:** the only durable state is `branding.json` on EFS. Enable **AWS
  Backup** for the EFS file system (or periodic EFS snapshots). No database to
  back up. Uploaded CSVs are ephemeral and require no backup.
- **Cert/secret rotation:** rotate the ACM certificate (auto-renew for
  ACM-issued); rotate the OIDC client secret in Secrets Manager and re-deploy
  the proxy. Rotate KMS CMK per your key policy.
- **Logs:** stream container stdout/stderr to **CloudWatch Logs**
  (`/ecs/business-insight-dashboard` or the EKS Fluent Bit pipeline). Streamlit
  logs startup, session, and upload events. Alarm on task restarts and
  unhealthy targets.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Dashboard connects then repeatedly disconnects / "Please wait…" spinner | ALB target group not **sticky**, or WebSocket idle timeout too short | Enable target-group **stickiness**; raise ALB **idle timeout** (e.g. 300s); ensure the listener forwards `Upgrade`/`Connection` headers. |
| Health check 404 | Wrong probe path | Point the target group / container `HEALTHCHECK` at exactly `/_stcore/health`. |
| Target stuck **unhealthy** | Wrong port or path in health check, or app still booting | Health check port must be **8501** (or your `STREAMLIT_SERVER_PORT`), path `/_stcore/health`; increase the health-check grace period. |
| Branding resets after every deploy / scale event | `branding.json` on **ephemeral** task storage | Mount **EFS** (Fargate) or **EFS CSI PVC** `ReadWriteMany` (EKS) and set `BRANDING_FILE` to that path. |
| Upload rejected as too large | `maxUploadSize` too low | Raise `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` / `server.maxUploadSize`. |
| GovCloud SDK calls fail / wrong endpoint | Using commercial endpoints in `aws-us-gov` | Set correct region (`us-gov-west-1`), `AWS_USE_FIPS_ENDPOINT=true`, and `aws-us-gov` ARNs. |
| Image pull denied | Execution role missing ECR perms, or wrong registry host | Grant ECR read to the **execution role**; use the partition-correct ECR host. |
| Login loops / 401 at proxy | OIDC secret/issuer mismatch | Verify the OIDC issuer URL and the client secret resolved from Secrets Manager; check redirect URI. |

---

*See also: [AZURE.md](./AZURE.md), [AIRGAPPED.md](./AIRGAPPED.md).*
