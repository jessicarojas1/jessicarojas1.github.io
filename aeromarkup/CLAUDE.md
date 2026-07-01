# CLAUDE.md — AeroMarkup Project Guidance

Guidance for working in the **AeroMarkup** project. This inherits the monorepo
rules in the repository-root [`../CLAUDE.md`](../CLAUDE.md); the notes below are
AeroMarkup-specific. **Read this before changing code, and keep the standard doc
set current** (see "Standing doc rule" at the end).

---

## What this app is

AeroMarkup is an **offline-first aerospace/manufacturing engineering-lifecycle
platform** for DoD programs: redline engineering drawings and photos of
airframes/parts, then run the work through NCR disposition, quality inspection,
and electronic-signature approval — on tablets/phones, **with or without
internet**. Deploys to Render today; the same container runs in AWS GovCloud and
Azure Government.

## Stack

| Layer | Detail |
|-------|--------|
| Backend | Python 3.12, **Flask** (`server.py`), served by **gunicorn** (2 workers, `--timeout 120`) |
| Auth | `werkzeug` password hashing, `itsdangerous` signed session tokens, double-submit CSRF |
| DB | **PostgreSQL 13+**, `psycopg` 3, dedicated `aeromarkup` schema, `search_path=aeromarkup,public` (shared-DB safe) |
| Frontend | Offline-first **PWA** — vanilla ES modules under `static/js/`, IndexedDB, service worker, self-contained WebGL 3D viewer. **No CDN / no build step / no external runtime calls.** |
| Container | `python:3.12-slim`, non-root uid `10001`, `HEALTHCHECK` on `/api/health` |

## Where things live

```
server.py            # all backend routes, auth, RBAC, audit, /api/sync
preview_server.py    # static-only offline PWA preview (dev, :4173)
db/schema.sql        # AUTHORITATIVE idempotent schema (updated with every migration)
db/seed.sql          # optional demo data
static/js/*          # app, router, store(IndexedDB), session, api, canvas, viewer3d, snapshot, branding, audit, ui, icons, views, charts
static/sw.js         # service worker
deploy/aws-govcloud/ # ECS Fargate task-definition.json + deploy.sh
deploy/azure-gov/    # Container Apps + App Gateway bicep + deploy.sh
deployments/         # 6 per-target operator runbooks
docs/                # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
Dockerfile / docker-compose.yml / render.yaml / .env.example
```

## Runtime model & conventions

- **Stateless server.** All durable state is in Postgres + each client's
  IndexedDB. Do not add local/in-process state that must survive restarts or be
  shared across replicas (the login throttle is the one deliberate in-memory
  exception — treat gateway/WAF as the durable limiter).
- **Offline-first / IndexedDB-authoritative.** The client writes locally first
  and reconciles via `POST /api/sync`. Every synced record carries a stable
  client-generated `client_uid` for idempotent upserts — preserve this when
  touching sync or write paths.
- **Config is env-only.** No config files. New settings are env vars, documented
  in `.env.example`, `docs/DEPLOYMENT.md`, and every affected `deployments/*.md`.
- **`AUTO_MIGRATE=1`** applies `db/schema.sql` at boot. The schema file is the
  single source of truth — there is no migration tool or version table.

## Security rules that apply here (must hold)

- **Parameterized SQL only** — never concatenate user input into SQL
  (`server.py` binds every value; keep it that way).
- **CSRF** — all state-changing `/api/*` (POST/PUT/PATCH/DELETE) require the
  `X-CSRF-Token` header matching the `am_csrf` cookie. New write routes are
  automatically gated by the `before_request` hook; don't bypass it.
- **AuthN/session** — sessions are stateless signed tokens in the **HttpOnly**
  `am_session` cookie (never localStorage). `AEROMARKUP_SECRET` (≥32 chars) is
  **required in production**; the app refuses to boot without it. It must be
  identical across replicas.
- **RBAC** — use the `@requires("<capability>")` decorator (or inline `_can()`)
  on every new mutating route, matching the `CAP` matrix. Mirror any capability
  change in `static/js/session.js` so client and server agree.
- **Server-bound identity** — e-signatures, `raised_by`, and inspector identity
  come from the authenticated session, never client-supplied fields. Use
  `uuid_or_none()` to keep free text out of uuid FK columns.
- **No default credentials** — first admin is created via
  `POST /api/auth/bootstrap`, allowed only while no user has a password.
- **CUI handling** — keep classification banners and classification columns;
  never introduce third-party CDN/runtime/AI egress (air-gap safety).
- **Branding** — logo URLs must stay sanitized to `http(s)://` or `data:image/`
  and user strings escaped (`static/js/branding.js`).
- **No secrets committed** — only `.env.example` with placeholders.

## Build / test / run

```bash
# Local full stack (app + Postgres)
export POSTGRES_PASSWORD='<strong secret>'
docker compose up --build            # http://localhost:8080

# Native backend
pip install -r requirements.txt
export ENVIRONMENT=development
export DATABASE_URL='postgres://aeromarkup:pass@localhost:5432/aeromarkup'
python server.py                     # http://localhost:8080

# Static offline preview (no backend)
python3 preview_server.py            # http://localhost:4173

# Frontend self-test (3D math, parsers, WebGL): open /test.html in a browser

# Apply schema manually
psql "$DATABASE_URL" -f db/schema.sql
```

Health check: `curl -s localhost:8080/api/health`.

## Deploy

- **Render:** `render.yaml` blueprint (web + managed Postgres, generates
  `AEROMARKUP_SECRET`).
- **AWS GovCloud:** `./deploy/aws-govcloud/deploy.sh` (ECS Fargate + RDS +
  Secrets Manager) — see [`deployments/AWS.md`](deployments/AWS.md).
- **Azure Gov:** `./deploy/azure-gov/deploy.sh` (Container Apps + Flexible
  Server + Key Vault + Managed Identity) — see
  [`deployments/AZURE.md`](deployments/AZURE.md).
- **Kubernetes / single VM / air-gapped:** see the matching `deployments/*.md`.

Full guide: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Standing doc rule (do not skip)

Whenever a feature, migration, config change, endpoint, or env var lands,
update **in the same change**:

- [ ] `db/schema.sql` — must always reflect the full, combined schema.
- [ ] `.env.example` — any new/changed env var.
- [ ] `docs/` (Architecture / Deployment / Disaster Recovery / Security) as affected.
- [ ] the affected `deployments/*.md` runbooks.
- [ ] `README.md` and `OPEN_ITEMS.md`.

The standard doc set (`deployments/` ×6, `docs/` ×4, `README.md`,
`OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`) is part of "done" and
must be kept accurate to the code. The app must remain **Docker- and
Render-deploy compatible**.
