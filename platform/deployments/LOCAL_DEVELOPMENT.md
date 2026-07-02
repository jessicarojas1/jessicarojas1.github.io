# Local Development — `platform` shared infrastructure

**Applicability:** `platform` is not an application — it is shared infrastructure:
an [`audit-sink/`](../audit-sink/) Terraform module (a central, tamper-evident
audit-log sink on AWS) and [`base-images/`](../base-images/) hardened container
base images (`Dockerfile.node`, `Dockerfile.php-apache`). "Local development" here
means **building the base images** and **validating/planning the Terraform module**
on a laptop. There is **no app process, no health endpoint, no login, no database,
and no file upload** to exercise — verification is `docker build` + a container
smoke test and `terraform fmt/validate/plan`.

---

## 1. Deployment architecture (local)

Two independent artifacts, built and checked locally:

| Artifact | Local action | Output |
|---|---|---|
| `base-images/Dockerfile.node` | `docker build` | local image `platform/node:dev` |
| `base-images/Dockerfile.php-apache` | `docker build` | local image `platform/php-apache:dev` |
| `audit-sink/` (Terraform) | `terraform init/validate/plan` | a plan file — **no `apply` locally** |

Nothing runs as a long-lived service. The Terraform module targets AWS; locally you
only produce and inspect a `plan`. You do **not** create real cloud resources from a
laptop unless you deliberately `apply` against an account.

## 2. Topology

```
  laptop
  ├── docker engine
  │     ├── build Dockerfile.node        -> platform/node:dev        (UID 10001)
  │     └── build Dockerfile.php-apache  -> platform/php-apache:dev  (www-data, :8080)
  │
  └── terraform CLI (>= 1.6.0)
        └── audit-sink/  --init--> hashicorp/aws >= 5.40, < 6.0
                          --plan--> reads AWS creds ONLY to read account_id/region
                                    (plan does not mutate; apply is a separate,
                                     deliberate step done in a real pipeline)
```

## 3. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| Docker Engine / BuildKit | 24+ | to build the two base images |
| Terraform CLI | **>= 1.6.0** (matches `versions.tf`) | `tfenv` recommended |
| AWS provider | `hashicorp/aws >= 5.40.0, < 6.0.0` | pulled by `terraform init` |
| AWS CLI v2 | latest | only needed if you run `plan` against a real account |
| `curl` | any | container smoke test |
| network access | to Docker Hub + Terraform Registry | for `docker build` and `terraform init` (see [AIRGAPPED.md](AIRGAPPED.md) for offline) |

## 4. Identity & credentials

- **Base images:** no credentials required to build. Pushing the built images to a
  registry uses your registry login / an OIDC-assumed **role** in CI — never
  long-lived keys (see [AWS.md](AWS.md) / [AZURE.md](AZURE.md)).
- **Terraform `plan`:** the module reads `aws_caller_identity`, `aws_partition`, and
  `aws_region` data sources, so `plan` needs **read-only** AWS credentials to resolve
  account id/region. Prefer a short-lived, assumed **read-only role**:

  ```bash
  # short-lived STS session for a read-only planner role
  eval "$(aws sts assume-role \
     --role-arn arn:aws:iam::<acct>:role/platform-tf-planner \
     --role-session-name local-plan \
     --query 'Credentials.[`AWS_ACCESS_KEY_ID=`+AccessKeyId,`AWS_SECRET_ACCESS_KEY=`+SecretAccessKey,`AWS_SESSION_TOKEN=`+SessionToken]' \
     --output text | sed 's/^/export /')"
  ```

  Never commit a real `terraform.tfvars` with ARNs (`terraform.tfvars.example` says
  the same). Do not put static AWS keys in the repo or shell history.

## 5. Environment variables

The module is **not** configured through environment variables — it is configured
through Terraform variables (see `audit-sink/variables.tf` and
`terraform.tfvars.example`). The only environment inputs are the standard AWS SDK
credential/region variables consumed during `plan`:

| Variable | Example | Purpose |
|---|---|---|
| `AWS_PROFILE` | `platform-dev` | Named profile for the read-only planner role |
| `AWS_REGION` | `us-east-1` (Commercial) / `us-gov-west-1` (GovCloud) | Region the plan resolves against |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_SESSION_TOKEN` | (from `assume-role`) | Short-lived STS session; prefer over static keys |
| `DOCKER_BUILDKIT` | `1` | Enable BuildKit for the base-image builds |

## 6. Configuration references (Terraform variables)

Set in `terraform.tfvars` (copy from `terraform.tfvars.example`):

| Variable | Example | Purpose |
|---|---|---|
| `name_prefix` | `platform-dev` | Prefix for all resource names (lowercase, 2–41 chars) |
| `log_group_names` | `["aegis","paladin","sentinel-qms"]` | One CloudWatch group per source app |
| `object_lock_mode` | `GOVERNANCE` (dev) / `COMPLIANCE` (prod) | WORM mode; use GOVERNANCE only in sandbox |
| `object_lock_retention_days` | `1095` | Default per-object WORM retention |
| `log_retention_days` | `400` | CloudWatch hot-tier retention |
| `kms_key_arn` | `""` | Empty ⇒ module creates a rotated CMK |
| `writer_principal_arns` | `[]` | App task/instance role ARNs (append-only log access) |
| `enable_legal_hold_role` | `true` | Create the MFA-gated legal-hold role |

## 7. Verification

There is **no health endpoint or login** for this project. Verify the artifacts:

**Base images build + smoke test:**

```bash
cd /path/to/repo

# Build both bases (build context is the repo root as in base-images/README.md)
docker build -f platform/base-images/Dockerfile.node       -t platform/node:dev .
docker build -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:dev .

# Smoke test 1: node base runs as non-root UID 10001
docker run --rm --entrypoint id platform/node:dev            # -> uid=10001 gid=10001

# Smoke test 2: php-apache base runs as www-data and serves on 8080
docker run -d --name pa -p 8080:8080 platform/php-apache:dev
docker exec pa id                                            # -> uid=33(www-data)
curl -fsS http://localhost:8080/ >/dev/null && echo "apache up on :8080"
docker rm -f pa

# Smoke test 3: SUID bits stripped (defence-in-depth)
docker run --rm platform/php-apache:dev bash -c 'find / -xdev -perm /6000 -type f 2>/dev/null | head'   # -> empty
```

**Terraform validate + plan (clean):**

```bash
cd platform/audit-sink
cp terraform.tfvars.example terraform.tfvars   # edit name_prefix etc.
terraform fmt -check          # passes clean (README confirms)
terraform init                # downloads hashicorp/aws >= 5.40
terraform validate            # -> "Success! The configuration is valid."
terraform plan -out=tfplan    # review the resource graph; DO NOT apply locally
```

A clean run shows the planned resources: 1 KMS key + alias, the S3 bucket and its
7 configuration sub-resources, N CloudWatch log groups (one per `log_group_names`),
the Firehose stream + its IAM role/policy + error log group/stream, the
CWL→Firehose role/policy + subscription filters, the append-only `writer` policy,
and (when enabled) the legal-hold role/policy.

## 8. Day-2 operations (local)

- **Re-pin base image digests** on patch cycles: each Dockerfile embeds the exact
  registry-API `curl` command to fetch the current `@sha256:` for its human tag
  (`php:8.3-apache`, `node:20-bookworm-slim`). Bump the digest and the trailing
  `# human-tag` comment together, then rebuild.
- **Bump the AWS provider** within the `>= 5.40.0, < 6.0.0` constraint by deleting
  `.terraform.lock.hcl` selectively / running `terraform init -upgrade`; re-`plan`.
- Keep `terraform fmt` clean before committing.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `terraform init` fails to download provider | No network / proxy to Terraform Registry | Use a provider mirror (see [AIRGAPPED.md](AIRGAPPED.md)) or configure `TF_CLI_ARGS_init` proxy |
| `plan` errors resolving `aws_caller_identity` | No/invalid AWS creds | Export a short-lived assumed-role session (§4) |
| `NoSuchBucket` / partition mismatch in later apply | Wrong partition/region | Deploy in the same partition as the apps (`aws` / `aws-us-gov`); the module is partition-aware |
| `docker build` cannot pull base | Digest unreachable / offline | Mirror the pinned digest to a local registry (see [AIRGAPPED.md](AIRGAPPED.md)) |
| `name_prefix` validation error | Not lowercase/hyphen or wrong length | Use 2–41 chars matching `^[a-z0-9][a-z0-9-]{1,40}$` |

See also: [DEPLOYMENT.md](../docs/DEPLOYMENT.md) · [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md)
