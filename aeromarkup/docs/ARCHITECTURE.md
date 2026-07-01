# AeroMarkup — Architecture

> **CUI-aware aerospace engineering-lifecycle platform for DoD programs.**
> AeroMarkup lets engineers redline engineering drawings and photos of airframes
> and parts, drive them through Non-Conformance Report (NCR) disposition,
> inspection, and e-signature approval — **fully offline in the field**, then
> reconcile across devices when connectivity returns.

Related docs: [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md) ·
[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · operator guides under
[`../deployments/`](../deployments/).

---

## 1. Platform

| Layer | Technology |
| --- | --- |
| **Frontend** | Offline-first **Progressive Web App (PWA)** — modular native **ES-module SPA**, no framework, no build step. Service worker (`static/sw.js`) for offline shell + asset caching. **IndexedDB** as the authoritative local store. |
| **Backend** | **Flask** (`server.py`, ~1260 lines) served by **gunicorn** (2 workers), **Python 3.12**. Stateless — holds no session or business state in process memory. |
| **Database** | **PostgreSQL 13+**, accessed via **psycopg3**. Dedicated `aeromarkup` schema; `pgcrypto` for `gen_random_uuid()`. |
| **Transport** | JSON REST + sync API under `/api/*`. TLS terminates at the edge (ALB / Azure Container App ingress / Render). |
| **Targets** | Render, **AWS GovCloud**, **Azure Government**. Docker- and Render-deploy compatible. |

There are **no third-party CDN, runtime, or AI calls** — every asset is
self-hosted, making the app **air-gap safe** by construction.

---

## 2. Design Principles

1. **Offline-first / IndexedDB-authoritative.** Every module (canvas redlining,
   NCR, inspection, approval, 3D viewer, audit) works fully with no network.
   The browser's IndexedDB is the source of truth for the local operator; the
   server is **best-effort persistence + multi-device reconciliation**, not a
   hard dependency for day-to-day work.
2. **Stateless server.** All durable state lives in PostgreSQL and in each
   client's IndexedDB. Sessions are **stateless signed tokens** (no server-side
   session store), so any replica can serve any request. Horizontal scaling is
   trivial provided `AEROMARKUP_SECRET` is identical across replicas.
3. **Shared-DB-safe schema.** All objects live in a dedicated `aeromarkup`
   schema and the app connects with `search_path=aeromarkup,public`, so the app
   can safely co-tenant a PostgreSQL instance shared with other apps without
   table-name collisions.
4. **No external egress.** No CDN, telemetry, or hosted-AI dependency. This is a
   deliberate DLP / air-gap posture (see [SECURITY.md](SECURITY.md)).
5. **RBAC + immutable audit.** Every consequential mutation is authorized
   against a role capability matrix and written to an append-only audit log
   **inside the same transaction**.
6. **CUI-aware.** Classification banners (top + bottom, including print output)
   and classification columns on programs/projects/drawings/NCRs are
   first-class.

---

## 3. Component Overview

### 3.1 Frontend modules (`static/js/`)

| Module | Responsibility |
| --- | --- |
| `app.js` | Bootstraps the SPA, wires modules, service-worker registration. |
| `router.js` | Hash/History routing between views. |
| `store.js` | **IndexedDB** data layer — the offline-authoritative store. |
| `session.js` | Auth/session state, login/bootstrap flows, CSRF token handling. |
| `audit.js` | Client-side audit surfacing / `/api/audit` reader. |
| `api.js` | REST + sync client (fetch wrapper, CSRF header injection, error decode). |
| `ui.js` | Shared UI primitives, toasts, modals. |
| `icons.js` | Inline SVG icon set (self-hosted, no icon CDN). |
| `canvas.js` | Drawing / redline canvas — layers, strokes, annotations. |
| `views.js` | View controllers (programs, projects, drawings, NCR, inspection, approvals). |
| `viewer3d.js` | STL/OBJ 3D model viewer for airframe/part models. |
| `snapshot.js` | Point-in-time snapshots / revisions of drawings. |
| `branding.js` | Org name, logo URL, accent color (sanitized, persisted). |
| `charts.js` | Dashboards / status charts. |

Static shell: `static/index.html`, `static/sw.js`, `static/app.css`,
`static/editor.css`, `static/manifest.webmanifest`.

### 3.2 Backend (`server.py`)

- Flask app exposing the `/api/*` JSON surface.
- Auth (stateless signed cookie tokens), CSRF double-submit, RBAC via the `CAP`
  matrix and `@requires(action)` decorator.
- Upsert-based write endpoints keyed on `client_uid` for idempotent replay.
- `/api/sync` change-journal reconciliation.
- Optional boot-time migration (`AUTO_MIGRATE=1`).
- `preview_server.py` — lightweight static preview harness for the frontend.

### 3.3 Database (`db/`)

- `db/schema.sql` — **idempotent** DDL (`CREATE TABLE IF NOT EXISTS`,
  `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`).
- `db/seed.sql` — optional reference/seed data.

Tables (all in schema `aeromarkup`):

```
users            programs         projects         drawings
layers           strokes          annotations      attachments
revisions        ncrs             inspections      inspection_items
approvals        comments         audit_log        sync_log
```

`updated_at` columns are maintained by database **triggers** — application code
never sets them.

---

## 4. Monorepo Structure

AeroMarkup lives at `aeromarkup/` inside the `jessicarojas1.github.io` monorepo.

```
jessicarojas1.github.io/
└── aeromarkup/
    ├── server.py              # Flask API + sync (~1260 lines)
    ├── preview_server.py      # static frontend preview harness
    ├── requirements.txt       # Flask, gunicorn, psycopg3, itsdangerous, werkzeug
    ├── Dockerfile             # multi-stage, non-root uid 10001, healthcheck
    ├── docker-compose.yml     # local app + Postgres
    ├── render.yaml            # Render Blueprint (generateValue for secret)
    ├── .env.example           # documented env vars (never commit .env)
    ├── db/
    │   ├── schema.sql         # idempotent full schema
    │   └── seed.sql           # optional seed data
    ├── deploy/
    │   ├── aws-govcloud/      # GovCloud IaC / manifests
    │   └── azure-gov/         # Azure Government IaC / manifests
    ├── static/
    │   ├── index.html
    │   ├── sw.js              # service worker
    │   ├── app.css  editor.css
    │   ├── manifest.webmanifest
    │   └── js/                # app router store session audit api ui icons
    │                          # canvas views viewer3d snapshot branding charts
    ├── deployments/           # operator guides (LOCAL_DEVELOPMENT.md, …)
    └── docs/                  # ARCHITECTURE / DEPLOYMENT / SECURITY / DISASTER_RECOVERY
```

---

## 5. Configuration Model

Configuration is **pure environment variables** — no config files at runtime.

| Variable | Example | Purpose |
| --- | --- | --- |
| `DATABASE_URL` | `postgresql://user:pass@host:5432/db?sslmode=require` | Postgres DSN. When set in production, `AEROMARKUP_SECRET` is **required**. |
| `PORT` | `8080` (Render: `10000`) | HTTP listen port. |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` at boot (idempotent). |
| `ENVIRONMENT` | `production` (default) | `production` enforces secure cookies + secret. `dev`/`local`/`test` relax secure-cookie + secret enforcement. |
| `AEROMARKUP_SECRET` | `<random ≥32 chars>` | Signs stateless session tokens. **REQUIRED in prod when `DATABASE_URL` is set — the app REFUSES to boot without it.** Must be identical across replicas. |
| `SESSION_TTL_SECONDS` | `43200` (12h) | Session token max age. |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed logins per window before HTTP 429. |
| `LOGIN_WINDOW_SECONDS` | `300` | Sliding window for login throttle. |
| `LOGIN_MAX_TRACKED` | `8192` | Max distinct (IP, user) keys tracked in memory. |
| `TRUSTED_PROXY_HOPS` | `0` | Number of trusted proxy hops for real client IP (ProxyFix). `0` = do not trust `X-Forwarded-For`. |

See [DEPLOYMENT.md](DEPLOYMENT.md) and [`../deployments/`](../deployments/) for
per-target values and AWS Commercial/GovCloud + Azure Commercial/Government
splits.

---

## 6. Request & Error Contract

- All application traffic is JSON REST under `/api/*`.
- Successful reads return the row/object; **creates return `201`** with the row.
- Errors are JSON `{"error":"<code>", ...}` with a matching HTTP status.
  Authorization failures also carry `"need": "<action>"`.

### Error / status code table

| Code | HTTP | Meaning |
| --- | --- | --- |
| `no_database` | 503 | No database configured/reachable. |
| `unauthorized` | 401 | No/invalid session. |
| `forbidden` (+ `need`) | 403 | Authenticated but lacks the required capability. |
| `csrf_failed` | 403 | Missing/mismatched CSRF token on a state-changing request. |
| `too_many_attempts` | 429 | Login throttle tripped (`Retry-After` header set). |
| `invalid_credentials` | 401 | Bad username/password. |
| `weak_credentials` / `weak_password` | 400 | Username `<3` / password `<8`. |
| `missing_credentials` | 400 | Username or password absent. |
| `already_initialized` | 403 | `/api/auth/bootstrap` after an admin exists. |
| `invalid_role` | 400 | Unknown role in user management. |
| `invalid_action` | 400 | Unknown capability/action requested. |
| `not_found` | 404 | Entity does not exist. |

Public endpoints (no auth): `/api/health`, `/api/auth/status`,
`/api/auth/login`, `/api/auth/bootstrap`. Everything else requires a session.

---

## 7. Offline ⇄ Online Sync Design

AeroMarkup reconciles multiple field devices against one shared database using
two mechanisms:

1. **`client_uid` idempotency.** Every client-created record carries a
   client-generated `client_uid`. Server write endpoints are **upserts**
   (`INSERT ... ON CONFLICT (client_uid) DO UPDATE / DO NOTHING`), so a device
   can safely replay the same queued mutation any number of times — a duplicate
   push converges to the same row instead of creating a duplicate. For
   idempotent-replay conflicts the server returns the existing row.

2. **`sync_log` change journal + cursor.** Consequential mutations append to the
   `sync_log` table (monotonic `seq`). A device calls `POST /api/sync` with the
   drawing scope and `since = <last seq this device has seen>`. The server:
   - accepts the device's pushed changes (idempotent via `client_uid`), then
   - **pulls back** changes authored by *other* devices with `seq > since`, and
   - returns `{ "ok": true, "cursor": <max seq>, "changes": [...] }`.

   The device persists `cursor` locally and passes it as `since` next time,
   giving an incremental, resumable delta pull. Because IndexedDB is
   authoritative locally, a device that was offline for hours simply replays its
   queue and pulls peers' deltas on reconnect with no data loss.

```
Field edits (offline) ──► IndexedDB queue ──► POST /api/sync (push)
                                                   │
                              ON CONFLICT(client_uid) upsert  ─► Postgres + sync_log(seq++)
                                                   │
Peers' changes (seq > since) ◄── pull back ◄───────┘
```

---

## 8. Security Model (summary)

- **AuthN:** local `users` table, werkzeug password hashing; first-run
  bootstrap admin only while no password is set. Sessions are **stateless
  signed tokens** in an **HttpOnly** `am_session` cookie (not readable by JS,
  not exfiltratable via XSS).
- **CSRF:** double-submit — JS-readable `am_csrf` cookie + `X-CSRF-Token` header
  compared with `secrets.compare_digest` on all `POST/PUT/PATCH/DELETE`.
- **AuthZ:** RBAC capability matrix (`CAP`) with `@requires(action)` +
  server-bound identity for e-signatures/NCR/inspection.
- **Brute-force throttle:** per-process sliding window on failed logins.
- **Audit:** immutable, transactional `audit_log`.

Full detail — including the role/capability matrix, FIPS readiness, and
operator responsibilities — is in [SECURITY.md](SECURITY.md).

---

## 9. Observability

- **Logs:** gunicorn/Flask log to **stdout**, collected by the platform
  (CloudWatch `awslogs` / Azure Log Analytics / Render logs).
- **Health:** `GET /api/health` reports DB connectivity — use it for
  load-balancer / container health checks and post-restore verification.
- **Gap:** there is **no metrics or tracing endpoint today** (no
  Prometheus/OTel export). Operators should rely on log-based alerting and the
  health probe; a metrics/traces surface is a known future enhancement.

---

## 10. Deployment Topology

```
                        TLS (edge)
   ┌───────────────┐   ┌──────────────────────────┐   ┌───────────────────────┐
   │  Browser PWA  │   │        Flask API         │   │   PostgreSQL 13+      │
   │  (IndexedDB - │◄─►│  gunicorn · 2 workers    │◄─►│  schema: aeromarkup   │
   │  authoritative│   │  stateless · N replicas  │   │  search_path=         │
   │  offline)     │   │  /api/* JSON + /api/sync  │   │   aeromarkup,public   │
   │  service      │   │                          │   │  pgcrypto, triggers   │
   │  worker cache │   │  ProxyFix (TRUSTED_PROXY) │   │  sslmode=require (gov)│
   └───────────────┘   └──────────────────────────┘   └───────────────────────┘
        │  offline edits queue → sync on reconnect          data URLs = uploads
        └───────────────────────────────────────────────────────────────────►
```

- Multiple **stateless** app replicas sit behind a load balancer; any replica
  serves any request.
- `AEROMARKUP_SECRET` **must match across replicas** (else sessions fail across
  pods). The login throttle is **per-process**, so for multi-replica deployments
  also enforce rate limiting at the gateway/WAF.
- Uploads (reference images + STL/OBJ) are stored as **data URLs inside
  Postgres** — there is **no separate object storage**, so a database backup
  captures all uploaded content.

Per-target specifics: [DEPLOYMENT.md](DEPLOYMENT.md) and
[`../deployments/`](../deployments/) (`LOCAL_DEVELOPMENT.md`,
`SINGLE_LINUX_SERVER.md`, `KUBERNETES.md`, `AZURE.md`, `AWS.md`, `AIRGAPPED.md`).
