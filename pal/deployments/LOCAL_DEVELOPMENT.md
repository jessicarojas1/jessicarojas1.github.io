# PAL local development

How to run PAL on a developer workstation. Two modes:

- **Mode A, native (day-to-day):** PHP built-in server + Vite dev server + PostgreSQL in a container. Fast reload, debug logging.
- **Mode B, production image:** the real container image via `deploy/compose.yaml`, for testing the image, nginx config and entrypoint.

Scope: PAL **Phase 1 foundation**. Everything is in PostgreSQL; there is no document storage, malware scanner or preview converter yet (Phase 2+). PAL has no AI/LLM features.

Shared material (Entra app registration, database roles, full variable reference) is in [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md).

---

## 1. Deployment architecture

**Mode A**

| Process | Command | Listens on |
|---|---|---|
| Vite dev server (SPA) | `npm run dev` in `frontend/` | `http://localhost:5173` (strict port) |
| PHP backend | `php -S 127.0.0.1:8000 -t public` in `backend/` (or `symfony serve`) | `127.0.0.1:8000` |
| Worker (optional) | `php bin/console messenger:consume async scheduler_default -vv` | — |
| PostgreSQL | container `postgres:17` | `127.0.0.1:5432` |

Vite proxies `/api`, `/auth` and `/health` to the backend (`frontend/vite.config.ts`, target `PAL_BACKEND_URL`, default `http://127.0.0.1:8000`, `changeOrigin: false`). The browser sees a single origin, `http://localhost:5173`, as it would behind nginx in production (BFF model: the session cookie is the only credential in the browser).

**Mode B**: the same containers as production (`migrate`, `web`, `worker`, bundled `db`), reached at `http://localhost:8080`.

## 2. Topology

```
Mode A                                         Mode B
                                               
Browser  http://localhost:5173                 Browser  http://localhost:8080
   |                                              |
Vite dev server (:5173)                        web container (nginx :8080 + PHP-FPM)
   |  /  -> SPA (HMR)                             |
   |  /api /auth /health  --proxy-->              +--> worker container
   v                                              |
PHP built-in server 127.0.0.1:8000             db container (postgres:17-alpine)
   |                                              
   +--> worker (optional, terminal 3)          Entra ID (internet) for sign-in
   v
PostgreSQL container 127.0.0.1:5432
   
Entra ID test tenant (internet) for sign-in
```

## 3. Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4 (what the image and CI use) | Extensions: `pdo_pgsql`, `intl`, `openssl`, `sodium`, `ctype`, `iconv` (CI installs `pdo_pgsql, intl, openssl, sodium`) |
| Composer | 2.x | |
| Node.js | 24 (CI); `package.json` requires `>=22` | npm comes with Node |
| Docker | Engine/Desktop with Compose v2 | For PostgreSQL (Mode A) or everything (Mode B) |
| PostgreSQL | 16+ (17 recommended) | Run in Docker; matching `serverVersion` in `DATABASE_URL` |
| Entra ID | A **test** tenant/app registration you may use | There is **no local login bypass**: without OIDC configured, sign-in redirects to `/sign-in?auth_error=sign_in_unavailable`. Tests use a fake Entra (`tests/Support/FakeEntraId`), not a dev server |
| Optional | Symfony CLI | `symfony serve` instead of `php -S` |

## 4. Identity and credentials

- Use a **separate development app registration** in a test tenant (never production credentials). Follow [SINGLE_LINUX_SERVER.md §4.2](SINGLE_LINUX_SERVER.md#42-entra-id-app-registration-shared-by-all-deployment-guides) with these differences:
  - Mode A redirect URI: `http://localhost:5173/auth/callback`. Mode B: `http://localhost:8080/auth/callback`. Entra allows `http://localhost` redirect URIs.
  - A **client secret** is acceptable for development (`OIDC_CLIENT_SECRET`); leave `OIDC_CLIENT_CERT_PATH`/`OIDC_CLIENT_KEY_PATH` empty. Exactly one credential must be configured.
  - Graph application permissions + admin consent are only needed if you work on directory sync.
  - Set *Assignment required? = Yes* on the dev enterprise application and assign only your dev users group, as production requires: every account that signs in becomes a PAL Reader, B2B guests included.
- Put real values in `backend/.env.local` (git-ignored by `backend/.gitignore`). Never put secrets in `.env.example`. The repository is public.
- Local DB credentials (`pal`/`pal`) are development-only.

## 5. Environment variables

Mode A reads `backend/.env` (copy of `.env.example`) then `backend/.env.local` (your overrides). Real environment variables win.

| Variable | Example (Mode A) | Purpose |
|---|---|---|
| `APP_ENV` | `dev` | Symfony environment. `dev` logs to `backend/var/log/dev.log` and the console |
| `APP_SECRET` | `openssl rand -hex 32` | Required. Kernel secret |
| `DEFAULT_URI` | `http://localhost:5173` | Router default URI for CLI URL generation |
| `DATABASE_URL` | `postgresql://pal:pal@127.0.0.1:5432/pal_dev?serverVersion=17&charset=utf8` | Dev database. Set `serverVersion` to your server's major version (`.env.example` says 16) |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` | Queue in PostgreSQL |
| `PAL_BASE_URL` | `http://localhost:5173` | Public origin; OIDC redirect URI = this + `/auth/callback` |
| `TRUSTED_PROXIES` | `127.0.0.1` | Vite proxy connects from loopback |
| `TRUSTED_HOSTS` | `^(localhost\|127\.0\.0\.1)$` | Accepted Host headers (Vite keeps `Host: localhost:5173`) |
| `PAL_SESSION_COOKIE_NAME` | `pal_session` | Plain name; `__Host-` prefix needs HTTPS |
| `PAL_SESSION_COOKIE_SECURE` | `0` | Plain HTTP in dev |
| `PAL_SESSION_IDLE_SECONDS` | `3600` | Idle timeout |
| `PAL_SESSION_ABSOLUTE_SECONDS` | `43200` | Absolute lifetime |
| `PAL_DEFAULT_TIMEZONE` | `America/Denver` | Organisation timezone |
| `OIDC_AUTHORITY` | `https://login.microsoftonline.com` | Entra authority |
| `OIDC_TENANT_ID` | `<test-tenant-guid>` | Test tenant |
| `OIDC_CLIENT_ID` | `<dev-app-guid>` | Dev app registration |
| `OIDC_CLIENT_SECRET` | `<dev-secret>` (in `.env.local` only) | Dev credential |
| `OIDC_CLIENT_CERT_PATH` / `OIDC_CLIENT_KEY_PATH` | *(empty)* | Leave empty when using a secret |
| `GRAPH_BASE_URL` | `https://graph.microsoft.com/v1.0` | Graph |
| `PAL_BOOTSTRAP_ADMIN_OIDS` | `<your-user-object-guid>` | Make yourself System Administrator |
| `PAL_AUDIT_ANCHOR_DIR` | *(empty)* or `/tmp/pal-anchor` | Optional audit anchor directory |
| `LOG_LEVEL` | `info` | Used by the prod log handler only; dev logs at debug |

For Mode B, the variable set is the production one ([SINGLE_LINUX_SERVER.md §5](SINGLE_LINUX_SERVER.md#5-environment-variables)) with the local values in §6.

## 6. Configuration references

| Setting | Value | Purpose |
|---|---|---|
| `PAL_BACKEND_URL` (shell variable for Vite) | `http://127.0.0.1:8000` | Where Vite proxies `/api`, `/auth`, `/health`. Change if the backend runs elsewhere |
| Vite dev port | `5173` (`strictPort`) | Fails instead of picking another port, so the redirect URI stays valid |
| Vite preview port | `4174` | `npm run preview` serves `dist/` with the production CSP header, to catch CSP violations |
| Test DB | `postgresql://pal:pal@127.0.0.1:5432/pal_test?serverVersion=16&charset=utf8` (`phpunit.dist.xml`) | **Dropped and rebuilt from migrations on every test run.** The bootstrap refuses any database whose name lacks `_test`. `PAL_TEST_SKIP_DB_RESET=1` skips the reset |
| Mode B local overrides in `deploy/pal.env` | `PAL_BASE_URL=http://localhost:8080`, `TRUSTED_HOSTS=^localhost$`, `PAL_SESSION_COOKIE_NAME=pal_session`, `PAL_SESSION_COOKIE_SECURE=0`, `DEFAULT_URI=http://localhost:8080` | The image defaults (`__Host-pal_session`, Secure) assume HTTPS |

## 7. Verification

### 7.1 Mode A setup

```sh
# PostgreSQL (dev + test databases)
docker run -d --name pal-pg -p 127.0.0.1:5432:5432 \
  -e POSTGRES_USER=pal -e POSTGRES_PASSWORD=pal -e POSTGRES_DB=pal_dev postgres:17
docker exec pal-pg createdb -U pal pal_test

# Backend
cd pal/backend
composer install
cp .env.example .env
cat > .env.local <<'EOF'
APP_SECRET=replace-with-openssl-rand-hex-32
DATABASE_URL="postgresql://pal:pal@127.0.0.1:5432/pal_dev?serverVersion=17&charset=utf8"
OIDC_TENANT_ID=<test-tenant-guid>
OIDC_CLIENT_ID=<dev-app-guid>
OIDC_CLIENT_SECRET=<dev-secret>
PAL_BOOTSTRAP_ADMIN_OIDS=<your-user-object-guid>
EOF
php bin/console doctrine:migrations:migrate --no-interaction
php -S 127.0.0.1:8000 -t public          # terminal 1

# Frontend
cd pal/frontend
npm ci
npm run dev                               # terminal 2 -> http://localhost:5173

# Worker (optional; runs the 15-min directory sync and daily audit verification)
cd pal/backend && php bin/console messenger:consume async scheduler_default -vv   # terminal 3
```

The dev database here is owned by the `pal` superuser-like bootstrap user, so `roles.sql` is not needed for Mode A. To test least-privilege behaviour, use Mode B.

### 7.2 Checks

| # | Check | Command | Expected |
|---|---|---|---|
| 1 | Liveness through the proxy | `curl -fsS http://localhost:5173/health/live` | `{"status":"ok"}` |
| 2 | Readiness | `curl -sS http://localhost:5173/health/ready` | `{"status":"ready","checks":{"database":"ok","migrations":"ok"}}` |
| 3 | Sign-in | Open `http://localhost:5173`, sign in | Signed in, not redirected to `/sign-in?auth_error=…` |
| 4 | Secrets resolved | Sign-in works (OIDC); `curl -s http://localhost:5173/api/me` without a session returns 401 `{"error":"unauthenticated",…}` | Sign-in succeeds; anonymous `/api/me` is 401 |
| 5 | Directory sync (if Graph consent exists) | `php bin/console pal:roles:map AUTHOR <group-object-id>` then `php bin/console pal:directory:sync` | Mapping + `N members (+a / -r)` |
| 6 | Audit chain | `php bin/console pal:audit:verify` | `Audit chain valid: …` |
| 7 | Upload accepted + scanned | — | **Not applicable until Phase 2 (document storage)** |

### 7.3 Quality gates (same as CI, `.github/workflows/pal-ci.yml`)

```sh
cd pal/backend
composer audit
APP_SECRET=ci php bin/console lint:container
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
vendor/bin/phpstan analyse -c phpstan.tests.neon --no-progress --memory-limit=1G
vendor/bin/deptrac analyse --no-progress
vendor/bin/phpunit            # needs pal_test; includes schema.sql parity test

cd ../frontend
npm audit --audit-level=high
npm run lint && npm run typecheck && npm test && npx vite build
```

### 7.4 Mode B

```sh
cd pal
umask 077; for f in db_admin_password db_owner_password db_app_password; do openssl rand -hex 24 > deploy/secrets/$f.txt; done
cp deploy/pal.env.example deploy/pal.env    # set local values from §6 + passwords from deploy/secrets
#   OIDC: set OIDC_CLIENT_SECRET and BLANK OIDC_CLIENT_CERT_PATH / OIDC_CLIENT_KEY_PATH.
#   compose.yaml mounts Docker secrets pal_oidc_cert/pal_oidc_key from deploy/secrets/oidc_cert.pem and
#   oidc_key.pem; Compose needs the files to exist, so create empty placeholders when using a secret:
touch deploy/secrets/oidc_cert.pem deploy/secrets/oidc_key.pem
docker compose -f deploy/compose.yaml --profile bundled-db up -d --build
curl -fsS http://localhost:8080/health/ready
```

## 8. Day-2 operations

| Task | Command |
|---|---|
| New migration | `php bin/console doctrine:migrations:generate`, write explicit SQL, then **update `database/schema.sql`** (the parity test fails otherwise) |
| Apply migrations | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Reset dev DB | `docker exec pal-pg dropdb -U pal pal_dev && docker exec pal-pg createdb -U pal pal_dev` then migrate (destroys local data) |
| Update dependencies | `composer update <pkg>` / `npm install <pkg>@<version>`; then run the audits in §7.3 |
| Rotate dev secret | New secret in the dev app registration, update `.env.local`, restart `php -S` |
| Logs | `backend/var/log/dev.log`; worker output in its terminal |
| Failed jobs | `php bin/console messenger:failed:show` / `messenger:failed:retry` |
| Backups | Not needed for dev data; never load real organisational documents locally |
| Clear cache | `php bin/console cache:clear` |

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `/sign-in?auth_error=sign_in_unavailable` | OIDC not configured: zero/placeholder GUIDs, or both secret and cert paths set (or neither), or Entra unreachable | Set real test GUIDs and exactly one credential in `.env.local`; check `var/log/dev.log` for `Sign-in is unavailable` and its `reason` |
| `/sign-in?auth_error=sign_in_failed` | Redirect URI/credential mismatch, wrong tenant | Check the app registration has `http://localhost:5173/auth/callback`; check the secret |
| `/sign-in?auth_error=sign_in_expired` | Session cookie not kept (e.g. `__Host-` name or `Secure=1` over HTTP) | `PAL_SESSION_COOKIE_NAME=pal_session`, `PAL_SESSION_COOKIE_SECURE=0` |
| HTTP **400** on every request | `Host` not matched by `TRUSTED_HOSTS` (e.g. using a LAN IP or custom hostname) | Add it to `TRUSTED_HOSTS` |
| Rate limits hit for everyone / wrong client IP | Proxy IP not in `TRUSTED_PROXIES` | Keep `127.0.0.1` for the Vite proxy |
| `/health/ready` **503**, `"migrations":"fail"` | Migrations not applied | `php bin/console doctrine:migrations:migrate` |
| Vite fails `Port 5173 is in use` | Strict port | Free the port (do not change it without updating the redirect URI and `PAL_BASE_URL`) |
| `502`/proxy error on `/api` from Vite | Backend not running on `PAL_BACKEND_URL` | Start `php -S 127.0.0.1:8000 -t public` |
| PHPUnit: `Refusing to reset a database whose name does not contain _test` | `DATABASE_URL` points at a non-test DB | Use a `*_test` database |
| Mode B container exits with code **64** | Missing required variable | See the log line `pal: required environment variable X is not set` |
| `could not find driver` | `pdo_pgsql` extension missing | Install/enable it |
| HTTP **403** `self_grant_forbidden` when mapping a group | You are a member of the group you are mapping to `PAL_ADMINISTRATOR`/`PUBLISHER`/`AUTHOR` | Use a group you are not in, or have another admin map it |
| Branding logo URL rejected | `http://` URLs are refused | Use `https://` or upload a raster image (`data:` URL) |
| `/sign-in?auth_error=too_many_attempts` while testing | `/auth/login` and `/auth/callback` share a 20/min per-IP limit | Wait a minute |

---

Related: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) (production reference).
