# ORRERY — Security Guide

> Discovery-phase. The scaffold sets strict security headers and a CSP nonce
> today; authentication, authorization, and audit are Phase 1 (see `../OPEN_ITEMS.md`).

## Security posture

ORRERY operates in an aerospace/defense context and is designed around **CMMC /
NIST SP 800-171 / DFARS** expectations. The portal is a **gate, not a vault**:
authoritative data stays in approved repositories; the portal holds only config,
portal-owned content, metadata/references, and audit.

## Identity & authentication

- Microsoft Entra ID, OIDC SSO, **MFA enforced**.
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
- CUI/ITAR, if in scope, requires GCC High + an authorized hosting boundary; some
  export-controlled data may stay **out of the portal** (link-only to an enclave).
- Data taxonomy: portal-owned · authoritative SoR (never copied) · linked ·
  embedded (live perms) · replicated (avoided).

## Auditability

Append-only audit of access, admin, publish, provision, and revoke actions;
heightened retention for external access; paired with the M365 unified audit log.

## Classification & DLP

Microsoft Purview sensitivity labels on SharePoint content; DLP policies at the
M365 tenant. Portal never renders content the caller is not authorized to see.

## FIPS readiness

Deploy in FIPS-validated boundaries for CUI (Gov cloud / GCC High). TLS and
crypto provided by the platform; the app adds no non-approved crypto.

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
