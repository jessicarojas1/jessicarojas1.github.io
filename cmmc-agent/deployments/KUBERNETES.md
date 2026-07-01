# Kubernetes — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent** on
Kubernetes. The app is a Flask web GUI plus a Claude-powered agentic CLI
covering all **110 NIST 800-171 practices** for CMMC Level 2.

This guide provides inline manifests: Deployment, Service, Ingress (TLS), probes
on `/api/dashboard`, resource requests/limits, a non-root securityContext, a
Secret for `ANTHROPIC_API_KEY` (with the preferred external-secret approach), an
HPA, a PDB, and a PVC for the local JSON state.

Sibling guides: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
[SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AZURE.md](AZURE.md) ·
[AWS.md](AWS.md) · [AIRGAPPED.md](AIRGAPPED.md).
Platform guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

---

## 1. Deployment architecture

The container image is built from `cmmc-agent/Dockerfile` (multi-stage
`python:3.11.9-slim`, non-root `appuser` uid **10001**, `EXPOSE 5050`,
`HEALTHCHECK` on `/api/dashboard`, `CMD ["python","server.py"]`). It runs one
synchronous Flask process per Pod.

Critical constraint: **all state is two local JSON files** (`status.json`,
`settings.json`) in the app directory `/app`. There is **no database, no object
store, no background worker, no queue, no auth**. Because state is a local
single-writer file, horizontal scaling is not free:

- **Recommended default: 1 replica** with a PVC mounted at `/app` so state
  survives Pod restarts/reschedules.
- If you need more than one replica, you **must** use an `RWX` (ReadWriteMany)
  volume shared by all Pods (e.g. EFS/Azure Files/NFS). Note the app assumes a
  single writer; concurrent writers to the same JSON files can race. Prefer 1
  replica unless you have accepted this trade-off.

The app calls the hosted **Anthropic API** (`api.anthropic.com`, model
`claude-opus-4-5`, hardcoded) — the cluster needs egress HTTPS to it. (An
on-prem LLM via Ollama would require a code change, not just config; see
[AIRGAPPED.md](AIRGAPPED.md).)

---

## 2. Topology

```
                 Internet / users
                        │ HTTPS
                        ▼
              ┌───────────────────┐
              │  Ingress (TLS)    │  cmmc.example.com
              └─────────┬─────────┘
                        │
              ┌───────────────────┐
              │  Service ClusterIP│  :80 → targetPort 5050
              └─────────┬─────────┘
                        │
        ┌───────────────────────────────┐
        │  Deployment  (Pod, uid 10001)  │──HTTPS──▶ api.anthropic.com
        │   Flask server.py :5050        │           (claude-opus-4-5)
        │   probes → /api/dashboard      │
        │   env ANTHROPIC_API_KEY ◀── Secret (ExternalSecrets/CSI preferred)
        │   /app ◀── PVC (status.json / settings.json)
        └───────────────────────────────┘
              HPA ──scales──▶ Deployment       PDB ──guards──▶ Pods
```

---

## 3. Prerequisites

| Requirement          | Detail                                                                 |
|----------------------|------------------------------------------------------------------------|
| Kubernetes cluster   | 1.25+ with an Ingress controller (nginx/ALB/AGIC) and cert-manager or managed TLS |
| Container registry   | To host the image built from `cmmc-agent/Dockerfile`                   |
| Metrics server       | For HPA (CPU/memory-based autoscaling)                                 |
| StorageClass         | RWO for single replica; **RWX** (EFS/Azure Files/NFS) if replicas > 1  |
| Secret backend (opt) | AWS Secrets Manager / Azure Key Vault + External Secrets Operator or Secrets Store CSI driver |
| Anthropic key        | `sk-ant-...` with quota for Opus-class calls                          |
| Egress               | Outbound HTTPS to `api.anthropic.com`                                  |

Build & push:

```bash
cd cmmc-agent
docker build -t <registry>/cmmc-agent:1.0.0 .
docker push <registry>/cmmc-agent:1.0.0
```

---

## 4. Identity & credentials

The one secret to protect is `ANTHROPIC_API_KEY`. **Prefer workload identity +
an external secret store over a raw Kubernetes Secret:**

- **AWS (EKS)**: bind the Deployment's ServiceAccount to an IAM role via **IRSA**;
  grant it `secretsmanager:GetSecretValue` on exactly the one secret ARN. Sync it
  into the Pod with the **External Secrets Operator** or the **Secrets Store CSI
  driver**.
- **Azure (AKS)**: use **workload identity** to a managed identity with `get`
  on the specific Key Vault secret; project it with the Secrets Store CSI driver
  (Azure provider) or External Secrets.

Least-privilege AWS policy for the IRSA role (Commercial partition — for GovCloud
use `arn:aws-us-gov:...`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["secretsmanager:GetSecretValue"],
      "Resource": "arn:aws:secretsmanager:us-east-1:123456789012:secret:cmmc/anthropic-api-key-*"
    }
  ]
}
```

The plain `Secret` shown in §6 is the documented **fallback** when no external
secret store is available. The app runs as **non-root uid 10001**
(`runAsNonRoot`), so grant least privilege at the Pod level too.

---

## 5. Environment variables & secret injection

| Variable            | Example            | Purpose / injection                                                        |
|---------------------|--------------------|----------------------------------------------------------------------------|
| `ANTHROPIC_API_KEY` | `sk-ant-abc123...` | **Required** for chat. Inject from a Secret via `secretKeyRef`, ideally one materialized by External Secrets / CSI from AWS Secrets Manager or Azure Key Vault. Missing → `/api/chat` 500 `{"error":"ANTHROPIC_API_KEY not set"}`. |
| `PORT`              | `5050`             | Container port Flask binds; matches `containerPort` and the probe port.    |

**ExternalSecrets example** (materializes the k8s Secret from a store):

```yaml
apiVersion: external-secrets.io/v1beta1
kind: ExternalSecret
metadata:
  name: cmmc-anthropic
  namespace: cmmc
spec:
  refreshInterval: 1h
  secretStoreRef:
    name: aws-secrets-manager        # or azure-key-vault
    kind: SecretStore
  target:
    name: cmmc-agent-secrets         # the k8s Secret the Deployment reads
    creationPolicy: Owner
  data:
    - secretKey: ANTHROPIC_API_KEY
      remoteRef:
        key: cmmc/anthropic-api-key
```

**Secrets Store CSI driver** alternative: mount a `SecretProviderClass` volume
that pulls the same secret and (optionally) syncs it to a k8s Secret via
`secretObjects`, then reference it with `secretKeyRef` as below.

---

## 6. Configuration references & manifests

| Variable            | Example           | Purpose                                                        |
|---------------------|-------------------|----------------------------------------------------------------|
| `PORT`              | `5050`            | Bind/target/probe port. `host` fixed to `0.0.0.0` in `server.py`. |
| `ANTHROPIC_API_KEY` | `sk-ant-...`      | Anthropic credential for the agentic tool loop.                |
| Model (in code)     | `claude-opus-4-5` | Hardcoded; provider/model change needs a code edit.            |

### Namespace + fallback Secret

```yaml
apiVersion: v1
kind: Namespace
metadata:
  name: cmmc
---
# Fallback ONLY if no External Secrets / CSI is available. Prefer §5 approaches.
apiVersion: v1
kind: Secret
metadata:
  name: cmmc-agent-secrets
  namespace: cmmc
type: Opaque
stringData:
  ANTHROPIC_API_KEY: "sk-ant-REPLACE_ME"
```

### PVC for local JSON state

```yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: cmmc-state
  namespace: cmmc
spec:
  accessModes: ["ReadWriteOnce"]     # use ReadWriteMany (EFS/Azure Files/NFS) only if replicas > 1
  resources:
    requests:
      storage: 1Gi
  # storageClassName: gp3            # set to your cluster's class
```

### Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: cmmc-agent
  namespace: cmmc
  labels: { app: cmmc-agent }
spec:
  replicas: 1                        # local JSON state ⇒ default single replica
  selector:
    matchLabels: { app: cmmc-agent }
  strategy:
    type: Recreate                   # single-writer state: avoid two Pods on the same RWO volume
  template:
    metadata:
      labels: { app: cmmc-agent }
    spec:
      serviceAccountName: cmmc-agent   # bind to IRSA / workload identity in §4
      securityContext:
        runAsNonRoot: true
        runAsUser: 10001
        runAsGroup: 10001
        fsGroup: 10001                 # lets the non-root user write the PVC
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: cmmc-agent
          image: <registry>/cmmc-agent:1.0.0
          imagePullPolicy: IfNotPresent
          ports:
            - containerPort: 5050
          env:
            - name: PORT
              value: "5050"
            - name: ANTHROPIC_API_KEY
              valueFrom:
                secretKeyRef:
                  name: cmmc-agent-secrets
                  key: ANTHROPIC_API_KEY
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: false   # app writes JSON under /app (the PVC)
            capabilities: { drop: ["ALL"] }
          resources:
            requests: { cpu: "100m", memory: "128Mi" }
            limits:   { cpu: "500m", memory: "512Mi" }
          # /api/dashboard is served from status.json and does NOT need the API key ⇒ ideal probe.
          readinessProbe:
            httpGet: { path: /api/dashboard, port: 5050 }
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            httpGet: { path: /api/dashboard, port: 5050 }
            initialDelaySeconds: 15
            periodSeconds: 20
          volumeMounts:
            - name: state
              mountPath: /app/data     # see note below
      volumes:
        - name: state
          persistentVolumeClaim:
            claimName: cmmc-state
```

> **State path note:** the app writes `status.json` / `settings.json` next to
> `server.py` in `/app`. Mounting the PVC directly at `/app` would shadow the
> image's code, so mount it at a subpath like `/app/data` and, if you want state
> on the PVC, either (a) run the process with `WorkingDirectory`/`cwd` at
> `/app/data`, or (b) symlink the two files into the PVC at startup. The simplest
> correct option is a single replica with an emptyDir/ephemeral acceptance of
> restart loss **plus** regular backups (see §8). Choose the model that matches
> your durability requirement; do not mount the PVC over `/app` and lose the code.

### Service

```yaml
apiVersion: v1
kind: Service
metadata:
  name: cmmc-agent
  namespace: cmmc
spec:
  type: ClusterIP
  selector: { app: cmmc-agent }
  ports:
    - name: http
      port: 80
      targetPort: 5050
```

### Ingress (TLS)

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: cmmc-agent
  namespace: cmmc
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
    # LLM round-trips can be slow; raise proxy timeouts for /api/chat:
    nginx.ingress.kubernetes.io/proxy-read-timeout: "300"
spec:
  ingressClassName: nginx
  tls:
    - hosts: ["cmmc.example.com"]
      secretName: cmmc-agent-tls
  rules:
    - host: cmmc.example.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service:
                name: cmmc-agent
                port: { number: 80 }
```

### HPA

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: cmmc-agent
  namespace: cmmc
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: cmmc-agent
  minReplicas: 1
  maxReplicas: 3                     # only meaningful with an RWX shared volume (see §1)
  metrics:
    - type: Resource
      resource:
        name: cpu
        target: { type: Utilization, averageUtilization: 70 }
```

> The HPA is included for completeness. Because state is single-writer local
> JSON, only enable `maxReplicas > 1` when backed by an RWX volume and you accept
> concurrent-write semantics. Otherwise keep `min = max = 1`.

### PDB

```yaml
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: cmmc-agent
  namespace: cmmc
spec:
  minAvailable: 1
  selector:
    matchLabels: { app: cmmc-agent }
```

---

## 7. Verification

```bash
kubectl -n cmmc rollout status deploy/cmmc-agent
kubectl -n cmmc get pods -l app=cmmc-agent

# Port-forward and hit the liveness endpoint (no API key required):
kubectl -n cmmc port-forward svc/cmmc-agent 8080:80 &
curl http://127.0.0.1:8080/api/dashboard
# expect JSON: {"overall_score_pct": <N>, "domains": {...}}

# Chat — confirm the Secret is injected and the key resolves:
curl -X POST http://127.0.0.1:8080/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"history":[{"role":"user","content":"score my program"}]}'
# expect {"reply":"...","tool_log":[...]}
# 500 {"error":"ANTHROPIC_API_KEY not set"} => Secret not wired / ExternalSecret not synced.

# Confirm state persists — mark a control, re-check the score:
curl -X POST http://127.0.0.1:8080/api/mark \
  -H 'Content-Type: application/json' \
  -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"k8s verify"}'
curl http://127.0.0.1:8080/api/dashboard      # score reflects the mark

# Through the Ingress over TLS:
curl https://cmmc.example.com/api/dashboard
```

Confirm the injected secret exists (without printing the value in logs):

```bash
kubectl -n cmmc get secret cmmc-agent-secrets -o jsonpath='{.data.ANTHROPIC_API_KEY}' | base64 -d | head -c 7
# prints "sk-ant-" if resolved
```

There is **no database or object store to verify** — persistence is only the two
JSON files on the volume.

---

## 8. Day-2 operations

- **Upgrades**: build a new image tag, `kubectl -n cmmc set image
  deploy/cmmc-agent cmmc-agent=<registry>/cmmc-agent:<newtag>`, watch
  `rollout status`. With `Recreate` strategy and a single RWO volume the old Pod
  terminates before the new one attaches.
- **Scaling**: local JSON state ⇒ default single replica. Only scale out with an
  RWX volume and accepted single-writer trade-offs (see §1/§6). Vertical scaling
  (raise CPU/memory limits) is safe.
- **Backups**: the entire state is `status.json` + `settings.json`. Back them up
  from the running Pod or the PVC:
  ```bash
  kubectl -n cmmc exec deploy/cmmc-agent -- sh -c 'cat /app/data/status.json'   > status.$(date +%F).json
  kubectl -n cmmc exec deploy/cmmc-agent -- sh -c 'cat /app/data/settings.json' > settings.$(date +%F).json
  ```
  (Or snapshot the PVC / underlying EFS/Azure Files share on a schedule.)
- **Secret rotation**: rotate the value in AWS Secrets Manager / Azure Key Vault;
  External Secrets re-syncs on `refreshInterval`, then
  `kubectl -n cmmc rollout restart deploy/cmmc-agent` to pick up the new env.
  Revoke the old key in the Anthropic Console.
- **Migrations**: **none** — no database exists. Upgrades are image-only.
- **Logs**: `kubectl -n cmmc logs deploy/cmmc-agent -f` (Flask logs to stdout).

---

## 9. Troubleshooting

| Symptom                                                | Cause                                                  | Fix                                                                        |
|--------------------------------------------------------|--------------------------------------------------------|----------------------------------------------------------------------------|
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set`     | Secret not injected / ExternalSecret not synced        | Check `secretKeyRef`, ExternalSecret status, then `rollout restart`.       |
| Pod `CrashLoopBackOff` right after mount               | PVC mounted over `/app`, shadowing code                | Mount at a subpath (`/app/data`), not `/app` — see §6 state-path note.     |
| Readiness/liveness failing                             | Wrong probe port / app not up                          | Probe must target 5050 and `/api/dashboard`; check `kubectl logs`.         |
| PVC won't bind                                         | No matching StorageClass / RWX unsupported             | Set a valid `storageClassName`; RWX needs EFS/Azure Files/NFS.             |
| `Permission denied` writing state                      | Volume not writable by uid 10001                       | Set `fsGroup: 10001`; ensure the StorageClass honors fsGroup.             |
| Anthropic 401 in `tool_log`                            | Bad / revoked key in the secret                        | Update the source secret; re-sync; `rollout restart`.                      |
| `/api/dashboard` → `overall_score_pct: 0`              | Fresh `status.json`, nothing marked                    | Expected on first deploy; mark controls via UI or `POST /api/mark`.        |
| `/api/chat` times out at the Ingress                   | Proxy read timeout too low for LLM calls               | Set `nginx.ingress.kubernetes.io/proxy-read-timeout: "300"`.               |
| Two replicas corrupt/overwrite state                   | Multiple writers on shared JSON                        | Use 1 replica, or accept single-writer risk on RWX; prefer `min=max=1`.    |
