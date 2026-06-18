# AEGIS — REST API

The AEGIS JSON API is served by `api/index.php`, delegated from the front
controller for any request path beginning with `/api/`. The versioned base path
is **`/api/v1`**.

## Conventions

- **Base URL:** `${APP_URL}/api/v1`
- **Content type:** `application/json`
- **CORS:** `Access-Control-Allow-Origin` is returned **only** when the request
  `Origin` exactly equals `APP_URL`. There is no wildcard.
- **Correlation:** every response carries a `request_id` in `meta`, matching the
  `X-Request-Id` response header, for log correlation.

### Response envelope

```json
{
  "success": true,
  "data": { "...": "..." },
  "meta": { "timestamp": "2026-06-17T00:00:00+00:00", "version": "v1" }
}
```

### Error envelope

```json
{
  "success": false,
  "error": "Human-readable message",
  "meta": { "timestamp": "...", "request_id": "ab12cd34ef56..." }
}
```

Status codes: `200` ok, `201` created, `401` unauthenticated, `403`
unauthorized, `404` not found, `429` rate limited.

## Authentication

Two mechanisms are accepted on protected endpoints:

### 1. API key

Send the key in the `X-API-Key` header. Only the **HMAC-SHA256** hash of the key
is stored server-side; the plaintext is shown once at creation. Keys support
`is_active` (revocation), `expires_at`, scopes (`permissions`), and `last_used`.

```bash
curl -H "X-API-Key: $AEGIS_API_KEY" "$APP_URL/api/v1/risks"
```

### 2. Bearer JWT

Exchange credentials for a short-lived (1 hour) HS256 token, then send it as a
`Bearer` token. Tokens **must** carry `exp`; the algorithm is pinned to HS256
(`alg:none` and algorithm-confusion attempts are rejected).

```bash
# Obtain a token (rate-limited per IP; MFA users must include totp_code)
curl -X POST "$APP_URL/api/v1/auth/token" \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"…","totp_code":"123456"}'

# Use it
curl -H "Authorization: Bearer $TOKEN" "$APP_URL/api/v1/risks"
```

## Rate limiting

60 requests per minute per IP (tracked in `rate_limits`). Exceeding the limit
returns `429`. The token endpoint is additionally throttled by the login
brute-force limiter.

## Pagination & sorting

List endpoints accept:

| Param | Default | Notes |
|-------|---------|-------|
| `page` | `1` | 1-based page number |
| `per_page` | `25` | clamped to `1..100` |
| `sort` | endpoint default | allowlisted column; prefix `-` for descending |

`sort` values are validated against a per-endpoint allowlist, so they never reach
SQL as untrusted input. Paginated responses include `meta.pagination`:

```json
"pagination": { "page": 1, "per_page": 25, "total": 248, "total_pages": 10 }
```

```bash
curl -H "X-API-Key: $K" "$APP_URL/api/v1/risks?page=2&per_page=50&sort=-r.inherent_score"
```

## Endpoints

### Health (public, no auth)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/health` | Liveness + database readiness. Returns `503` if the DB is unreachable. |

### Auth

| Method | Path | Description |
|--------|------|-------------|
| POST | `/auth/token` | Exchange email/password (+ `totp_code` if MFA) for a JWT. |

### Compliance

| Method | Path | Notes |
|--------|------|-------|
| GET | `/compliance/packages` | Paginated. Sort: `cp.name`, `cp.created_at`. |
| GET | `/compliance/packages/{id}` | Single package. |
| GET | `/compliance/packages/{id}/objectives` | Objectives + implementation status. |
| PUT | `/compliance/objectives/{id}/status` | Update control status (`write` scope). |
| GET | `/standards` | Paginated. Sort: `name`, `created_at`. |

### Risk

| Method | Path | Notes |
|--------|------|-------|
| GET | `/risks` | Paginated. Sort: `r.inherent_score`, `r.created_at`, `r.title`, `r.status`. |
| GET | `/risks/{id}` | Single risk. |
| POST | `/risks` | Create risk (`write` scope). Inputs are length-clamped and `likelihood`/`impact` bounded `1..5`. |

### Policy / Audit

| Method | Path | Notes |
|--------|------|-------|
| GET | `/policies` | Paginated. Sort: `p.updated_at`, `p.title`, `p.status`. |
| GET | `/audits` | Paginated. Sort: `a.scheduled_date`, `a.status`. |

### Dashboard / Admin

| Method | Path | Notes |
|--------|------|-------|
| GET | `/dashboard/stats` | Summary counts. |
| GET | `/users` | Admin only. Paginated. Sort: `name`, `email`, `role`, `created_at`. |

### SIEM / scanner ingestion

| Method | Path | Notes |
|--------|------|-------|
| POST | `/ingest/{tenable\|qualys\|wiz\|generic}` | Handled by `api/ingest.php`. |

## Scopes

API-key scopes are stored in the key's `permissions` array (default `["read"]`).
Write operations require the `write` scope (or an `admin` user). Admin-only
endpoints require the user's role to be `admin`.

## OpenAPI

A machine-readable description is served at `/api/docs` (`api/docs.php`). Keep it
in sync when adding or changing endpoints.
