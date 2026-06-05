# Deploying the Sentinel QMS demo to Render

This is the **fast path for a live demo**. It deploys the same Docker images you
would run in production, but on Render's free plans with demo-friendly settings.

> ⚠️ **Demo only — not for CUI / ITAR / production.** This profile runs the API
> in `development` mode (so a login is seeded), uses ephemeral local file
> storage, and ships a known demo admin password. For real workloads deploy to
> **AWS GovCloud** or **Azure Government** with the Terraform in `infra/` — see
> [`deployment-guide.md`](deployment-guide.md).

## What gets created

| Resource | Render type | Notes |
|----------|-------------|-------|
| `sentinel-qms-db` | PostgreSQL (free) | Free Postgres expires ~30 days; upgrade for anything lasting |
| `sentinel-qms-api` | Web service, Docker | FastAPI; runs migrations + seed on boot |
| `sentinel-qms-web` | Web service, Docker | React SPA on nginx, listens on Render's `$PORT` |

The blueprint lives at the **repository root** as [`render.yaml`](../../render.yaml).

---

## Option A — Blueprint deploy (recommended)

Render Blueprints expect `render.yaml` at the **root** of the connected repo.
The cleanest way to get that is to extract Sentinel QMS into its own repo first:

```bash
# from the portfolio repo root, on the branch that has sentinel-qms/
gh auth login                                   # once
./sentinel-qms/scripts/extract-to-standalone-repo.sh
```

Then in Render:

1. **Dashboard → New → Blueprint.**
2. Connect the `sentinel-qms` repository.
3. Render reads `render.yaml` and shows the database + two web services. Click
   **Apply**.
4. Wait for all three to go live (first build pulls images and runs migrations).

> If the service names `sentinel-qms-api` / `sentinel-qms-web` are already taken
> globally, Render appends a random suffix and your real URLs will differ. If so,
> update three values in the dashboard and redeploy the affected service:
> - **API service** → `CORS_ORIGINS` = your real **web** URL
> - **Web service** → `VITE_API_BASE_URL` = your real **API** URL + `/api/v1`
> - **Web service** → `API_ORIGIN` = your real **API** URL
> (Changing `VITE_API_BASE_URL` requires a rebuild because it is baked into the SPA.)

### Deploying straight from the monorepo (without extracting)

Render won't auto-find `render.yaml` in a subdirectory. Either point the Blueprint
at the file path `sentinel-qms/render.yaml`, or create the two services manually
(Option B) with `rootDir` set to `sentinel-qms/backend` and `sentinel-qms/frontend`.

---

## Option B — Manual services (no blueprint)

Create each resource by hand in the Render dashboard:

### 1. PostgreSQL
- **New → PostgreSQL**, name `sentinel-qms-db`, database `sentinel_qms`, user `sentinel`, free plan.
- Copy its **Internal Connection String**.

### 2. Backend (Docker)
- **New → Web Service** → connect repo → **Runtime: Docker**.
- **Root Directory:** `backend` (or `sentinel-qms/backend` from the monorepo).
- **Dockerfile Path:** `./Dockerfile`.
- **Health Check Path:** `/health`.
- **Environment variables:**

  | Key | Value |
  |-----|-------|
  | `DATABASE_URL` | *(paste the internal connection string)* |
  | `JWT_SECRET` | *(generate a 32+ char random value)* |
  | `ENVIRONMENT` | `development` |
  | `CORS_ORIGINS` | `https://sentinel-qms-web.onrender.com` |
  | `STORAGE_BACKEND` | `local` |
  | `ADMIN_EMAIL` | `admin@sentinel-qms.local` |
  | `ADMIN_PASSWORD` | *(your demo password)* |

  The container applies Alembic migrations and seeds roles + demo data on start
  (`AUTO_MIGRATE` / `AUTO_SEED` default to on). `DATABASE_URL` with a bare
  `postgres://` scheme is normalized automatically.

### 3. Frontend (Docker)
- **New → Web Service** → same repo → **Runtime: Docker**.
- **Root Directory:** `frontend` (or `sentinel-qms/frontend`).
- **Dockerfile Path:** `./Dockerfile.render`.
- **Health Check Path:** `/healthz`.
- **Environment variables:**

  | Key | Value |
  |-----|-------|
  | `VITE_API_BASE_URL` | `https://sentinel-qms-api.onrender.com/api/v1` |
  | `API_ORIGIN` | `https://sentinel-qms-api.onrender.com` |

  (`VITE_API_BASE_URL` is baked in at build time; `API_ORIGIN` is added to the
  Content-Security-Policy `connect-src` at container start.)

---

## Log in

Once `sentinel-qms-web` is live, open its URL and sign in:

- **Email:** `admin@sentinel-qms.local`
- **Password:** the `ADMIN_PASSWORD` you set (`Demo!Sentinel2026` if you kept the blueprint default)

**Change the admin password immediately** if the link is shared.

---

## How it works on Render

- **Ports** — Render injects `$PORT`. Gunicorn binds `0.0.0.0:$PORT`; the
  frontend's nginx config is templated to `listen ${PORT}` at container start.
- **Cross-origin** — Render gives each service its own URL, so the SPA calls the
  API's public origin directly. CORS is allowed on the API (`CORS_ORIGINS`) and
  the API origin is whitelisted in the SPA's CSP (`API_ORIGIN`).
- **Database scheme** — Render's `postgres://` URL is normalized to
  `postgresql+psycopg://` in `app/core/config.py`.
- **Migrations/seed** — handled by the backend container entrypoint.

## Limitations of the free demo

- Free web services **spin down when idle** → the first request after a pause is
  slow (cold start), and the API cold start also runs/repeats startup checks.
- Free PostgreSQL **expires** after ~30 days.
- `STORAGE_BACKEND=local` means uploaded files **do not persist** across deploys
  or restarts. Add a Render Disk (paid) or switch to S3/Azure Blob for durable
  uploads.
