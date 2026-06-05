# Sentinel QMS — Terraform

Infrastructure-as-Code for deploying Sentinel QMS to **AWS GovCloud (us-gov-west-1)**
and **Azure Government** (`environment = "usgovernment"`).

## Layout

```
terraform/
  modules/            Reusable, dual-cloud modules (cloud = "aws" | "azure")
    network/          VPC/VNet, public+private+data subnets, NAT, SGs/NSGs, flow logs
    database/         RDS PostgreSQL 16 / Azure Flexible Server (private, encrypted, HA)
    storage/          S3 (SSE-KMS, versioned) / Storage Account + Blob (CMK, private)
    secrets/          Secrets Manager / Key Vault (CMK-encrypted, RBAC)
    compute/          ECS Fargate + ALB + WAF / Container Apps
    observability/    CloudWatch / Log Analytics + alarms
  aws-govcloud/       Root stack for AWS GovCloud
  azure-gov/          Root stack for Azure Government
```

Each module switches on a single `cloud` variable so both clouds share one
interface. Resources for the non-selected cloud are guarded with `count`/`for_each`
and never created.

## Design principles (FedRAMP Moderate / NIST SP 800-171 / CMMC 2.0 L2)

- **Encryption at rest**: customer-managed KMS CMK (AWS) / CMK-capable Key Vault
  (Azure) on RDS/Flexible Server, S3/Blob, Secrets, and logs.
- **Encryption in transit**: TLS-only — `rds.force_ssl`, `require_secure_transport`,
  ALB FIPS SSL policy, S3 `DenyInsecureTransport` policy, storage `min_tls_version = TLS1_2`.
- **Private data tier**: databases sit in isolated subnets with no internet route
  (AWS) / VNet-injected with `public_network_access_enabled = false` (Azure).
- **Least privilege**: scoped IAM task roles / managed identities; the app reads
  only its own secrets and bucket.
- **Audit logging**: VPC flow logs, RDS log exports, Container Insights, Log Analytics.
- **Edge protection**: AWS WAFv2 / Azure WAF_v2 (OWASP CRS) with rate limiting.
- **Tagging**: every resource carries `Project`, `Environment`, `Owner`,
  `data-classification = CUI`, and `Compliance`.
- **No hardcoded secrets**: DB and JWT secrets are generated with `random_password`
  and written to Secrets Manager / Key Vault; the OIDC client secret is injected
  via `TF_VAR_oidc_client_secret`.

## Remote state

State lives in an encrypted S3 bucket + DynamoDB lock table (AWS) or an Azure
Storage container (Azure). Bootstrap once:

```bash
../scripts/bootstrap-remote-state.sh aws    # or: azure
```

Then `terraform init` with the printed `-backend-config` values (or uncomment
them in `backend.tf`).

## Deploy

```bash
# AWS GovCloud
cd aws-govcloud
cp terraform.tfvars.example terraform.tfvars   # edit
export TF_VAR_oidc_client_secret='***'
terraform init -backend-config=...
terraform plan -out tfplan
terraform apply tfplan

# Azure Government
cd ../azure-gov
cp terraform.tfvars.example terraform.tfvars   # edit
export TF_VAR_oidc_client_secret='***'
export ARM_SUBSCRIPTION_ID=... ARM_TENANT_ID=...
terraform init -backend-config=...
terraform plan -out tfplan
terraform apply tfplan
```

The `../scripts/deploy-*.sh` wrappers chain build/push + apply + migrate.

## Notes / simplifications

- ACM/Key Vault TLS certificates are referenced, not minted — supply your
  agency certificate ARN / Key Vault secret id.
- The Azure App Gateway listener starts as HTTP placeholder until
  `appgw_keyvault_cert_secret_id` is provided, then flips to HTTPS.
- DB master credentials rotate-ready but rotation Lambda/Function is left as a
  follow-on; rotation is enabled at the secret level.
- GuardDuty / AWS Config / Microsoft Defender for Cloud are organization-level
  and assumed enabled by the landing zone (see infra/README.md security notes).
