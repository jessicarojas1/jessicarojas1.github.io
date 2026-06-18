# AEGIS — Architecture

AEGIS is a self-hosted Governance, Risk & Compliance (GRC) platform built as a
**framework-free PHP 8.2 monolith** on **PostgreSQL 16**, shipped as a single
Docker container. There is no Composer, no npm, no build step — routing, auth,
the ORM-lite layer, CSP, RBAC, the API, and the views are all hand-rolled.

## Design principles

1. **Front-controller monolith.** Every web request enters through `index.php`.
2. **Server-rendered, same-origin PHP views.** No SPA, no client framework.
3. **CSP-strict frontend.** No inline scripts or inline event handlers; all
   interactivity is delegated through `data-*` attributes in `public/js/app.js`.
4. **Prepared statements only.** All SQL goes through PDO with bound parameters.
5. **Granular RBAC.** Permissions are `module.action` strings (e.g. `risk.accept`).
6. **Tamper-evident audit trail.** Every sensitive action is hash-chained.
7. **Runtime safety guards.** The schema self-heals idempotently for forward
   compatibility; durable changes live in numbered migrations.

## Request lifecycle (web)

```
Browser
  │
Apache (.htaccess) — blocks /src, /config, /database, /scripts; sets base headers
  │
index.php (front controller)
  ├─ define AEGIS_REQUEST_ID (per-request correlation id → X-Request-Id header)
  ├─ set_exception_handler  (generic 500; only config errors show a message)
  ├─ load .env + merge getenv() into $_ENV
  ├─ startup guard: JWT_SECRET present and ≥ 32 chars
  ├─ session_start()  (HttpOnly, SameSite=Strict, Secure + __Host- on HTTPS)
  ├─ spl_autoload_register → src/{Class}.php then controllers/{Class}.php
  ├─ Security::setSecurityHeaders()  (CSP + per-request nonce, HSTS, …)
  ├─ runtime self-healing migrations (idempotent ALTER guards, try/catch no-ops)
  ├─ track active_sessions
  └─ router
       ├─ exact match:  $routes[METHOD][URI]
       ├─ regex match:  $dynamicRoutes[METHOD]  (e.g. /risk/123)
       └─ dispatch(Controller, action, params)
             ├─ reflection coerces dynamic params to declared scalar types
             └─ Controller::method()
                   ├─ Auth::requireAuth() / Auth::requirePermission('module.action')
                   ├─ Database::fetchAll/One/insert/update  (PDO prepared)
                   └─ require views/<module>/<page>.php
                         → ob_start() captures HTML into $content
                         → require views/layout.php  (sidebar, topbar, $content)
```

If no route matches, the front controller returns the `views/errors/404.php`
page. Authorization failures render `views/errors/403.php`; unexpected
throwables are caught by the top-level handler and render `views/errors/500.php`
with only a correlation ID (never the underlying message — see `SECURITY.md`).

## Request lifecycle (API)

```
/api/... → api/index.php
  ├─ CORS: Access-Control-Allow-Origin echoed only when Origin === APP_URL (no wildcard)
  ├─ auth: X-API-Key (HMAC-SHA256 lookup, legacy SHA-256 auto-upgraded) OR
  │        Authorization: Bearer <JWT> (HS256, exp required)
  ├─ rate limit: 60 req/min per IP (rate_limits table)
  └─ match(true) dispatch → JSON { success, data|error, meta }
```

## Directory layout

| Path | Role |
|------|------|
| `index.php` | Front controller: bootstrap, route tables, dispatcher, runtime migrations |
| `api/index.php` | JSON API front controller |
| `src/` | Core libraries — see below |
| `controllers/` | ~50 controllers, one per module |
| `views/` | `layout.php` + per-module page templates + `errors/` |
| `config/` | `app.php` (tunables), `database.php` (DSN from `DATABASE_URL`) |
| `database/` | `schema.sql` (base) + `migrations/` (numbered) + `seeds/` |
| `public/` | `css/`, `js/app.js`, vendored `Chart.js` + Bootstrap Icons |
| `scripts/` | CLI/cron tools: workflow executor, webhook dispatcher, notifications, audit-log verifier |
| `tests/` | Framework-free test runner (`run.php`) + `test_*.php` |
| `docs/` | Cloud deployment guides + module docs |

## Core libraries (`src/`)

| Class | Responsibility |
|-------|----------------|
| `Database` | PDO singleton; `query/fetchOne/fetchAll/insert/update`. `update()` auto-appends `updated_at = NOW()`. |
| `Auth` | Session auth, RBAC (`requireAuth`, `requirePermission`, `can`), hash-chained `log()`. |
| `Security` | `h()` escaping, CSRF tokens, CSP `nonce()`, password hashing/policy, API-key gen, rate limiting, setting encryption. |
| `Errors` | Centralized `abort(code)` → consistent HTML/JSON error responses. |
| `Ssrf` | Centralized SSRF guard (IPv4+IPv6, metadata/CGNAT/ULA, DNS-pin). Used by `Webhook`, `SSO`, and any URL fetch. |
| `Branding` | White-label logo/name/accent; sanitizes logo URLs and colors. |
| `JWT` | HS256 sign/verify; rejects tokens without `exp`. |
| `SSO` | OIDC discovery + code exchange (pinned cURL via `Ssrf`). |
| `TOTP` | MFA / authenticator codes. |
| `Webhook` | Outbound delivery to 11 providers; SSRF-guarded, IP-pinned. |
| `Mailer` | Email + templates. |
| `AIAdvisor` | Opt-in LLM advisory features. |
| `Storage` | Upload handling (MIME validation, randomized names). |
| `CustomFields` | User-defined fields on entities. |

## Data model

All tables live in the isolated `aegis` PostgreSQL schema. Base tables are
created by `install.php` from `database/schema.sql`; subsequent changes are
applied via numbered migrations in `database/migrations/`. See the README's
"Database Schema" section for the full table catalogue and relationships.

## Observability

- **Correlation IDs** — every request gets `AEGIS_REQUEST_ID`, echoed in the
  `X-Request-Id` header, error logs, the 500 page, and health responses.
- **Health probes** — `GET /healthz` (liveness) and `GET /readyz` (readiness;
  checks the database and returns 503 when unavailable).
- **Audit trail** — see `AUDIT_TRAIL.md`.
