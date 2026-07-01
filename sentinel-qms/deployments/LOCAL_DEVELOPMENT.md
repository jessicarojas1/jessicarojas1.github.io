# Local Development Deployment — Sentinel QMS

> **Audience:** developers running Sentinel QMS on a laptop/workstation.
> **CUI notice:** a local stack is **not** an authorized CUI boundary. Never load
> real ITAR/EAR or CUI data into a laptop environment. Use synthetic/demo data
> only. For CUI/production see [`AWS.md`](AWS.md) (GovCloud) or [`AZURE.md`](AZURE.md)
> (Azure Government).

Sentinel QMS is a three-tier app: a **FastAPI** (Python 3.12) REST API at
`/api/v1`, a **PostgreSQL 16** database with an immutable audit trail, and a
**React + TypeScript (Vite)** SPA. Object uploads use a pluggable storage
backend (`local` disk, AWS **S3**, or Azure **Blob**); local dev uses **MinIO**
as an S3-compatible store.

Sibling guides: [`SINGLE_LINUX_SERVER.md`](SINGLE_LINUX_SERVER.md) ·
[`KUBERNETES.md`](KUBERNETES.md) · [`AWS.md`](AWS.md) · [`AZURE.md`](AZURE.md) ·
[`AIRGAPPED.md`](AIRGAPPED.md)

---

## 1. Deployment architecture

The canonical local path is **Docker Compose** (`infra/docker-compose.yml`),
which runs four/five containers on one bridge network:

| Container | Image | Role | Port (localhost) |
|-----------|-------|------|------------------|
| `sentinel-postgres` | `postgres:16-alpine` | Database `sentinel_qms` | `127.0.0.1:5432` |
| `sentinel-minio` | `minio/minio` | S3-compatible object store | `127.0.0.1:9000` (API), `:9001` (console) |
| `sentinel-minio-init` | `minio/mc` | One-shot: creates + versions the bucket | — |
| `sentinel-backend` | built from `backend/Dockerfile` | FastAPI API (gunicorn + uvicorn workers) | `127.0.0.1:8000` |
| `sentinel-frontend` | built from `frontend/Dockerfile` | React SPA via nginx, proxies `/api/v1` → backend | `127.0.0.1:8080` |

The backend entrypoint (`backend/docker-entrypoint.sh`) runs
`alembic upgrade head` (when `AUTO_MIGRATE=1`) then `python -m app.seed` (when
`AUTO_SEED=1`) **before** starting gunicorn. All ports bind to `127.0.0.1` only.

There is also a **single-service image** (root `Dockerfile`) where FastAPI serves
*both* the API and the built SPA on `:8000` (`SERVE_FRONTEND=1`) — used by
Render and handy for a one-container smoke test (see §7).

---

## 2. Topology

```
   Browser
     │  http://localhost:8080
     ▼
 ┌─────────────────────┐   /api/v1/*  (nginx proxy_pass)
 │ frontend (nginx)    │──────────────────────────────┐
 │  React SPA :8080    │                               │
 └─────────────────────┘                               ▼
                                       ┌──────────────────────────────┐
                                       │ backend  FastAPI :8000        │
                                       │  gunicorn + uvicorn workers   │
                                       │  /health  /api/v1/...         │
                                       └───────┬───────────────┬───────┘
                                 psycopg (5432)│               │ boto3 (S3 API)
                                               ▼               ▼
                                   ┌────────────────┐  ┌────────────────────┐
                                   │ postgres :5432 │  │ minio :9000        │
                                   │ sentinel_qms   │  │ bucket:            │
                                   │ (audit_logs …) │  │ sentinel-qms-uploads│
                                   └────────────────┘  └────────────────────┘
```

---

## 3. Prerequisites

| Tool | Version | Notes |
|------|---------|-------|
| Docker Engine | 24+ | with Compose v2 (`docker compose`) |
| Git | any recent | clone the repo |
| (native path) Python | 3.12 | only if running backend without Docker |
| (native path) Node.js | 20 LTS | only if running the SPA with `npm run dev` |
| RAM | ~4 GB free | postgres + minio + two app containers |

No cloud accounts or quotas are required for local development.

---

## 4. Identity & credentials

Local dev uses **static credentials by design** — they never leave `127.0.0.1`
and must never be reused elsewhere. There are no IAM roles locally; the S3 client
authenticates to MinIO with root creds.

| Credential | Source | Default (dev only) |
|-----------|--------|--------------------|
| Postgres user/password | `.env` (`POSTGRES_USER`/`POSTGRES_PASSWORD`) | `sentinel` / `change_me_local_only` |
| MinIO root user/password | `.env` (`MINIO_ROOT_USER`/`MINIO_ROOT_PASSWORD`) | `minioadmin` / `minioadmin_local_only` |
| JWT secret | `.env` (`JWT_SECRET`) | `local-dev-secret-not-for-production-min-32-chars` |
| Seeded admin | `ADMIN_EMAIL` / `ADMIN_PASSWORD` (only when `ENVIRONMENT=development`) | `admin@sentinel-qms.local` / `ChangeMe!Admin123` |

> The production secret guard (`app/core/config.py`) **refuses to boot** with the
> insecure JWT default or a secret `< 32` chars when `ENVIRONMENT=production`.
> Development mode relaxes that guard so these defaults work.

---

## 5. Environment variables

Copy the template and edit: `cd infra && cp .env.example .env`. Compose already
wires most of these; the table is the full set the backend reads.

| Variable | Example | Purpose |
|----------|---------|---------|
| `ENVIRONMENT` | `development` | Runtime mode. `development` enables seeded admin + relaxes secret guard; `production` hardens. |
| `LOG_LEVEL` | `INFO` | gunicorn/app log level. |
| `DATABASE_URL` | `postgresql+psycopg://sentinel:change_me_local_only@postgres:5432/sentinel_qms` | SQLAlchemy DSN (psycopg v3 driver auto-pinned). |
| `DB_SCHEMA` | `sentinel_qms` | Dedicated Postgres schema for all tables. Alphanumeric/underscore only. |
| `JWT_SECRET` | `local-dev-secret-not-for-production-min-32-chars` | HS256 signing secret (≥ 32 chars). |
| `JWT_ALGORITHM` | `HS256` | Access-token algorithm. |
| `CORS_ORIGINS` | `http://localhost:8080` | Allowed browser origins (comma-separated). |
| `STORAGE_BACKEND` | `s3` | `local` \| `s3` \| `azure_blob`. Compose uses `s3` against MinIO. |
| `S3_BUCKET` | `sentinel-qms-uploads` | Bucket the app writes uploads to. |
| `S3_REGION` | `us-gov-west-1` | Region string (any value for MinIO). |
| `S3_ENDPOINT_URL` | `http://minio:9000` | Custom S3 endpoint → path-style, no SSE-KMS (MinIO). |
| `AWS_ACCESS_KEY_ID` | `minioadmin` | MinIO access key (local only). |
| `AWS_SECRET_ACCESS_KEY` | `minioadmin_local_only` | MinIO secret key (local only). |
| `AWS_EC2_METADATA_DISABLED` | `true` | Stop boto3 probing the (absent) IMDS endpoint. |
| `AUTO_MIGRATE` | `1` | Run `alembic upgrade head` on boot. |
| `AUTO_SEED` | `1` | Seed roles + demo data on boot. |
| `ADMIN_AUTO_CREATE` | `true` | Bootstrap the admin from `ADMIN_EMAIL`/`ADMIN_PASSWORD`. |
| `ADMIN_EMAIL` | `admin@sentinel-qms.local` | Seeded admin login (dev). |
| `ADMIN_PASSWORD` | `ChangeMe!Admin123` | Seeded admin password (dev). |
| `WEB_CONCURRENCY` | `2` | gunicorn worker count. |

### Optional (SSO / notifications) — leave blank to disable

| Variable | Example | Purpose |
|----------|---------|---------|
| `OIDC_ISSUER` / `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | *(blank)* | OIDC SSO; empty = disabled (fails closed). |
| `SMTP_HOST` / `SMTP_PORT` | *(blank)* | Email dispatch for notifications/digests. |
| `RUN_SCHEDULER` | `true` | In-process SLA sweep + report digest scheduler. |

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` | Upload size cap (50 MB default). |
| `ACCESS_TOKEN_EXPIRE_MINUTES` | `30` | Access-token lifetime. |
| `REFRESH_TOKEN_EXPIRE_DAYS` | `7` | Refresh-cookie lifetime. |
| `RATE_LIMIT_PER_MINUTE` | `300` | Per-principal request budget. |
| `TRUST_PROXY_HEADERS` | `false` | Trust `X-Forwarded-For` (only behind a trusted proxy). |
| `VITE_API_BASE_URL` | `/api/v1` | Build-time SPA API base (frontend build arg). |

Allowed upload content types (enforced by magic-byte sniffing in
`app/services/storage.py`): PDF, PNG, JPEG, TIFF, CSV, TXT, DOC/DOCX, XLS/XLSX,
ZIP. A spoofed `Content-Type` is rejected.

---

## 7. Verification

### 7.1 Bring the stack up

```bash
cd sentinel-qms/infra
cp .env.example .env
docker compose up --build
```

Wait for `sentinel-backend` to log `[entrypoint] Migrations applied successfully.`
and gunicorn `Booting worker`.

### 7.2 Health endpoint

```bash
curl -fsS http://localhost:8000/health
# -> {"status":"ok", ...}   (HTTP 200)
```

### 7.3 Login works (secrets resolved)

The login endpoint is `POST /api/v1/auth/login`; `username` is the email.

```bash
curl -fsS -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@sentinel-qms.local","password":"ChangeMe!Admin123"}'
# -> {"access_token":"<JWT>","token_type":"bearer","expires_in":1800}
```

A valid `access_token` proves the DB is reachable, migrations/seed ran, and the
`JWT_SECRET` resolved. Capture it:

```bash
TOKEN=$(curl -fsS -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@sentinel-qms.local","password":"ChangeMe!Admin123"}' \
  | python -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')
```

### 7.4 File upload accepted + scanned + object written

Upload a small valid PDF (must be a real PDF — the server sniffs magic bytes):

```bash
printf '%%PDF-1.4\n%%EOF\n' > /tmp/test.pdf
curl -fsS -X POST http://localhost:8000/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/test.pdf;type=application/pdf'
# -> 201 with {"attachment":{...,"stored_key":"<uuid>.pdf",...}}
```

Confirm the **DB row** (attachment + audit trail):

```bash
docker exec -it sentinel-postgres psql -U sentinel -d sentinel_qms -c \
  "SET search_path TO sentinel_qms; \
   SELECT id, original_filename, stored_key, storage_backend, checksum_sha256 \
   FROM attachments ORDER BY id DESC LIMIT 1;"

docker exec -it sentinel-postgres psql -U sentinel -d sentinel_qms -c \
  "SET search_path TO sentinel_qms; \
   SELECT action, entity_type, actor_email, created_at \
   FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1;"
```

Confirm the **object written to storage** (MinIO/S3):

```bash
docker exec -it sentinel-minio sh -c \
  "mc alias set local http://localhost:9000 minioadmin minioadmin_local_only >/dev/null && \
   mc ls local/sentinel-qms-uploads"
# lists the randomized <uuid>.pdf object
```

### 7.5 One-container smoke test (optional)

```bash
docker build -t sentinel-qms:local .          # from repo root (single-service)
docker run --rm -p 8000:8000 \
  -e ENVIRONMENT=development -e AUTO_MIGRATE=1 -e AUTO_SEED=1 \
  -e ADMIN_AUTO_CREATE=true \
  -e ADMIN_EMAIL=admin@sentinel-qms.local -e ADMIN_PASSWORD='ChangeMe!Admin123' \
  -e DATABASE_URL='postgresql+psycopg://sentinel:pw@host.docker.internal:5432/sentinel_qms' \
  sentinel-qms:local
# SPA at http://localhost:8000/, API at http://localhost:8000/api/v1
```

---

## 8. Day-2 operations

| Task | Command |
|------|---------|
| Tail logs | `docker compose logs -f backend` |
| Apply a new migration | rebuild backend, restart — entrypoint runs `alembic upgrade head` |
| Manual migration | `docker exec -it sentinel-backend alembic upgrade head` |
| New empty migration | `docker exec -it sentinel-backend alembic revision -m "desc"` |
| Reset admin password | `docker exec -it sentinel-backend python -m app.reset_admin` |
| Reseed reference data | `docker exec -it sentinel-backend python -m app.seed` |
| DB backup | `docker exec sentinel-postgres pg_dump -U sentinel sentinel_qms > backup.sql` |
| Full wipe (data loss) | `docker compose down -v` (drops `pgdata` + `miniodata` volumes) |
| Rebuild after code change | `docker compose up --build backend` |

Native (no Docker) path: `pip install -e backend/[dev]`, run
`alembic upgrade head`, `uvicorn app.main:app --reload` (port 8000); frontend
`cd frontend && npm install && npm run dev`.

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Backend exits with `MIGRATION FAILED` | Postgres not ready / bad `DATABASE_URL` | Confirm `postgres` healthy; check the filtered reason in the entrypoint log. |
| `refusing to start with the insecure default` | `ENVIRONMENT=production` + weak `JWT_SECRET` | Set a ≥ 32-char `JWT_SECRET` or use `ENVIRONMENT=development` locally. |
| Login returns 401 | Admin not seeded | Ensure `ENVIRONMENT=development`, `ADMIN_AUTO_CREATE=true`, `ADMIN_EMAIL`/`ADMIN_PASSWORD` set; re-run `python -m app.seed`. |
| Upload 400 "contents do not match declared type" | File isn't a real allowed type | Upload a genuine PDF/PNG/etc.; the server sniffs magic bytes. |
| Upload 400 "content type not permitted" | Type not in allowlist | Use an allowed type (PDF, PNG, JPEG, TIFF, CSV, TXT, DOC/DOCX, XLS/XLSX, ZIP). |
| S3 errors / no object in bucket | `minio-init` didn't create the bucket | `docker compose up minio-init`; verify `S3_ENDPOINT_URL=http://minio:9000`. |
| SPA loads but API calls fail | nginx proxy or `VITE_API_BASE_URL` wrong | Confirm frontend built with `VITE_API_BASE_URL=/api/v1`; check `frontend/nginx.conf`. |
| Port already in use | 5432/8000/8080/9000 taken | Stop the conflicting service or edit the published port in `docker-compose.yml`. |
