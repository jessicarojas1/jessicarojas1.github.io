# CITADEL — Kubernetes Deployment

**Audience:** operators deploying the CITADEL deep-scan backend to a conformant Kubernetes
cluster (EKS, AKS, GKE, OpenShift, on-prem). This guide aligns with the Helm chart and raw
manifests under [`../deploy/kubernetes/`](../deploy/kubernetes/) and its
[runbook](../deploy/kubernetes/README.md).

CITADEL is a **Node 20 / Express** server that serves the SPA and shells out to real scanners
(Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV, …). It listens on **:8080**, health-checks
`GET /api/health`, runs **non-root UID/GID 10001** with a **read-only root filesystem**, and
needs **≥ 2 GB RAM per pod** — ClamAV alone loads a ~1.4 GB signature DB.

Related: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Env reference: [`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

- **Workload:** one `Deployment` (default **2 replicas**) of the `citadel-server` image, fronted
  by a `ClusterIP` `Service` (port `80` → targetPort `8080`) and an `Ingress` (TLS at the edge).
- **State:**
  - **No DB** → file store at `CITADEL_DATA_DIR` on a PVC. **Single writer → 1 replica only.**
  - **External Postgres** (`DATABASE_URL`) → durable, **HA (replicas > 1)** and **multi-tenant**
    capable. This is the recommended production mode.
- **Scaling:** optional `HorizontalPodAutoscaler` (CPU-target), `PodDisruptionBudget`
  (`minAvailable: 1`), rolling updates with `maxUnavailable: 0`.
- **Security:** hardened pod (non-root, RO root FS, all caps dropped, `seccompProfile:
  RuntimeDefault`), default-deny `NetworkPolicy`, SA token **not** auto-mounted.
- **Scratch:** untrusted uploads extract into a **memory-backed `emptyDir`** at
  `/tmp/citadel`; ClamAV DB lives in a disk-backed `emptyDir` at `/var/lib/clamav`.

> The chart **fails fast** if you request `replicaCount > 1`, `autoscaling.enabled`, or
> `multitenancy.enabled` **without** `externalDatabase.enabled=true`.

## 2. Topology

```
                          Internet
                             │  HTTPS
                             ▼
                   ┌───────────────────┐   TLS (cert-manager: citadel-tls)
                   │ Ingress (nginx)   │   force-ssl-redirect
                   └─────────┬─────────┘
                             │  :80 → :8080
                   ┌─────────▼─────────┐
                   │ Service ClusterIP │
                   └─────────┬─────────┘
             ┌───────────────┼───────────────┐
             ▼               ▼                ▼
      ┌────────────┐  ┌────────────┐   Deployment (2+ replicas, HPA 2→6)
      │ citadel pod│  │ citadel pod│   non-root 10001 · RO rootfs · caps drop ALL
      │ :8080      │  │ :8080      │   emptyDir /tmp/citadel (Memory, 512Mi)
      │ /api/health│  │            │   emptyDir /var/lib/clamav (disk, 1Gi)
      └─────┬──────┘  └─────┬──────┘
            │ NetworkPolicy egress: DNS(53), Postgres(5432), HTTPS(443)
            ▼
      External Postgres (DATABASE_URL, PGSSL verify-full)   ◄── Secret (JWT, admin, DB URL…)
                                                                  ExternalSecrets / SealedSecrets / CSI
      Prometheus ─ ServiceMonitor ─► /metrics (CITADEL_METRICS_TOKEN, from monitoring ns)
```

## 3. Prerequisites

| Requirement | Version / note |
|---|---|
| Kubernetes | **1.23+** (PodSecurity "restricted", `autoscaling/v2`, `policy/v1`) |
| Helm | **3.8+** (chart path) |
| Ingress controller | default annotations assume `ingress-nginx` |
| TLS cert source | e.g. **cert-manager** producing the `citadel-tls` Secret |
| metrics-server | only if you enable the HPA |
| Prometheus Operator CRDs | only if you enable the ServiceMonitor |
| External Postgres (TLS) | **required** for HA (replicas > 1) and multi-tenancy |
| Secret management | `kubectl`, **External Secrets Operator**, **Sealed Secrets**, or a CSI secret store |
| Node RAM headroom | schedule for the **2Gi pod limit**; ClamAV needs ~1.4 GB resident |

## 4. Identity & credentials

- **Pod ServiceAccount** (`serviceaccount.yaml`): created by the chart, **`automountServiceAccountToken:
  false`** (no in-cluster API token) — least privilege (NIST **AC-6**, **AC-3**).
- **Prefer workload identity for cloud secret managers.** The SA `annotations` map is empty by
  default; add your provider binding to pull secrets/DB creds without static keys:
  - **EKS / IRSA:** `eks.amazonaws.com/role-arn: arn:aws:iam::<acct>:role/citadel-secrets`
  - **AKS / Workload Identity:** `azure.workload.identity/client-id: <uami-client-id>` (+ pod label
    `azure.workload.identity/use: "true"`)
  - **GKE / Workload Identity:** `iam.gke.io/gcp-service-account: citadel@<proj>.iam.gserviceaccount.com`
  ```bash
  helm ... --set-string serviceAccount.annotations."eks\.amazonaws\.com/role-arn"=arn:aws:iam::123:role/citadel-secrets
  ```
- **Application secrets** come from a Kubernetes `Secret` (`{fullname}-secrets`). Manage it with
  **External Secrets Operator** or **Sealed Secrets** so values live in a vault, not in git
  (NIST **IA-5**, **SC-12/SC-28**). Create it out-of-band and reference with
  `secrets.existingSecret` (recommended).

```bash
kubectl create namespace citadel
kubectl -n citadel create secret generic citadel-secrets \
  --from-literal=CITADEL_JWT_SECRET="$(openssl rand -hex 32)" \
  --from-literal=CITADEL_ADMIN_EMAIL="admin@example.com" \
  --from-literal=CITADEL_ADMIN_PASSWORD="$(openssl rand -base64 24)" \
  --from-literal=DATABASE_URL="postgres://citadel:PASS@db.internal:5432/citadel?sslmode=verify-full" \
  --from-literal=CITADEL_SUPERADMIN_TOKEN="$(openssl rand -hex 32)" \
  --from-literal=CITADEL_METRICS_TOKEN="$(openssl rand -hex 32)"
```

## 5. Environment variables

Non-secret env (set by the chart from `values.yaml`):

| Variable | Example | Purpose |
|---|---|---|
| `NODE_ENV` | `production` | Prod hardening |
| `PORT` | `8080` | Derived from `service.targetPort` |
| `CITADEL_TMP` | `/tmp/citadel` | Memory-backed scratch mount for untrusted uploads |
| `CITADEL_DATA_DIR` | `/data/citadel` | Set only when `persistence.enabled` (file-store mode) |
| `PGSSL` / `PGSSL_VERIFY` / `PGSSL_CA` | `1` / `1` / `/certs/ca.pem` | Postgres TLS (set via `externalDatabase.ssl.*`) |
| `CITADEL_MULTITENANT` / `CITADEL_BASE_DOMAIN` | `1` / `citadel.example.com` | Schema-per-tenant (needs DB) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://otel-collector:4318` | Optional OTLP tracing (empty by default) |
| `CITADEL_ENABLE_CODEQL` | `1` | Opt-in CodeQL adapter (heavy; empty by default) |

Secret env (wired via `secretKeyRef` from `citadel-secrets`):

| Variable | Required? | Purpose |
|---|---|---|
| `CITADEL_JWT_SECRET` | **yes** (`optional:false`) | HS256 session signing key |
| `DATABASE_URL` | required when `externalDatabase.enabled` | Postgres connection string |
| `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD` | optional | First-boot admin |
| `CITADEL_SUPERADMIN_TOKEN` | optional (needed for multitenancy) | Tenant-provisioning token |
| `CITADEL_METRICS_TOKEN` | optional (needed for ServiceMonitor) | `/metrics` bearer token |

## 6. Configuration references (chart defaults)

| Value | Default | Purpose |
|---|---|---|
| `replicaCount` | `2` | Pod replicas (needs external DB when > 1) |
| `image.repository` / `image.tag` | `ghcr.io/your-org/citadel-server` / `latest` | Pin an **immutable tag/digest** in prod |
| `resources.requests` | `cpu: 250m`, `memory: 512Mi` | Scheduling floor |
| `resources.limits` | `cpu: "2"`, `memory: 2Gi` | Caps — **2Gi accommodates ClamAV** |
| `volumes.citadelTmp` | `emptyDir` `medium: Memory`, `512Mi` | Untrusted-upload scratch (`/tmp/citadel`) |
| `volumes.clamavDb` | `emptyDir` (disk), `1Gi` | ClamAV signature DB (`/var/lib/clamav`) |
| `autoscaling` | disabled; `min 2 / max 6`, CPU `70%` | HPA (`autoscaling/v2`) |
| `podDisruptionBudget` | `minAvailable: 1` | Availability during drains |
| `ingress` | `className: nginx`, host `citadel.example.com`, TLS `citadel-tls`, force-ssl-redirect | Edge TLS |
| `networkPolicy` | enabled; ingress from `ingress-nginx` + `monitoring`; egress DNS/443/5432 | Default-deny |
| `externalDatabase.ssl` | `enabled: true`, `verify: true` | Postgres TLS verification |
| `persistence` | disabled; `10Gi` RWO at `/data/citadel` | File-store PVC (1-replica mode) |
| `serviceMonitor` | disabled; interval `30s`, path `/metrics` | Prometheus Operator scrape |
| `startupProbe` | `periodSeconds: 5`, `failureThreshold: 30` | Slow first-boot tolerance (scanner DBs) |
| `livenessProbe` / `readinessProbe` | `/api/health` on `:8080` | Health |

## 7. Build, push & install

```bash
# Build from the repo root (Dockerfile lives under citadel/server/); pin an immutable tag.
docker build -f citadel/server/Dockerfile -t ghcr.io/your-org/citadel-server:v1.0.0 .
docker push ghcr.io/your-org/citadel-server:v1.0.0
```

**HA mode with external Postgres (recommended):**

```bash
helm install citadel ./citadel -n citadel --create-namespace \
  --set image.repository=ghcr.io/your-org/citadel-server \
  --set image.tag=v1.0.0 \
  --set replicaCount=2 \
  --set externalDatabase.enabled=true \
  --set externalDatabase.ssl.enabled=true --set externalDatabase.ssl.verify=true \
  --set secrets.create=false --set secrets.existingSecret=citadel-secrets \
  --set autoscaling.enabled=true --set autoscaling.minReplicas=2 --set autoscaling.maxReplicas=6 \
  --set ingress.hosts[0].host=citadel.example.com \
  --set ingress.tls[0].secretName=citadel-tls
```

**Single-replica file-store mode (no DB):**

```bash
helm install citadel ./citadel -n citadel --create-namespace \
  --set image.tag=v1.0.0 --set replicaCount=1 \
  --set persistence.enabled=true \
  --set secrets.create=false --set secrets.existingSecret=citadel-secrets \
  --set ingress.hosts[0].host=citadel.example.com --set ingress.tls[0].secretName=citadel-tls
```

**Raw manifests (no Helm):** `kubectl apply -f deploy/kubernetes/manifests/` (namespace `citadel`,
release `citadel`). Edit `20-secret.yaml` (or create `citadel-secrets` out-of-band and skip it),
the host in `50-ingress.yaml`, and namespace selectors in the NetworkPolicy.

**Multi-tenancy:** `--set multitenancy.enabled=true --set multitenancy.baseDomain=citadel.example.com`
(requires external DB). Add a **wildcard host + wildcard TLS cert** so `*.citadel.example.com`
reaches the Service, and ensure `CITADEL_SUPERADMIN_TOKEN` is in the Secret.

## 8. Verification

```bash
# 1. Rollout + pods healthy
kubectl -n citadel rollout status deploy/citadel
kubectl -n citadel get pods -l app.kubernetes.io/name=citadel

# 2. Health endpoint
kubectl -n citadel port-forward svc/citadel 8080:80 &
curl -fsS http://localhost:8080/api/health | jq   # expect {"status":"ok",...}

# 3. Login works (JWT) + secrets resolved from the Secret
TOKEN=$(curl -sS -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"<from citadel-secrets>"}' | jq -r .token)
curl -fsS http://localhost:8080/api/auth/me -H "Authorization: Bearer $TOKEN" | jq .email

# 4. Upload accepted + SCANNED (findings returned)
zip -r /tmp/s.zip citadel/js >/dev/null
curl -sS -X POST http://localhost:8080/api/scan -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/s.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length)}' /tmp/report.json  # findings > 0

# 5. Report persisted (DB-backed history when DATABASE_URL is set)
curl -fsS http://localhost:8080/api/scans -H "Authorization: Bearer $TOKEN" | jq 'length'
psql "$DATABASE_URL" -c "SELECT count(*) FROM citadel_scans;"   # row landed
```

**Secrets resolved check:** `kubectl -n citadel get secret citadel-secrets -o jsonpath='{.data}' | jq keys`
and confirm the pod started (a missing required `CITADEL_JWT_SECRET`/`DATABASE_URL` blocks start).

## 9. Day-2 operations

- **Scanner signature / DB updates.** The scratch/ClamAV volumes are `emptyDir` (per-pod,
  wiped on restart), so each pod refreshes its DBs after (re)start. To avoid slow first scans:
  - Bake fresh DBs into the image on rebuild, **or** run `freshclam` / `trivy --download-db-only`
    / `grype db update` from a **startup step / sidecar**, **or** mount a shared PVC / in-cluster
    mirror. For **air-gapped** clusters see [AIRGAPPED.md](AIRGAPPED.md).
  - `kubectl -n citadel exec deploy/citadel -- freshclam`
- **Upgrades.** Roll a new immutable image tag: `helm upgrade citadel ./citadel -n citadel
  --reuse-values --set image.tag=v1.1.0`. `maxUnavailable: 0` keeps the service up; the
  `checksum/secret` pod annotation forces a rollout when the Secret changes.
- **DB migrations.** The server **creates its schema on boot**; the manual reference is
  [`../database/schema.sql`](../database/schema.sql) (idempotent). No separate migrate job needed.
- **Scaling.** Prefer horizontal scaling (more replicas) over one huge pod — scans are bursty
  and CPU/RAM-bound. HPA targets CPU 70 %. Ensure nodes can host the 2Gi limit.
- **Backups.** Back up the **Postgres** database (users, sessions, scans, audit, settings,
  dispositions) and, in file-store mode, the **PVC** before teardown.
- **Secret rotation.** Rotate `CITADEL_JWT_SECRET` / DB creds in the vault; ESO re-syncs the
  Secret and the annotation checksum rolls the pods. Rotating the JWT secret invalidates sessions.
- **Metrics.** Enable the ServiceMonitor with `CITADEL_METRICS_TOKEN`; otherwise `/metrics` is
  loopback-only.
- **Logs.** Structured JSON to stdout: `kubectl -n citadel logs deploy/citadel -f`.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Chart install rejected ("requires externalDatabase") | `replicaCount>1` / autoscaling / multitenancy without a DB | Set `externalDatabase.enabled=true` or drop to `replicaCount=1` file-store |
| Pods `OOMKilled` / scans 502 | 2Gi too tight for concurrent scanners | Raise `resources.limits.memory`; set `SCAN_CONCURRENCY=1`; `CITADEL_SCAN_ISOLATION=0` |
| Pod stuck `ContainerCreating` / not `Ready` | Slow startup (Trivy/Grype/ClamAV DB pull) | `startupProbe` allows ~150 s; pre-seed DBs or use a sidecar; check egress NetworkPolicy |
| `CreateContainerConfigError` | Missing key in `citadel-secrets` | Add `CITADEL_JWT_SECRET` (and `DATABASE_URL` when DB enabled) |
| 5xx to Postgres | TLS verify failing / SG/NetworkPolicy | Provide `PGSSL_CA`, confirm egress 5432 allowed, `sslmode=verify-full` reachable |
| ServiceMonitor scrapes 404/401 | `/metrics` gated | Set `CITADEL_METRICS_TOKEN` and wire `metrics.serviceMonitor.bearerTokenSecret` |
| ClamAV findings absent | DB not refreshed on this pod | `exec … freshclam`; keep a warm DB source; ClamAV degrades gracefully if absent |
| File-store data lost on restart | Multi-replica with file store (no DB) | Use external Postgres for HA, or 1 replica + PVC |

## 11. NIST SP 800-53 Rev5 cross-walk (this package)

Non-root 10001 + `runAsNonRoot`/`allowPrivilegeEscalation:false` (**AC-6/CM-7**); read-only root
FS, memory-backed scratch (**CM-7/SI-7**); caps drop ALL + `seccompProfile: RuntimeDefault`
(**AC-6/SI-3**); default-deny NetworkPolicy (**SC-7/AC-4**); TLS at ingress + to Postgres
(**SC-8/SC-13**); Secrets from K8s/vault (**IA-5/SC-12/SC-28**); SA token not auto-mounted
(**AC-6**); PodSecurity "restricted" (**CM-6**); requests/limits + HPA + PDB (**SC-5/SC-6**);
`/api/health` probes + `maxUnavailable:0` (**SI-4/CP-10**); token-gated `/metrics`
(**AU-6/SI-4**); untrusted uploads quarantined in memory-backed scratch + ClamAV (**SI-3/SC-44**).

## 12. Teardown

```bash
helm uninstall citadel -n citadel        # or: kubectl delete -f deploy/kubernetes/manifests/
kubectl delete namespace citadel         # also removes Secret + PVCs — back up the DB/PVC first
```
