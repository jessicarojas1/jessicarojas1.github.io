# Kubernetes — CMMI v2.0 Practice Reference

Run the static site as an nginx-static `Deployment` behind an `Ingress`, built
from the repo-root Docker image so the parent `../` assets are baked in.

## 1. Deployment architecture

The `cmmi/Dockerfile` produces an `nginx-unprivileged` image that already copies
`cmmi/index.html`, `cmmi/branding.js`, **and** the parent assets
(`cmmidev3.js`, `theme.css`, `favicon.ico`, `users.js`, `roles.js`, `script.js`,
`analytics.js`, `siteSearch.js`) into the correct URL layout (`/cmmi/` + root
assets). Kubernetes runs replicas of that image behind a `Service` + `Ingress`
(TLS). No PVC, no database, no secrets — the pod is read-only and stateless.
Bootstrap/Icons/SheetJS load from `cdn.jsdelivr.net` unless you vendor them.

## 2. Topology

```
            Internet
               │ HTTPS
        ┌──────▼───────┐
        │   Ingress    │  TLS (cert-manager)
        └──────┬───────┘
        ┌──────▼───────┐   Service (ClusterIP :80 → :8080)
        │   Service    │
        └──────┬───────┘
     ┌─────────┼─────────┐
 ┌───▼───┐ ┌───▼───┐ ┌───▼───┐   Deployment (nginx-unprivileged, UID 101)
 │ pod 1 │ │ pod 2 │ │ pod 3 │   readOnlyRootFilesystem, /healthz probe
 └───────┘ └───────┘ └───────┘   image bakes cmmi/ + ../ parent assets
Browser ──▶ cdn.jsdelivr.net (Bootstrap 5.3.3 / Icons 1.11.3 / SheetJS)
```

## 3. Prerequisites

| Item | Version / note |
|------|----------------|
| Kubernetes | ≥ 1.25 |
| Ingress controller | nginx-ingress / any |
| cert-manager | for TLS (or pre-provisioned certs) |
| Container registry | to host the built image |
| Docker/BuildKit | build the image (context = repo ROOT) |

## 4. Identity & credentials

No application identity. The relevant identity is the **CI/deploy pipeline**
pushing the image and applying manifests — use a workload identity / OIDC-based
registry push, not long-lived registry passwords. The pod itself needs no
ServiceAccount permissions beyond default (it makes no API calls); bind it to a
minimal SA with `automountServiceAccountToken: false`.

## 5. Environment variables

The app uses none; nginx needs none. Manifest "variables" are image ref and host:

| Variable | Example | Purpose |
|----------|---------|---------|
| `IMAGE` | `registry.example.com/cmmi-ref:1.0.0` | Built from repo root |
| `HOST` | `cmmi.example.com` | Ingress host / TLS SAN |

## 6. Configuration references

Build & push (context is the **repo root**):

```bash
docker build -f cmmi/Dockerfile -t registry.example.com/cmmi-ref:1.0.0 .
docker push registry.example.com/cmmi-ref:1.0.0
```

Manifests:

```yaml
apiVersion: apps/v1
kind: Deployment
metadata: { name: cmmi-ref, labels: { app: cmmi-ref } }
spec:
  replicas: 3
  selector: { matchLabels: { app: cmmi-ref } }
  template:
    metadata: { labels: { app: cmmi-ref } }
    spec:
      automountServiceAccountToken: false
      securityContext: { runAsNonRoot: true, runAsUser: 101, seccompProfile: { type: RuntimeDefault } }
      containers:
        - name: nginx
          image: registry.example.com/cmmi-ref:1.0.0
          ports: [{ containerPort: 8080 }]
          readinessProbe: { httpGet: { path: /healthz, port: 8080 }, initialDelaySeconds: 3 }
          livenessProbe:  { httpGet: { path: /healthz, port: 8080 }, initialDelaySeconds: 10 }
          resources: { requests: { cpu: 10m, memory: 32Mi }, limits: { cpu: 100m, memory: 64Mi } }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
          volumeMounts:
            - { name: cache, mountPath: /var/cache/nginx }
            - { name: run,   mountPath: /tmp }
      volumes: [ { name: cache, emptyDir: {} }, { name: run, emptyDir: {} } ]
---
apiVersion: v1
kind: Service
metadata: { name: cmmi-ref }
spec: { selector: { app: cmmi-ref }, ports: [{ port: 80, targetPort: 8080 }] }
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: cmmi-ref }
spec: { minAvailable: 1, selector: { matchLabels: { app: cmmi-ref } } }
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: cmmi-ref }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: cmmi-ref }
  minReplicas: 2
  maxReplicas: 6
  metrics: [{ type: Resource, resource: { name: cpu, target: { type: Utilization, averageUtilization: 70 } } }]
---
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: cmmi-ref
  annotations: { cert-manager.io/cluster-issuer: letsencrypt }
spec:
  tls: [{ hosts: [cmmi.example.com], secretName: cmmi-tls }]
  rules:
    - host: cmmi.example.com
      http: { paths: [{ path: /, pathType: Prefix, backend: { service: { name: cmmi-ref, port: { number: 80 } } } }] }
```

> Alternative: skip the image and serve the repo root from an object-store bucket
> (see [AWS.md](AWS.md) / [AZURE.md](AZURE.md)) with the cluster only providing the
> Ingress/CDN.

## 7. Verification

```bash
kubectl rollout status deploy/cmmi-ref
kubectl get pods -l app=cmmi-ref

# in-cluster health + app
kubectl port-forward deploy/cmmi-ref 8080:8080 &
curl -I http://localhost:8080/healthz          # → 200 ok
curl -I http://localhost:8080/cmmi/            # → 200
curl -I http://localhost:8080/cmmidev3.js      # → 200 (baked into image)

# via ingress
curl -I https://cmmi.example.com/cmmi/         # → 200
```

Browser checks (no login/DB/upload exist): CSP clean, practices render/filter/
search, status persists (`cmmi2_*`), theme persists (`bsTheme`), branding
applies, `.xlsx` export downloads, print renders.

## 8. Day-2 operations

- **Upgrade:** rebuild the image on any content change (rebuild picks up
  `cmmidev3.js`), push a new tag, `kubectl set image deploy/cmmi-ref …`, watch
  the rollout; roll back with `kubectl rollout undo`.
- **Scaling:** HPA on CPU (static serving is cheap — small requests/limits).
- **PDB** keeps ≥1 pod during node drains.
- **No migrations, no backups of state** (there is none). Back up manifests in
  Git.
- **Logs:** `kubectl logs -l app=cmmi-ref` (nginx access/error).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `/cmmi/` 200 but blank | `cmmidev3.js` not in image | Rebuild with context = **repo root** (Dockerfile copies it) |
| Pod CrashLoopBackOff | writable-fs assumption vs `readOnlyRootFilesystem` | Ensure `/var/cache/nginx` + `/tmp` emptyDir mounts exist |
| Probe failures | wrong port | Probe `/healthz` on `8080` (unprivileged nginx) |
| 404 on assets | served from wrong path | Image serves `/cmmi/` + root assets — hit `/cmmi/` |
| Unstyled page | CDN egress blocked by NetworkPolicy | Allow `cdn.jsdelivr.net` egress or vendor assets ([AIRGAPPED.md](AIRGAPPED.md)) |
| ImagePullBackOff | registry auth | Fix imagePullSecret / registry identity |
