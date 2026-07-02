# OPEN_ITEMS — `platform` production-readiness register

Honest status of the shared-infrastructure directory. "Done" = present and verified in
the real files; "Outstanding" = a gap, TODO, or hardening not yet applied. Grouped by
theme with impact + suggested action.

Legend: ✅ done · ⚠️ partial · ❌ outstanding

---

## Terraform state & backend

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Remote state backend | ✅ | `audit-sink/backend.tf` adds an S3 `backend` block (partial config); `backend.hcl.example` + `terraform init -backend-config=backend.hcl`. The `bootstrap/` module provisions the state bucket/table/CMK and emits `backend_hcl`. Verified: `terraform fmt` clean; `validate` blocked only by egress policy on registry.terraform.io (provider download 403). | Run `bootstrap` once per account; keep `backend.hcl` out of git (gitignored) |
| State locking | ✅ | `bootstrap/` creates a DynamoDB `LockID` table (PAY_PER_REQUEST, PITR, SSE-KMS); wired via `dynamodb_table` in `backend.hcl` | — |
| State encryption | ✅ | State bucket SSE-KMS (rotated CMK) + `encrypt = true` in `backend.tf`; TLS-only + KMS-only-upload bucket policy | — |

## audit-sink module

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Object Lock COMPLIANCE + versioning + SSE-KMS | ✅ | Write-once archive achieved | — |
| Deny deletes / lock-weakening bucket policy | ✅ | AU-9 immutability enforced for all principals | — |
| KMS CMK with rotation + EncryptionContext-scoped CWL grant | ✅ | Avoids over-broad grant finding | — |
| Least-privilege writer / Firehose / CWL roles | ✅ | Apps append-only; no S3 access | — |
| MFA-gated legal-hold role | ✅ | Separation of duties | — |
| `terraform validate`/`plan` verified in-repo | ⚠️ | `terraform fmt -check -recursive` passes clean (verified with Terraform 1.9.8 in this env). `validate`/`plan` require the AWS provider, whose download from `registry.terraform.io` is blocked by egress policy here (HTTP 403) — cannot run in-repo | CI job `platform-audit-sink.yml` runs `init -backend=false` + `validate` on every push/PR; run `plan` in a real pipeline with AWS creds |
| Cross-region replication of the archive | ✅ | Optional CRR gated by `enable_crr`: replication IAM role + `aws_s3_bucket_replication_configuration` in `main.tf`, and a hardened Object-Locked destination in the `audit-sink/replica/` submodule. Delete markers not replicated (WORM). fmt clean | Wire the replica-region provider + apply in an account (live-account step) |
| CloudWatch metric alarm on `_firehose-errors` | ✅ | `enable_delivery_alarm` (default on): SNS topic (AWS-managed-key encrypted, optional email sub), a log-metric-filter alarm on the `_firehose-errors` group, and a `DeliveryToS3.DataFreshness` stall alarm — both action the SNS topic | Subscribe the topic to on-call/PagerDuty in the account |
| `writer_principal_arns` role-name regex assumption | ✅ | `validation` block requires each entry match `^arn:aws[a-z-]*:iam::\d{12}:role/[A-Za-z0-9_+=,.@-]+$` (no path/user/session); constraint documented in the variable description + AWS.md | — |
| Automated tests (terratest / checkov / tfsec) | ✅ (policy-as-code) / ⚠️ (terratest) | `platform-audit-sink.yml`: `fmt -check`, `validate`, **Trivy config** (tfsec engine, HIGH/CRITICAL hard gate) + **Checkov** (SARIF, soft-fail baseline). Terratest smoke apply still needs a non-prod account | Add a terratest apply/destroy in non-prod; triage Checkov baseline then flip to hard-fail |

## base-images

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| Digest-pinned upstreams | ✅ | Supply-chain integrity | Re-pin each patch cycle (commands inline) |
| Non-root, port 8080, SUID stripped, read-only-rootfs friendly | ✅ | Hardened baseline | — |
| Prod php.ini + OPcache hardening | ✅ | CM-7/SI-2 baseline | — |
| Published/tagged images in a registry | ⚠️ | `platform-base-images.yml` builds both bases and, on a `platform-images-v*` tag, pushes them to **GHCR** (`ghcr.io/<owner>/platform/<name>`). Adoption via `FROM <registry>/platform/...:<tag>` documented in `base-images/README.md` | Cut the first `platform-images-v*` tag to publish (live-registry step) |
| Image vulnerability scanning in CI | ✅ | `platform-base-images.yml` builds each base and runs a **Trivy** image scan with a HIGH/CRITICAL hard gate (`ignore-unfixed`, `os,library`) on every push/PR | — |
| SBOM / signing (cosign) | ✅ (in-repo) | Publish job attaches a BuildKit **SBOM + SLSA provenance**, a GitHub build-provenance attestation, and **keyless cosign** sign + verify gate. Runs on the release tag | Fires when a `platform-images-v*` tag is pushed (live-OIDC step) |
| Distinct healthchecks | ✅ (by design) | Bases intentionally leave HEALTHCHECK to apps | Keep; ensure each app adds one |

## Identity & secrets

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| OIDC roles for Terraform + registry push (documented) | ✅ | Prefer roles over static keys | Wire the actual CI OIDC provider + trust policy |
| No secrets committed | ✅ | `terraform.tfvars.example` only; `.example` warns against real ARNs | — |
| Permissions boundary on the apply role | ✅ | New `permissions_boundary_arn` var attaches a boundary to every IAM role the module creates (Firehose, CWL→Firehose, replication, legal-hold). The external apply role gets a reference boundary policy at `audit-sink/policies/apply-permissions-boundary.json` (scopes KMS/S3/Logs/Firehose/SNS/CW/DynamoDB, and forces created roles to carry a boundary) to attach out-of-band | Create the boundary as a managed policy + set it on the CI apply role (live-account step) |

## CI/CD & docs

| Item | Status | Impact | Suggested action |
|---|---|---|---|
| CI pipeline (`fmt`/`validate`/`plan`, build+scan images) | ✅ | Two workflows: `.github/workflows/platform-audit-sink.yml` (fmt/validate + Trivy config + Checkov) and `platform-base-images.yml` (build + Trivy image scan + tag-gated cosign/SBOM). README build badges wired. `plan` still needs live AWS creds | Add a non-prod `plan` step with OIDC creds |
| Standard doc set (deployments ×6, docs ×4, README, OPEN_ITEMS, CLAUDE, render.yaml) | ✅ | This delivery | Keep current on every change |
| Cross-region / multi-partition DR runbook | ⚠️ | Documented, not drilled | Perform the restore drills in DISASTER_RECOVERY.md |
| Azure sink port | ❌ (intentional) | Module is AWS-only; Azure is reference-only | Only if a downstream app requires an Azure-native sink |

## Not applicable (stated for honesty)

- **Database / migrations** — none in this project.
- **Background worker / cron / queue** — none; the sink pipeline is fully AWS-managed.
- **Ollama / GPU / AI feature** — none; no LLM dependency exists here.
- **App health endpoint / login / file upload** — none; this is infrastructure.
- **Root `Dockerfile`** — intentionally absent; the hardened Dockerfiles live in `base-images/`.
