# OPEN_ITEMS — `platform` production-readiness register

Honest status of the shared-infrastructure directory. "Done" = present and verified in
the real files; "Outstanding" = a gap, TODO, or hardening not yet applied. Grouped by
theme with impact + suggested action.

Legend: ✅ done · ⚠️ partial · ❌ outstanding

---

## Terraform state & backend

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Remote state backend | ❌ | `versions.tf` has **no `backend` block** — default is local state; risk of loss/no locking on team use | Add an S3 backend + DynamoDB lock table (KMS-encrypted, versioned); document in DEPLOYMENT.md |
| State locking | ❌ | Concurrent applies can corrupt state | DynamoDB lock table with the S3 backend |
| State encryption | ⚠️ | Depends on the backend you add | Enforce SSE-KMS on the state bucket |

## audit-sink module

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Object Lock COMPLIANCE + versioning + SSE-KMS | ✅ | Write-once archive achieved | — |
| Deny deletes / lock-weakening bucket policy | ✅ | AU-9 immutability enforced for all principals | — |
| KMS CMK with rotation + EncryptionContext-scoped CWL grant | ✅ | Avoids over-broad grant finding | — |
| Least-privilege writer / Firehose / CWL roles | ✅ | Apps append-only; no S3 access | — |
| MFA-gated legal-hold role | ✅ | Separation of duties | — |
| `terraform validate`/`plan` verified in-repo | ⚠️ | README notes provider download is network-restricted in the build env; only `fmt` verified there | Run `validate`/`plan` in a real pipeline against `hashicorp/aws >= 5.40` and record the result |
| Cross-region replication of the archive | ❌ | Regional durability gap for the immutable archive | Add optional CRR to a second Object-Locked bucket |
| CloudWatch metric alarm on `_firehose-errors` | ❌ | Silent audit-delivery gaps possible | Add a CloudWatch alarm + SNS on delivery errors |
| `writer_principal_arns` role-name regex assumption | ⚠️ | `regex("role/(.+)$", …)` assumes a plain role ARN (no path/session) | Document the constraint; validate inputs are role ARNs |
| Automated tests (terratest / checkov / tfsec) | ❌ | No policy-as-code gate | Add tfsec/checkov + a terratest smoke apply in non-prod |

## base-images

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Digest-pinned upstreams | ✅ | Supply-chain integrity | Re-pin each patch cycle (commands inline) |
| Non-root, port 8080, SUID stripped, read-only-rootfs friendly | ✅ | Hardened baseline | — |
| Prod php.ini + OPcache hardening | ✅ | CM-7/SI-2 baseline | — |
| Published/tagged images in a registry | ❌ | Referenced by path today; apps must build locally | Push versioned images to a registry; adopt `FROM <registry>/platform/...:<tag>` |
| Image vulnerability scanning in CI | ❌ | Unknown CVE exposure between re-pins | Add Trivy/Grype scan gate on build |
| SBOM / signing (cosign) | ❌ | No signed provenance beyond the digest pin | Generate SBOM + `cosign sign` the images |
| Distinct healthchecks | ✅ (by design) | Bases intentionally leave HEALTHCHECK to apps | Keep; ensure each app adds one |

## Identity & secrets

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| OIDC roles for Terraform + registry push (documented) | ✅ | Prefer roles over static keys | Wire the actual CI OIDC provider + trust policy |
| No secrets committed | ✅ | `terraform.tfvars.example` only; `.example` warns against real ARNs | — |
| Permissions boundary on the apply role | ❌ | Apply role is broad (KMS/S3/Logs/Firehose/IAM) | Attach a permissions boundary; scope by resource where possible |

## CI/CD & docs

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| CI pipeline (`fmt`/`validate`/`plan`, build+scan images) | ❌ | No automated gate; README has no build badge | Add a workflow; publish a badge |
| Standard doc set (deployments ×6, docs ×4, README, OPEN_ITEMS, CLAUDE, render.yaml) | ✅ | This delivery | Keep current on every change |
| Cross-region / multi-partition DR runbook | ⚠️ | Documented, not drilled | Perform the restore drills in DISASTER_RECOVERY.md |
| Azure sink port | ❌ (intentional) | Module is AWS-only; Azure is reference-only | Only if a downstream app requires an Azure-native sink |

## Not applicable (stated for honesty)

- **Database / migrations** — none in this project.
- **Background worker / cron / queue** — none; the sink pipeline is fully AWS-managed.
- **Ollama / GPU / AI feature** — none; no LLM dependency exists here.
- **App health endpoint / login / file upload** — none; this is infrastructure.
- **Root `Dockerfile`** — intentionally absent; the hardened Dockerfiles live in `base-images/`.
