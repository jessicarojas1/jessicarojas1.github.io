# Kubernetes — `cmmc2`

Serve the CMMC 2.0 Readiness Assessment Platform from an nginx-static Deployment behind an
Ingress. It is a static site: no database, no worker, no secrets, `readOnlyRootFilesystem`
and horizontal scaling are trivial because every replica is identical and stateless.

## 1. Deployment architecture

A container image (built from [`../Dockerfile`](../Dockerfile), nginx serving the portfolio
tree with `cmmc2` at `/cmmc2/`) runs as a Deployment with N replicas. A Service fronts the
pods; an Ingress (or Gateway) terminates TLS and routes the host to the Service. An HPA
scales on CPU; a PDB protects availability during disruptions. Assets can be baked into the
image (recommended) or mounted from a ConfigMap. The browser still fetches Bootstrap/Icons/
SheetJS from jsDelivr (or the vendored copy in the air-gapped image).

## 2. Topology

```
                Internet
                   │ 443
              ┌────▼─────┐
              │ Ingress  │  (TLS: cert-manager)
              └────┬─────┘
                   │
              ┌────▼─────┐   Service (ClusterIP :8080)
              │  svc     │
              └────┬─────┘
        ┌──────────┼──────────┐
   ┌────▼───┐  ┌───▼────┐  ┌──▼─────┐   Deployment (nginx-unprivileged, non-root,
   │ pod    │  │ pod    │  │ pod    │     readOnlyRootFilesystem, image bakes static files)
   └────────┘  └────────┘  └────────┘
   HPA (CPU) + PDB(minAvailable=1)
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Kubernetes | ≥ 1.25 |
| Ingress controller | nginx-ingress / Contour / cloud LB |
| cert-manager (or managed TLS) | for HTTPS |
| Container registry | to host the image |
| kubectl / Helm | to apply manifests |
| Built image | `cmmc2:<tag>` from the repo-root build context |

## 4. Identity & credentials

- **Running site:** no identity/secrets — do **not** attach a ServiceAccount token
  (`automountServiceAccountToken: false`).
- **Deploy pipeline:** CI authenticates to the **registry** and the **cluster** via
  workload identity / OIDC (e.g. IRSA, GKE Workload Identity, or a short-lived kubeconfig) —
  not long-lived credentials. Image pull uses an `imagePullSecret` or cloud-native registry
  auth.

## 5. Environment variables

**None for the app.** Container config is the nginx image + config, not env.

| Variable | Example | Purpose |
|---|---|---|
| _(none — app)_ | — | Static site needs no env vars |
| `IMAGE_TAG` (CI) | `cmmc2:2026.07.01` | Image reference for the Deployment |

## 6. Configuration references

Image is built with `-f cmmc2/Dockerfile` and context = repo root (bakes `cmmc2/` + parent
assets; nginx config from [`../nginx.conf`](../nginx.conf) adds CSP + security headers).

`k8s/cmmc2.yaml`:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: cmmc2
  labels: { app: cmmc2 }
spec:
  replicas: 2
  selector: { matchLabels: { app: cmmc2 } }
  template:
    metadata: { labels: { app: cmmc2 } }
    spec:
      automountServiceAccountToken: false
      securityContext:
        runAsNonRoot: true
        runAsUser: 101
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: nginx
          image: registry.example.com/cmmc2:2026.07.01
          ports: [{ containerPort: 8080 }]
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
          resources:
            requests: { cpu: "10m", memory: "32Mi" }
            limits:   { cpu: "100m", memory: "64Mi" }
          readinessProbe:
            httpGet: { path: /healthz, port: 8080 }
            initialDelaySeconds: 3
          livenessProbe:
            httpGet: { path: /healthz, port: 8080 }
            initialDelaySeconds: 5
          volumeMounts:            # writable dirs needed by nginx with RO rootfs
            - { name: cache, mountPath: /var/cache/nginx }
            - { name: run,   mountPath: /tmp }
      volumes:
        - { name: cache, emptyDir: {} }
        - { name: run,   emptyDir: {} }
---
apiVersion: v1
kind: Service
metadata: { name: cmmc2 }
spec:
  selector: { app: cmmc2 }
  ports: [{ port: 8080, targetPort: 8080 }]
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: cmmc2 }
spec:
  minAvailable: 1
  selector: { matchLabels: { app: cmmc2 } }
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: cmmc2 }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: cmmc2 }
  minReplicas: 2
  maxReplicas: 6
  metrics:
    - type: Resource
      resource: { name: cpu, target: { type: Utilization, averageUtilization: 60 } }
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: cmmc2
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
spec:
  tls: [{ hosts: [cmmc.example.com], secretName: cmmc2-tls }]
  rules:
    - host: cmmc.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend: { service: { name: cmmc2, port: { number: 8080 } } }
```

| Setting | Example | Purpose |
|---|---|---|
| Entry | `https://cmmc.example.com/cmmc2/` | The app |
| Probes | `/healthz` | From `nginx.conf`; readiness/liveness |
| `readOnlyRootFilesystem` | `true` | Hardening; needs `emptyDir` for cache/tmp |
| CSP/headers | baked in image (`nginx.conf`) | Or set on the Ingress controller |

> **Alternative (ConfigMap-mounted assets):** mount `index.html`/`branding.js`/parent assets
> from a ConfigMap into a stock `nginxinc/nginx-unprivileged` pod. Works, but ConfigMaps are
> ~1 MiB and `index.html` alone is ~168 KiB — baking into the image is simpler and versioned.
> **Alternative (object-store):** skip k8s and serve from S3/Blob (see AWS/Azure guides).

## 7. Verification

No login/DB/upload/object write — verify the static site + client behavior:

```bash
kubectl apply -f k8s/cmmc2.yaml
kubectl rollout status deploy/cmmc2
kubectl get pods -l app=cmmc2

# In-cluster health
kubectl run curl --rm -it --image=curlimages/curl --restart=Never -- \
  curl -sI http://cmmc2:8080/healthz | head -n1        # 200

# Through the Ingress
curl -sI https://cmmc.example.com/cmmc2/ | head -n1     # HTTP/2 200
curl -sI https://cmmc.example.com/cmmc2/ | grep -i content-security-policy
```

Browser checks: no CSP console violations; branding applies; theme persists; marking a
control updates SPRS; `.xlsx` export downloads.

## 8. Day-2 operations

| Task | Command |
|---|---|
| Deploy new version | `kubectl set image deploy/cmmc2 nginx=registry.example.com/cmmc2:<tag>` |
| Rollback | `kubectl rollout undo deploy/cmmc2` |
| Scale | HPA auto; or `kubectl scale deploy/cmmc2 --replicas=N` |
| Logs | `kubectl logs -l app=cmmc2` (nginx access/error) |
| Cert rotation | cert-manager auto-renews `cmmc2-tls` |
| Config change (CSP/headers) | Rebuild image (`nginx.conf`) or edit Ingress annotations; redeploy |
| Backups | None needed — image is rebuildable from git |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pod CrashLoopBackOff | RO rootfs without writable `/var/cache/nginx`,`/tmp` | Add the `emptyDir` mounts (shown) |
| 403/permission on start | Running as root in unprivileged image | Keep `runAsUser: 101`, port 8080 |
| 404 on `/cmmc2/` | Image built without repo-root context | Build with `-f cmmc2/Dockerfile .` from repo root |
| Unstyled page | Parent assets not baked | Confirm Dockerfile COPYs `theme.css`/`*.js` |
| Probe failures | Wrong path/port | Probe `/healthz` on `8080` |
| CSP missing at edge | Ingress strips headers | Bake CSP in image or set via Ingress config snippet |

See also: [`AWS.md`](AWS.md) · [`AZURE.md`](AZURE.md) · [`AIRGAPPED.md`](AIRGAPPED.md).
