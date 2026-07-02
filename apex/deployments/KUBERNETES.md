# APEX — Kubernetes Deployment

Operator guide for running **APEX** on Kubernetes. APEX is a stateless PHP 8.2 +
Apache container (the shipped `apex/Dockerfile`) serving a vanilla-JS SPA and
`/api/*` REST API on port **8080** as non-root `www-data`, backed by external
PostgreSQL 16. Auth is CAC/PIV-simulated (bcrypt PINs + HS256 JWT).

The image already satisfies `runAsNonRoot`, drop-ALL-caps, and read-only rootfs
(with `/tmp` as tmpfs) — Apache writes its PidFile/lock to `/tmp` and logs to
stdout/stderr.

Related: [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) ·
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) · [AWS](AWS.md) · [AZURE](AZURE.md) ·
[AIRGAPPED](AIRGAPPED.md)

---

## 1. Deployment architecture

| Object | Role |
|--------|------|
| `Deployment apex` | N replicas of the app pod (stateless; scale horizontally). |
| `Service apex` | ClusterIP → pod port 8080. |
| `Ingress apex` | TLS termination + host routing to the Service. |
| `Secret apex-secrets` | `JWT_SECRET`, `DATABASE_URL` (ideally via CSI/ExternalSecrets). |
| PostgreSQL | **External**: managed (RDS / Azure DB / Cloud SQL) or an in-cluster StatefulSet + PVC. APEX itself holds no local state. |
| `Job apex-migrate` (optional) | One-shot `scripts/migrate.php` to seed a fresh DB before rollout. |
| `HPA` / `PDB` | Autoscale on CPU; keep ≥1 pod available during disruptions. |

Because migration auto-runs on every pod start and is idempotent (skips when the
`users` table exists), a dedicated Job is optional — but recommended so app pods
don't race on first seed.

---

## 2. Topology

```
                Internet / mesh
                     │ :443
                     ▼
               ┌───────────┐
               │  Ingress  │ TLS, host apex.example
               └─────┬─────┘
                     ▼
               ┌───────────┐   Service apex (ClusterIP:80 → 8080)
               │  Service  │
               └─────┬─────┘
        ┌────────────┼────────────┐
        ▼            ▼            ▼
   ┌────────┐   ┌────────┐   ┌────────┐   Deployment apex (replicas)
   │ pod    │   │ pod    │   │ pod    │   readOnlyRootFS + tmpfs /tmp
   │ :8080  │   │ :8080  │   │ :8080  │   runAsNonRoot (www-data uid 33)
   └───┬────┘   └───┬────┘   └───┬────┘
       └────────────┴────────────┘
                     │ DATABASE_URL (Secret)
                     ▼
             External PostgreSQL 16
        (RDS / Azure DB / Cloud SQL / StatefulSet)
```

---

## 3. Prerequisites

| Item | Version / detail |
|------|------------------|
| Kubernetes | 1.27+ |
| kubectl / Helm | matching cluster; Helm 3 if templating |
| Ingress controller | nginx-ingress / Contour / cloud ALB controller |
| cert-manager (or managed cert) | TLS issuance |
| Secrets provider | Secrets Store CSI Driver **or** External Secrets Operator (preferred over raw Secrets) |
| StorageClass | Only if running Postgres in-cluster |
| Container registry | Pushable target for the built `apex` image |

Build & push:

```bash
docker build -t <registry>/apex:<tag> apex/
docker push <registry>/apex:<tag>
```

---

## 4. Identity & credentials

Prefer **workload identity** so pods pull secrets without static keys:

- **EKS**: IRSA — annotate the ServiceAccount with an IAM role; use External
  Secrets Operator → AWS Secrets Manager. (Partition/endpoint notes: [AWS.md](AWS.md).)
- **AKS**: Microsoft Entra Workload ID + Key Vault via CSI. (See [AZURE.md](AZURE.md).)
- **GKE / other**: External Secrets Operator against your vault/KMS.

Least-privilege: the app SA needs only read access to the `JWT_SECRET` and
`DATABASE_URL` secret material — nothing else. No cluster-admin, no node access.

`SecurityContext` (enforce non-root, matches the image):

```yaml
securityContext:
  runAsNonRoot: true
  runAsUser: 33            # www-data
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true
  capabilities: { drop: ["ALL"] }
```

Mount an `emptyDir` at `/tmp` (Apache PidFile/lock/mutex live there).

---

## 5. Environment variables

Injected from `apex-secrets` (via CSI/ESO) and a ConfigMap:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:***@apex-db:5432/apex?sslmode=require` | PDO PgSQL connection; use `sslmode=require` for managed DBs. From Secret. |
| `JWT_SECRET` | 32+ random chars | HS256 signing key. From Secret. App fails closed if <32 in production. |
| `APP_ENV` | `production` | Secure cookie, no error traces, fail-closed. ConfigMap. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | Must be `0`. ConfigMap. |

For managed cloud DBs, endpoint/partition differences (AWS Commercial vs
GovCloud, Azure Commercial vs Government) affect only the **host** in
`DATABASE_URL` and the TLS/CA config — see [AWS.md](AWS.md) and [AZURE.md](AZURE.md).

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| containerPort | `8080` | Image listens here (non-privileged). |
| Liveness/Readiness path | `/api/health` | Returns `{"data":{"ok":true,...}}` 200. |
| Startup probe | `/api/health` | Allow time for migration + Apache start. |
| Ingress annotation | `nginx.ingress.kubernetes.io/force-ssl-redirect: "true"` | App also force-HTTPS via `X-Forwarded-Proto`. |
| HPA target | CPU 70% | App is stateless; scale freely. |
| PDB | `minAvailable: 1` | Maintain availability during drains. |

Manifest excerpt (Deployment probes + env):

```yaml
containers:
  - name: apex
    image: <registry>/apex:<tag>
    ports: [{ containerPort: 8080 }]
    envFrom:
      - configMapRef: { name: apex-config }
      - secretRef:    { name: apex-secrets }
    livenessProbe:  { httpGet: { path: /api/health, port: 8080 }, initialDelaySeconds: 10, periodSeconds: 15 }
    readinessProbe: { httpGet: { path: /api/health, port: 8080 }, periodSeconds: 10 }
    startupProbe:   { httpGet: { path: /api/health, port: 8080 }, failureThreshold: 30, periodSeconds: 2 }
    volumeMounts: [{ name: tmp, mountPath: /tmp }]
volumes: [{ name: tmp, emptyDir: {} }]
```

Optional migration Job:

```yaml
apiVersion: batch/v1
kind: Job
metadata: { name: apex-migrate }
spec:
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: migrate
          image: <registry>/apex:<tag>
          command: ["php","/var/www/html/scripts/migrate.php"]
          envFrom: [{ secretRef: { name: apex-secrets } }]
```

---

## 7. Verification

```bash
kubectl -n apex rollout status deploy/apex

# Health from inside the cluster
kubectl -n apex run curl --rm -it --image=curlimages/curl --restart=Never -- \
  curl -s http://apex.apex.svc.cluster.local/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"..."}}

# Login through the Ingress (secrets resolved + bcrypt verify)
TOKEN=$(curl -s -X POST https://apex.example/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] && echo "login OK — JWT_SECRET resolved"

# Write a DB row (ticket) and confirm persistence
curl -s -X POST https://apex.example/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"k8s smoke test","type":"task"}'

kubectl -n apex exec deploy/apex -- \
  php -r '$p=new PDO(getenv("DATABASE_URL")?:"");' 2>/dev/null || \
psql "$DATABASE_URL" -c "SELECT id,title FROM tickets ORDER BY created_at DESC LIMIT 1;"
```

Verify: probes green ✓ · `/api/health` 200 ✓ · login token (secret resolved) ✓ ·
new `tickets` row in Postgres ✓.

---

## 8. Day-2 operations

| Task | Procedure |
|------|-----------|
| Upgrade | `kubectl set image deploy/apex apex=<registry>/apex:<newtag>` — rolling update; probes gate traffic. |
| Migrations | Run the `apex-migrate` Job (or rely on idempotent boot migrate); for altering an existing DB apply new SQL via a Job/`psql`. |
| Scale | `kubectl scale deploy/apex --replicas=N` or let HPA manage. Stateless — no session affinity needed (JWT in cookie/header). |
| Backups | Managed DB automated backups, or `pg_dump` CronJob for in-cluster Postgres. |
| Secret rotation | Rotate in Secrets Manager/Key Vault; ESO/CSI resync; `kubectl rollout restart deploy/apex` to pick up a new `JWT_SECRET`. |
| Certs | cert-manager auto-renews; verify `kubectl get certificate -n apex`. |
| Logs | `kubectl logs -f deploy/apex` → ship to Loki/CloudWatch/Log Analytics. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod `CrashLoopBackOff`, log `JWT_SECRET is missing or too short` | Weak/absent secret with `APP_ENV=production` | Set a 32+ char `JWT_SECRET` in `apex-secrets`. |
| Pod fails write to filesystem | `readOnlyRootFilesystem` without `/tmp` mount | Add the `emptyDir` at `/tmp` (Apache needs it). |
| Readiness never passes | DB unreachable, migration erroring | Check `DATABASE_URL`, DB SG/NSG, `kubectl logs`. |
| `permission denied` binding port | Trying to use port 80 | Image listens on 8080; keep `containerPort: 8080`. |
| Login `Invalid credentials` | Real PINs required; defaults off in prod | Use seed PIN; defaults force-disabled at `APP_ENV=production`. |
| Ingress 421/redirect loop | Missing `X-Forwarded-Proto` | Ensure controller sets it; enable force-ssl-redirect. |
| Two pods double-seed | Race on first migrate | Prefer the `apex-migrate` Job before scaling app pods up. |
| Secret not mounted | CSI/ESO misconfig or SA missing workload identity | Verify SA annotations and SecretProviderClass/ExternalSecret status. |
