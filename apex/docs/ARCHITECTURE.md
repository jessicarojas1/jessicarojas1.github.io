# APEX — Architecture

APEX is a DoD-grade project + ticket tracker. This document describes the
platform it is built on, its design principles, its component layout, the
configuration model, the request/error contract, the security model, and the
observability and deployment topology.

> Cross-links: [DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [SECURITY.md](SECURITY.md) · [../README.md](../README.md) · deployment target guides under [../deployments/](../deployments/)

---

## 1. Platform

| Layer      | Technology                                                                 |
|------------|----------------------------------------------------------------------------|
| Runtime    | PHP 8.2 (no framework — PDO + a hand-written router)                        |
| Web server | Apache 2.4 (`php:8.2-apache`), `mod_rewrite`, non-root, listening on `8080` |
| Database   | PostgreSQL 16                                                              |
| Auth       | CAC/PIV-simulated identity → bcrypt-verified PIN → HS256 JWT                |
| Frontend   | Vanilla-JS SPA (`app/apex.js`, `app/apex.css`), Bootstrap 5.3 dark theme (CSS-only from jsDelivr) |
| Container  | Single Docker image; migration runs on boot via `bin/start.sh`             |
| Deploy     | Render.com Blueprint (`render.yaml`), Docker anywhere, single VM, or Kubernetes |

There is no application server pool, message broker, cache tier, or background
worker. APEX is a single stateless PHP web process fronted by Apache, backed by
one PostgreSQL database. All persistent state lives in Postgres.

---

## 2. Design principles

- **No framework, small surface.** The backend is four small classes
  (`Database`, `Auth`, `Response`, `Router`) plus one PHP file per API resource.
  Everything is auditable in a single sitting.
- **Stateless web tier.** The PHP process holds no session state. Auth is a
  self-contained signed JWT (header + cookie). Any replica can serve any request;
  horizontal scaling needs no sticky sessions.
- **Fail closed on secrets.** In `APP_ENV=production`, `Auth` refuses to run if
  `JWT_SECRET` is missing or shorter than 32 chars, rather than falling back to a
  guessable key. Default seed PINs are refused unless explicitly enabled and are
  ignored entirely in production.
- **Parameterized data access only.** Every query goes through
  `Database::query()` with bound parameters; `PDO::ATTR_EMULATE_PREPARES` is off.
- **Uniform response envelope.** Success and error shapes are consistent across
  all endpoints, produced by a single `Response` class.
- **Least privilege at runtime.** The container runs as `www-data`, binds an
  unprivileged port, writes runtime files to `/tmp`, and is compatible with a
  read-only root filesystem and drop-ALL Linux capabilities.
- **Defense-in-depth headers.** A strict Content-Security-Policy, HSTS,
  `X-Frame-Options: DENY`, `nosniff`, and a forced HTTPS redirect are enforced at
  the Apache layer (`public/.htaccess`).

---

## 3. Component overview

```
Browser (SPA)
   │  HTTPS
   ▼
Apache 2.4  (public/.htaccess: HTTPS redirect, security headers, rewrite)
   ├── static:  /            → public/index.php  (SPA shell)
   ├── static:  /app/*       → public/app/apex.js, apex.css
   └── /api/*   → public/api/index.php  (API dispatcher)
                     │
                     ├── src/Router.php     path-pattern router
                     ├── src/Auth.php       JWT issue/verify, role gate
                     ├── src/Response.php   JSON envelope helpers
                     ├── src/Database.php   PDO singleton (DATABASE_URL → DSN)
                     │
                     └── public/api/*.php   one handler file per resource:
                          auth · users · projects · tickets · comments ·
                          labels · sprints · history · notifications · settings
                     │
                     ▼
              PostgreSQL 16
   tables: users, projects, project_members, tickets, labels, sprints,
           comments, history (audit), notifications, app_settings (branding)
```

**Backend classes (`src/`, namespace `Apex\`):**

| Class      | Responsibility |
|------------|----------------|
| `Database` | PDO singleton. Parses `DATABASE_URL` (`postgres://…`) into a PgSQL DSN, honors `?sslmode=`, exposes `query/fetchOne/fetchAll/execute/transaction`, and `newId()` for prefixed random IDs. |
| `Auth`     | Issues/verifies HS256 JWT (8h TTL). Reads token from `Authorization: Bearer` or the `apex_token` HttpOnly cookie. `requireAuth()`, `requireRole()`, `optionalAuth()`. Fails closed in production. |
| `Response` | JSON output. `ok/list/created/error/unauthorized/forbidden/notFound/serverError` and `readJsonBody()`. Sets `Cache-Control: no-store`. |
| `Router`   | Registers `{placeholder}` path patterns per HTTP method, dispatches, and returns 404/405 on no match. |

**API handler files (`public/api/`):** each `require`d by `public/api/index.php`,
each registers its routes on the shared `$router`. See
[../README.md](../README.md) for the full route table.

---

## 4. Monorepo placement & internal layout

APEX lives at `apex/` within the parent GitHub Pages monorepo. It is a
self-contained deployable unit — `render.yaml` sets `rootDir: apex` so the
project deploys standalone without the rest of the monorepo.

```
apex/
├── Dockerfile               # PHP 8.2 + Apache + pdo_pgsql, non-root on :8080
├── docker-compose.yml       # Local: app + postgres (loopback-bound)
├── render.yaml              # Render Blueprint (web + managed Postgres)
├── .env.example             # Copy to .env for local dev (never commit .env)
├── schema.sql               # Full schema + seed (3 users, 1 project, 8 tickets, 6 labels)
├── bin/start.sh             # Container entrypoint: migrate then apache2-foreground
├── scripts/migrate.php      # Idempotent applier — applies schema.sql if users table absent
├── src/                     # PHP classes (Apex\*): Database, Auth, Response, Router
├── public/                  # Apache document root
│   ├── .htaccess            # HTTPS redirect, security headers/CSP, rewrites
│   ├── index.php            # SPA shell (HTML)
│   ├── app/                 # Mirrored static assets (apex.js, apex.css)
│   └── api/                 # index.php dispatcher + one handler file per resource
├── app/                     # Canonical frontend source (mirrored into public/app at build)
└── docs/                    # ARCHITECTURE · DEPLOYMENT · DISASTER_RECOVERY · SECURITY
```

The Docker build copies `app/apex.js` / `app/apex.css` into `public/app/` so
Apache serves them directly with no PHP involvement.

---

## 5. Configuration model

Configuration is entirely environment-variable driven. There is no config file.
In local development, `scripts/migrate.php` will load a `.env` file if present
(only setting vars not already in the environment). In production, variables come
from the platform (Render env group, ECS task definition, Kubernetes Secret,
etc.).

| Variable                  | Example                                            | Purpose |
|---------------------------|----------------------------------------------------|---------|
| `DATABASE_URL`            | `postgresql://apex:pass@db:5432/apex?sslmode=require` | Postgres connection. `postgres://`/`postgresql://` URL or a raw PDO DSN. `?sslmode=` honored. |
| `JWT_SECRET`              | `<64 random hex chars>`                            | HMAC key for HS256 JWT. **Required and ≥32 chars in production** or the app fails closed. |
| `APP_ENV`                 | `production`                                       | `production` enables the fail-closed secret check and `Secure` cookies; anything else is dev mode. |
| `APEX_ALLOW_DEFAULT_PINS` | `0`                                               | `1` accepts the well-known seed PINs (dev convenience). Ignored entirely when `APP_ENV=production`. Keep `0`. |
| `DATABASE_USER`           | `apex`                                             | Only used when `DATABASE_URL` is a raw DSN rather than a URL. |
| `DATABASE_PASS`           | `••••`                                             | Only used when `DATABASE_URL` is a raw DSN rather than a URL. |
| `PORT`                    | `8080`                                             | Platform-provided listen port on some PaaS. Apache listens on `8080`. |

**Branding** (display name, logo URL, accent color) is *runtime* configuration
stored server-side in the `app_settings` table under the `branding` key, editable
by admins via `POST /api/settings/branding` and the Admin Center → Branding tab.
It is not an environment variable.

---

## 6. Request & error contract

**Routing.** All `/api/*` requests are rewritten to `public/api/index.php`, which
builds the `Router`, `require`s each resource handler, and dispatches on method +
path. Path parameters use `{name}` placeholders (e.g. `/api/tickets/{id}`).

**CORS/preflight.** The dispatcher echoes the request `Origin` with
`Access-Control-Allow-Credentials: true`, allows `GET, POST, PATCH, PUT, DELETE,
OPTIONS`, and answers `OPTIONS` with `204`.

**Success envelope.**

| Helper               | Shape                                               | Status |
|----------------------|-----------------------------------------------------|--------|
| `Response::ok()`     | `{ "data": <record>, "meta"?: {…} }`                | 200    |
| `Response::list()`   | `{ "data": [ … ], "meta": { "count": N, … } }`      | 200    |
| `Response::created()`| `{ "data": <record> }`                              | 201    |

**Error envelope.** `{ "error": "<message>", "code": "<CODE>" }` with the status
matching the code:

| Code                 | Status | Raised by |
|----------------------|--------|-----------|
| `BAD_REQUEST`        | 400    | `Response::error()` default |
| `VALIDATION`         | 422    | Handler input checks (e.g. PIN format) |
| `UNAUTHORIZED`       | 401    | Missing / invalid / expired token |
| `FORBIDDEN`          | 403    | Role or membership check failed |
| `NOT_FOUND`          | 404    | Unknown route or missing record |
| `METHOD_NOT_ALLOWED` | 405    | Path matched, wrong method |
| `SERVER_ERROR`       | 500    | Uncaught exception (global guard) |

All responses carry `Content-Type: application/json; charset=utf-8` and
`Cache-Control: no-store`. A global `try/catch` in the dispatcher logs the
exception to stderr and returns `SERVER_ERROR`; in non-production it additionally
includes the message and stack trace to aid debugging.

**Auth on requests.** Protected routes call `Auth::requireAuth()` (any valid
token) or `Auth::requireRole('member'|'admin')`. Project-scoped reads additionally
enforce membership (admins bypass). The role hierarchy is `viewer < member <
admin`.

---

## 7. Security model

- **Identity & authentication.** A simulated CAC/PIV identity is selected, then a
  4–8 digit PIN is verified server-side against a bcrypt hash
  (`PASSWORD_BCRYPT`). Login returns a uniform `Invalid credentials` regardless
  of which side failed. On success an HS256 JWT (8h TTL) is issued and set as the
  `apex_token` cookie (HttpOnly, SameSite=Lax, `Secure` in production) and can
  also be sent as `Authorization: Bearer`.
- **Authorization.** Role hierarchy enforced by `Auth::requireRole()`; project
  membership enforced for project-scoped reads. Admin-only endpoints cover user
  admin, project creation/edit, member management, labels, sprints, ticket
  deletion, and branding.
- **Data protection.** TLS enforced by an Apache HTTPS-redirect (trusting
  `X-Forwarded-Proto`) plus HSTS. PINs stored only as bcrypt hashes. Postgres
  connection honors `sslmode`. See [SECURITY.md](SECURITY.md) for at-rest key
  management per target.
- **Auditability.** Every ticket mutation writes a row to the `history` table
  (event, field, from/to, user, timestamp). Comments and status changes fan out
  notifications to assignee + watchers.
- **Browser hardening.** `public/.htaccess` sets a strict CSP
  (`script-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`), HSTS,
  `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`,
  and COOP/CORP; `X-Powered-By` is unset.
- **Input handling.** Branding logo URLs are sanitized to `http(s)://` or
  `data:image/…;base64,…` only; display name is length-capped and control-char
  stripped; accent color is validated as a hex color.

See [SECURITY.md](SECURITY.md) for the full treatment (DLP/CUI, FIPS readiness,
rotation, disclosure).

---

## 8. Observability

- **Logs.** Apache access/error logs go to stdout/stderr (12-factor). The API
  dispatcher writes `error_log('[apex-api] …')` for uncaught exceptions. On
  Render/ECS/Kubernetes these are captured by the platform log pipeline.
- **Health.** `GET /api/health` returns `{ "data": { "ok": true, "service":
  "apex-api", "time": "<ISO8601>" } }` with no auth — use it for liveness and
  readiness probes.
- **Metrics / traces.** No built-in metrics or distributed tracing exporter.
  Metrics are inferred from platform request logs and Postgres statistics
  (`pg_stat_*`). If tracing is required, front APEX with a sidecar/reverse proxy
  that emits OTLP.
- **Audit trail.** The `history` and `notifications` tables provide an
  application-level activity record queryable via
  `GET /api/tickets/{id}/history` and `GET /api/projects/{id}/history`.

---

## 9. Deployment topology

APEX is one stateless web container plus one PostgreSQL database. Supported
models (details in [DEPLOYMENT.md](DEPLOYMENT.md) and per-target guides under
[../deployments/](../deployments/)):

```
        ┌────────────────────────────────────────────┐
        │  Managed PaaS (Render) — render.yaml        │
        │   web: apex (Docker)  ──►  apex-db (Postgres)│
        └────────────────────────────────────────────┘

        ┌────────────────────────────────────────────┐
        │  Single Linux VM — docker-compose / systemd  │
        │   nginx/TLS ─► apex:8080 ─► local Postgres    │
        └────────────────────────────────────────────┘

        ┌────────────────────────────────────────────┐
        │  Kubernetes                                 │
        │   Ingress ─► Deployment(apex) ─► Managed PG  │
        │   Secret(JWT_SECRET, DATABASE_URL), HPA, PDB │
        └────────────────────────────────────────────┘

        ┌────────────────────────────────────────────┐
        │  AWS (Commercial / GovCloud)                │
        │   ALB ─► ECS/Fargate(apex) ─► RDS Postgres   │
        │   Secrets Manager, IAM task role, KMS        │
        └────────────────────────────────────────────┘

        ┌────────────────────────────────────────────┐
        │  Azure (Commercial / Government)            │
        │   App Gateway ─► App Service/AKS ─► Azure DB │
        │   Key Vault, Managed Identity, Entra ID      │
        └────────────────────────────────────────────┘
```

On every container start, `bin/start.sh` runs `scripts/migrate.php` (idempotent —
applies `schema.sql` only when the `users` table is absent) and then execs
`apache2-foreground`. The web tier is stateless and can be scaled to N replicas
behind any load balancer; all state remains in the single Postgres instance.
