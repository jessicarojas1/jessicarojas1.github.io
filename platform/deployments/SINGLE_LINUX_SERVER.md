# Single Linux Server — `platform` shared infrastructure

**Applicability:** `platform` does not itself run as a service on a VM. This guide
covers the two real ways a single Linux host interacts with the platform:

1. **Consuming the base images** ([`base-images/`](../base-images/)) — building and
   running downstream PHP-Apache / Node apps *FROM* the hardened bases on one VM
   (docker-compose / systemd + Docker).
2. **Operating the Terraform module** ([`audit-sink/`](../audit-sink/)) from that VM
   as a "Terraform bastion" — running `init/plan/apply` against AWS using an
   **instance role** rather than static keys.

There is no `platform` web app to expose, so there is **no nginx vhost, TLS
termination, health check, or login** for `platform` itself. Those belong to the
downstream apps that adopt the base images.

---

## 1. Deployment architecture

```
  one Linux VM (EC2 / on-prem)
  ├── Docker Engine
  │     ├── platform/php-apache:<tag>   (base, :8080, www-data)  <-- built from base-images
  │     └── platform/node:<tag>         (base, :8080, UID 10001)
  │           └── downstream app images build FROM these
  │
  └── Terraform CLI  (audit-sink/)  ---> AWS  (via the VM's INSTANCE ROLE)
                                          creates the central audit sink
```

The downstream app containers ship their per-app audit log to the CloudWatch groups
the module creates; the module forwards those to the locked S3 archive. See
[AWS.md](AWS.md) for the sink's cloud resources.

## 2. Topology

```
        +-------------------------- Linux VM --------------------------+
        |  systemd --> docker compose up                              |
        |    app (FROM platform/php-apache) :8080  read_only rootfs    |
        |      tmpfs /tmp ; volume uploads/ ; cap_drop ALL            |
        |    app (FROM platform/node)       :8080                      |
        |                                                             |
        |  terraform (audit-sink/)  --instance role-->  AWS            |
        +----------------------------------|--------------------------+
                                           v
                     CloudWatch Logs -> Firehose -> S3 (Object Lock)
```

## 3. Prerequisites

| Item | Version / note |
|---|---|
| Linux | Debian 12 / Ubuntu 22.04 / RHEL 9 (matches the `bookworm`/Debian base images) |
| Docker Engine + Compose plugin | 24+ |
| Terraform CLI | **>= 1.6.0** |
| AWS CLI v2 | for verifying the sink and assuming roles |
| An instance role | attached to the VM for Terraform + (for apps) audit-log writes |

## 4. Identity & credentials

**Prefer an instance role over static keys.** Attach an IAM instance profile to the
VM so the Terraform CLI and the AWS SDK resolve credentials automatically.

- **Terraform apply role** (deploying the sink) — needs create/manage on the
  resources in `main.tf`: KMS, S3, CloudWatch Logs, Kinesis Firehose, IAM
  role/policy/attachment. Scope to the account and, ideally, a permissions boundary.
- **App writer role** (running containers) — attach the module output
  `writer_policy_arn` (append-only `logs:CreateLogStream` + `logs:PutLogEvents` on
  the `/audit/<prefix>/*` groups **only** — never S3). Pass the app's role ARN into
  `writer_principal_arns` so the module attaches the policy for you.

Static access keys are a documented fallback only for disconnected/on-prem hosts;
if used, store them in a file with `0600`, never in the repo, and rotate on a
schedule (see [SECURITY.md](../docs/SECURITY.md)).

## 5. Environment variables

`platform` has no app runtime env. The relevant variables are the AWS SDK
credential/region variables used by Terraform on the VM:

| Variable | Example (Commercial) | Example (GovCloud) | Purpose |
|---|---|---|---|
| `AWS_REGION` | `us-east-1` | `us-gov-west-1` | Target region |
| `AWS_PROFILE` | `platform-prod` | `platform-gov` | Named profile (or omit and use the instance role) |
| `AWS_USE_FIPS_ENDPOINT` | `false` | `true` | Force FIPS endpoints in GovCloud |
| `DOCKER_BUILDKIT` | `1` | `1` | Build the base images with BuildKit |

## 6. Configuration references (Terraform variables)

Same variable set as [LOCAL_DEVELOPMENT.md §6](LOCAL_DEVELOPMENT.md#6-configuration-references-terraform-variables).
For a real single-server prod deployment: `object_lock_mode = "COMPLIANCE"`,
`object_lock_retention_days >= 1095`, `kms_key_arn = ""` (create a rotated CMK), and
populate `writer_principal_arns` with the VM/app role ARNs.

## 7. Verification

No `platform` health/login/upload exists — verify the artifacts and the sink:

```bash
# Base images build + run as non-root on the VM
docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:1 .
docker run --rm --entrypoint id platform/php-apache:1        # uid=33(www-data)

# Terraform apply the sink using the INSTANCE ROLE (no keys in env)
cd platform/audit-sink && terraform init && terraform apply

# Object written to the immutable archive (end-to-end):
BUCKET=$(terraform output -raw bucket_name)
GROUP=$(terraform output -json log_group_names | python3 -c 'import sys,json;print(list(json.load(sys.stdin).values())[0])')
aws logs create-log-stream --log-group-name "$GROUP" --log-stream-name smoke
aws logs put-log-events --log-group-name "$GROUP" --log-stream-name smoke \
  --log-events "timestamp=$(($(date +%s)*1000)),message=smoke-test"
# after the Firehose buffer interval (default 300s) flushes:
aws s3 ls "s3://$BUCKET/audit/" --recursive | tail    # object present
aws s3api get-object-lock-configuration --bucket "$BUCKET"   # COMPLIANCE default retention
```

Confirm the append-only guarantee: attempting `aws s3 rm s3://$BUCKET/...` must be
**denied** by the bucket policy (`DenyAllDeletesAndLockWeakening`).

## 8. Day-2 operations

- **Patch/upgrade base images:** re-pin `@sha256:` digests (commands inline in each
  Dockerfile), rebuild, redeploy the downstream containers.
- **Sink upgrades/migrations:** none in the SQL sense; changes are Terraform. Run
  `terraform plan` before every `apply`; review carefully — COMPLIANCE Object Lock is
  irreversible and `force_destroy = false`.
- **Scaling:** the sink scales in AWS (Firehose/S3 are managed). The VM only needs
  capacity for the downstream containers.
- **Backups:** the audit archive is protected by Object Lock + versioning; the
  critical thing to back up on the VM is **Terraform state** — use a remote backend
  (see [DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).
- **Cert/secret rotation:** the CMK has key rotation enabled; rotate the VM's role
  credentials via the instance profile (automatic with roles).
- **Logs:** downstream containers log to stdout/stderr (the bases are configured for
  it); ship them to the sink via the CloudWatch groups.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `AccessDenied` on `terraform apply` | Instance role lacks a resource permission | Grant the missing KMS/S3/Logs/Firehose/IAM action; check permissions boundary |
| App can't write audit logs | App role not in `writer_principal_arns` / policy not attached | Add the ARN, re-apply; confirm `writer_policy_arn` attached |
| `s3 rm` unexpectedly "succeeds" then object reappears | Versioning + Object Lock — delete markers, not real deletes | Expected; COMPLIANCE prevents true deletion |
| Container can't bind port 80 | Bases listen on **8080**, not 80 | Map `8080:8080`; no `CAP_NET_BIND_SERVICE` needed |
| FIPS endpoint errors in GovCloud | Endpoint/partition mismatch | Set `AWS_USE_FIPS_ENDPOINT=true`, region `us-gov-*`, partition `aws-us-gov` |

See also: [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) · [DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)
