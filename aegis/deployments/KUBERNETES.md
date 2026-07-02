# AEGIS GRC — Kubernetes Deployment

Audience: platform operators running AEGIS on Kubernetes (self-managed, EKS, AKS, GKE,
or an on-prem/air-gapped distribution). This guide is built around the repo's hardened
manifest **[`deploy/k8s/aegis.yaml`](../deploy/k8s/aegis.yaml)** and the hardened image
**[`docker/Dockerfile.hardened`](../docker/Dockerfile.hardened)**, which target Pod
Security **restricted**, `runAsNonRoot`, `readOnlyRootFilesystem`, and dropped
capabilities.

> Sibling guides: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AZURE.md](AZURE.md) ·
> [AWS.md](AWS.md) · [AIRGAPPED.md](AIRGAPPED.md)

---

## 1. Deployment architecture

| Object | Role |
|--------|------|
| `Deployment aegis` (3 replicas, HPA 3→10) | PHP 8.3 / Apache on **:8080**, non-root uid/gid **33** (`www-data`), read-only rootfs, tmpfs for `/tmp` + `/var/run/apache2` |
| `Service aegis` | ClusterIP `:80` → targetPort `8080` |
| Ingress (nginx-ingress or your controller) | TLS termination + external exposure → Service :80 |
| PostgreSQL 16 | **Not** created by `aegis.yaml` — use a managed DB (RDS/Azure PG/Cloud SQL) or an in-cluster operator (CloudNativePG/Zalando). Referenced as `aegis-db` |
| `Secret aegis-secrets` | Mounted as **files** at `/run/secrets/aegis/*` and consumed via `*_FILE` env (never plain env) |
| `PVC aegis-uploads` | `uploads/` when using the local storage driver (use `ReadWriteMany` if >1 replica writes; prefer S3-driver to avoid RWX) |
| `PodDisruptionBudget` (minAvailable 2), `NetworkPolicy` (default-deny + allow), `HPA` | resilience, isolation, autoscale |
| Cron/worker | A separate Deployment or `CronJob`s running the scheduled scripts (see §7) |

Probes: `livenessProbe` → `GET /healthz` (process up), `readinessProbe` → `GET /readyz`
(DB reachable → traffic gating).

## 2. Topology

```
                Internet (443/TLS)
                      │
              ┌───────▼─────────┐   namespace: ingress-nginx
              │  Ingress (TLS)  │   cert-manager or agency PKI
              └───────┬─────────┘
                      │ :80 → :8080
       ┌──────────────▼───────────────┐  namespace: aegis (PSA=restricted)
       │  Service aegis (ClusterIP)   │  NetworkPolicy default-deny + allow
       │        │       │       │     │
       │  ┌─────▼──┐ ┌──▼───┐ ┌─▼────┐│  Deployment aegis  (HPA 3–10, PDB≥2)
       │  │ pod    │ │ pod  │ │ pod  ││  runAsNonRoot uid33, RO rootfs,
       │  │ :8080  │ │:8080 │ │:8080 ││  caps drop ALL, seccomp RuntimeDefault
       │  └───┬────┘ └──┬───┘ └──┬───┘│  secrets → /run/secrets/aegis/*  (*_FILE)
       └──────┼─────────┼────────┼────┘
              │ 5432                └── PVC aegis-uploads (local driver) or S3 (settings)
       ┌──────▼───────────────┐
       │  PostgreSQL 16        │  managed (RDS/AzurePG/CloudSQL) or in-cluster operator
       └───────────────────────┘
       ┌───────────────────────┐
       │  Deployment aegis-cron│  run_workflows + dispatch_webhooks loop
       │  + CronJobs (schedules)│  notifications / reports / metrics / email
       └───────────────────────┘
```

## 3. Prerequisites

| Item | Version | Notes |
|------|---------|-------|
| Kubernetes | 1.27+ | Pod Security Admission `restricted` supported |
| `kubectl` / Helm (optional) | matching | apply manifests / templatize |
| Ingress controller | ingress-nginx or equivalent | the sample NetworkPolicy expects `namespaceSelector name=ingress-nginx` |
| Container registry | ECR/ACR/GCR/Harbor | push the hardened image, pin by `@sha256` in prod |
| PostgreSQL 16 | managed or operator | with TLS |
| Secret backend | CSI Secrets Store / External Secrets / Sealed Secrets / SOPS | do not commit real secret values |
| StorageClass | RWX (e.g. EFS/Azure Files) if local driver + >1 writer, else S3 driver | |

## 4. Identity & credentials

Prefer **workload identity** over static keys wherever the platform offers it:

- **EKS:** IRSA — annotate the ServiceAccount with an IAM role; the pod's SDK/STS calls
  assume it (used for RDS IAM auth and S3). See [AWS.md](AWS.md).
- **AKS:** Entra Workload Identity — federate the ServiceAccount to a managed identity.
  See [AZURE.md](AZURE.md).
- **GKE:** Workload Identity binding to a Google service account.

`aegis.yaml` sets `automountServiceAccountToken: false`; enable it only on the pods
that actually need a projected identity token (e.g. when using CSI/IRSA).

Secrets are injected as **files**, matching `Secrets::hydrate()` and the `*_FILE`
convention:

```yaml
env:
  - { name: JWT_SECRET_FILE,          value: /run/secrets/aegis/jwt_secret }
  - { name: AUDIT_HMAC_KEY_FILE,      value: /run/secrets/aegis/audit_hmac_key }
  - { name: APP_ENCRYPTION_KEY_FILE,  value: /run/secrets/aegis/app_encryption_key }
  - { name: DB_PASS_FILE,             value: /run/secrets/aegis/db_password }
```

Populate `Secret aegis-secrets` out-of-band. Example with External Secrets Operator:

```yaml
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata: { name: aegis-secrets, namespace: aegis }
spec:
  secretStoreRef: { name: cluster-store, kind: ClusterSecretStore }
  target: { name: aegis-secrets, creationPolicy: Owner }
  data:
    - { secretKey: jwt_secret,         remoteRef: { key: aegis/jwt_secret } }
    - { secretKey: audit_hmac_key,     remoteRef: { key: aegis/audit_hmac_key } }
    - { secretKey: app_encryption_key, remoteRef: { key: aegis/app_encryption_key } }
    - { secretKey: db_password,        remoteRef: { key: aegis/db_password } }
```

The manifest's least-privilege runtime DB role is `aegis_app` (see
`database/roles.sql`) — do not run the app as the DB owner.

## 5. Environment variables

Non-secret env is set inline in the Deployment; secrets via `*_FILE`. Full set:

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Production hardening + HSTS |
| `APP_URL` | `https://grc.example.gov` | Canonical URL / redirect allowlist |
| `DB_HOST` | `aegis-db` | Postgres service/endpoint |
| `DB_PORT` | `5432` | Postgres port |
| `DB_NAME` | `aegis` | Database |
| `DB_USER` | `aegis_app` | Least-privilege runtime role |
| `DB_PASS_FILE` | `/run/secrets/aegis/db_password` | DB password (file) |
| `JWT_SECRET_FILE` | `/run/secrets/aegis/jwt_secret` | Auth token signing (file) |
| `AUDIT_HMAC_KEY_FILE` | `/run/secrets/aegis/audit_hmac_key` | Audit chain HMAC (file) |
| `APP_ENCRYPTION_KEY_FILE` | `/run/secrets/aegis/app_encryption_key` | Settings encryption (file) |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | set on the **migration Job only** | First admin seed (see §7) |
| `SESSION_DRIVER` | `pg` | **Required for >1 replica** — shared sessions in Postgres |
| `REDIS_URL` | `rediss://…` | Optional shared cache + rate-limit counters across pods |
| `TRUSTED_PROXY_IPS` | ingress pod CIDR | Trust `X-Forwarded-*` from the ingress |
| `SMTP_*` | provider values | Outbound mail (secrets via `*_FILE` too) |

> Multi-replica correctness: with 3+ replicas you **must** set `SESSION_DRIVER=pg` so
> sessions are shared, and either use the **S3 storage driver** (configured in
> Admin → Storage) or a `ReadWriteMany` PVC for uploads. `REDIS_URL` makes rate limits
> and cache counters correct cluster-wide.

## 6. Configuration references

| Setting | Where | Purpose |
|---------|-------|---------|
| Replicas / HPA | Deployment `replicas: 3`, HPA `min 3 / max 10 @ 70% CPU` | scale |
| Resources | requests 100m/128Mi, limits 1/512Mi | tune to your load |
| PDB | `minAvailable: 2` | keep quorum during drains/upgrades |
| Probes | `/healthz` liveness, `/readyz` readiness | orchestration health |
| NetworkPolicy | default-deny + allow ingress:8080 from ingress-nginx, egress DNS + 5432 | isolation |
| Security context | runAsNonRoot uid33, RO rootfs, drop ALL caps, seccomp RuntimeDefault | PSA restricted |
| Upload size | ingress `nginx.ingress.kubernetes.io/proxy-body-size: 55m` | match app cap |

## 7. Build, deploy, migrate

```bash
# 1. Build + push the HARDENED image (non-root, RO-rootfs friendly)
docker build -f docker/Dockerfile.hardened -t <registry>/aegis:<gitsha> .
docker push <registry>/aegis:<gitsha>

# 2. Edit deploy/k8s/aegis.yaml: set image (pin @sha256), APP_URL, DB_HOST; wire secrets.
kubectl apply -f deploy/k8s/aegis.yaml
kubectl -n aegis rollout status deploy/aegis
```

**Migrations** — run as a one-shot Job (do not rely on per-pod install on a shared DB):

```yaml
apiVersion: batch/v1
kind: Job
metadata: { name: aegis-migrate, namespace: aegis }
spec:
  template:
    spec:
      restartPolicy: Never
      automountServiceAccountToken: false
      securityContext: { runAsNonRoot: true, runAsUser: 33, runAsGroup: 33, seccompProfile: { type: RuntimeDefault } }
      containers:
        - name: migrate
          image: <registry>/aegis:<gitsha>
          command: ["php", "/var/www/html/install.php"]
          env:
            - { name: DB_HOST, value: aegis-db }
            - { name: DB_NAME, value: aegis }
            - { name: DB_USER, value: aegis }          # owner role for DDL
            - { name: DB_PASS_FILE, value: /run/secrets/aegis/db_password }
            - { name: ADMIN_EMAIL,    valueFrom: { secretKeyRef: { name: aegis-admin, key: email } } }
            - { name: ADMIN_PASSWORD, valueFrom: { secretKeyRef: { name: aegis-admin, key: password } } }
          volumeMounts:
            - { name: secrets, mountPath: /run/secrets/aegis, readOnly: true }
      volumes:
        - { name: secrets, secret: { secretName: aegis-secrets } }
```
`install.php` is idempotent (applies `schema.sql` + all migrations, seeds the admin).
Use the DB **owner** role for the migration Job (DDL) and the least-privilege
`aegis_app` role for the runtime Deployment.

**Scheduled jobs** — either a small always-on worker Deployment (the compose loop:
`while true; do php scripts/run_workflows.php; php scripts/dispatch_webhooks.php; sleep 60; done`)
or discrete `CronJob`s:

| CronJob | schedule | command |
|---------|----------|---------|
| notifications | `0 * * * *` | `php scripts/send_notifications.php` |
| webhooks | `* * * * *` | `php scripts/dispatch_webhooks.php` |
| reports | `0 * * * *` | `php scripts/send_scheduled_reports.php` |
| workflows | `*/15 * * * *` | `php scripts/run_workflows.php` |
| metrics | `0 1 * * *` | `php scripts/capture_metrics_snapshot.php` |
| email-queue | `*/5 * * * *` | `php scripts/drain_email_queue.php` |

## 8. Verification

```bash
# 1. Liveness/readiness from inside the cluster
kubectl -n aegis exec deploy/aegis -- curl -fsS http://localhost:8080/healthz
kubectl -n aegis exec deploy/aegis -- curl -fsS http://localhost:8080/readyz   # database:ok
# 2. Secrets resolved — files mounted + audit chain intact
kubectl -n aegis exec deploy/aegis -- ls -l /run/secrets/aegis
kubectl -n aegis exec deploy/aegis -- php /var/www/html/scripts/verify_audit_log.php   # exit 0
# 3. Ingress + login (through the public URL; CSRF-protected form)
B=https://grc.example.gov; JAR=$(mktemp)
CSRF=$(curl -sc "$JAR" $B/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sb "$JAR" -i -X POST $B/login --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=$ADMIN_PASSWORD" | head -n1   # 302
# 4. Upload accepted + indexed + object written (attach evidence in UI, then:)
kubectl -n aegis exec deploy/aegis -- psql "$DATABASE_URL" -c \
  "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash FROM evidence_files ORDER BY id DESC LIMIT 1;"
# For S3 storage driver, confirm the object landed:
aws s3 ls s3://<bucket>/uploads/evidence/ | tail
# 5. Rollout health
kubectl -n aegis get pods,hpa,pdb
```

## 9. Day-2 operations

- **Upgrade (rolling):** build new `<gitsha>` image, run the migration Job, then
  `kubectl -n aegis set image deploy/aegis aegis=<registry>/aegis:<gitsha>`. PDB keeps
  ≥2 pods; readiness gating holds traffic until `/readyz` passes.
- **Scaling:** HPA autoscales 3→10 on CPU. Ensure `SESSION_DRIVER=pg` and shared
  storage first. Add `REDIS_URL` for correct distributed rate limiting.
- **Backups:** back up the managed/operator Postgres (snapshots/`pg_dump`) and the
  uploads (S3 versioning or PVC snapshot). Store the KMS-wrapped/External-Secrets
  material for `JWT_SECRET`/`AUDIT_HMAC_KEY`/`APP_ENCRYPTION_KEY` — losing
  `APP_ENCRYPTION_KEY` makes encrypted settings unrecoverable.
- **Secret rotation:** update the backend secret; External Secrets syncs it; restart
  the Deployment (`kubectl rollout restart`). Rotating `AUDIT_HMAC_KEY` invalidates
  verification of pre-rotation audit rows — retain old keys.
- **Certs:** cert-manager renews ingress TLS automatically; for agency PKI, reimport
  and reload.
- **Logs/observability:** stdout/stderr (Apache logs routed there) → your log stack;
  scrape probes; alert on `/readyz` failures, HPA saturation, and PDB blocks. Run
  `verify_audit_log.php` on a schedule.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod `CreateContainerError` under PSA restricted | securityContext incomplete | keep runAsNonRoot uid33, drop ALL caps, RO rootfs + tmpfs mounts as in `aegis.yaml` |
| App can't write `/tmp` or Apache PID | RO rootfs without tmpfs | mount `emptyDir{medium:Memory}` at `/tmp` and `/var/run/apache2` |
| Readiness never passes | DB unreachable / NetworkPolicy blocks 5432 | verify egress allow to `aegis-db:5432` + DNS; check `DB_*` |
| Random logouts across replicas | file sessions per-pod | set `SESSION_DRIVER=pg` |
| Uploads visible on one pod only | `ReadWriteOnce` PVC across replicas | use S3 driver or an RWX StorageClass |
| Login fails cluster-wide | CSRF token from a different pod / no shared cache | acceptable per-request; for scripted tests reuse one pod or set `REDIS_URL` |
| `503` from ingress during deploy | too few ready pods / PDB tight | check readiness, resources, HPA; ensure image pulls (registry auth) |
| Migration Job fails on DDL permission | ran as least-privilege `aegis_app` | run the Job as the DB **owner** role |
| Secrets empty in pod | `*_FILE` path wrong or Secret not synced | confirm mount path `/run/secrets/aegis/*` and ExternalSecret status |
