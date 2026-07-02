# CLAUDE.md — PALADIN Project Guidance

Guidance for working on **PALADIN**, a self-hosted, Confluence-style team
documentation & knowledge-workspace platform (spaces, pages, blogs, controlled
documents, workflows, approvals/e-signature, versioned attachments, comments,
SSO, SCIM). This file governs how changes are made in this project directory and
inherits the repo-wide rules in the root `CLAUDE.md`.

## What PALADIN is

- **Purpose:** a compliant, auditable knowledge base + controlled-document system
  — wiki spaces and pages, blog posts, controlled/numbered documents with
  approval workflows and e-signatures, versioned attachments, labels, comments,
  and full-text search.
- **Stack:** PHP 8.3 + Apache, PostgreSQL 16. No heavyweight framework — a small
  MVC-style router (`index.php` → `controllers/` → `views/`) with library
  classes in `src/`. Single Docker image; Render- and container-deployable.
- **Storage:** attachments via `local` disk or `s3` (`STORAGE_DRIVER`); S3
  credentials encrypted at rest.
- **Identity:** form login, SAML 2.0, OIDC (Auth Code + PKCE), SCIM 2.0
  provisioning, personal access tokens, MFA + recovery codes.

## Where things live

| Path | Contents |
|------|----------|
| `index.php` | Front controller / router; health endpoints `/health`, `/healthz`, `/readyz` |
| `controllers/` | Request handlers (Page, Space, Blog, Workflow, Approval, Comment, Attachment, Admin, Auth, Saml, Oidc, Scim, …) |
| `src/` | Core libraries: `Auth`, `Security`, `Storage`, `Saml`, `Oidc`, `Scheduler`, `Webhook`, `Retention`, `Upload`, `Pdf`, `Docx`, `View`, … |
| `views/` | Server-rendered templates |
| `database/schema.sql` + `database/migrations/` | Full schema + ordered idempotent migrations (`schema_migrations`) |
| `install.php` | Authoritative installer — applies schema + all migrations (idempotent, retrying) |
| `cli/` | CLI jobs: `send_digests.php`, `send_review_reminders.php` |
| `api/`, `scim/` | REST API + SCIM provisioning endpoints |
| `docker/`, `Dockerfile`, `docker-compose.yml`, `render.yaml` | Deployment assets |
| `docs/`, `deployments/` | The standard documentation + deployment set (see below) |

## Build / test / deploy

- **Local:** `docker compose up` (see `deployments/LOCAL_DEVELOPMENT.md`), or run
  Apache/PHP natively against a local PostgreSQL and run `php install.php`.
- **Migrations:** never hand-edit applied migrations. Add a new numbered file in
  `database/migrations/`, and update `database/schema.sql` to reflect the full
  combined schema (repo rule). `install.php` applies them idempotently.
- **Tests:** run the suite in `tests/`.
- **Deploy:** Docker image / Render blueprint. Cloud + air-gapped runbooks are in
  `deployments/`.

## Conventions (inherits root CLAUDE.md — key ones for PALADIN)

- **CSP / no inline handlers:** use `data-*` attributes wired by the app JS; every
  `<script>` carries a nonce. No `onclick=`/`onchange=`/etc.
- **XSS:** escape all user output with the `Security` helpers; `json_encode` in
  script contexts uses `JSON_HEX_TAG | JSON_HEX_AMP`.
- **CSRF:** every POST form includes the CSRF field; every POST handler validates
  it.
- **SQL:** parameterized queries only — never concatenate user input into SQL.
- **AuthZ:** every protected controller action enforces auth/permission; every
  view capability check uses the specific permission string.
- **Audit:** state-changing actions append to the hash-chained `activity_log`.
- **Uploads:** MIME + extension allowlist, magic-byte sniffing, randomized stored
  filenames; every upload writes an `attachments` row (versioned) + audit row.
- **Secrets:** never commit `.env`; use `.env.example` placeholders. Production
  secrets come from a secret manager.
- **Branding & Settings:** honor the repo Settings & Branding standard (logo URL /
  data-URL upload, display name, accent color; persisted server-side; header logo
  links home).

## Standing rule — keep the doc set current

PALADIN ships the standard documentation & deployment set and it must stay
accurate to the code:

- `deployments/` — `LOCAL_DEVELOPMENT`, `SINGLE_LINUX_SERVER`, `KUBERNETES`,
  `AZURE`, `AWS`, `AIRGAPPED`.
- `docs/` — `ARCHITECTURE`, `DEPLOYMENT`, `DISASTER_RECOVERY`, `SECURITY` (plus
  the existing supporting docs: `AUDIT_TRAIL`, `PERMISSIONS_MODEL`,
  `KNOWN_LIMITATIONS`, `QMS_WORKFLOW`, `DEMO_GUIDE`).
- Root — `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`.

Whenever a feature, migration, env var, port, or config change lands, update the
affected `deployments/`, `docs/`, `README.md`, `OPEN_ITEMS.md` and
`database/schema.sql` **in the same change** — treat the doc set as part of
"done." Do not invent commands, env vars, ports or paths; verify every claim
against the real code.
