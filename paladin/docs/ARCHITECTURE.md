# PALADIN — Architecture

> Canonical architecture reference for the PALADIN platform. Companion documents:
> [`DEPLOYMENT.md`](DEPLOYMENT.md), [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md),
> [`SECURITY.md`](SECURITY.md), plus the operator guides under
> [`../deployments/`](../deployments/).

---

## 1. Platform & technology

PALADIN is a **server-rendered PHP 8.3 application** backed by **PostgreSQL**. It deliberately
avoids heavyweight frameworks in favour of a small, fully auditable MVC core. A single front
controller (`index.php`) bootstraps the environment, configures secure sessions, sets security
headers, and dispatches each request to a controller method via a static + regex route table.

| Layer | Technology |
|---|---|
| Runtime | PHP 8.3 (Apache `mpm_prefork` + `mod_rewrite`, `mod_headers`), no framework |
| PHP extensions | `pdo`, `pdo_pgsql`, `gd`, `opcache`, `zip`, `sodium` (AES-256-GCM), `curl` |
| Database | PostgreSQL 14+ (schema namespace `paladin`) |
| Front end | Server-rendered PHP views, Bootstrap 5 + Bootstrap Icons, CSP-safe vanilla JS (`public/js/app.js`, `data-*` delegation — no inline handlers) |
| Storage | Pluggable: local mounted volume **or** any S3-compatible store (AWS/GovCloud S3, MinIO, R2) via native SigV4 |
| Mail | SMTP (STARTTLS/SSL) or a `queued` outbox transport (`src/Mailer.php`) |
| Packaging | Single OCI image (`Dockerfile`, `php:8.3-apache`), `docker-compose.yml`, `render.yaml`, `docker/k8s.yaml` |

## 2. Design principles

- **Auditable core** — small, framework-free codebase; every state change is authorized,
  parameterized, and audited.
- **Secure by default** — nonce-based CSP without `unsafe-inline` scripts, Argon2id hashing,
  rotating CSRF, rate limiting, at-rest encryption for sensitive settings.
- **Cloud-agnostic & offline-friendly** — all config via env vars; storage abstracted; no hard
  dependency on any commercial cloud service; runs fully air-gapped.
- **Idempotent installs** — `install.php` + `database/migrations/*.sql` are safe to re-run on
  every boot; rolling deploys are safe.
- **Stateless app tier** — the only local state is uploaded files (externalizable to S3), so the
  app scales horizontally behind a load balancer.

## 3. Request lifecycle & routing

```
Request
  │
  ▼
index.php  ── env load ─ secure session ─ CSP/security headers ─ autoload core
  │
  ├─ GET /health              → JSON: DB + disk check (200 healthy / 503 degraded)
  ├─ GET /healthz, /readyz    → JSON {"status":"ok"} (liveness / readiness)
  ├─ /api/*                   → api/index.php  (Bearer PAT / X-API-Key / session, JSON)
  ├─ /api/docs                → Swagger UI (api/docs.php)
  ├─ /scim/v2/*               → scim/index.php (SCIM 2.0, Bearer token)
  │
  ▼  route table (static map + regex patterns)
Controller::method($params)
  │  Auth::requireAuth() / requirePermission('module.action')   ← authorization
  │  Security::validateCsrf()                                    ← CSRF (POST; rotates token)
  │  Security::sanitizeInput() / sanitizeHtml()                  ← input handling
  │  Database::fetch*/insert/update  (parameterized PDO)
  │  Auth::log(action, entity, id, changes)                     ← hash-chained audit
  │  Webhook::dispatch(event, payload)                          ← optional integration event
  ▼
View (ob_start → $content → views/layout.php)                   ← Security::h() output escaping
```

Routing is a **static route map** (GET + POST arrays) plus **regex patterns** (`#^/documents/(\d+)$#`,
`#^/spaces/(\d+)/edit$#`, …). `dispatch(controller, action, params)` loads the controller file,
instantiates it, and invokes the method via reflection with typed params.

## 4. Request & error contract

**Web (HTML) responses** render `views/layout.php`. A global exception handler in `index.php`
catches every `Throwable`, logs the detail server-side (`[PALADIN] Uncaught: …`), and returns a
generic **HTTP 500 configuration-error page** — no stack traces, SQL, paths, secrets, or env data
are leaked.

**API responses** (`/api/v1`) are always JSON with `Content-Type: application/json` and
`X-API-Version: v1`:

| Shape | Body |
|---|---|
| Single object | `{ "id": 1, "title": "…", … }` |
| List | `{ "data": [ … ], "count": N }` |
| Error | `{ "error": "Human-readable message" }` |

API error codes: `400` invalid, `401` unauthorized, `403` forbidden / missing write scope,
`404` not found (also used to avoid disclosing private-space content), `405` method not allowed,
`422` validation, `429` rate limit exceeded, `500` server error. JSON is encoded with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; script-embedded JSON in views uses
`JSON_HEX_TAG | JSON_HEX_AMP`.

## 5. Core classes (`src/`)

| Class | Responsibility |
|---|---|
| `Database` | PDO singleton; parameterized `query/fetchOne/fetchAll/insert/update`. `update()` auto-stamps `updated_at`. `tableExists()`. |
| `Security` | CSP nonce + headers, CSRF (generate/validate/rotate), Argon2id hashing (`m=65536,t=4,p=2`), password policy, DOM-based HTML sanitizer, DB-backed rate limiting, API-key + PAT generation/validation (HMAC-SHA256), **AES-256-GCM** setting encryption, SSRF guard (`safeOutboundIp`), proxy-aware `clientIp`. |
| `Auth` | Session principal, RBAC (`can`, 9 built-in roles + custom roles + explicit grants + coarse aliases), `requireAuth/requirePermission/requireAdmin`, login/MFA/logout, SSO login, immutable hash-chained `log()`. |
| `Storage` | Local or S3-compatible file storage (`put/get/delete/url`); native **AWS Signature V4** signing; presigned URLs or CDN `s3_public_url`. |
| `Upload` | Upload validation (extension allowlist + size + MIME sniff), randomized stored name, SHA-256 file hash → `Storage`. |
| `Mailer` | `send()` records every message in `mail_outbox`; delivers immediately when `MAIL_TRANSPORT=smtp` (AUTH LOGIN + STARTTLS/SSL), otherwise queues. Never throws. |
| `Scheduler` | Cron-free opportunistic sweeps: `runDuePages()` (scheduled publish), `runExpiredDocuments()` (auto-expiry). |
| `Webhook` | Outbound event delivery (`dispatch`), signed POST (`X-Paladin-Signature: sha256=…`), SSRF-guarded, exponential-backoff `retryDue()`. |
| `Retention` | Retention rules (`preview`, `apply`), `sweepExpired()`, archive/notify actions. |
| `Digest` / `Reminders` | Per-user email digests (`Digest::run`) and document review/expiry reminders (`Reminders::run`). |
| `Saml` / `Oidc` | SAML 2.0 (signed/encrypted assertions) and OIDC (Authorization Code + PKCE S256) SSO. |
| `JWT` | HS256 token sign/verify (`issue`/`verify`) and RS256 verification for OIDC JWKS. |
| `Branding` | Display name, logo (data-URI or URL), accent colour; live CSS-variable override. |
| `View` | Presentation helpers (status/priority badges, dates, avatars, catalogs). |
| Content helpers | `Markdown`, `Diff`, `Docx`, `Pdf`, `Csv`, `Macros`, `Mentions`, `Reactions`, `Recent`, `Activity`, `PageAccess`, `SpaceAccess`, `PageProps`, `PageTasks`, `DocNumbering`, `Blueprint`, `TOTP`. |

## 6. Data model (high level)

All objects live in the PostgreSQL **`paladin`** schema; every object is idempotent
(`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, `ON CONFLICT DO NOTHING`).

| Module | Tables |
|---|---|
| **Identity & access** | `users`, `user_permissions`, `custom_roles`, `custom_role_permissions`, `api_keys`, `personal_access_tokens`, `mfa_recovery_codes`, `active_sessions`, `rate_limits`, `settings`, `alerts` |
| **Spaces & pages** | `spaces`, `space_members`, `space_shortcuts`, `pages`, `page_versions`, `page_restrictions`, `page_tasks`, `page_properties`, `inline_comments`, `blog_posts` |
| **Controlled documents** | `documents`, `document_versions`, `document_acknowledgements`, `ack_campaigns`, `ack_campaign_targets` |
| **Processes & relations** | `processes`, `entity_relations`, `tags`, `entity_tags` |
| **Workflow & approvals** | `workflow_templates`, `workflow_steps`, `wf_states`, `wf_transitions`, `workflow_space_assignments`, `wf_status`, `wf_history`, `approval_requests`, `approval_request_steps`, `approval_history` |
| **Work & collaboration** | `tasks`, `templates`, `comments`, `reactions`, `watches`, `recent_views`, `favorites`, `attachments`, `media`, `saved_searches` |
| **Integrations & automation** | `webhooks`, `webhook_deliveries`, `retention_rules` |
| **Email & audit** | `mail_outbox`, `activity_log` (hash-chained), `schema_migrations` |

`database/schema.sql` is the authoritative, idempotent baseline; `database/migrations/*.sql`
(001–033) carry incremental deltas and are tracked in `schema_migrations`. `install.php` replays
schema + pending migrations on every boot.

## 7. Approval & workflow engine

An approval request is created from a workflow template (or an ad-hoc single approver). The
template's ordered steps are copied into `approval_request_steps`.

- **single / sequential:** the *current* step is actionable; approving advances `current_step`
  to the next pending step; the request completes when no pending steps remain.
- **parallel / consensus:** every pending step is actionable simultaneously; the request is
  approved only when *all* steps are approved.

Any approver may **reject** (→ rejected) or **return for revision** (→ returned; a linked document
returns to Draft). A linked document moves Draft → In Review on submission and → Approved on
completion. When `require_esignature` is enabled, an approve/reject requires the signer to type
their exact name and re-authenticate; the record binds identity/decision/meaning/time/IP/UA into a
SHA-256 `signature_hash`. Every transition is recorded in `approval_history`, `wf_history`, and the
global `activity_log`, and may dispatch a webhook event.

## 8. Background processing model

PALADIN is **cron-free by default**: `Scheduler`, `Webhook::retryDue`, and retention sweeps run
opportunistically on common authenticated requests (e.g. dashboard load). For guaranteed timeliness,
invoke the CLI entrypoints from real cron/systemd timers:

| Entrypoint | Purpose |
|---|---|
| `php cli/send_digests.php [daily\|weekly]` | Per-user notification email digests (`Digest::run`). |
| `php cli/send_review_reminders.php [lookAheadDays] [cooldownDays]` | Document review/expiry reminders (`Reminders::run`). |

See [`DEPLOYMENT.md`](DEPLOYMENT.md) §"Background & scheduled work".

## 9. Configuration model

Configuration is entirely environment-driven (`.env` for dev; secret store for prod) plus a
runtime `settings` table (Admin → Settings). `config/app.php` and `config/database.php` read
`$_ENV`. `DATABASE_URL` (Render/Heroku/Azure/AWS style) is accepted as an alternative to discrete
`DB_*` vars. Sensitive `settings` values (SMTP/S3 secrets, SCIM token) are encrypted at rest with
AES-256-GCM keyed from `JWT_SECRET` — so `JWT_SECRET` is also the master key for at-rest secrets.

## 10. Security model (summary)

- **CSP:** `default-src 'self'`; `script-src 'self' 'nonce-…'` (per-request nonce, no
  `unsafe-inline` scripts); `frame-ancestors 'none'`; `base-uri 'self'`; `form-action 'self'`.
- **Sessions:** HttpOnly, `SameSite=Strict`, `use_strict_mode`, `__Host-PALADIN` cookie + `Secure`
  under HTTPS, idle timeout, server-side revocation (`users.sessions_revoked_at`).
- **AuthZ:** granular `module.action` permissions enforced server-side in controllers + views;
  object-level (anti-IDOR) checks for spaces/pages/attachments.
- **Audit:** `activity_log` rows chain `SHA-256(prev | user | action | entity | changes | ip)` —
  tamper-evident.
- **At rest:** AES-256-GCM for sensitive settings; Argon2id for passwords.

Full detail in [`SECURITY.md`](SECURITY.md), [`PERMISSIONS_MODEL.md`](PERMISSIONS_MODEL.md), and
[`AUDIT_TRAIL.md`](AUDIT_TRAIL.md).

## 11. Observability & health

| Path | Use | Returns |
|---|---|---|
| `GET /health` | Full check (DB `SELECT 1` + `disk_free_space` ≥ 100 MB) | `{"status":"healthy\|degraded","service":"paladin","checks":{"database":"ok\|error","disk":"ok\|low"}}` — 200 healthy / 503 degraded |
| `GET /healthz` | Liveness (process up) | `{"status":"ok"}` (always 200) |
| `GET /readyz` | Readiness | `{"status":"ok"}` (always 200) |
| `GET /api/v1/health` | API health | `{status, service, version, time}` |

Application logs write to `/var/www/html/logs` and container stdout/stderr (ship to your
aggregator). The `activity_log` is the security/compliance event stream. Errors are logged with a
`[PALADIN]` prefix.

## 12. Deployment topology

The single stateless app image runs behind a TLS-terminating reverse proxy / load balancer, with a
managed or self-hosted PostgreSQL and either a mounted uploads volume or an S3-compatible bucket:

```
        ┌────────────┐      ┌──────────────────────┐      ┌────────────────┐
 users ─┤  LB / TLS  ├──────┤  PALADIN app (N×)     ├──────┤  PostgreSQL     │
        │  (proxy)   │      │  php:8.3-apache OCI    │      │  (paladin schema)│
        └────────────┘      │  /health /healthz      │      └────────────────┘
                            │  /readyz               │
                            └───────┬────────────────┘
                                    │ uploads
                          ┌─────────┴──────────┐
                          │ local volume  OR   │
                          │ S3-compatible store │
                          └────────────────────┘
```

`TRUSTED_PROXY_IPS` must list the proxy so client IPs are attributed correctly in the audit log.
Scale replicas freely once uploads are externalized to S3 (and, for >1 node, sessions moved to a
shared store). Target guides live under [`../deployments/`](../deployments/).

## 13. Monorepo placement

PALADIN is a **self-contained standalone project** at `paladin/` within the repository. Its Render
Blueprint (`render.yaml`) and Dockerfile are rooted at the project directory. Internal layout:

```
paladin/
├── index.php              # front controller / router + health probes
├── install.php            # idempotent installer + migration runner + first-run seed
├── router-dev.php         # PHP built-in server router (local, no Docker)
├── config/                # app.php, database.php
├── src/                   # core classes (Database, Security, Auth, Storage, …)
├── controllers/           # one controller per module
├── views/                 # server-rendered templates (+ layout.php, partials/)
├── api/                   # REST API (index.php), Swagger UI (docs.php), openapi.json
├── scim/                  # SCIM 2.0 provisioning endpoint
├── cli/                   # send_digests.php, send_review_reminders.php
├── scripts/startup.sh     # container entrypoint (install → apache)
├── database/              # schema.sql, migrations/ (001–033), seeds/
├── public/                # css/js/vendor assets
├── docker/k8s.yaml        # reference Kubernetes manifest
├── Dockerfile, docker-compose.yml, render.yaml, .env.example
├── docs/                  # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY (+ others)
└── deployments/           # per-target operator guides
```
