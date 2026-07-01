# Air-Gapped / Offline — `platform` shared infrastructure

**Applicability:** Two offline concerns, both real:

1. **Base images** ([`base-images/`](../base-images/)) — mirror the digest-pinned
   upstreams (`php:8.3-apache@sha256:…`, `node:20-bookworm-slim@sha256:…`) into a
   **private registry** so builds never reach Docker Hub.
2. **Terraform module** ([`audit-sink/`](../audit-sink/)) — run `init/plan/apply`
   with an **offline provider mirror** for `hashicorp/aws >= 5.40, < 6.0`. The module
   deploys to AWS; in a true air gap this means an **AWS-disconnected region
   (GovCloud/isolated partition)** reached over a private link, with all *tooling*
   (providers, images, packages) mirrored inside the enclave.

There is **no hosted AI/LLM dependency anywhere in this project**, so the standard
"replace the hosted AI API with Ollama" step is **N/A** — there is no AI feature to
self-host. State this explicitly in the enclave's runbook.

---

## 1. Deployment architecture

```
  air-gapped enclave
  ├── private OCI registry  (mirrors pinned base images by digest)
  │     library/php:8.3-apache@sha256:954d…  -> registry.internal/platform/php-apache
  │     library/node:20-bookworm-slim@sha256:2cf0… -> registry.internal/platform/node
  ├── terraform provider mirror  (filesystem/network mirror of hashicorp/aws)
  └── terraform CLI  (audit-sink/)  --private link-->  AWS (isolated partition)
```

## 2. Topology

```
  [ internet-connected staging host ]                 [ air-gapped enclave ]
     docker pull php@sha256 / node@sha256   --bundle-->   private registry
     terraform providers mirror ./mirror    --bundle-->   provider mirror dir
                                                              |
                                                     terraform init -plugin-dir
                                                              |
                                                     plan/apply over private link
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Staging host with internet | To pull images + mirror providers, then bundle |
| Private OCI registry | Harbor / ECR (in-partition) / registry:2 |
| Terraform provider mirror | `terraform providers mirror` output for `hashicorp/aws >= 5.40, < 6.0` |
| Transfer medium | Signed bundle (tar) moved across the gap per policy |
| Terraform | `>= 1.6.0` inside the enclave |

## 4. Identity & credentials

- **Registry push/pull:** use the enclave registry's OIDC/robot **role**, not a
  static admin password.
- **Terraform:** assume an in-partition **role** (instance role on the enclave
  bastion, or SSO) — no long-lived keys crossing the gap. GovCloud/isolated
  partitions still support STS role assumption over the private link.
- Sign every transferred bundle (checksums + detached signature) and verify inside
  the enclave before import (supply-chain integrity — see [SECURITY.md](../docs/SECURITY.md)).

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `TF_CLI_ARGS_init` | `-plugin-dir=/opt/tf-mirror` | Point `init` at the offline provider mirror |
| `AWS_REGION` | `us-gov-west-1` | Isolated-partition region |
| `AWS_USE_FIPS_ENDPOINT` | `true` | FIPS endpoints in gov/isolated partitions |
| `DOCKER_CONFIG` | `/opt/docker` | Registry auth pointing at the internal registry |

## 6. Configuration references

**Mirror the base images (staging host, then move the bundle):**

```bash
# Pull by the EXACT pinned digests used in the Dockerfiles
docker pull php@sha256:954d6198d9877b396382aa8a93d8be4832ab4908a7dc64f58dcc4be2833b8e29
docker pull node@sha256:2cf067cfed83d5ea958367df9f966191a942351a2df77d6f0193e162b5febfc0
docker save php@sha256:954d… node@sha256:2cf0… -o platform-bases.tar   # bundle across the gap

# Inside the enclave:
docker load -i platform-bases.tar
docker tag  php@sha256:954d…  registry.internal/library/php:8.3-apache
docker tag  node@sha256:2cf0… registry.internal/library/node:20-bookworm-slim
docker push registry.internal/library/php:8.3-apache
docker push registry.internal/library/node:20-bookworm-slim
```

Then point the base Dockerfiles' `FROM` at the internal registry (keep the
`@sha256:` digest so integrity is still enforced), or configure a registry mirror so
`FROM php:8.3-apache@sha256:…` resolves internally.

**Mirror the Terraform provider (staging host):**

```bash
terraform providers mirror ./tf-mirror     # from a dir with the audit-sink required_providers
tar czf tf-mirror.tgz tf-mirror            # move across the gap
# enclave:
terraform init -plugin-dir=/opt/tf-mirror  # no registry access needed
terraform validate && terraform plan
```

## 7. Verification

```bash
# Base images build with NO external network
docker build --network=none \
  -f platform/base-images/Dockerfile.php-apache -t platform/php-apache:air .
docker run --rm --entrypoint id platform/php-apache:air     # www-data (non-root)

# Terraform is fully offline
terraform init -plugin-dir=/opt/tf-mirror   # succeeds without internet
terraform validate                          # "Success! The configuration is valid."
terraform plan                              # clean, using mirrored provider

# End-to-end audit write (same as AWS.md §7) over the private link, then confirm
# the object is present and DELETE is denied by the bucket policy.
```

No app/health/login exists; verification = clean offline build + clean offline
plan/apply + WORM delete-deny. No Ollama/GPU step (no AI feature).

## 8. Day-2 operations

- **Patch cycle:** on the connected staging host, re-pin the base-image digests
  (commands are inline in each Dockerfile), re-pull, re-bundle, re-import, re-sign.
- **Provider updates:** re-run `terraform providers mirror` for the new patch within
  `>= 5.40, < 6.0`, bundle, import.
- **CVE feeds:** import offline vulnerability feeds to scan the mirrored base images
  (Trivy/Grype in offline DB mode) as part of each patch cycle.
- **State:** keep Terraform state on an in-enclave encrypted backend with locking
  (see [DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `terraform init` reaches out to registry.terraform.io | Mirror not used | Set `-plugin-dir` / `TF_CLI_ARGS_init`; verify `provider_installation` mirror block |
| `docker build` fails pulling `FROM` | Digest not in internal registry | Import the pinned digest; retag to internal; keep `@sha256:` |
| Digest mismatch on import | Wrong/rotated digest | Use the exact `@sha256:` from the Dockerfile; re-pin deliberately |
| Bundle signature invalid | Tamper / wrong key | Re-transfer; verify against the signing key before import |
| Expecting Ollama config | There is no AI feature | N/A — remove from the runbook |

See also: [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) · [SECURITY.md](../docs/SECURITY.md)
