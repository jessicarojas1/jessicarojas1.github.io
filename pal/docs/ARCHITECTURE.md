# PAL — Architecture

This document describes the architecture of PAL (Enterprise Process Asset Library) **as it is implemented today** and marks where the target design (the [Phase 0 Architecture Proposal](PHASE0_ARCHITECTURE_PROPOSAL.md) and the [ADRs](adr/)) goes further.

> **Status.** Phase 1 (foundation) is implemented: sign-in, directory sync, role mapping, permissions, branding settings, the audit trail, health checks, the container and CI. **Documents, revisions, workflow, publication, storage, search, previews, scanning and notifications are not built yet** (Phases 2–6). Anything marked *Planned — Phase N* does not exist in the code.

Related guides: [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [Decision log](DECISION_LOG.md) · [Requirements traceability](REQUIREMENTS_TRACEABILITY.md)

---

## Contents

1. [Platform](#1-platform)
2. [Design principles](#2-design-principles)
3. [Component overview](#3-component-overview)
4. [Repository placement and internal layout](#4-repository-placement-and-internal-layout)
5. [Configuration model](#5-configuration-model)
6. [Request and error contract](#6-request-and-error-contract)
7. [Security model (summary)](#7-security-model-summary)
8. [Observability](#8-observability)
9. [Deployment topology](#9-deployment-topology)
10. [Implemented vs. planned](#10-implemented-vs-planned)

---

## 1. Platform

| Layer | Technology (verified in code) | Notes |
|---|---|---|
| Backend language | PHP 8.4 (container base `php:8.4-fpm-alpine`, CI `php-version: '8.4'`) | `composer.json` declares `"php": ">=8.2"`; the supported runtime is 8.4 (see §10 discrepancies). |
| Backend framework | Symfony 7.4 LTS (`symfony/framework-bundle` v7.4.21 locked) | Security, Messenger, Scheduler, RateLimiter, Lock, HttpClient, Monolog. |
| Data access | Doctrine **DBAL 4 only** (no ORM) + Doctrine Migrations | Invariants are written as explicit SQL (`config/packages/doctrine.yaml`). |
| Database | PostgreSQL 17 in production (16+ supported by `database/schema.sql`) | Also holds HTTP sessions and the Messenger queue. |
| Background jobs | Symfony Messenger (Doctrine transport) + Symfony Scheduler | No Redis, no RabbitMQ. |
| Token validation | `firebase/php-jwt` v7 | RS256 only. |
| Frontend | Vue 3.5 + TypeScript + Quasar 2 + Pinia + Vue Router, built with Vite | Single-page app; same origin as the API (BFF). |
| Web tier | nginx + PHP-FPM in one container, supervised by supervisord, PID 1 = tini | `deploy/nginx/nginx.conf`, `deploy/php/`, `deploy/supervisord.conf`. |
| Identity provider | Microsoft Entra ID via OIDC (sign-in) and Microsoft Graph, read-only (group/user sync) | Owner decision D18. PAL is **not** built on SharePoint or M365. |

Owner decisions that shape the platform (see [Proposal §1a](PHASE0_ARCHITECTURE_PROPOSAL.md#1a-owner-decisions-rev-2)): on-premises Linux is the production target (D2); Entra ID is used for sign-in and read-only group sync only (D18); email will be SMTP only (not built yet); the organisation default timezone is `America/Denver` (D6a); the repository is public, so it contains placeholders only (D12).

## 2. Design principles

1. **Modular monolith** ([ADR-0001](adr/ADR-0001-modular-monolith.md)). One deployable backend plus a worker process from the same image. Module boundaries are enforced in CI by Deptrac (`pal/backend/deptrac.yaml`).
2. **Correctness in the database.** Invariants are expressed in SQL (constraints, triggers, role privileges) rather than hidden in an ORM. Today this covers the append-only audit trail; the effectivity exclusion constraint and immutable revisions are *Planned — Phases 3–5*.
3. **Audit in the same transaction.** Every audited change and its audit row commit or roll back together (`AuditLogger::record()` joins the caller's transaction).
4. **Server-side authority.** The browser never holds OIDC tokens (BFF, [ADR-0004](adr/ADR-0004-authentication-bff.md)); every authorization decision is made in the backend.
5. **Fail closed, leak nothing.** Unknown errors return a generic JSON body with a correlation ID; health responses never include connection details.
6. **Configuration by environment.** The same image runs in every environment; differences are environment variables only.
7. **Idempotent operations.** Migrations, `database/schema.sql`, `deploy/sql/roles.sql` and the directory sync are safe to re-run. Messenger delivery is at-least-once, so handlers must be idempotent.

## 3. Component overview

```
                          Browser (Vue 3 SPA, no tokens in JS)
                                     │  HTTPS, same origin, session cookie
                                     ▼
                       Reverse proxy / load balancer (TLS)        <- operator-provided
                                     │  HTTP :8080, X-Forwarded-*
┌────────────────────────────────────▼─────────────────────────────────────────┐
│ pal container, role "web"  (tini -> supervisord)                             │
│  ┌──────────────────────────────┐      ┌───────────────────────────────────┐ │
│  │ nginx :8080                  │      │ PHP-FPM 127.0.0.1:9000            │ │
│  │  /            -> SPA (CSP)   │─────▶│ Symfony kernel (public/index.php) │ │
│  │  /assets/     -> immutable   │ fcgi │  Platform  Identity  Audit        │ │
│  │  /api /auth /health -> FPM   │      │  Settings                         │ │
│  └──────────────────────────────┘      └───────────────┬───────────────────┘ │
└────────────────────────────────────────────────────────┼─────────────────────┘
                                                         │ DBAL + PDO (sessions)
┌───────────────────────────────────┐                    ▼
│ pal container, role "worker"      │        ┌───────────────────────────────┐
│  messenger:consume async          │───────▶│ PostgreSQL 17                 │
│                    scheduler_default       │  users, groups, memberships,  │
│  - SyncDirectory     every 15 min │        │  role mappings, settings,     │
│  - VerifyAuditChain  daily 02:15Z │        │  audit_event (append-only),   │
└──────────┬────────────────────────┘        │  sessions, messenger_messages │
           │                                 └───────────────────────────────┘
           │ optional: append chain head to PAL_AUDIT_ANCHOR_DIR (write-once storage)
           ▼
   Outbound HTTPS only:  Entra ID (OIDC discovery, JWKS, token endpoint)
                         Microsoft Graph v1.0 (read-only group/user sync)
   Logs: JSON on stdout/stderr -> host log collector -> Microsoft Sentinel (ADR-0011)

   One-shot roles from the same image: "migrate" (Doctrine migrations + audit revokes),
   "console" (any bin/console command).
```

### 3.1 Backend modules (`pal/backend/src/`)

| Module | Responsibility | Key classes |
|---|---|---|
| **Platform** | Cross-cutting HTTP and runtime concerns | `Http/RequestIdSubscriber` (X-Request-Id), `Http/SecurityHeadersSubscriber`, `Http/ApiExceptionSubscriber` + `ApiProblem` (JSON error contract), `Http/JsonBody` (strict JSON decoding), `Http/RequestContext` (correlation ID), `Health/HealthController`, `Logging/CorrelationIdProcessor`, `Database/Row` (typed row access), `Scheduler/PalSchedule` |
| **Audit** | Hash-chained, append-only audit trail | `AuditLogger`, `AuditCanonicalizer`, `AuditChainVerifier`, `AuditAnchorWriter`, `Command/VerifyAuditChainCommand` (`pal:audit:verify`), `Message/VerifyAuditChainHandler` |
| **Identity** | Sign-in, users, directory sync, roles, permissions | `Oidc/*` (client, settings, provider metadata, ID-token validator, client credentials, pending authorizations), `Security/*` (callback authenticator, user provider, CSRF header, session lifetime, API rate limit, entry point, access-denied handler, logout, return-to validation), `Authorization/*` (`GlobalRole`, `Permission`, `RolePermissionMap`, `RoleResolver`, `PermissionVoter`), `Directory/*` (`DirectoryAdapter`, `GraphDirectoryAdapter`, `DirectorySynchronizer`, repositories), `User/*`, `Controller/*`, `Command/*` (`pal:roles:map`, `pal:directory:sync`) |
| **Settings** | Organisation settings, branding | `SettingsRepository`, `Branding`, `BrandingSanitizer`, `Controller/BrandingController` |

Allowed dependencies (`deptrac.yaml`): Platform → Audit, Identity (only so the scheduler can wire their messages); Audit → Platform; Identity → Platform, Audit, Settings; Settings → Platform, Audit, Identity. CI fails on any other dependency.

*Planned — Phases 2–6:* Repository, Revisions, Workflow, Publication, Notifications, Search, Administration, Reporting modules ([ADR-0001](adr/ADR-0001-modular-monolith.md)); converter (Gotenberg) and malware scanner (ClamAV) containers ([Proposal §7](PHASE0_ARCHITECTURE_PROPOSAL.md#7-database-storage-search-preview-jobs)).

### 3.2 HTTP endpoints (implemented)

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/health/live` | Public (no firewall) | Liveness: process is up |
| GET | `/health/ready` | Public (no firewall) | Readiness: database reachable and no pending migrations; 503 otherwise |
| GET | `/auth/login?returnTo=` | Public, rate-limited | Starts OIDC authorization-code + PKCE flow |
| GET | `/auth/callback` | Public, rate-limited (same `auth_login` limiter, per client IP) | Completes sign-in (handled by `OidcCallbackAuthenticator`) |
| POST | `/api/session/logout` | Authenticated + CSRF | Ends the PAL session; returns Entra end-session URL if published |
| GET | `/api/me` | Authenticated | User, roles, effective permissions, CSRF token, session limits |
| PATCH | `/api/me/preferences` | Authenticated + CSRF | Sets the user's timezone (or `null` for the org default) |
| GET | `/api/branding` | Public | Effective branding for the sign-in screen and shell |
| PUT | `/api/admin/branding` | `system.config` + CSRF | Updates branding |
| GET | `/api/admin/role-mappings` | `admin.roles` | Lists group → role mappings |
| POST | `/api/admin/role-mappings` | `admin.roles` + CSRF | Adds a mapping (group is resolved in Graph first). Refused with 403 `self_grant_forbidden` when the caller is a member of the group and the role is `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`. The first membership sync runs synchronously; for a large group it may time out, but the mapping is already saved and the next scheduled sync completes it. |
| DELETE | `/api/admin/role-mappings/{id}` | `admin.roles` + CSRF | Removes a mapping |

### 3.3 Frontend (`pal/frontend/src/`)

| Area | Files | Notes |
|---|---|---|
| API client | `api/client.ts` | Same-origin `fetch` wrapper. Only `/api/` paths; sends `X-CSRF-Token` on non-safe methods; maps errors to `ApiError(status, code, message, fields, correlationId)`; calls an unauthorized handler on 401. |
| State | `stores/session.ts`, `stores/branding.ts` | Session (`/api/me`) and branding (Pinia). |
| Routing | `router/index.ts` | `/sign-in` (public), `/` dashboard, `settings/preferences`, `settings/branding` (`system.config`), `admin/roles` (`admin.roles`), forbidden, not-found. Route `meta.permission` is a **UX hint only**; the API enforces every permission. |
| Theming | `branding.ts`, `theme.ts`, `components/BrandMark.vue` | Logo URL check (`https://` or raster `data:image/...`; `http://` is rejected), hex colour check, WCAG AA contrast helpers (`contrastRatio`, `onAccent`, `accentForText` against a 4.5:1 target). |

## 4. Repository placement and internal layout

PAL lives under `pal/` in this (public, portfolio) repository. At handover it moves to a private repository ([Proposal §18.3](PHASE0_ARCHITECTURE_PROPOSAL.md#183-handover-to-the-private-company-repo-d12)).

```
pal/
├── backend/                Symfony application
│   ├── bin/console         CLI entry point
│   ├── config/             packages/*.yaml (framework, security, messenger, monolog, doctrine), services.yaml
│   ├── migrations/         Doctrine migrations (authoritative schema installer)
│   ├── public/index.php    front controller (served by PHP-FPM via nginx)
│   ├── src/                Platform/ Audit/ Identity/ Settings/ (+ Kernel.php)
│   ├── tests/              Unit/ Integration/ Functional/ Support/
│   ├── deptrac.yaml        module boundary rules
│   ├── phpstan*.neon       static analysis
│   └── .env.example        configuration template (no secrets)
├── frontend/               Vue 3 + Quasar SPA (Vite); src/ as in §3.3
├── database/schema.sql     idempotent manual-setup reference; parity-tested against migrations
├── deploy/
│   ├── compose.yaml        single-host Docker Compose (web, worker, migrate, optional bundled db)
│   ├── docker-entrypoint.sh roles: web | worker | migrate | console
│   ├── nginx/nginx.conf    SPA + FastCGI routing, SPA CSP, JSON access log
│   ├── php/                php.ini, fpm-pool.conf
│   ├── supervisord.conf    nginx + PHP-FPM for the web role
│   ├── sql/roles.sql       least-privilege DB roles (owner / app); init-roles.sh for the bundled db
│   ├── secrets/            Docker secret files (git-ignored)
│   └── pal.env.example     runtime configuration template
├── deployments/            operator guides per target (LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED)
├── docs/                   this guide, DEPLOYMENT, SECURITY, DISASTER_RECOVERY, Phase 0 proposal, ADRs, decision log, traceability
├── Dockerfile              multi-stage, non-root image
└── render.yaml             Render Blueprint (demo/evaluation only)
```

CI for PAL is `.github/workflows/pal-ci.yml` at the repository root.

## 5. Configuration model

- All runtime configuration is **environment variables**. Symfony reads `.env` (in the image, a copy of `.env.example`, which holds no secrets) and real environment variables always override it.
- Variables are consumed in `config/packages/*.yaml` (`%env(...)%`) and in services via `#[Autowire(env: ...)]`. The complete list, verified by grep, is in [DEPLOYMENT.md §4](DEPLOYMENT.md#4-configuration-and-secrets).
- The container entrypoint refuses to start a role when its required variables are empty (exit code 64): `web` needs `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; `worker` and `migrate` need `APP_SECRET`, `DATABASE_URL`.
- OIDC settings are validated **when sign-in is used**, not at boot (`OidcSettings::configurationProblem()`), so health checks and tooling work before Entra is configured. Exactly one client credential must be configured (secret *or* certificate + key).
- Organisation settings that administrators change at runtime (branding; the default timezone row) live in the `org_setting` table, not in environment variables.

## 6. Request and error contract

### 6.1 Requests

- **Same origin.** The SPA, `/api`, `/auth` and `/health` are served from one origin by nginx. In development, Vite proxies these paths to the backend (`frontend/vite.config.ts`).
- **JSON bodies** are decoded by `JsonBody::decode()`: `Content-Type` must start with `application/json` (else **415**), body ≤ 64 KiB by default (else **413**; the branding endpoint allows ~404 KB for a logo data URL), depth ≤ 32, and the top level must be a JSON object (else **400** `invalid_json`). nginx caps request bodies at 2 MB and PHP `post_max_size` is 2M.
- **CSRF.** Every `/api` request whose method is not GET, HEAD or OPTIONS must send the session's token in `X-CSRF-Token` (token ID `pal_api`, obtained from `GET /api/me` as `csrfToken`). Missing or invalid → **403** `csrf_invalid`. `/api/session/logout` accepts POST only (other methods → **405**).
- **Correlation.** nginx forwards a well-formed upstream `X-Request-Id` (including one sent by the client itself, so the correlation ID is caller-influenced and must not be treated as unique or trustworthy) (`^[A-Za-z0-9-]{8,64}$`) or its own `$request_id`. Symfony accepts the same pattern, otherwise generates a UUIDv4, and echoes it as `X-Request-Id` on every response. The same ID is attached to every log record (`extra.correlation_id`) and stored in each audit event (`correlation_id`).

### 6.2 Error body

API errors on `/api`, `/auth` and `/health` are JSON:

```json
{ "error": "machine_code", "message": "Human-readable, safe to show", "fields": { "field": "problem" }, "correlationId": "uuid" }
```

`fields` appears only on validation errors; `correlationId` only on 500s. 401 responses also carry `loginUrl: "/auth/login"`.

| Status | `error` | Produced by |
|---|---|---|
| 400 | `invalid_json`, `bad_request` | `JsonBody`, generic 400 |
| 401 | `unauthenticated` (+`loginUrl`) | `ApiAuthenticationEntryPoint` (never a redirect) |
| 401 | `session_expired` (+`loginUrl`) | `SessionLifetimeSubscriber` (absolute lifetime exceeded) |
| 403 | `forbidden` | `ApiAccessDeniedHandler` (permission denied) |
| 403 | `csrf_invalid` | `CsrfHeaderSubscriber` |
| 403 | `self_grant_forbidden` | Role-mapping API: caller is a member of the group being mapped to `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR` |
| 404 | `not_found` | Unknown route or missing resource |
| 405 | `method_not_allowed` | Router; logout via non-POST |
| 409 | `conflict`, `mapping_exists` | Generic 409; duplicate role mapping |
| 413 | `payload_too_large` | `JsonBody` |
| 415 | `unsupported_media_type` | `JsonBody` |
| 422 | `validation_failed` (+`fields`) | `ApiProblem::validation()` |
| 429 | `too_many_requests` (+`Retry-After` header) | `ApiRateLimitSubscriber` |
| 500 | `internal_error` (+`correlationId`) | `ApiExceptionSubscriber`; the exception is logged, never returned |
| 502 | `directory_lookup_failed` | Graph group lookup failed |
| 503 | `directory_unavailable` | Directory sync not configured |

Sign-in endpoints are browser navigations, so their failures **redirect** to `/sign-in?auth_error=<code>` instead of returning JSON. Codes: `too_many_attempts`, `sign_in_unavailable`, `sign_in_failed`, `sign_in_cancelled`, `sign_in_expired`, `account_disabled`.

## 7. Security model (summary)

Full detail in [SECURITY.md](SECURITY.md).

- **Authentication:** Entra ID OIDC authorization-code flow with PKCE (S256), single-use `state` and `nonce`, RS256 ID-token validation (issuer, audience, tenant, nonce, lifetime with 120 s leeway), keys pinned to RS256, JWKS refreshed once on an unknown `kid`. Users are keyed by immutable `(tid, oid)`.
- **Sessions:** server-side in PostgreSQL (`PdoSessionHandler`, advisory-lock mode), `HttpOnly`, `SameSite=Lax`, session-only cookie; `__Host-pal_session` + `Secure` in the container image. Idle limit `PAL_SESSION_IDLE_SECONDS`, absolute limit `PAL_SESSION_ABSOLUTE_SECONDS`. Session ID migrated on sign-in.
- **Request protection:** `X-CSRF-Token` header on state-changing API calls; rate limits on `/auth/login` and `/auth/callback` (`auth_login`, 20/min per client IP) and `/api` (600/min per user, or per IP before sign-in; counters are per replica); `TRUSTED_HOSTS` Host-header allowlist; `TRUSTED_PROXIES`; open-redirect-safe `returnTo`.
- **Authorization:** five global roles mapped from directory groups; 21 granular permissions checked with `#[IsGranted]` and `PermissionVoter`. System Administrator holds no document authority, and nobody can map a group they belong to onto a document role (`self_grant_forbidden`). Entra **Assignment required = Yes** is mandatory, because every account that can sign in becomes a Reader.
- **Audit:** append-only `audit_event` table protected by triggers, database role separation, a SHA-256 hash chain verified daily, and optional external anchoring of the chain head.
- **Headers:** strict CSP and hardening headers from Symfony on API responses; a separate SPA CSP (`script-src 'self'`, no inline scripts) from nginx; HSTS when the proxy reports HTTPS.

## 8. Observability

| Signal | Implementation |
|---|---|
| Application logs | Monolog, production handler writes **JSON to `php://stderr`** at `LOG_LEVEL` (default `info`), excluding the `event` and `deprecation` channels. Every record has `extra.correlation_id`. |
| Audit/security log stream | Monolog channel **`pal_audit`**. `AuditLogger` emits one `audit_event` record per audit row (event ID, type, actor snapshot, subject, state change). Forwarded to Microsoft Sentinel by the host's collector ([ADR-0011](adr/ADR-0011-logging-to-microsoft-sentinel.md)). The database row stays the system of record. The log line is written **inside** the database transaction, so a transaction that later rolls back can leave a phantom SIEM record with no matching audit row; reconcile against the database by `event_id`. |
| Access logs | nginx JSON access log on stdout (`time`, `request_id`, `remote_addr`, method, path, status, bytes, duration, user agent). Query strings are not logged (`$uri`). |
| PHP-FPM / nginx errors | stderr, via supervisord. |
| Health | `/health/live` (liveness) and `/health/ready` (database `SELECT 1` + no pending migrations). The image `HEALTHCHECK` polls `/health/live`. |
| Audit integrity | Daily `VerifyAuditChain` job; a broken chain is logged at `critical` (`Audit chain verification FAILED`) and deliberately **not** written to the audit table. |

Gaps (to be tracked in [OPEN_ITEMS.md](../OPEN_ITEMS.md)): there is no metrics endpoint and no OpenTelemetry instrumentation yet (listed in the Phase 1 scope of [Proposal §16](PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)); the `pal_audit` channel is written to the **same** stderr stream as operational logs, distinguished by its `channel` field, not to a separate stream as ADR-0011 describes; the uptime-monitoring target is undecided (D17).

## 9. Deployment topology

One image, four roles (`Dockerfile`, `deploy/docker-entrypoint.sh`):

| Role | Command | Process | Scaling |
|---|---|---|---|
| `web` (default) | `docker run pal web` | supervisord → nginx :8080 + PHP-FPM 127.0.0.1:9000 | Stateless; horizontally scalable (sessions are in PostgreSQL) |
| `worker` | `docker run pal worker` | `messenger:consume async scheduler_default`, exits after `PAL_WORKER_TIME_LIMIT` s (default 3600) or `PAL_WORKER_MEMORY_LIMIT` (default 256M) for the orchestrator to restart | ≥1. The schedule holds a PostgreSQL advisory lock, so extra workers do not double-fire jobs. |
| `migrate` | `docker run pal migrate` | Doctrine migrations, then re-applies audit revokes to `PAL_APP_DB_ROLE` | One-shot before web/worker start |
| `console` | `docker run pal console <cmd>` | Any `bin/console` command | One-shot |

The selected production target is **on-premises Linux with Docker Compose** (`deploy/compose.yaml`; decision D2): TLS terminates at a reverse proxy on the host, the web container binds `127.0.0.1:8080`, containers run read-only with all capabilities dropped and `no-new-privileges`, and PostgreSQL runs either externally (recommended: its own host, per [Proposal §14.2](PHASE0_ARCHITECTURE_PROPOSAL.md#142-option-a-on-premises-linux-selected-d2)) or bundled with `--profile bundled-db`. `render.yaml` exists for demo/evaluation only. Per-target guides are in [`../deployments/`](../deployments/) and summarised in [DEPLOYMENT.md](DEPLOYMENT.md).

## 10. Implemented vs. planned

| Capability | Status |
|---|---|
| OIDC sign-in, sessions, CSRF, rate limits, security headers | Implemented (Phase 1) |
| Graph group/user sync, group → role mapping, permission voter | Implemented (Phase 1) |
| Branding settings, user timezone preference | Implemented (Phase 1) |
| Hash-chained append-only audit trail, daily verification, file anchoring | Implemented (Phase 1) |
| Document/type/number entities, upload pipeline, blob storage (filesystem/NAS, S3), full-text search | *Planned — Phase 2* |
| Revisions, content freeze, manifest hashing, native editor (TipTap) | *Planned — Phase 3* |
| Workflow engine, SoD at decision time, re-authentication for approvals | *Planned — Phase 4* |
| Scheduled publication, effectivity exclusion constraint, supersession, legal hold | *Planned — Phase 5* |
| Previews (PDF.js, Gotenberg), notifications (SMTP), reports, audit export, as-of view, `/admin/audit/verify` UI | *Planned — Phase 6* |
| Confluence importer | *Planned — Phase 6b* |
| Category/document-scoped access grants to groups ([ADR-0010](adr/ADR-0010-group-based-access.md)) | *Planned — Phase 2+* |
| Metrics / OpenTelemetry | Not implemented (in Phase 1 scope, outstanding) |

**Known differences between the code and the Phase 0 design** (recorded so they are resolved deliberately, not silently):

- The DB owner role is named `pal_owner` in `deploy/sql/roles.sql`; the proposal calls it `pal_migrator`.
- The proposal describes a separate `pal-scheduler` process ("exactly 1 scheduler"); the implementation runs the scheduler inside each worker and relies on an advisory lock for single firing.
- Global roles come from **group mappings only**; Entra app roles (mentioned in ADR-0004 / Proposal §12 as an alternative) are not read.
- The `audit_event` table has no `actor_ip` or document/revision/workflow/publication foreign-key columns yet; the sign-in-failure event stores the client IP inside `changes`.
- No message is routed to the `async` Messenger transport in Phase 1; the worker currently only does scheduled work.
- `config/packages/doctrine.yaml` cites "ADR-0013" for the DBAL-only decision; no ADR-0013 exists in `docs/adr/`.
- `composer.json` allows PHP `>=8.2` while the stated platform is PHP 8.4+.
