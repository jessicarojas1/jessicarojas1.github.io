# PAL Platform — Architecture

## Overview

PAL is a server-rendered PHP 8 application backed by PostgreSQL. It deliberately avoids
heavyweight frameworks in favour of a small, fully auditable MVC core. A single front
controller (`index.php`) bootstraps the environment, configures secure sessions, sets
security headers, and dispatches the request to a controller method via a static + regex
route table. Controllers query the database through a thin PDO wrapper, enforce
authorization, mutate state, write an immutable audit record, and render a view.

```
Request
  │
  ▼
index.php  ── env load ─ session ─ CSP/security headers ─ autoload core
  │
  ├─ /health, /healthz, /readyz   → JSON probes (no auth)
  ├─ /api/*                       → api/index.php (API-key or session auth, JSON)
  ├─ /api/docs                    → Swagger UI
  │
  ▼  route table (static + regex)
Controller::method()
  │  Auth::requireAuth() / requirePermission()    ← authorization
  │  Security::validateCsrf()                      ← CSRF (POST)
  │  Security::sanitizeInput()/sanitizeHtml()      ← input handling
  │  Database::fetch*/insert/update (parameterized)
  │  Auth::log(...)                                ← hash-chained audit
  ▼
View (ob_start → $content → views/layout.php)      ← Security::h() output escaping
```

## Core classes (`src/`)

| Class | Responsibility |
|---|---|
| `Database` | PDO singleton; parameterized `query/fetchOne/fetchAll/insert/update`. `update()` auto-stamps `updated_at`. |
| `Security` | CSP nonce + headers, CSRF tokens, Argon2id hashing, password policy, HTML sanitizer, rate limiting, API-key generation/validation, AES-256-GCM setting encryption. |
| `Auth` | Session principal, RBAC (`can`, role defaults + explicit grants + coarse aliases), `requireAuth/requirePermission/requireAdmin`, login/logout, immutable hash-chained `log()`. |
| `Branding` | Display name, logo (data-URI or URL), accent colour; live CSS-variable override. |
| `Storage` | Local or S3-compatible file storage (put/get/delete/url). |
| `Upload` | Upload validation (extension allowlist + size + MIME) → `Storage`. |
| `View` | Presentation helpers (status/priority badges, date/relative-time, avatars, catalogs). |
| `JWT` | Token signing for future integrations. |

## Data model (high level)

- **Identity:** `users`, `user_permissions`, `active_sessions`, `api_keys`, `rate_limits`,
  `settings`, `activity_log` (audit), `alerts` (notifications).
- **Knowledge:** `spaces`, `space_members`; `pages`, `page_versions`.
- **Controlled documents:** `documents`, `document_versions`, `document_acknowledgements`,
  `entity_relations` (related process/risk/control/system).
- **Processes:** `processes` (+ `entity_relations`).
- **Workflow & approvals:** `workflow_templates`, `workflow_steps`, `approval_requests`,
  `approval_request_steps`, `approval_history`.
- **Work & collaboration:** `tasks`, `templates`, `comments`, `watches`, `favorites`,
  `attachments`, `saved_searches`.
- **Taxonomy:** `tags`, `entity_tags`.

`database/schema.sql` is the authoritative, idempotent reference (every object uses
`IF NOT EXISTS` / `ON CONFLICT DO NOTHING`); `database/migrations/*.sql` hold incremental
changes and are replayed by `install.php` on every deploy.

## Approval engine

An approval request is created from a workflow template (or an ad-hoc single approver).
The template's ordered steps are copied into `approval_request_steps`. Behaviour by mode:

- **single / sequential:** the *current* step is actionable; approving it advances
  `current_step` to the next pending step. The request is approved when no pending steps
  remain.
- **parallel / consensus:** every pending step is actionable simultaneously; the request
  is approved only when *all* steps are approved.

Any approver may **reject** (request → rejected) or **return for revision** (request →
returned; a linked document returns to Draft). A linked document automatically moves
Draft → In Review on submission and → Approved on completion. Every transition is recorded
in `approval_history` and the global `activity_log`.

## Security posture

- **CSP:** `script-src 'self' 'nonce-…'` — no `unsafe-inline` scripts; all interactivity
  is `data-*` delegated through `app.js`.
- **Sessions:** HttpOnly, `SameSite=Strict`, `__Host-` cookie name under HTTPS, idle
  timeout, server-side revocation (`users.sessions_revoked_at`).
- **Audit integrity:** each `activity_log` row stores
  `SHA-256(prev_hash | user | action | entity | changes | ip)`, forming a tamper-evident
  chain.
- **At rest:** SMTP/S3 secrets encrypted with AES-256-GCM keyed from `JWT_SECRET`.

## Statelessness & scaling

The application keeps no local state beyond uploaded files (externalizable to S3) — making
it safe to run multiple replicas behind a load balancer. Sessions use PHP's default store
(swap to Redis/database for multi-node), and the DB is the single source of truth.
Health/readiness endpoints support orchestrated rollouts.
