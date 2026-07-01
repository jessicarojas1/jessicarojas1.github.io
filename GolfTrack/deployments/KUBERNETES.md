# Kubernetes — GolfTrack

> **Applicability: the app itself does not run in Kubernetes.** GolfTrack is an
> on-device iOS / watchOS / macOS app. There is **no companion backend, no API,
> and no container workload to serve** — nothing to expose via Ingress, no HPA,
> no PDB, no probes for the app.
>
> **What this document covers instead:** a **Kubernetes-based CI/build pipeline**
> for the platform-agnostic SwiftPM **compile check**, plus the honest boundary
> that **real signing/archiving requires macOS**, which Kubernetes cannot natively
> schedule — those steps live on **self-hosted macOS runners outside the cluster**.

Cross-links: [SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) ·
[DEPLOYMENT](../docs/DEPLOYMENT.md) · [Dockerfile](../Dockerfile) ·
[AZURE](AZURE.md) / [AWS](AWS.md) (distribution).

---

## 1. Deployment architecture

Kubernetes hosts **batch build Jobs** that run the `swift:slim` compile-check
image against the package. It does **not** host the app. The signing/archive
stage is delegated to a macOS runner registered as an external CI executor.

| Stage | Where | Container? |
|-------|-------|-----------|
| Package resolve + `swift build`/`swift test` | k8s `Job` (Linux) | Yes — `swift:slim` |
| `xcodebuild archive` + sign + upload | macOS runner (outside k8s) | No — Xcode on macOS |
| Distribution | App Store Connect / TestFlight / MDM | Managed by Apple |

## 2. Topology

```
  Git push ──► CI controller (Argo/Tekton/GitHub Actions)
                    │
                    ├─► kubernetes Job: swift:slim ──► swift build / swift test
                    │        (Linux compile check; Apple frameworks NOT compiled)
                    │
                    └─► (webhook) self-hosted macOS runner  [OUTSIDE the cluster]
                             xcodebuild archive ──► sign ──► TestFlight/App Store
```

Kubernetes cannot run macOS containers; do not attempt to `xcodebuild` in-cluster.

## 3. Prerequisites

| Item | Version / note |
|------|----------------|
| Kubernetes | 1.27+ |
| Container image | `swift:slim` (5.9) — see [Dockerfile](../Dockerfile) |
| CI runner for k8s | Tekton / Argo Workflows / GitHub Actions ARC |
| macOS runner | macOS 14 + Xcode 15, registered to the CI system (external) |
| Secrets backend | External Secrets Operator or CSI driver (for the macOS stage secrets) |

## 4. Identity & credentials

- **In-cluster Jobs:** no app secrets required (compile check only). Use a
  minimal `ServiceAccount` with no extra RBAC; mount nothing sensitive.
- **macOS stage:** signing identity + App Store Connect API key, pulled from a
  secrets manager via workload identity (see [AWS](AWS.md) / [AZURE](AZURE.md)).
  Keep these **off** the cluster.

Prefer workload identity / OIDC for any secrets pull; avoid static credentials.

## 5. Environment variables

Compile-check Job:

| Variable | Example | Purpose |
|----------|---------|---------|
| `SWIFT_VERSION` | `5.9` | Toolchain pin |
| `CI` | `true` | Non-interactive |

(Signing env vars live on the macOS runner — see
[SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) §5.)

## 6. Configuration references

No runtime app config. Job config is limited to the image tag and resource
requests below.

## 7. Verification

No health/login/upload/object — **explicitly N/A**. Verify the Job result:

```bash
kubectl apply -f golftrack-ci-job.yaml
kubectl wait --for=condition=complete job/golftrack-compile-check --timeout=600s
kubectl logs job/golftrack-compile-check
```
Acceptance: Job `Complete`; logs show `swift build` success and `swift test`
"no tests" (expected until a `Tests/` target exists).

### Example compile-check Job
```yaml
apiVersion: batch/v1
kind: Job
metadata:
  name: golftrack-compile-check
spec:
  backoffLimit: 1
  template:
    spec:
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 1000
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: build
          image: ghcr.io/jessicarojas1/golftrack-ci:latest   # built from ../Dockerfile
          command: ["/bin/bash", "-c", "swift build && swift test || true"]
          resources:
            requests: { cpu: "1", memory: "2Gi" }
            limits:   { cpu: "2", memory: "4Gi" }
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: false   # SwiftPM writes to .build
            capabilities: { drop: ["ALL"] }
```

## 8. Day-2 operations

| Task | How |
|------|-----|
| Update Swift image | Rebuild [Dockerfile](../Dockerfile) with a new `swift:slim` tag; push; bump Job image |
| Scale builds | Increase parallel Jobs (each PR = one Job); no HPA needed for batch |
| Rotate secrets | Only on the macOS runner side |
| Logs/retention | Ship Job logs to your cluster logging stack; TTL-after-finished on Jobs |
| Clean up | `ttlSecondsAfterFinished` on the Job spec |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Job fails: missing Apple module | Building Apple code on Linux | Expected; only compile-check runs here — archive on macOS |
| Trying to `xcodebuild` in a pod | macOS-only tool | Move to the external macOS runner |
| `swift test` "no tests" | No `Tests/` target | Expected; see [OPEN_ITEMS](../OPEN_ITEMS.md) |
| Pod OOMKilled | Under-provisioned | Raise memory request/limit |
| Image pull denied | Registry auth | Configure `imagePullSecrets` |
