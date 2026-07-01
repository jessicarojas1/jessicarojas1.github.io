# Kubernetes — Compliance Copilot

Operator guide for deploying **Compliance Copilot** to Kubernetes. Compliance Copilot is a
stateless **Next.js 14/16** app (`next start`, port 3000) backed by **Supabase**
(PostgreSQL + Storage + Auth) and an optional **Anthropic Claude** relay. All persistent state
lives in Supabase, so the workload scales horizontally with no in-cluster database required.

> Cross-links: [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md) · [AZURE.md](./AZURE.md) ·
> [AWS.md](./AWS.md) · [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

A `Deployment` of N replicas runs the app container (image built by the repo `Dockerfile`).
A `Service` (ClusterIP) fronts the pods; an `Ingress` (nginx/Traefik/cloud LB) terminates TLS.
Secrets are delivered via the **Secrets Store CSI driver** or **External Secrets Operator**
(from Vault / cloud secret manager) rather than plain `Secret` manifests. Supabase is external
(SaaS or self-hosted in another namespace/cluster).

> **Note on the in-memory rate limiter:** `/api/ai/generate` and `/api/auth/login` use a
> **per-process** best-effort limiter. Across multiple replicas it does **not** enforce a global
> limit — add rate limiting at the Ingress/WAF for production-grade protection.

---

## 2. Topology

```
                 Internet
                    │ 443
                    ▼
             ┌────────────┐
             │  Ingress   │  TLS (cert-manager)  + rate-limit annotations
             └─────┬──────┘
                   ▼
             ┌────────────┐   ClusterIP
             │  Service   │
             └─────┬──────┘
        ┌──────────┼──────────┐
        ▼          ▼          ▼
     ┌─────┐    ┌─────┐    ┌─────┐   Deployment (HPA 2..N), PodDisruptionBudget
     │ pod │    │ pod │    │ pod │   next start :3000, readiness=/  liveness=/
     └──┬──┘    └──┬──┘    └──┬──┘
        └──────────┴──────────┘
                   │ HTTPS (anon + service role via CSI-mounted secret)
                   ▼
        Supabase (SaaS or self-hosted)  ── + outbound 443 → api.anthropic.com
```

---

## 3. Prerequisites

| Item | Version / detail |
|---|---|
| Kubernetes | 1.27+ |
| Ingress controller | ingress-nginx / Traefik / cloud LB |
| cert-manager | for TLS (or provide certs) |
| Secrets delivery | Secrets Store CSI driver **or** External Secrets Operator |
| Container image | built from repo `Dockerfile`, pushed to a registry |
| Supabase | external project (SaaS or self-hosted) reachable from the cluster |
| Metrics server | for HPA |

---

## 4. Identity & credentials

Prefer **workload identity** to pull secrets, not static `Secret` manifests:

- **EKS**: IRSA — annotate the ServiceAccount with an IAM role that reads the relevant Secrets
  Manager entries (see [AWS.md](./AWS.md)).
- **AKS**: Azure Workload Identity — federate the ServiceAccount to a managed identity with Key
  Vault `get` on the secrets (see [AZURE.md](./AZURE.md)).
- **Vault**: External Secrets Operator with a Kubernetes auth role scoped to this namespace.

Least-privilege ServiceAccount + SecretProviderClass (CSI) example:

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: compliance-copilot
  namespace: grc
  # EKS: eks.amazonaws.com/role-arn: arn:aws:iam::<acct>:role/cc-secrets-reader
  # AKS: azure.workload.identity/client-id: <managed-identity-client-id>
---
apiVersion: secrets-store.csi.x-k8s.io/v1
kind: SecretProviderClass
metadata: { name: cc-secrets, namespace: grc }
spec:
  provider: aws            # or azure
  parameters:
    objects: |
      - objectName: "compliance-copilot/supabase-service-role"
        objectType: "secretsmanager"
      - objectName: "compliance-copilot/anthropic-api-key"
        objectType: "secretsmanager"
```

The service role and Anthropic keys are mounted only into the app pods; the anon key may be a
plain ConfigMap value (it is browser-public anyway).

---

## 5. Environment variables

Delivered via CSI-mounted secret (synced to env) or ExternalSecret → `Secret` → `envFrom`.

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://abcd.supabase.co` | Supabase project URL |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | Public anon key (ConfigMap ok) |
| `SUPABASE_SERVICE_ROLE_KEY` | *(from secret store)* | Service role key (server-only) |
| `ANTHROPIC_API_KEY` | *(from secret store)* | AI Copilot upstream key |
| `AI_PROXY_TOKEN` | *(from secret store)* | Required in prod to gate token callers on `/api/ai/generate` |
| `APP_SESSION_SECRET` | *(from secret store)* | HMAC signs `cc_session`; ≥16 chars |
| `APP_AUTH_USERNAME` | `issoadmin` | Login username |
| `APP_AUTH_PASSWORD` | *(from secret store)* | Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Storage bucket name |
| `BRANDING_ADMIN_TOKEN` | *(from secret store)* | Gates branding write |
| `NODE_ENV` | `production` | Secure cookies + fail-closed relay |
| `PORT` | `3000` | Container listen port |
| `HOSTNAME` | `0.0.0.0` | Bind all interfaces inside the pod |

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| Readiness probe | `GET /` :3000 | Marks pod ready (dashboard is the health surface) |
| Liveness probe | `GET /` :3000 | Restarts wedged pods |
| Ingress rate-limit | `nginx.ingress.kubernetes.io/limit-rpm: "60"` | Global limit (compensates per-pod limiter) |
| `next.config.js` `remotePatterns` | `*.supabase.co` | Supabase image hosts |
| HPA target | CPU 70% | Scale trigger |

---

## 7. Verification

Deployment core (`deployment.yaml`):

```yaml
apiVersion: apps/v1
kind: Deployment
metadata: { name: compliance-copilot, namespace: grc }
spec:
  replicas: 2
  selector: { matchLabels: { app: compliance-copilot } }
  template:
    metadata: { labels: { app: compliance-copilot } }
    spec:
      serviceAccountName: compliance-copilot
      securityContext: { runAsNonRoot: true, runAsUser: 1001, seccompProfile: { type: RuntimeDefault } }
      containers:
        - name: app
          image: <registry>/compliance-copilot:<tag>
          ports: [{ containerPort: 3000 }]
          envFrom: [{ secretRef: { name: cc-secrets } }]
          readinessProbe: { httpGet: { path: /, port: 3000 }, initialDelaySeconds: 5 }
          livenessProbe:  { httpGet: { path: /, port: 3000 }, initialDelaySeconds: 15 }
          resources: { requests: { cpu: 250m, memory: 256Mi }, limits: { cpu: "1", memory: 512Mi } }
          securityContext: { allowPrivilegeEscalation: false, readOnlyRootFilesystem: true, capabilities: { drop: ["ALL"] } }
```

Plus `Service` (ClusterIP :80→3000), `Ingress` (TLS), `HPA` (2..6, CPU 70%), and a
`PodDisruptionBudget` (`minAvailable: 1`).

One-time DB + bucket setup (external Supabase):

```bash
psql "$SUPABASE_DB_URL" -f supabase/schema.sql
# Create bucket 'evidence-files' in Supabase Studio
```

Checks:

```bash
kubectl -n grc rollout status deploy/compliance-copilot
kubectl -n grc get pods -l app=compliance-copilot

# Health / homepage
curl -sI https://grc.example.com/ | head -1                       # 200

# Login works
curl -s -X POST https://grc.example.com/api/auth/login \
  -H 'Content-Type: application/json' -d '{"username":"issoadmin","password":"<pw>"}'

# Secrets resolved (relay authorized)
curl -s -X POST https://grc.example.com/api/ai/generate \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $AI_PROXY_TOKEN" \
  -d '{"prompt":"Suggest improvements for 3.14.1"}'

# DB row present + storage object after Evidence upload
psql "$SUPABASE_DB_URL" -c "select count(*) from controls;"
psql "$SUPABASE_DB_URL" -c "select file_name from evidence order by created_at desc limit 1;"
```

---

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade | Build new image, `kubectl set image deploy/compliance-copilot app=<img>:<tag>`; rolling update |
| Rollback | `kubectl rollout undo deploy/compliance-copilot` |
| DB migration | Run `supabase/schema.sql` via a one-shot `Job` or `psql` from a bastion |
| Scale | HPA auto; or `kubectl scale --replicas=N` |
| Backups | Supabase-managed (SaaS) or `pg_dump` CronJob for self-hosted; object storage snapshots |
| Secret rotation | Update secret store; ESO/CSI re-syncs; `kubectl rollout restart` to pick up env changes |
| Cert rotation | cert-manager auto-renews |
| Logs | `kubectl logs -l app=compliance-copilot -f`; ship to cluster logging stack |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pods `CrashLoopBackOff` | Missing required env | Confirm `NEXT_PUBLIC_SUPABASE_*` in the mounted secret |
| Readiness never passes | App not up on :3000 / probe path wrong | Probe must target `/` on 3000 |
| Secrets empty | CSI/ESO not synced or IAM/identity missing | Check SecretProviderClass + ServiceAccount identity annotation |
| AI relay 503 | Prod + no `AI_PROXY_TOKEN` + no session | Provide token via secret store |
| Rate limit ineffective | Per-pod limiter only | Add Ingress `limit-rpm` / WAF rule |
| Evidence upload fails | Bucket missing | Create `evidence-files` in Supabase |
| `readOnlyRootFilesystem` write error | Next.js needs a writable temp | Mount an `emptyDir` at `/tmp` if required |
