# AeroMarkup ✈

**Offline-capable aircraft drawing, sketch & annotation tool** for aerospace and
manufacturing. Load an engineering drawing or photo of an airframe/part, then
sketch, point, annotate, measure, and redline it — on a 2-in-1, iPad, Android
tablet, or phone, **with or without internet**.

> Deploys to **Render** today; the same container runs in **AWS GovCloud** and
> **Azure Government**.

---

## What it does

| Capability | Notes |
|------------|-------|
| Freehand sketch / scribble | Pressure-sensitive with a stylus (Apple Pencil, Surface Pen, S Pen) |
| Highlighter & eraser | Translucent markup; eraser removes ink |
| Pointers / arrows | Drag from base to tip to point at a feature |
| Notes & callouts | Drop draggable text notes anywhere |
| Pins | Drop location markers |
| Shapes | Line, rectangle, ellipse to circle areas |
| Load reference image | Bring in a drawing or photo to mark up |
| Pan / pinch-zoom | Two-finger gestures on touch; wheel + space-drag on desktop |
| Undo / redo | Full history per drawing |
| Export PNG | Flattened image of background + markup |
| **Works offline** | App shell cached (service worker); all data saved to **IndexedDB** |
| **Syncs when online** | Pushes/pulls changes via `/api/sync` to PostgreSQL |

Input is handled with **Pointer Events**, so mouse, touch, and pen all work and
pen pressure is captured where the hardware supports it.

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
Browser PWA (static/)                Flask API (server.py)         PostgreSQL (db/schema.sql)
┌───────────────────────┐   /api    ┌────────────────────┐  SQL  ┌────────────────────────┐
│ canvas engine + IndexedDB │ ◄────► │ /projects /drawings │ ◄──► │ projects, drawings,     │
│ service worker (offline)  │  sync  │ /sync  /health      │       │ strokes, annotations,   │
└───────────────────────┘           └────────────────────┘       │ revisions, sync_log …   │
                                                                  └────────────────────────┘
```

The Flask process is **stateless** — all durable state is in Postgres and each
client's IndexedDB. That's what makes it portable across Render, ECS/Fargate,
and Azure Container Apps.

---

## Database

> **Shared-database safe.** All objects are created in a dedicated
> `aeromarkup` Postgres schema, and the app connects with
> `search_path = aeromarkup,public`. You can therefore point `DATABASE_URL`
> at a database shared with other apps (e.g. APEX) without colliding on
> common names like `users` or `projects`.

The full schema for **every table** is in [`db/schema.sql`](db/schema.sql)
(idempotent; PostgreSQL 13+). Tables (all under schema `aeromarkup`):

`users` · `projects` · `drawings` · `layers` · `strokes` · `annotations` ·
`attachments` · `revisions` · `sync_log`

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
