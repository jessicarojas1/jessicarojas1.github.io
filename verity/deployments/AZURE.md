# VERITY — Azure

Operator guidance for hosting the Verity Docker image on Azure. **No
Azure-specific files ship in this repo** — this is operator guidance for
deploying the existing `Dockerfile` to the targets below, not a description
of infrastructure-as-code already present.

## 1. Deployment architecture

The app is a stateless container (see the session-affinity caveat in
`KUBERNETES.md` §0 if you choose AKS with >1 replica) plus PostgreSQL.
Azure Container Apps is the simplest fit for this build's actual footprint
today (no queue, no worker, single container); AKS is the right choice only
if you already run Kubernetes elsewhere or need the scaling pattern in
`KUBERNETES.md`.

## 2. Topology

```
            Azure
  ┌─────────────────────────────────────────────────────────┐
  │ [Container Apps / AKS Ingress] ──TLS──> [verity container]│
  │                                              │             │
  │                                   [Managed Identity]       │
  │                                              │             │
  │                                   [Key Vault: DATABASE_URL]│
  │                                              │             │
  │                             [Azure Database for PostgreSQL]│
  └─────────────────────────────────────────────────────────┘
```

## 3. Prerequisites

- An Azure subscription (Commercial or Government — see §6) and the `az` CLI.
- Azure Container Apps environment, or an AKS cluster (follow
  `KUBERNETES.md` for the workload manifests once the cluster exists).
- Azure Database for PostgreSQL — Flexible Server, version 14+.
- Azure Key Vault for secrets, and a user-assigned (or system-assigned)
  managed identity for the container.
- The Verity image pushed to Azure Container Registry (or any registry the
  environment can pull from).

## 4. Identity & credentials

Prefer **managed identity over a static connection string** wherever the
target supports it:

1. Create a user-assigned managed identity and assign it to the Container
   App (or AKS pod via Azure AD Workload Identity).
2. Grant that identity `Key Vault Secrets User` on the vault holding
   `DATABASE_URL`.
3. Mount the secret into the container's environment via Container Apps'
   Key Vault secret reference (or the Secrets Store CSI driver on AKS) —
   the app itself only ever reads `DATABASE_URL` from its process
   environment (`app/Support/Config.php`); it has no Key Vault SDK
   integration of its own, so the platform must inject the resolved value.
4. If Azure Database for PostgreSQL Flexible Server is configured for Azure
   AD authentication, you can avoid a static database password entirely by
   having the identity fetch a short-lived AAD token as the Postgres
   password at container start — this requires a small startup wrapper
   script since the app does not do this itself today; a static
   least-privilege database role + Key Vault secret is the simpler default.

There is no Entra ID app registration to create for *this build* — local
email/password is the only working sign-in method (`/auth/sso` returns 503;
see `LOCAL_DEVELOPMENT.md` §4). Do not provision an Entra SSO app registration
expecting it to do anything yet.

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Runtime mode |
| `PORT` | `8080` | Container listen port (Container Apps sets this automatically; keep it aligned with the Dockerfile's `EXPOSE 8080`) |
| `DATABASE_URL` | Key Vault reference, e.g. `@Microsoft.KeyVault(...)` resolving to `postgresql://verity_app:***@<server>.postgres.database.azure.com:5432/verity?sslmode=require` | PDO Postgres DSN — inject via managed identity + Key Vault, not as plaintext |

## 6. Azure Commercial vs. Azure Government

This is the one place the existing code is already Government-shaped, even
though nothing calls out to Azure/Entra yet: `app/Support/Config.php`
defaults `GRAPH_BASE_URL`, `ENTRA_AUTHORITY_HOST`, and `AZURE_PORTAL_URL` to
the **`.us` (Azure Government / GCC High) endpoints**, by design, so the
not-yet-built connector framework never has a commercial endpoint to fall
back to by accident. Those variables currently have no runtime effect — no
code path calls Graph or Entra yet.

| Concern | Azure Commercial | Azure Government |
|---------|-------------------|-------------------|
| This build (Phases 1-3, local auth only, no CUI data) | **Works fine today** — plain PHP/Postgres, nothing in the app is region-restricted | Also works, but adds no benefit yet since there's no live GCC High integration to colocate with |
| Future GCC High connector (Phase 4) | Not applicable — GCC High is a Government-cloud-only Microsoft 365/Entra tenant | **The natural home** once a real connector is built, since the app's defaults already point at `.us` endpoints |
| Resource naming | `<resource>.postgres.database.azure.com`, `login.microsoftonline.com` | `<resource>.postgres.database.usgovcloudapi.net`, `login.microsoftonline.us` |
| Compliance posture | No CUI/FCI/regulated-data claim is made for this build | Choose this only once a specific compliance requirement (e.g. real GCC High data) exists — do not provision it speculatively |

**Recommendation:** deploy to Azure Commercial for this build's current
phase (Phases 1-3; no CUI data, local-auth-only). Revisit Azure Government
only when the GCC High connector (Phase 4) is actually implemented and real
regulated data is in scope — see `render.yaml`'s own boundary comment, which
states the same thing for the Render deployment target.

## 7. Verification

```bash
curl -fsS https://<app-fqdn>/health
# {"status":"ok","app":"verity","db":true}
```
- Confirm the Container App/pod's environment shows `DATABASE_URL` resolved
  (not a literal Key Vault reference string) via `az containerapp show` or
  `kubectl exec ... env`.
- Sign in at `/auth/login`; confirm dashboard KPIs render.
- Confirm no plaintext database password appears in the Container App
  revision definition or any ConfigMap.

## 8. Day-2 operations

- Rotate the Postgres role password (or AAD token lifetime) on a schedule;
  Key Vault versioning lets you roll forward without redeploying the image.
- Azure Database for PostgreSQL automated backups; test a restore
  periodically (point-in-time restore is built into Flexible Server).
- Monitor Container Apps/AKS logs for `password_hash`/auth failures and for
  PDO connection errors.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Container fails to start, `/health` unreachable | Key Vault reference not resolving | Confirm the managed identity has `Key Vault Secrets User` and the reference syntax matches the Container Apps secret format |
| `/health` returns `"db":false` | `DATABASE_URL` env var not actually injected | Check the Container App's secret/env binding, not just the Key Vault entry |
| Postgres connection refused | Flexible Server firewall/VNet rule blocks the Container Apps environment | Add the Container Apps environment's outbound IP range or use VNet integration |
| Using commercial endpoints when Government was intended | `GRAPH_BASE_URL`/`ENTRA_AUTHORITY_HOST` left unset and defaults silently apply | Defaults are already `.us` — this only matters once a real connector consumes them; otherwise harmless |
