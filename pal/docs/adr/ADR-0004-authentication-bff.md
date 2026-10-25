# ADR-0004: Authentication: Entra ID OIDC via backend-for-frontend (BFF)

- **Status:** Proposed
- **Date:** 2026-10-25

## Decision
The API runs the OIDC authorization-code flow with PKCE against Entra ID and keeps tokens server-side. It issues a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`, `__Host-` prefix). The SPA is served from the same origin. State-changing requests require an antiforgery token. Approvals optionally force re-authentication (`max_age=0`) and record `auth_time`.

Users are keyed by (`tid`, `oid`), never by email or UPN. Global roles map from Entra groups or app roles (decision D4).

## Rationale
Removes token theft through XSS as a risk class and keeps identity logic in one place. OIDC is standard, so another IdP could replace Entra later behind the same adapter.

## Consequences
- CSRF protection is mandatory and is covered.
- Session store: ASP.NET Core data-protection keys persisted to the database or Blob storage, so multiple API instances share sessions.
