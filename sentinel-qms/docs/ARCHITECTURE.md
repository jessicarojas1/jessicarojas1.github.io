# Sentinel QMS — Architecture

Canonical architecture reference for Sentinel QMS. This is the single-page
overview; the deep-dive documents live under [`architecture/`](architecture/)
and are linked inline.

- [`architecture/overview.md`](architecture/overview.md) — C4 context/container view
- [`architecture/architecture-diagram.md`](architecture/architecture-diagram.md) — diagrams
- [`architecture/data-model.md`](architecture/data-model.md) — relational model
- [`architecture/api-reference.md`](architecture/api-reference.md) — endpoint reference
- [`architecture/security-architecture.md`](architecture/security-architecture.md) — security internals
- [`architecture/PERMISSIONS_MODEL.md`](architecture/PERMISSIONS_MODEL.md) — RBAC / granular permissions

Related: [`DEPLOYMENT.md`](DEPLOYMENT.md) · [`SECURITY.md`](SECURITY.md) ·
[`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md) · [`../README.md`](../README.md)

---

## 1. Platform

Sentinel QMS is a **three-tier web application** engineered to store, process,
and transmit **Controlled Unclassified Information (CUI)** inside a U.S.
government cloud boundary (AWS GovCloud / Azure Government).

| Tier | Technology | Runtime |
|------|------------|---------|
| **Frontend** | React 18 + TypeScript, Vite, TanStack Query, react-hook-form + zod, Recharts, react-router | nginx (`:8080`) or served by the API in single-service mode |
| **Backend** | Python 3.12, FastAPI, SQLAlchemy 2.0, Pydantic v2, Alembic | gunicorn + Uvicorn workers (`:8000`) |
| **Data** | PostgreSQL 16 (JSONB, native enums); object storage (AWS S3 / Azure Blob / local) | Managed RDS / Azure DB for PostgreSQL |

The app can run in two shapes:

1. **Two-service** — nginx serves the built SPA and reverse-proxies `/api/v1`
   to the FastAPI service. This is the compose / Kubernetes / cloud topology.
2. **Single-service** — one container where FastAPI serves **both** the API and
   the built SPA from the same origin (`SERVE_FRONTEND=1`, `STATIC_DIR=/app/static`).
   No CORS. This is the [root `Dockerfile`](../Dockerfile) and the Render demo.

---

## 2. Design principles

- **Secure by default** — production refuses to boot with a weak `JWT_SECRET`
  (`< 32` chars or the dev default); admin auto-seed is off unless explicitly
  opted in. See `app/core/config.py::_guard_production_secrets`.
- **Immutable audit trail** — every state-changing mutation is journaled
  (who / what / when / before / after); controlled records are soft-deleted, never
  hard-deleted. See `app/core/audit.py`.
- **Fail closed** — federation (OIDC/SAML/CAC-PIV), rate limiting, and login
  throttling degrade to a safe state rather than a permissive one.
- **Parameterized data access only** — all queries go through SQLAlchemy 2.0;
  no string-concatenated SQL.
- **Government-cloud portable** — one image, config-only differences between
  AWS Commercial, AWS GovCloud, Azure Commercial, and Azure Government.
- **Pluggable at the edges** — storage backend, identity provider, notification
  channels, and rate-limit store are all swappable by configuration.

---

## 3. Component overview

```
                       ┌──────────────────────────────────────────┐
        Browser  ──►   │  React + TypeScript SPA (nginx :8080)     │
                       │  CUI banner · RBAC nav · e-sign · charts  │
                       └───────────────────┬──────────────────────┘
                                           │  HTTPS  /api/v1
                       ┌───────────────────▼──────────────────────┐
                       │  FastAPI (Python 3.12) — REST API :8000   │
                       │  Middleware: SecurityHeaders · RateLimit  │
                       │  · RequestContext · CORS                  │
                       │  JWT/OIDC/SAML/CAC-PIV · RBAC · audit log │
                       │  · e-signatures · record numbering · SLA  │
                       │  scheduler · webhooks · notifications     │
                       └───────────────────┬──────────────────────┘
                                           │  SQLAlchemy 2.0 (parameterized)
                       ┌───────────────────▼──────────────────────┐
                       │  PostgreSQL 16   ·   Object storage       │
                       │  (SSE-KMS S3 / CMK Azure Blob / local)    │
                       │  Secrets Manager / Key Vault              │
                       └──────────────────────────────────────────┘
```

### Backend internals (`backend/app/`)

| Package | Responsibility |
|---------|----------------|
| `main.py` | App factory; middleware stack; `/health`; SPA mount; lifespan (scheduler start/stop) |
| `api/routers/` | ~50 module routers (documents, nonconformances, capa, audits, suppliers, calibration, training, changes, risks, inspections, mgmt_reviews, complaints, fmea, spc, apqp, msa, fod, counterfeit, concessions, iam, auth, …) |
| `api/deps.py` | Request dependencies (current user, DB session, permission checks) |
| `core/` | `config` (settings), `database` (engine/session), `security` (JWT/hashing), `rbac`/`permissions`/`iam`/`entity_access` (authz), `audit`, `middleware`, `exceptions`, `logging`, `net_guard`, `pages` |
| `models/` | SQLAlchemy ORM models per module + `user`, `permission`, `refresh_token`, `token_denylist`, `password_reset`, `settings`, `sla`, `webhook`, `record_share` |
| `schemas/` | Pydantic v2 request/response models |
| `services/` | `crud`, `workflow`, `numbering`, `signatures`, `storage`, `oidc`/`saml`/`cac`/`mfa`, `refresh_tokens`, `scheduler`, `sla`, `delivery`/`notifications`, `webhooks`, `kpi`, `spc`, `pdf`, `report_digest`, `csv_import`, `capa_factory` |
| `seed.py` | Idempotent reference-data + optional bootstrap-admin seeding |

### Frontend internals (`frontend/src/`)

React SPA (TanStack Query for server state, react-hook-form + zod for forms,
Recharts for KPI dashboards, react-router for routing). Talks to the API via
axios at `VITE_API_BASE_URL` (defaults to `/api/v1` same-origin).

---

## 4. Monorepo placement & internal layout

Sentinel QMS lives at `sentinel-qms/` inside the portfolio monorepo. It is a
**standalone** application (own Dockerfile, own render.yaml, own IaC) and can be
extracted to its own repo via `scripts/extract-to-standalone-repo.sh`.

```
sentinel-qms/
├── Dockerfile              single-service image (SPA + API, non-root, healthcheck)
├── render.yaml             Render Blueprint (demo: 1 web service + Postgres)
├── Makefile                dev convenience targets (up/down/migrate/seed/test/lint)
├── README.md · SECURITY.md · LICENSE · OPEN_ITEMS.md · CLAUDE.md
├── backend/                FastAPI API, SQLAlchemy models, Alembic migrations, tests
│   ├── app/                application code (see table above)
│   ├── alembic/            migrations (0001..0009)
│   ├── gunicorn.conf.py    worker/bind/timeout config
│   ├── docker-entrypoint.sh  migrate → seed → exec server
│   ├── pyproject.toml      dependencies + ruff/pytest config
│   └── .env.example        full config reference
├── frontend/               React + TypeScript SPA (Vite), nginx configs
├── infra/                  docker-compose, Terraform (aws-govcloud, azure-gov),
│                           Kubernetes base + Helm chart + overlays
├── docs/                   this documentation set
│   ├── ARCHITECTURE.md · DEPLOYMENT.md · SECURITY.md · DISASTER_RECOVERY.md
│   ├── architecture/  compliance/  deployment/  user-guide/
│   └── CHANGELOG.md · KNOWN_LIMITATIONS.md · CONTRIBUTING.md
└── deployments/            per-target operator guides (LOCAL, SINGLE_LINUX_SERVER,
                            KUBERNETES, AWS, AZURE, AIRGAPPED)
```

---

## 5. Configuration model

Configuration is **environment-driven** via Pydantic Settings
(`app/core/config.py`), loaded from process env or a `.env` file. Full reference:
[`../backend/.env.example`](../backend/.env.example) and
[`deployment/configuration-reference.md`](deployment/configuration-reference.md).

Key variables:

| Variable | Example | Purpose |
|----------|---------|---------|
| `ENVIRONMENT` | `production` | `development` \| `production`; production enables HSTS + secret guard |
| `DATABASE_URL` | `postgresql+psycopg://user:pw@host:5432/db` | Postgres DSN (bare `postgres://` auto-normalized to `+psycopg`) |
| `DB_SCHEMA` | `sentinel_qms` | Dedicated schema isolating Sentinel tables (`public` to use default) |
| `JWT_SECRET` | *(32+ char random)* | Signing key; production refuses weak/default values |
| `LOG_LEVEL` | `INFO` | `DEBUG`\|`INFO`\|`WARNING`\|`ERROR` |
| `STORAGE_BACKEND` | `s3` | `s3` \| `azure_blob` \| `local` |
| `AUTO_MIGRATE` | `1` | Run `alembic upgrade head` at entrypoint (set `0` to migrate via a Job) |
| `AUTO_SEED` | `1` | Seed reference data at entrypoint (non-fatal) |
| `WEB_CONCURRENCY` | `4` | gunicorn worker count |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | *(secret)* | Bootstrap admin; only used when `ADMIN_AUTO_CREATE=true` |

Validation guards worth noting: `DB_SCHEMA` must be a plain identifier (it is
interpolated into DDL); `OIDC_GROUP_ROLE_MAP` must be valid JSON;
`DATABASE_URL` scheme is normalized to the psycopg v3 driver.

---

## 6. Request & error contract

**Routing.** All application endpoints are mounted under `API_V1_PREFIX`
(`/api/v1`). System endpoints: `GET /health` (liveness/readiness), `GET /docs`
(Swagger UI), `GET /redoc`, `GET /api/v1/openapi.json`. In single-service mode
every non-API path falls back to `index.html` (SPA client-side routing); unknown
`/api/...` paths return a JSON 404, not the SPA shell.

**Health envelope** (`GET /health`):

```json
{
  "status": "ok",
  "version": "1.0.0",
  "environment": "production",
  "database": { "connected": true }
}
```

`status` is `"degraded"` and `database.connected` is `false` (with an `error`
field) when the DB probe fails — the endpoint still returns `200`.

**Error envelope.** All handled errors return a consistent problem body
(`app/core/exceptions.py`):

```json
{
  "error": {
    "code": "not_found",
    "message": "…",
    "request_id": "…"
  }
}
```

Validation errors add a `details` array. Error codes → HTTP status:

| Code | HTTP | Raised by |
|------|------|-----------|
| `app_error` | 400 | `AppError` base |
| `authentication_failed` | 401 | `AuthenticationError` |
| `permission_denied` | 403 | `PermissionDeniedError` (RBAC) |
| `not_found` | 404 | `NotFoundError` |
| `conflict` / `invalid_state_transition` | 409 | `ConflictError` / `WorkflowError` |
| `integrity_error` | 409 | DB uniqueness/FK violation |
| `validation_error` | 422 | `RequestValidationError` / `ValidationAppError` |
| `rate_limited` | 429 | `RateLimitMiddleware` |
| `database_error` | 500 | unhandled `SQLAlchemyError` |
| `internal_error` | 500 | any other unhandled exception |

Every response carries an `X-Request-ID` header (also echoed in the error body's
`request_id`) for log correlation.

---

## 7. Security model

Summary; full detail in [`SECURITY.md`](SECURITY.md) and
[`architecture/security-architecture.md`](architecture/security-architecture.md).

- **Authentication** — local password (bcrypt via passlib) issuing short-lived
  access JWTs + server-tracked, rotating refresh tokens; optional federation via
  **OIDC** (JWKS-verified ID tokens), **SAML 2.0** (signxml-verified assertions,
  SXW-mitigated), and **CAC/PIV** (mTLS terminated at a trusted proxy). Federation
  fails closed when unconfigured. Optional **MFA** and revocable **Personal
  Access Tokens**.
- **Authorization** — RBAC with granular module × action permissions
  (`core/rbac.py`, `core/permissions.py`, `core/iam.py`), plus per-record access
  (`core/entity_access.py`, `record_share`).
- **Login throttling** — audit-log-based (`LOGIN_MAX_FAILURES` /
  `LOGIN_FAILURE_WINDOW_MINUTES`), works across replicas.
- **Transport & headers** — `SecurityHeadersMiddleware` sets security headers
  (HSTS in production); databases in private subnets; default-deny NetworkPolicies.
- **Data protection** — TLS in transit; SSE-KMS (S3) / CMK (Azure Blob) at rest;
  secrets from Secrets Manager / Key Vault, never committed.
- **Electronic signatures** — 21 CFR Part 11-style signing (meaning, signer,
  timestamp, re-authentication) on dispositions/approvals.

---

## 8. Observability

| Signal | How |
|--------|-----|
| **Logs** | Structured JSON to stdout/stderr (`app/core/logging.py`, python-json-logger); gunicorn access/error logs to container stdout; every request tagged with `X-Request-ID` |
| **Health** | `GET /health` reports status, version, environment, DB connectivity; wired to the container `HEALTHCHECK` and to Kubernetes liveness/readiness probes |
| **Audit trail** | Append-only audit log queryable via `/api/v1/audit-logs` (admin) — the compliance-grade record of every mutation |
| **Metrics/traces** | Not built in; front with a platform collector (CloudWatch / Azure Monitor / Prometheus scrape of the gateway). Tracked in [`../OPEN_ITEMS.md`](../OPEN_ITEMS.md) |

---

## 9. Deployment topology

| Model | Shape | Guide |
|-------|-------|-------|
| Local dev | docker-compose: postgres + backend + frontend + MinIO | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | one VM, docker-compose/systemd, nginx/TLS | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | Helm/Kustomize, ingress, HPA, PDB, probes | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| AWS (Commercial + GovCloud) | ECS/EKS + RDS + S3 + Secrets Manager + IRSA/KMS | [`../deployments/AWS.md`](../deployments/AWS.md) |
| Azure (Commercial + Gov) | AKS/App Service + Azure DB for PostgreSQL + Blob + Key Vault + Managed Identity | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| Air-gapped | private registry mirror, offline bundles, self-hosted LLM (Ollama) | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |
| Render (demo) | single web service + Postgres via `render.yaml` | [`deployment/render-demo.md`](deployment/render-demo.md) |

See [`DEPLOYMENT.md`](DEPLOYMENT.md) for the operator-grade deployment guide.
