# AEGIS — Deployment & Operations

AEGIS ships as a single Docker container (PHP 8.3 + Apache) backed by PostgreSQL.
This guide covers configuration, deployment (Docker / Render), health checks,
scheduled jobs, and backup/restore.

## Environment variables

| Variable | Required | Notes |
|----------|----------|-------|
| `JWT_SECRET` | **Yes** | ≥ 32 chars. Signs API JWTs. Startup aborts if missing/short. |
| `DATABASE_URL` | **Yes**¹ | `postgres://user:pass@host:5432/dbname`. |
| `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` | Alt¹ | Used when `DATABASE_URL` is absent. |
| `APP_URL` | **Yes (prod)** | Canonical URL. CORS allow-origin + absolute links. Required when `APP_ENV=production`. |
| `APP_ENV` | No | `production` (default) or `development`. |
| `APP_NAME` | No | Display name (default `AEGIS GRC`). |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | Install | Seed the first admin during install. |

¹ Provide **either** `DATABASE_URL` **or** the discrete `DB_*` set. Startup aborts
if neither is configured.

**Startup validation** (in `index.php`) fails fast with an operator-safe message
(no stack traces) when `JWT_SECRET`, a database, or (in production) `APP_URL` is
missing — see `SECURITY.md` for the error-handling model.

`.env` is never committed. Copy `.env.example` and fill in real values, or set
them in your platform's dashboard.

## Health & readiness

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `GET /healthz` | none | Liveness — process is serving. |
| `GET /readyz` | none | Readiness — database reachable (503 if not). |
| `GET /api/v1/health` | none | API liveness + DB readiness. |

The Docker image declares a `HEALTHCHECK` against `/healthz`; `render.yaml` sets
`healthCheckPath: /healthz`.

## Deploy with Docker

```bash
docker build -t aegis ./aegis
docker run -d --name aegis -p 8080:80 \
  -e JWT_SECRET="$(openssl rand -hex 32)" \
  -e DATABASE_URL="postgres://aegis:secret@db:5432/aegis" \
  -e APP_URL="https://grc.example.com" \
  -e APP_ENV=production \
  aegis
```

Or use `docker compose up` (see `docker-compose.yml`, which bundles PostgreSQL).

First run: visit `/install.php` once to create the schema and seed the admin,
then ensure `install.php` is blocked (it is, via `.htaccess`).

## Deploy on Render

`render.yaml` defines the web service + a managed PostgreSQL database. Push to
the connected repo; Render builds the Dockerfile, provisions `aegis-db`, injects
`DATABASE_URL`, and generates `JWT_SECRET`. Set `APP_URL` and `ADMIN_EMAIL`
(marked `sync: false`) in the dashboard.

## Scheduled jobs (cron)

Run these from cron (or a scheduler) inside the container/host:

```cron
# Workflow automation rules
* * * * *  php /var/www/html/scripts/run_workflows.php

# Outbound webhook delivery (with exponential backoff)
* * * * *  php /var/www/html/scripts/dispatch_webhooks.php

# Notifications + escalation reminders
*/5 * * * * php /var/www/html/scripts/send_notifications.php

# Scheduled reports
0 6 * * *  php /var/www/html/scripts/send_scheduled_reports.php

# Metrics snapshot (for trend charts)
0 1 * * *  php /var/www/html/scripts/capture_metrics_snapshot.php

# Audit-log integrity verification (alert on non-zero exit) — see AUDIT_TRAIL.md
0 * * * *  php /var/www/html/scripts/verify_audit_log.php --quiet || notify-oncall "AEGIS audit log FAILED"
```

## Operational verifiers (also run in CI)

```bash
php scripts/verify_migrations.php   # migration registration / ordering / idempotency
php scripts/check_ui.php            # CSP: no inline handlers, scripts carry a nonce
php tests/run.php                   # unit suite (SSRF, JWT, RiskScore, AIAdvisor, DueStatus)
```

## Backup & restore (PostgreSQL)

**Backup** (logical dump, schema-scoped):

```bash
pg_dump "$DATABASE_URL" --format=custom --file=aegis-$(date +%F).dump
```

**Restore** into a fresh database:

```bash
createdb aegis
pg_restore --no-owner --no-acl --dbname="$DATABASE_URL" aegis-YYYY-MM-DD.dump
```

Guidance:
- Schedule daily dumps; retain per your retention policy. Store off-host, encrypted.
- After restore, run `php scripts/verify_audit_log.php` to confirm the audit hash
  chain is intact and `php scripts/verify_migrations.php` for schema currency.
- Never hard-delete compliance evidence; prefer void/soft-delete patterns.

## Secure deployment checklist

- [ ] `JWT_SECRET` is a unique ≥ 32-char random value (not shared across envs).
- [ ] HTTPS terminated in front of the container; HSTS honored.
- [ ] `APP_URL` set to the exact public origin (CORS depends on an exact match).
- [ ] `install.php` blocked after first install (verify it 403s).
- [ ] `/src`, `/config`, `/database`, `/scripts` not web-served (`.htaccess`).
- [ ] Upload directory non-executable and not directly served.
- [ ] Database credentials least-privilege; backups encrypted and off-host.
- [ ] Cron jobs scheduled, including the hourly audit-log verifier.
- [ ] Admin MFA enabled; default admin password rotated.
- [ ] CI green (lint + tests + migration verifier + UI linter).

## Upgrade / migration runbook

1. Back up the database (above).
2. Pull the new image / code.
3. New migrations are applied by `install.php` on the registered list, and
   idempotent runtime guards in `index.php` self-heal compatibility columns.
4. Run `php scripts/verify_migrations.php` to confirm all migrations are
   registered and ordered.
5. Smoke test `/healthz`, `/readyz`, and a couple of core modules.
