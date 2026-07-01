# Azure — Compliance Copilot (Commercial + Azure Government)

Operator guide for deploying **Compliance Copilot** to **Azure Commercial** and **Azure
Government**. Compliance Copilot is a stateless **Next.js 14/16** app (`next start`, port 3000)
whose persistent state lives in **Supabase**. Two hosting shapes are covered: **Azure Container
Apps / App Service** (simplest) and **AKS** (see [KUBERNETES.md](./KUBERNETES.md) for manifest
detail). Secrets come from **Azure Key Vault** via **Managed Identity**; login can front on
**Entra ID** at the ingress layer.

> **CUI / data-residency note (read first):** Compliance Copilot supports CMMC L2 / NIST
> 800-171 programs whose data is frequently **CUI**. Hosted Supabase SaaS is **not** FedRAMP/IL
> authorized and stores data outside Azure Government boundaries — **do not** place CUI in hosted
> Supabase for a gov workload. For Azure Government, **self-host Supabase** inside the gov tenant
> (Container Apps/AKS + **Azure Database for PostgreSQL Flexible Server** + Blob-backed storage)
> so all CUI stays within the authorized boundary. See §5 for the split.

> Cross-links: [AWS.md](./AWS.md) · [KUBERNETES.md](./KUBERNETES.md) ·
> [AIRGAPPED.md](./AIRGAPPED.md) · [SINGLE_LINUX_SERVER.md](./SINGLE_LINUX_SERVER.md)

---

## 1. Deployment architecture

| Layer | Commercial | Azure Government |
|---|---|---|
| Compute | Container Apps / App Service (Linux) **or** AKS | Same, in an `usgovvirginia`/`usgovtexas` region |
| Data | Supabase SaaS **or** self-hosted | **Self-hosted Supabase** (Postgres Flexible Server + Blob) — CUI stays in-boundary |
| Secrets | Azure Key Vault + Managed Identity | Key Vault (Government) + Managed Identity |
| Identity (optional SSO) | Entra ID (Easy Auth / APIM) | Entra ID Government |
| AI | Anthropic API **or** self-hosted Ollama (see [AIRGAPPED.md](./AIRGAPPED.md)) | Self-hosted Ollama recommended (no egress to hosted AI) |

The app container listens on `:3000`; Container Apps/App Service front it with managed TLS.

---

## 2. Topology

```
        Entra ID (optional SSO) ─┐
                                 ▼
   Client ──443──► Front Door / App GW ──► Container App / App Service (Next.js :3000)
                                                    │  Managed Identity
                                                    ├───────────────► Key Vault (secrets)
                                                    │
                                                    ▼
                            ┌──────────────────────────────────────────┐
        Commercial:         │ Supabase SaaS  OR  self-hosted Supabase   │
        Government:         │ self-hosted Supabase in gov tenant:       │
                            │   Postgres Flexible Server + Blob Storage │
                            └──────────────────────────────────────────┘
                                                    │
                        AI relay egress 443 ────────┴──► Anthropic (commercial) / Ollama (gov)
```

---

## 3. Prerequisites

| Item | Detail |
|---|---|
| Azure subscription | Commercial or Government; Owner/Contributor + User Access Admin on the RG |
| Azure CLI | `az` latest; for gov: `az cloud set --name AzureUSGovernment` |
| Container registry | Azure Container Registry (ACR) in the same cloud |
| Image | built from repo `Dockerfile`, pushed to ACR |
| Key Vault | one per environment |
| PostgreSQL | Azure Database for PostgreSQL Flexible Server (if self-hosting Supabase) |
| Storage | Azure Blob (evidence) when self-hosting Supabase storage |

---

## 4. Identity & credentials

Use a **user-assigned Managed Identity** on the Container App/App Service (or **AKS Workload
Identity**) — no static keys in app config. Grant it Key Vault access:

```bash
# Government: az cloud set --name AzureUSGovernment  (first)
az identity create -g rg-grc -n id-compliance-copilot
PRINCIPAL=$(az identity show -g rg-grc -n id-compliance-copilot --query principalId -o tsv)

az role assignment create --assignee "$PRINCIPAL" \
  --role "Key Vault Secrets User" \
  --scope $(az keyvault show -n kv-grc --query id -o tsv)
```

Least-privilege: the identity gets **Key Vault Secrets User** (get/list secrets) only — not
purge/set. Reference secrets in Container Apps via `secretRef` bound to Key Vault, or in App
Service via `@Microsoft.KeyVault(...)` references. The Supabase **service role key** and
**ANTHROPIC_API_KEY** live only in Key Vault.

---

## 5. Environment variables

Where Commercial vs Government differ, the difference is the **Supabase endpoint** (SaaS vs
self-hosted in-boundary) and the **AI upstream** (Anthropic vs Ollama). The variable *names* are
identical.

| Variable | Example (Commercial) | Example (Government) | Purpose |
|---|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://abcd.supabase.co` | `https://supabase.grc.usgov.internal` | Supabase URL (SaaS vs self-hosted in gov) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | `eyJhbGci...` (self-hosted anon) | Public anon key |
| `SUPABASE_SERVICE_ROLE_KEY` | *(Key Vault)* | *(Key Vault, self-hosted)* | Service role key (server-only) |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | *(usually unset — use Ollama)* | Hosted AI upstream |
| `AI_PROXY_TOKEN` | *(Key Vault)* | *(Key Vault)* | Gate `/api/ai/generate` (required in prod) |
| `APP_SESSION_SECRET` | *(Key Vault)* | *(Key Vault)* | HMAC signs `cc_session` (≥16 chars) |
| `APP_AUTH_USERNAME` | `issoadmin` | `issoadmin` | Login username |
| `APP_AUTH_PASSWORD` | *(Key Vault)* | *(Key Vault)* | Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | `evidence-files` | Storage bucket |
| `BRANDING_ADMIN_TOKEN` | *(Key Vault)* | *(Key Vault)* | Gates branding write |
| `NODE_ENV` | `production` | `production` | Secure cookies + fail-closed relay |
| `PORT` | `3000` | `3000` | Listen port |
| `HOSTNAME` | `0.0.0.0` | `0.0.0.0` | Bind all interfaces |

> Government note: keep the app in an `usgov*` region, use the Key Vault **Government**
> endpoints (`*.vault.usgovcloudapi.net`), and ensure Postgres Flexible Server + Blob are in the
> same gov region so CUI never leaves the boundary. Do **not** point `NEXT_PUBLIC_SUPABASE_URL`
> at hosted `*.supabase.co` for CUI data.

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| Ingress health path | `/` | Container Apps/App Service health probe (dashboard = health surface) |
| `WEBSITES_PORT` (App Service) | `3000` | Tell App Service which port the container listens on |
| Container Apps `targetPort` | `3000` | Ingress target |
| `next.config.js` `remotePatterns` | `*.supabase.co` (commercial) / self-host host | Allowed image hosts |
| Entra ID (optional) | Easy Auth on App Service | SSO in front of the app's own session login |

---

## 7. Verification

```bash
# Provision Postgres (self-hosted Supabase / gov) and apply schema
psql "$SUPABASE_DB_URL" -f supabase/schema.sql
# Create bucket 'evidence-files' (Supabase Studio for self-host, or Storage UI)

APP=https://compliance-copilot.<region>.azurecontainerapps.io   # or *.azurewebsites.us in gov

# Health / homepage
curl -sI $APP/ | head -1                                        # 200

# Secrets resolved from Key Vault → login works
curl -s -X POST $APP/api/auth/login -H 'Content-Type: application/json' \
  -d '{"username":"issoadmin","password":"<pw>"}'               # {"ok":true}

# AI relay authorized (token from Key Vault)
curl -s -X POST $APP/api/ai/generate -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $AI_PROXY_TOKEN" \
  -d '{"prompt":"Generate a POA&M item for 3.3.1"}'             # {"text":"..."}

# DB row + storage object after Evidence upload
psql "$SUPABASE_DB_URL" -c "select count(*) from controls;"
psql "$SUPABASE_DB_URL" -c "select file_name,file_url from evidence order by created_at desc limit 1;"
```

Confirm the Managed Identity resolved secrets: App Service → **Configuration** shows Key Vault
references as `Resolved`; Container Apps → revision secrets bound without errors.

---

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade | Push new image to ACR; `az containerapp update --image <acr>/compliance-copilot:<tag>` (new revision, rolling) |
| DB migration | Re-run `supabase/schema.sql` (idempotent) against Flexible Server via `psql` |
| Scale | Container Apps scale rules (HTTP concurrency) / App Service plan tier; AKS via HPA |
| Backups | Postgres Flexible Server automated backups + PITR; Blob soft-delete + geo-redundancy |
| Secret rotation | Update Key Vault secret version; restart revision to pick up (rotating `APP_SESSION_SECRET` logs users out) |
| Cert rotation | Managed certificate auto-renew on Container Apps/App Service |
| Logs | Log Analytics workspace; `az containerapp logs show` |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Container starts then app 500s | Key Vault reference unresolved (identity missing role) | Grant **Key Vault Secrets User** to the managed identity |
| App Service 503 / no response | `WEBSITES_PORT` not 3000 | Set `WEBSITES_PORT=3000` |
| Gov deploy reaches hosted Supabase | Wrong `NEXT_PUBLIC_SUPABASE_URL` | Point at in-boundary self-hosted Supabase; keep CUI in gov region |
| AI relay 503 | Prod + no `AI_PROXY_TOKEN` | Bind token from Key Vault; or use Ollama upstream in gov |
| Wrong cloud endpoints | CLI targeting Commercial in gov | `az cloud set --name AzureUSGovernment` before `az login` |
| Evidence upload fails | Bucket/container missing | Create `evidence-files` in the storage backend |
