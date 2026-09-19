# REDOUBT — Microsoft 365 / Azure GCC High Configuration

This guide covers the **cloud back-end** side of the hybrid deployment: the
**Microsoft 365 / Azure GCC High** identity and document services that the
**on-prem** REDOUBT app depends on. It does **not** host the app (that runs
on-prem — see `SINGLE_LINUX_SERVER.md` / `KUBERNETES.md`).

> Why GCC High: CUI/ITAR are in scope. GCC High is the authorized M365 tenant for
> DoD/ITAR workloads and uses distinct endpoints from commercial.

## 1. Architecture (cloud side)

- **Entra ID GCC High** — user identity, SSO (OIDC), MFA, B2B guests, Conditional Access.
- **SharePoint Online GCC High** — authoritative document store; one **site
  collection per program**, one **document library per company** for isolation.
- **Microsoft Graph (GCC High)** — the API the on-prem app calls to browse/search
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

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `AADSTS500011` / resource not found | Commercial resource URL | Use `https://graph.microsoft.us` |
| Consent required loop | Admin consent not granted | Grant admin consent to app permissions |
| 403 to a site | `Sites.Selected` not granted for that site | Grant the app to the specific site |
| Guest can't sign in | B2B/Conditional Access | Confirm invite + CA policy for guests |
