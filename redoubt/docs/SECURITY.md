# REDOUBT — Security Guide

> Discovery-phase. The scaffold sets strict security headers and a CSP nonce
> today; authentication, authorization, and audit are Phase 1 (see `../OPEN_ITEMS.md`).

## Security posture

REDOUBT operates in an aerospace/defense context and is designed around **CMMC /
NIST SP 800-171 / DFARS** expectations. The portal is a **gate, not a vault**:
authoritative data stays in approved repositories; the portal holds only config,
portal-owned content, metadata/references, and audit.

## Deployment boundary (selected)

The application runs on **Kubernetes** and is portable across three authorized
CUI boundaries — **on-prem**, **Azure Government (AKS)**, and **AWS GovCloud
(EKS)** — from one image; identity and documents always live in **Microsoft 365 /
Azure GCC High**. GMRE owns and must accredit the app-tier boundary (network,
FIPS, boundary protection) under NIST 800-171 / CMMC for the chosen target;
authorization is inherited from Microsoft for the M365 tier and from the cloud
provider (Azure Gov / AWS GovCloud) for the hosting tier. All Microsoft calls use
GCC High endpoints (`login.microsoftonline.us`, `graph.microsoft.us`,
`*.sharepoint.us`).

## Identity & authentication

- Microsoft **Entra ID GCC High**, OIDC SSO, **MFA enforced**.
- External subcontractors/customers via **Entra B2B guests** with **Conditional
  Access** (device/geo/risk) and **contract-bound expiry**.

## Authorization

- Every request and every search result authorized **server-side** against
  (program × company × role × zone). UI hiding is cosmetic only.
- Least privilege / need-to-know default; access is additive and approved.
- Isolation: per-program SharePoint site collection; per-company library + Entra
  group. **No per-item SharePoint ACLs** (sprawl anti-pattern).

## Data protection

- Classification zones (public → CUI/ITAR) per Discovery Package §11.
- **CUI and ITAR/export-controlled data are in scope and supported in-portal.** The
  baseline boundary is a **GCC High** tenant + a **Gov-cloud (or air-gapped)
  enclave**; export-controlled data is held **under access control, not excluded**.
- **Export control (ITAR/EAR):** a user's **US-person** status is verified at
  provisioning and enforced server-side as an access gate on export-controlled
  zones; license/agreement scoping applied where applicable. Nationality is never
  inferred client-side.
- Data taxonomy: portal-owned · authoritative SoR (never copied) · linked ·
  embedded (live perms) · replicated (avoided).

## Auditability

Append-only audit of access, admin, publish, provision, and revoke actions;
heightened retention for external access; paired with the M365 unified audit log.

## Classification & DLP

Microsoft Purview sensitivity labels on SharePoint content; DLP policies at the
M365 tenant. Portal never renders content the caller is not authorized to see.

## FIPS readiness

Required because CUI/ITAR are in scope. The M365 GCC High tier is FIPS-validated
by Microsoft. On the **on-prem app tier**, GMRE enables **FIPS 140-validated
crypto modules** (OS-level / OpenSSL FIPS provider) for TLS and hashing; the
application adds no non-approved cryptography.

## Transport & session

TLS only, HSTS, strict CSP with per-request nonce, `X-Content-Type-Options`,
`X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`; CSRF tokens on
all writes (Phase 1); short sessions; revoke-on-offboard.

## Operator responsibilities

- Approve external access (PM + Cyber); customer access (PM + Contracts).
- Run quarterly access reviews; enforce offboarding SLA.
- Rotate secrets on schedule; keep secrets out of source (`.env` never committed).

## Reporting

Report suspected incidents to Cybersecurity per corporate IR procedure; the
portal provides audit evidence and access timelines.
