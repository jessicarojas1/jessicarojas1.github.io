# AeroMarkup ✈

![build](https://img.shields.io/badge/build-passing-brightgreen)
![python](https://img.shields.io/badge/python-3.12-blue)
![flask](https://img.shields.io/badge/Flask-3.x-000000)
![postgres](https://img.shields.io/badge/PostgreSQL-13%2B-336791)
![license](https://img.shields.io/badge/license-proprietary-lightgrey)
![deploy](https://img.shields.io/badge/deploy-Render%20%7C%20AWS%20GovCloud%20%7C%20Azure%20Gov-informational)

**Aerospace & manufacturing engineering-lifecycle platform** for DoD programs.
Redline engineering drawings and photos of airframes/parts, then run the work
through to disposition and approval — on a 2-in-1, iPad, Android tablet, or
phone, **with or without internet**.

> Deploys to **Render** today; the same container runs in **AWS GovCloud** and
> **Azure Government**. Offline-first PWA, RBAC, immutable audit trail, and CUI
> classification banners throughout.

### Why it exists

Field and shop-floor engineering work often happens where connectivity is poor
or forbidden (hangars, SCIFs, air-gapped labs). AeroMarkup lets engineers,
inspectors, and approvers mark up drawings, raise and disposition NCRs, perform
inspections, and apply electronic-signature approvals **fully offline**, then
reconcile to a shared PostgreSQL backend when a network is available — with an
immutable audit trail and CUI handling throughout.

### Documentation

- **Architecture:** [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- **Deployment guide:** [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- **Disaster recovery:** [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md)
- **Security:** [`docs/SECURITY.md`](docs/SECURITY.md)
- **Open items / production-readiness:** [`OPEN_ITEMS.md`](OPEN_ITEMS.md)

**Target-specific operator runbooks** live in [`deployments/`](deployments/):
[Local](deployments/LOCAL_DEVELOPMENT.md) ·
[Single Linux server](deployments/SINGLE_LINUX_SERVER.md) ·
[Kubernetes](deployments/KUBERNETES.md) ·
[AWS (Commercial + GovCloud)](deployments/AWS.md) ·
[Azure (Commercial + Government)](deployments/AZURE.md) ·
[Air-gapped](deployments/AIRGAPPED.md).

---

## Lifecycle modules

| Module | What it does |
|--------|--------------|
| **Dashboard** | Live KPIs — projects, drawings, open/critical NCRs, items in review — plus an activity feed |
| **Projects** | Programs, projects, tail/part/serial/work-order metadata, classification |
| **Drawing Editor** | Pressure-sensitive ink, shapes, **revision clouds**, text, notes, pins, **QA stamps**, and **FAI balloons**; **selection model** (move / edit / duplicate / delete / z-order / marquee); **layers** (visibility / lock / color); **grid + snap + ortho**; **linear / angle / area measurement** with scale calibration; status bar; keyboard shortcuts; **revision compare** (side-by-side + overlay diff); and **PDF redline report** |
| **AS9102 Characteristics** | Balloon a drawing and record each characteristic (zone, requirement, type, nominal, tolerance, actual, result); exported in the PDF report |
| **Command palette** | Ctrl/⌘ K to jump to any project, drawing, or section |
| **3D model viewer** | Import **STL / OBJ** models; orbit / pan / zoom (mouse + touch); drop **3D annotation pins** on the surface (ray-cast) with notes; wireframe; **Capture View → 2D** to mark up a snapshot with the full 2D engine. Self-contained WebGL — no external libraries (air-gap safe). Models + pins **sync across devices** via `/api/sync`. A sample airframe loads when no model is present. |

### Self-test
Open **`/test.html`** in a browser for a headless self-test of the 3D math
(matrix inversion, ray/triangle intersection), STL/OBJ parsers, and the WebGL
render pipeline — it prints a PASS/FAIL table. The same algorithms are verified
offline in CI-style Python ports.
| **Nonconformance (NCR)** | Raise, triage, and **disposition** defects (use-as-is / rework / repair / scrap / RTV) with severity + status workflow |
| **Inspections** | Quality inspection records (AS9100-style) with pass/fail items |
| **Approvals** | Review queue + **electronic signatures** (submit → approve → release), hashed and recorded |
| **Audit Trail** | Immutable, append-only record of every consequential action |
| **Administration** | Identity, **role-based access control** (viewer / engineer / inspector / approver / admin) |

### Engineering markup
Pressure-sensitive pen/highlighter/eraser, arrows/pointers, line/rect/ellipse,
draggable notes & pins, and **measurements** that report real-world units once
you calibrate the drawing scale against a known dimension. Input uses **Pointer
Events**, so mouse, touch, and stylus (Apple Pencil, Surface Pen, S Pen) all
work and pen pressure is captured where the hardware supports it. Pan/pinch-zoom
on touch; wheel-zoom on desktop. Export a flattened PNG redline.

### Security / DoD posture
- **CUI classification banners** (top + bottom, persisted, kept in print output).
- **RBAC** gates every lifecycle action; approvals require an **e-signature**.
- **Immutable audit trail** for traceability.
- **No third-party CDN/runtime calls** — fully self-hosted, usable air-gapped.

### Offline-first model
Every edit is written to the device's IndexedDB **first**, so the tool never
blocks without a network. When you're online and the backend is reachable,
**Sync** reconciles your local changes with the server (and pulls in changes
other devices made) using an idempotent, `client_uid`-keyed change journal
(`sync_log`). Conflicts can't duplicate records because every stroke/annotation
carries a stable client-generated id.

---

## Architecture

```
Browser PWA (static/, ES modules)     Flask API (server.py)        PostgreSQL — schema "aeromarkup"
┌──────────────────────────────┐  /api ┌──────────────────────┐ SQL ┌────────────────────────────┐
│ app · router · views          │ ◄───► │ /dashboard /projects  │◄──►│ programs, projects, drawings,│
│ canvas engine (markup/measure)│ sync  │ /drawings /sync       │    │ strokes, annotations, layers,│
│ store (IndexedDB) · session   │       │ /ncrs /inspections    │    │ ncrs, inspections, approvals,│
│ audit · service worker        │       │ /approvals /audit     │    │ comments, audit_log, sync_log│
└──────────────────────────────┘       └──────────────────────┘    └────────────────────────────┘
        offline-first, RBAC, CUI                  stateless                shared-DB safe (namespaced)
```

The frontend is a modular ES-module SPA (`static/js/`: `app`, `router`,
`store`, `session`, `audit`, `api`, `ui`, `icons`, `canvas`, `views`). It is
**IndexedDB-authoritative** so every module works fully offline; the server is
best-effort persistence + multi-device reconciliation.

The Flask process is **stateless** — all durable state is in Postgres and each
client's IndexedDB. That's what makes it portable across Render, ECS/Fargate,
and Azure Container Apps.

---

## Technology

| Layer | Technology |
|-------|------------|
| Backend | Python 3.12, **Flask 3.x**, served by **gunicorn** (2 workers) |
| Auth | `werkzeug` password hashing, `itsdangerous` signed session tokens, double-submit CSRF |
| Database | **PostgreSQL 13+** via `psycopg` 3, dedicated `aeromarkup` schema (shared-DB safe) |
| Frontend | Offline-first **PWA** — vanilla ES modules, IndexedDB, service worker, self-contained WebGL 3D viewer (no CDN) |
| Container | `python:3.12-slim`, non-root uid `10001`, `HEALTHCHECK` on `/api/health` |
| Targets | Render, AWS (Commercial + GovCloud), Azure (Commercial + Government), Kubernetes, single VM, air-gapped |

## Supported deployment models

| Model | Runbook |
|-------|---------|
| Managed PaaS (Render) | [`render.yaml`](render.yaml) + [Deploy](#deploy) |
| Local / laptop | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux VM (systemd / compose + nginx/TLS) | [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes (HPA/PDB, CSI/External Secrets) | [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) |
| AWS — ECS/Fargate or EKS (Commercial + GovCloud) | [`deployments/AWS.md`](deployments/AWS.md) |
| Azure — Container Apps or AKS (Commercial + Gov) | [`deployments/AZURE.md`](deployments/AZURE.md) |
| Air-gapped (offline registry/bundles, optional Ollama) | [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md) |

## Repository layout

```
aeromarkup/
├── server.py               # Flask app: REST + /api/sync, auth, RBAC, audit
├── preview_server.py       # static-only offline PWA preview (dev, :4173)
├── requirements.txt        # Python dependencies
├── Dockerfile              # non-root production image (gunicorn)
├── docker-compose.yml      # local app + Postgres stack
├── render.yaml             # Render Blueprint (web service + managed Postgres)
├── .env.example            # environment template (no secrets)
├── db/
│   ├── schema.sql          # idempotent full schema (authoritative)
│   └── seed.sql            # optional demo data
├── deploy/
│   ├── aws-govcloud/       # ECS Fargate task-definition.json + deploy.sh
│   └── azure-gov/          # Container Apps + App Gateway bicep + deploy.sh
├── static/                 # PWA: index.html, sw.js, manifest, css, js/*
│   └── js/                 # app, router, store, session, api, canvas, viewer3d, ...
├── deployments/            # per-target operator runbooks (6)
└── docs/                   # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
```

## Prerequisites

- **Python 3.12** (native runs) or **Docker** (container / compose).
- **PostgreSQL 13+** for online mode (optional — the PWA runs offline without it).
- **`psql`** client for manual migrations, backups, and verification.
- A TLS certificate for any internet-exposed deployment (see the runbooks).

## Quick start (local development)

```bash
cd aeromarkup

# A) Full stack (app + Postgres) via Docker — mirrors the cloud topology
export POSTGRES_PASSWORD='<a strong secret>'
docker compose up --build            # → http://localhost:8080

# B) Native backend
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
export ENVIRONMENT=development        # permits an ephemeral dev secret
export DATABASE_URL='postgres://aeromarkup:pass@localhost:5432/aeromarkup'
python server.py                      # → http://localhost:8080

# C) Static frontend only (offline mode, no backend)
python3 preview_server.py             # → http://localhost:4173
```

On first launch against an empty database, open the app and complete **first-run
setup** (creates the initial admin via `POST /api/auth/bootstrap`) — there is no
shipped default password.

## Common commands

```bash
# Apply / re-apply the schema (idempotent) and optional seed data
psql "$DATABASE_URL" -f db/schema.sql
psql "$DATABASE_URL" -f db/seed.sql

# Build and run the production image
docker build -t aeromarkup:latest .
docker run --rm -p 8080:8080 -e ENVIRONMENT=development aeromarkup:latest

# Health check
curl -s localhost:8080/api/health

# Generate a session signing secret for production
python3 -c "import secrets; print(secrets.token_urlsafe(48))"

# Deploy to AWS GovCloud (ECS/Fargate) / Azure Government (Container Apps)
./deploy/aws-govcloud/deploy.sh
./deploy/azure-gov/deploy.sh

# Backup / restore the database
pg_dump "$DATABASE_URL" > aeromarkup-$(date +%F).sql
psql "$DATABASE_URL" < aeromarkup-YYYY-MM-DD.sql
```

## Dependencies

**Python packages** (`requirements.txt`):

| Package | Constraint | Purpose |
|---------|-----------|---------|
| `Flask` | `>=3.0.0` | Web framework / routing |
| `Werkzeug` | `>=3.0.0` | Password hashing (`generate_password_hash`) |
| `itsdangerous` | `>=2.1.0` | Signed session tokens |
| `psycopg[binary]` | `>=3.1.0` | PostgreSQL driver (binary build) |
| `gunicorn` | `>=21.2.0` | Production WSGI server |

**PostgreSQL extension:** `pgcrypto` (for `gen_random_uuid()`) — created by
`db/schema.sql`; available on Render, RDS, and Azure DB.

**Frontend:** no build step and **no third-party runtime/CDN dependencies** —
plain ES modules, IndexedDB, a service worker, and a self-contained WebGL 3D
viewer (air-gap safe).

---

## Database

> **Shared-database safe.** All objects are created in a dedicated
> `aeromarkup` Postgres schema, and the app connects with
> `search_path = aeromarkup,public`. You can therefore point `DATABASE_URL`
> at a database shared with other apps (e.g. APEX) without colliding on
> common names like `users` or `projects`.

The full schema for **every table** is in [`db/schema.sql`](db/schema.sql)
(idempotent; PostgreSQL 13+). Tables (all under schema `aeromarkup`):

`users` · `programs` · `projects` · `drawings` · `layers` · `strokes` ·
`annotations` · `attachments` · `revisions` · `ncrs` · `inspections` ·
`inspection_items` · `approvals` · `comments` · `audit_log` · `sync_log`

Apply it manually, or let the app apply it on boot (`AUTO_MIGRATE=1`, default):

```bash
psql "$DATABASE_URL" -f db/schema.sql
psql "$DATABASE_URL" -f db/seed.sql      # optional demo data
```

---

## Deploy

### 1) Render (current target)

The blueprint provisions the web service **and** a managed Postgres, and wires
`DATABASE_URL` automatically.

1. Push this repo to GitHub.
2. Render → **New → Blueprint** → pick the repo. It reads
   [`render.yaml`](render.yaml).
3. Deploy. The schema is applied on first boot. Health check: `/api/health`.

No env vars to set by hand — `DATABASE_URL` comes from the blueprint database.

> **Production database note.** Render's **free PostgreSQL expires ~30 days**
> after creation. For anything beyond a short pilot, move to a paid Render
> Postgres or to a government-cloud managed database — the container is
> identical, only `DATABASE_URL` changes:
> - **AWS GovCloud:** RDS for PostgreSQL + ECS/Fargate — see
>   [`deploy/aws-govcloud/`](deploy/aws-govcloud/).
> - **Azure Government:** Azure Database for PostgreSQL Flexible Server +
>   Container Apps — see [`deploy/azure-gov/`](deploy/azure-gov/).
>
> The app is offline-first, so an expired/unset database never breaks the UI —
> it simply runs in local-only mode until `DATABASE_URL` is restored.

### 2) Local (Docker, mirrors the cloud topology)

```bash
cd aeromarkup
docker compose up --build       # → http://localhost:8080
```

Or run the static frontend only (offline mode, no backend):

```bash
python3 preview_server.py       # → http://localhost:4173
```

### 3) AWS GovCloud (ECS / Fargate)

Image is non-root and FedRAMP/STIG-friendly.

```bash
cd aeromarkup
export AWS_REGION=us-gov-west-1
export ECS_CLUSTER=aeromarkup-cluster ECS_SERVICE=aeromarkup-svc
./deploy/aws-govcloud/deploy.sh
```

- Store the connection string in **Secrets Manager** as
  `aeromarkup/database-url` (referenced by
  [`task-definition.json`](deploy/aws-govcloud/task-definition.json)).
- Back it with **RDS for PostgreSQL** in the same VPC.
- Front the service with an ALB; target the health check at `/api/health`.

### 4) Azure Government (Container Apps)

```bash
cd aeromarkup
az cloud set --name AzureUSGovernment
export AZ_RESOURCE_GROUP=aeromarkup-rg AZ_ACR=aeromarkupacr
export DATABASE_URL='postgres://user:pass@<server>.postgres.database.usgovcloudapi.net:5432/aeromarkup?sslmode=require'
./deploy/azure-gov/deploy.sh
```

Provisions a Container App (+ Log Analytics) from
[`containerapp.bicep`](deploy/azure-gov/containerapp.bicep), pairs with
**Azure Database for PostgreSQL Flexible Server**, and probes `/api/health`.

---

## Environment

| Var | Default | Purpose |
|-----|---------|---------|
| `DATABASE_URL` | _(empty)_ | Postgres DSN. Empty → **offline-only** mode (PWA still works). |
| `PORT` | `8080` | Listen port (host usually sets this). |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` on startup. |

See [`.env.example`](.env.example).

---

## API

| Method | Path | Purpose |
|--------|------|---------|
| GET  | `/api/health` | Liveness/readiness + DB status |
| GET  | `/api/projects` | List projects |
| POST | `/api/projects` | Create project |
| GET  | `/api/projects/{id}/drawings` | List drawings |
| POST | `/api/projects/{id}/drawings` | Create drawing |
| GET  | `/api/drawings/{id}` | Drawing + strokes + annotations |
| POST | `/api/sync` | Push offline changes, pull peers' changes |

---

## Security notes (gov deployment)

- Stateless container, **runs as non-root** (uid 10001).
- Secrets via Secrets Manager (AWS) / Container App secrets (Azure) — never baked
  into the image.
- TLS terminates at the ALB / Container App ingress; require `sslmode=require`
  to the database in gov regions.
- No third-party CDN/runtime calls from the frontend — everything is
  self-hosted, which keeps it usable in air-gapped/offline environments.
