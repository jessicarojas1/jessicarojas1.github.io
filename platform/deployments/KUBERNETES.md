# Kubernetes — `platform` shared infrastructure

**Applicability:** `platform` is not a workload you deploy to Kubernetes. Two real
Kubernetes concerns exist:

1. **Base images in cluster builds** — downstream apps build *FROM*
   [`base-images/`](../base-images/) and run in the cluster with the exact
   `securityContext` the bases are designed for (non-root, read-only rootfs, drop
   all caps, port 8080).
2. **Shipping audit logs to the sink** — pods emit their per-app audit log to the
   CloudWatch groups provisioned by the [`audit-sink/`](../audit-sink/) Terraform
   module; the module forwards them to the immutable S3 archive.

The `audit-sink` itself is AWS-managed infra (KMS/S3/CloudWatch/Firehose) — it is
**not** deployed as Kubernetes manifests. Provision it with Terraform (see
[AWS.md](AWS.md)); this guide covers how cluster workloads *consume* the platform.

---

## 1. Deployment architecture

```
  EKS / any K8s cluster
  ├── image registry: platform/node, platform/php-apache  (pushed from base-images)
  ├── app Deployment (FROM platform/php-apache)  :8080
  │     securityContext: runAsNonRoot, runAsUser 33 (www-data),
  │                      readOnlyRootFilesystem, drop ALL, no privilege escalation
  │     volumes: emptyDir/tmpfs -> /tmp ; PVC -> uploads/
  │     IRSA role  --writer_policy_arn-->  logs:PutLogEvents on /audit/<prefix>/<app>
  └── app Deployment (FROM platform/node)  :8080  runAsUser 10001
                                   |
                                   v
              (AWS) CloudWatch Logs -> Firehose -> S3 (Object Lock COMPLIANCE)
```

## 2. Topology

```
   Pod (app FROM base image)
     |  audit records
     v
   stdout/stderr  --(Fluent Bit / CloudWatch agent OR AWS SDK PutLogEvents)-->
   CloudWatch Log group  /audit/<prefix>/<app>   (created by audit-sink Terraform)
     |  subscription filter (forward all)
     v
   Kinesis Firehose (SSE-KMS, GZIP)  -->  S3 bucket (Object Lock, versioned, SSE-KMS)
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Kubernetes 1.27+ (EKS for IRSA) | For workload identity to the audit groups |
| Container registry | ECR / private registry holding the base images |
| The `audit-sink` module applied | Provides the CloudWatch groups + `writer_policy_arn` |
| IRSA / OIDC provider on the cluster | To map a ServiceAccount to the writer IAM role |
| Fluent Bit / aws-for-fluent-bit **or** app-side `PutLogEvents` | To route pod audit logs into the `/audit/<prefix>/<app>` group |

## 4. Identity & credentials

**Use IRSA (IAM Roles for Service Accounts) — no static keys in pods.**

- Create an IAM role trusted by the cluster OIDC provider, attach the module output
  `writer_policy_arn` (append-only `logs:CreateLogStream` + `logs:PutLogEvents` on
  the audit groups **only**). Pass that role ARN into the module's
  `writer_principal_arns` so the append-only policy is attached to it.
- Annotate the app ServiceAccount:

  ```yaml
  apiVersion: v1
  kind: ServiceAccount
  metadata:
    name: aegis
    annotations:
      eks.amazonaws.com/role-arn: arn:aws:iam::<acct>:role/aegis-audit-writer
  ```

- **Registry push identity** (CI building the base images) is a separate OIDC role
  with `ecr:PutImage`/`BatchCheckLayerAvailability` scoped to the base-image repos —
  see [AWS.md](AWS.md).

## 5. Environment variables

The base images set only runtime defaults; there are no cluster-level app secrets
for `platform`:

| Variable | Example | Purpose | Source |
|---|---|---|---|
| `PORT` | `8080` | Listen port (Node base default) | baked into `Dockerfile.node` |
| `NODE_ENV` | `production` | Node runtime mode | baked into `Dockerfile.node` |
| `AWS_REGION` | `us-east-1` / `us-gov-west-1` | Region for `PutLogEvents` | pod env / IRSA |
| `AWS_ROLE_ARN` + `AWS_WEB_IDENTITY_TOKEN_FILE` | (injected) | IRSA credential chain | injected by EKS |

## 6. Configuration references

The **runtime `securityContext`** is the platform's Kubernetes "config" — it must
match what the base images assume (see `base-images/README.md`):

```yaml
securityContext:              # pod or container level
  runAsNonRoot: true
  runAsUser: 10001            # node base; use 33 (www-data) for the php-apache base
  allowPrivilegeEscalation: false
  readOnlyRootFilesystem: true
  capabilities:
    drop: ["ALL"]
# writable paths the bases need:
volumeMounts:
  - { name: tmp, mountPath: /tmp }
volumes:
  - { name: tmp, emptyDir: { medium: Memory } }
ports:
  - containerPort: 8080
```

Add HPA, PDB, and readiness/liveness probes on the **app's** `/healthz` (php-apache)
or `/api/health` (node) route — the bases intentionally do not define a HEALTHCHECK
(each app adds its own; see the Dockerfile trailers).

## 7. Verification

No `platform` health/login/upload. Verify base-image compatibility and log delivery:

```bash
# Pod runs as non-root with read-only rootfs (base image contract)
kubectl exec deploy/aegis -- id                 # uid=33(www-data) or 10001
kubectl exec deploy/aegis -- touch /root/x      # -> read-only filesystem error (expected)
kubectl exec deploy/aegis -- sh -c 'echo ok > /tmp/x && cat /tmp/x'   # /tmp writable

# Audit record reaches the immutable archive (end-to-end)
GROUP=/audit/platform-prod/aegis
aws logs describe-log-streams --log-group-name "$GROUP" --max-items 3
# after the Firehose buffer flushes, the object appears under s3://<bucket>/audit/YYYY/MM/DD/
aws s3 ls "s3://$(cd platform/audit-sink && terraform output -raw bucket_name)/audit/" --recursive | tail
```

## 8. Day-2 operations

- **Image updates:** rebuild base images with re-pinned digests, push, roll the
  downstream Deployments (`kubectl rollout restart`).
- **Scaling:** HPA on the app workloads; the sink (Firehose/S3) is managed and scales
  independently.
- **PDB:** set `minAvailable` for the app tier; the sink has no cluster component.
- **Secrets:** none for `platform`; IRSA supplies short-lived credentials. If any app
  secret exists, use CSI Secrets Store / External Secrets — not plain Secrets.
- **Log routing:** keep Fluent Bit / the CloudWatch agent healthy so audit records
  keep reaching `/audit/<prefix>/<app>`; a gap here is an audit-completeness gap.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Pod `CreateContainerError: runAsNonRoot` | App layer added a root process | Keep the base `USER`; don't override to root |
| App crashes writing to disk | `readOnlyRootFilesystem: true` | Mount `/tmp` (tmpfs) + PVCs for `uploads/`, `logs/` |
| Cannot bind privileged port | App tried port <1024 | Use 8080 (base default); Service maps 80→8080 |
| `AccessDenied` on `PutLogEvents` | IRSA role missing `writer_policy_arn` | Add role ARN to `writer_principal_arns`, re-apply Terraform |
| Audit records never appear in S3 | Firehose buffer not flushed / log router down | Wait the buffer interval (default 300s); check Fluent Bit + `_firehose-errors` log group |

See also: [AWS.md](AWS.md) · [AZURE.md](AZURE.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md)
