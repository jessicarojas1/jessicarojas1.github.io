# app/Support (Phase 1)

Intended home for framework services introduced with the MVP:

- `Security` — CSP nonce, output escaping (`h()`), CSRF token issue/validate.
- `Auth` — Entra OIDC sign-in, session, MFA gating.
- `Authorize` — the policy engine: authorize every request/result by
  (program × company × role × zone).
- `Graph` — Microsoft Graph client for SharePoint document browse/search.
- `Audit` — append-only audit logging.
- `Db` — PostgreSQL access (parameterized queries only).

Empty during the discovery phase by design; see `../../OPEN_ITEMS.md`.
