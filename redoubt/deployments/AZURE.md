# REDOUBT — Microsoft 365 / Azure GCC High Configuration

This guide covers **(A)** the **Microsoft 365 / Azure GCC High** identity and
document back end that *every* hosting target depends on, and **(B)** hosting the
app on **Azure Government (AKS)** when Azure Gov is the chosen boundary (Part B,
below). For on-prem or AWS GovCloud hosting see `KUBERNETES.md` + `AWS.md`.

> Why GCC High: CUI/ITAR are in scope. GCC High is the authorized M365 tenant for
> DoD/ITAR workloads and uses distinct endpoints from commercial.

This guide has two parts: **(A)** the M365 GCC High back end (required for *every*
hosting target — on-prem, Azure Gov, AWS GovCloud), and **(B)** hosting the app
itself on **Azure Government (AKS)** when Azure Gov is the chosen boundary.

## 1. Architecture (cloud side)

- **Entra ID GCC High** — user identity, SSO (OIDC), MFA, B2B guests, Conditional Access.
- **SharePoint Online GCC High** — authoritative document store; one **site
  collection per program**, one **document library per company** for isolation.
- **Microsoft Graph (GCC High)** — the API the app calls to browse/search
  documents and read identity/group claims.

## 2. Topology

```
On-prem REDOUBT app ──HTTPS──> login.microsoftonline.us   (OIDC + token)
                     ──HTTPS──> graph.microsoft.us         (Graph API)
                                     │
                                     ▼
                         SharePoint Online (*.sharepoint.us)
                         hub site ─┬─ Program A site ─ per-company libraries
                                   └─ Program B site ─ per-company libraries
```

## 3. Prerequisites

- A Microsoft 365 **GCC High** tenant + Global/Entra admin for setup.
- SharePoint admin for site provisioning.
- Purview enabled for CUI sensitivity labels (recommended).

## 4. Identity & credentials (app registration)

1. In **Entra ID GCC High**, register an application (single tenant).
2. Add a **Web** redirect URI to the on-prem app's `.../auth/callback` (HTTPS).
3. Create a **client secret** or (preferred) upload a **certificate** for app auth.
4. **API permissions (least privilege):**
   - Delegated: `openid`, `profile`, `email`, `User.Read` (sign-in).
   - Application: prefer **`Sites.Selected`** and grant per program site (avoid
     tenant-wide `Sites.Read.All`/`Files.Read.All` unless justified).
   - Grant **admin consent**.
5. Enforce **MFA + Conditional Access** for internal and B2B guest users.
6. Record `Tenant ID`, `Client ID`, and the secret/cert for the on-prem secret store.

## 5. Endpoints (GCC High vs Commercial)

| Purpose | GCC High (use these) | Commercial (do NOT use) |
|---------|----------------------|--------------------------|
| Auth authority | `https://login.microsoftonline.us` | `https://login.microsoftonline.com` |
| Microsoft Graph | `https://graph.microsoft.us` | `https://graph.microsoft.com` |
| SharePoint | `https://<tenant>.sharepoint.us` | `https://<tenant>.sharepoint.com` |
| National cloud id | `USGovernment` | `AzurePublic` |

## 6. Configuration references (SharePoint layout)

- **Program isolation:** separate **site collection per program** (hub-and-spoke:
  a hub site with per-program spokes).
- **Company isolation:** separate **document library + Entra security group per
  company**; grant the library to the group. **Do not** use per-item ACLs.
- **Zones:** Project / Subcontractor-shared / Customer(COR) / Contracts /
  Financial as libraries or folders with distinct group grants.
- **CUI/ITAR:** apply Purview sensitivity labels; the on-prem app additionally
  enforces the US-person gate on export-controlled zones.

## 7. Verification

- App can obtain a token from `login.microsoftonline.us` for the tenant.
- Graph call to `graph.microsoft.us/v1.0/sites/...` returns the program site.
- A test user in a company group sees only that company's library; a user outside
  it receives 403 (isolation proven).
- Sign-in requires MFA; Conditional Access applies to a B2B guest.

## 8. Day-2 operations

- Rotate the app secret/cert on schedule; review API permissions periodically.
- Quarterly access reviews on program/company groups and guest accounts.
- Monitor Entra sign-in logs + M365 unified audit log alongside the app audit.

## 9. Part B — Hosting the app on Azure Government (AKS)

When Azure Gov is the chosen boundary, run the REDOUBT container on **AKS in
Azure Government** (follow `KUBERNETES.md` for the workload; the specifics below
are Azure-Gov-only).

- **Cluster:** AKS in an Azure Government region; FIPS-enabled node pools; private
  cluster/API where required; Azure CNI with NetworkPolicies.
- **Identity (secretless, preferred):** enable **Entra Workload Identity** —
  federate the AKS OIDC issuer to the app registration so pods obtain Graph tokens
  with **no client secret**. Fallback: client secret/cert in Key Vault.
- **Secrets:** Azure Key Vault (Gov) surfaced via the **Secrets Store CSI driver**
  (`DATABASE_URL`, and the client secret if not using workload identity).
- **Data:** Azure Database for PostgreSQL (Gov) or in-cluster StatefulSet; use
  Azure Storage (Gov) for backups.
- **Ingress:** Application Gateway / AGIC or an ingress controller with a Gov TLS cert.
- **Endpoints:** unchanged — still `login.microsoftonline.us` / `graph.microsoft.us`.
- **Egress:** in-cloud to M365 GCC High; keep the `.us` allowlist.

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `AADSTS500011` / resource not found | Commercial resource URL | Use `https://graph.microsoft.us` |
| Consent required loop | Admin consent not granted | Grant admin consent to app permissions |
| 403 to a site | `Sites.Selected` not granted for that site | Grant the app to the specific site |
| Guest can't sign in | B2B/Conditional Access | Confirm invite + CA policy for guests |
| Workload identity token fails | Federated credential subject mismatch | Match the AKS OIDC issuer + service account subject in the app registration |
