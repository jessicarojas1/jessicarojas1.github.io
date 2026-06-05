# Deploying the Sentinel QMS demo to Render

This is the **fast path for a live demo**. It deploys **one Docker web service**
where FastAPI serves both the `/api/v1` API and the React SPA (same origin — no
CORS, no cross-service URLs), plus a PostgreSQL database.

> ⚠️ **Demo only — not for CUI / ITAR / production.** This profile runs the API
> in `development` mode (so a login is seeded), uses ephemeral local file
> storage, and ships a known demo admin password. For real workloads deploy to
> **AWS GovCloud** or **Azure Government** with the Terraform in `infra/` — see
> [`deployment-guide.md`](deployment-guide.md).

## What gets created

| Resource | Render type | Notes |
|----------|-------------|-------|
| `sentinel-qms-db` | PostgreSQL (free) | Free Postgres expires ~30 days; upgrade for anything lasting |
| `sentinel-qms` | **Web service, Docker** | FastAPI serves the API **and** the SPA; runs migrations + seed on boot |

The combined image is built by [`Dockerfile`](../../Dockerfile) at the project
root; the Blueprint is [`render.yaml`](../../render.yaml).

---

## Option A — Blueprint deploy (recommended)

Render Blueprints expect `render.yaml` at the **root** of the connected repo, so
extract Sentinel QMS into its own repo first:

```bash
# from the portfolio repo root, on the branch that has sentinel-qms/
gh auth login                                   # once
./sentinel-qms/scripts/extract-to-standalone-repo.sh
```

Then in Render:

1. **Dashboard → New → Blueprint.**
2. Connect the `sentinel-qms` repository.
3. Render reads `render.yaml` and shows the database + one web service. Click **Apply**.
4. Wait for the build (it builds the SPA, builds the API, runs migrations + seed,
   then starts). First boot takes a few minutes.

That's it — open the web service URL and log in.

---

## Option B — Create the web service manually (no blueprint)

### 1. PostgreSQL
- **New → PostgreSQL**, name `sentinel-qms-db`, database `sentinel_qms`, user `sentinel`, free plan.
- Copy its **Internal Connection String**.

### 2. Web service (Docker)
- **New → Web Service** → connect repo → **Runtime: Docker**.
- **Dockerfile Path:** `./Dockerfile`
  - *(Deploying from the portfolio monorepo instead of the extracted repo? Set
    **Root Directory** to `sentinel-qms` so the build context includes
    `frontend/` and `backend/`.)*
- **Health Check Path:** `/health`
- **Environment variables:**

  | Key | Value |
  |-----|-------|
  | `DATABASE_URL` | *(paste the internal connection string)* |
  | `JWT_SECRET` | *(generate a 32+ char random value)* |
  | `ENVIRONMENT` | `development` |
  | `STORAGE_BACKEND` | `local` |
  | `ADMIN_EMAIL` | `admin@sentinel-qms.local` |
  | `ADMIN_PASSWORD` | *(your demo password)* |

The container applies Alembic migrations and seeds roles + demo data on start
(`AUTO_MIGRATE` / `AUTO_SEED` default to on). A `postgres://` `DATABASE_URL` is
normalized to `postgresql+psycopg://` automatically.

---

## Log in

Open the web service URL (e.g. `https://sentinel-qms.onrender.com`) and sign in:

- **Email:** `admin@sentinel-qms.local`
- **Password:** the `ADMIN_PASSWORD` you set (`Demo!Sentinel2026` if you kept the blueprint default)

API docs are at `…/docs`, health at `…/health`.
**Change the admin password immediately** if the link is shared.

---

## How it works on Render

- **One process, two roles** — FastAPI mounts the built SPA from `STATIC_DIR`
  (`SERVE_FRONTEND=1`), serving `index.html` with client-side-route fallback, and
  keeps `/api/v1`, `/docs`, `/health` working. Because the SPA is same-origin,
  there is no CORS and no API URL to configure.
- **Port** — Render injects `$PORT`; gunicorn binds `0.0.0.0:$PORT`.
- **Database scheme** — Render's `postgres://` URL is normalized to
  `postgresql+psycopg://` in `app/core/config.py`.
- **Migrations/seed** — handled by the container entrypoint before gunicorn starts.

## Limitations of the free demo

- Free web services **spin down when idle** → the first request after a pause is
  slow (cold start).
- Free PostgreSQL **expires** after ~30 days.
- `STORAGE_BACKEND=local` means uploaded files **do not persist** across deploys
  or restarts. Add a Render Disk (paid) or switch to S3/Azure Blob for durable
  uploads.

---

## Alternative — two separate services (API + nginx frontend)

If you prefer the API and frontend as **independent** services (closer to the
GovCloud/Azure topology), the repo also ships
[`frontend/Dockerfile.render`](../../frontend/Dockerfile.render) and a
port-flexible nginx template. In that model you run:

- an API web service (`backend/Dockerfile`, health `/health`) with
  `CORS_ORIGINS` set to the frontend URL, and
- a frontend web service (`frontend/Dockerfile.render`, health `/healthz`) with
  `VITE_API_BASE_URL` = `<api-url>/api/v1` and `API_ORIGIN` = `<api-url>`.

The single-service Blueprint above is simpler and is the recommended demo path.
