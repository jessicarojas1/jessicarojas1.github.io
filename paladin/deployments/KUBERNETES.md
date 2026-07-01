# PALADIN — Kubernetes Deployment

Operator guide for running PALADIN on Kubernetes (AKS, EKS, vanilla, or on-prem).
A reference manifest ships at [`../docker/k8s.yaml`](../docker/k8s.yaml); this
guide hardens it for production: ingress + TLS, secret injection via CSI /
External Secrets, HPA, PDB, probes, and shared object storage.

Related: [AWS.md](AWS.md) (EKS/IRSA) · [AZURE.md](AZURE.md) (AKS/Workload
Identity) · [AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

- **Deployment** of the `paladin` image (`php:8.3-apache`), `replicas: 2+`. Each
  pod runs `startup.sh` → `install.php` (idempotent migrations) → Apache on `:80`.
- **Service** (ClusterIP) → **Ingress** (nginx/ALB/AGIC) terminating TLS.
- **PostgreSQL** — a managed DB (RDS/Azure Database) or an in-cluster operator
  (CloudNativePG/Zalando). Not the app pod.
- **Object storage** — with 2+ replicas you **must** use `STORAGE_DRIVER=s3`
  (S3/Blob/MinIO) so uploads are shared; the `ReadWriteOnce` PVC in the reference
  manifest only works for a single replica.
- **Secrets** — via CSI Secrets Store (AWS/Azure/Vault provider) or External
  Secrets Operator, not plain `Secret` in production.
- **Background jobs** — `CronJob`s invoke `cli/send_digests.php` and
  `cli/send_review_reminders.php`. In-request `Scheduler` handles
  scheduled-publish/auto-expire on live traffic.

> Note: the reference manifest's probes hit `/healthz` (liveness) and `/readyz`
> (readiness); both are implemented and return `{"status":"ok"}`. Use `/health`
> for a deep DB-aware check.

## 2. Topology

```
             Ingress (TLS)  paladin.example.gov
                    │
              ┌─────▼─────┐  Service (ClusterIP :80)
              │  paladin  │
        ┌─────┴─────┬─────┴─────┐        HPA 2..N pods, PDB minAvailable 1
        ▼           ▼           ▼
     pod(1)      pod(2)      pod(N)   php:8.3-apache, non-root uploads dir
        │  PDO       │           │  s3 PUT/GET (presigned)
        ▼            ▼           ▼
   ┌──────────┐             ┌──────────────┐
   │ Managed  │             │  S3 / Blob   │  (shared uploads — required @>1 replica)
   │PostgreSQL│             │  MinIO       │
   └──────────┘             └──────────────┘
   Secrets: CSI Secrets Store / External Secrets → env
   CronJobs: send_digests.php, send_review_reminders.php
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Kubernetes | 1.27+ |
| Ingress controller | ingress-nginx, AWS LB Controller, or AGIC |
| cert-manager | For automated TLS (or platform cert) |
| Secret injection | CSI Secrets Store driver **or** External Secrets Operator |
| StorageClass | Only if using PVC (single-replica local driver) |
| Managed PostgreSQL | 16 (13+), reachable from the cluster |
| Object store | S3/Blob/MinIO bucket for `STORAGE_DRIVER=s3` |
| Registry | Push the `paladin` image to a reachable registry |

## 4. Identity & credentials

Prefer **workload identity** so pods obtain cloud creds without static keys:

- **EKS**: IRSA — annotate the ServiceAccount with an IAM role; S3 access via
  role. See [AWS.md](AWS.md).
- **AKS**: Entra Workload Identity — federate the ServiceAccount to a managed
  identity with Blob/Key Vault access. See [AZURE.md](AZURE.md).
- **Vault**: Vault Agent Injector or CSI provider.

App-level secrets (`JWT_SECRET`, DB URL, admin creds) come from a secret manager
through CSI/External Secrets. Example ExternalSecret:

```yaml
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata: { name: paladin-secrets }
spec:
  secretStoreRef: { name: cluster-store, kind: ClusterSecretStore }
  target: { name: paladin-secrets }        # consumed via envFrom.secretRef
  data:
    - { secretKey: JWT_SECRET,     remoteRef: { key: paladin/jwt_secret } }
    - { secretKey: DATABASE_URL,   remoteRef: { key: paladin/database_url } }
    - { secretKey: ADMIN_EMAIL,    remoteRef: { key: paladin/admin_email } }
    - { secretKey: ADMIN_PASSWORD, remoteRef: { key: paladin/admin_password } }
```

Least-privilege pod SecurityContext (add to the reference Deployment):

```yaml
securityContext: { runAsNonRoot: true, runAsUser: 33, fsGroup: 33 }   # www-data
containers:
  - securityContext:
      allowPrivilegeEscalation: false
      readOnlyRootFilesystem: false        # Apache needs writable /var/run, uploads/
      capabilities: { drop: ["ALL"] }
```

## 5. Environment variables

Injected via `envFrom.secretRef: paladin-secrets` plus non-secret `env:`.

| Variable | Example | Purpose |
|---|---|---|
| `JWT_SECRET` | *(from secret)* | Token signing (**required**) |
| `DATABASE_URL` | `postgres://paladin:pw@pg:5432/paladin` | DB connection |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(from secret)* | First-run admin |
| `APP_ENV` | `production` | Prod behavior |
| `APP_NAME` | `PALADIN` | Brand |
| `APP_URL` | `https://paladin.example.gov` | Ingress host base URL |
| `STORAGE_DRIVER` | `s3` | **`s3` required for >1 replica** |
| `TRUSTED_PROXY_IPS` | `10.0.0.0/8` | Ingress/pod CIDR for `X-Forwarded-*` |
| `MAIL_TRANSPORT` | `smtp` | Delivery vs queued outbox |
| `PORT` | `80` | Apache listen port |
| `INSTALL_ATTEMPTS` / `INSTALL_DELAY` | `12` / `5` | Boot DB retry loop in `startup.sh` |

## 6. Configuration references

| Object | Setting | Purpose |
|---|---|---|
| Ingress annotation | `nginx.ingress.kubernetes.io/proxy-body-size: "40m"` | Allow ≤32M uploads (present in reference manifest) |
| HPA | `minReplicas: 2, maxReplicas: 6, CPU 70%` | Scale on load (needs S3 storage) |
| PodDisruptionBudget | `minAvailable: 1` | Keep availability during drains |
| Resources | req `100m`/`192Mi`, lim `1`/`512Mi` | From reference manifest |
| CSI SecretProviderClass / S3 settings | `s3_bucket`, `s3_region`, `s3_endpoint` | Stored in `settings` (encrypted at rest) |

### HPA + PDB (add these)

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: paladin }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: paladin }
  minReplicas: 2
  maxReplicas: 6
  metrics:
    - type: Resource
      resource: { name: cpu, target: { type: Utilization, averageUtilization: 70 } }
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: paladin }
spec: { minAvailable: 1, selector: { matchLabels: { app: paladin } } }
```

### CronJobs

```yaml
apiVersion: batch/v1
kind: CronJob
metadata: { name: paladin-digests }
spec:
  schedule: "0 7 * * *"
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: Never
          containers:
            - name: digests
              image: <registry>/paladin:latest
              envFrom: [ { secretRef: { name: paladin-secrets } } ]
              command: ["php","cli/send_digests.php","daily"]
---
# Same shape for review reminders:
#   command: ["php","cli/send_review_reminders.php","14","7"]  schedule "0 6 * * *"
```

## 7. Verification

```bash
kubectl rollout status deploy/paladin
kubectl get pods -l app=paladin

# Probes healthy
kubectl port-forward svc/paladin 8080:80 &
curl -fsS http://localhost:8080/healthz   # {"status":"ok"}
curl -fsS http://localhost:8080/readyz    # {"status":"ok"}
curl -fsS http://localhost:8080/health    # deep: {"status":"healthy","checks":{"database":"ok",...}}

# Secrets resolved (JWT/DB present → install.php succeeded)
kubectl logs deploy/paladin | grep -E "Installation complete|Applied migration"

# Login via ingress
curl -fsSI https://paladin.example.gov/login | head -1   # HTTP/2 200

# Upload accepted + object written (S3 driver): attach a file in UI, then
kubectl exec deploy/paladin -- php -r '
 require "config/database.php"; require "src/Database.php";
 var_dump(Database::fetchOne("SET search_path TO paladin; SELECT original_name, stored_path FROM attachments ORDER BY id DESC LIMIT 1"));'
aws s3 ls s3://$S3_BUCKET/uploads/attachments/ | tail   # object present

# Page + audit rows
kubectl exec deploy/paladin -- php -r '
 require "config/database.php"; require "src/Database.php";
 print_r(Database::fetchOne("SELECT title,status FROM paladin.pages ORDER BY id DESC LIMIT 1"));
 print_r(Database::fetchOne("SELECT action, (log_hash IS NOT NULL) AS chained FROM paladin.activity_log ORDER BY id DESC LIMIT 1"));'
```

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade | Push new image tag, `kubectl set image deploy/paladin paladin=<img>:<tag>`; rollout re-runs `install.php` migrations |
| Migrations | Applied automatically on pod start; multiple pods are safe (idempotent, tracked in `schema_migrations`) |
| Scale | HPA on CPU; ensure `STORAGE_DRIVER=s3` before scaling >1 |
| Rotate `JWT_SECRET` | Update secret manager → restart pods; invalidates sessions/tokens |
| Rotate DB creds | Update secret manager → rolling restart |
| Logs | `kubectl logs -f deploy/paladin`; ship via cluster logging (Fluent Bit) |
| Backups | Managed DB snapshots + object-store versioning; see [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md) |
| Rollback | `kubectl rollout undo deploy/paladin` (migrations are additive/idempotent) |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pod `CrashLoopBackOff` at boot | `JWT_SECRET`/DB unset or DB unreachable | Check `envFrom` secret exists; `startup.sh` retries DB 12× |
| Readiness never true | `/readyz` blocked (network policy) | Allow probe traffic to `:80` |
| Uploads disappear across pods | PVC/local driver with >1 replica | Switch to `STORAGE_DRIVER=s3` |
| 413 on upload | Ingress body limit | Set `proxy-body-size: "40m"` |
| Duplicate seed on scale-up | Multiple fresh installs racing | Only occurs on empty DB; seed runs once — pre-create DB or scale to 1 for first boot |
| S3 AccessDenied | Missing IRSA/Workload Identity | Bind ServiceAccount to role (see AWS/AZURE guides) |
| CronJob mail queued only | `MAIL_TRANSPORT=queued` | Set `smtp` + SMTP settings |
