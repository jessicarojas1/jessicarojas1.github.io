# Teacher Hub — Kubernetes

**Target:** serve Teacher Hub from a hardened static-nginx `Deployment` behind an
Ingress. Appropriate when a district already runs Kubernetes and wants the
classroom hub delivered with the same platform, TLS, and headers as other apps.

> **Applicability:** Applicable but arguably heavier than needed — a purely static
> site is equally well served by object storage + CDN ([AWS.md](AWS.md),
> [AZURE.md](AZURE.md)). Use k8s here for uniformity with an existing platform.
> The container adds the **security headers + CSP the HTML lacks**.

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[../Dockerfile](../Dockerfile) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

An `nginx-unprivileged` container image (built from [../Dockerfile](../Dockerfile))
bakes in the static files (`teacher/`, plus `theme.css`/`favicon.ico` at the web
root so `../` resolves) and a hardened `nginx.conf` that sets cache + security
headers. The pod is stateless and `readOnlyRootFilesystem`; scale it horizontally
with an HPA; protect availability with a PDB. No PVC, no DB, no secrets — the app
holds all state in the visitor's browser `localStorage`.

## 2. Topology

```
                 Internet / District network
                          │ 443
                          ▼
                    ┌───────────┐
                    │  Ingress  │  (TLS via cert-manager)
                    │  nginx/ALB│
                    └─────┬─────┘
                          │  ClusterIP :8080
                    ┌─────▼──────────────────────────┐
                    │ Service teacherhub             │
                    └─────┬───────────────┬──────────┘
                   ┌──────▼─────┐   ┌──────▼─────┐   (HPA 2..N)
                   │ Pod nginx  │   │ Pod nginx  │   readOnlyRootFS
                   │ static site│   │ static site│   non-root (uid 101)
                   └────────────┘   └────────────┘
                          │ browser also fetches Bootstrap/Icons
                          ▼
                    cdn.jsdelivr.net   (or vendored → offline, see AIRGAPPED.md)
```

## 3. Prerequisites

| Item | Version / note |
|------|----------------|
| Kubernetes | 1.26+ |
| Ingress controller | ingress-nginx / cloud ALB |
| cert-manager | for TLS (or platform-managed certs) |
| Container registry | to push the image built from `../Dockerfile` |
| kubectl / Helm | 1.26+ / 3.x |

## 4. Identity & credentials

The workload needs **no identity** — it serves files and reads nothing privileged.
Run it with a dedicated ServiceAccount that has **no RBAC and no token
automount**:

```yaml
apiVersion: v1
kind: ServiceAccount
metadata:
  name: teacherhub
automountServiceAccountToken: false
```

The identity that matters is the **CI deploy identity** that builds and pushes the
image and applies manifests — use the cluster's OIDC/workload identity (IRSA on
EKS, Workload Identity on AKS/GKE) scoped to registry push + `apply` on this
namespace only. No static registry passwords in cluster secrets where avoidable.

## 5. Environment variables

The container/app read **no application environment variables.** The knobs are
image tag and replica count:

| Variable / knob | Example | Purpose |
|-----------------|---------|---------|
| image tag | `registry/teacherhub:2026.07.01` | pin the built static bundle |
| `replicas` / HPA min-max | `2` / `2..6` | availability & scale |
| Ingress host | `teacherhub.school.k12.us` | external hostname |

## 6. Configuration references

Core manifests (Deployment + Service + HPA + PDB). The hardened `nginx.conf` and
CSP live in the image (see [../Dockerfile](../Dockerfile)); you may also set the
CSP at the Ingress via annotations.

```yaml
apiVersion: apps/v1
kind: Deployment
metadata: { name: teacherhub, labels: { app: teacherhub } }
spec:
  replicas: 2
  selector: { matchLabels: { app: teacherhub } }
  template:
    metadata: { labels: { app: teacherhub } }
    spec:
      serviceAccountName: teacherhub
      automountServiceAccountToken: false
      securityContext:
        runAsNonRoot: true
        runAsUser: 101            # nginx-unprivileged
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: nginx
          image: registry/teacherhub:2026.07.01
          ports: [{ containerPort: 8080 }]
          readinessProbe: { httpGet: { path: /teacher/, port: 8080 }, initialDelaySeconds: 2 }
          livenessProbe:  { httpGet: { path: /teacher/, port: 8080 }, periodSeconds: 15 }
          resources:
            requests: { cpu: 10m, memory: 32Mi }
            limits:   { cpu: 200m, memory: 128Mi }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
          volumeMounts:
            - { name: cache, mountPath: /tmp }
            - { name: run,   mountPath: /var/run }
      volumes:
        - { name: cache, emptyDir: {} }
        - { name: run,   emptyDir: {} }
---
apiVersion: v1
kind: Service
metadata: { name: teacherhub }
spec:
  selector: { app: teacherhub }
  ports: [{ port: 8080, targetPort: 8080 }]
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: teacherhub }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: teacherhub }
  minReplicas: 2
  maxReplicas: 6
  metrics:
    - type: Resource
      resource: { name: cpu, target: { type: Utilization, averageUtilization: 60 } }
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: teacherhub }
spec:
  minAvailable: 1
  selector: { matchLabels: { app: teacherhub } }
```

Ingress (adds/asserts CSP + HSTS at the edge; TLS via cert-manager):

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: teacherhub
  annotations:
    cert-manager.io/cluster-issuer: "letsencrypt-prod"
    nginx.ingress.kubernetes.io/configuration-snippet: |
      more_set_headers "Content-Security-Policy: default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; font-src 'self' https://cdn.jsdelivr.net; img-src 'self' data: https:; connect-src 'self'; frame-ancestors 'none'; object-src 'none'; form-action 'self'";
      more_set_headers "X-Content-Type-Options: nosniff";
      more_set_headers "Referrer-Policy: no-referrer";
spec:
  tls: [{ hosts: [teacherhub.school.k12.us], secretName: teacherhub-tls }]
  rules:
    - host: teacherhub.school.k12.us
      http:
        paths:
          - path: /
            pathType: Prefix
            backend: { service: { name: teacherhub, port: { number: 8080 } } }
```

> `script-src` is strict (`'self'` + jsDelivr, no `'unsafe-inline'`) — all JS is
> externalized and every handler is a `data-*` attribute wired by delegation.
> `style-src` keeps `'unsafe-inline'` for inline `style=""` + the `<style>` block
> (see [../OPEN_ITEMS.md](../OPEN_ITEMS.md)).

## 7. Verification

No DB/login/secret/upload/object-write — verify pod health + served content:

```bash
kubectl -n teacherhub rollout status deploy/teacherhub
kubectl -n teacherhub get pods -o wide

# In-cluster: entry page 200
kubectl -n teacherhub run curl --rm -it --image=curlimages/curl --restart=Never -- \
  -sS -o /dev/null -w '%{http_code}\n' http://teacherhub:8080/teacher/     # 200

# Through the Ingress: 200 + CSP header
curl -sSI https://teacherhub.school.k12.us/teacher/ | grep -iE 'HTTP/|content-security-policy'

# Assets resolve at the served layout
curl -sS -o /dev/null -w '%{http_code}\n' https://teacherhub.school.k12.us/theme.css   # 200
```

Then browser checks (theme persist, 10 tabs, save plan/grade + reload, CSV export,
template print, branding) per [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) §7.

## 8. Day-2 operations

| Task | Command |
|------|---------|
| Release | build+push new image tag, `kubectl set image deploy/teacherhub nginx=registry/teacherhub:<tag>` |
| Rollback | `kubectl rollout undo deploy/teacherhub` |
| Scale | edit HPA min/max, or `kubectl scale` |
| Cert rotation | cert-manager auto-renews `teacherhub-tls` |
| Logs | `kubectl logs -l app=teacherhub` (access logs only; no app/business logs) |
| Backups | **none needed** — image is rebuildable from git; no cluster-side state. Student data is in browsers, not the cluster ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)). |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod CrashLoopBackOff | nginx can't write temp with `readOnlyRootFilesystem` | mount `emptyDir` at `/tmp` and `/var/run` (shown above) |
| 403/permission on start | running as root in a non-root image | use `nginx-unprivileged`, port 8080, uid 101 |
| 404 `/theme.css` | image missing root-level `theme.css` | ensure Docker build copies repo-root files (see [../Dockerfile](../Dockerfile)) |
| No styles/icons | egress to jsDelivr blocked | allow egress, or bake vendored assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| CSP breaks page | dropped `'unsafe-inline'` from **style-src** | keep `'unsafe-inline'` on **style-src** (inline styles); `script-src` is already strict |
| Ingress 502 | probe path wrong / port mismatch | probes and Service target `:8080`, path `/teacher/` |
