# CITADEL — Government Deployment

The CITADEL public demo runs entirely in the browser. For an authorized, multi-tenant, or
CUI-bearing deployment you run the **production tier**: a hardened container serving the
analyzer front-end plus an optional API/worker that orchestrates heavyweight open-source
scanners (Semgrep, Trivy, Syft/Grype, Gitleaks, ClamAV, Bandit). This directory provides
turnkey Infrastructure-as-Code and runbooks for two FedRAMP-High / IL4–IL5 capable clouds.

## Choose a target

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
