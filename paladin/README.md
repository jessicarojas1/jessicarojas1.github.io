# PALADIN — Process · Approval · Library

**PALADIN** (*Process, Approval & Library — Assured Documentation & Information Network*)
is an enterprise-grade controlled-document, workflow and knowledge platform: the authoritative
source for organizational policies, procedures, processes, forms, records, templates,
standards, work instructions and knowledge. It combines the capabilities of a
Confluence-style wiki, a SharePoint document library, a controlled-document /
quality-management system, a workflow & approval engine, and a compliance evidence
repository — in a single, self-hostable, government-cloud-ready application.

---

## Highlights

| Capability | What you get |
|---|---|
| **Spaces / Knowledge Areas** | Department, team, program, project, compliance & process spaces with tree navigation, members, watchers, favorites and per-space document libraries |
| **Pages** | Rich-text editor, child-page hierarchy, full version history with restore, comments, publish workflow |
| **Controlled Documents** | 12 document types, full control metadata, lifecycle states (Draft → In Review → Approved → Published → Archived/Obsolete), revision tracking, check-in/check-out, version snapshots, read receipts/acknowledgements, related processes/risks/controls/systems, file attachments |
| **Workflow Engine** | Reusable templates with ordered steps, single / sequential / parallel / consensus approval modes, SLA per step, escalation-ready due dates |
| **Approvals** | Route any item, multi-step decisions (approve / reject / return for revision), full hash-chained audit trail, in-app notifications |
| **Processes** | Process repository with ownership, versioning, flow definition and relationship linkage |
| **Tasks** | My / team / overdue / completed views, priorities, corrective actions, due-date tracking |
| **Templates** | Reusable policy/procedure/process/meeting/project/risk/audit templates |
| **Search** | Full-text across documents, pages, processes, tasks & spaces, with saved searches |
| **Reporting** | Document status, expiring documents, approval backlog, acknowledgement coverage — with CSV export |
| **RBAC** | 9 roles + granular module×action permissions, two-pane IAM editor with role defaults vs explicit grants |
| **Audit & Compliance** | Immutable, SHA-256 hash-chained activity log of every meaningful action |
| **API** | Versioned REST API (`/api/v1`), API-key + session auth, OpenAPI/Swagger docs at `/api/docs` |
| **Branding** | Logo (URL or uploaded data-URI), display name and accent colour, applied live across the app |

---

## Architecture

- **Language / runtime:** PHP 8.3, no framework — a small, auditable MVC core.
- **Database:** PostgreSQL (schema namespace `paladin`).
- **Front end:** server-rendered PHP views, Bootstrap 5 + Bootstrap Icons, a CSP-safe
  vanilla-JS layer (`public/js/app.js`) using `data-*` event delegation (no inline handlers).
- **Storage:** pluggable abstraction — local mounted volume or any S3-compatible store
  (AWS GovCloud S3, MinIO, Cloudflare R2…).
- **Security:** nonce-based CSP, Argon2id password hashing, rotating CSRF tokens,
  rate limiting, HttpOnly/SameSite=Strict sessions, server-side session revocation,
  AES-256-GCM encryption-at-rest for sensitive settings, immutable hash-chained audit log.

```
paladin/
├── index.php              # front controller / router
├── install.php            # idempotent installer + migrations + first-run seed
├── config/                # app + database config
├── src/                   # core: Database, Security, Auth, Branding, Storage, Upload, JWT, View
├── controllers/           # one controller per module
├── views/                 # server-rendered templates (+ layout.php, partials/)
├── api/                   # REST API (index.php), Swagger UI (docs.php), openapi.json
├── database/              # schema.sql (authoritative reference), migrations/, seeds/
├── public/                # css/js/vendor assets
├── scripts/startup.sh     # container entrypoint (install → apache)
├── Dockerfile, docker-compose.yml, render.yaml
└── docs/                  # ARCHITECTURE.md, DEPLOYMENT.md
```

---

## Quick start (local, Docker)

```bash
cp .env.example .env
# set JWT_SECRET (>=64 hex), ADMIN_EMAIL, ADMIN_PASSWORD, DB_PASS
docker compose up --build
# → http://localhost:8080   (sign in with ADMIN_EMAIL / ADMIN_PASSWORD)
```

The first boot runs `install.php`, which creates the `paladin` schema, applies the schema +
migrations, creates the admin user and seeds demo content (spaces, documents, processes,
workflow templates, tasks and the template library). Demo users (password `PalDemo!2026`)
cover every role: `pal.admin@`, `compliance@`, `owner@`, `author@`, `reviewer@`,
`approver@`, `auditor@`, `viewer@` `demo.local`.

## Quick start (local, no Docker)

```bash
createdb pal && psql pal -c "CREATE SCHEMA IF NOT EXISTS paladin;"
cp .env.example .env   # point DB_* at your Postgres, set JWT_SECRET/ADMIN_*
php install.php
php -S localhost:8080 router-dev.php
```

---

## Roles

`admin` (system) · `pal_admin` · `compliance_admin` · `space_owner` · `contributor` ·
`reviewer` · `approver` · `auditor` · `viewer`. Each role carries sensible module
defaults; administrators can grant additional **explicit** permissions per user in
**Administration → Permissions** (green = role default, orange = explicit grant).

## Document lifecycle

```
Draft ──submit──▶ In Review ──approve──▶ Approved ──publish──▶ Published
   ▲                  │                                            │
   └──── return ──────┘                       archive / obsolete ──┘
```

Routing a document for approval moves it to **In Review**; a completed approval moves it
to **Approved**; rejection returns it to **Rejected**; returns send it back to **Draft**.
Published documents can require **acknowledgement** (read receipts) and support
revision-based supersession.

---

## Security & compliance

- Immutable **hash-chained audit log** (`activity_log.log_hash` chains each event to the
  prior one — tampering is detectable).
- **CSP** without `unsafe-inline` for scripts (per-request nonce); all UI behaviour uses
  `data-*` delegation.
- **CSRF** tokens on every state-changing request, rotated on use.
- **RBAC** enforced in controllers (`Auth::requirePermission`) and views (`Auth::can`).
- **Encryption at rest** for SMTP/S3 secrets (`Security::encryptSetting`, AES-256-GCM).
- **Uploads** validated by extension allowlist + size + MIME sniff, stored with
  randomized filenames.

See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for **Azure Government**, **AWS GovCloud**,
**Kubernetes** and **Docker Swarm** guidance, and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
for the data model and request lifecycle.

Security & compliance references:
[`docs/SECURITY.md`](docs/SECURITY.md) (controls & threat coverage),
[`docs/PERMISSIONS_MODEL.md`](docs/PERMISSIONS_MODEL.md) (RBAC + object-level access),
[`docs/AUDIT_TRAIL.md`](docs/AUDIT_TRAIL.md) (hash-chained, tamper-evident audit log).

## API

```bash
curl -H "X-API-Key: pal_xxx" https://your-domain/api/v1/documents
```

Interactive documentation: **`/api/docs`** (Swagger UI). Spec: `/api/openapi.json`.
Create keys in **Administration → API Keys**.
