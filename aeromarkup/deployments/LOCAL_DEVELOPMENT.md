# AeroMarkup — Local Development

Operator guide for running AeroMarkup on a developer workstation. Two supported
paths: a one-command **docker-compose** stack (app + Postgres) and a **native**
run (Python venv + local Postgres). A third, **static-only** preview serves the
offline-first PWA with no backend.

Sibling guides: [SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES](KUBERNETES.md) · [AZURE](AZURE.md) · [AWS](AWS.md) ·
[AIRGAPPED](AIRGAPPED.md). See also [docs/DEPLOYMENT](../docs/DEPLOYMENT.md) and
[docs/ARCHITECTURE](../docs/ARCHITECTURE.md).

---

## 1. Deployment architecture

AeroMarkup is a single stateless Flask web process (`server.py`, served by
gunicorn) plus a PostgreSQL 13+ database. There is **no object storage**:
uploaded reference images and STL/OBJ 3D models are stored as `data:` URLs in
Postgres columns (`drawings.background_data`, `drawings.model_data`,
`attachments.data`). The frontend under `static/` is an offline-first PWA (ES
modules, IndexedDB, service worker) that syncs to the API when online.

All DB objects live in a dedicated `aeromarkup` schema; the app pins
`search_path=aeromarkup,public`, so it is safe to share a database with other
apps. With `AUTO_MIGRATE=1` (default) the app applies `db/schema.sql` at boot.

Locally you run with `ENVIRONMENT=development`, which relaxes secure-cookie and
secret-strength enforcement so you can use a weak/short `AEROMARKUP_SECRET` (or
none, when `DATABASE_URL` is unset).

Three modes:

| Mode | Command | Backend | State |
|------|---------|---------|-------|
| Compose stack | `docker compose up --build` | Flask+gunicorn + Postgres 16 | Postgres volume |
| Native | `python server.py` + local Postgres | Flask dev server | your Postgres |
| Static preview | `python3 preview_server.py` | none (PWA only) | browser IndexedDB |

---

## 2. Topology

```
  Developer browser (PWA: IndexedDB + service worker)
        │  http://localhost:8080   (or :4173 static-only)
        ▼
 ┌─────────────────────────────┐
 │  Flask app  (server.py)     │   ENVIRONMENT=development
 │  gunicorn  :8080            │   AUTO_MIGRATE=1 → db/schema.sql
 │  /api/health /api/auth/* …  │
 └───────────────┬─────────────┘
                 │ psycopg (search_path=aeromarkup,public)
                 ▼
 ┌─────────────────────────────┐
 │  PostgreSQL 13+             │   schema: aeromarkup
 │  projects, drawings, ncrs,  │   images/models stored as data: URLs
 │  inspections, approvals,    │
 │  audit, users               │
 └─────────────────────────────┘

 Static-only preview (no DB):
  Developer browser ──> preview_server.py :4173 ──> static/  (PWA offline mode)
```

---

## 3. Prerequisites

| Tool | Version | Needed for |
|------|---------|-----------|
| Docker Engine + Compose v2 | 24+ / `docker compose` | Compose path |
| Python | 3.12 | Native + static preview |
| pip / venv | bundled with 3.12 | Native |
| PostgreSQL | 13+ (16 recommended) | Native path (server or local) |
| `psql` client | matches server | Verification, manual migrate |
| `curl` | any | Verification |

The Compose path needs only Docker. The native path needs Python 3.12 and a
reachable PostgreSQL. The static preview needs only Python 3.12.

---

## 4. Identity & credentials

- **Postgres password** — the Compose stack refuses to start without
  `POSTGRES_PASSWORD`. Put it in a local, git-ignored `.env`. Never commit it.
- **`AEROMARKUP_SECRET`** — signs the `am_session` cookie. In `production` it is
  **required** and must be ≥32 chars when `DATABASE_URL` is set. Locally set
  `ENVIRONMENT=development` so a weak or absent secret is permitted; still,
  generate a real one to mirror prod:
  ```bash
  python3 -c "import secrets;print(secrets.token_urlsafe(48))"
  ```
- **No shipped default login.** On first run against an empty DB you create the
  initial admin via `POST /api/auth/bootstrap` (see Verification). Least
  privilege: give your local Postgres role only what it needs (owner of the
  `aeromarkup` schema is enough; superuser not required).

Roles in the app: `viewer`, `engineer`, `inspector`, `approver`, `admin`.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgres://aeromarkup:pw@127.0.0.1:5432/aeromarkup` | Postgres DSN. **Empty ⇒ offline-only mode** (API returns `no_database`, PWA still works). |
| `PORT` | `8080` | HTTP listen port (default 8080). |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` at boot (default 1). Set `0` to migrate manually. |
| `ENVIRONMENT` | `development` | Use `development`/`local`/`test` locally to relax secure-cookie + secret enforcement. `production` is the default. |
| `AEROMARKUP_SECRET` | `k3f…` (≥32 chars) | Signs `am_session`. Required in production with a DB; optional/weak allowed in development. |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime (default 12h). |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed logins before throttle. |
| `LOGIN_WINDOW_SECONDS` | `300` | Throttle window. |
| `LOGIN_MAX_TRACKED` | `8192` | Max IP/user keys tracked in-memory. |
| `TRUSTED_PROXY_HOPS` | `0` | Proxy hops to trust for client IP. Keep `0` locally (no proxy). |
| `POSTGRES_USER` | `aeromarkup` | Compose only — Postgres role. |
| `POSTGRES_PASSWORD` | (from `.env`) | Compose only — **required**, no default. |
| `POSTGRES_DB` | `aeromarkup` | Compose only — database name. |

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| Schema file | `db/schema.sql` | Idempotent DDL; auto-applied when `AUTO_MIGRATE=1`, or `psql "$DATABASE_URL" -f db/schema.sql`. |
| Seed file | `db/seed.sql` | Optional demo data: `psql "$DATABASE_URL" -f db/seed.sql`. |
| Session cookie | `am_session` | HttpOnly, signed via itsdangerous. |
| CSRF cookie | `am_csrf` | Double-submit token; echo it in header `X-CSRF-Token` on mutating requests. |
| Schema search path | `aeromarkup,public` | Pinned per connection — shared-DB safe. |
| Example env | `.env.example` | Copy to `.env` and edit. Never commit `.env`. |
| Compose file | `docker-compose.yml` | app + Postgres 16; app on `8080:8080`, db bound to `127.0.0.1:5432`. |

---

## 7. Verification

### Path A — docker-compose (fast path)

```bash
cd aeromarkup
cp .env.example .env
# edit .env: set a strong POSTGRES_PASSWORD (required)
echo "POSTGRES_PASSWORD=$(python3 -c 'import secrets;print(secrets.token_urlsafe(24))')" >> .env
docker compose up --build          # → http://localhost:8080
```

### Path B — native (venv)

```bash
cd aeromarkup
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt

# start a local Postgres (any 13+). Example with Docker:
docker run -d --name am-pg -p 127.0.0.1:5432:5432 \
  -e POSTGRES_USER=aeromarkup -e POSTGRES_PASSWORD=devpw \
  -e POSTGRES_DB=aeromarkup postgres:16-alpine

export ENVIRONMENT=development
export DATABASE_URL="postgres://aeromarkup:devpw@127.0.0.1:5432/aeromarkup"
export AEROMARKUP_SECRET="$(python3 -c 'import secrets;print(secrets.token_urlsafe(48))')"
export AUTO_MIGRATE=1
python server.py                   # → http://localhost:8080
```

> If you set `AUTO_MIGRATE=0`, apply the schema by hand first:
> `psql "$DATABASE_URL" -f db/schema.sql`

### Path C — static-only offline preview (no backend)

```bash
cd aeromarkup
python3 preview_server.py          # → http://127.0.0.1:4173
```
The PWA loads and works offline against IndexedDB. API calls report offline;
this path is for UI/PWA work only.

### Smoke checks (Paths A & B)

1) **Health** — expect `database: connected`, `mode: online`:
```bash
curl -s localhost:8080/api/health
# {"status":"ok","database":"connected","mode":"online"}
```

2) **Bootstrap the first admin** (only works while no password user exists):
```bash
curl -s -X POST localhost:8080/api/auth/bootstrap \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme123","display_name":"Admin"}'
```

3) **Log in and capture session + CSRF cookies**:
```bash
curl -s -c cookies.txt -X POST localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme123"}'
# read the CSRF token that the login set as the am_csrf cookie:
CSRF=$(awk '/am_csrf/{print $7}' cookies.txt)
```

4) **Create a project** (mutating → needs session cookie + `X-CSRF-Token`):
```bash
curl -s -b cookies.txt -X POST localhost:8080/api/projects \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Wing Rib Inspection","category":"aerospace"}'
```

5) **Confirm the row landed in Postgres** (`aeromarkup` schema):
```bash
psql "$DATABASE_URL" -c "SELECT count(*) FROM aeromarkup.projects;"
#  count
# -------
#      1
```
(Compose users: `psql "postgres://aeromarkup:$POSTGRES_PASSWORD@127.0.0.1:5432/aeromarkup" -c "SELECT count(*) FROM aeromarkup.projects;"`)

---

## 8. Day-2 operations

- **Upgrade code**: `git pull`, then rebuild (`docker compose up --build`) or
  reinstall deps (`pip install -r requirements.txt`) and restart.
- **Re-apply schema**: idempotent — safe to re-run any time:
  `psql "$DATABASE_URL" -f db/schema.sql`. With `AUTO_MIGRATE=1` a restart also
  applies pending schema changes.
- **Seed/refresh demo data**: `psql "$DATABASE_URL" -f db/seed.sql`.
- **Backups** (native local DB):
  ```bash
  pg_dump "$DATABASE_URL" -n aeromarkup -Fc -f aeromarkup-$(date +%F).dump
  # restore: pg_restore -d "$DATABASE_URL" --clean aeromarkup-YYYY-MM-DD.dump
  ```
  Note: dumps include image/model data URLs stored in table columns.
- **Logs**: Compose → `docker compose logs -f app db`. Native → gunicorn/Flask
  writes to the console; check `/api/health` for DB reachability.
- **Secret rotation**: change `AEROMARKUP_SECRET` and restart — existing
  `am_session` cookies become invalid and users must log in again.
- **Reset the stack** (Compose): `docker compose down -v` drops the `pgdata`
  volume and all local data.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `/api/health` shows `"database":"offline"`, `mode: offline-only`; API calls return `503 {"error":"no_database"}` | `DATABASE_URL` unset/empty | Export a valid `DATABASE_URL`; restart. PWA still works offline by design. |
| App refuses to start: `AEROMARKUP_SECRET is missing or too weak (need >= 32 chars)` | `ENVIRONMENT=production` (default) with a DB and no/weak secret | Set `ENVIRONMENT=development` locally, or provide a ≥32-char `AEROMARKUP_SECRET`. |
| `403 {"error":"csrf_failed"}` on POST | Missing/stale `X-CSRF-Token` header, or no `am_csrf` cookie | Log in first, read `am_csrf` from the cookie jar, send it as `X-CSRF-Token`. |
| `429 {"error":"too_many_attempts"}` | Login brute-force throttle tripped (`LOGIN_MAX_ATTEMPTS` in `LOGIN_WINDOW_SECONDS`) | Wait for the window to elapse, or restart the app to clear in-memory counters. |
| `401 unauthorized` on API | No/expired session cookie | Log in again (session TTL `SESSION_TTL_SECONDS`, default 12h); send `-b cookies.txt`. |
| `bootstrap` returns `403 already_initialized` | A password user already exists | Bootstrap is first-run only; use `/api/auth/login` instead. |
| `bootstrap` returns `400 weak_credentials` | username <3 or password <8 chars | Use username ≥3, password ≥8. |
| Compose: `POSTGRES_PASSWORD must be set` | No `.env` / empty password | Add a strong `POSTGRES_PASSWORD` to `.env`. |
| Port 8080 already in use | Another process on 8080 | Set `PORT` to a free port and reconnect. |
| Static preview shows no data / API errors at :4173 | Expected — no backend on the preview server | Use Path A/B for API work; :4173 is PWA-only. |
