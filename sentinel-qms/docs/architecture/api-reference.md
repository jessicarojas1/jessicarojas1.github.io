# API Reference

Sentinel QMS exposes a versioned REST API under **`/api/v1`** (configurable via `API_V1_PREFIX`). The API
is built with FastAPI and publishes an auto-generated **OpenAPI 3** document at `/api/v1/openapi.json`
with interactive docs at `/api/v1/docs` (disabled or access-restricted in production). This reference
summarizes conventions and the resource catalog with representative request/response examples.

---

## 1. Conventions

### 1.1 Base URL & versioning
```
https://<host>/api/v1
```
The major version is in the path. Backward-incompatible changes increment the version; additive changes
do not. See [../CHANGELOG.md](../CHANGELOG.md).

### 1.2 Content type
All requests and responses use `application/json; charset=utf-8` unless uploading binaries
(`multipart/form-data`). Timestamps are ISO-8601 UTC (`2026-06-05T14:22:31Z`). IDs are UUIDv4.

### 1.3 Authentication
Obtain tokens at `POST /api/v1/auth/login`, then send the access token as a bearer header:
```
Authorization: Bearer <access_token>
```
Refresh via `POST /api/v1/auth/refresh`. Federated (OIDC/SAML/CAC-PIV) sessions are exchanged for an
internal token by the identity broker. See
[security-architecture.md](security-architecture.md) §2.

#### Personal Access Tokens (scoped API keys)
For scripts, CI jobs, and service integrations, mint a long-lived **Personal Access Token** under
**My Profile → API Tokens** (or `POST /api/v1/tokens`). The token is presented exactly once at
creation; only its SHA-256 hash and a short non-secret prefix (`sntl_xxxxxxxx`) are stored. Use it
exactly like an access token:
```
Authorization: Bearer sntl_<secret>
```
A token **acts as its owning user** — every page-level, granular, and per-record permission check
still applies on top. In addition it carries a coarse scope:

| Scope   | Grants                                                            |
|---------|------------------------------------------------------------------|
| `read`  | safe methods only (`GET`/`HEAD`/`OPTIONS`)                        |
| `write` | also permits state-changing methods (`POST`/`PUT`/`PATCH`/`DELETE`) |

A read-only token attempting a mutation receives `403 Forbidden`. Tokens may carry an optional
expiry and can be revoked at any time (revocation and expiry both take effect immediately,
fail-closed). For safety, a Personal Access Token can never be used to mint or revoke tokens — those
self-management endpoints require an interactive (JWT) session.

| Method & path             | Purpose                                              |
|---------------------------|------------------------------------------------------|
| `GET /api/v1/tokens`      | List your tokens (never includes the secret).        |
| `POST /api/v1/tokens`     | Create a token; response includes the one-time secret.|
| `DELETE /api/v1/tokens/{id}` | Revoke one of your tokens (idempotent).           |

### 1.4 Authorization
Each endpoint enforces a permission (`<domain>:<action>`). A caller lacking the permission receives
`403 Forbidden`. See the RBAC matrix in [security-architecture.md](security-architecture.md) §3.

### 1.5 Pagination
List endpoints are page-based:
```
GET /api/v1/nonconformances?page=1&page_size=50
```
| Param | Default | Max | Meaning |
|-------|---------|-----|---------|
| `page` | 1 | — | 1-based page index |
| `page_size` | 25 | 200 | items per page |

Response envelope:
```json
{
  "items": [ /* ... */ ],
  "page": 1,
  "page_size": 50,
  "total": 327,
  "total_pages": 7
}
```

### 1.6 Filtering, sorting & search
- **Filtering:** field-scoped query params, e.g. `?status=open&severity=major&supplier_id=<uuid>`.
- **Date ranges:** `?created_from=2026-01-01&created_to=2026-03-31`.
- **Sorting:** `?sort=-created_at` (prefix `-` = descending). Multiple keys comma-separated.
- **Full-text search:** `?q=<term>` searches title/description/record_number where supported.

### 1.7 Errors
Errors use a consistent envelope with appropriate HTTP status:
```json
{
  "error": {
    "code": "validation_error",
    "message": "quantity_affected must be >= 0",
    "details": [{ "field": "quantity_affected", "issue": "ge" }],
    "request_id": "req_01HXYZ..."
  }
}
```
| Status | When |
|--------|------|
| 400 | Malformed request |
| 401 | Missing/invalid/expired token |
| 403 | Authenticated but not permitted |
| 404 | Resource not found / out of tenant scope |
| 409 | Conflict (optimistic-concurrency `version` mismatch, duplicate record number) |
| 422 | Validation error (Pydantic) |
| 429 | Rate limited |
| 5xx | Server error (no stack traces in production) |

### 1.8 Concurrency & idempotency
- Mutations accept the current `version`; a stale value yields `409 Conflict` (optimistic locking).
- `POST` creation endpoints accept an optional `Idempotency-Key` header to safely retry.

### 1.9 Rate limiting
The API enforces an in-process **fixed-window** rate limit per caller (defense-in-depth; a fronting
gateway/WAF should also limit in a horizontally scaled deployment). Callers are bucketed by **source
IP** — deliberately not by any client-supplied header, which could be rotated to mint fresh budgets.
`X-Forwarded-For` is honored only when `TRUST_PROXY_HEADERS=true` (set it behind a trusted proxy/LB
so the real client IP, not the proxy's, is used). Every API response carries:

| Header | Meaning |
|--------|---------|
| `X-RateLimit-Limit` | Requests allowed per window |
| `X-RateLimit-Remaining` | Requests left in the current window |
| `X-RateLimit-Reset` | Seconds until the window resets |

Exceeding the budget returns `429 Too Many Requests` with a `Retry-After` header. `/health` and the
served SPA are exempt. Tunable via `RATE_LIMIT_ENABLED`, `RATE_LIMIT_PER_MINUTE`, and
`RATE_LIMIT_WINDOW_SECONDS` (see the configuration reference).

### 1.10 Audit & signatures
Every mutating call is audited automatically. Signature-bearing endpoints (see catalog) require a
re-authentication payload and persist an e-signature manifest.

---

## 2. Resource Catalog

| Domain | Base path | Key endpoints |
|--------|-----------|---------------|
| Auth | `/auth` | `POST /login`, `POST /refresh`, `POST /logout`, `GET /me` |
| Users & Roles | `/users`, `/roles` | CRUD users, assign roles (Admin only) |
| Documents | `/documents` | CRUD, `POST /{id}/revisions`, `POST /{id}/approve`, `POST /{id}/acknowledge` |
| Nonconformances | `/nonconformances` | CRUD, `POST /{id}/disposition`, `POST /{id}/mrb` |
| CAPA | `/capas` | CRUD, `PUT /{id}/steps/{d}`, `POST /{id}/actions`, `POST /{id}/close` |
| Audits | `/audits` | plans/events/findings CRUD, `POST /findings/{id}/link-capa` |
| Suppliers | `/suppliers` | CRUD, `/asl`, `POST /{id}/scars`, `POST /{id}/ratings` |
| Calibration | `/equipment` | CRUD, `POST /{id}/calibrations`, `GET /due` |
| Training | `/training/courses`, `/training/records` | CRUD, `GET /records/expiring` |
| Change Mgmt | `/changes` | CRUD, `POST /{id}/approve`, `POST /{id}/tasks` |
| Risk | `/risks` | CRUD, `POST /{id}/assessments` |
| Inspection / FAI | `/inspections`, `/fai` | CRUD, `POST /fai/{id}/sign` |
| Management Review | `/management-reviews` | CRUD, `POST /{id}/inputs`, `POST /{id}/actions` |
| Complaints / RMA | `/complaints`, `/rmas` | CRUD, `POST /complaints/{id}/rma` |
| Dashboard | `/dashboard` | `GET /kpis`, `GET /kpis/{name}` |
| Attachments | `/attachments` | `POST` (multipart), `GET /{id}`, download URL |
| Audit Log | `/audit-log` | `GET` (read-only, filterable) |
| Health | `/health`, `/healthz` | liveness/readiness (unauthenticated) |

---

## 3. Example Requests & Responses

### 3.1 Login
```http
POST /api/v1/auth/login
Content-Type: application/x-www-form-urlencoded

username=jane.engineer@contoso.gov&password=********
```
```json
200 OK
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "refresh_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
  "token_type": "bearer",
  "expires_in": 1800
}
```

### 3.2 Current user
```http
GET /api/v1/auth/me
Authorization: Bearer <access_token>
```
```json
200 OK
{
  "id": "0b8c…",
  "email": "jane.engineer@contoso.gov",
  "display_name": "Jane Engineer",
  "roles": ["Quality Engineer"],
  "organization_id": "1f2a…",
  "auth_source": "oidc"
}
```

### 3.3 Create a Nonconformance
```http
POST /api/v1/nonconformances
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "title": "Bracket bore out of tolerance",
  "description": "Lot 4471 bore measured 12.10mm vs 12.00 +/-0.05",
  "severity": "major",
  "source": "in_process_inspection",
  "part_number": "AX-22841",
  "lot_serial": "LOT-4471",
  "quantity_affected": 18,
  "supplier_id": null
}
```
```json
201 Created
{
  "id": "9d31…",
  "record_number": "NCR-2026-000147",
  "status": "open",
  "severity": "major",
  "created_at": "2026-06-05T14:22:31Z",
  "version": 1
}
```

### 3.4 Disposition an NCR (signature-bearing)
```http
POST /api/v1/nonconformances/9d31…/disposition
Authorization: Bearer <access_token>
Content-Type: application/json

{
  "disposition": "rework",
  "justification": "Rework per RWI-118; re-inspect 100%.",
  "version": 1,
  "signature": {
    "meaning": "approved",
    "reauth_method": "password",
    "reauth_secret": "********"
  }
}
```
```json
200 OK
{
  "id": "9d31…",
  "record_number": "NCR-2026-000147",
  "status": "dispositioned",
  "disposition": "rework",
  "esignature": {
    "signer": "jane.engineer@contoso.gov",
    "meaning": "approved",
    "signed_at": "2026-06-05T14:31:09Z",
    "record_hash": "sha256:7b1d…"
  },
  "version": 2
}
```

### 3.5 List CAPAs with filters & pagination
```http
GET /api/v1/capas?status=open&priority=high&sort=-due_date&page=1&page_size=25
Authorization: Bearer <access_token>
```
```json
200 OK
{
  "items": [
    {
      "id": "5af0…",
      "record_number": "CAPA-2026-000058",
      "title": "Recurring bore oversize on AX-22841",
      "status": "root_cause",
      "priority": "high",
      "owner": "Jane Engineer",
      "due_date": "2026-06-20"
    }
  ],
  "page": 1, "page_size": 25, "total": 1, "total_pages": 1
}
```

### 3.6 Equipment due for calibration
```http
GET /api/v1/equipment/due?window_days=30
Authorization: Bearer <access_token>
```
```json
200 OK
{
  "items": [
    { "record_number": "EQP-2026-000310", "asset_tag": "CMM-04",
      "description": "Coordinate Measuring Machine", "next_due_date": "2026-06-18",
      "status": "due_soon" }
  ],
  "total": 1
}
```

### 3.7 Upload an attachment
```http
POST /api/v1/attachments
Authorization: Bearer <access_token>
Content-Type: multipart/form-data; boundary=...

entity_type=fai_report
entity_id=...
file=@AS9102_Form3.pdf
```
```json
201 Created
{
  "id": "c4e8…",
  "original_filename": "AS9102_Form3.pdf",
  "content_type": "application/pdf",
  "size_bytes": 248113,
  "sha256": "9af2…",
  "storage_key": "org/1f2a/att/8c12-1b9e-randomized.pdf"
}
```

### 3.8 Read the audit log
```http
GET /api/v1/audit-log?entity_type=nonconformance&entity_id=9d31…&sort=-occurred_at
Authorization: Bearer <access_token>
```
```json
200 OK
{
  "items": [
    { "action": "ncr.disposition", "actor": "jane.engineer@contoso.gov",
      "before_hash": "sha256:1a…", "after_hash": "sha256:7b…",
      "source_ip": "10.40.16.42", "occurred_at": "2026-06-05T14:31:09Z" }
  ],
  "total": 1
}
```

---

## 4. OpenAPI & SDKs

The canonical contract is the OpenAPI document at `GET /api/v1/openapi.json`. Typed clients (TypeScript
for the SPA, Python for integrations) are generated from it in CI, keeping clients in lockstep with the
server. Breaking changes are gated by the security/API review described in [../CONTRIBUTING.md](../CONTRIBUTING.md).
