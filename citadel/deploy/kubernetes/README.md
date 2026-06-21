# CITADEL — Kubernetes / Helm Deployment Runbook

**CITADEL** — *Code Inspection, Threat Analysis & Deployment Evaluation Lab* — is a
**Node 20 Express** server that serves a single-page app and runs source-code /
executable security scanners. This directory packages the **production** service
for any conformant Kubernetes cluster (EKS, AKS, GKE, OpenShift, on-prem), with a
hardened, least-privilege pod posture aligned to **NIST SP 800-53 Rev5** (the
sibling `aws-gov/` and `azure-gov/` packages cross-walk the same controls for
**CUI / FedRAMP / IL4–IL5** workloads).

Two equivalent delivery formats are provided:

| Path | Format | Use when |
|------|--------|----------|
| [`citadel/`](./citadel/) | **Helm chart** | You want values-driven config, HPA, ServiceMonitor, multi-tenancy toggles, templated secrets. |
| [`manifests/`](./manifests/) | **Raw YAML** | You want plain `kubectl apply` with no Helm dependency. A faithful mirror of the chart defaults. |

---

## 1. Application facts (what the chart wires)

- **Listen port:** `8080` (HTTP) — `PORT` env, container port `http`.
- **Health probe:** `GET /api/health` (200 = healthy) — liveness, readiness, startup.
- **Metrics:** `GET /metrics` (Prometheus), gated — loopback or `CITADEL_METRICS_TOKEN` bearer.
- **Runs as:** non-root **UID/GID 10001**, **read-only root filesystem**.
- **Only writable paths:** `CITADEL_TMP=/tmp/citadel` (memory-backed `emptyDir`; scratch for untrusted uploads) and optionally `/var/lib/clamav` (ClamAV DB; `emptyDir`).
- **State:**
  - **No DB** → file store at `CITADEL_DATA_DIR` (needs a PVC; **single writer → 1 replica**).
  - **External Postgres** (`DATABASE_URL`) → durable, **HA (replicas > 1)** and **multi-tenant** capable.

---

## 2. Prerequisites

- Kubernetes **1.23+** (PodSecurity "restricted" admission, `autoscaling/v2`, `policy/v1`).
- **Helm 3.8+** (for the chart path).
- An **ingress controller** (default annotations assume `ingress-nginx`) and a TLS
  cert source (e.g. **cert-manager**) for the `citadel-tls` Secret.
- **metrics-server** (only if you enable the HPA).
- **Prometheus Operator** CRDs (only if you enable the ServiceMonitor).
- An **external Postgres** with TLS (required for HA replicas > 1 and multi-tenancy).
- A way to manage Secrets: `kubectl create secret`, **External Secrets Operator**,
  **Sealed Secrets**, or a cloud secret manager (Vault / AWS SM / Azure Key Vault).

---

## 3. Build & push the image

The image is built **from the repository root** (the Dockerfile lives under
`citadel/server/`):

```bash
# from the repo root
docker build -f citadel/server/Dockerfile -t ghcr.io/your-org/citadel-server:v1.0.0 .
docker push ghcr.io/your-org/citadel-server:v1.0.0
```

Pin an **immutable tag or digest** in production (avoid `latest`). For private
registries, create a pull secret and reference it:

```bash
kubectl -n citadel create secret docker-registry ghcr-creds \
  --docker-server=ghcr.io --docker-username=USER --docker-password=TOKEN
# then: --set imagePullSecrets[0].name=ghcr-creds
```

---

## 4. Create the application Secret (never commit real values)

All sensitive env (`CITADEL_JWT_SECRET`, admin creds, `DATABASE_URL`,
`CITADEL_SUPERADMIN_TOKEN`, `CITADEL_METRICS_TOKEN`) comes from a Kubernetes
Secret. Create it out-of-band and point the chart at it with
`secrets.existingSecret` (recommended), or let the chart create one from
`--set-string` values.

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

> **Production best practice:** manage `citadel-secrets` with the External Secrets
> Operator or Sealed Secrets so the values live in a vault, not in a Secret you
> hand-create. (NIST **IA-5**, **SC-12/SC-28**.)

---

## 5. Install with Helm

### 5a. Single-replica file-store mode (quick start / no DB)

```bash
helm install citadel ./citadel -n citadel --create-namespace \
  --set image.repository=ghcr.io/your-org/citadel-server \
  --set image.tag=v1.0.0 \
  --set replicaCount=1 \
  --set persistence.enabled=true \
  --set secrets.create=false \
  --set secrets.existingSecret=citadel-secrets \
  --set ingress.hosts[0].host=citadel.example.com \
  --set ingress.tls[0].hosts[0]=citadel.example.com \
  --set ingress.tls[0].secretName=citadel-tls
```

> The chart **fails fast** if you ask for `replicaCount > 1`, `autoscaling.enabled`,
> or `multitenancy.enabled` **without** `externalDatabase.enabled=true`.

### 5b. HA mode with external Postgres (default 2 replicas)

```bash
helm install citadel ./citadel -n citadel --create-namespace \
  --set image.tag=v1.0.0 \
  --set replicaCount=2 \
  --set externalDatabase.enabled=true \
  --set externalDatabase.ssl.enabled=true \
  --set externalDatabase.ssl.verify=true \
  --set secrets.create=false \
  --set secrets.existingSecret=citadel-secrets \
  --set autoscaling.enabled=true \
  --set autoscaling.minReplicas=2 \
  --set autoscaling.maxReplicas=6 \
  --set ingress.hosts[0].host=citadel.example.com \
  --set ingress.tls[0].secretName=citadel-tls
```

For anything beyond a couple of flags, use a values file:

```bash
helm install citadel ./citadel -n citadel -f my-values.yaml
```

### Chart-managed Secret (alternative to step 4)

If you prefer the chart to create the Secret (e.g. in CI that already has the
values), keep `secrets.create=true` and pass values at install — **never** put
them in a committed `values.yaml`:

```bash
helm install citadel ./citadel -n citadel \
  --set-string secrets.values.jwtSecret="$(openssl rand -hex 32)" \
  --set-string secrets.values.databaseUrl="$DATABASE_URL" \
  --set externalDatabase.enabled=true
```

---

## 6. Enable multi-tenancy (schema-per-tenant)

Requires an external Postgres. Subdomain routing resolves tenants from
`CITADEL_BASE_DOMAIN`; tenant provisioning uses `CITADEL_SUPERADMIN_TOKEN`.

```bash
helm upgrade citadel ./citadel -n citadel \
  --reuse-values \
  --set externalDatabase.enabled=true \
  --set multitenancy.enabled=true \
  --set multitenancy.baseDomain=citadel.example.com
```

Add a **wildcard host + wildcard TLS cert** to the ingress so `*.citadel.example.com`
reaches the service. Ensure `CITADEL_SUPERADMIN_TOKEN` exists in the Secret.

---

## 7. Metrics & ServiceMonitor

`GET /metrics` is gated. For network scraping, set `CITADEL_METRICS_TOKEN` in the
Secret and enable the ServiceMonitor (needs the Prometheus Operator CRD):

```bash
helm upgrade citadel ./citadel -n citadel --reuse-values \
  --set metrics.serviceMonitor.enabled=true \
  --set metrics.serviceMonitor.bearerTokenSecret.name=citadel-secrets \
  --set metrics.serviceMonitor.bearerTokenSecret.key=CITADEL_METRICS_TOKEN
```

Without the operator, scrape via static config / annotations against the Service
port using the same bearer token.

---

## 8. Raw-manifest path (no Helm)

The `manifests/` directory mirrors the chart's defaults (release `citadel`,
namespace `citadel`).

```bash
# 1) Edit manifests/20-secret.yaml (or better, create citadel-secrets out-of-band
#    and SKIP that file). 2) Adjust the host in 50-ingress.yaml and the namespace
#    selectors in 80-networkpolicy.yaml.
kubectl apply -f deploy/kubernetes/manifests/
```

Files: `00-namespace`, `10-serviceaccount`, `20-secret` (placeholder),
`30-deployment`, `40-service`, `50-ingress`, `60-hpa`, `70-pdb`,
`80-networkpolicy`. For file-store mode, scale the Deployment to 1 and add a PVC
mounted at `/data/citadel` with `CITADEL_DATA_DIR` set.

---

## 9. Verify

```bash
kubectl -n citadel rollout status deploy/citadel
kubectl -n citadel get pods -l app.kubernetes.io/name=citadel
kubectl -n citadel port-forward svc/citadel 8080:80 &
curl -fsS http://localhost:8080/api/health   # expect 200
```

---

## 10. NIST SP 800-53 Rev5 control cross-walk

| # | Deployment control (this package) | NIST SP 800-53 Rev5 |
|---|-----------------------------------|---------------------|
| 1 | Pods run **non-root** (UID/GID 10001), `runAsNonRoot: true`, `allowPrivilegeEscalation: false` | **AC-6**, **CM-7** |
| 2 | **Read-only root filesystem**; only memory-backed `emptyDir` scratch is writable | **CM-7**, **SI-7** |
| 3 | **All Linux capabilities dropped** (`capabilities.drop: [ALL]`) + `seccompProfile: RuntimeDefault` | **AC-6**, **CM-7**, **SI-3** |
| 4 | **NetworkPolicy** default-deny ingress/egress; ingress from controller ns only, curated egress | **SC-7**, **AC-4** |
| 5 | **TLS terminates at ingress** (HTTPS-only, force-ssl-redirect) | **SC-8**, **SC-13** |
| 6 | **TLS to Postgres** (`PGSSL` + `PGSSL_VERIFY` / `PGSSL_CA`) | **SC-8**, **SC-13** |
| 7 | **Secrets from K8s Secrets / external vault** — no credentials in git or `values.yaml` | **IA-5**, **SC-12**, **SC-28** |
| 8 | **Least-privilege ServiceAccount**, API token **not** auto-mounted | **AC-6**, **AC-3** |
| 9 | **PodSecurity "restricted"** admission enforced on the namespace | **CM-6**, **CM-7** |
| 10 | **Resource requests/limits**, **HPA**, **PodDisruptionBudget** for availability | **SC-5**, **SC-6** |
| 11 | **Health probes** (`/api/health`) + rolling updates with `maxUnavailable: 0` | **SI-4**, **CP-10** |
| 12 | **Token-gated metrics** (`/metrics` via `CITADEL_METRICS_TOKEN`) + OTLP telemetry | **AU-6**, **SI-4** |
| 13 | **Untrusted uploads quarantined** in memory-backed scratch (`CITADEL_TMP`) + ClamAV | **SI-3**, **SC-44** |

---

## 11. Teardown

```bash
# Helm
helm uninstall citadel -n citadel

# Raw manifests
kubectl delete -f deploy/kubernetes/manifests/

# Namespace (also removes the Secret, PVCs, etc.)
kubectl delete namespace citadel
```

> Deleting the namespace removes the `citadel-secrets` Secret and any PVC. Back up
> the file store (PVC) or database before teardown if you need the data.

---

## 12. Validation notes

The chart is standard Helm v2-API templating. If `helm`/`kubeconform` are
available, validate before install:

```bash
helm lint deploy/kubernetes/citadel
helm template t deploy/kubernetes/citadel | kubeconform -strict -ignore-missing-schemas
kubectl apply --dry-run=server -f deploy/kubernetes/manifests/
```

In the authoring environment `helm`, `kubeconform`, and `kubectl` were **not
installed**, so templates were validated by structure/by eye; run the commands
above in your cluster's tooling environment as a final gate.
