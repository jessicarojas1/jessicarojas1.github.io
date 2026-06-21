# CITADEL — Production Deployment (Infrastructure-as-Code)

The CITADEL public demo runs entirely in the browser. For an authorized, multi-tenant, or
CUI-bearing deployment you run the **production tier**: a hardened container serving the
analyzer front-end plus an API/worker that orchestrates heavyweight open-source scanners
(Semgrep, Trivy, Syft/Grype, Gitleaks, ClamAV, Bandit). This directory provides turnkey
Infrastructure-as-Code and runbooks for **six targets** across government cloud, commercial
cloud, Kubernetes, and a single-host container stack — plus a CI workflow that validates all
of it.

All targets deploy the **same** container image (built from the repository root with
`docker build -f citadel/server/Dockerfile -t citadel-server .`), expose the app on port
**8080**, health-check `GET /api/health`, run **non-root as UID 10001** with a read-only root
filesystem, and source every secret (`CITADEL_JWT_SECRET`, `CITADEL_ADMIN_PASSWORD`,
`CITADEL_SUPERADMIN_TOKEN`, `DATABASE_URL`, …) from a managed secret store — never from the
image. Durable, multi-instance, and **multi-tenant** (`CITADEL_MULTITENANT=1`) operation
requires PostgreSQL, so every server-side target provisions or wires a managed Postgres.

## Government cloud (FedRAMP-High / IL4–IL5)

| | **Azure Government** | **AWS GovCloud (US)** |
|---|---|---|
| Folder | [`azure-gov/`](azure-gov/) | [`aws-gov/`](aws-gov/) |
| IaC | Bicep (`main.bicep`) | Terraform (`main.tf`) |
| Compute | Azure Container Apps | ECS Fargate |
| Registry | Azure Container Registry | Amazon ECR (scan-on-push) |
| Edge / WAF | Front Door / App Gateway WAF | ALB + AWS WAFv2 |
| Secrets / KMS | Key Vault (Premium, HSM) | Secrets Manager + KMS CMK |
| Object store | Storage (private blob) | S3 (SSE-KMS, Object Lock) |
| Logging | Log Analytics + Defender | CloudWatch + GuardDuty + Security Hub |
| Regions | `usgovvirginia`, `usgovarizona` | `us-gov-west-1`, `us-gov-east-1` |
| Quick start | `azure-gov/deploy.sh` | `aws-gov/deploy.sh` |

## Commercial cloud & platform targets

| | **AWS (commercial)** | **Google Cloud** | **Kubernetes** | **Docker Compose** |
|---|---|---|---|---|
| Folder | [`aws/`](aws/) | [`gcp/`](gcp/) | [`kubernetes/`](kubernetes/) | [`compose/`](compose/) |
| IaC | Terraform | Terraform | Helm chart + raw manifests | Compose v2 |
| Compute | ECS Fargate (≥2 AZ) | Cloud Run v2 | Deployment + HPA + PDB | Single host |
| Database | RDS PostgreSQL (Multi-AZ) | Cloud SQL (private IP, HA) | external `DATABASE_URL` | bundled `postgres:16` |
| Edge / WAF | ALB + ACM + WAFv2 | External HTTPS LB + Cloud Armor | Ingress + NetworkPolicy | nginx TLS reverse proxy |
| Secrets | Secrets Manager + KMS CMK | Secret Manager | Secret / ExternalSecret | `.env` / Compose secrets |
| Registry | ECR (scan-on-push) | Artifact Registry | any | local build or any |
| Best for | Commercial AWS prod | Serverless / scale-to-zero | Existing clusters | On-prem / dev / single VM |
| Quick start | `aws/deploy.sh` | `gcp/deploy.sh` | `helm install` (see README) | `docker compose up -d` |

## Continuous validation

[`ci/iac-validate.yml`](ci/) is a reference GitHub Actions workflow that validates every
package above on any PR touching `citadel/deploy/**`: Terraform `fmt`/`validate` + `tflint` +
`checkov`/`trivy config` (matrix over the three Terraform stacks), `bicep build`, `helm lint`
+ `kubeconform`, `hadolint` over all Dockerfiles, and `docker compose config`. Copy it into
`.github/workflows/` to enable it (see [`ci/README.md`](ci/README.md)).

## Shared hardening posture

Both packages enforce the same baseline, cross-walked to **NIST SP 800-53 Rev 5** and **CMMC 2.0**:

- **Non-root, least-privilege container** — read-only root filesystem, dropped Linux capabilities,
  FIPS-friendly base image (`CM-6`, `CM-7`).
- **TLS 1.2+ with FIPS-validated endpoints**; HTTP→HTTPS redirect only (`SC-8`, `SC-13`).
- **No public ingress except the WAF**; private networking / VPC endpoints for all backend traffic (`SC-7`).
- **Secrets from a managed vault** via workload identity — never baked into images (`IA-5`, `SC-12`).
- **Encryption at rest with customer-managed keys**; uploads quarantined in immutable storage (`SC-28`).
- **Centralized, tamper-evident logging & monitoring** with threat detection (`AU-2`, `AU-9`, `SI-4`).
- **Image vulnerability scanning** on push and continuous posture management (`RA-5`, `SI-2`).

See each folder's `README.md` for the step-by-step runbook, the full control-mapping table, and
teardown instructions.

## Build the container

Each target ships a multi-stage, hardened `Dockerfile` and `nginx.conf`:

```bash
cd azure-gov   # or aws-gov
docker build -t citadel:latest .
```

The `deploy.sh` scripts build the image, push it to the in-boundary registry, and apply the IaC.
