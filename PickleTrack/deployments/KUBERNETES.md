# PickleTrack — Kubernetes

> **Applicability: N/A for the app itself.** PickleTrack is an on-device iOS/macOS app;
> it does **not** run in Kubernetes and there is **no companion backend service** to host
> (all data is on-device SwiftData; the only network call is Apple's MapKit `MKLocalSearch`).
> This document covers the **nearest real thing**: a **Kubernetes-based CI/build pipeline**
> for the Swift package. Note up front: real signing/archiving/TestFlight steps require
> **macOS runners, which cannot run inside standard Linux Kubernetes** — those stages run
> on macOS hosts *outside* the cluster.

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

| Stage | Where it runs | Container? |
|-------|---------------|-----------|
| **Compile check** (`swift build`/`swift test`) | Kubernetes Linux `Job` using `swift:slim` | Yes |
| **App build / test / archive / sign** | External **macOS runner** pool | No (bare macOS, not k8s) |
| **Distribute** (TestFlight / MDM) | From the macOS runner | No |

Kubernetes hosts only the platform-agnostic Swift compile gate. Because the app depends
on Apple frameworks unavailable on Linux, no runnable app artifact is produced in-cluster.

---

## 2. Topology

```
   Git push / PR
        │
        ▼
 ┌─────────────────────────── Kubernetes cluster ───────────────────────────┐
 │  CI controller (Argo Workflows / Tekton / GitHub ARC)                     │
 │        │                                                                  │
 │        ▼                                                                  │
 │   Job: swift-compile-check   image: swiftlang/swift:5.9-slim              │
 │        runAsNonRoot, readOnlyRootFilesystem, no secrets                   │
 │        swift build && swift test  ─▶ compile-check ✅                     │
 └──────────────────────────────────┬───────────────────────────────────────┘
                                     │ (on success, dispatch)
                                     ▼
                         macOS runner pool (OUTSIDE k8s)
                         xcodebuild archive/export ─▶ TestFlight / MDM
                         signing assets from Secrets Manager / Key Vault
```

---

## 3. Prerequisites

| Requirement | Detail |
|-------------|--------|
| Kubernetes | 1.27+ |
| CI controller | Argo Workflows, Tekton, or GitHub Actions Runner Controller (ARC) |
| Container image | `swiftlang/swift:5.9-slim` (or the project [../Dockerfile](../Dockerfile)) |
| macOS runners | Separate pool (self-hosted / MacStadium / cloud mac) for real builds |
| Registry | To host the CI image (see [AIRGAPPED.md](AIRGAPPED.md) for offline) |

---

## 4. Identity & credentials

- **In-cluster compile Job:** requires **no secrets** — it only compiles. Run it locked
  down (see manifest). Prefer a **workload identity** binding only if it must pull from a
  private registry.
- **macOS runners (out of cluster):** hold signing identities + App Store Connect API key,
  pulled at build time from a cloud secrets manager via a short-lived **role** (see
  [AWS.md](AWS.md) / [AZURE.md](AZURE.md)). Never mount signing secrets into the k8s Job.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `SWIFT_VERSION` | `5.9` | Pin the toolchain image tag |
| `GIT_REF` | `refs/pull/42/head` | Ref the Job checks out |
| `SWIFT_BUILD_FLAGS` | `-c debug` | Optional build configuration for the compile check |

The compile Job needs no cloud/app secrets. App runtime env vars: none.

---

## 6. Configuration references

| Setting | Example | Purpose |
|---------|---------|---------|
| Job image | `swiftlang/swift:5.9-slim` | Linux compile toolchain |
| `activeDeadlineSeconds` | `900` | Bound the compile Job |
| `backoffLimit` | `1` | Fail fast on compile errors |
| resources.requests | `cpu: 1, memory: 2Gi` | SwiftPM compile footprint |

**Example compile-check Job:**

```yaml
apiVersion: batch/v1
kind: Job
metadata:
  name: pickletrack-compile-check
spec:
  backoffLimit: 1
  activeDeadlineSeconds: 900
  template:
    spec:
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 1000
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: swiftpm
          image: swiftlang/swift:5.9-slim
          command: ["bash", "-lc", "swift build && swift test"]
          workingDir: /src
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: ["ALL"] }
          volumeMounts:
            - { name: src, mountPath: /src }
            - { name: tmp, mountPath: /tmp }
      volumes:
        - name: src
          emptyDir: {}     # populated by a git-clone init container
        - name: tmp
          emptyDir: {}
```

> `readOnlyRootFilesystem: true` requires a writable `/tmp` (and possibly a writable build
> dir) mounted as `emptyDir` for SwiftPM's build cache.

---

## 7. Verification

> No health endpoint, login, upload, or DB. Verification = the compile Job succeeds.

```bash
kubectl apply -f pickletrack-compile-check.yaml
kubectl wait --for=condition=complete job/pickletrack-compile-check --timeout=900s
kubectl logs job/pickletrack-compile-check | tail -n 20   # expect build OK, "no tests"
```

- [ ] Job reaches `Complete`
- [ ] Logs show `Compiling ... Build complete!`
- [ ] `swift test` reports no tests (no `Tests/` target yet)
- [ ] Real app archive verified separately on the macOS runner

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Upgrade toolchain | Bump the `swift:slim` image tag; re-run Job |
| Scale | Compile Jobs are ephemeral; parallelize per-PR with unique Job names |
| Registry mirror | For airgap, mirror `swift:slim` (see [AIRGAPPED.md](AIRGAPPED.md)) |
| Prune | Jobs are `restartPolicy: Never`; use `ttlSecondsAfterFinished` to auto-clean |
| Signing rotation | Handled on macOS runners, not here |

There is nothing long-running to scale, no HPA/PDB, no persistent volumes, and no probes —
these are batch compile Jobs, not services.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Job fails: "no such module 'SwiftUI'" | Apple frameworks not on Linux | Expected — this is a compile check; build the app on macOS runners |
| Job `OOMKilled` | SwiftPM compile memory | Raise `resources.limits.memory` (e.g. `4Gi`) |
| `readOnlyRootFilesystem` build error | No writable scratch | Mount `emptyDir` at `/tmp` and the build dir |
| Image pull fails (airgap) | Registry not mirrored | Mirror `swift:slim` to the internal registry |
| Job hangs | `swift package resolve` reaching network | PickleTrack has **no external deps**; remove network wait / use offline mode |
