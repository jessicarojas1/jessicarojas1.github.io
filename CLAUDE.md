# CLAUDE.md — AEGIS GRC Project Rules

## Permanent Workflow Rules

### 1. Security & Compliance Analysis (Every Feature)
After completing any significant feature, fix, or module addition, spawn a security audit agent covering:
- **CSP compliance**: no inline event handlers (`onclick=`, `onchange=`, `onsubmit=`, `oninput=`); all `<script>` tags have `nonce="<?= Security::nonce() ?>"`
- **XSS**: all user output wrapped in `Security::h()`; `json_encode` in script contexts uses `JSON_HEX_TAG | JSON_HEX_AMP`
- **CSRF**: every POST form has `Security::csrfField()`; every POST controller method calls `Security::validateCsrf()`
- **SQL injection**: parameterized queries only — no string concatenation of user input into SQL
- **Open redirects**: validate `HTTP_REFERER` and any redirect targets against a strict path regex before use
- **Sensitive data**: no hardcoded credentials; secrets from env vars or Secrets Manager
- **File uploads**: MIME type validation, extension allowlist, randomized stored filenames
- **Auth coverage**: every protected route calls `Auth::requireAuth()` or `Auth::requirePermission()`

### 2. UI Consistency Check (Every Feature)
After completing any UI work, spawn a UI audit agent covering:
- **No inline event handlers**: use `data-click`, `data-submit`, `data-show-modal`, `data-close-modal`, `data-confirm-click` attributes handled by app.js
- **Filter buttons**: all list pages use `<button class="btn btn-sm filter-btn" data-toggle-class="open" data-target="#ID"><i class="bi bi-funnel-fill"></i> Filters`
- **Modal patterns**: open via `data-show-modal`, close via `data-close-modal` + `<i class="bi bi-x-lg"></i>` — no `addEventListener` wiring
- **Empty states**: use `class="empty-state-sm"` inside `<td class="empty-row">` — no inline padding styles
- **Page headers**: every full view has `<div class="page-header"><h1 class="page-title">`
- **Breadcrumbs**: every view sets `$breadcrumbs = [...]`
- **Dark mode**: no hardcoded hex colors in inline styles — use CSS custom properties (`var(--danger)`, `var(--success)`, `var(--warning)`, `var(--card-bg)`, etc.)
- **Submit buttons**: no hardcoded `disabled` attribute on submit buttons

### 3. Database Schema File (Every Project)
Every project that uses a database **must** maintain a `database/schema.sql` file that:
- Is a **complete, idempotent** SQL script covering all tables, indexes, and seed data
- Can be run against a fresh database to produce a fully functional schema
- Uses `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`, and `INSERT ... ON CONFLICT DO NOTHING` — never plain `CREATE` without `IF NOT EXISTS`
- Is organized with section comments grouping tables by module
- Includes a header comment explaining it is a manual-setup reference and pointing to the authoritative installer (e.g. `install.php`)
- Is updated whenever a new migration is added — the schema file must always reflect the current state of all migrations combined

### 4. These Checks Are Part of the Roadmap
Every project roadmap must include:
- [ ] Security & compliance audit (CSP, XSS, CSRF, SQLi, auth, uploads, redirects)
- [ ] UI consistency check (handlers, modals, filters, empty states, dark mode, breadcrumbs)
- [ ] `database/schema.sql` updated to reflect all current migrations
- [ ] Standard documentation & deployment set present and current (`deployments/` ×6, `docs/` ×4, `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml` — see "Standard Documentation & Deployment Set")
- [ ] Fix all findings before marking the milestone complete

## User & Permission Management UI Standard

When asked to build any user management, roles, or permissions UI, always deliver this level of depth:

- **Two-pane IAM layout**: scrollable user list (left, ~300px) + permission editor (right, fills remaining space)
- **User list**: live search filtering by name, user cards with avatar initial, name, department, role badge, click-to-select with active state
- **Permission editor**: per-module accordions (first 3 open by default), each module shows colored icon + label + `N/total` granted count badge + Grant All / Clear All batch buttons
- **Granular module × action permissions**: not just read/write/edit — specific actions per module (e.g. `risk.accept`, `risk.review`, `kri.record`, `vendor.contracts`, `bcp.exercise`, `policy.publish`, `audit.close`)
- **Visual distinction**: green dot = role default, orange dot = explicit grant, gray = denied — never just a binary checkbox grid
- **AJAX save**: POST via `fetch()`, return `{"ok":true,"csrf":"<rotated-token>"}` on success so client rotates CSRF in-memory; show toast notifications (success/error)
- **Dirty tracking**: "Unsaved changes" indicator on save buttons; disable save during in-flight request
- **Expand All / Collapse All** toolbar controls + live total permissions count
- **Role defaults vs explicit grants**: always separate — role defaults are inherited from the role, explicit grants are stored in DB and override
- **Backward-compat aliases**: old coarse strings (e.g. `module.write`) must map to arrays of granular strings via an `$aliases` property so existing code keeps working
- **Controller coverage**: every controller method must call `Auth::requirePermission('module.action')` with the specific granular string
- **View coverage**: every `Auth::can()` call in views must use the specific granular string

## Settings & Branding Standard (Every App / Project)

Every app or project — new or existing, moving forward — **must** include a
**Settings** area that contains a **Branding** section where the user can:

- **Set a logo via URL** (a `logoUrl` text field — paste an image URL). Also
  accept a **file upload stored as a `data:` URL** so it works offline.
- Set the **organization / product display name** (text).
- Set a **primary accent / brand color** (color picker, applied via a CSS custom property).

Branding must be **persisted** and **applied live** across the app:
- Persist wherever the app stores settings: server-side settings when a backend
  exists (shared), and `localStorage` / IndexedDB for static/front-end-only
  hosting (per-browser). The backend value wins when both are present.
- The logo replaces the default brand mark in the header/top bar; the display
  name replaces the default app name (header + document `<title>`); the accent
  color overrides the app's primary CSS custom property.
- The logo also appears in any generated reports / PDF / print output and on
  login/landing screens.

Requirements:
- Provide sensible empty-state defaults (the app's built-in logo/name/accent) so
  unset branding never breaks the UI; a bad/broken logo URL degrades gracefully
  to the default mark.
- **Sanitize** logo URLs (allow only `http(s)://` or `data:image/...`) and escape
  user-supplied strings when injected into markup.
- Follow all existing rules: no inline event handlers, and never hardcode colors
  that should be the accent var.

## Standard Documentation & Deployment Set (Every Project)

Every app or project — new or existing, moving forward — **must** carry the same
standardized documentation + deployment set, kept accurate to the code and
updated as the app changes. This is the format established for AEGIS, Sentinel
QMS, and CITADEL; apply it to every project going forward. Do not invent
commands, env vars, ports, or paths — verify every claim against the real code.

**1. `deployments/` folder — one operator guide per target.** Each of the six
files below must contain, tailored to that target and the app: (1) deployment
architecture, (2) topology diagram, (3) prerequisites, (4) identity &
credentials — **prefer IAM roles / IRSA / managed & workload identity** over
static keys, with a least-privilege policy, (5) environment variables as a
`Variable | Example | Purpose` table, splitting **AWS Commercial vs GovCloud**
(partition `aws` vs `aws-us-gov`, FIPS/regional endpoints) and **Azure
Commercial vs Government** where they differ, (6) configuration references
(`Variable | Example | Purpose`), (7) verification (health, login, secrets
resolved, upload accepted + indexed/scanned, object written), (8) day-2
operations, (9) troubleshooting.
- `deployments/LOCAL_DEVELOPMENT.md`
- `deployments/SINGLE_LINUX_SERVER.md`
- `deployments/KUBERNETES.md`
- `deployments/AZURE.md` (Commercial + Azure Government)
- `deployments/AWS.md` (Commercial + GovCloud)
- `deployments/AIRGAPPED.md` (offline registry/bundles, offline secrets & CVE
  feeds, self-hosted LLM inference via **Ollama** replacing any hosted AI API)

**2. `docs/` folder — four canonical guides.**
- `docs/ARCHITECTURE.md` — the platform it's built on, design principles,
  component overview, monorepo structure (placement + internal layout),
  configuration model, request & error contract, security model, observability,
  deployment topology.
- `docs/DEPLOYMENT.md` — deployment guide + contents/TOC, deployment models,
  prerequisites, configuration & secrets, database migrations (exact commands),
  the worker/background process, **Ollama configuration**, **GPU acceleration**,
  and a production checklist with sub-sections: **Secrets & identity**,
  **Transport & exposure**, **Hardening**, **Resilience & operations**.
- `docs/DISASTER_RECOVERY.md` — what holds state, RPO/RTO targets, backups,
  restore runbook (numbered, copy-pasteable), verification cadence (restore
  drills), high availability.
- `docs/SECURITY.md` — security guide, identity & authentication, authorization,
  data protection, auditability, classification & DLP, FIPS readiness, operator
  responsibilities, secrets rotation, reporting.

**3. Root project files.**
- `Dockerfile` — builds the app (multi-stage, non-root, healthcheck).
- `render.yaml` — a valid Render Blueprint for the project.
- `README.md` — what the app is, why it exists, supported deployment models,
  repo layout, technology, prerequisites, quick start (local development),
  common commands, build status, and all package dependencies & extensions
  required. Cross-links `docs/` and `deployments/`.
- `OPEN_ITEMS.md` — honest production-readiness register (done vs outstanding,
  grouped by theme, each with impact + suggested action).
- `CLAUDE.md` — project guidance for that app, including the standing rule that
  this doc set must be kept current as the app changes.

Every project must be **dockerized (Docker deploy) and Render-deploy
compatible**. Whenever a feature, migration, or config change lands, update the
affected `deployments/`, `docs/`, `README.md`, and `OPEN_ITEMS.md` in the same
change — treat this doc set as part of "done," and add it to every project
roadmap alongside the security and UI audits.

## Other Permanent Rules

- **Header logo links home** — in every app/project, the top-left logo / brand
  mark in the header must be a clickable link to the app's default
  home/dashboard screen (e.g. wrap it in `<a href="<home>">`, or a router link
  for SPAs). Clicking the logo always returns the user to home/dashboard.
- **No inline event handlers** — CSP compliance required at all times
- **Always push to production (main branch)** after completing work
- **Always spawn multiple agents in parallel** for independent subtasks
- **Every project ships the standard doc set** — `deployments/` (×6), `docs/` (×4: Architecture, Deployment, Disaster Recovery, Security), `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml` — and it is kept current (see "Standard Documentation & Deployment Set")
- **Every project must be Docker- and Render-deploy compatible**
- **Every section with file upload** must include a field reference key below it
- **`Database::update()`** automatically appends `updated_at = NOW()` — never include `updated_at` in data arrays passed to it
- **Never commit `.env`** — only `.env.example` with placeholder values
- **`database/schema.sql` must be kept current** — update it whenever a migration is added; it must always represent the full, combined schema across all migrations
