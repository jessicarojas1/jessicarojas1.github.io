# Demo Guide

How to stand up a working Sentinel QMS demo, what sample data is seeded, and how
to sign in. For a hosted demo on Render, see [render-demo.md](render-demo.md);
for full production deployment see [deployment-guide.md](deployment-guide.md).

> Authoritative seeding logic: `backend/app/seed.py`.

---

## 1. What seeding does

On startup (or via `python -m app.seed`) the seeder runs in two phases. It is
**idempotent** — safe to run repeatedly; it upserts essentials and only inserts
demo records into empty tables.

**Phase 1 — essentials (always):**
- **Roles** — all 8 (Admin, Quality Manager, Quality Engineer, Auditor, Supplier
  Quality, Operator, Read-Only, Customer), each with a description.
- **Bootstrap admin** — created/synced only when `ADMIN_AUTO_CREATE=true` and
  `ADMIN_EMAIL` / `ADMIN_PASSWORD` are set (see §3).
- **Permission matrix** — role × page defaults (`RolePagePermission`); existing
  rows are preserved so admin customizations persist.
- **Org settings** — the singleton settings row.
- **Standards coverage** — AS9100D, ISO 9001, NADCAP, NIST 800-171, AS9145
  clause-to-module coverage matrix.

**Phase 2 — demo data (only when tables are empty; best-effort):**
- **Suppliers (2):** Aero Precision Machining LLC (Approved, AS9100D),
  TitaniumWorks Inc. (Conditional, ISO 9001).
- **Document (1):** Quality Policy `DOC-2026-0001` (Approved).
- **Nonconformance (1):** Out-of-tolerance bore diameter `NCR-2026-0001`
  (Major, Open).
- **CAPA (1):** Recurring bore oversize on CNC cell 3 `CAPA-2026-0001`
  (Root-cause, 5-Why).
- **Equipment (1):** Mitutoyo micrometer `GAGE-2026-0001` (365-day cal interval).
- **Quality objectives (2):** On-time delivery ≥ 98%; Customer escapes ≤ 3/yr.
- **Improvements / Kaizen (2):** CNC setup-time reduction (Done); weld-shop
  digital travelers (In progress).
- **Customer (1):** Apex Aerospace Primes `CUST-0001`.
- **Customer satisfaction survey (1):** Q1 2026 (quality / delivery /
  communication scores).
- **FMEA (1):** Bracket weld process PFMEA `FMEA-2026-0001` with 2 failure-mode
  items (severity × occurrence × detection → RPN).

Phase 2 is wrapped in best-effort error handling: if demo seeding fails it rolls
back and logs, but never blocks the admin account or essentials.

## 2. Running the demo

### Local
```bash
cd sentinel-qms/backend
python -m venv .venv && source .venv/bin/activate
pip install -e ".[dev]"
cp .env.example .env            # then set JWT_SECRET, ADMIN_EMAIL, ADMIN_PASSWORD
alembic upgrade head            # apply schema (PostgreSQL)
python -m app.seed              # seed roles, admin, and demo data
uvicorn app.main:app --reload
```
Build the SPA (or run it with Vite) per `backend/README.md`; in single-service
mode the API serves the built SPA same-origin (`SERVE_FRONTEND=1`).

### Hosted (Render)
The container entrypoint runs `alembic upgrade head` and seeds before starting
Gunicorn when `AUTO_MIGRATE=true` / `AUTO_SEED=true`. Follow
[render-demo.md](render-demo.md) for the Blueprint or manual setup.

## 3. Signing in

The bootstrap admin uses the credentials **you** provide via environment — no
admin password ships in the repo:

| Variable | Purpose |
|----------|---------|
| `ADMIN_AUTO_CREATE` | `true` to create/sync the bootstrap admin (set `false` in production after first login). |
| `ADMIN_EMAIL` | Bootstrap admin email. |
| `ADMIN_PASSWORD` | Bootstrap admin password (hashed on seed; rotate after first login). |

Sign in at the app URL with that email/password. API docs are at `/docs`, the
health probe at `/health`.

> **Security:** Change the admin password immediately after first login,
> especially if the demo URL is shared. Never set a production demo to
> `ENVIRONMENT=production` with `ADMIN_AUTO_CREATE=true` left on.

## 4. Exploring the seeded data

Once signed in as admin you can walk a full quality thread end-to-end:
the seeded **NCR** → its linked **CAPA** (5-Why) → **supplier** SCAR context →
**FMEA** risk priorities → **quality objectives** and **customer satisfaction**
feeding the **Management Review** inputs and the **Executive Dashboard** tiles.

## 5. Demo caveats (free hosting tiers)
- Services may **spin down when idle** (cold start on next request).
- Free PostgreSQL instances expire after ~30 days.
- `STORAGE_BACKEND=local` means **uploaded files do not persist** across
  restarts/redeploys — use S3/Azure Blob (or a persistent disk) for durable
  uploads. See [KNOWN_LIMITATIONS.md](../KNOWN_LIMITATIONS.md) §5.
