# app/Support — framework services (Phase 1 skeleton)

Implemented in this drop (namespace `Redoubt\Support\`):

- `Config` — env-backed config; defaults to **GCC High** endpoints.
- `Session` — hardened session (HttpOnly, SameSite, Secure behind TLS).
- `Security` — CSP nonce, `h()` escaping, `jsonForScript()`, CSRF issue/validate/rotate.
- `Db` — PDO/PostgreSQL, parameterized only; `update()` auto-appends `updated_at`.
- `Roles` — role → granular-permission defaults + coarse→granular aliases.
- `Authorize` — policy engine: (program × company × role × zone) + **US-person export gate**; role defaults + explicit grants − denials.
- `Oidc` — Entra GCC High OIDC (PKCE, token exchange, JWKS RS256 verify).
- `Auth` — sign-in orchestration, session, membership/grant load, logout.
- `Graph` — Microsoft Graph (GCC High) client-credentials client for SharePoint.
- `Audit` — append-only audit logging.
- `ApiKey` — bearer API-client authentication (hashed secrets).
- `Webhooks` — HMAC-signed outbound dispatch + delivery log; inbound verify.

HTTP layer lives in `app/Http/` (`AuthController`, `AppController`, `ApiRouter`).

**Not yet built (Phase 1 modules):** announcements, documents, task orders, jobs,
directory, search, the two-pane IAM admin UI, and the concrete API/webhook admin
endpoints. See `../../OPEN_ITEMS.md`.

> Security: the hand-rolled OIDC token verification in `Oidc` must be
> security-reviewed before go-live; consider a vetted JOSE library. Do not enable
> production sign-in until reviewed.
