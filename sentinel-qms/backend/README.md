# Sentinel QMS — Backend API

Enterprise Quality Management System for aerospace, manufacturing, and U.S. DoD
programs. Standards-aligned with **AS9100D / ISO 9001 / 21 CFR Part 11** and
designed for deployment to **AWS GovCloud** and **Azure Government**.

- **Stack:** Python 3.12 · FastAPI · SQLAlchemy 2.0 · Pydantic v2 · Alembic · PostgreSQL 16
- **API base path:** `/api/v1` · **Port:** `8000`
- **Auth:** JWT (HS256) access + refresh tokens; pluggable OIDC/SAML + CAC/PIV (stubbed)

## Modules

Document Control · Nonconformance (NCR/MRB) · CAPA (8D) · Audits · Supplier
Quality (SCAR/ASL/scorecards) · Calibration · Training & Competency ·
Engineering Change (ECN/ECO) · Risk Register (RPN) · Inspection & First Article
(AS9102) · Management Review · Customer Complaints (RMA) · Dashboard KPIs.

## Quick start (local)

```bash
cd backend
python -m venv .venv && source .venv/bin/activate
pip install -e ".[dev]"

cp .env.example .env            # then edit secrets

# Option A — create schema + demo data directly (dev/test):
python -m app.seed

# Option B — manage schema with Alembic (recommended):
alembic upgrade head
python -m app.seed             # seeds roles/admin/demo idempotently

uvicorn app.main:app --reload --port 8000
```

Open the interactive docs at <http://localhost:8000/docs>.

Default dev admin (only when `ADMIN_AUTO_CREATE=true` and not production):
`admin@sentinel-qms.local` / `ChangeMe!Admin123`.

## Environment variables

See [`.env.example`](./.env.example). Key variables:

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | `postgresql+psycopg://user:pass@host:5432/sentinel_qms` |
| `JWT_SECRET` / `JWT_ALGORITHM` | Token signing (HS256) |
| `ACCESS_TOKEN_EXPIRE_MINUTES` / `REFRESH_TOKEN_EXPIRE_DAYS` | Token lifetimes |
| `CORS_ORIGINS` | Comma-separated allowed origins |
| `STORAGE_BACKEND` | `s3` \| `azure_blob` \| `local` |
| `S3_BUCKET` / `S3_REGION` | AWS GovCloud S3 (e.g. `us-gov-west-1`) |
| `AZURE_STORAGE_CONNECTION_STRING` / `AZURE_STORAGE_CONTAINER` | Azure Gov Blob |
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | Federal SSO (stub) |

## Database migrations (Alembic)

```bash
alembic upgrade head                       # apply all migrations
alembic revision -m "describe change"      # create a new revision
alembic downgrade -1                       # roll back one revision
```

The initial migration (`alembic/versions/0001_initial.py`) builds the full
schema from the ORM metadata so it stays in lockstep with the models.

## Tests

```bash
pytest                 # runs against in-memory SQLite
pytest --cov=app       # with coverage
```

## Production

```bash
docker build -t sentinel-qms-api .
docker run -p 8000:8000 --env-file .env sentinel-qms-api
```

The image runs Gunicorn with Uvicorn workers as a non-root user and ships a
container `HEALTHCHECK` hitting `/health`.

## Compliance highlights

- **Audit trail:** every state-changing endpoint writes an immutable `AuditLog`
  entry (actor, action, before/after snapshot, IP, request id).
- **Electronic signatures (21 CFR Part 11):** approvals, dispositions, change
  approvals, and CAPA close-out capture signer + meaning + reason + timestamp,
  with optional password re-authentication and a tamper-evident hash.
- **Soft delete:** controlled records (documents, NCR, CAPA, audits, suppliers,
  risks, complaints) are never hard-deleted.
- **Security headers:** HSTS (prod), `X-Content-Type-Options`, `X-Frame-Options`,
  and a CSP tuned for API + docs.
