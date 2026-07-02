# Azure Deployment — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent**
(`cmmc-agent/`) on **Azure**, covering both **Azure Commercial** and **Azure
Government**. The recommended target is **Azure Container Apps** (or **App
Service for Containers**), with the single application secret
(`ANTHROPIC_API_KEY`) resolved from **Azure Key Vault** through a
**system-assigned managed identity** — no static secrets — and the app's local
JSON state (`status.json`, `settings.json`) placed on an **Azure Files** share
mounted into the container.

> The app is a single synchronous Flask process: a web GUI + a Claude-powered
> agentic backend that assesses/tracks/closes gaps across all 110 NIST 800-171
> practices for CMMC Level 2. There is **no database, no object storage, no
> server-side document upload, and no login/auth**. All persistent state is two
> local JSON files.

**Siblings:** [AWS.md](AWS.md) · [KUBERNETES.md](KUBERNETES.md) ·
[AIRGAPPED.md](AIRGAPPED.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md)
**Canonical guide:** [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

> **CUI / CMMC note:** CMMC Level 2 protects Controlled Unclassified Information
> (CUI). For any environment actually storing or processing CUI, **Azure
> Government** is the relevant cloud, and its dedicated endpoints (below) must be
> used. Azure Commercial is appropriate only for non-CUI evaluation, demo, or
> internal-tracking use.

---

## 1. Deployment architecture

- **Compute:** an **Azure Container App** (Consumption or Dedicated) — or **App
  Service for Containers** — running one revision from the `cmmc-agent` image
  (`python:3.11.9-slim`, non-root uid 10001, listens on **5050**,
  `CMD python server.py`).
- **Ingress:** Container Apps external ingress (or App Service front end)
  terminates TLS and forwards to the container's **targetPort 5050**. The
  platform **health probe** targets `GET /api/dashboard`, which returns scoring
  JSON and requires **no API key**.
- **Secret:** `ANTHROPIC_API_KEY` lives in **Azure Key Vault**. The container's
  **system-assigned managed identity** is granted the **Key Vault Secrets User**
  RBAC role, and the app references the secret via a Key Vault **secret
  reference** (Container Apps `secretRef` from Key Vault, or App Service
  `@Microsoft.KeyVault(...)` reference / Key Vault CSI on AKS). No secret value
  is stored in config.
- **Admin plane identity:** operators authenticate to **Microsoft Entra ID** for
  the Azure control plane (portal/CLI); the **application itself has no login**.
- **State:** `status.json` and `settings.json` are written in the app's working
  directory. Mount an **Azure Files** SMB share into the container at the state
  path so the program record survives revision restarts and redeploys.
- **Egress:** the container needs outbound HTTPS to **`api.anthropic.com`**
  (model `claude-opus-4-5`). In Azure Government / CUI networks that disallow
  hosted-AI egress, use the on-prem **Ollama** alternative —
  [AIRGAPPED.md](AIRGAPPED.md).
- **Registry:** image in **Azure Container Registry (ACR)**; the managed
  identity is granted `AcrPull`.

---

## 2. Topology

```
                    Azure Commercial                    |  Azure Government
                    region eastus (example)             |  region usgovvirginia (example)
                    login.microsoftonline.com           |  login.microsoftonline.us
                    *.vault.azure.net                    |  *.vault.usgovcloudapi.net
 ┌──────────┐  HTTPS
 │ Operator │ ─────────►┌────────────────────────────┐
 │ browser  │           │ Container Apps ingress (TLS)│  health probe: GET /api/dashboard
 └──────────┘           └───────────────┬─────────────┘
                                        │ targetPort 5050
                                        ▼
                    ┌───────────────────────────────────┐
                    │ Container App revision              │
                    │  cmmc-agent (Flask, uid 10001)      │
                    │  python server.py :5050             │
                    │                                     │
                    │  env ANTHROPIC_API_KEY  ◄───────────┼── Key Vault secret reference
                    │  (via system-assigned managed id)   │   (RBAC: Key Vault Secrets User)
                    │                                     │
                    │  /app/status.json   ◄───────────────┼── Azure Files share (persistent state)
                    │  /app/settings.json                 │
                    └───────────────────┬─────────────────┘
                                        │ outbound HTTPS
                                        ▼
                              api.anthropic.com  (claude-opus-4-5)
                              — OR —  on-prem Ollama (see AIRGAPPED.md)
```

State is **local JSON on an Azure Files share**. There is **no Azure SQL / Cosmos
DB and no Blob Storage** in this architecture — do not provision or expect them.

---

## 3. Prerequisites

- An Azure subscription in the correct cloud: **Azure Commercial** or **Azure
  Government** (a separate cloud with distinct endpoints and sign-in).
- **Azure CLI** signed in to the target cloud
  (`az cloud set --name AzureUSGovernment` for Government;
  `az cloud set --name AzureCloud` for Commercial).
- An **ACR** repository with the `cmmc-agent` image pushed to it.
- A **Key Vault** (RBAC authorization model enabled) holding the
  `ANTHROPIC_API_KEY` secret.
- A **Storage Account** + **Azure Files** share for persistent state.
- A Container Apps **Environment** (or an App Service Plan) in the target region.
- Permission to create role assignments (to grant the managed identity Key Vault
  and ACR roles).

---

## 4. Identity & credentials

**Prefer managed identity — no static secrets, no service-principal passwords in
config.**

- Enable a **system-assigned managed identity** on the Container App (or App
  Service / AKS workload identity).
- Grant that identity the **least-privilege** Key Vault role — **"Key Vault
  Secrets User"** — scoped to **the vault** (or, more tightly, the individual
  secret). This grants read of secret *values* only, not management.
- Grant **`AcrPull`** on the ACR to the same identity for image pulls.

### Role assignments (Azure Commercial example)

```bash
# System-assigned identity on the Container App
az containerapp identity assign \
  --name cmmc-agent --resource-group rg-cmmc --system-assigned

PRINCIPAL_ID=$(az containerapp identity show \
  --name cmmc-agent --resource-group rg-cmmc --query principalId -o tsv)

VAULT_ID=$(az keyvault show --name kv-cmmc --query id -o tsv)

# Least privilege: read secret values from THIS vault only
az role assignment create \
  --assignee-object-id "$PRINCIPAL_ID" \
  --assignee-principal-type ServicePrincipal \
  --role "Key Vault Secrets User" \
  --scope "$VAULT_ID"

# Pull the image from ACR
ACR_ID=$(az acr show --name acrcmmc --query id -o tsv)
az role assignment create \
  --assignee-object-id "$PRINCIPAL_ID" \
  --assignee-principal-type ServicePrincipal \
  --role "AcrPull" --scope "$ACR_ID"
```

For **Azure Government** the same commands apply after `az cloud set --name
AzureUSGovernment`; the vault DNS resolves to `*.vault.usgovcloudapi.net` and
sign-in goes through `login.microsoftonline.us`.

### Key Vault secret reference (injects the key)

Container Apps: define a secret sourced from Key Vault and map it to the env var.

```bash
az containerapp secret set \
  --name cmmc-agent --resource-group rg-cmmc \
  --secrets anthropic-key=keyvaultref:https://kv-cmmc.vault.azure.net/secrets/ANTHROPIC-API-KEY,identityref:system

az containerapp update \
  --name cmmc-agent --resource-group rg-cmmc \
  --set-env-vars ANTHROPIC_API_KEY=secretref:anthropic-key
```

(App Service alternative: an app setting valued
`@Microsoft.KeyVault(SecretUri=https://kv-cmmc.vault.azure.net/secrets/ANTHROPIC-API-KEY/)`.
Government vault URI uses `...vault.usgovcloudapi.net`.)

---

## 5. Environment variables

The container reads exactly two environment variables. `ANTHROPIC_API_KEY` is
**required** for the AI backend; `PORT` is optional (default 5050).

### Azure Commercial

| Variable | Example | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | Key Vault ref → `https://kv-cmmc.vault.azure.net/secrets/ANTHROPIC-API-KEY` | AI backend credential; without it `POST /api/chat` returns HTTP 500 `{"error":"ANTHROPIC_API_KEY not set"}` |
| `PORT` | `5050` | Container listen port; must equal ingress `targetPort` |
| `ANTHROPIC_BASE_URL` | *(unset)* | Optional SDK override; only set when repointing to a proxy/Ollama front-end ([AIRGAPPED.md](AIRGAPPED.md)) |

Commercial endpoints: sign-in `login.microsoftonline.com`, Key Vault DNS
`*.vault.azure.net`, ARM `management.azure.com`.

### Azure Government — CUI/CMMC target

| Variable | Example | Purpose |
|---|---|---|
| `ANTHROPIC_API_KEY` | Key Vault ref → `https://kv-cmmc.vault.usgovcloudapi.net/secrets/ANTHROPIC-API-KEY` | AI backend credential (Government vault DNS) |
| `PORT` | `5050` | Container listen port |
| `ANTHROPIC_BASE_URL` | *(unset — hosted egress often disallowed)* | In Government/CUI, prefer on-prem Ollama; see [AIRGAPPED.md](AIRGAPPED.md) |

Government endpoints: sign-in `login.microsoftonline.us`, Key Vault DNS
`*.vault.usgovcloudapi.net`, ARM `management.usgovcloudapi.net`, region examples
`usgovvirginia` / `usgovarizona`. **Azure Government is the relevant cloud for
CUI/CMMC workloads.**

---

## 6. Configuration references

| Setting | Example | Purpose |
|---|---|---|
| Health probe path (liveness + readiness) | `/api/dashboard` | JSON score endpoint, no API key required |
| Ingress `targetPort` | `5050` | Matches `EXPOSE 5050` / `PORT` default |
| External ingress | enabled | Public HTTPS entry to the GUI/API |
| Image | `acrcmmc.azurecr.io/cmmc-agent:<tag>` | ACR image reference |
| Managed identity | system-assigned | Resolves Key Vault + pulls from ACR |
| Key Vault secret | `ANTHROPIC-API-KEY` | Source of the injected env var |
| Azure Files mount | `/app` (or dedicated `/app/state` if code adjusted) | Persists `status.json` + `settings.json` |
| Storage account + share | `stcmmc` / `cmmc-state` | Backing store for the Files mount |
| Min / max replicas | `1` / `1` | Constrained by shared-state semantics (see §8) |
| CPU / memory | `0.25` vCPU / `0.5Gi` | Single lightweight Flask process |

**Alternative platform:** to run on **AKS**, use the Deployment + PVC (Azure
Files CSI) + Key Vault CSI secrets pattern in [KUBERNETES.md](KUBERNETES.md).

---

## 7. Verification

Run against the Container App ingress FQDN (`https://<app>.<region>.azurecontainerapps.io`
or your custom domain). No database or Blob Storage exists, so there is nothing
to verify there — persistence is proven by the JSON state files below.

**1. Health / dashboard (no key needed):**

```bash
curl -fsS https://<app-fqdn>/api/dashboard
# → {"overall_score_pct": <N>, "domains": { ... }}
```

**2. Secret resolved (key present):**

```bash
curl -sS -X POST https://<app-fqdn>/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"history":[{"role":"user","content":"score my program"}]}'
# Expected: {"reply": "...", "tool_log": [...]}
# FAILURE (secret NOT resolved): HTTP 500 {"error":"ANTHROPIC_API_KEY not set"}
```

A 500 `ANTHROPIC_API_KEY not set` means the Key Vault reference or the managed
identity's **Key Vault Secrets User** role assignment is misconfigured — not a
code issue.

**3. State write proven (status.json persisted):**

```bash
curl -sS https://<app-fqdn>/api/dashboard | grep -o 'overall_score_pct":[0-9]*'

curl -sS -X POST https://<app-fqdn>/api/mark \
  -H 'Content-Type: application/json' \
  -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"verified via ingress"}'

curl -sS https://<app-fqdn>/api/dashboard | grep -o 'overall_score_pct":[0-9]*'
```

A changed score (and persistence across a revision restart, with Azure Files
mounted) proves `status.json` is being written to the share.

---

## 8. Day-2 operations

- **Upgrades / releases:** push a new image tag to ACR and update the Container
  App (`az containerapp update --image ...`) — Container Apps creates a new
  revision and shifts traffic once the `/api/dashboard` probe passes.
- **Scaling caveat (important):** state is **local JSON**, not a shared DB. Keep
  **min = max = 1 replica** so a single writer owns `status.json`. Multiple
  replicas either race on the same Azure Files file or diverge on separate
  storage; horizontal scaling is **not** a supported correctness model. Scale
  vertically (CPU/memory) if needed.
- **Backups:** back up the **Azure Files share** (Azure Backup for Files, or a
  storage-account snapshot/copy of `status.json` + `settings.json`). Those two
  files are the entire recoverable state.
- **Secret rotation:** set a new secret version in Key Vault, then restart the
  revision so the reference re-resolves
  (`az containerapp revision restart ...`). No value is baked into the image, so
  no rebuild is needed.
- **Logs:** the Flask process logs to stdout/stderr → Container Apps log stream /
  Log Analytics. Watch for the 500 `ANTHROPIC_API_KEY not set` line.
- **Database migrations:** **none exist.** There is no database and no schema to
  migrate.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Revision unhealthy / not serving | Probe can't reach `/api/dashboard` on 5050 | Confirm `targetPort` 5050 equals `PORT`, health path is exactly `/api/dashboard`, ingress enabled |
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set` | Key Vault ref not resolving / RBAC missing | Verify managed identity has **Key Vault Secrets User** on the vault, secret name/URI correct, correct cloud DNS |
| Chat 500 but key IS set | Egress to `api.anthropic.com` blocked | Confirm outbound HTTPS allowed; in Government/CUI switch to on-prem Ollama ([AIRGAPPED.md](AIRGAPPED.md)) |
| Score resets after redeploy | No Azure Files mount; state on ephemeral storage | Mount the Files share at the app state path; back it up |
| Score inconsistent between requests | Multiple replicas with divergent local JSON | Set min = max = 1 replica |
| `AcrPull` / image pull failure | Managed identity lacks `AcrPull` | Assign `AcrPull` on the ACR to the app's identity |
| Government calls hit commercial endpoints | Wrong cloud / DNS | `az cloud set --name AzureUSGovernment`; use `*.vault.usgovcloudapi.net`, `login.microsoftonline.us`, `management.usgovcloudapi.net` |
| Key Vault ref shows literal `keyvaultref:` string | Reference not linked to an identity | Ensure `identityref:system` (or a user-assigned identity) is set on the secret |

---

*Return to the canonical deployment guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).*
