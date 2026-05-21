# NEXUS · Project Tracker

A DoD-grade project + ticket tracker. PHP 8.2 + PostgreSQL backend, simple
HTML/CSS/vanilla-JS SPA frontend, CAC/PIV-simulated authentication with
bcrypt-verified PINs and HS256 JWTs. Deployable to Render.com in a single
push via the included `render.yaml`.

This is a real dynamic application — all state lives in PostgreSQL; the
frontend talks to a REST API at `/api/*`. It replaces the prior
`tracker.html` single-file SPA that persisted to `localStorage`.

---

## Tech stack

| Layer       | Choice                                |
|-------------|---------------------------------------|
| Backend     | PHP 8.2 (no framework — PDO + a tiny router) |
| Database    | PostgreSQL 16                         |
| Auth        | CAC/PIV simulation + bcrypt PIN + HS256 JWT (cookie + Authorization header) |
| Frontend    | Vanilla JS, Bootstrap 5.3 dark theme  |
| Container   | `php:8.2-apache` + `pdo_pgsql`        |
| Deploy      | Render.com (`render.yaml`)            |

---

## Local development

### Option A — Docker Compose (fastest)

```bash
cd nexus
docker compose up --build
```

Then open `http://localhost:8080`. The PHP container will run
`scripts/migrate.php` against the bundled Postgres before Apache starts;
that script applies `schema.sql` if the `users` table is missing.

### Option B — Plain PHP + a Postgres you already have

```bash
cp .env.example .env
# edit .env: point DATABASE_URL at your local Postgres

# create the schema
psql "$DATABASE_URL" -f schema.sql

# serve the SPA from public/
php -S 0.0.0.0:8080 -t public/
```

> Note: PHP's built-in server doesn't process `.htaccess`. The SPA still
> works because every URL gets routed through `public/index.php` — for the
> API you need to hit `/api/index.php/...` *or* use Apache/Nginx. Docker
> Compose is the path of least resistance.

---

## Deploying to Render.com

1. Fork (or push) this repo to GitHub.
2. In Render, click **New → Blueprint** and point it at the repo.
3. Render reads `nexus/render.yaml` and provisions:
   - the `nexus-db` PostgreSQL database (free tier),
   - a Docker web service named `nexus`,
   - a `JWT_SECRET` (auto-generated),
   - `DATABASE_URL` (auto-wired from the DB).
4. On first boot, `scripts/migrate.php` applies `schema.sql` (including the
   seed users and the SEC project).
5. Sign in with one of the three test identities (below).

### Default test credentials

| Username | PIN     | Role   | Clearance     |
|----------|---------|--------|---------------|
| rojas    | 654321  | admin  | SECRET        |
| smith    | 112233  | member | TS/SCI        |
| brown    | 999999  | viewer | UNCLASSIFIED  |

The seed `pin_hash` column is a real `password_hash($pin, PASSWORD_BCRYPT)`
value — bcrypt-verified server-side. The fallback env var
`NEXUS_ALLOW_DEFAULT_PINS=1` additionally accepts the plain PINs above so
first-run deploys "just work"; flip it to `0` in production.

---

## API reference

All routes return JSON. Successful responses are `{ "data": ..., "meta": ... }`.
Errors are `{ "error": "...", "code": "..." }`.

Authentication: send the JWT either in `Authorization: Bearer <token>` or
via the `nexus_token` HttpOnly cookie set on login.

| Method  | Path                                       | Auth     | Notes |
|---------|--------------------------------------------|----------|-------|
| GET     | `/api/health`                              | none     | Liveness probe |
| POST    | `/api/auth/login`                          | none     | Body: `{ userId, pin }` |
| POST    | `/api/auth/logout`                         | any      | Clears the cookie |
| GET     | `/api/auth/me`                             | any      | Current user payload |
| GET     | `/api/projects`                            | any      | Projects the caller is a member of |
| POST    | `/api/projects`                            | admin    | Creates a project + auto-adds creator |
| GET     | `/api/projects/{id}`                       | member   | Includes member roster |
| PATCH   | `/api/projects/{id}`                       | admin    | Update name/description/etc. |
| POST    | `/api/projects/{id}/members`               | admin    | Body: `{ userId, role }` |
| DELETE  | `/api/projects/{id}/members/{uid}`         | admin    | Remove a member |
| GET     | `/api/projects/{id}/tickets`               | member   | Filters: `status`, `priority`, `effort`, `type`, `assignee`, `label`, `sprint`, `search` |
| POST    | `/api/tickets`                             | member   | Body: `{ projectId, title, ... }`; auto-IDs (`SEC-009`) |
| GET     | `/api/tickets/{id}`                        | member   | Full ticket payload |
| PATCH   | `/api/tickets/{id}`                        | member   | Any subset of fields; logs history per scalar |
| DELETE  | `/api/tickets/{id}`                        | admin    | Cascade removes comments + history |
| PATCH   | `/api/tickets/{id}/status`                 | member   | Status transition + notifications |
| PATCH   | `/api/tickets/{id}/watch`                  | member   | Toggle watcher |
| GET     | `/api/tickets/{id}/comments`               | member   | Chronological |
| POST    | `/api/tickets/{id}/comments`               | member   | Body: `{ body }`; notifies watchers |
| GET     | `/api/tickets/{id}/history`                | member   | Per-ticket audit log |
| GET     | `/api/projects/{id}/history`               | member   | 200 most recent events project-wide |
| GET     | `/api/projects/{id}/labels`                | member   | |
| POST    | `/api/projects/{id}/labels`                | admin    | |
| PATCH   | `/api/labels/{id}`                         | admin    | |
| DELETE  | `/api/labels/{id}`                         | admin    | |
| GET     | `/api/projects/{id}/sprints`               | member   | |
| POST    | `/api/projects/{id}/sprints`               | admin    | |
| PATCH   | `/api/sprints/{id}`                        | admin    | Setting `status=completed` returns non-Done tickets to backlog |
| GET     | `/api/notifications`                       | any      | `meta.unread` is included |
| PATCH   | `/api/notifications/{id}`                  | any      | Body: `{ read: true }` |
| POST    | `/api/notifications/read-all`              | any      | |

### Role hierarchy
`viewer < member < admin`. `requireRole('member')` accepts both members and
admins; `requireRole('admin')` is admin-only.

---

## Layout

```
nexus/
├── Dockerfile                 # PHP 8.2 + Apache + pdo_pgsql
├── docker-compose.yml         # Local: app + postgres
├── render.yaml                # Render.com blueprint
├── .env.example
├── schema.sql                 # Full schema + seed (3 users, 1 project, 8 tickets, 6 labels)
├── bin/start.sh               # Container entrypoint (runs migrate + apache)
├── scripts/migrate.php        # Idempotent schema applier
├── public/                    # Apache document root
│   ├── .htaccess
│   ├── index.php              # SPA shell
│   ├── app/                   # mirrored static assets (nexus.js / nexus.css)
│   └── api/
│       ├── index.php          # API router entry
│       ├── auth.php           # /api/auth/*
│       ├── projects.php       # /api/projects/*
│       ├── tickets.php        # /api/tickets/*, transitions, watchers
│       ├── comments.php       # /api/tickets/{id}/comments
│       ├── labels.php         # /api/projects/{id}/labels
│       ├── sprints.php        # /api/projects/{id}/sprints
│       ├── history.php        # /api/tickets/{id}/history
│       └── notifications.php  # /api/notifications/*
├── src/                       # PHP classes (Nexus\* namespace)
│   ├── Database.php
│   ├── Auth.php
│   ├── Response.php
│   └── Router.php
└── app/                       # Canonical frontend (also mirrored under public/app)
    ├── nexus.js
    └── nexus.css
```

---

## Security notes

- PINs are bcrypt-hashed (`PASSWORD_BCRYPT`). Login responds with a
  uniform `Invalid credentials` regardless of which side failed.
- JWTs are HS256 with an 8h expiry, signed with `JWT_SECRET`. The cookie
  is HttpOnly + SameSite=Lax + Secure (in production).
- All mutating endpoints go through `Auth::requireAuth()` / `requireRole()`,
  and project-scoped reads go through `requireMembership()` (admins
  bypass the project membership check).
- Every ticket mutation writes an audit row to `history`. Notifications
  fan out to the assignee + the watcher set on status changes & comments.
- Production: set `NEXUS_ALLOW_DEFAULT_PINS=0` once you've rotated the
  seed PINs, and rotate `JWT_SECRET` if it's ever logged.
