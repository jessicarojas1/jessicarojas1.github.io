# Kubernetes — CPP Tool Collection

**Applicability:** Reframed. The tools are **not** a long-running Deployment/
Service — there is nothing to keep alive, no port, no readiness probe. The real
Kubernetes pattern is **batch**: run a tool as a one-shot `Job`, or on a
schedule as a `CronJob`, inside the project's container image, reading input
from a mounted volume and writing a report to stdout/logs or an output volume.
No Ingress, HPA, Service, or DB is involved; those sections are adapted below.

## 1. Deployment architecture

The multi-stage image (`./Dockerfile`) is built in CI and pushed to a registry.
A `CronJob`/`Job` schedules a Pod that runs one tool with args against a mounted
data volume (PVC or object-store CSI). The Pod runs to completion; its exit code
becomes the Job's success/failure. Logs (stdout/stderr) are collected by the
cluster's logging stack.

## 2. Topology

```
  registry ──image──▶ ┌──────────── CronJob / Job ────────────┐
                       │  Pod (non-root uid 10001)              │
   PVC /data (RO) ─────┤   ENTRYPOINT: cui-classifier /data     │
                       │              --json                    │
   PVC /out  (RW) ◀────┤   stdout ─▶ report ; exit 0/2          │
                       └────────────────────────────────────────┘
        Job status ◀── exit code       logs ─▶ cluster logging (Loki/ELK)
  No Service, no Ingress, no probes (batch, not serving).
```

## 3. Prerequisites

- A Kubernetes cluster (any conformant distro) and `kubectl`.
- A container registry reachable from the cluster; the image built from
  `./Dockerfile`.
- A `PersistentVolumeClaim` (or CSI-mounted bucket) for input data, and
  optionally one for output reports.
- Secrets/CSI driver only if you use `aes-vault` with a piped passphrase.

## 4. Identity & credentials

- **Workload identity for image pull**, not registry passwords in plain
  Secrets, where the platform supports it (IRSA on EKS, Workload Identity on
  GKE/AKS) — see the [AWS](AWS.md)/[AZURE](AZURE.md) guides.
- The tools need **no cloud credentials** at runtime (no network calls).
- `memory-scanner` in-cluster only makes sense to scan a *sidecar/ephemeral*
  target and needs `SYS_PTRACE` and shared PID namespace — avoid unless
  specifically required; it is not part of the standard batch pattern.
- Pull secrets (`aes-vault` passphrase) via a mounted Secret / CSI SecretStore,
  piped to the tool on stdin.

## 5. Environment variables

The tools read no runtime env vars. Job manifests typically set none for the
app; any vars are for your wrapper/logging.

| Variable | Example | Purpose |
|----------|---------|---------|
| (none required by tools) | — | behavior is set by container `args` |
| `TZ` (optional) | `UTC` | timestamps in `log-correlator`/`zt-policy` audit |

## 6. Configuration references

Configuration is the Pod `command`/`args`. Keep them in the manifest under
version control. Example arg sets: `["entropy-scanner","/data","--min","7.2","--json"]`,
`["yara-lite","--builtin","/data","--recursive"]`,
`["packet-analyzer","/data/capture.pcap","--json"]`.

## 7. Verification

No health endpoint/login/DB/upload — verify the image runs a tool and the Job
completes with the expected exit code:

```bash
# One-off Job that runs a self-contained demo (no inputs needed)
kubectl run cpp-smoke --rm -it --restart=Never \
  --image=$REGISTRY/cpp-tools:latest -- mil1553-sim | grep -q "Bus Monitor" && echo OK

# Apply the CronJob, then trigger a manual run and inspect status
kubectl apply -f cronjob-cui.yaml
kubectl create job --from=cronjob/cpp-cui-sweep cpp-cui-manual
kubectl wait --for=condition=complete job/cpp-cui-manual --timeout=120s
kubectl logs job/cpp-cui-manual            # the JSON report
kubectl get job cpp-cui-manual -o jsonpath='{.status.succeeded}'
```

Note: `cui-classifier`/`entropy-scanner`/`yara-lite`/`packet-analyzer` exit `2`
when they *find* something. Wrap the command so a "found" result is not a Job
failure if that's your intent (see the `sh -c` wrapper below).

## 8. Day-2 operations

**CronJob** (`cronjob-cui.yaml`) — nightly CUI sweep, non-root, read-only root
fs, treats "found" (exit 2) as success:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: cpp-cui-sweep
spec:
  schedule: "0 3 * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      backoffLimit: 1
      activeDeadlineSeconds: 3600
      template:
        spec:
          restartPolicy: Never
          securityContext:
            runAsNonRoot: true
            runAsUser: 10001
            seccompProfile: { type: RuntimeDefault }
          containers:
            - name: cui
              image: REGISTRY/cpp-tools:latest
              command: ["/bin/sh","-c"]
              # exit 2 = CUI found → treat as success; only real errors (1) fail
              args:
                - 'cui-classifier /data --json > /out/cui.json; rc=$?; [ "$rc" = 0 ] || [ "$rc" = 2 ]'
              resources:
                requests: { cpu: "250m", memory: "256Mi" }
                limits:   { cpu: "1",    memory: "1Gi" }
              securityContext:
                allowPrivilegeEscalation: false
                readOnlyRootFilesystem: true
                capabilities: { drop: ["ALL"] }
              volumeMounts:
                - { name: data, mountPath: /data, readOnly: true }
                - { name: out,  mountPath: /out }
          volumes:
            - name: data
              persistentVolumeClaim: { claimName: cpp-data }
            - name: out
              persistentVolumeClaim: { claimName: cpp-out }
```

- **Upgrades:** rebuild + push a new image tag; update the CronJob image; roll
  forward (no in-place migration — stateless).
- **Scaling:** batch scales by running more Jobs (e.g. shard input dirs) or by
  the tool's own `--parallel`/`--threads` within one Pod; there is **no HPA**
  (nothing serving) and **no PDB** (Jobs aren't disrupted like Deployments).
- **Storage:** input via `ReadOnlyMany` PVC or CSI bucket mount; output via a
  separate RW PVC or write JSON to stdout and let logging capture it.
- **Probes:** N/A — batch Pods run to completion; use `activeDeadlineSeconds`
  and `backoffLimit` instead of liveness/readiness.
- **Secret rotation:** `aes-vault` passphrase via CSI SecretStore, rotated at
  the secret manager; no cert rotation (no TLS).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Job marked `Failed` after a clean detection | tool exited `2` (found) | wrap in `sh -c '...; [ "$rc" = 0 ] || [ "$rc" = 2 ]'` |
| `CreateContainerConfigError` / cannot run as non-root | image or SCC mismatch | image already runs as uid 10001; ensure `runAsNonRoot: true` matches |
| Pod OOMKilled on large input | `packet-analyzer`/`yara-lite` load files into memory | raise memory limit or shard input |
| `Permission denied` on `/data` | wrong fsGroup / RO mount | set correct `fsGroup`; ensure input volume is readable by uid 10001 |
| `memory-scanner` can't read target | no `SYS_PTRACE` / not shareProcessNamespace | out of scope for batch; add capability + shared PID ns only if required |
| Empty report file | tool wrote to stderr or exited early | `kubectl logs` the Pod; check exit code and args |
