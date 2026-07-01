# CLAUDE.md — Sentinel QMS

Project guidance for working on Sentinel QMS. This is a standalone app inside the
portfolio monorepo (`sentinel-qms/`); the repo-root `CLAUDE.md` rules also apply.

## What it is

An enterprise **Quality Management System (QMS)** for aerospace, manufacturing, and
U.S. defense suppliers operating under **AS9100D / ISO 9001**, **CMMC 2.0 Level 2**,
and **DFARS 252.204-7012 / NIST SP 800-171**. It handles **CUI** and is built to
deploy into **AWS GovCloud** / **Azure Government**. Real three-tier app with an
immutable audit trail and 21 CFR Part 11-style e-signatures — not a static demo.

## Stack

- **Backend** — Python 3.12, FastAPI, SQLAlchemy 2.0, Pydantic v2, Alembic;
  gunicorn + Uvicorn workers. API under `/api/v1`; health at `/health`.
- **Frontend** — React 18 + TypeScript, Vite, TanStack Query, react-hook-form + zod,
  Recharts, react-router.
- **Data** — PostgreSQL 16 (JSONB, native enums); object storage S3 / Azure Blob /
  local (`STORAGE_BACKEND`).
- **IaC** — Terraform (`infra/terraform/aws-govcloud`, `azure-gov`), Kubernetes base
  + Helm chart + overlays (`infra/kubernetes/`), docker-compose (`infra/`).

## Where things live

| Area | Path |
|------|------|
| App factory / middleware / health | `backend/app/main.py` |
| Config (env-driven settings) | `backend/app/core/config.py`, `backend/.env.example` |
| Routers (per module) | `backend/app/api/routers/` |
| Models / schemas / services | `backend/app/models/`, `schemas/`, `services/` |
| Authz (RBAC / permissions / IAM) | `backend/app/core/{rbac,permissions,iam,entity_access}.py` |
| Audit / errors / logging | `backend/app/core/{audit,exceptions,logging}.py` |
| Migrations | `backend/alembic/versions/` (`0001`–`0009`) |
| Entrypoint (migrate → seed → serve) | `backend/docker-entrypoint.sh` |
| Frontend | `frontend/src/` |
| Docs | `docs/` (see below) |
| Deploy targets | `deployments/` |

## Build / test / deploy

```bash
make up            # full local stack (postgres + backend + frontend + minio)
make migrate       # alembic upgrade head (in the backend container)
make seed          # python -m app.seed
make test          # backend pytest + frontend vitest
make lint          # ruff check + eslint
make fmt           # ruff format + prettier
make tf-aws-plan   # terraform plan (AWS GovCloud)
make tf-azure-plan # terraform plan (Azure Gov)
```

- **Single-service image** — root `Dockerfile` (FastAPI serves API + SPA, non-root,
  healthcheck). **Render** — `render.yaml` blueprint (demo).
- Migrations run automatically at container start (`AUTO_MIGRATE=1`); set `0` and
  run via a Job for scaled prod.

## Conventions & rules

- **Secure by default** — production refuses to boot with a weak `JWT_SECRET`;
  `ADMIN_AUTO_CREATE=false` for real deploys. Never commit secrets — only
  `.env.example` placeholders.
- **Parameterized SQL only** (SQLAlchemy 2.0); no string-concatenated queries.
- **Immutable audit trail** — journal every mutation; soft-delete controlled
  records (never hard-delete).
- **Authz everywhere** — every state-changing endpoint enforces RBAC / granular
  permissions; federation and rate limiting **fail closed**.
- **Consistent error envelope** — `{"error":{"code","message","request_id"}}` via
  `app/core/exceptions.py`; keep new errors mapped there.
- **Prefer workload identity** (IRSA / Managed Identity) over static cloud creds.
- Backend style: `ruff` (line length 100, py312 target). Keep tests green
  (`backend/tests/`, `frontend` vitest).
- CUI: never load real ITAR/EAR/CUI data into demo/dev; production only in an
  authorized Gov cloud boundary.

## Documentation set (keep updated)

This doc set is a standing requirement — update it whenever the app changes:

- `README.md` — overview, quick start, links to docs + deployments
- `docs/ARCHITECTURE.md` — canonical architecture (+ `docs/architecture/` deep dives)
- `docs/DEPLOYMENT.md` — deployment guide (models, config, migrations, worker,
  Ollama/GPU, production checklist)
- `docs/SECURITY.md` — security guide (root `SECURITY.md` is the short policy)
- `docs/DISASTER_RECOVERY.md` — backups, restore runbook, HA
- `docs/compliance/` — AS9100D / NIST 800-171 / CMMC L2 / DFARS / 21 CFR Part 11 maps
- `OPEN_ITEMS.md` — production-readiness gaps (mirror `docs/KNOWN_LIMITATIONS.md`)
- `deployments/` — per-target operator guides (owned separately; keep cross-links valid)

When you add a migration, update `database/schema` references and the docs. When you
add a config var, update `backend/.env.example` and the config tables in
`docs/DEPLOYMENT.md` + `docs/deployment/configuration-reference.md`.
