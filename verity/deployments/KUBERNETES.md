# VERITY — Kubernetes

Operator guidance for running the Verity Docker image on Kubernetes. **This
repository does not ship Kubernetes manifests today** — the YAML below is a
starting pattern for an operator to apply directly or commit under
`deploy/k8s/`, not a description of files that already exist in this repo.

> **Session-store caveat — read before scaling past 1 replica.**
> `app/Support/Session.php` configures cookie flags (HttpOnly, SameSite=Lax,
> Secure-when-HTTPS, cookie name `VERITY_SID`) but does **not** configure a
> custom `session.save_handler` — PHP falls back to its default, which stores
> session files on local disk inside each pod. With more than one replica
> and no sticky sessions, a request routed to a different pod than the one
> that created the session will not see it and the user is silently signed
> out. Until the app ships a shared session store (e.g. a `pgsql`/Redis
> session handler), run either **a single replica**, or **multiple replicas
> with session affinity enabled on the Service/Ingress** — do not run
> multiple replicas without one of those two mitigations.

## 1. Deployment architecture

Stateless (aside from the session caveat above) PHP pods running the
existing Docker image, behind a Service/Ingress with TLS termination, talking
to PostgreSQL 14+ (in-cluster StatefulSet or a managed instance) over PDO.
No queue, no worker Deployment, no Redis — there is nothing else to run.

## 2. Topology

```
            Kubernetes cluster
  ┌───────────────────────────────────────────────────┐
  │ [Ingress: TLS] ─> [Service] ─> [verity pods x N]   │
  │                                      │              │
  │                           ┌──────────┴─────────┐    │
  │                    [PostgreSQL STS/PVC   [Secret:   │
  │                     or external managed]  DATABASE_URL]
  └───────────────────────────┬─────────────────────────┘
                               │ GET /health (readiness/liveness)
```

## 3. Prerequisites

- A Kubernetes cluster (any conformant distribution) and `kubectl` access.
- An Ingress controller with a TLS certificate.
- PostgreSQL 14+ reachable from the cluster (in-cluster or managed — see
  `AZURE.md`/`AWS.md` for cloud-managed options).
- The Verity image built and pushed to a registry the cluster can pull from
  (`docker build -t <registry>/verity:<tag> . && docker push ...` using the
  `Dockerfile` already in this repo).

## 4. Identity & credentials

- Store `DATABASE_URL` as a Kubernetes `Secret` — never in a `ConfigMap` or
  baked into the image (the Dockerfile already strips any `.env` at build
  time).
- There is no external identity provider to register for this build — local
  email/password only; `/auth/sso` returns 503 by design (Phase 4, see
  `LOCAL_DEVELOPMENT.md` §4).
- Prefer a cloud-managed database with IAM/workload-identity-based
  authentication over a static password where your target cloud supports it
  (see `AZURE.md`/`AWS.md`); on a self-managed in-cluster Postgres, rotate the
  app role's password through the Secret and restart pods to pick it up.

## 5. Environment variables

| Source | Keys |
|--------|------|
| ConfigMap (non-secret) | `APP_ENV=production`, `PASSWORD_BREACH_CHECK_ENABLED=true` |
| Secret | `DATABASE_URL` |

`PASSWORD_BREACH_CHECK_ENABLED` (default `true`) checks new passwords
against the Have I Been Pwned range API (k-anonymity; only a 5-char hash
prefix ever leaves the pod) and fails open on any network error. It needs
an egress-allowed path to `api.pwnedpasswords.com` — set it to `false` in
the ConfigMap if a `NetworkPolicy` blocks outbound internet from this
namespace.

Omit the `ENTRA_*`/`GRAPH_BASE_URL`/`ENTRA_AUTHORITY_HOST`/`AZURE_PORTAL_URL`
variables entirely unless you are specifically staging for the future GCC
High connector work — they have no effect on sign-in in this build.

## 6. Example manifests

```yaml
apiVersion: v1
kind: ConfigMap
metadata: { name: verity-config }
data:
  APP_ENV: "production"
---
apiVersion: v1
kind: Secret
metadata: { name: verity-secrets }
type: Opaque
stringData:
  DATABASE_URL: "postgresql://verity_app:<password>@<pg-host>:5432/verity?sslmode=require"
---
apiVersion: apps/v1
kind: Deployment
metadata: { name: verity }
spec:
  replicas: 1   # see the session-store caveat above before raising this
  selector: { matchLabels: { app: verity } }
  template:
    metadata: { labels: { app: verity } }
    spec:
      containers:
        - name: verity
          image: <registry>/verity:<tag>
          ports: [{ containerPort: 8080 }]
          envFrom:
            - configMapRef: { name: verity-config }
            - secretRef: { name: verity-secrets }
          readinessProbe:
            httpGet: { path: /health, port: 8080 }
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet: { path: /health, port: 8080 }
            initialDelaySeconds: 10
            periodSeconds: 20
---
apiVersion: v1
kind: Service
metadata: { name: verity }
spec:
  selector: { app: verity }
  sessionAffinity: ClientIP   # required if replicas > 1 — see session-store caveat
  ports: [{ port: 80, targetPort: 8080 }]
```

## 7. Schema migration job

There is no migration framework — `database/schema.sql` is a single
idempotent file (`CREATE TABLE IF NOT EXISTS` throughout). Apply it with a
one-shot Job (or an initContainer on first deploy) before traffic reaches the
Deployment:

```yaml
apiVersion: batch/v1
kind: Job
metadata: { name: verity-schema-apply }
spec:
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: apply-schema
          image: postgres:16-alpine
          command: ["psql", "$(DATABASE_URL)", "-f", "/schema/schema.sql"]
          envFrom: [{ secretRef: { name: verity-secrets } }]
          volumeMounts: [{ name: schema, mountPath: /schema }]
      volumes:
        - name: schema
          configMap: { name: verity-schema }   # create from database/schema.sql
```
Safe to re-run on every deploy (every statement is `IF NOT EXISTS`).

## 8. Horizontal scaling notes

- The app tier itself is stateless compute; the blocker to scaling past one
  replica is purely the file-based PHP session store described at the top of
  this file — mitigate with `sessionAffinity: ClientIP` (shown above) or an
  Ingress-level sticky-session annotation until a shared session backend
  exists.
- PostgreSQL is the only stateful dependency; scale it per your Postgres
  platform's own guidance (read replicas, connection pooling) — the app does
  no connection pooling of its own (`Db::connection()` holds one PDO
  connection per PHP process/request).

## 9. Verification

```bash
kubectl get pods -l app=verity                       # all Running/Ready
kubectl exec deploy/verity -- curl -fsS localhost:8080/health
curl -fsS https://<ingress-host>/health               # via Ingress
```
- Sign in through the Ingress hostname; confirm dashboard KPIs render
  (proves the pod reached PostgreSQL through the Secret-sourced
  `DATABASE_URL`).
- Confirm `DATABASE_URL` does not appear in `kubectl get configmap
  verity-config -o yaml` (it must only be in the Secret).

## 10. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Pods CrashLoopBackOff | Missing/malformed `DATABASE_URL` Secret | `kubectl logs` — `Db::connection()` raises a generic "Database connection failed" without the DSN; check the Secret value directly |
| Users randomly logged out under load | >1 replica without sticky sessions | Add `sessionAffinity: ClientIP` or an Ingress sticky annotation (see §4/§8 caveat) |
| Readiness probe flapping | `/health`'s `db` field only checks `DATABASE_URL` is set, not connectivity — a pod can be "ready" with an unreachable DB | Add a separate connectivity check if you need stricter readiness, or rely on the dashboard-render check in §9 |
| Ingress 502 | Probe/port mismatch | Confirm the container listens on `8080` and the probe/Service target that port |
| Schema Job fails on first run | Database not yet created, or `DATABASE_URL`'s dbname missing | Create the target database first; the Job only applies tables, not the database itself |
