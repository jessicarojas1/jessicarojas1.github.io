# CLAUDE.md — APEX Project Guidance

APEX is a DoD-grade project + ticket tracker. This file is guidance for working in
the `apex/` project. It complements the repo-root `CLAUDE.md` (whose standards
apply here too).

## What it is
A real dynamic app: PHP 8.2 + Apache backend, PostgreSQL 16, a vanilla-JS SPA, and
CAC/PIV-simulated auth (bcrypt PINs + HS256 JWT). All state lives in Postgres; the
SPA talks to a REST API at `/api/*`. Deployable to Render via `render.yaml`, or as
a Docker container anywhere, a single VM, or Kubernetes.

## Stack & conventions
- **Backend:** no framework. Four classes in `src/` (namespace `Apex\`):
  `Database`, `Auth`, `Response`, `Router`. One handler file per resource under
  `public/api/`, each `require`d by `public/api/index.php`.
- **Responses:** always go through `Apex\Response`. Success is
  `{ "data": …, "meta"?: … }`; errors are `{ "error": …, "code": … }`. Never echo
  JSON by hand.
- **DB access:** only via `Apex\Database` with **bound parameters**. Never
  concatenate user input into SQL. `EMULATE_PREPARES` is off. Use
  `Database::newId('prefix')` for IDs.
- **Auth:** gate every mutating/protected route with `Auth::requireAuth()` or
  `Auth::requireRole('member'|'admin')`; project reads also enforce membership.
  Role order: `viewer < member < admin`.
- **Frontend:** edit the canonical `app/apex.js` / `app/apex.css`; the Docker
  build mirrors them into `public/app/`. **No inline event handlers** (CSP:
  `script-src 'self'`) — use data-attributes + listeners in `apex.js`.
- **Secrets:** from env only (`JWT_SECRET`, `DATABASE_URL`). Never commit `.env`.
  Production fails closed if `JWT_SECRET` < 32 chars.
- **Branding:** stored server-side in `app_settings` (`branding` key), edited via
  `POST /api/settings/branding` (admin). Logo URLs must be sanitized to
  `http(s)://` or `data:image/…` only. Header brand mark links home.

## Where things live
```
src/                 PHP classes (Database, Auth, Response, Router)
public/api/          API dispatcher (index.php) + one file per resource
public/index.php     SPA shell (HTML)
public/.htaccess     HTTPS redirect + CSP/security headers + rewrites
app/                 canonical apex.js / apex.css (mirrored into public/app at build)
schema.sql           full schema + seed (idempotently applied by migrate.php)
scripts/migrate.php  applies schema.sql only when the users table is absent
bin/start.sh         entrypoint: migrate then apache2-foreground
Dockerfile           PHP 8.2 + Apache + pdo_pgsql, non-root on :8080
render.yaml          Render Blueprint (rootDir: apex)
docs/                ARCHITECTURE · DEPLOYMENT · DISASTER_RECOVERY · SECURITY
deployments/         6 target guides (owned/maintained separately)
```

## Build / test / deploy
- **Local:** `docker compose up --build` (needs `DB_PASS` and `JWT_SECRET` set),
  open `http://localhost:8080`. Or native: `psql "$DATABASE_URL" -f schema.sql`
  then `php -S 0.0.0.0:8080 -t public/`.
- **Migrate:** automatic on container boot; manual via `php scripts/migrate.php`
  or `psql "$DATABASE_URL" -f schema.sql` (⚠️ `schema.sql` drops+reseeds — never
  run it directly against a populated prod DB).
- **Deploy:** Render Blueprint, or any Docker host. See
  [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
- **Verify:** `curl /api/health`, then login + create a ticket (see
  DEPLOYMENT §Verification).
- **No worker/cron:** all work is synchronous in-request; nothing to schedule.

## Security & UI rules that apply here
- No inline event handlers; all `<script>`/JS from `'self'` only (strict CSP).
- Parameterized SQL only; uniform `Invalid credentials`; fail closed on secrets.
- Every protected route calls `requireAuth()`/`requireRole()`; branding writes are
  admin-only and input-sanitized.
- Header logo links to home; dark-mode-safe; no hardcoded colors that should be
  the accent var (branding accent applied via CSS custom property).

## Standing doc rule
Keep the standard doc set current as the app changes: `docs/` (Architecture,
Deployment, Disaster Recovery, Security), `deployments/` (×6), `README.md`,
`OPEN_ITEMS.md`, and this file. Update the affected docs in the same change as any
feature, migration, or config change. Every project stays Docker- and
Render-deploy compatible.
