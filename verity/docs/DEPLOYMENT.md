# VERITY — Deployment Guide

## Contents

1. [Deployment models](#deployment-models)
2. [Prerequisites](#prerequisites)
3. [Configuration & secrets](#configuration--secrets)
4. [Database setup](#database-setup)
5. [Background processes, Ollama, GPU](#background-processes-ollama-gpu)
6. [Production checklist](#production-checklist)

## Deployment models

Verity is dockerized and Render-deploy compatible. Full per-target operator
instructions live under [`../deployments/`](../deployments/) — this guide
intentionally does not duplicate their content:

| Model | Guide |
|---|---|
| Local development | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| Docker + Render | [`../render.yaml`](../render.yaml), [`../Dockerfile`](../Dockerfile) |
| Kubernetes | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| Azure | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| AWS | [`../deployments/AWS.md`](../deployments/AWS.md) |
| Air-gapped | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |

Kubernetes is listed as a supported future model per the standard doc set —
it is not exercised or hardened for this app today (no Helm chart, no
manifests exist yet in this repo); treat `deployments/KUBERNETES.md` as the
authoritative statement of what that would take.

## Prerequisites

- PHP 8.2+ (CLI; tested on 8.3 in the Docker image and 8.5 in local
  development) with the `pdo_pgsql` extension.
- PostgreSQL 14+.
- No Composer dependencies are required — `composer.json` declares none, and
  `app/bootstrap.php` ships a built-in PSR-4 autoloader that runs with no
  `vendor/` directory at all.

## Configuration & secrets

All configuration is environment variables, read through `Config`
(`app/Support/Config.php`). Never commit a real `.env` — only
`.env.example` (placeholder values) is tracked; the Dockerfile explicitly
deletes any `.env` that happens to be in the build context (`RUN rm -f .env`)
before the image ships.

| Variable | Example | Purpose |
|---|---|---|
| `APP_ENV` | `production` | Disables `display_errors`; `database/seed.php` refuses to run when this is `production` |
| `DATABASE_URL` | `postgresql://user:pass@host:5432/verity` | PostgreSQL connection string. Required for everything beyond the login screen |
| `DB_SCHEMA` | `verity` | Optional. Set this when `DATABASE_URL` points at a database **shared with another application** — isolates every Verity table into its own Postgres schema instead of the default `public`, with zero risk of colliding with that other app's tables. `Db::connection()` creates the schema automatically (idempotent) and switches to it on every connection; no manual `CREATE SCHEMA` step needed. Leave unset for a dedicated database |
| `GRAPH_BASE_URL` | `https://graph.microsoft.us` | Reserved for the not-yet-built GCC High connector (Phase 4) |
| `ENTRA_AUTHORITY_HOST` | `https://login.microsoftonline.us` | Reserved; no sign-in flow consumes it yet |
| `AZURE_PORTAL_URL` | `https://portal.azure.us` | Reserved; informational only today |
| `ENTRA_TENANT_ID` | *(tenant GUID)* | Read by `Config::entraConfigured()`; not yet wired to a sign-in flow |
| `ENTRA_CLIENT_ID` | *(app registration GUID)* | Same as above |
| `ENTRA_CLIENT_SECRET` | *(secret)* | Same as above — if ever set, it must come from a secret manager, never a committed file |
| `ENTRA_REDIRECT_URI` | `https://.../auth/sso/callback` | Same as above (no such callback route exists yet) |

Local sign-in works out of the box with only `DATABASE_URL` set — no Entra
configuration is required for any implemented feature.

## Database setup

**Dedicated database (`DB_SCHEMA` unset):** apply the idempotent reference
schema directly:

```bash
psql "$DATABASE_URL" -f database/schema.sql
```

**Shared database (`DB_SCHEMA` set)** — do **not** use the raw `psql`
command above for first-time setup: it would apply the schema into
whatever schema the connection defaults to (normally `public`), not into
`DB_SCHEMA`'s value, because the schema-creation/`search_path` logic lives
in `Db::connection()`, which plain `psql` never calls. Run it through the
app's own bootstrap instead, so the schema is created and `search_path` is
set exactly the way the running app expects:

```bash
php -r 'require "app/bootstrap.php"; use Verity\Support\Db; Db::connection()->exec(file_get_contents("database/schema.sql"));'
```

Either way it's safe to re-run — every statement is `CREATE TABLE IF NOT
EXISTS` / `CREATE INDEX IF NOT EXISTS`. There is no migration framework and
no migration history table; `schema.sql` is the single source of truth and
is kept current with every schema change (17 tables as of this writing).

Load synthetic development data (optional, never in production):

```bash
php database/seed.php --force
```

`seed.php` refuses outright when `APP_ENV=production` (verified in
`database/seed.php`) — this is a deliberate guard against loading fictional
demo data into a real deployment. **Operational note for Render or any
target whose `APP_ENV` is permanently `production`:** to seed such an
environment for demo purposes, temporarily override `APP_ENV` for the one
shell invocation (e.g. `APP_ENV=development php database/seed.php --force`
in a Render shell), then let the service's normal environment (`production`)
resume on the next deploy/restart. Do this only against a database that
should hold nothing but fictional demo data.

Seed creates 150 synthetic people across 10 departments with a manager
hierarchy, 15 synthetic applications (one GCC High entry explicitly marked as
a mock catalog entry), their entitlements, roughly 750 system accounts and a
comparable number of entitlement assignments (exact counts vary run to run
based on the department/role distribution logic — see the script's own
summary output), and 5 platform users, one per role, all sharing the
password `ChangeMe123!`:

| Email | Role |
|---|---|
| `admin@verity.local` | enterprise_admin |
| `security.admin@verity.local` | security_admin |
| `supervisor@verity.local` | supervisor |
| `system.owner@verity.local` | system_owner |
| `auditor@verity.local` | auditor |

Rotate or delete these accounts before any non-demo use.

## Background processes, Ollama, GPU

**Not applicable in this build.** Verity has no worker process, no job
queue, and nothing that runs outside the request/response cycle of the PHP
front controller. There is no AI/LLM feature in this build, so there is no
Ollama configuration and no GPU acceleration to configure — these sections
are called out explicitly, per the standard deployment-doc format, rather
than silently omitted.

## Production checklist

Honest status — what's in place today versus what is not:

### Secrets & identity

- ✅ `DATABASE_URL` and the Entra values are environment-sourced, never
  hardcoded; the Dockerfile strips any `.env` from the built image.
- ✅ Passwords are hashed with PHP's `password_hash()` default algorithm
  (currently bcrypt; PHP will migrate the default to argon2id on its own
  schedule — the app does not pin an algorithm).
- ❌ No secrets-manager integration (AWS Secrets Manager, Azure Key Vault,
  etc.) is wired up — today's model is "whatever environment variables the
  platform injects." Document and configure this per target using
  `../deployments/AWS.md` / `../deployments/AZURE.md`.
- ❌ No credential/secret rotation automation — see `DISASTER_RECOVERY.md`
  and `SECURITY.md` for the manual rotation process.

### Transport & exposure

- ✅ The front controller sets CSP (strict, nonce-based, no
  `unsafe-inline`), `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, and HSTS when the request is
  detected as HTTPS (directly or via `X-Forwarded-Proto`).
- ❌ TLS termination is not this app's job in any deployment model — it
  must be provided by the hosting platform (Render terminates TLS for you;
  a self-hosted target needs its own reverse proxy/load balancer with a
  valid certificate). Nothing here configures or validates that externally.
- ❌ No rate limiting of any kind (login attempts, API calls, CSV export)
  exists in the application. A brute-force login attempt is only slowed by
  `password_verify()`'s own cost, not by any lockout or throttle.
- ❌ No WAF guidance has been authored yet for any target.

### Hardening

- ⚠️ **The container runs `php -S 0.0.0.0:$PORT -t public` directly** (see
  `Dockerfile`). PHP's built-in server is explicitly documented by php.net as
  single-threaded and not intended for production use. This is a known,
  honestly-flagged gap, not an oversight — a real production deployment
  should front the app with PHP-FPM behind nginx or Caddy. That work is not
  done; see `OPEN_ITEMS.md`.
- ✅ The Dockerfile runs as a non-root user (`verity`) and defines a
  `HEALTHCHECK` against `/health`.
- ✅ Sessions are `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the
  request is HTTPS.
- ❌ No automated dependency/image vulnerability scanning is configured for
  this app (no CI pipeline exists at all yet — see `OPEN_ITEMS.md`).

### Resilience & operations

- ❌ No automated database backups are configured by this app. A managed
  Postgres target's own point-in-time recovery, or an operator-scheduled
  `pg_dump`, is the only backup story today — see `docs/DISASTER_RECOVERY.md`.
  This has **not** been set up or verified in this build.
- ❌ No backup-restore drill has been performed. A quarterly drill is
  recommended in `DISASTER_RECOVERY.md`; none has happened yet.
- ❌ No performance/load testing has been done. Matrix queries use indexed
  joins with server-side `LIMIT`/`OFFSET` pagination, but are unbenchmarked
  at scale — seed data (150 people / 15 applications / ~750 accounts) is a
  demonstration scale, nowhere near the platform's eventual
  100k-account/1M-assignment design target. Benchmark before trusting this
  at enterprise scale.
- ❌ No metrics, tracing, or alerting beyond `/health` and the
  `audit_event`/`error_log` trail described in `docs/ARCHITECTURE.md`.
- ✅ A single `database/schema.sql` keeps schema state reproducible and
  reviewable; there is no drift between "what migrations ran" and "what the
  file says" because there is no separate migration history to drift from.
