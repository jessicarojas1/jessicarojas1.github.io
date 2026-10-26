# ADR-0004: Authentication: OIDC SSO via backend-for-frontend (BFF)

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed. Identity provider confirmed: **Entra ID** (D18, 2026-10-25), for sign-in only
- **Date:** 2026-10-25

## Decision
The backend runs the OIDC authorization-code flow with PKCE against **Entra ID** (D18) and keeps tokens server-side. It issues a session cookie (`HttpOnly`, `Secure`, `SameSite=Lax`, `__Host-` prefix). The SPA is served from the same origin. State-changing requests require an antiforgery token. Approvals optionally force re-authentication (`max_age=0`) and record `auth_time`.

Users are keyed by the IdP's immutable subject (Entra: `tid` + `oid`), never by email or UPN. Global roles map from Entra groups or app roles (decision D4).

## Rationale
Removes token theft through XSS as a risk class and keeps identity logic in one place. OIDC is standard, so another IdP could replace Entra later behind the same adapter.

## Consequences
- CSRF protection is mandatory and is covered.
- Sessions are stored server-side in PostgreSQL (Symfony PDO session handler), so multiple app instances share sessions and logout or revocation is immediate.
