# REDOUBT — On-Prem Single Linux Server (Production)

Operator guide for running REDOUBT on a **single hardened Linux host inside
GMRE's on-prem CUI boundary**, connecting to **Microsoft 365 / Azure GCC High**
for identity and documents. For HA, see `KUBERNETES.md`; for the M365 side, see
`AZURE.md`.

> Boundary note: this host is part of GMRE's **customer-owned CUI enclave** and
> must meet NIST SP 800-171 / CMMC (physical, network, boundary protection, FIPS)
> — that accreditation is not inherited from Microsoft. See `../docs/SECURITY.md`.

## 1. Deployment architecture

A single Linux VM/host runs the REDOUBT container plus PostgreSQL, behind a TLS
reverse proxy. The app reaches GCC High (`graph.microsoft.us`,
`login.microsoftonline.us`) for identity and SharePoint documents. No controlled
document bytes are stored on the host — only config, portal-owned content,
metadata/references, and audit.

## 2. Topology

```
                 GMRE on-prem CUI enclave (800-171/CMMC)
  ┌───────────────────────────────────────────────────────────┐
  │  [reverse proxy: TLS/HSTS/WAF] ──> [REDOUBT container :8080]│
  │                                        │                    │
  │                                        ├─> [PostgreSQL]     │
  │                                        └─> [secret store]   │
  └───────────────────────────┬───────────────────────────────┘
                              │ HTTPS (ExpressRoute or Gov egress)
                              ▼
             Microsoft 365 / Azure GCC High (cloud SoR)
        Entra ID (login.microsoftonline.us) · Graph (graph.microsoft.us)
                     · SharePoint Online (*.sharepoint.us)
```

## 3. Prerequisites

- Hardened Linux host (e.g., RHEL/Ubuntu) inside the CUI enclave, FIPS mode enabled.
- Docker Engine (or Podman) + a reverse proxy (nginx/Apache/Caddy) with a trusted TLS cert.
- PostgreSQL 14+ (local service or a separate enclave DB host).
- Outbound HTTPS to the GCC High endpoints (`*.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us`) allowlisted.
- A completed Entra GCC High app registration (see `AZURE.md`).

## 4. Identity & credentials

- The app authenticates users via **Entra ID GCC High (OIDC)** and calls Graph
  with the app registration's client credentials.
- **Prefer a managed/enclave secret store** (e.g., HashiCorp Vault, or the host's
  protected secret file with least-privilege perms) over plaintext env files.
- Client secret / certificate is provisioned per `AZURE.md`; rotate on schedule.
- Least privilege: grant only the Graph application permissions actually needed
  (e.g., `Sites.Selected` scoped to the program sites, not tenant-wide).

## 5. Environment variables (GCC High)

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Runtime mode |
| `PORT` | `8080` | Container listen port |
| `DATABASE_URL` | `postgres://redoubt:***@127.0.0.1:5432/redoubt` | Portal DB |
| `MS_CLOUD` | `USGovernment` | Selects GCC High national cloud |
| `ENTRA_AUTHORITY_HOST` | `https://login.microsoftonline.us` | Auth authority (NOT .com) |
| `GRAPH_BASE_URL` | `https://graph.microsoft.us` | Graph endpoint (NOT .com) |
| `ENTRA_TENANT_ID` | GUID | GCC High tenant |
| `ENTRA_CLIENT_ID` | GUID | App registration |
| `ENTRA_CLIENT_SECRET` | (secret) | OIDC/Graph credential — from secret store |
| `GRAPH_SCOPES` | `https://graph.microsoft.us/.default` | Graph resource scope |

> Commercial `.com` endpoints do **not** work against GCC High.

## 6. Configuration references

- Routing/headers: `../public/index.php`. Schema: `../database/schema.sql`.
- Reverse proxy must forward `X-Forwarded-Proto: https` so the app sets HSTS.

## 7. Verification

```bash
# App is up
curl -fsS https://<host>/health            # {"status":"ok",...}
```
- Sign in via Entra GCC High; confirm MFA is enforced.
- Confirm secrets resolve (no plaintext in process env dumps).
- Open a document — confirm it streams from SharePoint GCC High (not a local copy).
- Confirm an audit row is written for the access.

## 8. Day-2 operations

- Patch the host and rebuild/repull the image on a cadence; keep FIPS mode on.
- Back up PostgreSQL (PITR); test restore quarterly (`../docs/DISASTER_RECOVERY.md`).
- Rotate the Entra client secret/cert; run quarterly access reviews.
- Monitor auth failures and Graph throttling (HTTP 429) with backoff.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Login loops / AADSTS error | Commercial endpoint used | Set `.us` authority + Graph base URL |
| 403 from Graph | Missing/over-broad app permission | Grant least-privilege `Sites.Selected` and admin-consent |
| 429 from Graph | Throttling | Exponential backoff + cache tokens |
| HSTS not applied | Proxy not forwarding proto | Send `X-Forwarded-Proto: https` |
| Document won't open | Site permission / endpoint | Verify site grant + `.us` SharePoint host |
