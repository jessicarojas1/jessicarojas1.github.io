# Verity — API Specification

Verity is primarily a server-rendered application (plain PHP views, no SPA).
Its JSON API surface is intentionally small today — this document describes
exactly what exists; it is not a target or aspirational spec. No OpenAPI
document is generated yet (see `OPEN_ITEMS.md`).

## Authentication

Every endpoint below except `/health` requires an active session (the same
`VERITY_SID` cookie used by the HTML app — there is no separate API token or
key scheme). Endpoints under `/app/admin/iam/*` additionally require the
`iam.view`/`iam.manage` permission via `Authorize`; `/api/v1/matrix` requires
the same permission the equivalent Matrix view requires on the HTML side —
**the API reuses `Authorize::can()` exactly, never a parallel check.**

## Public

### `GET /health`

No authentication required. Liveness/readiness probe target.

```json
{"status": "ok", "app": "verity", "db": true}
```

`db` reflects whether `DATABASE_URL` is configured, **not** a live
connectivity probe — see `deployments/LOCAL_DEVELOPMENT.md` §10.

## Versioned read API (`app/Http/ApiRouter.php`)

### `GET /api/v1/matrix`

Returns the same scoped, paginated result as the Enterprise Access Matrix
HTML page (`app/Support/Matrix.php::query()`), as JSON.

Query parameters: `view` (`enterprise`|`supervisor`|`privileged`|`exception`
— `person`/`application` views are not exposed over this endpoint today),
`limit` (default 50, max 200), `offset` (default 0).

```json
{
  "rows": [ { "account_id": 1, "person_name": "...", "application_name": "...", "...": "..." } ],
  "total": 839
}
```

- Unauthenticated → `401 {"error":"Unauthorized"}`
- Authenticated but lacking the matching permission → `403 {"error":"Forbidden"}`
- `view=supervisor` is scoped server-side to the caller's own
  `Authorize::reportsOf()` reporting chain, exactly like the HTML page.

## Internal AJAX endpoints (same-origin only, CSRF-protected)

These back specific UI interactions and are not intended as a general
integration surface, but are documented here for completeness since they
return/accept JSON:

| Endpoint | Method | Purpose |
|---|---|---|
| `/app/admin/iam/users` | GET | List platform users (Admin IAM console left pane) |
| `/app/admin/iam/user?id=` | GET | Effective permission state for one user |
| `/app/admin/iam/save` | POST | Save role/permission changes; rotates and returns a new CSRF token |
| `/app/accounts/link-candidates?q=` | GET | Typeahead candidates for manual identity correlation |

All POST endpoints (here and across the HTML app) require a valid `_csrf`
token tied to the session; none of them are safe to call cross-origin.

## Not implemented

- No OpenAPI/Swagger document.
- No API client/key management (the directive's "Integration & Connector
  Management" / external API-consumer story is not built).
- No rate limiting on any endpoint.
- No endpoints for identities, accounts, applications, or dynamic fields
  beyond the one Matrix read above — those modules are HTML-only today.
