# APEX — Local Development Deployment

Operator guide for running **APEX** (DoD-grade project + ticket tracker) on a
developer laptop/workstation. APEX is PHP 8.2 + Apache serving a vanilla-JS SPA
against PostgreSQL 16, with CAC/PIV-simulated auth (bcrypt PINs + HS256 JWT).

Fast path: **Docker Compose**. Native PHP is documented as a fallback.

Related guides: [SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES](KUBERNETES.md) · [AWS](AWS.md) · [AZURE](AZURE.md) ·
[AIRGAPPED](AIRGAPPED.md)

---

## 1. Deployment architecture

Two containers on the developer host, wired by `docker-compose.yml`:

| Component | Image | Role |
|-----------|-------|------|
| `app` | built from `apex/Dockerfile` (`php:8.2-apache` + `pdo_pgsql`) | Serves the SPA (`public/`) and the `/api/*` REST API on port **8080** as non-root `www-data`. Entrypoint `bin/start.sh` runs `scripts/migrate.php` then `apache2-foreground`. |
| `db` | `postgres:16-alpine` | Stores all state. Bound to `127.0.0.1:5432` only. |

On first boot the app container applies `schema.sql` (via `scripts/migrate.php`,
which only runs when the `users` table is absent), seeding 3 users, 1 project
(`SEC`), 8 tickets, 6 labels, and default branding.

Auth model: `POST /api/auth/login` bcrypt-verifies a PIN and issues an 8-hour
HS256 JWT delivered both in the JSON body and as an HttpOnly `apex_token`
cookie. In `APP_ENV=development` a non-secret dev JWT key is tolerated; in
production the app fails closed if `JWT_SECRET` is missing/short.

---

## 2. Topology

```
 Developer host (localhost)
 ┌─────────────────────────────────────────────────────────┐
 │                                                           │
 │  Browser ──http://localhost:8080──▶  app (php:8.2-apache) │
 │                                        │  DocumentRoot    │
 │                                        │  /var/www/html/  │
 │                                        │  public          │
 │                                        │                  │
 │                                        ▼ DATABASE_URL     │
 │                              db (postgres:16-alpine)      │
 │                              127.0.0.1:5432  vol: pgdata  │
 └─────────────────────────────────────────────────────────┘
   Networks: default compose bridge (app↔db by service name "db")
   Persistence: named volume "pgdata"
   Source bind-mount: ./ → /var/www/html (live edits w/o rebuild)
```

---

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Docker Engine | 24+ | Or Docker Desktop |
| Docker Compose | v2 (`docker compose`) | Bundled with recent Docker |
| Git | any | To clone the monorepo |
| psql client | 16 (optional) | For verification / manual schema apply |
| PHP CLI | 8.2 (optional) | Only for the native fallback (Option B) |

Ports required free on the host: **8080** (app), **5432** (Postgres, loopback).

---

## 4. Identity & credentials

Local development uses **no cloud identity**. Two secrets are still required by
compose and will refuse to start if unset:

| Secret | Source | Notes |
|--------|--------|-------|
| `DB_PASS` | shell env / `.env` | Postgres superuser password for the `apex` role. `docker-compose.yml` fails with `DB_PASS is required` if empty. |
| `JWT_SECRET` | shell env / `.env` | HS256 signing key. Required even locally; compose fails with `JWT_SECRET is required` if empty. |

Seed login identities (from `schema.sql`):

| Username | PIN | Role | Clearance |
|----------|-----|------|-----------|
| `rojas` | `654321` | admin | SECRET |
| `smith` | `112233` | member | TS/SCI |
| `brown` | `999999` | viewer | UNCLASSIFIED |

The seed `pin_hash` values are real bcrypt hashes, so login works with
`APEX_ALLOW_DEFAULT_PINS=0`. Set `APEX_ALLOW_DEFAULT_PINS=1` (dev only) to also
accept the well-known plaintext PINs even if hashes were altered. This fallback
is **never** honored when `APP_ENV=production`.

> Do **not** commit `.env`. Only `.env.example` (placeholders) belongs in git.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:${DB_PASS}@db:5432/apex` | PDO PgSQL connection. Accepts `postgres(ql)://` URL or a raw DSN. Honors `?sslmode=`. |
| `JWT_SECRET` | `dev-only-please-override` | HS256 JWT signing key. In dev, empty falls back to `apex-dev-secret-please-override`. |
| `APP_ENV` | `development` | `development` tolerates a weak JWT key and enables verbose JSON error traces. `production` fails closed. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | `1` accepts default plaintext PINs (dev only). Keep `0` unless first-run bootstrapping. |
| `DB_PASS` | `localdevpass` | Compose-only: injected into `POSTGRES_PASSWORD` and `DATABASE_URL`. Not read by app code directly. |

Local development is single-partition (no cloud); AWS Commercial vs GovCloud and
Azure Commercial vs Government distinctions do not apply here — see
[AWS.md](AWS.md) and [AZURE.md](AZURE.md).

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_USER` | `apex` | Used only when `DATABASE_URL` is a raw DSN (no embedded creds). |
| `DATABASE_PASS` | `localdevpass` | Same — raw-DSN fallback for the password. |
| Apache listen port | `8080` | Hardcoded in the image (`ports.conf` patched, unprivileged). |
| DocumentRoot | `/var/www/html/public` | Set in the image vhost. |
| JWT TTL | `8h` | Constant in `src/Auth.php` (`JWT_TTL_SECS`). |
| Cookie name | `apex_token` | HttpOnly, SameSite=Lax, Secure only when `APP_ENV=production`. |

---

## 7. Verification

### 7a. Bring the stack up

```bash
cd apex
export DB_PASS='localdevpass'
export JWT_SECRET='dev-only-please-override'
export APEX_ALLOW_DEFAULT_PINS=0     # seed bcrypt hashes work at 0
docker compose up --build
```

Open <http://localhost:8080>.

### 7b. Health endpoint

```bash
curl -s http://localhost:8080/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"2026-07-01T..."}}
```

### 7c. Login works (secrets resolved)

```bash
curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}'
# → {"data":{"token":"<jwt>","user":{"id":"rojas","role":"admin",...}}}
```

A non-empty `token` confirms `JWT_SECRET` resolved and bcrypt verification
passed. Capture it:

```bash
TOKEN=$(curl -s -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
curl -s http://localhost:8080/api/auth/me -H "Authorization: Bearer $TOKEN"
```

### 7d. Object written to storage (DB row)

APEX has no file-upload feature; the equivalent "write + persist" flows are
**ticket creation** and **branding update** (admin), both of which write DB rows.

Create a ticket (writes to `tickets`, auto-IDs like `SEC-009`):

```bash
curl -s -X POST http://localhost:8080/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"Verify local deploy","type":"task"}'
```

Update branding (admin-only; writes/updates the `app_settings` row):

```bash
curl -s -X POST http://localhost:8080/api/settings/branding \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"displayName":"APEX Dev","accentColor":"#22c55e","logoUrl":""}'
```

Confirm the rows landed in Postgres:

```bash
docker compose exec db psql -U apex -d apex \
  -c "SELECT id,title,status FROM tickets ORDER BY created_at DESC LIMIT 3;"
docker compose exec db psql -U apex -d apex \
  -c "SELECT key, value FROM app_settings WHERE key='branding';"
```

Seeing your new ticket row and the updated branding JSON confirms the full path:
Apache → PHP API → PDO → PostgreSQL persistence.

---

## 8. Day-2 operations

| Task | Command |
|------|---------|
| Rebuild after Dockerfile change | `docker compose up --build` |
| Live code edits | The repo is bind-mounted (`.:/var/www/html`); edit and refresh — no rebuild for PHP/JS/CSS. |
| Re-run migration manually | `docker compose exec app php /var/www/html/scripts/migrate.php` |
| Reset schema (destructive) | `docker compose down -v` (drops the `pgdata` volume), then `up`. |
| Apply schema by hand | `psql "postgresql://apex:$DB_PASS@127.0.0.1:5432/apex" -f schema.sql` |
| Tail logs | `docker compose logs -f app` (Apache logs go to stdout/stderr) |
| Rotate JWT_SECRET | Change `JWT_SECRET`, `docker compose up -d` — invalidates all outstanding tokens. |
| Backup dev DB | `docker compose exec db pg_dump -U apex apex > apex_dev.sql` |

### Native fallback (Option B, no Docker)

```bash
cp .env.example .env      # edit DATABASE_URL to your local Postgres
psql "$DATABASE_URL" -f schema.sql
php -S 0.0.0.0:8080 -t public/
```

The PHP built-in server does not process `.htaccess`; `public/index.php` routes
the SPA, and the API must be hit via `/api/index.php/...`. Compose is preferred.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `DB_PASS is required` on `up` | `DB_PASS` unset | `export DB_PASS=...` before `docker compose up`. |
| `JWT_SECRET is required` on `up` | `JWT_SECRET` unset | `export JWT_SECRET=...`. Compose requires it even in dev. |
| `/api/health` connection refused | App still building / port 8080 taken | Wait for build; check `lsof -i :8080`. |
| `{"error":"DATABASE_URL is not set"}` | App container missing env | Confirm `docker-compose.yml` env; rebuild. |
| Login returns `Invalid credentials` | Wrong PIN, or hashes altered with `APEX_ALLOW_DEFAULT_PINS=0` | Use PIN `654321` for `rojas`; or set `APEX_ALLOW_DEFAULT_PINS=1` (dev). |
| `[migrate] Schema already present` but you want a reset | `users` table exists so migrate skips | `docker compose down -v` then `up`. |
| `psql` can't reach 5432 | Bound to loopback only | Connect via `127.0.0.1:5432` (not `0.0.0.0` / container IP). |
| API 500 with stack trace in JSON | `APP_ENV=development` exposes traces | Expected in dev; set `APP_ENV=production` to suppress. |
| Static `/app/apex.js` 404 | Native server without mirrored assets | Use Docker (image mirrors `app/` into `public/app/`), or copy manually. |
