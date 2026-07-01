# ISMS Document Library — Kubernetes

Audience: platform teams serving the ISMS library from Kubernetes as an
**nginx-static** Deployment. It is a **Type A static website** — no backend, no
database, no app runtime, no secrets. The pod just serves files.

> Siblings: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AZURE.md](AZURE.md) ·
> [AWS.md](AWS.md) · [AIRGAPPED.md](AIRGAPPED.md)

## 1. Deployment architecture

Build the container from [`../Dockerfile`](../Dockerfile) (nginx-unprivileged,
non-root uid 101, listens on **8080**, serves the library at `/isms/` with the
shared `../` assets baked in). Run it as a Deployment (≥2 replicas) behind a
Service + Ingress with TLS. Security headers/CSP come from the baked
[`../nginx.conf`](../nginx.conf) (and/or ingress annotations). Pods are stateless
and can run `readOnlyRootFilesystem`. Bootstrap/devicons load from jsDelivr unless
you built a vendored image ([AIRGAPPED.md](AIRGAPPED.md)).

## 2. Topology

```
Internet ─HTTPS─► Ingress (TLS, cert-manager) ──► Service (ClusterIP :80→:8080)
                                                     │
                        ┌────────────────────────────┴───────────┐
                        ▼                                         ▼
                  Pod: isms-static                          Pod: isms-static
                  nginx-unprivileged :8080                  (replica, other node)
                  readOnlyRootFS, non-root(101)             probes: /isms/index.html
                        │  loads Bootstrap+devicons ──► jsDelivr CDN (egress)
   HPA (CPU) ─ scales replicas   PDB ─ keeps ≥1 during disruptions
```

## 3. Prerequisites

| Item | Version | Notes |
|------|---------|-------|
| Kubernetes | 1.27+ | any conformant cluster |
| kubectl | matches cluster | apply manifests |
| Ingress controller | ingress-nginx / cloud LB | HTTP routing + TLS |
| cert-manager | latest | TLS certs (or bring your own) |
| Container registry | any | push the built image |
| Docker/BuildKit | 24+ | build the image (context = repo root) |

## 4. Identity & credentials

- **Running pod:** no cloud identity, no secrets — it serves static files. Give
  the ServiceAccount **no** extra RBAC and no cloud role.
- **Deploy/build identity:** CI uses a **registry push** credential — prefer
  **OIDC/workload identity** to the registry over static tokens.
- **Optional access gate:** put oauth2-proxy / OIDC auth on the Ingress; those
  secrets belong to the proxy, not the app.

## 5. Environment variables

**None** for the app container. Cluster-level values:

| Variable / field | Example | Purpose |
|------------------|---------|---------|
| `IMAGE` | `registry.example.com/isms-library:1.0.0` | built static image |
| `replicas` | `2` | availability |
| container port | `8080` | nginx-unprivileged listen port |
| Ingress host | `isms.example.com` | external hostname |

## 6. Configuration references

```yaml
apiVersion: apps/v1
kind: Deployment
metadata: { name: isms-static, labels: { app: isms-static } }
spec:
  replicas: 2
  selector: { matchLabels: { app: isms-static } }
  template:
    metadata: { labels: { app: isms-static } }
    spec:
      automountServiceAccountToken: false
      securityContext: { runAsNonRoot: true, runAsUser: 101, seccompProfile: { type: RuntimeDefault } }
      containers:
        - name: nginx
          image: registry.example.com/isms-library:1.0.0
          ports: [{ containerPort: 8080 }]
          readinessProbe: { httpGet: { path: /isms/index.html, port: 8080 }, initialDelaySeconds: 3 }
          livenessProbe:  { httpGet: { path: /isms/index.html, port: 8080 }, periodSeconds: 15 }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
          resources: { requests: { cpu: 10m, memory: 32Mi }, limits: { cpu: 200m, memory: 128Mi } }
          volumeMounts:
            - { name: cache, mountPath: /var/cache/nginx }
            - { name: run,   mountPath: /tmp }
      volumes:
        - { name: cache, emptyDir: {} }
        - { name: run,   emptyDir: {} }
---
apiVersion: v1
kind: Service
metadata: { name: isms-static }
spec:
  selector: { app: isms-static }
  ports: [{ port: 80, targetPort: 8080 }]
---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata: { name: isms-static }
spec: { minAvailable: 1, selector: { matchLabels: { app: isms-static } } }
---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata: { name: isms-static }
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: isms-static }
  minReplicas: 2
  maxReplicas: 5
  metrics: [{ type: Resource, resource: { name: cpu, target: { type: Utilization, averageUtilization: 70 } } }]
```

Ingress adds TLS + can reassert security headers (values in
[../docs/SECURITY.md](../docs/SECURITY.md)). No ConfigMap of app config is needed
because `nginx.conf` is baked into the image; if you prefer, mount `nginx.conf`
via a ConfigMap instead.

## 7. Verification

No login/DB/upload. Verify serving, probes, headers, and client behavior:

```bash
kubectl apply -f isms-k8s.yaml
kubectl rollout status deploy/isms-static
kubectl get pods -l app=isms-static           # Running, READY 1/1

# In-cluster smoke test
kubectl run curl --rm -it --image=curlimages/curl --restart=Never -- \
  -I http://isms-static.default.svc.cluster.local/isms/index.html   # 200

# Through the ingress
curl -I https://isms.example.com/isms/index.html                    # 200
curl -sI https://isms.example.com/isms/index.html | grep -i content-security-policy
```

Browser: hub renders, search/filters work, theme + branding persist, CSP clean.

## 8. Day-2 operations

- **Release:** build a new immutable tag, `kubectl set image deploy/isms-static
  nginx=…:<tag>`, `rollout status`; rollback with `kubectl rollout undo`.
- **Scaling:** HPA on CPU (tiny workload — mostly for availability). PDB keeps ≥1
  during node drains.
- **Certs:** cert-manager auto-renews; watch `Certificate` resources.
- **Backups:** none needed — stateless; **git is the source of truth**
  ([../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **Image hygiene:** rebuild on base-image CVEs; scan (Trivy/Grype) in CI;
  `imagePullPolicy: IfNotPresent` with immutable tags.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Pod `CrashLoopBackOff` | Tried to bind :80 as non-root | Use the provided image (listens :8080) + `containerPort: 8080` |
| `Permission denied` writing cache | `readOnlyRootFilesystem` without writable mounts | Mount `emptyDir` at `/var/cache/nginx` and `/tmp` (shown above) |
| 404 on `/theme.css` | Shared assets not in image | Build with context = repo root per [`../Dockerfile`](../Dockerfile) |
| Unstyled pages | Egress to jsDelivr blocked (NetworkPolicy) | Allow CDN egress or ship a vendored image ([AIRGAPPED.md](AIRGAPPED.md)) |
| Probes failing | Wrong path/port | Probe `GET /isms/index.html` on `8080` |
| Missing headers | Header only in image, stripped by ingress | Reassert headers via ingress annotations |
