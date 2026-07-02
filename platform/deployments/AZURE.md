# Azure — `platform` shared infrastructure (Commercial + Azure Government)

**Applicability:** The [`audit-sink/`](../audit-sink/) Terraform module targets
**AWS only** — its providers and resources are `hashicorp/aws` (KMS, S3 Object Lock,
CloudWatch Logs, Kinesis Firehose, IAM). There is **no Azure provider or Azure
resource in the module**, so there is nothing to deploy to Azure for the audit sink
today. Do not fabricate `azurerm` resources — they do not exist in this project.

What genuinely applies to Azure:

1. **Base images on Azure** — the hardened [`base-images/`](../base-images/) run
   unchanged on **AKS**, **Azure Container Apps**, or **App Service for Containers**,
   pushed to **Azure Container Registry (ACR)**.
2. **The nearest Azure equivalent of the audit sink** — if a downstream app runs on
   Azure and needs the same write-once guarantee, the mapping is documented below so
   teams know the target of a future port. It is a design reference, **not**
   something this module provisions.

Commercial vs **Azure Government** endpoint differences are noted where they matter
(`*.usgovcloudapi.net`, ARM `management.usgovcloudapi.net`, login
`login.microsoftonline.us`).

---

## 1. Deployment architecture

```
  Azure subscription
  ├── ACR  (platform/node, platform/php-apache)   <-- pushed from base-images
  ├── AKS / Container Apps / App Service
  │     app image FROM platform base   :8080   runAsNonRoot, read-only rootfs
  │     Workload Identity / Managed Identity -> writes app audit log
  └── (nearest sink equivalent — NOT provisioned by this module):
        Azure Monitor Log Analytics  ->  immutable Storage (see §1 mapping)
```

**AWS audit-sink → Azure equivalent (reference only):**

| AWS resource (real, in `main.tf`) | Nearest Azure service |
|---|---|
| CloudWatch Log group `/audit/<prefix>/<app>` | Azure Monitor **Log Analytics** workspace / table |
| Kinesis Firehose → S3 | Diagnostic setting → Storage export / Data Export rule |
| S3 bucket Object Lock **COMPLIANCE** | **Blob immutability policy** (time-based, WORM) with **locked** state |
| SSE-KMS CMK | Storage **customer-managed key** in **Key Vault** (Managed HSM for FIPS) |
| KMS key rotation | Key Vault key auto-rotation |
| IAM writer role (append-only) | Managed Identity + `Monitoring Metrics Publisher` / minimal RBAC |
| Legal-hold role (MFA) | Blob **legal hold** + PIM-elevated role requiring MFA |

## 2. Topology

```
  Pod/app (FROM platform base)  --diagnostic/log push-->  Log Analytics workspace
                                                              |
                                                              v
                                        Storage account (immutable blob container,
                                        time-based retention LOCKED, CMK via Key Vault)
```

## 3. Prerequisites

| Item | Note |
|---|---|
| Azure CLI | `az` latest; for Gov: `az cloud set --name AzureUSGovernment` |
| Docker / ACR | to build+push the base images |
| Terraform (only if you port the sink) | would require adding an `azurerm` provider — not present today |
| Entra ID (Azure AD) | Workload Identity / Managed Identity for app log writes |

## 4. Identity & credentials

**Prefer Managed Identity / Workload Identity — no static secrets.**

- **Image push:** grant the CI pipeline's **workload identity federation** (OIDC from
  GitHub/Azure DevOps) the `AcrPush` role on the ACR. No admin user, no static
  registry password.
- **App log writes:** the app's **User-Assigned Managed Identity** gets least-
  privilege RBAC to the Log Analytics workspace (the Azure analogue of the AWS
  append-only writer policy).
- **Legal hold / immutability changes:** gate behind **PIM** just-in-time elevation
  requiring MFA — the analogue of the module's MFA-gated legal-hold role.

For Azure Government, authenticate against `login.microsoftonline.us` and ARM
`management.usgovcloudapi.net`.

## 5. Environment variables (Commercial vs Government)

No Azure resources are provisioned by the module, so there are no module env vars.
For base-image build/push and any future sink port:

| Variable | Azure Commercial | Azure Government | Purpose |
|---|---|---|---|
| `AZURE_ENVIRONMENT` | `AzureCloud` | `AzureUSGovernment` | Cloud selection for CLI/SDK |
| ARM endpoint | `management.azure.com` | `management.usgovcloudapi.net` | Control plane |
| Login authority | `login.microsoftonline.com` | `login.microsoftonline.us` | Entra ID auth |
| Storage suffix | `blob.core.windows.net` | `blob.core.usgovcloudapi.net` | Blob endpoints |
| Key Vault suffix | `vault.azure.net` | `vault.usgovcloudapi.net` | CMK / secrets |
| ACR login server | `<name>.azurecr.io` | `<name>.azurecr.us` | Image registry |

## 6. Configuration references

For the immutable-storage equivalent (if/when a downstream app is ported to Azure):
set the blob container's **time-based retention policy** to match the AWS defaults —
retention `object_lock_retention_days` (1095 days), and **lock** the policy so it
becomes WORM (the analogue of COMPLIANCE mode). Enable versioning and a
customer-managed key from Key Vault. These are not Terraform variables in this
module; document them in the porting app's own IaC.

## 7. Verification

No `platform` app/health/login on Azure. Verify the two real things:

```bash
# Base images run non-root on Azure compute
az acr build -r <registry> -f platform/base-images/Dockerfile.node -t platform/node:1 .
# (or docker build + az acr login + docker push)
kubectl exec deploy/<app> -- id          # non-root (10001 / www-data) on AKS

# Immutable-blob equivalent (only if a sink port exists) — WORM enforced:
az storage blob delete --account-name <acct> --container-name audit --name <blob>
#   -> fails: "This operation is not permitted as the blob is immutable." (expected)
```

## 8. Day-2 operations

- **Image patching:** re-pin base-image digests, `az acr build`/push, roll workloads.
- **Retention:** a **locked** immutability policy cannot be shortened — same
  irreversibility as AWS COMPLIANCE. Extend only.
- **Key rotation:** Key Vault auto-rotation for the CMK.
- **Porting the sink to Azure:** treat as a new module with an `azurerm` provider;
  keep it out of `audit-sink/` (which is AWS-specific) or make it a sibling module.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Expecting `terraform apply` to build Azure infra | Module is AWS-only | Use [AWS.md](AWS.md); no `azurerm` resources exist here |
| Base image fails on App Service | App Service expects `WEBSITES_PORT` | Set `WEBSITES_PORT=8080` (bases listen on 8080) |
| Cannot delete audit blob | Locked immutability policy | Expected WORM behavior; wait out retention |
| Gov auth failures | Wrong cloud/authority | `az cloud set --name AzureUSGovernment`; use `*.us` endpoints |

See also: [AWS.md](AWS.md) (the real IaC) · [KUBERNETES.md](KUBERNETES.md) · [SECURITY.md](../docs/SECURITY.md)
