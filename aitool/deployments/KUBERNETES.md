# Kubernetes — AI Tool Evaluation Framework (`aitool`)

**Applicability:** fully applicable. Serve the static files from an `nginx` Deployment
(built from the repo `Dockerfile`) behind an Ingress, with HPA/PDB and a read-only root
filesystem. No database, no worker, no server secrets.

## 1. Deployment architecture

A Deployment of `nginx-unprivileged` pods serves the baked-in static content (image
built from [`../Dockerfile`](../Dockerfile)). A Service fronts the pods; an Ingress
terminates TLS. Content is immutable in the image — updates ship as new image tags.
Optionally serve from an object-store bucket instead (see AWS/AZURE guides).

## 2. Topology

```
        Internet
           │ HTTPS
           ▼
   ┌──────────────┐    ┌───────────────────────────────┐
   │   Ingress    │──► │ Service (ClusterIP) ─► Pods (N)│
   │ (TLS, headers)│    │  nginx-unprivileged :8080      │
   └──────────────┘    │  baked static content          │
                       │  readOnlyRootFilesystem: true  │
                       └───────────────────────────────┘
   HPA scales pods on CPU;  PDB keeps ≥1 during disruption
   Client browser ──HTTPS──► cdn.jsdelivr.net (Bootstrap, SRI)
```

## 3. Prerequisites

| Item | Note |
|------|------|
| Kubernetes | 1.27+ |
| Ingress controller | nginx-ingress / cloud LB |
| cert-manager (or managed cert) | TLS |
| Container registry | to host the built image |
| CI with OIDC to the registry/cluster | image build + apply |

## 4. Identity & credentials

- **Workload:** no app secrets; the pod serves static files only. Run with a dedicated,
  minimal ServiceAccount (no cluster permissions needed).
- **Build/deploy:** CI authenticates to the registry and cluster via **OIDC / workload
  identity** (IRSA on EKS, Workload Identity on GKE/AKS) — no static registry keys.
- **Image pull:** use a registry that the cluster's node identity can pull, or an
  `imagePullSecret` sourced from a secret manager (not committed).

## 5. Environment variables

**None consumed by the site.** nginx listens on `8080` (from the base image). No app
config via env.

| Variable | Example | Purpose |
|----------|---------|---------|
| (none for the app) | — | Static content only |
| `TZ` (optional) | `UTC` | Log timestamps |

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Container port | `8080` | nginx-unprivileged default |
| Image | `<registry>/aitool:<tag>` | Built from `../Dockerfile` |
| Replicas | `2`+ | Availability |
| Ingress host | `aitool.example.com` | Public URL |
| Security headers / CSP | Ingress annotations or baked `nginx.conf` | Hardening |

Example manifests:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata: { name: aitool, labels: { app: aitool } }
spec:
  replicas: 2
  selector: { matchLabels: { app: aitool } }
  template:
    metadata: { labels: { app: aitool } }
    spec:
      automountServiceAccountToken: false
      securityContext: { runAsNonRoot: true, seccompProfile: { type: RuntimeDefault } }
      containers:
        - name: aitool
          image: <registry>/aitool:1.0.0
          ports: [{ containerPort: 8080 }]
          readinessProbe: { httpGet: { path: /index.html, port: 8080 }, initialDelaySeconds: 5 }
          livenessProbe:  { httpGet: { path: /index.html, port: 8080 }, periodSeconds: 15 }
          resources: { requests: { cpu: 10m, memory: 32Mi }, limits: { cpu: 250m, memory: 128Mi } }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true          # nginx-unprivileged writes only to /tmp
            capabilities: { drop: [ALL] }
          volumeMounts:
            - { name: tmp, mountPath: /tmp }
            - { name: cache, mountPath: /var/cache/nginx }
            - { name: run, mountPath: /var/run }
      volumes:
        - { name: tmp, emptyDir: {} }
        - { name: cache, emptyDir: {} }
        - { name: run, emptyDir: {} }
---
apiVersion: v1
kind: Service
metadata: { name: aitool }
spec: { selector: { app: aitool }, ports: [{ port: 80, targetPort: 8080 }] }
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: aitool }
spec: { minAvailable: 1, selector: { matchLabels: { app: aitool } } }
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: aitool }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: aitool }
  minReplicas: 2
  maxReplicas: 6
  metrics: [{ type: Resource, resource: { name: cpu, target: { type: Utilization, averageUtilization: 70 } } }]
```

Ingress (nginx) — add security headers + TLS:

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: aitool
  annotations:
    nginx.ingress.kubernetes.io/configuration-snippet: |
      more_set_headers "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; frame-ancestors 'none'; base-uri 'self'";
      more_set_headers "X-Content-Type-Options: nosniff";
      more_set_headers "Strict-Transport-Security: max-age=63072000; includeSubDomains";
spec:
  tls: [{ hosts: [aitool.example.com], secretName: aitool-tls }]
  rules:
    - host: aitool.example.com
      http: { paths: [{ path: /, pathType: Prefix, backend: { service: { name: aitool, port: { number: 80 } } } }] }
```

> **Note on `../` assets:** the image built from `../Dockerfile` contains only `aitool/`.
> To serve the shared parent assets, either build an image from the repo root or vendor
> the shared files into `aitool/` before building. See the `Dockerfile` header note.

## 7. Verification

No login/DB/upload. Verify pods + serving + client behavior:

```bash
kubectl get pods -l app=aitool                       # Running, Ready
kubectl port-forward deploy/aitool 8080:8080 &
curl -I http://localhost:8080/index.html             # 200
curl -I https://aitool.example.com/index.html        # 200 via Ingress
curl -sI https://aitool.example.com/index.html | grep -i content-security-policy
```
Browser via the Ingress host: styled page, theme toggle + branding persist, tracker
export works, no CSP/SRI console errors.

## 8. Day-2 operations

- **Update content:** build a new image tag, `kubectl set image deploy/aitool
  aitool=<registry>/aitool:<newtag>` (or GitOps); rollout is zero-downtime with 2+
  replicas + PDB.
- **Rollback:** `kubectl rollout undo deploy/aitool`.
- **Scale:** HPA on CPU; adjust min/max. Static serving is cheap — small requests.
- **TLS:** cert-manager auto-renews the `aitool-tls` secret.
- **Backups:** none needed for the workload (content is in git/registry); back up the
  registry + manifests. See `../docs/DISASTER_RECOVERY.md`.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod CrashLoop | readOnlyRootFS without tmp/cache/run volumes | Mount `emptyDir` for `/tmp`, `/var/cache/nginx`, `/var/run` |
| 404 on `../theme.css` | Image has only `aitool/` | Build from repo root or vendor shared assets |
| 403/permission on port | Ran as root or wrong port | Use nginx-unprivileged (8080), `runAsNonRoot` |
| No CSP header | Missing Ingress annotation | Add the `configuration-snippet` above |
| ImagePullBackOff | Registry auth | Fix workload identity / imagePullSecret |
| Readiness failing | Probe path wrong | Probe `/index.html` on 8080 |
