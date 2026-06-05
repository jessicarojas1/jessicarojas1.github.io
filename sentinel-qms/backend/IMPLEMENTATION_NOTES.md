# Sentinel QMS Backend — Implementation Notes

This document summarizes what was built, the design decisions, and the
intentional stubs.

## What was built

A complete FastAPI backend for an enterprise QMS, structured as:

```
app/
  core/        config, database, security, rbac, audit, logging, exceptions, middleware
  models/      14 domain model files + base mixins (all imported in __init__)
  schemas/     Pydantic v2 Create/Update/Read/List variants per domain
  api/         deps + 16 routers aggregated under /api/v1
  services/    numbering, workflow, storage, notifications, kpi, signatures, crud
  seed.py      idempotent roles/admin/demo seeding
alembic/       env.py, script template, 0001_initial migration
tests/         conftest + auth, NCR, CAPA workflow, RBAC, dashboard suites
Dockerfile, gunicorn.conf.py, README.md, .env.example, pyproject.toml, alembic.ini
```

### Fully fleshed-out modules (real domain logic)

- **Auth** — OAuth2 password login (email as username), JWT access+refresh,
  `/me`, `/refresh`, audited login/logout, failed-login auditing.
- **Nonconformance (NCR/MRB)** — CRUD, sequential numbering (`NCR-YYYY-NNNN`),
  status state-machine, MRB disposition with the five disposition types
  (use-as-is/rework/repair/scrap/return) gated by a 21 CFR Part 11 e-signature.
- **CAPA (8D)** — full D1–D8 fields, action sub-records, an enforced 8D state
  machine (root cause required before action planning; corrective action before
  verification), effectiveness verification, and signed close-out that requires
  verified effectiveness.
- **Suppliers** — CRUD, SCARs, Approved Supplier List, and ratings with a
  computed composite score and A/B/C/D grade.
- **Calibration** — equipment CRUD, calibration records that roll the next-due
  date forward from the interval, auto out-of-service on a failed calibration,
  and a due/overdue query endpoint.
- **Dashboard** — KPI aggregation (open NCRs by severity, overdue CAPAs,
  calibration due/overdue, open audit findings by type, supplier averages,
  open complaints) via parameterized aggregate queries.
- **Documents** — revisions + approval workflow with e-signature, effectivity,
  and supersession of prior effective revisions.
- **Audits** — CRUD, findings (auto-numbered per audit), checklist items, and
  finding→CAPA linkage.

### Complete CRUD + RBAC + audit (DRY base)

Training, Change Orders (ECN/ECO with approval + e-signature), Risk register
(auto RPN + residual RPN), Inspections + AS9102 FAI with balloon
characteristics, Management Reviews (inputs + action items), Complaints/RMA, and
Attachments (upload/download with storage abstraction) — all share the helpers
in `app/services/crud.py`.

## Cross-cutting compliance

- **Audit log:** `app/core/audit.py` snapshots ORM rows (redacting secrets) and
  every state-changing endpoint calls `audit.record(...)` inside the same
  transaction as the change.
- **Electronic signatures:** `app/services/signatures.py` verifies the signer
  (optional password re-auth), stores meaning/reason/timestamp, and writes a
  SHA-256 binding hash. Used by NCR dispositions, document/change approvals, and
  CAPA close-out.
- **RBAC:** 7 roles with a permission matrix in `app/core/rbac.py`; routes use
  `require_permission(...)`/`require_roles(...)` dependencies.
- **Soft delete:** controlled records use `SoftDeleteMixin`; delete endpoints
  set the flag and audit it — no hard deletes.
- **Security headers + request context:** middleware adds HSTS (prod), nosniff,
  frame-deny, referrer-policy, a docs-aware CSP, and a per-request id surfaced
  in logs, the `X-Request-ID` header, and audit entries.
- **Health:** `/health` reports version, environment, and live DB connectivity.

## Numbering

`app/services/numbering.py` generates `PREFIX-YEAR-NNNN` by scanning the max
existing suffix for the prefix/year. Because controlled records are
soft-deleted (never removed), suffixes remain monotonic. Prefixes: NCR, CAPA,
SUP, SCAR, DOC, AUD, GAGE, ECN, RISK, INSP, FAI, MR, CMP.

## Storage abstraction

`app/services/storage.py` provides `LocalStorage`, `S3Storage` (AWS GovCloud,
SSE-KMS, presigned URLs), and `AzureBlobStorage` (Azure Gov). boto3/azure SDKs
are imported lazily so they're only required when that backend is selected.
Uploads enforce a content-type allowlist, size cap, randomized stored keys, and
a SHA-256 checksum.

## Migrations

`alembic/env.py` reads `DATABASE_URL` from settings and targets
`Base.metadata`. The initial migration builds the schema from the ORM metadata
(`create_all`/`drop_all`) so it is guaranteed to match the models on first
deploy; subsequent migrations should be authored as explicit diffs via
`alembic revision --autogenerate`.

## Tests

`tests/conftest.py` runs against in-memory SQLite with a `StaticPool`, seeds
roles and one user per relevant role, and overrides `get_db`. Suites cover auth
+ token refresh, NCR numbering/disposition/transitions/soft-delete, the full 8D
CAPA workflow + gating + signed close-out, RBAC enforcement, and dashboard KPIs.

## Intentional stubs / simplifications

- **OIDC/SAML/CAC-PIV verification** (`core/security.verify_oidc_token`) is a
  stub — the local HS256 JWT path is fully functional; the federal SSO verify
  raises until an issuer is configured so no false security is implied.
- **Email delivery** (`services/notifications._send_email_stub`) logs instead of
  sending; wire to SES / Azure Communication Services in deployment.
- **Token revocation:** logout is stateless (audited); a `jti`-based denylist can
  be layered on using the claim already present in issued tokens.
- **Azure SAS URLs:** Azure/local backends proxy downloads through the API
  rather than issuing presigned URLs (S3 issues presigned GETs).
- **DB portability:** models use PostgreSQL-friendly types; the test suite uses
  SQLite, where native `ENUM`/`JSON` degrade gracefully via SQLAlchemy. Run
  Alembic against PostgreSQL for production.

## Conventions honored

Python 3.12, FastAPI, SQLAlchemy 2.0 typed declarative, Pydantic v2, Alembic,
`/api/v1` base path, port 8000, DB `sentinel_qms`, JWT HS256 with the specified
env-var names, and the 7-role RBAC set.
