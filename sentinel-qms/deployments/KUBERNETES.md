# Kubernetes Deployment — Sentinel QMS

> **Audience:** platform/operations engineers deploying Sentinel QMS to a
> Kubernetes cluster (EKS on AWS GovCloud, AKS on Azure Government, or any
> conformant cluster).
> **CUI notice:** run production/CUI clusters only inside an authorized
> GovCloud/Azure Gov boundary. This guide aligns with the manifests and Helm
> chart under [`infra/kubernetes/`](../infra/kubernetes/).

This app-layer guide covers the workload objects; the cloud-specific
provisioning of EKS/AKS, RDS/PostgreSQL, S3/Blob, and secret stores lives in
[`AWS.md`](AWS.md) and [`AZURE.md`](AZURE.md).

Sibling guides: [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) ·
[`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) · [`AWS.md`](AWS.md) ·
[`AZURE.md`](AZURE.md) · [`AIRGAPPED.md`](AIRGAPPED.md)

---

## 1. Deployment architecture

Two container workloads, backed by managed data services:

| Object | From | Notes |
|--------|------|-------|
| `backend` Deployment | `infra/kubernetes/base/backend-deployment.yaml` | FastAPI :8000, 3 replicas (HPA 3→12), `/health` probes. |
| `frontend` Deployment | `frontend-deployment.yaml` | nginx SPA :8080, 2 replicas (HPA 2→6), `/healthz` probe. |
| Services | `backend-service.yaml`, `frontend-service.yaml` | ClusterIP. |
| Ingress | `ingress.yaml` | TLS termination, routes `/api/v1`→backend, `/`→frontend. |
| ConfigMap | `configmap.yaml` | Non-secret env (`ENVIRONMENT`, `STORAGE_BACKEND`, `S3_BUCKET`, …). |
| Secret | `secret.yaml` (placeholder) | Replaced by ExternalSecret / CSI in cloud overlays. |
| HPA / PDB / ResourceQuota / NetworkPolicy | respective files | Autoscaling, availability, isolation. |
| ServiceAccount | `serviceaccount.yaml` | Annotated for IRSA (AWS) / Workload Identity (Azure). |

Deploy via **Kustomize** (`base` + `overlays/aws-govcloud` | `overlays/azure-gov`)
or the **Helm chart** (`infra/kubernetes/helm/sentinel-qms` with
`values-aws-govcloud.yaml` / `values-azure-gov.yaml`).

The **database is not run in-cluster** — point `DATABASE_URL` at managed RDS /
Azure Database for PostgreSQL. Migrations run either via the container entrypoint
(`AUTO_MIGRATE=1`) or, preferably in production, a dedicated **migration Job**
with `AUTO_MIGRATE=0` on the Deployment (§8).

---

## 2. Topology

```
                    Internet / private edge
                            │ 443
                    ┌───────▼────────┐
                    │ Ingress (nginx/│  TLS (cert from Secret / cert-manager)
                    │  ALB / AGIC)   │
                    └───┬────────┬───┘
              /api/v1   │        │  /  (SPA + static)
                        ▼        ▼
         ┌───────────────────┐  ┌───────────────────┐
         │ backend Deploy    │  │ frontend Deploy   │
         │ FastAPI :8000 x3+ │  │ nginx :8080 x2+   │
         │ SA: sentinel-backend  └───────────────────┘
         │  (IRSA / WI)      │
         └───┬───────────┬───┘
   psycopg   │           │  boto3 / azure-sdk (via SA identity)
   (TLS 5432)▼           ▼
   ┌──────────────────┐  ┌──────────────────────────┐
   │ RDS / Azure DB   │  │ S3 (SSE-KMS) / Blob (CMK) │
   │ PostgreSQL 16    │  │ uploads bucket/container   │
   └──────────────────┘  └──────────────────────────┘

   Secrets: ExternalSecret (AWS Secrets Manager) / SecretProviderClass
            (Azure Key Vault CSI) → K8s Secret → env
   NetworkPolicy: only ingress-nginx namespace → backend; default deny
```

---

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Kubernetes | 1.28+ | EKS/AKS or conformant. |
| `kubectl` | matched to cluster | — |
| `kustomize` | 5+ (or `kubectl -k`) | for base/overlays. |
| `helm` | 3.12+ | for the chart path. |
| Ingress controller | nginx / AWS LB Controller / AGIC | provides the Ingress. |
| Secret store add-on | External Secrets Operator **or** Secrets Store CSI Driver | injects DB/JWT/OIDC secrets. |
| `metrics-server` | latest | required for HPA. |
| Managed PostgreSQL 16 | reachable from cluster | `DATABASE_URL`. |
| Object storage | S3 (GovCloud) / Blob (Azure Gov) | uploads. |
| Container registry | ECR / ACR (or mirror) | holds `backend`/`frontend` images. |

---

## 4. Identity & credentials

**Prefer workload identity — no static cloud keys in the pod.**

- **AWS / EKS:** annotate the ServiceAccount for **IRSA** and let the backend
  pods assume a scoped IAM role for S3 + KMS + (optionally) Secrets Manager:
  ```yaml
  # overlays/aws-govcloud/patch-serviceaccount.yaml
  metadata:
    annotations:
      eks.amazonaws.com/role-arn: arn:aws-us-gov:iam::<acct>:role/sentinel-qms-irsa
  ```
- **Azure / AKS:** annotate for **Workload Identity** so pods use a managed
  identity for Blob + Key Vault:
  ```yaml
  metadata:
    annotations:
      azure.workload.identity/client-id: <managed-identity-client-id>
  ```
- **Application secrets** (DB DSN, `JWT_SECRET`, OIDC client secret) come from
  the cloud secret store, not from `secret.yaml`:
  - AWS: `overlays/aws-govcloud/external-secret.yaml` (External Secrets Operator
    → AWS Secrets Manager) and `delete-secret.yaml` removes the placeholder.
  - Azure: `overlays/azure-gov/secret-provider-class.yaml` (Key Vault CSI driver).

Least-privilege IAM for the backend role (GovCloud partition shown):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "Uploads", "Effect": "Allow",
      "Action": ["s3:GetObject","s3:PutObject","s3:DeleteObject"],
      "Resource": "arn:aws-us-gov:s3:::sentinel-qms-prod-uploads/*" },
    { "Sid": "ListBucket", "Effect": "Allow",
      "Action": ["s3:ListBucket"],
      "Resource": "arn:aws-us-gov:s3:::sentinel-qms-prod-uploads" },
    { "Sid": "Kms", "Effect": "Allow",
      "Action": ["kms:GenerateDataKey","kms:Decrypt"],
      "Resource": "arn:aws-us-gov:kms:us-gov-west-1:<acct>:key/<s3-cmk-id>" }
  ]
}
```

---

## 5. Environment variables

Non-secret keys → **ConfigMap** (`sentinel-backend-config`); secret keys →
**Secret** (`sentinel-backend-secrets`, populated by the secret store).

### ConfigMap (non-secret)

| Variable | Example (AWS GovCloud) | Example (Azure Gov) | Purpose |
|----------|------------------------|---------------------|---------|
| `ENVIRONMENT` | `production` | `production` | Hardens (JWT guard, HSTS). |
| `LOG_LEVEL` | `INFO` | `INFO` | Log level. |
| `CORS_ORIGINS` | `https://qms.example.gov` | `https://qms.example.us` | Allowed origins. |
| `DB_SCHEMA` | `sentinel_qms` | `sentinel_qms` | Dedicated schema. |
| `STORAGE_BACKEND` | `s3` | `azure_blob` | Upload backend. |
| `S3_BUCKET` | `sentinel-qms-prod-uploads` | — | S3 bucket. |
| `S3_REGION` | `us-gov-west-1` | — | GovCloud region. |
| `AZURE_STORAGE_CONTAINER` | — | `sentinel-qms` | Blob container. |
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` | your IdP | your IdP (Entra Gov) | SSO. |
| `TRUST_PROXY_HEADERS` | `true` | `true` | Behind ingress. |
| `AUTO_MIGRATE` | `0` (Deployment) / `1` (Job) | same | Migrations run by the Job. |
| `AUTO_SEED` | `0` in prod (run once) | same | Avoid reseeding on every rollout. |
| `ADMIN_AUTO_CREATE` | `false` | `false` | No auto admin in prod. |

> Endpoints/partitions differ by cloud: GovCloud uses partition `aws-us-gov`,
> region `us-gov-west-1`, FIPS S3/KMS/STS endpoints; Azure Gov uses
> `*.usgovcloudapi.net` and connection strings scoped to the Gov cloud. See
> [`AWS.md`](AWS.md) / [`AZURE.md`](AZURE.md).

### Secret (from secret store)

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql+psycopg://sentinel:***@<rds-host>:5432/sentinel_qms?sslmode=verify-full` | DB DSN. |
| `JWT_SECRET` | *(≥ 32-char secret)* | Token signing. |
| `OIDC_CLIENT_SECRET` | *(secret)* | SSO client secret. |
| `AZURE_STORAGE_CONNECTION_STRING` | *(Azure only)* | Blob access (or use Workload Identity). |

---

## 6. Configuration references

Key Helm `values.yaml` knobs (`infra/kubernetes/helm/sentinel-qms`):

| Value | Default | Purpose |
|-------|---------|---------|
| `backend.replicaCount` | `3` | Backend pods. |
| `backend.autoscaling.{min,max}Replicas` | `3` / `12` | HPA bounds. |
| `backend.autoscaling.targetCPUUtilizationPercentage` | `65` | HPA target. |
| `backend.pdb.minAvailable` | `2` | Disruption budget. |
| `backend.resources.requests/limits` | `250m/512Mi` → `1/1Gi` | Pod sizing. |
| `frontend.replicaCount` / `frontend.pdb.minAvailable` | `2` / `1` | SPA availability. |
| `secrets.externalSecrets.enabled` | `false` | When `true`, chart skips the placeholder Secret. |
| `ingress.host` / `ingress.tlsSecretName` | `qms.example.gov` / `sentinel-qms-tls` | Edge. |
| `networkPolicy.ingressNamespace` | `ingress-nginx` | Allowed ingress source ns. |
| `securityContext` | `readOnlyRootFilesystem`, `runAsNonRoot`, drop ALL caps | Hardening. |

Probes (backend): liveness/readiness on `GET /health`; frontend on `/healthz`.
`WEB_CONCURRENCY` set per pod to match the CPU limit (≈ 2–4). Provide a
`writable emptyDir` for `/app/var` / gunicorn temp given `readOnlyRootFilesystem`.

---

## 7. Verification

### 7.1 Deploy

```bash
# Kustomize (GovCloud overlay)
kubectl apply -k infra/kubernetes/overlays/aws-govcloud
# or Helm
helm upgrade --install sentinel-qms infra/kubernetes/helm/sentinel-qms \
  -n sentinel-qms --create-namespace -f infra/kubernetes/helm/sentinel-qms/values-aws-govcloud.yaml
```

### 7.2 Migration Job (production)

```bash
kubectl -n sentinel-qms create job sentinel-migrate-$(date +%s) \
  --image=<registry>/sentinel-qms/backend:<tag> -- \
  sh -lc 'alembic upgrade head'
kubectl -n sentinel-qms logs job/sentinel-migrate-<...> -f  # expect "Migrations applied"
```

### 7.3 Health, secrets resolved, login

```bash
kubectl -n sentinel-qms rollout status deploy/backend
kubectl -n sentinel-qms port-forward svc/backend 8000:8000 &

curl -fsS http://localhost:8000/health                       # {"status":"ok"}

TOKEN=$(curl -fsS -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@your-org.gov","password":"<pw>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
# A token proves the Secret (JWT_SECRET) and DATABASE_URL resolved from the store.
```

### 7.4 Upload accepted + scanned + object written

```bash
printf '%%PDF-1.4\n%%EOF\n' > /tmp/t.pdf
curl -fsS -X POST http://localhost:8000/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/t.pdf;type=application/pdf'                  # 201, stored_key=<uuid>.pdf
```

Confirm DB rows (from any pod that can reach the DB):

```bash
kubectl -n sentinel-qms exec deploy/backend -- python -c \
"from app.core.database import SessionLocal; from sqlalchemy import text; \
s=SessionLocal(); \
print(s.execute(text('SELECT stored_key, storage_backend FROM attachments ORDER BY id DESC LIMIT 1')).fetchone()); \
print(s.execute(text(\"SELECT action, actor_email FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1\")).fetchone())"
```

Confirm the object in object storage:

```bash
aws s3 ls s3://sentinel-qms-prod-uploads/ --region us-gov-west-1 --endpoint-url https://s3-fips.us-gov-west-1.amazonaws.com
# Azure: az storage blob list --account-name <acct> --container-name sentinel-qms --auth-mode login -o table
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| App upgrade | Push new image, bump `image.*.tag`, `helm upgrade` / `kubectl apply` — rolling update honors PDBs. |
| Migrations | Run the **migration Job** first (with `AUTO_MIGRATE=0` on the Deployment) so schema changes are decoupled from rollout. |
| Scale | HPA scales on CPU; tune `min/maxReplicas`. Add `REDIS_URL` so rate limiting is shared across replicas. |
| Backups | Managed DB automated backups + PITR (see [`AWS.md`](AWS.md)/[`AZURE.md`](AZURE.md)); bucket versioning for uploads. |
| Secret rotation | Rotate in Secrets Manager / Key Vault; ExternalSecret/CSI re-syncs; restart pods to pick up new env (`kubectl rollout restart deploy/backend`). |
| TLS rotation | cert-manager or updated `tlsSecretName`; ingress reloads. |
| Logs/metrics | stdout JSON → CloudWatch/Azure Monitor; scrape `/health`; alert on 5xx, p95 latency, pod restarts, audit-pipeline failures. |
| Roll back | `helm rollback sentinel-qms <rev>` (roll back schema separately only if the migration is backward-incompatible). |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Backend pods `CrashLoopBackOff`, `MIGRATION FAILED` | DB unreachable / bad DSN / migration error | Check Secret `DATABASE_URL`, NetworkPolicy to DB, run migration Job manually for full logs. |
| Pods `CreateContainerConfigError` | Secret keys missing | ExternalSecret/CSI not synced — check the operator/`SecretProviderClass` status. |
| `refusing to start ... insecure default` | `JWT_SECRET` weak/short | Store a ≥ 32-char secret in the secret store. |
| `readOnlyRootFilesystem` write errors | No writable temp/upload volume | Mount `emptyDir` at `/app/var` and gunicorn temp paths. |
| HPA `<unknown>` targets | metrics-server missing | Install metrics-server. |
| 403/AccessDenied writing to S3/Blob | IRSA/Workload Identity not assumed | Verify SA annotation + trust policy / federated credential; confirm KMS/CMK permissions. |
| 502 at ingress | Readiness failing on `/health` | Check DB reachability and probe path/port. |
| Wrong client IP / rate-limit bucket | Proxy headers untrusted | Set `TRUST_PROXY_HEADERS=true` + correct `TRUSTED_PROXY_COUNT`. |
