# VERITY — Enterprise Identity & Access Governance

> **Status: Phases 1–3 of an 8-phase build.** Working modules: Enterprise
> Dashboard, Identity Directory, Access Inventory / Unmatched Accounts
> (manual correlation only), Application Catalog + connector catalog
> (configuration only — no live sync), Enterprise Access Matrix (6
> authorization-scoped views, CSV export, saved views), Dynamic Fields,
> self-service account/password management, a full admin user-management
> flow (create with password, edit details, reset password, activate/
> disable), Settings/Branding, and an append-only Audit trail. **No CI
> badge exists.** See [`OPEN_ITEMS.md`](OPEN_ITEMS.md) for an honest,
> itemized account of what is and is not built.

*"Unified Visibility. Verified Access. Complete Accountability."*

## What it is and why it exists

Verity is an IGA (Identity & Access Governance) platform demonstrating
enterprise access-governance patterns: a canonical identity directory, a
cross-application inventory of who has access to what, a flagship
Enterprise Access Matrix with genuinely server-side authorization scoping,
and an admin console with a real three-state (role-default / explicit-grant
/ explicit-deny) permission model. It exists to show these patterns built
correctly — server-side authorization before every query, parameterized SQL
everywhere, a strict CSP with no inline handlers or inline styles, and an
honest distinction between "built and tested" and "not yet implemented" —
rather than to be a finished commercial product.

## Supported deployment models

Dockerized and Render-deploy compatible. Full instructions:
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) and per-target guides under
[`deployments/`](deployments/) (`LOCAL_DEVELOPMENT.md`,
`SINGLE_LINUX_SERVER.md`, `KUBERNETES.md`, `AZURE.md`, `AWS.md`,
`AIRGAPPED.md`).

## Repository layout

```
verity/
├─ public/
│  ├─ index.php          Front controller: routing, security headers, CSP nonce, /health, /api/*
│  └─ assets/            app.css, confirm.js, matrix.js, iam.js, accounts.js, settings.js
├─ app/
│  ├─ bootstrap.php      PSR-4 autoload (Composer if present, else built-in) + .env loading
│  ├─ Http/               AccountsController, ApplicationsController, AuditController,
│  │                      AuthController, DashboardController, DynamicFieldsController,
│  │                      IamController, IdentitiesController, MatrixController,
│  │                      SettingsController, ApiRouter
│  ├─ Support/            Db, Security, Session, Config, Auth, Authorize, Roles,
│  │                      PermissionCatalog, Audit, People, Accounts, Applications,
│  │                      Connectors, Matrix, DynamicFields, Users, Settings, SavedViews, Dashboard
│  └─ Views/              Plain PHP templates + partials/
├─ database/
│  ├─ schema.sql          Idempotent reference schema (17 tables)
│  └─ seed.php            Synthetic demo-data generator (refuses on APP_ENV=production)
├─ tests/                 Zero-dependency harness: run.php, unit_test.php, db_test.php, lib/T.php
├─ docs/                  ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
├─ deployments/           Per-target operator guides
├─ Dockerfile, render.yaml, composer.json, .env.example
├─ OPEN_ITEMS.md
└─ CLAUDE.md
```

## Technology

- **PHP 8.2+** (tested on PHP 8.5), **zero Composer dependencies** — see
  `composer.json`'s empty `require` beyond the PHP version itself.
- **PostgreSQL 14+** via PDO (`pdo_pgsql` extension).
- No Node, no frontend framework, no Redis, no queue, no CI pipeline
  specific to this app.

## Prerequisites

- PHP 8.2+ with `pdo_pgsql`.
- PostgreSQL 14+ reachable via a connection string.
- Docker, only if deploying via container.

## Quick start (local development)

```bash
cd verity
cp .env.example .env               # edit DATABASE_URL to point at your local Postgres
createdb verity_dev                # or your preferred database name
psql "$DATABASE_URL" -f database/schema.sql
php database/seed.php --force      # optional: synthetic demo data (refuses if APP_ENV=production)
php -S 0.0.0.0:8090 -t public      # also wired into .claude/launch.json as "verity", port 8090
```

Open `http://localhost:8090` and sign in with any of the 5 seeded accounts
(all password `ChangeMe123!` — see `docs/DEPLOYMENT.md`):

| Email | Role |
|---|---|
| `admin@verity.local` | enterprise_admin |
| `security.admin@verity.local` | security_admin |
| `supervisor@verity.local` | supervisor |
| `system.owner@verity.local` | system_owner |
| `auditor@verity.local` | auditor |

## Common commands

| Command | Purpose |
|---|---|
| `php -S 0.0.0.0:8090 -t public` | Run the app locally |
| `psql "$DATABASE_URL" -f database/schema.sql` | Apply/update the schema (idempotent) |
| `php database/seed.php --force` | Wipe + reload synthetic demo data (non-production only) |
| `php tests/run.php` | Run the full test suite (logic always; DB tests need `VERITY_TEST_DB=1` + `DATABASE_URL`) |
| `docker build -t verity .` | Build the container |
| `curl -fsS localhost:8090/health` | Health check (JSON) |

## Testing

Zero-dependency harness (`tests/lib/T.php`, not PHPUnit): `php tests/run.php`
runs **66 assertions, all passing** (verified in this session) — 42
pure-logic checks (role/grant/deny layering including the wildcard-vs-deny
regression test, coarse-alias expansion, password policy, connector
capability-manifest honesty) that always run, plus 24 live-database checks
(reporting-chain scoping, application-ownership scoping, `Db::update`'s
automatic `updated_at`, basic insert/fetch, SQL identifier allowlisting,
dedicated-schema isolation, and the user-creation/password-reset flow) that
self-skip unless both `DATABASE_URL` and `VERITY_TEST_DB=1` are set. The
DB-backed group builds its own isolated fixture inside a transaction that is
always rolled back — it never touches or depends on `seed.php`'s data.

```bash
php tests/run.php
# or, to include the DB-backed group against a throwaway database:
DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/verity_test" VERITY_TEST_DB=1 php tests/run.php
```

## Build status

**Phase 1–3 of 8 complete.** No CI pipeline exists for this app yet — test
runs are manual. See [`OPEN_ITEMS.md`](OPEN_ITEMS.md) for the full
production-readiness register, and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
for how the implemented pieces fit together.

## Package dependencies

**None.** `composer.json` requires only `php: >=8.2`; there is no `vendor/`
directory and no `require` beyond the PHP version itself. `app/bootstrap.php`
uses Composer's autoloader when present but falls back to a built-in PSR-4
autoloader when it isn't, so the app runs identically with or without
`composer install`.

## Further reading

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — platform, design
  principles, the dual DB-backed authorization scoping, request/error
  contract, observability.
- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — deployment guide, env vars,
  database setup, production checklist.
- [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md) — RPO/RTO,
  backups, restore runbook, HA.
- [`docs/SECURITY.md`](docs/SECURITY.md) — identity, authorization, data
  protection, auditability, FIPS readiness, reporting an issue.
- [`deployments/`](deployments/) — per-target operator guides.
- [`OPEN_ITEMS.md`](OPEN_ITEMS.md) — production-readiness register.
