# AEGIS GRC Platform — Executive Overview

> Audience: a brand-new engineering team with zero prior knowledge of this codebase.
> Everything below is derived from the actual source (`index.php`, `views/layout.php`,
> `src/`, `README.md`, the `*.md` design docs, and `render.yaml`). Where a capability is
> only partially built or explicitly *not* implemented, that is called out rather than
> glossed over.

---

## 1. What AEGIS Is

AEGIS is a **self-hosted Governance, Risk, and Compliance (GRC) platform**. It consolidates
the work that organizations normally spread across spreadsheets and several point tools —
compliance tracking, risk management, audit workflows, and policy lifecycle management — into
a single server-rendered web application.

From the README's own summary (`README.md:8`):

> "AEGIS is a self-hosted Governance, Risk, and Compliance (GRC) platform built with PHP 8.2
> and PostgreSQL. It consolidates compliance tracking, risk management, audit workflows, and
> policy lifecycle management into a single cohesive application — with no external framework
> dependencies, no build pipeline, and a single Docker container deployment model."

Concretely, AEGIS is a classic **PHP 8.2 MVC application with no framework and no Composer
dependencies**. A single front controller (`index.php`) handles bootstrap, security headers,
routing, and dispatch; controllers under `controllers/` (51 `*Controller.php` files) drive
server-rendered PHP views under `views/` (41 view directories) wrapped by a shared
`views/layout.php` chrome. It runs on PostgreSQL (the `aegis` schema, separate from `public`)
and deploys as one Docker container, primarily targeting Render.com.

The product is oriented toward **regulated and security-conscious organizations**, with
particularly deep support for **U.S. federal / defense-industrial-base compliance**: it ships
modules for System Security Plans (SSP), POA&M, CUI inventory, SPRS scoring, and NIST
Organization-Defined Parameters (ODP) — the artifacts associated with NIST 800-53 / 800-171 /
CMMC programs — while remaining framework-agnostic (any standard can be imported as JSON/CSV).

---

## 2. Target Users

AEGIS is built for the people who own a GRC program, not just a single function. The
**five built-in roles** (`README.md`, RBAC section) make the intended audience explicit:

| Role | Typical user |
|------|--------------|
| `admin` | Platform owner / GRC administrator — full configuration and user management |
| `manager` | Risk, compliance, or security program manager — owns workflows and approvals |
| `auditor` | Internal/external auditor — conducts audits, reviews findings |
| `analyst` | GRC analyst — day-to-day data entry, assessments, treatments |
| `viewer` | Read-only stakeholder — executives, board members, external reviewers |

On top of these roles, AEGIS layers **per-user, per-module permission overrides** using
granular `module.action` strings (e.g. `risk.accept`, `policy.publish`, `audit.close`) —
see `PERMISSIONS_MODEL.md`. In practice the platform serves:

- **CISOs / security & compliance leaders** who need a single posture view and a board pack.
- **GRC / compliance analysts** doing control implementation, evidence collection, and audits.
- **Risk managers** maintaining a risk register, KRIs, treatments, and appetite thresholds.
- **Auditors** running audit schedules and tracking findings to closure.
- **Vendors / external respondents** via scoped portal links for questionnaires (no login).
- **Executives & boards** consuming the read-only Board Dashboard and reports.

---

## 3. Module Catalog

The module catalog below is taken directly from the sidebar in `views/layout.php` (the
authoritative navigation), grouped exactly as the app groups it. Every module can be hidden
per-deployment via **Module Visibility** settings (`module_hide_*` keys, `layout.php:45-56`).
Route targets are the live `GET` routes registered in `index.php` (`index.php:729-855`).

### Overview
- **Dashboard** (`/`) — KPI rings and Chart.js summaries; the home screen the logo links to.

### Compliance (`layout.php:71-91`)
- **Packages** (`/compliance`) — compliance packages against any imported standard; controls,
  evidence, policy linkage, bulk assess/status operations.
- **Import Standard** (`/compliance/import`) — multi-format import (JSON, CSV, XLSX, PDF, manual).
- **Bulk Import** (`/import`) — cross-module bulk data import.
- **Control Testing** (`/compliance/testing`) — control test dashboard.
- **Gap Analysis** (`/compliance/gap-analysis`) — coverage / gap reporting.
- **Sec. Plans (SSP)** (`/ssp`) — System Security Plans (multi-tab authoring; see below).
- **POA&M** (`/poam`) — Plans of Action & Milestones.
- **Audit Findings** (`/audit-findings`) — external/pen-test/certification/regulatory findings.
- **ODP Center** (`/odp`) — NIST Organization-Defined Parameters.
- **SPRS Score** (`/sprs`) — Supplier Performance Risk System scoring.
- **RACI Matrix** (`/raci`) — responsibility assignment per package/control.

### Operations (`layout.php:93-113`)
- **Audits** (`/audit`) — schedule and conduct audits, checklist scoring, recurring schedules.
- **Policies** (`/policy`) — draft/version/approve/publish/review; control mapping; attestations.
- **Playbooks** (`/playbooks`) — operational playbook library linked to incidents/risks.
- **Issues** (`/issue`) — issue register linked to compliance, risk, and audit entities.
- **BCP / DR** (`/bcp`) — business continuity & disaster recovery plans.
- **Incident SLA** (`/incident/sla`) — incident SLA tracking and reporting.
- **Questionnaires** (`/questionnaire`) — questionnaire builder for vendor/internal assessments.
- **Awareness Training** (`/awareness`) — training programs, assignment, completion tracking.
- **Data Privacy** (`/privacy`) — privacy impact assessments and data-handling / DSAR records.
- **GRC Projects** (`/projects`) — project tracking for GRC initiatives, with tasks/links.

### Risk (`layout.php:115-135`)
- **Risk Register** (`/risk`) — score risks on a configurable likelihood × impact matrix.
- **Risk Matrix** (`/risk/matrix`) — interactive 5×5 heatmap.
- **Treatment Roadmap** (`/risk/roadmap`) — Kanban-style treatment planning.
- **Exceptions** (`/risk/exceptions`) — formal risk exception requests with approvals.
- **Threat Register** (`/threats`) — threat catalogue with severity/status.
- **Treatment Plans** (`/treatment`) — structured treatment actions linked to risks.
- **KRI Dashboard** (`/kris`) — Key Risk Indicators with threshold breach alerting.
- **Vendor Risk** (`/vendor`) — vendor register, risk tier, data-access, assessments, portals.
- **Contracts** (`/vendor/contracts`) — vendor contract tracking.
- **Asset Inventory** (`/assets`) — asset register with risk linking and categorization.

> Related risk surfaces that are not their own sidebar entry but exist as routes/controllers:
> **Risk Reviews** (`/risk/reviews`), **Risk Acceptances** (`/risk-acceptances`), **Risk
> Scenarios** (`/risk/scenarios`), and **BowTie** diagrams (`BowTieController`).

### Analytics (`layout.php:137-154`)
- **Metrics & Trends** (`/metrics`) — compliance %, risk trends, audit scores (Chart.js).
- **Documents** (`/documents`) — document store with version upload history.
- **Reports** (`/report`) — compliance / executive / risk report generation.
- **Board Dashboard** (`/report/board`, alias `/report/board-pack`) — the read-only **board pack**.
- **Export** (`/export`) — per-module CSV/XLSX exports plus a full-platform ZIP bundle.
- **Calendar** (`/calendar`) — GRC event / due-date calendar (with an iCal-style feed).
- **Custom Dashboards** (`/dashboards`) — user-configurable dashboard widgets.

### GRC Tools (`layout.php:156-168`)
- **Automation Rules** (`/automation`) — rules engine (triggers + action chains, templates).
- **CUI Inventory** (`/cui`) — Controlled Unclassified Information inventory.

### Resources (`layout.php:170-182`)
- **Search** (`/search`) — global cross-module search.
- **Documentation** (`/docs`) — in-app documentation.

### Administration (admin-only; `layout.php:184-216`)
A full **platform admin** area: Overview, Users, Permissions (granular `module.action`
editor), Workflows, Approval Templates, Risk Matrix, Risk Appetite, Alerts, API Keys,
Webhooks, Email Settings, System Settings, Module Visibility, **SSO / OIDC**, Security Policy,
Activity Logs, Sessions, Storage, Data Retention, Custom Fields, Tags, SLA Policy, and Policy
Attestations.

### Account (every user; `layout.php:218-244`)
Approvals (with pending-count badge), Notifications, Edit Profile, My Attestations,
Two-Factor Auth (TOTP) setup, and MFA Backup Codes.

> **SSP detail.** The System Security Plan module supports a 7-tab authoring view (Overview,
> Approval, Organization, Boundary, Environment, Inventory, Compliance), multiple presentation
> modes (Standard, Military, Corporate, Air Force, DoD), JSONB-backed hardware/software/network/
> server/data inventories, versioning, and authorization signatures (`README.md`, SSP section).

> **Platform / multi-tenant admin.** A `PlatformController` and a `/platform/tenants` view
> (`views/platform/tenants.php`) exist for tenant administration and tenant switching
> (`/platform/switch-tenant`, `/platform/exit-tenant`). See the multitenancy note in §5.

---

## 4. Tech Stack at a Glance

From `README.md` (Tech Stack section), `render.yaml`, and the source:

**Backend**
- **PHP 8.2**, `declare(strict_types=1)`, **no framework, zero Composer dependencies**.
- Hand-rolled **front controller + array-based router** in `index.php` (~407 routes across
  static and regex/dynamic GET/POST tables; ~411 `=>` route entries counted in the route block).
- **PostgreSQL** via PDO / `pdo_pgsql`, using a dedicated `aegis` schema (isolated from `public`).
- **Auth:** session-based + pure-PHP **HS256 JWT** (`src/JWT.php`); **Argon2ID** password hashing;
  **TOTP MFA** with backup codes (`src/TOTP.php`); **OIDC SSO** (`src/SSO.php`).
- **21 core classes** in `src/`: `Security`, `Auth`, `Database`, `JWT`, `TOTP`, `SSO`, `Ssrf`,
  `Storage`, `Secrets`, `Kms`, `Mailer`, `Webhook`, `RiskScore`, `Branding`, `CustomFields`,
  `Csv`, `DueStatus`, `PgSessionHandler`, `AIAdvisor`, `Errors`.
- **Export** via built-in `ZipArchive` + manual XLSX generation (no external libraries).
- **Secrets / KMS:** `*_FILE` secret mounts resolved at boot (`Secrets::hydrate()`); optional
  KMS envelope-encryption of the app data key (`Kms::hydrate()`).

**Frontend**
- **Vanilla HTML5/CSS3 and ES2020** — no framework, no bundler, **no build step**.
- **Chart.js 4.4.3** for visualizations; **Bootstrap 5.3.3 CSS** + **Bootstrap Icons** (CDN,
  Subresource-Integrity pinned); single `public/css/app.css` + `public/js/app.js`, cache-busted
  by file mtime (`layout.php:10-16`).
- Theming via **CSS custom properties**; full dark/light toggle persisted in `localStorage`.
- All interactivity uses **`data-*` attributes + event delegation in `app.js`** — no inline
  event handlers (a hard CSP requirement; see §5).

**Infrastructure**
- **Docker** (`php:8.2-apache` base) with `mod_rewrite` + `mod_headers`.
- **Render.com** web service defined by `render.yaml`; `startup.sh` runs the idempotent
  `install.php` then starts Apache. Health checks at `/healthz` (live) and `/readyz` (ready).
- Required env at boot, enforced by startup guards (`index.php:91-105`): `JWT_SECRET`
  (≥32 chars), a database (`DATABASE_URL` or `DB_*`), and `APP_URL` in production.

---

## 5. Key Differentiators

### Tamper-evident, immutable audit chain
Every actioned event is written to `activity_log` and bound to the previous record through a
**keyed hash chain** (`AUDIT_TRAIL.md`). Each row's `log_hash` is an **HMAC-SHA256** over
`prev_hash | user_id | action | entity_type | entity_id | changes | ip`, keyed with a dedicated
`AUDIT_HMAC_KEY`. Because it is *keyed* (not a plain hash), an attacker who can write the
database but cannot read the key **cannot forge the chain**. Appends are serialized with a
PostgreSQL advisory lock so concurrent requests cannot fork the chain, and the verifier accepts
both the keyed HMAC and a legacy unkeyed SHA-256 so historical rows still validate. This gives
AEGIS a defensible, verifiable record of who did what and when.

### Nonce-based, `unsafe-inline`-free CSP
`Security::setSecurityHeaders()` (`src/Security.php:337-374`) emits a strict
**Content-Security-Policy** with a per-request **script nonce** and **no `'unsafe-inline'`** for
scripts: `script-src 'self' 'nonce-…'` — no external origin, since all JavaScript is vendored
locally. Every `<script>` tag carries `nonce="<?= Security::nonce() ?>"`, and all event handlers
are delegated through `data-*` attributes in `app.js` — there are no inline `onclick`/`onchange`
handlers anywhere. The one remaining CDN asset (the Bootstrap stylesheet, allowed only in
`style-src`) is Subresource-Integrity pinned. The response also sets `X-Frame-Options:
DENY`, `frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, a strict `Referrer-Policy`,
cross-origin isolation headers, and a locked-down `Permissions-Policy`.

### Security-first by construction
PDO prepared statements throughout (no string-concatenated SQL), CSRF tokens on every POST,
Argon2ID hashing, brute-force lockout, TOTP MFA, SSRF protection (`src/Ssrf.php`) on outbound
webhooks/branding-logo fetches, randomized evidence filenames with SHA-256 integrity hashing
and PHP-gated downloads, and operator-safe error handling that keeps internal detail in logs
keyed by a per-request correlation ID (`index.php:11-64`).

### Governed, opt-in AI
`AIAdvisor` (`AIADVISOR.md`) provides **advisory-only** AI (control-gap remediation suggestions
and executive narratives). It is opt-in (disabled until an admin configures a provider/key),
has a **global kill-switch**, applies data-minimization/redaction before sending data, never
writes records, and logs all AI use to the tamper-evident audit trail (ISO 42001 / NIST AI RMF
aligned).

### Row-Level-Security multitenancy — *scaffolded, not yet fully enforced*
This is the one area where the marketing framing and the code diverge, so it is stated plainly.
`MULTI_TENANCY.md` declares the current status: **"NOT IMPLEMENTED — AEGIS is single-tenant per
deployment (one organization per instance/database)."** The intended end-state is
defense-in-depth: application scoping **and** PostgreSQL **Row-Level Security** (so a forgotten
`WHERE` clause or even a SQLi cannot leak another tenant's data). The foundations are already in
the codebase — a `tenants` table (migration 026), `Database::setTenant()/currentTenant()/
clearTenant()` GUC plumbing (`src/Database.php:69-93`), auto-stamping of `tenant_id` on inserts
into a defined set of tenant-owned tables, an RLS policy template (`database/tenancy/
rls_template.sql`), and a `PlatformController` + `/platform/tenants` admin surface — but RLS is
not yet enforced across all tables. **New engineers should treat AEGIS as single-tenant today
and follow the phased adoption plan in `MULTI_TENANCY.md` before relying on tenant isolation.**

---

## 6. How It All Fits Together

A request enters through `index.php`, the single front controller (all URLs rewrite to it via
`.htaccess`). It boots the environment (resolving `*_FILE` secrets and optional KMS-wrapped
keys), enforces startup guards for `JWT_SECRET` / database / `APP_URL`, hardens the session,
and sets the nonce-based CSP and security headers via `Security`. `Auth` resolves the session
(or JWT/API key for the REST API), the router matches the URI against static and regex route
tables and dispatches to one of the 51 controllers, and the controller calls
`Auth::requireAuth()`/`requirePermission()`, runs parameterized PDO queries through `Database`,
records any state change to the keyed hash-chained `activity_log`, renders a server-side PHP
view, and wraps it in `views/layout.php` — whose sidebar exposes the full module catalog
(Compliance, Operations, Risk, Analytics, GRC Tools, Resources, Administration, Account),
filtered by role and per-deployment module-visibility settings. The whole thing ships as one
zero-dependency Docker image to Render, with Chart.js dashboards and a board pack on top and a
tamper-evident audit trail underneath — a complete GRC program in a single, auditable, self-
hosted application.
