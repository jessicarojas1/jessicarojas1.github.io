# VERITY — Architecture

> Phases 1–3 of an 8-phase build are implemented and tested. See
> [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md) for exactly what is and is not built.

## Platform

Verity is an **Enterprise Identity & Access Governance (IGA)** platform —
*"Unified Visibility. Verified Access. Complete Accountability."* It is a
plain **PHP 8.2+** application (tested on PHP 8.5) with **zero Composer
dependencies** and **PostgreSQL 14+** (via PDO) as its only stateful tier.
There is no Node build, no frontend framework, no Redis, no queue, and no
background worker — everything a request needs happens inside that request.

## Design principles

- **Server-side authorization, always.** Every permission check happens in
  the controller, before any query runs. Navigation hiding is cosmetic only
  — see "Authorization model" below.
- **Honesty over placeholders.** A capability that isn't built is omitted or
  clearly labeled "mock", never faked. The Dashboard omits KPIs for modules
  that don't exist rather than showing a misleading zero; the GCC High
  connector is explicitly a catalog-only mock; `/auth/sso` returns a real 503
  instead of pretending to sign anyone in.
- **One place for each piece of trust logic.** The Enterprise Access Matrix
  query engine (`Matrix`) performs no authorization logic of its own — it
  trusts the `$scope` the controller hands it. That keeps reporting-chain and
  ownership scoping in exactly one place (`Authorize`), not duplicated
  between the query layer and the controller.
- **Audit everything material, change nothing retroactively.** `audit_event`
  is append-only by convention in application code (no code path issues
  `UPDATE`/`DELETE` against it); production deployments should also enforce
  this at the database-role level (see `docs/SECURITY.md`).
- **Configuration over hardcoding.** All environment-specific values —
  including the Microsoft GCC High endpoints — come from environment
  variables, never from constants in code.

## Component overview

| Layer | Path | Responsibility |
|---|---|---|
| Front controller | `public/index.php` | Manual switch-based router (no framework), security headers, CSP nonce, `/health`, `/api/*` dispatch |
| HTTP controllers | `app/Http/*Controller.php` | One per module; call `Auth::requireAuth()` + `Authorize::requirePermission()` before touching data, validate CSRF on every POST, require views |
| API router | `app/Http/ApiRouter.php` | Minimal versioned REST surface (`/api/v1/matrix` today); reuses the exact same `Authorize` engine as the HTML routes — never a parallel check |
| Support services | `app/Support/*.php` | Framework + domain logic: `Db`, `Security`, `Session`, `Config`, `Auth`, `Authorize`, `Roles`, `PermissionCatalog`, `Audit`, plus one class per module (`People`, `Accounts`, `Applications`, `Connectors`, `Matrix`, `DynamicFields`, `Users`, `Settings`, `SavedViews`, `Dashboard`) |
| Views | `app/Views/*.php` | Plain PHP templates; all output escaped via `Security::h()`; no inline event handlers or `style=""` attributes anywhere |
| Static assets | `public/assets/*.{css,js}` | `app.css` utility classes (no hardcoded hex colors — CSS custom properties only); `confirm.js` is a single delegated `data-confirm` click listener shared by every authenticated page; module-specific JS (`matrix.js`, `iam.js`, `accounts.js`, `settings.js`) |
| Database | `database/schema.sql` | One idempotent script, 17 tables, `CREATE TABLE IF NOT EXISTS` throughout, section-commented by module |

### Request flow

`public/index.php` sets security headers and the CSP nonce, then branches on
path: `/health` (no auth), `/api/*` (session-based auth inside `ApiRouter`),
everything else starts a session and switches on an explicit route table to
one controller method. There is no middleware pipeline and no implicit
routing — every route is a literal `case` in the switch statement, which
makes "does this route require auth?" answerable by reading the one
controller method it calls.

## Configuration model

Everything configurable comes from environment variables, read through
`Config` (`app/Support/Config.php`). `app/bootstrap.php` loads an optional
local `.env` (never committed — see `.env.example`) only when the real
process environment doesn't already define a key, so a real deployment's
environment always wins over a stray `.env` file. Verified variables (no
others exist):

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `development` | `production` disables `display_errors` and causes `database/seed.php` to refuse to run |
| `DATABASE_URL` | *(none)* | PostgreSQL connection string; the app degrades honestly (not fake data) when unset |
| `DB_SCHEMA` | *(none — defaults to the connection's own default, normally `public`)* | Optional dedicated Postgres schema, for sharing a database with another application without any table-name collision risk. When set, `Db::connection()` runs `CREATE SCHEMA IF NOT EXISTS` (idempotent) and `SET search_path` to it immediately after connecting — every unqualified table reference in the app, `schema.sql`, and `seed.php` then resolves inside it automatically |
| `GRAPH_BASE_URL` | `https://graph.microsoft.us` | Microsoft Graph endpoint reserved for the not-yet-built GCC High connector |
| `ENTRA_AUTHORITY_HOST` | `https://login.microsoftonline.us` | Entra authority endpoint, read but not yet consumed by a sign-in flow |
| `AZURE_PORTAL_URL` | `https://portal.azure.us` | Azure Government portal link, read but not yet consumed |
| `ENTRA_TENANT_ID` / `ENTRA_CLIENT_ID` / `ENTRA_CLIENT_SECRET` / `ENTRA_REDIRECT_URI` | *(none)* | Entra app registration values; `Config::entraConfigured()` reports whether all four are present, but nothing acts on them yet |

GCC High endpoints default to the `.us` government cloud, never the
commercial `.com` endpoints — intentional, since the only connector this app
will ever target in its current design is GCC High, even though that
connector is a mock today.

## Request & error contract

Verified directly from the controllers and `public/index.php`:

| Code | When |
|---|---|
| `302` | Unauthenticated visit to `/` or an authenticated-only page → `/auth/login`; successful login/logout redirects |
| `400` | CSRF validation failure on a POST (`Security::validateCsrf()`), or a missing required field (e.g. saved-view name) |
| `401` | `/api/*` call with no session; `Auth`'s internal `fail()` path for an inconsistent session |
| `403` | `Authorize::requirePermission()` denial — plain-text `403 Forbidden` body, and an `authz.deny.<permission>` row written to `audit_event` before the response is sent |
| `404` | Unknown route in the front controller; unknown `/api/*` path; a detail view (e.g. account) whose id doesn't resolve |
| `405` | A POST-only route hit with any other verb (explicit `http_response_code(405)` calls in `public/index.php`) |
| `503` | `GET /auth/sso` — Entra GCC High SSO is honestly reported as not implemented rather than faking a sign-in |

`GET /health` returns `{"status":"ok","app":"verity","db":<bool>}` before any
session or auth logic runs, so it reflects raw process/DB reachability.

## Security model

Full detail lives in [`SECURITY.md`](SECURITY.md); in summary: local
email/password authentication with native PHP sessions (`VERITY_SID`,
HttpOnly, SameSite=Lax, Secure over HTTPS); a three-state authorization model
(role default → explicit grant → explicit deny, denials always win) layered
with two DB-backed scoping mechanisms described below; strict CSP with a
per-request nonce and zero inline handlers or inline `style=""`; CSRF tokens
on every POST, rotated after sensitive AJAX saves; parameterized SQL only.

### The standout design decision: dual DB-backed authorization scoping

Verity has no program/company tenancy to lean on the way a multi-tenant SaaS
product would, so `Authorize` (`app/Support/Authorize.php`) implements two
narrower scoping mechanisms directly against the identity data it already
has, beyond plain role/grant/deny membership:

1. **Reporting-chain scope** (permissions ending in `.reports`, and
   `matrix.view.supervisor`). A supervisor's effective permission set only
   covers people in their own transitive reporting chain. Given a caller and
   a `subject_person_id` in the authorization context, `Authorize` runs a
   recursive CTE that walks **up** from the subject through
   `person.manager_person_id` and checks whether the caller's own
   `person_id` appears in that chain:

   ```sql
   WITH RECURSIVE chain AS (
       SELECT id, manager_person_id FROM person WHERE id = :subject
       UNION ALL
       SELECT p.id, p.manager_person_id FROM person p JOIN chain c ON p.id = c.manager_person_id
   )
   SELECT EXISTS(SELECT 1 FROM chain WHERE id = :sup) AS in_chain
   ```

   A companion query (`Authorize::reportsOf()`) walks the chain in the other
   direction — **down** from a supervisor — to build the person-id list the
   Matrix query uses for the "Supervisor" view. This was verified end to end
   in this build: a supervisor requesting another department's identity
   detail page gets a genuine `403` (confirmed by an isolated-fixture DB test
   — `tests/db_test.php` — and reproduced live in this review), not merely a
   hidden nav link.

2. **Application-ownership scope** (permissions ending in `.owned`). A
   system owner's effective permissions only reach applications where they
   are recorded as `application.system_owner_person_id`
   (`Authorize::ownsApplication()` — a direct, parameterized lookup).

Both checks run inside `Authorize::can()`/`requirePermission()` *after* the
plain role/grant/deny check passes, so a denial always wins regardless of
scope, and every denial is audited via `Audit::denied()` before the 403 is
returned. Controllers pass the context (`subject_person_id` or
`application_id`) explicitly and the Matrix query engine itself contains no
authorization logic — there is exactly one place this can go wrong, not two.

## Observability

What exists today, verified against the code — nothing more:

- **`audit_event`** — every authentication, administrative action,
  correlation/link change, and permission denial writes a row (actor,
  action, target, before/after JSON, justification, correlation id, IP,
  result). `Audit::log()` falls back to `error_log()` when no database is
  configured, so events are never silently dropped even in a database-less
  dev session.
- **PHP `error_log`** — runtime errors (`display_errors` off in production,
  `log_errors` on always) and the audit fallback above.
- **`GET /health`** — a liveness/readiness probe consumed by the Dockerfile
  `HEALTHCHECK` and Render's `healthCheckPath`.

There is no metrics endpoint, no distributed tracing, no log aggregation
integration, and no alerting — see `OPEN_ITEMS.md`.

## Deployment topology

A single PHP process (today: PHP's built-in server, `php -S`, inside the
Docker image) talking to a single PostgreSQL instance. No cache tier, no
queue, no worker process, no load balancer in this build. Sessions are
in-process PHP sessions tied to the cookie `VERITY_SID`; there is no shared
session store, which is a constraint on horizontally scaling to multiple app
instances (see `docs/DISASTER_RECOVERY.md` → High Availability). Full
per-target deployment instructions live in `../deployments/` and
`docs/DEPLOYMENT.md`.

## Monorepo placement & internal layout

Lives in the `jessicarojas1.github.io` repo under `verity/`:

```
verity/
├─ public/
│  ├─ index.php          Front controller: routing, security headers, CSP nonce, /health, /api/*
│  └─ assets/            app.css, confirm.js, matrix.js, iam.js, accounts.js, settings.js
├─ app/
│  ├─ bootstrap.php      PSR-4 autoload (Composer if present, else built-in) + .env loading
│  ├─ Http/              One controller per module + ApiRouter
│  ├─ Support/           Framework services + one class per module
│  └─ Views/             Plain PHP templates + partials/
├─ database/
│  ├─ schema.sql         Idempotent reference schema (17 tables)
│  └─ seed.php           Synthetic development data generator (refuses on APP_ENV=production)
├─ tests/                Zero-dependency harness (tests/lib/T.php), run.php, unit_test.php, db_test.php
├─ docs/                 This file + DEPLOYMENT, DISASTER_RECOVERY, SECURITY
├─ deployments/          Per-target operator guides
├─ Dockerfile, render.yaml, composer.json, .env.example
└─ CLAUDE.md
```
