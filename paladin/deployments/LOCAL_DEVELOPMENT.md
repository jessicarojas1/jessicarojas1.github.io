# PALADIN — Local Development Deployment

Operator guide for running PALADIN on a laptop/workstation for development and
evaluation. Two supported paths: **Docker Compose** (recommended, matches
production image) and **native PHP + PostgreSQL**.

Related guides: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
[AIRGAPPED.md](AIRGAPPED.md) · [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md)

---

## 1. Deployment architecture

PALADIN is a PHP 8.3 + Apache application (no framework) backed by PostgreSQL.
For local development, everything runs on the workstation:

- **`app`** — `php:8.3-apache` container built from the repo `Dockerfile`. On
  startup `scripts/startup.sh` runs `install.php` (creates the `paladin` schema,
  applies `database/schema.sql` + `database/migrations/*.sql`, seeds the admin
  and demo data on a fresh DB) and then launches `apache2-foreground`.
- **`db`** — `postgres:16-alpine`, database/schema `paladin`.
- **Storage** — local driver: uploads written under
  `/var/www/html/uploads/{documents,attachments,evidence}` (a Docker volume).
- **Background work** — the in-request `Scheduler` (auto-publish scheduled
  pages, auto-expire documents) runs opportunistically on normal requests. The
  `cli/send_digests.php` and `cli/send_review_reminders.php` scripts are run
  manually in dev (cron optional).

The native path is identical but Apache is replaced by the PHP built-in server
(`php -S localhost:8080 router-dev.php`).

## 2. Topology

```
┌────────────────────────── workstation ──────────────────────────┐
│                                                                  │
│  browser ──HTTP :8080──►  app (php:8.3-apache)                   │
│                              │  PDO pgsql :5432                  │
│                              ▼                                    │
│                           db (postgres:16-alpine)                │
│                              │                                    │
│  volumes:  paladin_uploads (uploads/)   paladin_logs (logs/)     │
│            paladin_pgdata  (/var/lib/postgresql/data)            │
└──────────────────────────────────────────────────────────────────┘
   Docker Compose maps host :8080 → container :80 (Apache).
```

## 3. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| Docker Engine | 24+ | with Compose v2 (`docker compose`) |
| — or — PHP | 8.3 | extensions: `pdo_pgsql`, `gd`, `opcache`, `zip`, `sodium`, `curl` |
| PostgreSQL | 16 (13+ works) | native path only |
| `curl`, `psql` | any | verification |
| Free ports | 8080, 5432 | host ports |

Native PHP extension install (Debian/Ubuntu):

```bash
sudo apt-get install -y php8.3-cli php8.3-pgsql php8.3-gd php8.3-zip \
  php8.3-curl php8.3-opcache php-sodium postgresql-16
```

## 4. Identity & credentials

Local development uses **static values in `.env`** — this is the documented
exception; no cloud identity is involved. Never commit `.env` (it is
git-ignored). Required first-run values:

- `JWT_SECRET` — ≥64 hex chars; generate with
  `php -r "echo bin2hex(random_bytes(32));"`.
- `ADMIN_EMAIL` / `ADMIN_PASSWORD` — seeds the first `admin` user (only used on
  a fresh database).
- `DB_PASS` — local PostgreSQL password.

Demo users are seeded on first install (password `PalDemo!2026`):
`pal.admin@`, `compliance@`, `owner@`, `author@`, `reviewer@`, `approver@`,
`auditor@`, `viewer@` `demo.local`.

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `APP_NAME` | `PALADIN` | Display name / document `<title>` |
| `APP_ENV` | `development` | `development` shows errors; `production` hides them |
| `APP_URL` | `http://localhost:8080` | Absolute base URL (SSO/callbacks/links) |
| `JWT_SECRET` | `a1b2…(64 hex)` | Signs API/JWT tokens; **required** |
| `DATABASE_URL` | `postgres://paladin:paladin@db:5432/paladin` | Single-URL DB config (Compose) |
| `DB_HOST` | `localhost` | Discrete DB host (native path) |
| `DB_PORT` | `5432` | DB port |
| `DB_NAME` | `paladin` | Database name |
| `DB_USER` | `paladin` | DB user |
| `DB_PASS` | `paladin` | DB password |
| `ADMIN_EMAIL` | `admin@demo.local` | First-run admin login |
| `ADMIN_PASSWORD` | `change_me_strong` | First-run admin password |
| `TRUSTED_PROXY_IPS` | `127.0.0.1` | Trusted proxies for `X-Forwarded-*` |
| `STORAGE_DRIVER` | `local` | `local` (volume) or `s3` |
| `MAIL_TRANSPORT` | `queued` | `queued` records mail to `mail_outbox`; `smtp` delivers |
| `HTTP_PORT` | `8080` | Host port Compose maps to container `:80` |
| `PORT` | `80` | Apache listen port inside the container |

## 6. Configuration references

App config lives in `config/app.php` (mostly env-driven) and runtime settings in
the `settings` table (editable at **Administration → Settings**).

| Variable / Setting | Example | Purpose |
|---|---|---|
| `session_lifetime` (app.php) | `28800` | Session lifetime seconds (8h) |
| `csrf_lifetime` (app.php) | `7200` | CSRF token lifetime seconds (2h) |
| `rate_limit.login_attempts` | `5` | Failed logins before lockout |
| `api.rate_limit_per_minute` | `60` | API calls/min per key |
| `storage_driver` (settings) | `local` | Overrides `STORAGE_DRIVER` when set in DB |
| `mail_transport` (settings) | `queued` | SMTP vs queued outbox |

## 7. Verification

```bash
# 0. Bring it up
cp .env.example .env
# edit .env: set JWT_SECRET (64 hex), ADMIN_EMAIL, ADMIN_PASSWORD, DB_PASS
docker compose up --build -d

# 1. Health endpoint (DB + disk checks)
curl -fsS http://localhost:8080/health
# → {"status":"healthy","service":"paladin","checks":{"database":"ok","disk":"ok"}}
curl -fsS http://localhost:8080/healthz   # → {"status":"ok"} (liveness)
curl -fsS http://localhost:8080/readyz    # → {"status":"ok"} (readiness)

# 2. Login works (form login) — expect a Set-Cookie session on success
curl -si http://localhost:8080/login | head -1        # 200 login form
# then sign in in the browser at http://localhost:8080/login

# 3. Secrets resolved — install.php ran and admin exists
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT email, role FROM users WHERE role='admin';"

# 4. Upload accepted + row written:
#    In the UI open a Space → a Page (or Document) → attach a file.
#    Confirm the attachment row and its stored key:
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin;
   SELECT id, entity_type, original_name, stored_path, version, is_current
   FROM attachments ORDER BY id DESC LIMIT 1;"
#    Confirm the file landed on the local volume:
docker compose exec app ls -la /var/www/html/uploads/attachments | tail

# 5. A page row exists and an audit row was hash-chained:
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT id, title, status FROM pages ORDER BY id DESC LIMIT 1;"
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT id, action, entity_type, log_hash IS NOT NULL AS chained
   FROM activity_log ORDER BY id DESC LIMIT 1;"
```

Native path verification is identical, using `psql pal` locally and
`http://localhost:8080`.

## 8. Day-2 operations

| Task | Command |
|---|---|
| Rebuild after code change | `docker compose up --build -d` |
| Apply new migrations | Restart `app` — `startup.sh`→`install.php` applies pending `database/migrations/*.sql` and records them in `schema_migrations` |
| Tail logs | `docker compose logs -f app` |
| Run digest job | `docker compose exec app php cli/send_digests.php daily` |
| Run review reminders | `docker compose exec app php cli/send_review_reminders.php 14 7` |
| Reset DB (destroys data) | `docker compose down -v && docker compose up --build -d` |
| DB shell | `docker compose exec db psql -U paladin -d paladin` |
| Backup dev DB | `docker compose exec db pg_dump -U paladin -n paladin paladin > dev.sql` |

Migrations are idempotent; a failed one is retried on next boot and only recorded
in `schema_migrations` on success.

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `JWT_SECRET is required` on `up` | Env var unset | Set `JWT_SECRET` (≥64 hex) in `.env` |
| App restarts / `install/migration failed … retrying` | DB not ready | Normal at first boot; `startup.sh` retries 12× (5s). If persistent, check `docker compose logs db` |
| `/health` returns 503 `degraded` | DB unreachable | Verify `DATABASE_URL`/`DB_*`, `db` healthy (`pg_isready`) |
| Login rejected repeatedly then locked | 5 failed attempts → 15-min lockout | Wait 900s or clear `rate_limits` table |
| Uploads vanish after `down` | Volume removed with `-v` | Omit `-v` to keep `paladin_uploads` |
| Port 8080 in use | Host conflict | Set `HTTP_PORT=8081` in `.env` |
| Errors not shown | `APP_ENV=production` | Set `APP_ENV=development` for dev |
| Native: `could not find driver` | Missing `pdo_pgsql` | Install `php8.3-pgsql`, restart |
