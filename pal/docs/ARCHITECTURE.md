# PAL — Architecture

This document describes the architecture of PAL (Enterprise Process Asset Library) **as it is implemented today** and marks where the target design (the [Phase 0 Architecture Proposal](PHASE0_ARCHITECTURE_PROPOSAL.md) and the [ADRs](adr/)) goes further.

> **Status.** Phase 1 (foundation) and Phase 2 (repository + walking skeleton) are implemented: Entra and local-account sign-in, directory sync, role mapping, permissions, branding, the audit trail, health checks, the container and CI; documents with immutable revisions, scanned uploads into a content-addressed blob store, a single-approver workflow bound to a manifest hash, immediate publication with an exclusion constraint, document grants and library search. **Multi-stage workflows, scheduled publication, the native editor, previews and notifications are not built yet** (Phases 3–6). Anything marked *Planned — Phase N* does not exist in the code.

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
| Identity provider | Microsoft Entra ID via OIDC (sign-in) and Microsoft Graph, read-only (group/user sync); **local username/password accounts** alongside it | Owner decision D18; local accounts added by [ADR-0014](adr/ADR-0014-local-accounts.md). PAL is **not** built on SharePoint or M365. |
| File storage | Content-addressed, write-once filesystem/NAS store under `PAL_BLOB_DIR` | [ADR-0006](adr/ADR-0006-immutable-content-addressed-storage.md). S3 backend *Planned — Phase 6*. |
| Malware scanning | ClamAV daemon (`clamd`) over TCP, INSTREAM protocol; `clamav/clamav:1.5.4` in Compose and Render | Fails closed. |
| File type detection | PHP `fileinfo` (libmagic) | Type decided from bytes, never from the name. |

Owner decisions that shape the platform (see [Proposal §1a](PHASE0_ARCHITECTURE_PROPOSAL.md#1a-owner-decisions-rev-2)): on-premises Linux is the production target (D2); Entra ID is used for sign-in and read-only group sync only (D18); email will be SMTP only (not built yet); the organisation default timezone is `America/Denver` (D6a); the repository is public, so it contains placeholders only (D12).

## 2. Design principles

1. **Modular monolith** ([ADR-0001](adr/ADR-0001-modular-monolith.md)). One deployable backend plus a worker process from the same image. Module boundaries are enforced in CI by Deptrac (`pal/backend/deptrac.yaml`).
2. **Correctness in the database.** Invariants are expressed in SQL (constraints, triggers, role privileges) rather than hidden in an ORM. Today this covers the append-only audit trail, immutable approved revisions and draft-only files (triggers), one open draft per document (partial unique index), one terminal decision per task, and one effective/visible publication per document and scope (`EXCLUDE USING gist`).
3. **Audit in the same transaction.** Every audited change and its audit row commit or roll back together (`AuditLogger::record()` joins the caller's transaction).
4. **Server-side authority.** The browser never holds OIDC tokens (BFF, [ADR-0004](adr/ADR-0004-authentication-bff.md)); every authorization decision is made in the backend.
5. **Fail closed, leak nothing.** Unknown errors return a generic JSON body with a correlation ID; health responses never include connection details.
6. **Configuration by environment.** The same image runs in every environment; differences are environment variables only.
7. **Idempotent operations.** Migrations, `database/schema.sql`, `deploy/sql/roles.sql`, the directory sync, `pal:users:bootstrap-admin`, blob writes (content-addressed) and release are safe to re-run. Messenger delivery is at-least-once, so handlers must be idempotent.
8. **Nothing unscanned is stored.** Bytes enter PAL only through `UploadService`; a file is promoted to the blob store only after the type check and a clean scan. If the scanner is missing or unreachable, the upload is refused.

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
│  │  /api /auth /health -> FPM   │      │  Settings  Storage   Documents    │ │
│  │  /api/uploads: 52m bodies    │      └──────┬────────────┬───────┬──────┘ │
│  └──────────────────────────────┘             │            │       │        │
└───────────────────────────────────────────────┼────────────┼───────┼────────┘
     INSTREAM (TCP 3310)                        │            │       │ write-once files
   ┌────────────────────────┐◀──────────────────┘            │       ▼
   │ clamd (ClamAV)         │                                │  PAL_BLOB_DIR volume
   │ signatures via         │                                │  sha256/ab/cd/<hash>
   │ freshclam              │                                │  (filesystem / NAS)
   └────────────────────────┘                                │ DBAL + PDO (sessions)
┌───────────────────────────────────┐                        ▼
│ pal container, role "worker"      │        ┌───────────────────────────────┐
│  messenger:consume async          │───────▶│ PostgreSQL 17                 │
│                    scheduler_default       │  users, local credentials,    │
│  - SyncDirectory     every 15 min │        │  groups, roles, settings,     │
│  - VerifyAuditChain  daily 02:15Z │        │  documents, revisions, files, │
│  - SyncDirectoryGroup  on demand  │        │                               │
└──────────┬────────────────────────┘        │  workflow, publications,      │
           │                                 │  audit_event (append-only),   │
           │                                 │  sessions, messenger_messages │
           │                                 └───────────────────────────────┘
           │ optional: append chain head to PAL_AUDIT_ANCHOR_DIR (write-once storage)
           ▼
   Outbound HTTPS only:  Entra ID (OIDC discovery, JWKS, token endpoint)
                         Microsoft Graph v1.0 (read-only group/user sync)
   Logs: JSON on stdout/stderr -> host log collector -> Microsoft Sentinel (ADR-0011)

   One-shot roles from the same image: "migrate" (Doctrine migrations, first local admin
   bootstrap, audit revokes), "console" (any bin/console command).
   Only the web role needs the blob volume and clamd; the worker uses neither in Phase 2.
```

### 3.1 Backend modules (`pal/backend/src/`)

| Module | Responsibility | Key classes |
|---|---|---|
| **Platform** | Cross-cutting HTTP and runtime concerns | `Http/RequestIdSubscriber` (X-Request-Id), `Http/SecurityHeadersSubscriber`, `Http/ApiExceptionSubscriber` + `ApiProblem` (JSON error contract), `Http/JsonBody` (strict JSON decoding), `Http/RequestContext` (correlation ID), `Health/HealthController` + `Health/ReadinessProbe` (interface, tag `pal.readiness_probe`, so modules add readiness checks without Platform depending on them), `Logging/CorrelationIdProcessor`, `Database/Row` (typed row access), `Scheduler/PalSchedule` |
| **Audit** | Hash-chained, append-only audit trail | `AuditLogger`, `AuditCanonicalizer`, `AuditChainVerifier`, `AuditAnchorWriter`, `Command/VerifyAuditChainCommand` (`pal:audit:verify`), `Message/VerifyAuditChainHandler` |
| **Identity** | Sign-in, users, directory sync, roles, permissions | `Oidc/*` (client, settings, provider metadata, ID-token validator, client credentials, pending authorizations), `Security/*` (callback authenticator, user provider, CSRF header, session lifetime, API rate limit, entry point, access-denied handler, logout, return-to validation), `Authorization/*` (`GlobalRole`, `Permission`, `RolePermissionMap`, `RoleResolver` — group-mapped plus explicit `user_role` grants, `PermissionVoter`), `Directory/*` (`DirectoryAdapter`, `GraphDirectoryAdapter`, `DirectorySynchronizer`, repositories), `Local/*` (`LocalAccountService`: verify with lockout, create, change/reset password; `PasswordPolicy`), `Security/LocalPasswordAuthenticator`, `Security/PasswordChangeRequiredSubscriber`, `User/*`, `Controller/*` (incl. `UserAdminController`, `SignInMethodsController`, `UserSearchController`), `Command/*` (`pal:roles:map`, `pal:directory:sync`, `pal:users:bootstrap-admin`, `pal:users:unlock`) |
| **Settings** | Organisation settings, branding | `SettingsRepository`, `Branding`, `BrandingSanitizer`, `Controller/BrandingController` |
| **Storage** | The only way bytes enter PAL | `UploadService` (size → hash → type → scan → promote; every attempt recorded), `FileTypePolicy` (libmagic allow-list + extension match, filename sanitising), `ClamdScanner` (INSTREAM, fail-closed) behind `MalwareScanner`, `FilesystemBlobStore` (write-to-temp, verify hash, `0440`, atomic rename, never overwrite) behind `BlobStore`, `StorageReadinessProbe`, `Controller/UploadController` |
| **Documents** | Repository, revisions, approval skeleton, publication, access | `LifecycleService` (create, edit, attach, submit, decide, reopen, withdraw, new revision, release with verify-on-release), `ManifestHasher`, `DocumentAccess` (single access policy, PHP and SQL forms), `GrantService`, `LibraryQuery` (search, workspace, tasks), `DocumentRepository`, `DocumentPresenter`, `ReferenceRepository`, `Controller/*` (documents, revisions, reference admin) |

Allowed dependencies (`deptrac.yaml`): Platform → Audit, Identity (only so the scheduler can wire their messages); Audit → Platform; Identity → Platform, Audit, Settings; Settings → Platform, Audit, Identity; Storage → Platform, Audit, Identity; Documents → Platform, Audit, Identity, Storage. CI fails on any other dependency.

*Planned — Phases 3–6:* Workflow (engine), Publication (scheduling), Notifications, Reporting modules ([ADR-0001](adr/ADR-0001-modular-monolith.md)); converter (Gotenberg) container ([Proposal §7](PHASE0_ARCHITECTURE_PROPOSAL.md#7-database-storage-search-preview-jobs)).

### 3.2 HTTP endpoints (implemented)

| Method | Path | Access | Purpose |
|---|---|---|---|
| GET | `/health/live` | Public (no firewall) | Liveness: process is up |
| GET | `/health/ready` | Public (no firewall) | Readiness: database reachable, no pending migrations, the DB schema no newer than this image (`schema_version`), blob directory present and writable (`storage`); 503 otherwise. ClamAV is **not** part of readiness |
| GET | `/auth/login?returnTo=` | Public, rate-limited | Starts OIDC authorization-code + PKCE flow |
| GET | `/auth/callback` | Public, rate-limited (same `auth_login` limiter, per client IP) | Completes sign-in (handled by `OidcCallbackAuthenticator`) |
| GET | `/auth/methods` | Public | Login CSRF token and sign-in methods with `connected` flags (labels and booleans only). Okta, Google Workspace and SAML are always `connected: false` |
| POST | `/auth/password` | Public, login CSRF, throttled per IP and per username | Local sign-in (handled by `LocalPasswordAuthenticator`); returns `{ok, mustChangePassword}` or a uniform 401 `invalid_credentials` |
| POST | `/api/session/logout` | Authenticated + CSRF | Ends the PAL session; returns Entra end-session URL if published |
| GET | `/api/me` | Authenticated | User, roles, effective permissions, CSRF token, session limits |
| POST | `/api/me/password` | Authenticated + CSRF | Local account: change own password (current password required). 409 `not_local_account` for SSO users |
| PATCH | `/api/me/preferences` | Authenticated + CSRF | Sets the user's timezone (or `null` for the org default) |
| GET | `/api/branding` | Public | Effective branding for the sign-in screen and shell |
| PUT | `/api/admin/branding` | `system.config` + CSRF | Updates branding |
| GET | `/api/admin/role-mappings` | `admin.roles` | Lists group → role mappings |
| POST | `/api/admin/role-mappings` | `admin.roles` + CSRF | Adds a mapping (group is resolved in Graph first). Refused with 403 `self_grant_forbidden` when the caller is a member of the group and the role is `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`. For those document-authority roles the membership sync runs synchronously (the self-grant check needs fresh membership before granting); for every other role the initial sync is dispatched to the `async` transport (`SyncDirectoryGroup`) so a large group cannot time out the request. |
| DELETE | `/api/admin/role-mappings/{id}` | `admin.roles` + CSRF | Removes a mapping |
| GET | `/api/admin/users` | `admin.users` | Lists users with sign-in method, lock state, explicit and group-inherited roles (`?q=`, max 500) |
| POST | `/api/admin/users` | `admin.users` + CSRF | Creates a local account; returns a temporary password once. 403 `role_escalation_forbidden` when giving a document-authority role without holding `PAL_ADMINISTRATOR` |
| PUT | `/api/admin/users/{id}/roles` | `admin.users` + CSRF | Replaces explicit roles. 403 `self_grant_forbidden` / `role_escalation_forbidden` / `owner_grant_forbidden` (only an `OWNER` may grant or revoke the `OWNER` superuser role); 409 `last_administrator` |
| PUT | `/api/admin/users/{id}/active` | `admin.users` + CSRF | Activates/deactivates. 409 `cannot_deactivate_self` / `last_administrator` |
| POST | `/api/admin/users/{id}/reset-password` | `admin.users` + CSRF | New temporary password, returned once. 403 `role_escalation_forbidden` when the account holds a document-authority role and the caller does not hold `PAL_ADMINISTRATOR` |
| GET | `/api/users?q=` | Authenticated | People picker: active users' names and emails (min 2 characters, max 20 results) |
| GET | `/api/reference` | Authenticated | Active document types and departments, plus `maxUploadBytes` (the configured `PAL_MAX_UPLOAD_BYTES`) |
| GET | `/api/library` | Authenticated | Published documents the user may see (`q`, `type`, `department`, `page`; page size 25; authorization in SQL) |
| GET | `/api/workspace` | Authenticated | My documents, my open tasks; documents awaiting release for holders of `publication.release` |
| POST | `/api/uploads` | Authenticated + CSRF, 30/hour per user + daily quota | Multipart field `file`; runs the upload pipeline (§3.4). The only endpoint accepting large bodies |
| POST | `/api/documents` | `document.create` + CSRF | Creates a document and its first draft |
| GET | `/api/documents/{id}` | Visibility check, else 404 | Document, revisions, available actions |
| GET | `/api/documents/{id}/approvers` | Owner or author, else 404 | Eligible approvers (excludes the owner and anyone who contributed to the draft) |
| POST | `/api/documents/{id}/revisions` | Owner or author + CSRF, else 404 | Starts the next revision of a published document |
| POST / DELETE | `/api/documents/{id}/grants`, `…/grants/{grantId}` | Owner or `document.manage_access` + CSRF | Adds or revokes a grant to a person or directory group |
| PATCH / PUT | `/api/revisions/{id}`, `/api/revisions/{id}/file` | Owner or author + CSRF, `DRAFT` only | Edits draft metadata (optimistic `row_version`, 409 `edit_conflict`); attaches a clean upload by its uploader |
| POST | `/api/revisions/{id}/submit`, `/reopen`, `/withdraw` | Owner or author + CSRF | Submit to one eligible approver (freezes the manifest hash); reopen a returned revision; withdraw with a reason |
| POST | `/api/tasks/{id}/decision` | Assignee + CSRF, else 404 | `APPROVE`, `REJECT` or `REQUEST_CHANGES`; re-checks SoD, eligibility and the manifest hash |
| POST | `/api/revisions/{id}/release` | `publication.release` + CSRF | Idempotent immediate release; re-verifies hashes of metadata and stored bytes |
| GET | `/api/revisions/{id}/file` | Read access to that revision, else 404 | Streams the file as an attachment (`nosniff`, sandbox CSP); logged on `pal_audit` as `document_downloaded` |
| GET | `/api/admin/reference` | `admin.types` | All types and departments, including inactive |
| POST / PATCH | `/api/admin/departments[/{id}]` | `admin.departments` + CSRF | Departments |
| POST / PATCH | `/api/admin/document-types[/{id}]` | `admin.types` + CSRF | Document types |

Until a temporary password is changed, every `/api` path except `/api/me`, `/api/me/password`, `/api/session/logout` and `/api/branding` answers 403 `password_change_required`.

### 3.3 Frontend (`pal/frontend/src/`)

| Area | Files | Notes |
|---|---|---|
| API client | `api/client.ts` | Same-origin `fetch` wrapper. Only `/api/` paths; sends `X-CSRF-Token` on non-safe methods; maps errors to `ApiError(status, code, message, fields, correlationId)`; calls an unauthorized handler on 401. |
| State | `stores/session.ts`, `stores/branding.ts` | Session (`/api/me`) and branding (Pinia). |
| Routing | `router/index.ts` | `/sign-in` and `/unavailable` (public), `/change-password` (forced while a temporary password is set), `/` dashboard, `library`, `workspace`, `documents/new` (`document.create`), `documents/:id`, `admin/users` (`admin.users`), `admin/reference` (`admin.types`), `admin/roles` (`admin.roles`), `settings/preferences`, `settings/branding` and `settings/sign-in` (`system.config`), forbidden, not-found. Route `meta.permission` is a **UX hint only**; the API enforces every permission. |
| Theming | `branding.ts`, `theme.ts`, `components/BrandMark.vue` | Logo URL check (`https://` or raster `data:image/...`; `http://` is rejected), hex colour check, WCAG AA contrast helpers (`contrastRatio`, `onAccent`, `accentForText` against a 4.5:1 target). |

### 3.4 Upload and lifecycle flow

**Upload** (`UploadService`, one way in): per-user rate limit (`upload`, 30/hour) → non-empty → daily quota (`PAL_UPLOAD_DAILY_BYTES`, clean bytes in any 24 h) → size (`PAL_MAX_UPLOAD_BYTES`) → SHA-256 → libmagic type + extension match (PDF, DOCX, XLSX, PPTX, TXT; no macro or legacy Office) → clamd INSTREAM → `BlobStore::put` → `stored_file` + `upload` rows and the audit event in one transaction. Blobs are written first, so a failed database write leaves at most an unreferenced blob, never a dangling reference. Rejected, infected and errored attempts are recorded in `upload` as evidence and never reach the store.

**Lifecycle** (`LifecycleService`): `DRAFT → IN_WORKFLOW` on submit, which freezes `content_manifest_hash` (SHA-256 over metadata plus each file's role, hash and name, `ManifestHasher`). A decision recomputes the hash (409 `content_changed`) and stores it on `approval_decision`. `APPROVED` revisions are immutable (trigger). Release re-hashes metadata and the stored bytes (409 `approval_integrity_failed`), writes one `publication` row, closes the previous periods (supersession) and is idempotent. `PUBLISHED`/`SUPERSEDED` are derived from `publication`, not stored on the revision ([ADR-0007](adr/ADR-0007-lifecycle-state-decomposition.md)).

**Visibility:** `DocumentAccess` is the single policy, in PHP for single objects and as SQL for lists. Not visible means 404, never 403. Default audience is all employees; restricted documents only reach grantees.

## 4. Repository placement and internal layout

PAL lives under `pal/` in this (public, portfolio) repository. At handover it moves to a private repository ([Proposal §18.3](PHASE0_ARCHITECTURE_PROPOSAL.md#183-handover-to-the-private-company-repo-d12)).

```
pal/
├── backend/                Symfony application
│   ├── bin/console         CLI entry point
│   ├── config/             packages/*.yaml (framework, security, messenger, monolog, doctrine), services.yaml
│   ├── migrations/         Doctrine migrations (authoritative schema installer)
│   ├── public/index.php    front controller (served by PHP-FPM via nginx)
│   ├── src/                Platform/ Audit/ Identity/ Settings/ Storage/ Documents/ (+ Kernel.php)
│   ├── tests/              Unit/ Integration/ Functional/ Support/
│   ├── deptrac.yaml        module boundary rules
│   ├── phpstan*.neon       static analysis
│   └── .env.example        configuration template (no secrets)
├── frontend/               Vue 3 + Quasar SPA (Vite); src/ as in §3.3
├── database/schema.sql     idempotent manual-setup reference; parity-tested against migrations
├── deploy/
│   ├── compose.yaml        single-host Docker Compose (web, worker, migrate, clamd, optional bundled db)
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
- The container entrypoint refuses to start a role when its required variables are empty (exit code 64): `web` needs `APP_SECRET`, `DATABASE_URL`, `PAL_BASE_URL`, `TRUSTED_HOSTS`; `worker` and `migrate` need `APP_SECRET`, `DATABASE_URL`. It also tolerates its own path as the first argument, for platforms that replace the image `ENTRYPOINT` with their start command.
- Storage and scanning: `PAL_BLOB_DIR` (image default `/srv/pal/data/blobs`), `PAL_MAX_UPLOAD_BYTES` (app default 52428800; `deploy/pal.env.example` and `render.yaml` set 26214400 to stay within ClamAV's default `StreamMaxLength`), `PAL_UPLOAD_DAILY_BYTES`, `PAL_CLAMD_ADDRESS`, `PAL_ALLOW_UNSCANNED_UPLOADS`. An empty `PAL_CLAMD_ADDRESS` means uploads are refused (fail closed), unless `PAL_ALLOW_UNSCANNED_UPLOADS=1` is set as an explicit opt-out — honoured in any environment, but logged as an error and recorded as `none (unscanned)` in production.
- OIDC settings are validated **when sign-in is used**, not at boot (`OidcSettings::configurationProblem()`), so health checks and tooling work before Entra is configured. Exactly one client credential must be configured (secret *or* certificate + key).
- Organisation settings that administrators change at runtime (branding; the default timezone row) live in the `org_setting` table, not in environment variables.

## 6. Request and error contract

### 6.1 Requests

- **Same origin.** The SPA, `/api`, `/auth` and `/health` are served from one origin by nginx. In development, Vite proxies these paths to the backend (`frontend/vite.config.ts`).
- **JSON bodies** are decoded by `JsonBody::decode()`: `Content-Type` must start with `application/json` (else **415**), body ≤ 64 KiB by default (else **413**; the branding endpoint allows ~404 KB for a logo data URL), depth ≤ 32, and the top level must be a JSON object (else **400** `invalid_json`). nginx caps request bodies at 2 MB everywhere except `POST /api/uploads` (52 MB, 120 s timeouts); PHP allows `upload_max_filesize` 50M and `post_max_size` 52M; the application limit is `PAL_MAX_UPLOAD_BYTES`.
- **CSRF.** Every `/api` request whose method is not GET, HEAD or OPTIONS must send the session's token in `X-CSRF-Token` (token ID `pal_api`, obtained from `GET /api/me` as `csrfToken`). Missing or invalid → **403** `csrf_invalid`. `POST /auth/password` uses a separate login token (ID `pal_login`, from `GET /auth/methods`), checked before the credentials. `/api/session/logout` accepts POST only (other methods → **405**).
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
| 400 | `upload_failed` | The file did not arrive completely |
| 400 | `invalid_request` | Malformed `POST /auth/password` body |
| 401 | `invalid_credentials` | `POST /auth/password`: any failure, including a locked account or a bad login CSRF token |
| 401 | `unauthenticated` (+`loginUrl`) | `ApiAuthenticationEntryPoint` (never a redirect) |
| 401 | `session_expired` (+`loginUrl`) | `SessionLifetimeSubscriber` (absolute lifetime exceeded) |
| 403 | `forbidden` | `ApiAccessDeniedHandler` (permission denied) |
| 403 | `csrf_invalid` | `CsrfHeaderSubscriber` |
| 403 | `self_grant_forbidden` | Role-mapping API: caller is a member of the group being mapped to `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`; users API: caller gives themselves such a role |
| 403 | `role_escalation_forbidden` | Users API: conferring `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`, or resetting the password of an account holding one, without holding `PAL_ADMINISTRATOR` |
| 403 | `password_change_required` | `PasswordChangeRequiredSubscriber`: temporary password not yet changed |
| 403 | `separation_of_duties`, `approver_revoked` | Decision by an owner or contributor; decision by someone no longer eligible |
| 404 | `not_found` | Unknown route or missing resource |
| 405 | `method_not_allowed` | Router; logout via non-POST |
| 409 | `conflict`, `mapping_exists`, `grant_exists` | Generic 409; duplicate role mapping or grant |
| 409 | `edit_conflict`, `invalid_state`, `draft_exists`, `not_published`, `not_approved`, `document_not_active`, `task_closed`, `content_changed`, `approval_integrity_failed` | Lifecycle rules |
| 409 | `cannot_deactivate_self`, `last_administrator`, `not_local_account` | Users API, password change |
| 413 | `payload_too_large` | `JsonBody` |
| 413 | `file_too_large` | Upload above `PAL_MAX_UPLOAD_BYTES` or PHP's limit |
| 415 | `unsupported_media_type` | `JsonBody` |
| 422 | `validation_failed` (+`fields`) | `ApiProblem::validation()` |
| 422 | `file_empty`, `file_type_not_allowed`, `malware_detected` | Upload pipeline |
| 429 | `too_many_requests` (+`Retry-After` header) | `ApiRateLimitSubscriber` |
| 429 | `too_many_attempts` | `POST /auth/password` throttled |
| 429 | `too_many_uploads`, `upload_quota_exceeded` | Per-user upload rate limit; daily quota |
| 500 | `internal_error` (+`correlationId`) | `ApiExceptionSubscriber`; the exception is logged, never returned |
| 502 | `directory_lookup_failed` | Graph group lookup failed |
| 503 | `directory_unavailable` | Directory sync not configured |
| 503 | `scanner_unavailable` | ClamAV unset, unreachable or returned an error: the upload is refused (fail closed) |

Sign-in endpoints are browser navigations, so their failures **redirect** to `/sign-in?auth_error=<code>` instead of returning JSON. Codes: `too_many_attempts`, `sign_in_unavailable`, `sign_in_failed`, `sign_in_cancelled`, `sign_in_expired`, `account_disabled`.

## 7. Security model (summary)

Full detail in [SECURITY.md](SECURITY.md).

- **Local accounts** ([ADR-0014](adr/ADR-0014-local-accounts.md)): argon2id hashes, 12–128 character policy, lockout after 5 consecutive failures for 15 minutes, throttling per IP and per username, uniform failure responses, login CSRF before credentials, forced change of temporary passwords enforced by the server, and optional temporary-password expiry (`PAL_TEMP_PASSWORD_MAX_AGE_HOURS`, off by default).
- **Authentication:** Entra ID OIDC authorization-code flow with PKCE (S256), single-use `state` and `nonce`, RS256 ID-token validation (issuer, audience, tenant, nonce, lifetime with 120 s leeway), keys pinned to RS256, JWKS refreshed once on an unknown `kid`. Users are keyed by immutable `(tid, oid)`.
- **Sessions:** server-side in PostgreSQL (`PdoSessionHandler`, advisory-lock mode), `HttpOnly`, `SameSite=Lax`, session-only cookie; `__Host-pal_session` + `Secure` in the container image. Idle limit `PAL_SESSION_IDLE_SECONDS`, absolute limit `PAL_SESSION_ABSOLUTE_SECONDS`. Session ID migrated on sign-in.
- **Request protection:** `X-CSRF-Token` header on state-changing API calls; rate limits on `/auth/login`, `/auth/callback` and `/auth/password` (`auth_login`, 20/min per client IP), password attempts per username (`login_username`, 10 per 15 min), uploads per user (`upload`, 30/hour) and `/api` (600/min per user, or per IP before sign-in; counters are per replica); `TRUSTED_HOSTS` Host-header allowlist; `TRUSTED_PROXIES`; open-redirect-safe `returnTo`.
- **Authorization:** five global roles from directory-group mappings and explicit per-user grants; 25 granular permissions checked with `#[IsGranted]`, `PermissionVoter` and `DocumentAccess`; document grants to people and groups. System Administrator holds no document authority; nobody can map a group they belong to onto a document role or grant themselves one (`self_grant_forbidden`), confer a document role or reset the password of an account holding one without holding `PAL_ADMINISTRATOR` (`role_escalation_forbidden`, users API), or remove the last active System Administrator (`last_administrator`). Owners and contributors cannot approve their revision. Entra **Assignment required = Yes** is mandatory, because every account that can sign in becomes a Reader.
- **Uploads:** content-based type allow-list, ClamAV fail-closed, write-once content-addressed storage, per-user rate limit and daily quota; downloads are always attachments with a sandbox CSP.
- **Audit:** append-only `audit_event` table protected by triggers, database role separation, a SHA-256 hash chain verified daily, and optional external anchoring of the chain head.
- **Headers:** strict CSP and hardening headers from Symfony on API responses; a separate SPA CSP (`script-src 'self'`, no inline scripts) from nginx; HSTS when the proxy reports HTTPS.

## 8. Observability

| Signal | Implementation |
|---|---|
| Application logs | Monolog, production handler writes **JSON to `php://stderr`** at `LOG_LEVEL` (default `info`), excluding the `event` and `deprecation` channels. Every record has `extra.correlation_id`. |
| Audit/security log stream | Monolog channel **`pal_audit`**. `AuditLogger` emits one `audit_event` record per audit row (event ID, type, actor snapshot, subject, state change). Forwarded to Microsoft Sentinel by the host's collector ([ADR-0011](adr/ADR-0011-logging-to-microsoft-sentinel.md)). The database row stays the system of record. The log line is written **inside** the database transaction, so a transaction that later rolls back can leave a phantom SIEM record with no matching audit row; reconcile against the database by `event_id`. |
| Access logs | nginx JSON access log on stdout (`time`, `request_id`, `remote_addr`, method, path, status, bytes, duration, user agent). Query strings are not logged (`$uri`). |
| PHP-FPM / nginx errors | stderr, via supervisord. |
| Health | `/health/live` (liveness) and `/health/ready` (database `SELECT 1`, no pending migrations, `schema_version` — the DB has no migration this image lacks, so an old replica during a rolling deploy is pulled out — plus module probes: today `storage`, which fails when `PAL_BLOB_DIR` is missing or not writable). Responses list each check as `ok` or `fail`. The image `HEALTHCHECK` polls `/health/live`. ClamAV outages show up as `ClamAV is unreachable` errors and `UPLOAD_REJECTED` audit events, not in readiness. |
| Audit integrity | Daily `VerifyAuditChain` job; a broken chain is logged at `critical` (`Audit chain verification FAILED`) and deliberately **not** written to the audit table. |

Gaps (to be tracked in [OPEN_ITEMS.md](../OPEN_ITEMS.md)): there is no metrics endpoint and no OpenTelemetry instrumentation yet (listed in the Phase 1 scope of [Proposal §16](PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)); the `pal_audit` channel is written to the **same** stderr stream as operational logs, distinguished by its `channel` field, not to a separate stream as ADR-0011 describes; the uptime-monitoring target is undecided (D17).

## 9. Deployment topology

One image, four roles (`Dockerfile`, `deploy/docker-entrypoint.sh`):

| Role | Command | Process | Scaling |
|---|---|---|---|
| `web` (default) | `docker run pal web` | supervisord → nginx :8080 + PHP-FPM 127.0.0.1:9000 | Sessions are in PostgreSQL; document files are on the `PAL_BLOB_DIR` volume, so several instances must share that volume (NFS/NAS) |
| `worker` | `docker run pal worker` | `messenger:consume async scheduler_default`, exits after `PAL_WORKER_TIME_LIMIT` s (default 3600) or `PAL_WORKER_MEMORY_LIMIT` (default 256M) for the orchestrator to restart | ≥1. The schedule holds a PostgreSQL advisory lock, so extra workers do not double-fire jobs. |
| `migrate` | `docker run pal migrate` | Doctrine migrations, then `pal:users:bootstrap-admin`, then re-applies audit revokes to `PAL_APP_DB_ROLE` | One-shot before web/worker start |
| `console` | `docker run pal console <cmd>` | Any `bin/console` command | One-shot |

The selected production target is **on-premises Linux with Docker Compose** (`deploy/compose.yaml`; decision D2): TLS terminates at a reverse proxy on the host, the web container binds `127.0.0.1:8080`, containers run read-only with all capabilities dropped and `no-new-privileges` (the `pal-blobs` volume is the one writable data path, mounted at `/srv/pal/data` on `web`), `clamd` runs as a companion service with its own `pal-clamav` signature volume, and PostgreSQL runs either externally (recommended: its own host, per [Proposal §14.2](PHASE0_ARCHITECTURE_PROPOSAL.md#142-option-a-on-premises-linux-selected-d2)) or bundled with `--profile bundled-db`. `render.yaml` exists for demo/evaluation only ([DEPLOYMENT.md §12](DEPLOYMENT.md#12-render-demo--evaluation)). Per-target guides are in [`../deployments/`](../deployments/) and summarised in [DEPLOYMENT.md](DEPLOYMENT.md).

## 10. Implemented vs. planned

| Capability | Status |
|---|---|
| OIDC sign-in, sessions, CSRF, rate limits, security headers | Implemented (Phase 1) |
| Local accounts, users admin, forced password change, env bootstrap | Implemented ([ADR-0014](adr/ADR-0014-local-accounts.md)) |
| Okta, Google Workspace, SAML sign-in | **Not implemented**; listed as "Not connected" |
| Graph group/user sync, group → role mapping, permission voter | Implemented (Phase 1) |
| Branding settings, user timezone preference | Implemented (Phase 1) |
| Hash-chained append-only audit trail, daily verification, file anchoring | Implemented (Phase 1) |
| Document/type/number entities, upload pipeline, filesystem/NAS blob store, metadata full-text search | Implemented (Phase 2) |
| Revisions, content freeze, manifest hashing, single-approver workflow, SoD at decision time, immediate release, effectivity exclusion constraint, supersession | Implemented (Phase 2, skeleton depth) |
| Document-level grants to people and groups | Implemented (Phase 2) |
| S3 blob backend, full-text of file content | *Planned — Phase 6* |
| Configurable numbering, minor versions, comparison, rollback, native editor (TipTap), category grants | *Planned — Phase 3* |
| Multi-stage workflow engine, delegation, escalation, re-authentication for approvals | *Planned — Phase 4* |
| Scheduled publication, separate effective dates, emergency publish, obsolete/archive, legal hold | *Planned — Phase 5* |
| Previews (PDF.js, Gotenberg), notifications (SMTP), reports, audit export, as-of view, `/admin/audit/verify` UI | *Planned — Phase 6* |
| Confluence importer | *Planned — Phase 6b* |
| Metrics / OpenTelemetry | Not implemented (in Phase 1 scope, outstanding) |

**Known differences between the code and the Phase 0 design** (recorded so they are resolved deliberately, not silently):

- The DB owner role is named `pal_owner` in `deploy/sql/roles.sql`; the proposal calls it `pal_migrator`.
- The proposal describes a separate `pal-scheduler` process ("exactly 1 scheduler"); the implementation runs the scheduler inside each worker and relies on an advisory lock for single firing.
- Global roles come from **group mappings and explicit `user_role` grants**; Entra app roles (mentioned in ADR-0004 / Proposal §12 as an alternative) are not read.
- ADR-0006 describes a quarantine area and Azure/S3 backends. The implementation scans PHP's upload temp file in place and only promotes it afterwards (no separate quarantine directory), and has only the filesystem backend. The ADR's "application identity cannot delete or overwrite" depends on the operator: the code never does, but the app user owns the files (`0440`, directories `0750`) unless the storage denies deletion.
- Upload is checked by the `BlobStore` hash at write and at release, not on every download.
- The `audit_event` table has no `actor_ip` or document/revision/workflow/publication foreign-key columns yet; the sign-in-failure event stores the client IP inside `changes`.
- The only producer on the `async` Messenger transport is `SyncDirectoryGroup`, dispatched when a non-document-authority role mapping is added so the initial membership pull runs off-request (I8). It is low-volume (one message per mapping, an admin action), so one worker still suffices. The worker otherwise does scheduled work and does not touch blobs or ClamAV.
- `composer.json` allows PHP `>=8.2` while the stated platform is PHP 8.4+.
