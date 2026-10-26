# PAL on Kubernetes

Reference for running PAL on a Kubernetes cluster (on-premises, AKS or EKS). This is **not** the selected production target (that is a single on-prem Linux host, see [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md)); use it if the organisation standardises on Kubernetes.

Scope: PAL **Phase 1 foundation**. All state is in PostgreSQL. No document storage, malware scanner or preview converter yet (Phase 2+), so PAL needs **no persistent volumes** of its own. PAL makes no LLM calls.

The manifests below are minimal examples, checked for YAML syntax only; they have **not** been applied to a cluster. Review them against your cluster's policies.

---

## 1. Deployment architecture

| Object | Image role | Count | Notes |
|---|---|---|---|
| `Job/pal-migrate-<version>` | `migrate` | once per release, before rollout | Runs Doctrine migrations as the owner role, re-applies `audit_event` revokes to `PAL_APP_DB_ROLE` |
| `Deployment/pal-web` | `web` | 2+ | nginx + PHP-FPM under supervisord, port 8080. Stateless (sessions are in PostgreSQL) |
| `Deployment/pal-worker` | `worker` | **1** | `messenger:consume async scheduler_default`. Runs the scheduler |
| `Service/pal-web` | — | — | ClusterIP, port 80 → 8080 |
| `Ingress/pal` | — | — | TLS termination; must preserve `Host` and set `X-Forwarded-Proto` |
| Secrets | — | — | DB URLs, `APP_SECRET`, Entra certificate. Prefer an external secret operator |
| PostgreSQL 16+ (17 rec.) | — | — | Outside this guide: managed service, or an operator such as CloudNativePG |

**Why one worker:** the scheduler (directory sync every 15 min, audit verification daily 02:15 UTC) is protected by a PostgreSQL advisory lock (`pal-schedule-default`, `DoctrineDbalPostgreSqlStore`). Extra worker replicas will not double-fire scheduled jobs, so they are *safe*, but every replica also consumes the scheduler transport and contends for the lock, which is wasteful. Run **one** worker replica. The `async` queue currently has no producers that need scaling. The worker exits cleanly every `PAL_WORKER_TIME_LIMIT` seconds (default 3600) to reclaim memory; Kubernetes restarts it (expected, not a crash). Each replica keeps its own scheduler state in its local filesystem cache, which is lost on restart; `processOnlyLastMissedRun` limits catch-up to one run.

## 2. Topology

```
                Users
                  | HTTPS
        +---------v----------+
        | Ingress controller |  TLS, Host preserved, X-Forwarded-For/-Proto
        +---------+----------+
                  | :80
        +---------v----------+         namespace: pal
        | Service pal-web    |
        +---------+----------+
                  | :8080
   +--------------v---------------+     +---------------------+
   | Deployment pal-web (2+ pods) |     | Deployment          |
   |  nginx + PHP-FPM, uid 10001  |     | pal-worker (1 pod)  |
   +--------------+---------------+     +----------+----------+
                  |                                |
                  |      +----------------------+  |
                  |      | Job pal-migrate-<v>  |  |
                  |      +----------+-----------+  |
                  v                 v              v
        +---------------------------------------------------+
        | PostgreSQL 17 (managed or operator) TCP 5432      |
        +---------------------------------------------------+
   web + worker egress HTTPS 443 --> Entra ID (login.microsoftonline.com)
                                 --> Microsoft Graph (graph.microsoft.com)
   Secrets: External Secrets Operator / Secrets Store CSI <-- Key Vault / Secrets Manager / Vault
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| Cluster | Kubernetes with Pod Security "restricted" compatible workloads (PAL runs non-root, no capabilities) |
| Ingress | An ingress controller with TLS (examples assume ingress-nginx; adapt annotations) |
| Image | `pal` image built from `pal/Dockerfile`, pushed to a registry the cluster can pull from (e.g. `registry.example.internal/pal:1.0.0`). Pin by digest in production |
| PostgreSQL | 16+ (17 recommended), reachable from the namespace; `deploy/sql/roles.sql` applied by a DBA (§4.3) |
| Secrets | External Secrets Operator or Secrets Store CSI driver (recommended), or plain Kubernetes Secrets with etcd encryption at rest |
| Egress | HTTPS 443 to the Entra authority host and Graph host (directly or via proxy) |
| Entra ID | App registration as in [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides), including the **mandatory** *Assignment required = Yes* with only the PAL users group assigned (every account that can sign in becomes a Reader, B2B guests included) |
| Nodes | NTP-synchronised (ID token leeway is 120 s) |

## 4. Identity and credentials

### 4.1 Workload identity

PAL itself calls **no cloud APIs**: it talks only to PostgreSQL, Entra ID and Graph. Workload identity (AKS Workload Identity, EKS IRSA / Pod Identity) is therefore used by the **secret operator**, not by PAL pods:

- External Secrets Operator's service account gets a federated identity with read access to exactly the PAL secrets (Key Vault: `Key Vault Secrets User` scoped to the vault or to individual secrets; AWS: `secretsmanager:GetSecretValue` on the `pal/*` ARNs). See [AZURE.md](AZURE.md) and [AWS.md](AWS.md) for policies.
- PAL pods use a dedicated service account with `automountServiceAccountToken: false`; they need no Kubernetes API access.

### 4.2 What still needs a credential

| Credential | Why managed identity cannot replace it (verified in code) | Delivered as |
|---|---|---|
| Entra app certificate + key | PAL supports only a client secret or a certificate file pair (`private_key_jwt`); there is no federated/workload-identity client assertion support | Secret volume, files at `OIDC_CLIENT_CERT_PATH` / `OIDC_CLIENT_KEY_PATH` |
| `pal_app` / `pal_owner` passwords | PAL connects with a static password in `DATABASE_URL`; no token-based DB auth (Entra / IAM DB auth) | `DATABASE_URL`, `DATABASE_URL_MIGRATIONS` env from Secret |
| `APP_SECRET` | Application secret | env from Secret |

Kubernetes Secrets are only base64-encoded. Enable encryption at rest for etcd, restrict `get/list` on Secrets in the `pal` namespace with RBAC, and source values from a vault via an operator.

### 4.3 Database roles

A DBA runs `deploy/sql/roles.sql` before the first migration and after every migration (see [SINGLE_LINUX_SERVER.md §4.4](SINGLE_LINUX_SERVER.md#44-database-roles-least-privilege)):

```sh
psql "postgresql://<dba>@pg.example.internal:5432/pal" -v ON_ERROR_STOP=1 \
     -v owner_role=pal_owner -v app_role=pal_app -v db_name=pal -f deploy/sql/roles.sql
```

On managed PostgreSQL (non-superuser admin) `GRANT pal_owner TO <dba>;` may be needed first (verify).

## 5. Environment variables

Same set as the Linux guide (full descriptions: [SINGLE_LINUX_SERVER.md §5](SINGLE_LINUX_SERVER.md#5-environment-variables)). Split between ConfigMap (non-secret) and Secret.

| Variable | Example | Purpose | Source |
|---|---|---|---|
| `APP_SECRET` | 64 hex chars | Kernel secret; required by web, worker, migrate | Secret |
| `DATABASE_URL` | `postgresql://pal_app:<pw>@pg.example.internal:5432/pal?serverVersion=17&charset=utf8` | Runtime connection (app role); required by all roles | Secret |
| `DATABASE_URL_MIGRATIONS` | `postgresql://pal_owner:<pw>@pg.example.internal:5432/pal?serverVersion=17&charset=utf8` | Owner connection; **migrate Job only** | Secret (separate, mounted only in the Job) |
| `PAL_APP_DB_ROLE` | `pal_app` | Role whose `audit_event` writes are revoked after migrating | Job env |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Queue in PostgreSQL | ConfigMap |
| `PAL_BASE_URL` | `https://pal.example.internal` | Public origin; redirect URI = + `/auth/callback`; required by web | ConfigMap |
| `TRUSTED_HOSTS` | `^pal\.example\.internal$` | Accepted Host regex; required by web | ConfigMap |
| `TRUSTED_PROXIES` | `10.244.0.0/16` | Exact IP(s)/CIDR of the ingress controller pods (or the load balancer that connects to the pods). Only these may set `X-Forwarded-*`. Image default `private_ranges` is not the production setting. The ingress must **overwrite** `X-Forwarded-For`, or client IPs (rate limits, audit `client_ip`) can be spoofed | ConfigMap |
| `DEFAULT_URI` | `https://pal.example.internal` | Router default URI for CLI/worker | ConfigMap |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | Entra authority (Gov: `https://login.microsoftonline.us`, verify) | ConfigMap |
| `OIDC_TENANT_ID` | `<tenant-guid>` | Entra tenant | ConfigMap |
| `OIDC_CLIENT_ID` | `<client-guid>` | App registration | ConfigMap |
| `OIDC_CLIENT_CERT_PATH` | `/run/secrets/pal-oidc/tls.crt` | Certificate file | ConfigMap |
| `OIDC_CLIENT_KEY_PATH` | `/run/secrets/pal-oidc/tls.key` | Unencrypted PEM key | ConfigMap |
| `OIDC_CLIENT_SECRET` | *(unset)* | Do not set alongside the certificate | — |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | Graph (Gov: `https://graph.microsoft.us/v1.0`, verify) | ConfigMap |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | `<user-object-guid>` | Temporary System Administrators | ConfigMap (remove after mapping) |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | Org timezone | ConfigMap |
| `PAL_SESSION_IDLE_SECONDS` | `3600` | Idle timeout | ConfigMap |
| `PAL_SESSION_ABSOLUTE_SECONDS` | `43200` | Absolute lifetime | ConfigMap |
| `PAL_AUDIT_ANCHOR_DIR` | `/srv/pal-anchor` | Audit anchor directory (worker; needs a writable volume) | ConfigMap |
| `LOG_LEVEL` | `info` | Log level | ConfigMap |
| `PAL_WORKER_TIME_LIMIT` / `PAL_WORKER_MEMORY_LIMIT` | `3600` / `256M` | Worker recycling | worker env |

Image defaults (leave as is): `APP_ENV=prod`, `APP_DEBUG=0`, `PAL_SESSION_COOKIE_NAME=__Host-pal_session`, `PAL_SESSION_COOKIE_SECURE=1`.

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Container port | `8080` | nginx in the `web` container |
| `runAsUser` / `runAsGroup` / `fsGroup` | `10001` | Image user `pal`; `fsGroup` makes secret files and emptyDirs readable/writable |
| `readOnlyRootFilesystem` | `true` | Writable paths via emptyDir: `/tmp`, `/srv/pal/backend/var/cache`, `/srv/pal/backend/var/log` (same as `deploy/compose.yaml` tmpfs) |
| Liveness probe (web) | `GET /health/live` :8080 | Process up; no DB check |
| Readiness probe (web) | `GET /health/ready` :8080 | DB reachable and no unapplied migrations; 503 otherwise |
| Worker probes | none | No HTTP listener. Kubernetes restarts the container when it exits |
| Ingress body size | `nginx.ingress.kubernetes.io/proxy-body-size: 2m` | PAL's own limit is 2 MB |
| `TRUSTED_PROXIES` | ingress controller pod CIDR, e.g. `10.244.0.0/16` | Set it to the narrowest range that contains the ingress controller pods (a dedicated node pool/subnet helps). `private_ranges` (Symfony: 10/8, 172.16/12, 192.168/16, loopback, link-local, IPv6 ULA) also trusts every other pod, and does not cover CGNAT pod CIDRs such as `100.64.0.0/10` |
| `X-Forwarded-For` handling | ingress overwrites it | ingress-nginx's default (`use-forwarded-headers: false`) does not trust client-supplied forwarded headers (verify for your controller and version). Do not enable `use-forwarded-headers`/`compute-full-forwarded-for` unless a trusted load balancer in front sets the header |
| Forwarded headers | `X-Forwarded-For`, `-Proto`, `-Port` trusted; **`X-Forwarded-Host` is not** | Ingress must pass the original `Host` (ingress-nginx does by default) |
| Egress proxy | `HTTPS_PROXY`, `NO_PROXY` (platform variables, not PAL settings) | Symfony HttpClient honours them (verify); include the DB host and cluster CIDRs in `NO_PROXY` |
| DB TLS | `PGSSLMODE=verify-full`, `PGSSLROOTCERT=/run/secrets/pal-db-ca/ca.crt` | libpq variables; apply TLS to both of PAL's DB connections (DBAL + session handler). Verify with `pg_stat_ssl` |

Note: mounting an emptyDir over `/srv/pal/backend/var/cache` hides the Symfony cache warmed at image build time, so each pod rebuilds its cache on first request (slower first requests). This matches the Compose configuration.

### 6.1 Example manifests

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: pal
  labels:
    pod-security.kubernetes.io/enforce: restricted
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: pal
  namespace: pal
automountServiceAccountToken: false
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: pal-config
  namespace: pal
data:
  PAL_BASE_URL: "https://pal.example.internal"
  DEFAULT_URI: "https://pal.example.internal"
  TRUSTED_HOSTS: '^pal\.example\.internal$'
  TRUSTED_PROXIES: "10.244.0.0/16"     # ingress controller pod CIDR: adjust
  MESSENGER_TRANSPORT_DSN: "doctrine://default?auto_setup=0"
  OIDC_AUTHORITY: "https://login.microsoftonline.com"
  OIDC_TENANT_ID: "<tenant-guid>"
  OIDC_CLIENT_ID: "<client-guid>"
  OIDC_CLIENT_CERT_PATH: "/run/secrets/pal-oidc/tls.crt"
  OIDC_CLIENT_KEY_PATH: "/run/secrets/pal-oidc/tls.key"
  GRAPH_BASE_URL: "https://graph.microsoft.com/v1.0"
  PAL_BOOTSTRAP_ADMIN_OIDS: "<user-object-guid>"
  PAL_DEFAULT_TIMEZONE: "America/Denver"
  PAL_SESSION_IDLE_SECONDS: "3600"
  PAL_SESSION_ABSOLUTE_SECONDS: "43200"
  LOG_LEVEL: "info"
---
# Secrets are normally created by External Secrets Operator (example below).
# Expected keys:
#   pal-runtime:    APP_SECRET, DATABASE_URL
#   pal-migrations: DATABASE_URL_MIGRATIONS
#   pal-oidc:       tls.crt, tls.key   (type kubernetes.io/tls)
apiVersion: external-secrets.io/v1
kind: ExternalSecret
metadata:
  name: pal-runtime
  namespace: pal
spec:
  refreshInterval: 1h
  secretStoreRef:
    kind: SecretStore
    name: pal-vault            # backed by Key Vault / Secrets Manager / Vault via workload identity
  target:
    name: pal-runtime
  data:
    - secretKey: APP_SECRET
      remoteRef:
        key: pal-app-secret
    - secretKey: DATABASE_URL
      remoteRef:
        key: pal-database-url
---
apiVersion: batch/v1
kind: Job
metadata:
  name: pal-migrate-1-0-0      # one Job per release; Job specs are immutable
  namespace: pal
spec:
  backoffLimit: 0
  ttlSecondsAfterFinished: 86400
  template:
    spec:
      serviceAccountName: pal
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001
        seccompProfile:
          type: RuntimeDefault
      containers:
        - name: migrate
          image: registry.example.internal/pal:1.0.0
          args: ["migrate"]
          env:
            - name: PAL_APP_DB_ROLE
              value: pal_app
          envFrom:
            - secretRef:
                name: pal-runtime
            - secretRef:
                name: pal-migrations
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities:
              drop: ["ALL"]
          volumeMounts:
            - { name: tmp, mountPath: /tmp }
            - { name: cache, mountPath: /srv/pal/backend/var/cache }
            - { name: log, mountPath: /srv/pal/backend/var/log }
      volumes:
        - { name: tmp, emptyDir: {} }
        - { name: cache, emptyDir: {} }
        - { name: log, emptyDir: {} }
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: pal-web
  namespace: pal
spec:
  replicas: 2
  selector:
    matchLabels: { app: pal, component: web }
  template:
    metadata:
      labels: { app: pal, component: web }
    spec:
      serviceAccountName: pal
      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001
        seccompProfile:
          type: RuntimeDefault
      containers:
        - name: web
          image: registry.example.internal/pal:1.0.0
          args: ["web"]
          ports:
            - { name: http, containerPort: 8080 }
          envFrom:
            - configMapRef: { name: pal-config }
            - secretRef: { name: pal-runtime }
          readinessProbe:
            httpGet: { path: /health/ready, port: http }
            periodSeconds: 10
            timeoutSeconds: 5
            failureThreshold: 3
          livenessProbe:
            httpGet: { path: /health/live, port: http }
            initialDelaySeconds: 20
            periodSeconds: 30
            timeoutSeconds: 5
            failureThreshold: 3
          resources:
            requests: { cpu: 250m, memory: 512Mi }
            limits: { memory: 2Gi }        # not yet measured; PHP-FPM max 16 children x 256M
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities:
              drop: ["ALL"]
          volumeMounts:
            - { name: tmp, mountPath: /tmp }
            - { name: cache, mountPath: /srv/pal/backend/var/cache }
            - { name: log, mountPath: /srv/pal/backend/var/log }
            - { name: oidc, mountPath: /run/secrets/pal-oidc, readOnly: true }
      volumes:
        - { name: tmp, emptyDir: {} }
        - { name: cache, emptyDir: {} }
        - { name: log, emptyDir: {} }
        - name: oidc
          secret:
            secretName: pal-oidc
            defaultMode: 0440
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: pal-worker
  namespace: pal
spec:
  replicas: 1                      # scheduler: one consumer is enough (advisory lock prevents double-firing)
  strategy:
    type: Recreate
  selector:
    matchLabels: { app: pal, component: worker }
  template:
    metadata:
      labels: { app: pal, component: worker }
    spec:
      serviceAccountName: pal
      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001
        seccompProfile:
          type: RuntimeDefault
      containers:
        - name: worker
          image: registry.example.internal/pal:1.0.0
          args: ["worker"]
          envFrom:
            - configMapRef: { name: pal-config }
            - secretRef: { name: pal-runtime }
          resources:
            requests: { cpu: 100m, memory: 256Mi }
            limits: { memory: 512Mi }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities:
              drop: ["ALL"]
          volumeMounts:
            - { name: tmp, mountPath: /tmp }
            - { name: cache, mountPath: /srv/pal/backend/var/cache }
            - { name: log, mountPath: /srv/pal/backend/var/log }
            - { name: oidc, mountPath: /run/secrets/pal-oidc, readOnly: true }
      volumes:
        - { name: tmp, emptyDir: {} }
        - { name: cache, emptyDir: {} }
        - { name: log, emptyDir: {} }
        - name: oidc
          secret:
            secretName: pal-oidc
            defaultMode: 0440
---
apiVersion: v1
kind: Service
metadata:
  name: pal-web
  namespace: pal
spec:
  selector: { app: pal, component: web }
  ports:
    - { name: http, port: 80, targetPort: http }
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: pal
  namespace: pal
  annotations:
    nginx.ingress.kubernetes.io/proxy-body-size: "2m"
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
spec:
  ingressClassName: nginx
  tls:
    - hosts: ["pal.example.internal"]
      secretName: pal-tls
  rules:
    - host: pal.example.internal
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service: { name: pal-web, port: { name: http } }
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: pal-web
  namespace: pal
spec:
  minAvailable: 1
  selector:
    matchLabels: { app: pal, component: web }
```

A NetworkPolicy restricting ingress to the ingress controller and egress to PostgreSQL (5432), DNS and HTTPS 443 is recommended; its selectors depend on your CNI and ingress namespace.

The certificate Secret can be created from the files generated in [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides) when not using an operator:

```sh
kubectl -n pal create secret tls pal-oidc --cert=oidc_cert.pem --key=oidc_key.pem
```

## 7. Verification

```sh
kubectl -n pal apply -f pal-base.yaml              # namespace, SA, ConfigMap, secrets
kubectl -n pal apply -f pal-migrate-1-0-0.yaml
kubectl -n pal wait --for=condition=complete job/pal-migrate-1-0-0 --timeout=300s
kubectl -n pal logs job/pal-migrate-1-0-0          # ends with "audit trail revokes applied to role pal_app"
kubectl -n pal apply -f pal-apps.yaml              # deployments, service, ingress
kubectl -n pal rollout status deploy/pal-web
```

| # | Check | Command | Expected |
|---|---|---|---|
| 1 | Liveness | `kubectl -n pal exec deploy/pal-web -- wget -qO- http://127.0.0.1:8080/health/live` | `{"status":"ok"}` |
| 2 | Readiness | `curl -sS https://pal.example.internal/health/ready` | 200, `"database":"ok","migrations":"ok"` |
| 3 | Pods non-root | `kubectl -n pal exec deploy/pal-web -- id -u` | `10001` |
| 4 | Secrets resolved | `kubectl -n pal exec deploy/pal-web -- sh -c 'test -r "$OIDC_CLIENT_CERT_PATH" && test -r "$OIDC_CLIENT_KEY_PATH" && echo readable'` | `readable` |
| 5 | Sign-in | Browse `https://pal.example.internal/` | Signed in, not on `/sign-in?auth_error=…`; an unassigned account is refused by Entra (AADSTS50105) |
| 6 | Directory sync | `kubectl -n pal exec deploy/pal-worker -- php /srv/pal/backend/bin/console pal:roles:map PAL_ADMINISTRATOR <group-object-id>` then `... pal:directory:sync` | Mapping created; sync exits 0 |
| 7 | Audit chain | `kubectl -n pal exec deploy/pal-worker -- php /srv/pal/backend/bin/console pal:audit:verify` | `Audit chain valid: …` |
| 8 | Append-only | As DBA: `SELECT has_table_privilege('pal_app','audit_event','UPDATE');` | `f` |
| 9 | Client IP, not spoofable | `curl -s -o /dev/null -H 'X-Forwarded-For: 203.0.113.9' 'https://pal.example.internal/auth/callback?state=x&code=y'`, then `kubectl -n pal logs deploy/pal-web` | Log shows your real IP, not `203.0.113.9` and not an ingress pod IP. (Junk callbacks without a valid pending sign-in are logged to the application log only, not the audit trail) |
| 10 | Upload accepted + scanned, object written | — | **Not applicable until Phase 2 (document storage)** |

`kubectl exec` bypasses the entrypoint and starts in `/srv/pal`, hence the full `bin/console` path.

## 8. Day-2 operations

**Upgrade:** build and push a new tag → create `Job/pal-migrate-<new>` with the new image and wait for completion → DBA re-runs `roles.sql` → update the image on `pal-web` and `pal-worker` (`kubectl set image` or GitOps) → `rollout status`. With Helm/Argo CD, model the migrate Job as a pre-upgrade hook / earlier sync wave. Readiness 503s on the **new** pods if migrations were skipped, so a forgotten migration stalls the rollout instead of serving errors. Old pods keep running during a rolling update against the migrated schema; keep migrations backward compatible with the previous release, or scale web to 0 for breaking migrations.

**Backups:** PAL pods are stateless. Back up PostgreSQL (WAL archiving / managed PITR to meet the provisional RPO 15 min / RTO 4 h, decision D10), the vault holding PAL secrets, and the audit anchor storage. Restore procedure: [SINGLE_LINUX_SERVER.md §8.2](SINGLE_LINUX_SERVER.md#82-backups).

**Audit anchoring:** mount a writable volume (backed by storage another team controls) at `PAL_AUDIT_ANCHOR_DIR` in `pal-worker` only.

**Logs:** JSON on stdout/stderr; collect with the cluster's log agent (on AKS, Container insights / Azure Monitor Agent feeding Microsoft Sentinel; elsewhere Fluent Bit to the SIEM's supported ingestion path). Filter `"channel":"pal_audit"` for the security stream. Do not use the legacy Log Analytics HTTP Data Collector API.

**Rotation:** update the value in the vault; External Secrets refreshes the Kubernetes Secret (`refreshInterval`). Environment variables are read at container start, so `kubectl -n pal rollout restart deploy/pal-web deploy/pal-worker` after DB password or `APP_SECRET` changes. Mounted secret files (certificate) update in place, but restart anyway to be certain the new key is used. For the Entra certificate, upload the new public cert before switching and remove the old one after verifying sign-in and sync.

**Failed jobs:** `kubectl -n pal exec deploy/pal-worker -- php /srv/pal/backend/bin/console messenger:failed:show`.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pod `CrashLoopBackOff`, log `pal: required environment variable X is not set`, exit **64** | Missing required var (web: `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; worker/migrate: `APP_SECRET`, `DATABASE_URL`) | Fix ConfigMap/Secret; check the ExternalSecret synced (`kubectl -n pal get externalsecret`) |
| Web pods never Ready; `/health/ready` **503** `"migrations":"fail"` | Migrate Job not run or failed | Run/inspect the Job logs |
| 503 `"database":"fail"` | DB unreachable, NetworkPolicy, wrong password, TLS settings | Check egress to 5432, `DATABASE_URL`, `PGSSLMODE` |
| All requests **400** | `Host` not matching `TRUSTED_HOSTS`, or ingress rewrites Host | Fix regex; do not set upstream-vhost annotations |
| Users throttled (`/sign-in?auth_error=too_many_attempts`, **429**) together; audit `client_ip` is a pod IP | Ingress pod IPs not in `TRUSTED_PROXIES` (`/auth/login` and `/auth/callback` allow 20/min per client IP) | Set `TRUSTED_PROXIES` to the ingress pod CIDR |
| `client_ip` values users can choose (forged `X-Forwarded-For`) | Ingress trusts/appends client-supplied forwarded headers, or `TRUSTED_PROXIES` too broad | Disable `use-forwarded-headers` (unless behind a trusted LB); narrow `TRUSTED_PROXIES` |
| HTTP **403** `self_grant_forbidden` on role mapping | Admin belongs to the group being mapped to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` | Another administrator creates the mapping |
| Branding logo URL rejected | Only `https://` or raster `data:` URLs are accepted | Use an `https://` URL or upload an image |
| `/sign-in?auth_error=sign_in_unavailable` | OIDC not configured (GUIDs, exactly one credential), cert unreadable (check `fsGroup`, `defaultMode`), egress to Entra blocked | Web log `Sign-in is unavailable` → `reason` |
| `/sign-in?auth_error=sign_in_failed` | Redirect URI mismatch, cert not uploaded to Entra, clock skew | Check app registration and NTP |
| `/sign-in?auth_error=sign_in_expired` | Cookie lost: TLS not terminated as HTTPS, or `X-Forwarded-Proto` missing | Check ingress TLS and headers |
| Worker restarts about hourly | Expected: `--time-limit=3600` | None (tune `PAL_WORKER_TIME_LIMIT`) |
| `Read-only file system` errors | A path outside the three emptyDirs is written | Report it; add an emptyDir for that path as a workaround |
| Migrate Job `field is immutable` on re-apply | Job names are immutable | Use a new Job name per release, or delete the finished Job |

---

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md), [AZURE.md](AZURE.md), [AWS.md](AWS.md), [AIRGAPPED.md](AIRGAPPED.md).
