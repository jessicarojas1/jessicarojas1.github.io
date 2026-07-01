# Azure (Commercial + Azure Government) — CPP Tool Collection

**Applicability:** Reframed. These are CLI tools, not a web app, so there is no
App Service site, no database, no public endpoint. On Azure the real pattern is:
**build in CI with a workload-identity (federated) service principal**, store the
image/binaries as artifacts (**ACR** / Blob), and **run tools as batch** via
**Azure Container Instances (ACI)** one-off/scheduled runs or **Azure Batch**.
Because these are defense/aerospace tools, **Azure Government** (endpoints
`*.usgovcloudapi.net`, ARM `management.usgovcloudapi.net`) is a first-class
target; Commercial vs Government differences are called out where they exist.

## 1. Deployment architecture

CI (GitHub Actions / Azure Pipelines) uses **Microsoft Entra Workload Identity
Federation** (no client secret) to push the image built from `./Dockerfile` to
**Azure Container Registry**, and/or a binary bundle to **Blob Storage**. A
scheduled ACI container group (or Azure Batch task) pulls the image, runs a tool
with args against a mounted Blob/Azure Files input, writes reports to Blob, and
logs to Azure Monitor / Log Analytics. Nothing serves traffic.

## 2. Topology

```
 GitHub/Pipelines ─OIDC─▶ Entra Workload Identity ─▶ CI SP (least-priv)
      │                                                  ├─▶ ACR (image)
   make -j / docker build                                └─▶ Blob (bundle)
      ▼
 Logic App / Scheduler ─▶ ACI container group (or Azure Batch task)
      │                        │  input  ◀── Blob / Azure Files mount
      │                        │  report ──▶ Blob
      └────────────────────────┴─ logs ──▶ Log Analytics ; exit code ─▶ run status
   Managed Identity used for ACR pull + Blob/Key Vault access. No inbound ports.
```

## 3. Prerequisites

- Azure subscription (Commercial) and/or **Azure Government** subscription.
- Azure Container Registry; a Storage account (Blob) for artifacts + I/O.
- ACI (for one-off/scheduled runs) or Azure Batch pool.
- Entra ID app/managed identity with workload-identity federation for CI.
- `az` CLI (set the right cloud: `az cloud set --name AzureUSGovernment` for Gov).

## 4. Identity & credentials

Prefer **managed identity / workload identity federation**, never a client
secret in CI.

- **CI service principal** (federated) — least-privilege roles:
  `AcrPush` on the registry, `Storage Blob Data Contributor` on the artifacts
  container. No password stored.
- **Runtime managed identity** (assigned to the ACI group / Batch pool):
  `AcrPull` on ACR, `Storage Blob Data Reader` on input, `Storage Blob Data
  Contributor` on the reports container. If `aes-vault` is used, `Key Vault
  Secrets User` on the passphrase secret only.
- The tools themselves need no Azure credentials (no network calls). Managed
  identity is only for pulling the image and moving input/output.

## 5. Environment variables

Tools read no env vars; these drive the CI/run wrapper. **Commercial vs
Government** differ in cloud suffix and endpoints:

| Variable | Commercial example | Government example | Purpose |
|----------|--------------------|--------------------|---------|
| `AZURE_CLOUD` | `AzureCloud` | `AzureUSGovernment` | `az cloud set` target |
| `ACR_LOGIN_SERVER` | `cpp.azurecr.io` | `cpp.azurecr.us` | Image registry |
| `STORAGE_SUFFIX` | `blob.core.windows.net` | `blob.core.usgovcloudapi.net` | Blob endpoint |
| `KEYVAULT_URI` | `https://kv.vault.azure.net` | `https://kv.vault.usgovcloudapi.net` | `aes-vault` passphrase secret |
| `ARM_ENDPOINT` | `management.azure.com` | `management.usgovcloudapi.net` | ARM control plane |
| `LOG_WORKSPACE_ID` | `<guid>` | `<guid>` | Log Analytics workspace |
| `TOOL_CMD` | `cui-classifier /data --json` | same | Tool + args to run |

FIPS: Azure Government storage/compute run in FIPS 140-validated facilities; for
`aes-vault`, build/link against a FIPS-validated OpenSSL provider (see
`docs/SECURITY.md`).

## 6. Configuration references

No app config files. Configuration = the container `command`/args (`TOOL_CMD`),
stored in the ACI YAML / Batch task under version control.

## 7. Verification

No health/login/DB/upload — verify pipeline + a container run + report object:

```bash
# Image pushed
az acr repository show --name cpp --image cpp-tools:latest

# Smoke: run a demo tool in ACI, check logs + exit
az container create -g rg-cpp --name cpp-smoke --image $ACR_LOGIN_SERVER/cpp-tools:latest \
   --restart-policy Never --command-line "mil1553-sim"
az container logs -g rg-cpp --name cpp-smoke | grep -q "Bus Monitor" && echo OK
az container show -g rg-cpp --name cpp-smoke \
   --query "containers[0].instanceView.currentState.exitCode"

# Batch/scheduled sweep → confirm report blob written
az storage blob list --account-name cppstore --container-name reports \
   --auth-mode login -o table        # report blob present (object written)
```

"Object written to storage" = the report blob in the reports container (the
CLI-tool analogue of the standard's DB-row/object check).

## 8. Day-2 operations

- **Build/publish:** CI on tag → `docker build` → `az acr login` (via workload
  identity) → push; optionally `az storage blob upload-batch` the `bin/` bundle.
- **Scheduled sweeps:** a **Logic App** (recurrence) or Azure Scheduler triggers
  `az container create`/`restart` with the tool command; or an Azure Batch job
  schedule. Set restart-policy `Never` and a timeout.
- **Scaling:** run more ACI groups / Batch tasks to shard input, or use the
  tool's `--parallel`/`--threads` per container. No autoscale-for-traffic.
- **Backups:** artifacts rebuildable from git; enable **Blob versioning + soft
  delete** on report/artifact containers; ACR content trust / immutable tags.
- **Secret rotation:** `aes-vault` passphrase in Key Vault with rotation; no TLS
  certs (no service). Keep OpenSSL patched → rebuild image.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Commercial endpoints hit from a Gov subscription | wrong cloud profile | `az cloud set --name AzureUSGovernment`; use `.us`/`usgovcloudapi.net` hosts |
| `unauthorized` on ACR pull | runtime identity lacks `AcrPull` | assign `AcrPull` to the container group's managed identity |
| CI push fails, asks for a secret | federation not configured | set up Entra workload-identity federation (no client secret) |
| Container run marked failed on a clean detection | tool exited `2` (found) | wrap: `sh -c '...; [ $rc = 0 ] || [ $rc = 2 ]'` |
| `aes-vault` container hangs | waiting for interactive passphrase | pipe the Key Vault secret to stdin via a wrapper |
| Report blob missing | tool wrote stdout only / perms | grant `Storage Blob Data Contributor`; redirect output to a file then upload |
