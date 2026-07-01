# Azure Deployment — Business Insight Dashboard

Operator guide for running the **Business Insight Dashboard** on Azure, covering
both **Azure Commercial** and **Azure Government** (US Gov / `azure.us`).

The app is a **Streamlit** application (Python 3.11.9). It has **no external
database**, no built-in authentication, and **makes no AI/LLM calls**. Uploaded
CSVs are parsed with pandas **in memory only** and are never persisted or
transmitted. The **only** persisted state is `branding.json` (display name,
accent color, logo) written next to `app.py`.

> Streamlit talks to the browser over **WebSockets**. Any App Gateway / Front
> Door / ingress in front of the app **must** enable **session affinity (sticky
> sessions)**, allow WebSocket upgrades, and use a generous idle timeout. This is
> the most common production failure mode — see [Troubleshooting](#9-troubleshooting).

Sibling guides: [AWS.md](./AWS.md) · [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

Two supported topologies. Pick one:

| Option | Compute | Front end | Image | Persistence | Auth |
|--------|---------|-----------|-------|-------------|------|
| **A. App Service (Linux container)** (recommended default) | Web App for Containers | App Service built-in HTTPS + optional App Gateway/Front Door | ACR | **Azure Files** share mounted for `branding.json` | **Easy Auth** (App Service Authentication / Entra ID) |
| **B. AKS** | Kubernetes Deployment | Application Gateway Ingress (AGIC) or NGINX Ingress | ACR | Azure Files CSI PVC (`ReadWriteMany`) | oauth2-proxy / Entra ID via ingress |

Key architectural facts:

- **Container:** `python:3.11-slim`, non-root, `EXPOSE 8501`, `HEALTHCHECK` on
  `/_stcore/health`, CMD runs
  `streamlit run app.py --server.port $PORT --server.address 0.0.0.0 --server.headless true`.
- **Port:** App Service injects `WEBSITES_PORT`; set it to `8501` (or set
  `STREAMLIT_SERVER_PORT` to match). AKS maps the Service to container port 8501.
- **Health:** `GET /_stcore/health` returns `ok` — use it for the App Service
  health check and the AKS liveness/readiness probes.
- **State:** ephemeral by design. The **only** durable artifact is
  `branding.json`. Mount an **Azure Files** share (App Service) or an
  **Azure Files CSI PVC** (AKS) and point `BRANDING_FILE` at it so branding
  survives restarts and is shared across instances.
- **No database, no secrets by default.** Azure Key Vault is needed **only** for
  the reverse-proxy authentication layer (the Entra ID OIDC client secret) — not
  for the app itself.

### Commercial vs Government at a glance

| Concern | Azure Commercial | Azure Government |
|---------|------------------|------------------|
| Portal | `portal.azure.com` | `portal.azure.us` |
| Entra ID (login) authority | `https://login.microsoftonline.com` | `https://login.microsoftonline.us` |
| Resource Manager | `management.azure.com` | `management.usgovcloudapi.net` |
| ACR login server | `<name>.azurecr.io` | `<name>.azurecr.us` |
| Key Vault DNS | `<name>.vault.azure.net` | `<name>.vault.usgovcloudapi.net` |
| Storage (Azure Files) suffix | `*.file.core.windows.net` | `*.file.core.usgovcloudapi.net` |
| App Service default host | `*.azurewebsites.net` | `*.azurewebsites.us` |
| Azure Monitor / Log Analytics | Commercial endpoints | Gov (`*.usgovcloudapi.net`) endpoints |

> When targeting Government, set the CLI cloud (`az cloud set --name AzureUSGovernment`)
> and use the `login.microsoftonline.us` authority in the auth proxy / Easy Auth
> configuration.

---

## 2. Topology

```
                             ┌────────────────────────────────────────┐
   User (browser)            │              Azure region              │
        │ HTTPS/WSS          │                                        │
        ▼                    │  ┌────────────────┐   ┌─────────────┐  │
  ┌────────────┐  Entra ID   │  │ App Gateway /  │   │ Azure Files │  │
  │ Entra ID   │◄────────────┼─►│ Front Door     │   │ branding.json│ │
  │ (OIDC)     │  Easy Auth  │  │ - HTTPS/WSS    │   └──────▲──────┘  │
  └────────────┘             │  │ - sticky (aff) │         │ SMB/CSI  │
                             │  │ - WS upgrade   │  ┌──────┴───────┐  │
                             │  └───────┬────────┘  │ App Service  │  │
                             │          │ 8501      │ (Linux cont) │  │
                             │          └──────────►│ Streamlit    │  │
                             │                      │ app.py :8501 │  │
                             │  ┌────────────────┐  └──────────────┘  │
                             │  │ Key Vault      │   image ◄── ACR    │
                             │  │ (OIDC secret)  │   via Managed ID   │
                             │  └────────────────┘                    │
                             └────────────────────────────────────────┘
```

AKS variant: replace "App Service" with a Deployment behind AGIC/NGINX ingress;
replace the Azure Files SMB mount with an **Azure Files CSI PVC**
(`ReadWriteMany`); identity comes from **Workload Identity** (federated Entra ID)
or a **Managed Identity** on the node pool.

---

## 3. Prerequisites

- Azure subscription in the target cloud (Commercial or Government). Government
  requires an Azure Government subscription and `az cloud set --name AzureUSGovernment`.
- Azure CLI configured for the target cloud.
- **ACR** registry (`.azurecr.io` / `.azurecr.us`) for the image.
- **App Service**: a Linux **App Service Plan** + Web App for Containers, and a
  **Storage account** with a file share for `branding.json`.
- **AKS**: a cluster (1.28+), the **Azure Files CSI driver**, and
  **Workload Identity** enabled for federated pod identity.
- A **Managed Identity** (system- or user-assigned) for ACR pull and Key Vault
  read.
- (Auth) An **Entra ID app registration** for Easy Auth / oauth2-proxy, with the
  correct authority (`login.microsoftonline.com` vs `login.microsoftonline.us`).

---

## 4. Identity & credentials

**Prefer Managed Identity / Workload Identity. Avoid client secrets and static
keys wherever possible.**

| Topology | Identity mechanism |
|----------|--------------------|
| App Service | **System-assigned Managed Identity** for ACR pull + Key Vault read |
| AKS | **Workload Identity** (federated Entra ID) bound to the pod service account, or a user-assigned Managed Identity |
| CI/build | Entra ID **workload federation** (OIDC) from the pipeline → short-lived token; avoid service-principal secrets |

The application itself needs **no Azure permissions** — no database, no storage
SDK calls, no secret fetch. Identity is purely operational:

- **ACR pull:** grant the Managed Identity the **AcrPull** role on the registry.
- **Key Vault read (auth only):** grant **Key Vault Secrets User** on the single
  OIDC client secret, only if the proxy reads it from Key Vault.
- **Azure Files:** mounted via storage-account key or (preferred) identity-based
  SMB; restrict the storage account to the app's VNet with a private endpoint.

### Least-privilege role assignments

```bash
# ACR pull for the app's managed identity
az role assignment create \
  --assignee-object-id <mi-principal-id> --assignee-principal-type ServicePrincipal \
  --role "AcrPull" \
  --scope /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.ContainerRegistry/registries/<acr>

# Key Vault read for ONLY the OIDC client secret (auth proxy only)
az role assignment create \
  --assignee-object-id <mi-principal-id> --assignee-principal-type ServicePrincipal \
  --role "Key Vault Secrets User" \
  --scope /subscriptions/<sub>/resourceGroups/<rg>/providers/Microsoft.KeyVault/vaults/<kv>/secrets/bid-oidc-client-secret
```

> If auth runs entirely in **Easy Auth** (App Service Authentication), the app
> container needs **no Key Vault access at all** — Easy Auth manages the token
> exchange and stores its secret in the platform.

**Client-secret fallback (discouraged):** only if federation is impossible, store
the Entra ID app **client secret** in **Key Vault** and reference it from the
proxy via a Key Vault reference (`@Microsoft.KeyVault(...)`). Never place it in
app settings in plaintext or in `render.yaml`. Rotate on a schedule.

---

## 5. Environment variables

The app's own configuration surface is small. Values that differ between clouds
apply to the **surrounding infrastructure / auth**, not the app.

| Variable | Example (Commercial) | Example (Government) | Purpose |
|----------|----------------------|----------------------|---------|
| `WEBSITES_PORT` / `PORT` / `STREAMLIT_SERVER_PORT` | `8501` | `8501` | Port Streamlit binds. App Service uses `WEBSITES_PORT`; set all to the same value. |
| `STREAMLIT_SERVER_ADDRESS` | `0.0.0.0` | `0.0.0.0` | Bind all interfaces so the container is reachable. |
| `STREAMLIT_SERVER_HEADLESS` | `true` | `true` | Disable browser prompt; required in containers. |
| `STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION` | `true` | `true` | Keep Streamlit XSRF on. |
| `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` | `50` | `50` | Max CSV upload size (MB). |
| `STREAMLIT_BROWSER_GATHER_USAGE_STATS` | `false` | `false` | Disable telemetry (mandatory for Gov / no-egress). |
| `BRANDING_FILE` | `/mnt/branding/branding.json` | `/mnt/branding/branding.json` | Writable, Azure Files-mounted path for persisted branding. |

Auth / infrastructure variables (only when authentication is enabled):

| Variable | Commercial | Government | Purpose |
|----------|------------|------------|---------|
| Entra ID authority | `https://login.microsoftonline.com/<tenant>` | `https://login.microsoftonline.us/<tenant>` | OIDC authority for Easy Auth / oauth2-proxy. |
| Key Vault URI | `https://<kv>.vault.azure.net` | `https://<kv>.vault.usgovcloudapi.net` | Where the OIDC client secret lives. |
| ACR login server | `<acr>.azurecr.io` | `<acr>.azurecr.us` | Registry the image is pulled from. |
| OIDC client secret ref | `@Microsoft.KeyVault(SecretUri=https://<kv>.vault.azure.net/secrets/bid-oidc-client-secret)` | `@Microsoft.KeyVault(SecretUri=https://<kv>.vault.usgovcloudapi.net/secrets/bid-oidc-client-secret)` | Proxy secret via Key Vault reference. |

---

## 6. Configuration references — Streamlit `config.toml`

Ship `.streamlit/config.toml` in the image (or mount it). Env vars above
override these at runtime.

```toml
[server]
port = 8501
address = "0.0.0.0"
headless = true
enableXsrfProtection = true
enableCORS = false            # App Gateway / App Service fronts the origin
maxUploadSize = 50            # MB; matches STREAMLIT_SERVER_MAX_UPLOAD_SIZE

[browser]
gatherUsageStats = false      # no telemetry egress

[global]
developmentMode = false
```

| Key | Value | Purpose |
|-----|-------|---------|
| `server.port` | `8501` | Container listen port; align with `WEBSITES_PORT`. |
| `server.address` | `0.0.0.0` | Reachable inside the app/pod network. |
| `server.headless` | `true` | Container-safe startup. |
| `server.enableXsrfProtection` | `true` | CSRF protection for the WS/session. |
| `server.enableCORS` | `false` | Single trusted origin fronts the app. |
| `server.maxUploadSize` | `50` | Guardrail on in-memory CSV size. |
| `browser.gatherUsageStats` | `false` | No outbound analytics. |

---

## 7. Verification

Run after each deploy (Commercial or Government):

```bash
# 1. Health endpoint returns "ok"
curl -fsS https://<host>/_stcore/health        # -> ok
#   Commercial host: <app>.azurewebsites.net ; Gov host: <app>.azurewebsites.us
```

Manual checklist:

- [ ] `curl -fsS https://<host>/_stcore/health` prints `ok`.
- [ ] Login **through Easy Auth / the proxy** completes (Entra ID redirect on
      the correct authority).
- [ ] Dashboard opens over **HTTPS/WSS** with no WebSocket console errors.
- [ ] Upload a sample CSV from `sample_data/` — file parses in memory.
- [ ] **KPIs, charts, and rule-based insights render** for the uploaded data.
- [ ] Change branding → confirm `branding.json` is **written to the Azure Files
      mount** (`ls -l $BRANDING_FILE` in the container/pod).
- [ ] App Service **health check** (or AKS readiness probe) reports **healthy**.

---

## 8. Day-2 operations

- **Upgrades:** build and push a new immutable image tag to ACR; update the Web
  App container image (or AKS Deployment) → **rolling deploy**. Use App Service
  **deployment slots** for zero-downtime swaps; keep affinity enabled during the
  swap.
- **Scaling:** scale out instances freely — the app is stateless except
  `branding.json`. **Session affinity (ARR affinity / App Gateway cookie
  affinity) is mandatory** so a user's WebSocket stays pinned. Azure Files gives
  shared branding across instances.
- **Backups:** the only durable state is `branding.json` on Azure Files. Enable
  **Azure Backup for Azure Files** (share snapshots). No database to back up;
  uploaded CSVs are ephemeral.
- **Cert/secret rotation:** App Service manages TLS certs (managed cert or your
  own in Key Vault); rotate the Entra ID **client secret** in Key Vault and let
  the Key Vault reference refresh, or restart the proxy.
- **Logs:** send container stdout/stderr and platform logs to **Azure Monitor /
  Log Analytics** (App Service Diagnostic Settings, or AKS Container Insights).
  Alarm on restarts and unhealthy instances.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Dashboard connects then disconnects / stuck spinner | App Gateway / ARR **affinity off**, or short idle timeout | Enable **ARR affinity** (App Service) or App Gateway **cookie-based affinity**; raise the **idle timeout**; allow WebSocket upgrades. |
| Health check 404 | Wrong probe path | Point the App Service health check / AKS probe at exactly `/_stcore/health`. |
| Instance/pod stuck **unhealthy** | Wrong port or path, or slow boot | Ensure `WEBSITES_PORT`/probe port is **8501**, path `/_stcore/health`; increase initial-delay/grace period. |
| Branding resets after restart / scale | `branding.json` on **ephemeral** container storage | Mount **Azure Files** (App Service) or **Azure Files CSI PVC** `ReadWriteMany` (AKS); set `BRANDING_FILE` to that path. |
| Upload rejected as too large | `maxUploadSize` too low | Raise `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` / `server.maxUploadSize`. |
| Login fails in Government cloud | Using `login.microsoftonline.com` authority | Use `login.microsoftonline.us` authority and Gov endpoints in Easy Auth / proxy. |
| Image pull fails | Managed Identity missing **AcrPull**, or wrong login server | Assign **AcrPull**; use `.azurecr.io` (Commercial) or `.azurecr.us` (Gov). |
| Key Vault reference unresolved | Managed Identity lacks **Key Vault Secrets User**, or wrong vault DNS | Grant the role; use `vault.azure.net` (Commercial) or `vault.usgovcloudapi.net` (Gov). |

---

*See also: [AWS.md](./AWS.md), [AIRGAPPED.md](./AIRGAPPED.md).*
