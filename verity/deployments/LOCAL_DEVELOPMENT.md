# VERITY — Local Development

Operator guide for running Verity (Enterprise Identity & Access Governance,
Phases 1-3: identity directory, access inventory, enterprise access matrix,
dynamic fields, admin IAM console, settings/branding, audit log) on a
workstation.

## 1. Deployment architecture

A single PHP process serves server-rendered views directly from `public/`
via the front controller (`public/index.php`, a manual switch-based router —
no framework). The app talks to one PostgreSQL database over PDO. There is no
queue, no worker process, no Redis, and no Node/build step — views are plain
PHP includes and `public/assets/*` is hand-rolled CSS/JS served as-is.

## 2. Topology

```
Browser ──> PHP built-in server (:8080, `php -S`)
                  └── public/index.php (router, CSP/security headers)
                        ├── /health          (JSON status)
                        ├── /auth/login       (local email+password)
                        ├── /auth/sso         (503 — not implemented, see §4)
                        └── /app/*            (authenticated views)
                                  └── PDO ──> PostgreSQL 14+
```

## 3. Prerequisites

- PHP 8.2 or later (developed and tested through PHP 8.5). No PHP extensions
  beyond the bundled `pdo`/`pdo_pgsql` (install via your OS package manager if
  not already present, e.g. `brew install php` on macOS ships both).
- PostgreSQL 14+ reachable from your workstation (local install, Homebrew
  service, or a container — any PostgreSQL 14+ works, no managed-service
  specifics required here).
- No Node.js, no Composer required. `composer.json` only declares the PSR-4
  autoload mapping; `app/bootstrap.php` falls back to a hand-rolled PSR-4
  autoloader when `vendor/` is absent, so `composer install` is optional, not
  required, for local development.

## 4. Identity & credentials

Verity authenticates locally with email + password (bcrypt/argon2i via PHP's
`password_hash()` default) and PHP native sessions (cookie `VERITY_SID`,
HttpOnly, SameSite=Lax). There is no external identity provider to configure
for local development.

`GET /auth/sso` intentionally returns **HTTP 503** — Microsoft Entra ID GCC
High single sign-on is not implemented in this build (Phase 4). Use local
email/password sign-in at `/auth/login`. The four `ENTRA_*` environment
variables below are read by `Config` but nothing in the app consumes them for
a real sign-in flow yet; leave them unset locally.

## 5. Environment variables

Copy `.env.example` to `.env` (never commit the real `.env` — it is already
git-ignored and the Dockerfile explicitly removes it from the image).

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `development` | Runtime mode (default `development`). `production` disables PHP error display and blocks `database/seed.php`. |
| `DATABASE_URL` | `postgresql://user:password@127.0.0.1:5432/verity_dev` | PDO Postgres DSN. Required for any page beyond the login screen — the app degrades honestly (not fake data) when unset. |
| `PASSWORD_BREACH_CHECK_ENABLED` | `true` (default) | Checks new passwords against the Have I Been Pwned range API (k-anonymity; only a 5-char hash prefix leaves this server). Fails open on any network error. Leave at the default locally — it needs outbound internet from your dev machine. |

The `ENTRA_*`/`GRAPH_BASE_URL`/`ENTRA_AUTHORITY_HOST`/`AZURE_PORTAL_URL`
variables exist for the future GCC High connector; leave them unset for local
development (see §4 and `docs/SECURITY.md`).

## 6. Configuration references

- Routing + security headers (CSP with per-request nonce, X-Frame-Options,
  Referrer-Policy, Permissions-Policy, HSTS when HTTPS): `public/index.php`.
- Config accessor: `app/Support/Config.php`. DB access layer: `app/Support/Db.php`.
- Schema (idempotent, safe to re-run): `database/schema.sql`.

## 7. Run

```bash
cd verity

# 1. Create the database (once) and apply the schema — idempotent, safe to re-run.
createdb verity_dev
psql "$DATABASE_URL" -f database/schema.sql

# 2. Set DATABASE_URL (either export it or populate .env from .env.example).
cp .env.example .env    # then edit DATABASE_URL to match your local Postgres

# 3. Seed synthetic demo data (refuses to run if APP_ENV=production).
php database/seed.php --force

# 4. Run the app.
php -S 0.0.0.0:8080 -t public
```

## 8. Seeded sign-in accounts

`database/seed.php --force` populates ~150 synthetic people, 15 synthetic
applications, and roughly 750 accounts with ~775 entitlement assignments
(all fictional), plus one platform user per role — all with the password
below:

| Email | Role |
|-------|------|
| `admin@verity.local` | `enterprise_admin` |
| `security.admin@verity.local` | `security_admin` |
| `supervisor@verity.local` | `supervisor` |
| `system.owner@verity.local` | `system_owner` |
| `auditor@verity.local` | `auditor` |

Password for all five: **`ChangeMe123!`**. This is synthetic development
data only — never run `seed.php` against a database holding real identities,
and never reuse this password anywhere outside a local/demo database.

## 9. Running tests

```bash
php tests/run.php
```

Zero-dependency custom test harness (no PHPUnit). The DB-backed test group
self-skips unless **both** `DATABASE_URL` is set **and** `VERITY_TEST_DB=1`
are present — set both against a throwaway Postgres database to also exercise
those assertions:

```bash
DATABASE_URL="$DATABASE_URL" VERITY_TEST_DB=1 php tests/run.php
```

## 10. Verification

```bash
curl -fsS http://localhost:8080/health
# {"status":"ok","app":"verity","db":true}
```
Note `db` only reflects whether `DATABASE_URL` is configured, not a live
connectivity probe — a correct `db:true` with a wrong password will still
fail on the next page load.

- Open `http://localhost:8080/auth/login` and sign in as `admin@verity.local`
  / `ChangeMe123!`.
- Confirm the dashboard KPIs (identity, account, and entitlement counts) are
  non-zero — this proves the seed data landed and the app can read it back.
- Confirm `/auth/sso` returns `503` with the explanatory text (expected,
  not a bug).

## 11. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Blank page / 500 | PHP < 8.2, or `DATABASE_URL` malformed | Check `php -v`; `Db::connection()` throws a generic "Database connection failed" without leaking the DSN — check `php -S` stderr for the real PDO error |
| `/health` shows `"db":false` | `DATABASE_URL` not set in the environment the server was started in | Confirm `.env` is present and `php -S` was started from `verity/` (bootstrap loads `.env` relative to the project root) |
| `seed.php` exits with "Refusing to run..." | `APP_ENV=production` | Unset or change `APP_ENV` for local/demo databases only |
| Dashboard KPIs show 0 | Seed script not run, or pointed at a different database than the app | Re-run `php database/seed.php --force`; confirm both the app and the seed script read the same `DATABASE_URL` |
| 404 on every `/app/*` route | Not signed in | Sign in at `/auth/login` first — protected routes require a session |
