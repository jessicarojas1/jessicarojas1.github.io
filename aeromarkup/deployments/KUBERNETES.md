# AeroMarkup — Kubernetes Deployment Guide

Operator guide for running AeroMarkup on Kubernetes (vanilla, EKS, AKS, or GKE).

Related guides: [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) · [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md) · [AWS.md](./AWS.md) · [AZURE.md](./AZURE.md) · [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

AeroMarkup is a single **stateless** Flask web process served by **gunicorn**
(`gunicorn server:app --bind 0.0.0.0:$PORT --workers 2 --timeout 120`, Python
3.12). The container image is `python:3.12-slim`, runs as **non-root uid 10001**,
`EXPOSE 8080`, and ships a container `HEALTHCHECK` that curls `/api/health`.

Key architectural properties that shape the Kubernetes design:

- **Stateless pods, no PVC.** All durable state lives in **PostgreSQL 13+** plus
  each client browser's **IndexedDB**. There is **no object storage / S3** —
  uploaded reference images and STL/OBJ 3D models are persisted as **data URLs in
  Postgres columns**. The Deployment therefore mounts no volumes and needs no
  `PersistentVolumeClaim`.
- **Horizontally scalable.** Run multiple replicas freely, with two caveats:
  1. `AEROMARKUP_SECRET` **must be identical across all pods** so the signed
     `am_session` cookie validates on whichever pod serves the request.
  2. The login throttle (`LOGIN_MAX_ATTEMPTS`) is **per-pod in-memory**, so with
     >1 replica enforce brute-force rate limiting at the **Ingress / WAF** layer
     as the authoritative control.
- **Schema-scoped, shared-DB safe.** The app pins `search_path=aeromarkup,public`
  and owns a dedicated `aeromarkup` schema, so it can co-tenant an existing
  Postgres cluster. `db/schema.sql` is idempotent; with `AUTO_MIGRATE=1` (default)
  it is applied at boot, or you can run it once from a Kubernetes **Job**.
- **Proxy-aware.** Behind an Ingress that appends `X-Forwarded-For`, set
  `TRUSTED_PROXY_HOPS` to the number of proxy hops (e.g. `1` for a single nginx
  ingress) so client IPs and cookie `Secure` handling resolve correctly.

Postgres is **not** deployed by this guide's app manifests — use a managed
service (RDS, Azure Database for PostgreSQL, Cloud SQL) or a dedicated Postgres
Operator / StatefulSet. Only the DSN is injected into the app via a Secret.

---

## 2. Topology

```mermaid
flowchart TD
    U[Browser + IndexedDB] -->|HTTPS| ING[Ingress nginx TLS<br/>WAF / rate-limit<br/>adds X-Forwarded-For]
    ING -->|HTTP :8080| SVC[Service ClusterIP :8080]
    SVC --> P1[Pod aeromarkup-0<br/>gunicorn 2 workers<br/>uid 10001]
    SVC --> P2[Pod aeromarkup-1<br/>gunicorn 2 workers<br/>uid 10001]
    P1 -->|DATABASE_URL| PG[(PostgreSQL 13+<br/>schema: aeromarkup)]
    P2 -->|DATABASE_URL| PG
    ESO[ExternalSecrets / CSI<br/>Secrets Store] -.injects.-> SEC[(Secret:<br/>DATABASE_URL<br/>AEROMARKUP_SECRET)]
    SEC -.envFrom.-> P1
    SEC -.envFrom.-> P2
    HPA[HPA CPU] -. scales .-> P1
    PDB[PodDisruptionBudget] -. guards .-> P1
```

```
[ Browser+IndexedDB ] --HTTPS--> [ Ingress nginx/TLS/WAF ] --:8080--> [ Service ClusterIP ]
                                                                          |         |
                                                                     [ Pod 0 ] [ Pod 1 ]   (uid 10001, stateless)
                                                                          \        /
                                                                       DATABASE_URL
                                                                           |
                                                                  [ PostgreSQL 13+  schema=aeromarkup ]
   Secret (DATABASE_URL, AEROMARKUP_SECRET) <== ExternalSecrets/CSI <== external secret store
```

---

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Kubernetes | 1.26+ | EKS / AKS / GKE / vanilla |
| `kubectl` | matches cluster ±1 minor | |
| Container image | `aeromarkup:<tag>` | `python:3.12-slim` base, uid 10001, EXPOSE 8080 |
| Ingress controller | ingress-nginx 1.9+ (or equivalent) | must forward `X-Forwarded-For` |
| PostgreSQL | 13+ | managed or StatefulSet; reachable from pods |
| TLS | cert-manager or managed cert | terminate at Ingress/LB |
| Secrets injection (recommended) | External Secrets Operator 0.9+ **or** Secrets Store CSI Driver | workload-identity backed |
| `psql` client | 13+ | for verification / manual migration |

Image registry must be reachable by the cluster (private registry for regulated
/ air-gapped clusters — see [AIRGAPPED.md](./AIRGAPPED.md)).

---

## 4. Identity & credentials

**Prefer workload identity over static keys.** The app needs exactly two secret
values — `DATABASE_URL` and `AEROMARKUP_SECRET` — and (optionally) a DB
connection that can itself be issued via IAM. Grant the least privilege that
resolves those secrets; nothing else.

**Least-privilege posture**
- The pod ServiceAccount may resolve **only** the AeroMarkup secret path (e.g.
  `secretsmanager:GetSecretValue` on `arn:*:secret:aeromarkup/*`, or Key Vault
  `get` on a single secret). No write, no wildcard on the whole vault.
- The Postgres role used by `DATABASE_URL` needs DML on the `aeromarkup` schema
  and DDL only if `AUTO_MIGRATE=1` applies `db/schema.sql`. If you run migrations
  from a separate Job with an elevated role, the runtime role can be DML-only.

**Recommended: External Secrets Operator (workload-identity backed)**

```yaml
apiVersion: external-secrets.io/v1beta1
kind: SecretStore
metadata:
  name: aeromarkup-store
  namespace: aeromarkup
spec:
  provider:
    # AWS example — the SA below is IRSA-annotated; no static keys.
    aws:
      service: SecretsManager
      region: us-gov-west-1        # or aws / commercial region
      auth:
        jwt:
          serviceAccountRef:
            name: aeromarkup
---
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata:
  name: aeromarkup-secrets
  namespace: aeromarkup
spec:
  refreshInterval: 1h
  secretStoreRef: { name: aeromarkup-store, kind: SecretStore }
  target:
    name: aeromarkup-secrets       # the k8s Secret consumed via envFrom
    creationPolicy: Owner
  data:
    - secretKey: DATABASE_URL
      remoteRef: { key: aeromarkup/prod, property: database_url }
    - secretKey: AEROMARKUP_SECRET
      remoteRef: { key: aeromarkup/prod, property: app_secret }
```

**ServiceAccount bound to workload identity**

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: aeromarkup
  namespace: aeromarkup
  annotations:
    # EKS / IRSA:
    eks.amazonaws.com/role-arn: arn:aws-us-gov:iam::<acct>:role/aeromarkup-secrets
    # AKS Azure Workload Identity (alternative):
    # azure.workload.identity/client-id: <client-id>
    # GKE Workload Identity (alternative), also set spec.iam.gke.io binding:
    # iam.gke.io/gcp-service-account: aeromarkup@<proj>.iam.gserviceaccount.com
```

**Alternative: Secrets Store CSI Driver** — mount `DATABASE_URL` /
`AEROMARKUP_SECRET` from the external store and use the driver's
`secretObjects` to sync into a Kubernetes Secret consumed via `envFrom`.

**Fallback only — static Secret.** If no external store is available, a plain
Kubernetes Secret is acceptable (encrypt etcd at rest, restrict RBAC `get`/`list`
on Secrets to the app namespace). See the plain Secret in §KUBERNETES manifests.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgres://amuser:***@pg:5432/aeromarkup` | Postgres DSN. Empty ⇒ offline-only mode (no server persistence). |
| `PORT` | `8080` | Listen port (gunicorn `--bind 0.0.0.0:$PORT`). |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` at boot (idempotent). Set `0` to migrate out-of-band via Job. |
| `ENVIRONMENT` | `production` | Anything not dev/local/test ⇒ prod hardening (secret required, secure cookies). |
| `AEROMARKUP_SECRET` | `<64 hex chars>` | Signs `am_session`/CSRF. **>=32 chars, REQUIRED in prod when `DATABASE_URL` set. Identical across all pods.** |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime (default 12h). |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed-login lockout threshold. **Per-pod in-memory** — enforce at ingress/WAF for multi-replica. |
| `LOGIN_WINDOW_SECONDS` | `900` | Sliding window for lockout counting. |
| `LOGIN_MAX_TRACKED` | `1000` | Max distinct identities tracked in the throttle table. |
| `TRUSTED_PROXY_HOPS` | `1` | Number of trusted proxy hops in front (ingress). Enables `ProxyFix` for `X-Forwarded-For`/proto. `0` = no proxy. |

---

## 6. Configuration references

| Config point | Value / location | Purpose |
|--------------|------------------|---------|
| Health / readiness | `GET /api/health` | JSON `{"status":"ok","database":"connected\|configured\|offline","mode":...}`. Used for liveness **and** readiness probes and container `HEALTHCHECK`. |
| Listener | `0.0.0.0:8080` | gunicorn bind; matches Service `targetPort`. |
| Non-root | `runAsUser: 10001` | Matches image `appuser`; keep `readOnlyRootFilesystem` viable (app writes no files). |
| DB schema | `aeromarkup` (`search_path=aeromarkup,public`) | Dedicated schema; shared-DB safe. |
| Schema file | `db/schema.sql` | Idempotent DDL applied by `AUTO_MIGRATE` or manual `psql -f`. |
| Session cookie | `am_session` (HttpOnly, signed) | Validated by `AEROMARKUP_SECRET` on any pod. |
| CSRF | cookie `am_csrf` + header `X-CSRF-Token` | Double-submit; required on state-changing requests. |
| Bootstrap | `POST /api/auth/bootstrap` | First-run initial admin creation. |
| Login | `POST /api/auth/login` | Roles: viewer / engineer / inspector / approver / admin. |
| Proxy trust | `TRUSTED_PROXY_HOPS` ↔ Ingress `X-Forwarded-For` | Must match hop count so client IP/secure-cookie logic is correct. |

---

## 7. Verification

```bash
# 0. Apply manifests
kubectl apply -f namespace.yaml -f serviceaccount.yaml -f secret.yaml \
              -f deployment.yaml -f service.yaml -f ingress.yaml \
              -f hpa.yaml -f pdb.yaml

# 1. Pods Ready and probes healthy
kubectl -n aeromarkup rollout status deploy/aeromarkup
kubectl -n aeromarkup get pods -o wide
kubectl -n aeromarkup describe pod -l app=aeromarkup | grep -A2 -E 'Liveness|Readiness'

# 2. Health via port-forward
kubectl -n aeromarkup port-forward svc/aeromarkup 8080:8080 &
curl -s http://127.0.0.1:8080/api/health
# => {"status":"ok","database":"connected","mode":"online"}

# 3. First-run bootstrap admin, then login (capture cookies + CSRF)
curl -s -c cj.txt -X POST http://127.0.0.1:8080/api/auth/bootstrap \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"ChangeMe!23456","display_name":"Admin"}'

curl -s -c cj.txt -b cj.txt -X POST http://127.0.0.1:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"ChangeMe!23456"}'

# extract CSRF token from the am_csrf cookie for the next write
CSRF=$(awk '/am_csrf/{print $7}' cj.txt)

# 4. Create a project (state-changing => needs cookie + X-CSRF-Token)
curl -s -b cj.txt -X POST http://127.0.0.1:8080/api/projects \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Verify-01","category":"structures"}'

# 5. Confirm the row landed in Postgres (via a pod's psql)
kubectl -n aeromarkup exec deploy/aeromarkup -- \
  sh -c 'psql "$DATABASE_URL" -c "SELECT count(*) FROM aeromarkup.projects;"'
# => count >= 1
```

Success criteria: `rollout status` completes, all pods `Ready`,
`/api/health` reports `database:"connected"`, bootstrap+login succeed, the
`aeromarkup.projects` count is ≥ 1, and no liveness/readiness restarts appear in
`kubectl describe`.

---

## 8. Day-2 operations

**Rolling upgrades** — new image tag + `kubectl set image` (or GitOps). The
`RollingUpdate` strategy with `maxUnavailable: 0` keeps capacity during rollout;
readiness gating on `/api/health` prevents traffic to unready pods.
```bash
kubectl -n aeromarkup set image deploy/aeromarkup aeromarkup=registry/aeromarkup:<newtag>
kubectl -n aeromarkup rollout status deploy/aeromarkup
kubectl -n aeromarkup rollout undo deploy/aeromarkup   # rollback if needed
```

**Migrations** — `db/schema.sql` is idempotent. Options:
- *In-boot (default):* leave `AUTO_MIGRATE=1`; each pod applies the schema at
  startup. Safe because it is idempotent.
- *Out-of-band Job:* set `AUTO_MIGRATE=0` on the Deployment and run the
  migration Job (§manifests) before/with the rollout so DDL runs once under an
  elevated role while runtime pods stay DML-only.

**HPA scaling** — CPU-target HPA scales replicas 2→N (see manifest). Because pods
are stateless this is safe; keep `AEROMARKUP_SECRET` identical (it comes from the
same Secret) and rely on ingress/WAF for rate limiting across the larger fleet.

**PodDisruptionBudget** — `minAvailable: 1` keeps at least one pod during node
drains / voluntary disruptions.

**Backups** — the pods hold **no state**; back up **PostgreSQL only**
(managed-service snapshots or `pg_dump` of the `aeromarkup` schema). Clients also
retain an offline copy in IndexedDB, but Postgres is the system of record.
```bash
kubectl -n aeromarkup exec deploy/aeromarkup -- \
  sh -c 'pg_dump "$DATABASE_URL" -n aeromarkup' > aeromarkup-$(date +%F).sql
```

**Secret rotation** — rotate `AEROMARKUP_SECRET` / `DATABASE_URL` in the external
store; External Secrets re-syncs the Kubernetes Secret. Roll pods to pick up new
values: `kubectl -n aeromarkup rollout restart deploy/aeromarkup`. Rotating
`AEROMARKUP_SECRET` invalidates all existing `am_session` cookies (users
re-login) — expected.

**Logs** — gunicorn/app logs go to stdout: `kubectl -n aeromarkup logs -f
deploy/aeromarkup`. Ship to your cluster log stack; startup logs echo
`ENVIRONMENT`, prod flag, DB-enabled, and `TRUSTED_PROXY_HOPS`.

---

## 9. Troubleshooting

| Symptom | Likely cause | Resolution |
|---------|--------------|------------|
| Pod `CrashLoopBackOff` at start | `AEROMARKUP_SECRET` missing/<32 chars while `DATABASE_URL` set in prod | Provide a ≥32-char secret via the Secret/ExternalSecret; `kubectl logs` shows "AEROMARKUP_SECRET is missing or too weak". |
| `503` on API writes, `/api/health` shows `database:"configured"` (or `offline`) | DB unreachable or `DATABASE_URL` empty | Verify DSN, network policy, DB reachability; `configured` = set but connect failed, `offline` = unset. |
| Readiness/liveness probe failing | Probe path/port wrong, or DB check slow at boot | Ensure probes hit `/api/health` on `8080`; raise `initialDelaySeconds`; check DB latency. |
| Write returns `csrf_failed` | Missing/stale `X-CSRF-Token` or dropped `am_csrf` cookie | Send header matching the `am_csrf` cookie; ensure ingress isn't stripping cookies; use HTTPS so Secure cookie is sent. |
| Users logged out / session invalid when hitting different pods | `AEROMARKUP_SECRET` differs across pods | Ensure all pods share the **same** Secret value; roll deployment after fixing. |
| Client IP shows as pod/ingress IP; lockout ineffective | `TRUSTED_PROXY_HOPS` mismatch | Set it to the real hop count (e.g. `1`) so `ProxyFix` reads `X-Forwarded-For`; enforce rate limits at ingress for multi-replica. |
| Brute-force not blocked cluster-wide | Login throttle is per-pod in-memory | Add ingress/WAF rate limiting on `/api/auth/login` as the authoritative control. |

---

## Kubernetes manifests

> Stateless — **no PVC**. Postgres is external/managed or a separate StatefulSet
> (not shown here; only its DSN is injected).

### Namespace

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: aeromarkup
```

### Secret — ExternalSecrets/CSI (preferred) vs plain (fallback)

Preferred: use the `ExternalSecret` from §4 to produce the Secret
`aeromarkup-secrets`. Fallback plain Secret (only if no external store):

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: aeromarkup-secrets
  namespace: aeromarkup
type: Opaque
stringData:
  DATABASE_URL: "postgres://amuser:CHANGE_ME@postgres:5432/aeromarkup"
  AEROMARKUP_SECRET: "REPLACE_WITH_>=32_CHAR_RANDOM_SECRET"
```

### Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: aeromarkup
  namespace: aeromarkup
  labels: { app: aeromarkup }
spec:
  replicas: 2
  strategy:
    type: RollingUpdate
    rollingUpdate: { maxUnavailable: 0, maxSurge: 1 }
  selector:
    matchLabels: { app: aeromarkup }
  template:
    metadata:
      labels: { app: aeromarkup }
    spec:
      serviceAccountName: aeromarkup
      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        fsGroup: 10001
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: aeromarkup
          image: registry.example.com/aeromarkup:latest   # pin a digest in prod
          ports:
            - containerPort: 8080
          envFrom:
            - secretRef: { name: aeromarkup-secrets }      # DATABASE_URL, AEROMARKUP_SECRET
          env:
            - { name: PORT,               value: "8080" }
            - { name: ENVIRONMENT,        value: "production" }
            - { name: AUTO_MIGRATE,       value: "1" }
            - { name: SESSION_TTL_SECONDS, value: "43200" }
            - { name: TRUSTED_PROXY_HOPS, value: "1" }      # single ingress hop
          livenessProbe:
            httpGet: { path: /api/health, port: 8080 }
            initialDelaySeconds: 20
            periodSeconds: 30
            timeoutSeconds: 5
            failureThreshold: 3
          readinessProbe:
            httpGet: { path: /api/health, port: 8080 }
            initialDelaySeconds: 10
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          resources:
            requests: { cpu: "100m", memory: "192Mi" }
            limits:   { cpu: "1",    memory: "512Mi" }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
```

### Service (ClusterIP)

```yaml
apiVersion: v1
kind: Service
metadata:
  name: aeromarkup
  namespace: aeromarkup
spec:
  type: ClusterIP
  selector: { app: aeromarkup }
  ports:
    - name: http
      port: 8080
      targetPort: 8080
```

### Ingress (TLS, nginx, forwards X-Forwarded-For)

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: aeromarkup
  namespace: aeromarkup
  annotations:
    kubernetes.io/ingress.class: nginx
    cert-manager.io/cluster-issuer: letsencrypt-prod
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
    # nginx forwards X-Forwarded-For by default; pair with TRUSTED_PROXY_HOPS=1.
    # Authoritative multi-replica brute-force control lives here:
    nginx.ingress.kubernetes.io/limit-rps: "10"
spec:
  tls:
    - hosts: [aeromarkup.example.com]
      secretName: aeromarkup-tls
  rules:
    - host: aeromarkup.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: aeromarkup
                port: { number: 8080 }
```

### HorizontalPodAutoscaler (CPU)

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: aeromarkup
  namespace: aeromarkup
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: aeromarkup
  minReplicas: 2
  maxReplicas: 6
  metrics:
    - type: Resource
      resource:
        name: cpu
        target: { type: Utilization, averageUtilization: 70 }
```

### PodDisruptionBudget

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: aeromarkup
  namespace: aeromarkup
spec:
  minAvailable: 1
  selector:
    matchLabels: { app: aeromarkup }
```

### Migration Job (option — when `AUTO_MIGRATE=0`)

Runs `db/schema.sql` once with an elevated DB role; runtime pods can then stay
DML-only. `db/schema.sql` is idempotent, so re-runs are safe.

```yaml
apiVersion: batch/v1
kind: Job
metadata:
  name: aeromarkup-migrate
  namespace: aeromarkup
spec:
  backoffLimit: 2
  template:
    spec:
      restartPolicy: Never
      serviceAccountName: aeromarkup
      securityContext: { runAsNonRoot: true, runAsUser: 10001 }
      containers:
        - name: migrate
          image: registry.example.com/aeromarkup:latest
          command: ["sh", "-c", "psql \"$DATABASE_URL\" -v ON_ERROR_STOP=1 -f db/schema.sql"]
          envFrom:
            - secretRef: { name: aeromarkup-secrets }
```
