# AEGIS GRC — Local Development Deployment

Audience: developers and operators running AEGIS on a laptop or a throwaway VM to
evaluate, develop, or debug the platform. This is the **fast path**: one command
brings up the full stack (app + PostgreSQL + optional nginx perimeter + background
worker) with realistic security defaults.

> Sibling guides: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
> [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
> [AIRGAPPED.md](AIRGAPPED.md)

---

## 1. Deployment architecture

AEGIS is a **no-framework PHP 8.3 application served by Apache** inside a single
container image (`./Dockerfile`). It talks to **PostgreSQL 16**. Everything else is
optional:

| Component | Role | Local default |
|-----------|------|---------------|
| `app` | PHP 8.3 + Apache, listens on **8080**, runs as non-root `www-data`. Serves the UI, the `/api/*` JSON API, and the health probes `/healthz` + `/readyz`. | container |
| `db` | PostgreSQL 16. First boot runs `docker/initdb.sh`, which applies `database/schema.sql` then every file in `database/migrations/` in numeric order into the `aegis` schema. | container |
| `nginx` | Optional TLS/perimeter proxy in front of `app:8080` (mirrors `.htaccess` security headers, blocks `/install.php`, `/uploads`, `/config`, `/src`, `/database`, `/scripts`). Publishes host ports 80/443. | container |
| `cron` | Background worker. In compose it loops `run_workflows.php` then `dispatch_webhooks.php` every 60s. Additional scheduled jobs (below) are run manually in dev. | container |

The web container's entrypoint is `scripts/startup.sh`, which runs `php install.php`
(idempotent — creates the schema, applies migrations, seeds the admin from
`ADMIN_EMAIL`/`ADMIN_PASSWORD`) and then `apache2-foreground`.

Background jobs (normally cron/scheduler; run by hand locally as needed):

| Script | Cadence in prod | Purpose |
|--------|-----------------|---------|
| `scripts/send_notifications.php` | hourly | Due-date reminders / digests |
| `scripts/dispatch_webhooks.php` | per minute | Deliver queued webhooks |
| `scripts/send_scheduled_reports.php` | hourly (self-gates) | Scheduled report emails |
| `scripts/run_workflows.php` | every 15 min | Automation rules, overdue escalations |
| `scripts/capture_metrics_snapshot.php` | daily | GRC metric snapshot for trend charts |
| `scripts/drain_email_queue.php` | every 5 min | Retry emails that failed immediate send |

## 2. Topology

```
 Developer laptop
 ┌──────────────────────────────────────────────────────────────┐
 │  Docker network: frontend_net + backend_net                  │
 │                                                              │
 │   host :80/:443 ──► ┌─────────┐   proxy    ┌───────────────┐ │
 │                     │  nginx  │ ─────────► │  app          │ │
 │                     │ (opt.)  │  app:8080  │  PHP8.3/Apache│ │
 │                     └─────────┘            │  :8080        │ │
 │                                            └──────┬────────┘ │
 │                                    pdo_pgsql :5432 │          │
 │                                            ┌───────▼────────┐ │
 │   ┌──────────┐  loop 60s                   │  db            │ │
 │   │  cron    │ ───────────────────────────►│  Postgres 16   │ │
 │   │  worker  │  run_workflows/webhooks     │  schema=aegis  │ │
 │   └──────────┘                             └────────────────┘ │
 │                                                              │
 │  volumes: pg_data · uploads_data · logs_data                 │
 └──────────────────────────────────────────────────────────────┘
```

Data flow: browser → (nginx) → Apache/PHP → PostgreSQL. Uploads land on the
`uploads_data` volume (local `STORAGE_DRIVER`) and are indexed as rows in the
`evidence_files` table. Every mutating action appends a hash-chained row to the
`activity_log` audit table.

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Docker Engine | 24+ | `docker --version` |
| Docker Compose | v2 (`docker compose`) | bundled with modern Docker Desktop / CLI plugin |
| git | any | to clone the repo |
| curl / psql | optional | for verification checks below |

Native (no Docker) alternative requires: PHP **8.3** with extensions
`pdo`, `pdo_pgsql`, `gd`, `opcache` (plus `poppler-utils`/`pdftoppm` on PATH for PDF
thumbnailing), Apache with `mod_rewrite` + `mod_headers`, and a local PostgreSQL 16.
Docker is strongly preferred — it reproduces the production image exactly.

## 4. Identity & credentials

Local development uses **static secrets in a git-ignored `.env` file** — there is no
cloud identity provider on a laptop. The compose file *refuses to start* unless
`DB_PASS` and `JWT_SECRET` are set (no insecure defaults), which forces you to
generate real values even in dev.

- Never commit `.env` (it is git-ignored; only `.env.example` is tracked).
- Generate strong values:
  ```bash
  php -r "echo bin2hex(random_bytes(32));"   # per secret
  # or, without PHP:
  openssl rand -hex 32
  ```
- The `*_FILE` convention (e.g. `JWT_SECRET_FILE=/run/secrets/jwt_secret`) also works
  locally if you want to rehearse the production secret-mount flow; a direct
  `JWT_SECRET=` value takes precedence over its `_FILE`.

## 5. Environment variables

Copy `.env.example` to `.env` and fill in the required values. Minimum viable set:

| Variable | Example | Purpose |
|----------|---------|---------|
| `DB_HOST` | `db` | Postgres host (service name in compose) |
| `DB_PORT` | `5432` | Postgres port |
| `DB_NAME` | `aegis` | Database name |
| `DB_USER` | `aegis` | Database role |
| `DB_PASS` | `<random>` | **Required** — compose won't start without it |
| `JWT_SECRET` | `<64 hex>` | **Required** — signs JWT auth tokens |
| `APP_ENV` | `development` | `production` hardens error handling; use `development` locally |
| `APP_URL` | `http://localhost` | Base URL for links/emails/redirect validation |
| `ADMIN_EMAIL` | `admin@example.com` | Seeds the first admin on `install.php` |
| `ADMIN_PASSWORD` | `<strong>` | Seeds the first admin password (min 12 chars, upper/number/special) |
| `AUDIT_HMAC_KEY` | `<64 hex>` | Signs the tamper-evident audit hash chain. Optional locally (falls back to a JWT_SECRET-derived key) |
| `APP_ENCRYPTION_KEY` | `<64 hex>` | Encrypts sensitive settings at rest (SMTP/S3/AI keys). Optional locally |
| `HTTP_PORT` / `HTTPS_PORT` | `80` / `443` | Host ports the nginx service publishes |

`DATABASE_URL` (a single `postgres://user:pass@host:port/dbname` string) is also
honored and takes precedence over the discrete `DB_*` vars — this is how Render and
some PaaS targets inject connection info.

> **Storage note (important):** S3/local storage is **not** configured via env vars in
> AEGIS — the storage driver, bucket, region, and keys live in the `settings` table
> and are edited from **Admin → Storage** in the UI. Locally you keep the default
> `local` driver and files land on the `uploads_data` volume. See
> [AWS.md](AWS.md#file-storage-s3) for the S3 settings keys.

## 6. Configuration references

App configuration is in `config/app.php` (readable, not secret) and is driven by the
environment. Notable defaults:

| Setting (config/app.php) | Value | Purpose |
|--------------------------|-------|---------|
| `session_lifetime` | `3600*8` (8h) | Login session lifetime |
| `csrf_lifetime` | `3600*2` (2h) | CSRF token lifetime |
| `rate_limit.login_attempts` | `5` / `300s` window / `900s` lockout | Brute-force protection |
| `password.min_length` | `12` (upper+number+special required) | Password policy — affects `ADMIN_PASSWORD` |
| `api.rate_limit_per_minute` | `60` | Per-client API rate limit |

Optional local toggles from `.env.example`: `SESSION_DRIVER=files|pg`,
`REDIS_URL=` (shared cache/rate-limit backend; needs the `phpredis` extension),
`SMTP_*` (leave blank to disable email — notifications queue but don't send),
`AI_PROVIDER`/`AI_API_KEY` reference vars (the runtime AI key actually lives in the
`settings` table via **Admin → AI**).

## 7. Quick start

```bash
git clone <repo> && cd <repo>/aegis
cp .env.example .env
# edit .env: set DB_PASS, JWT_SECRET, ADMIN_EMAIL, ADMIN_PASSWORD (min 12 chars)

docker compose up -d --build
docker compose logs -f app        # watch install.php run + Apache start
```

First boot: `db` applies `schema.sql` + all migrations; `app` runs `install.php`
(seeds the admin) then starts Apache. Browse to <http://localhost> (via nginx) or
map the app port directly for framework-free debugging.

Native quick start (no Docker):

```bash
createdb aegis
psql -d aegis -v ON_ERROR_STOP=1 -c "CREATE SCHEMA IF NOT EXISTS aegis;"
PGOPTIONS="--search_path=aegis,public" psql -d aegis -f database/schema.sql
for f in database/migrations/*.sql; do PGOPTIONS="--search_path=aegis,public" psql -d aegis -f "$f"; done
ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD='Change-me-123!' php install.php
php -S localhost:8080 router-dev.php   # dev router
```

## 8. Verification

Run these after `docker compose up`. Replace host/port if you mapped differently.

**1. Health / liveness**
```bash
curl -fsS http://localhost/healthz
# {"status":"ok","request_id":"...","time":"..."}
```

**2. Readiness (DB reachable)**
```bash
curl -fsS http://localhost/readyz
# {"status":"ready","checks":{"database":"ok"},...}   (503 + "not_ready" if DB is down)
```

**3. Secrets resolved** — the app booted and can reach Postgres with the configured
`DB_PASS`, and the audit key is active:
```bash
docker compose exec app php -r 'require "config/database.php"; new PDO(getDSN(), getDatabaseConfig()["user"], getDatabaseConfig()["password"]); echo "db ok\n";'
docker compose exec app php scripts/verify_audit_log.php   # exits 0 = hash chain intact
```

**4. Login works** — the login form is CSRF-protected, so scrape the token first:
```bash
JAR=$(mktemp)
CSRF=$(curl -sc "$JAR" http://localhost/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sb "$JAR" -c "$JAR" -i -X POST http://localhost/login \
  --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASSWORD" | head -n1
# HTTP/1.1 302 Found  → redirect to dashboard/MFA = success
```

**5. Upload accepted + indexed + scanned + object written** — upload evidence in the
UI (any control/risk → attach file), then confirm the DB row and stored object:
```bash
docker compose exec db psql -U aegis -d aegis -c \
  "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash, review_status FROM evidence_files ORDER BY id DESC LIMIT 1;"
# a row with a randomized stored_name + sha-256 file_hash proves it was indexed
docker compose exec app sh -c 'ls -l uploads/evidence | tail'   # the object on disk (local driver)
```
The dangerous-extension denylist (`.php`, `.svg`, `.html`, `.exe`, …) is enforced in
`Storage::put()` — try uploading a `.php` file and confirm it is rejected.

## 9. Day-2 operations

- **Rebuild after code change:** `docker compose up -d --build app`.
- **Apply new migrations:** migrations are idempotent. Re-run `install.php`
  (`docker compose exec app php install.php`) or, on an existing DB, apply just the
  new file: `docker compose exec db psql -U aegis -d aegis -f /aegis-db/migrations/NNN_x.sql`
  (the `./database` tree is mounted read-only at `/aegis-db`). Verify with
  `docker compose exec app php scripts/verify_migrations.php`.
- **Run a scheduled job manually:** `docker compose exec cron php scripts/send_notifications.php`.
- **Reset the whole stack (destroys data):** `docker compose down -v`.
- **Logs:** `docker compose logs -f app | cron | nginx | db`; app/cron also write to
  the `logs_data` volume (`logs/workflows.log`, `logs/webhooks.log`).
- **DB backup/restore (dev):**
  ```bash
  docker compose exec db pg_dump -U aegis -d aegis -Fc -f /tmp/aegis.dump
  docker compose cp db:/tmp/aegis.dump ./aegis.dump
  # restore: docker compose exec -T db pg_restore -U aegis -d aegis --clean < aegis.dump
  ```
- **Secret rotation (dev):** change the value in `.env`, `docker compose up -d`.
  Rotating `AUDIT_HMAC_KEY` invalidates verification of rows written under the old
  key — keep the old key to verify historical rows.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `DB_PASS is required` / `JWT_SECRET is required` on `up` | Compose interpolation guard — vars unset | Set them in `.env` before `docker compose up` |
| App container restart-loops; logs show DB connection refused | `db` not healthy yet on first boot | `app` `depends_on: db: service_healthy` should handle it; if native, confirm Postgres is up and `DB_HOST` is right |
| `install.php` fatals: "ADMIN_EMAIL and ADMIN_PASSWORD must be set" | Admin seed vars missing | Set both in `.env`; password must satisfy the 12-char upper/number/special policy |
| `/readyz` returns 503 `not_ready` | App up but Postgres unreachable | Check `db` logs, network, `DB_*`/`DATABASE_URL` values |
| Login POST returns 419/redirect back to `/login` | Missing/expired CSRF token, or wrong creds, or rate-limited (5 tries / 5 min) | Re-scrape `csrf_token`; wait out the 15-min lockout; confirm creds |
| Upload rejected: "Refusing to store disallowed file type" | Extension on the denylist (`.php`, `.svg`, `.html`, `.exe`, …) | Expected — use a permitted document/image type |
| Migrations partially applied on a stale DB | Volume created before a migration existed | `docker compose down -v` (dev only) to reinitialize, or apply the missing files manually |
| PDF thumbnails missing | `pdftoppm`/`poppler-utils` absent (native only) | Install `poppler-utils`; the Docker image bundles it |
