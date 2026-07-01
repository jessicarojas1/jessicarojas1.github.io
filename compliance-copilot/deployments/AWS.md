# AWS — Compliance Copilot (Commercial + GovCloud)

Operator guide for deploying **Compliance Copilot** to **AWS Commercial** and **AWS GovCloud
(US)**. Compliance Copilot is a stateless **Next.js 14/16** app (`next start`, port 3000) whose
persistent state lives in **Supabase**. Recommended shapes: **ECS/Fargate** (simplest) or **EKS**
(see [KUBERNETES.md](./KUBERNETES.md)); **Amplify Hosting** works for Commercial demos. Secrets
come from **AWS Secrets Manager** via **IAM roles** (task role / IRSA); TLS at **ALB**.

> **CUI / data-residency note (read first):** CMMC L2 / NIST 800-171 data is frequently **CUI**.
> Hosted Supabase SaaS is **not** FedRAMP/IL-authorized and stores data outside the GovCloud
> boundary. For **GovCloud**, **self-host Supabase** inside the GovCloud account (containers +
> **RDS for PostgreSQL** + **S3** for storage) so all CUI stays in the `aws-us-gov` partition.
> Do **not** point a GovCloud deployment at hosted `*.supabase.co`. Commercial deployments may
> use hosted Supabase if the data is not CUI.

> Cross-links: [AZURE.md](./AZURE.md) · [KUBERNETES.md](./KUBERNETES.md) ·
> [AIRGAPPED.md](./AIRGAPPED.md) · [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md)

---

## 1. Deployment architecture

| Layer | Commercial | GovCloud (US) |
|---|---|---|
| Compute | ECS/Fargate or EKS (or Amplify for demo) | ECS/Fargate or EKS in `us-gov-west-1`/`us-gov-east-1` |
| Data | Supabase SaaS **or** self-hosted | **Self-hosted Supabase** (RDS Postgres + S3) — CUI in `aws-us-gov` |
| Secrets | Secrets Manager + IAM task role | Secrets Manager (gov endpoints) + IAM task role |
| TLS | ALB + ACM | ALB + ACM (gov) |
| AI | Anthropic API **or** self-hosted Ollama | Self-hosted Ollama recommended (no egress to hosted AI) |

---

## 2. Topology

```
   Client ──443──► ALB (ACM cert) ──► ECS Fargate task / EKS pod (Next.js :3000)
                                            │  IAM Task Role / IRSA
                                            ├──────────────► Secrets Manager (KMS-encrypted)
                                            │
                                            ▼
             Commercial: Supabase SaaS  OR  self-hosted Supabase
             GovCloud:   self-hosted Supabase → RDS PostgreSQL + S3 (aws-us-gov)
                                            │
             AI egress 443 ────────────────┴──► Anthropic (commercial) / Ollama (gov)
```

---

## 3. Prerequisites

| Item | Detail |
|---|---|
| AWS account | Commercial or GovCloud; permission to create ECS/EKS, ALB, IAM, Secrets Manager, RDS, S3 |
| AWS CLI | v2; GovCloud uses `--region us-gov-west-1` and gov endpoints |
| ECR | image registry in the same partition |
| Image | built from repo `Dockerfile`, pushed to ECR |
| VPC | private subnets for tasks, public for ALB, NAT for egress |
| RDS + S3 | if self-hosting Supabase (required for GovCloud CUI) |

---

## 4. Identity & credentials

Use **IAM roles**, never static keys:

- **ECS/Fargate**: an IAM **task role** with read access to the specific secrets.
- **EKS**: **IRSA** — a ServiceAccount annotated with the role ARN (see [KUBERNETES.md](./KUBERNETES.md)).

Least-privilege task-role policy (Commercial ARNs; swap partition/region for GovCloud):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": [
        "arn:aws:secretsmanager:us-east-1:<acct>:secret:compliance-copilot/*"
      ]
    },
    {
      "Effect": "Allow",
      "Action": ["kms:Decrypt"],
      "Resource": ["arn:aws:kms:us-east-1:<acct>:key/<cmk-id>"]
    }
  ]
}
```

**GovCloud:** partition `aws-us-gov`, e.g.
`arn:aws-us-gov:secretsmanager:us-gov-west-1:<acct>:secret:compliance-copilot/*`; use the FIPS
STS/KMS/Secrets Manager endpoints (`*.us-gov-west-1.amazonaws.com`, FIPS variants where
required). The Supabase **service role key** and **ANTHROPIC_API_KEY** live only in Secrets
Manager; the anon key can be a plain task-def env value (browser-public).

---

## 5. Environment variables

Names are identical across partitions; **values/endpoints** differ (Supabase location + AI
upstream). Inject secret-backed vars via ECS `secrets` (from Secrets Manager) and plain vars via
`environment`.

| Variable | Example (Commercial) | Example (GovCloud) | Purpose |
|---|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://abcd.supabase.co` | `https://supabase.grc.us-gov.internal` | Supabase URL (SaaS vs self-hosted in gov) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | `eyJhbGci...` (self-hosted) | Public anon key |
| `SUPABASE_SERVICE_ROLE_KEY` | *(Secrets Manager)* | *(Secrets Manager, gov)* | Service role key (server-only) |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | *(usually unset — Ollama)* | Hosted AI upstream |
| `AI_PROXY_TOKEN` | *(Secrets Manager)* | *(Secrets Manager)* | Gate `/api/ai/generate` (required in prod) |
| `APP_SESSION_SECRET` | *(Secrets Manager)* | *(Secrets Manager)* | HMAC signs `cc_session` (≥16 chars) |
| `APP_AUTH_USERNAME` | `issoadmin` | `issoadmin` | Login username |
| `APP_AUTH_PASSWORD` | *(Secrets Manager)* | *(Secrets Manager)* | Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | `evidence-files` | Storage bucket |
| `BRANDING_ADMIN_TOKEN` | *(Secrets Manager)* | *(Secrets Manager)* | Gates branding write |
| `NODE_ENV` | `production` | `production` | Secure cookies + fail-closed relay |
| `PORT` | `3000` | `3000` | Listen port |
| `HOSTNAME` | `0.0.0.0` | `0.0.0.0` | Bind all interfaces |
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Region for SDK/endpoint resolution |

> GovCloud: set `AWS_REGION=us-gov-west-1` (or `us-gov-east-1`), use partition `aws-us-gov` in
> all ARNs, and prefer FIPS endpoints for STS/KMS/Secrets Manager/S3.

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| ALB health-check path | `/` | Target group health (dashboard = health surface) |
| ALB health-check port | `3000` | Container port |
| ECS `containerPort` | `3000` | Task listen port |
| `next.config.js` `remotePatterns` | `*.supabase.co` / self-host host | Allowed image hosts |
| S3 bucket (self-host storage) | `evidence-files` | Supabase Storage backing bucket |

---

## 7. Verification

```bash
# Self-hosted Supabase (required for GovCloud CUI): provision RDS + apply schema
psql "$SUPABASE_DB_URL" -f supabase/schema.sql
# Create Storage bucket 'evidence-files' (Supabase Studio; backed by S3 in self-host)

APP=https://grc.example.com   # ALB DNS / Route 53 record

# Health / homepage
curl -sI $APP/ | head -1                                        # 200

# Secrets resolved (Secrets Manager via task role) → login works
curl -s -X POST $APP/api/auth/login -H 'Content-Type: application/json' \
  -d '{"username":"issoadmin","password":"<pw>"}'               # {"ok":true}

# AI relay authorized
curl -s -X POST $APP/api/ai/generate -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $AI_PROXY_TOKEN" \
  -d '{"prompt":"Draft narrative for 3.6.1"}'                   # {"text":"..."}

# DB row + storage object after Evidence upload
psql "$SUPABASE_DB_URL" -c "select count(*) from controls;"
psql "$SUPABASE_DB_URL" -c "select file_name,file_url from evidence order by created_at desc limit 1;"
```

Confirm the task role resolved secrets: ECS task → **stopped reason** empty; CloudWatch logs
show no "unable to retrieve secret" errors.

---

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade | Push new image to ECR; update ECS service (rolling) or `kubectl set image` on EKS |
| DB migration | Re-run `supabase/schema.sql` (idempotent) against RDS via `psql`/bastion |
| Scale | ECS service autoscaling (CPU/ALB req count) or EKS HPA |
| Backups | RDS automated backups + PITR + snapshots (KMS-encrypted); S3 versioning + cross-region for storage |
| Secret rotation | Update Secrets Manager version; force new ECS deployment to pick up (rotating `APP_SESSION_SECRET` logs users out) |
| Cert rotation | ACM auto-renews ALB cert |
| Logs | CloudWatch Logs (awslogs driver); ship to your SIEM |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Task fails to start | Task role lacks `secretsmanager:GetSecretValue`/`kms:Decrypt` | Attach the least-privilege policy in §4 |
| ALB 502/503 | Health check path/port wrong | Set health check `/` on 3000; app must be healthy |
| GovCloud reaches hosted Supabase | Wrong `NEXT_PUBLIC_SUPABASE_URL` | Point at in-partition self-hosted Supabase; keep CUI in `aws-us-gov` |
| `InvalidClientTokenId` in gov | Commercial endpoints/partition used | Set `AWS_REGION=us-gov-west-1`, use `aws-us-gov` ARNs + FIPS endpoints |
| AI relay 503 | Prod + no `AI_PROXY_TOKEN` | Provide token from Secrets Manager; or use Ollama in gov |
| Evidence upload fails | Bucket missing / S3 policy | Create `evidence-files`; verify Supabase storage → S3 config |
