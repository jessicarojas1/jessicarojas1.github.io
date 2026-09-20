# REDOUBT — Open Items / Production-Readiness Register

Honest status as of 2026-09-20. REDOUBT is a **working application** (standalone
with local auth + a database; Entra/Graph optional). The list below separates what
is **done** from what is **outstanding**, grouped by theme, with impact + action.

## Done (to date)

- Product & Architecture Discovery Package (served at `/`), incl. annexes for
  extreme IAM, module-architecture standard, API & webhooks, and differentiators.
- Deployable PHP scaffold: front controller/router, strict security headers + CSP
  nonce, `/health`, PSR-4 bootstrap (runs with or without Composer).
- **Phase 1 framework services (skeleton):** `Config`, `Session`, `Security`
  (nonce/escape/CSRF), `Db` (PDO/PostgreSQL, parameterized, auto `updated_at`),
  `Roles` (granular perms + aliases), `Authorize` (program×company×role×zone +
  US-person export gate), `Oidc` (Entra GCC High, PKCE, JWKS RS256), `Auth`,
  `Graph` (GCC High client-credentials), `Audit`, `ApiKey`, `Webhooks`.
- **HTTP:** `AuthController` (login/callback/logout), `AppController`
  (authenticated role-aware home), `ApiRouter` (`/api/v1`, permission-aware).
- Docker (multi-stage, non-root, healthcheck, `pdo_pgsql`) + Render blueprint (non-CUI).
- `database/schema.sql` extended: `user_permission_grant`, `api_client`,
  `webhook_subscription`, `webhook_delivery`, `integration_connector`, plus
  export-control columns.
- `.env.example` (GCC High endpoints); core docs + all deployment guides
  (KUBERNETES primary, AZURE, AWS, SINGLE_LINUX_SERVER, AIRGAPPED, LOCAL_DEVELOPMENT).

## Blocking decisions (must resolve before build)

**Decided:** CUI and ITAR/export-controlled data are **in scope**. **Runtime is
Kubernetes**, portable across three authorized boundaries — **on-prem**, **Azure
Government (AKS)**, and **AWS GovCloud (EKS)** — with identity + documents always
in **Microsoft 365 / Azure GCC High**. **Customer/COR access is contractually
authorized** (Customer/COR library is in MVP). **Export-control gates are a build
requirement.** The blockers below flow from these.

| Item | Impact | Suggested action |
|------|--------|------------------|
| Which boundary(s) first + app-tier ATO | Each of on-prem/Azure Gov/AWS GovCloud must meet 800-171/CMMC (network, FIPS, boundary) | Cyber + Enterprise Systems select + assess before go-live |
| Export-control gate definition | Authoritative US-person source + which zones are ITAR/EAR + license scoping | Cyber + Export/Empowered Official |
| GCC High tenant + Entra app registration | Document SoR + identity for controlled data | Enterprise Systems provision tenant + app reg |
| Egress path app → GCC High | Graph/auth connectivity per target (ExpressRoute / Gov egress / cross-cloud) | Network/Cyber decide + allowlist `.us` endpoints |
| COR onboarding terms | Flow-downs + customer-approved document set | Contracts confirm terms |
| Entra + B2B licensed/approved (GCC High) | External identity design | Enterprise Systems confirm |

## Outstanding — Documentation & deployment set (per repo standard)

| Item | Impact | Suggested action |
|------|--------|------------------|
| `deployments/KUBERNETES.md` | Primary runtime (on-prem / AKS / EKS) | **Done** |
| `deployments/AZURE.md` | M365 GCC High back end (all targets) + AKS hosting | **Done** |
| `deployments/AWS.md` | AWS GovCloud (EKS) hosting → M365 GCC High | **Done** |
| `deployments/SINGLE_LINUX_SERVER.md` | Fallback / small footprint | **Done** |
| `deployments/AIRGAPPED.md` | Fully offline enclave + self-hosted LLM (Ollama) | **Done** |

## Outstanding — Application (Phase 1 / MVP)

**Done (built + verified):**
- **Two-pane IAM console** (Annex G): `/app/admin/iam` — user list w/ search, per-module
  accordions, role-default/grant/deny 3-way controls, Grant-all/Clear, Expand/Collapse,
  AJAX save with CSRF rotation + audit (`IamController`, `app_iam.php`, `iam.js`, `PermissionCatalog`).
- **Announcements module** (Annex H) end-to-end: `/app/announcements` (list/create/edit/
  publish/delete, permission-checked per action, audience-trimmed) + real
  `GET /api/v1/announcements` (permission-trimmed for user or API client) + signed webhook
  on publish + audit (`Announcements`, `AnnouncementsController`, `app_announcements.php`).
- **Documents module** (Annex H): `/app/documents` (register/edit/delete + zone filters),
  gated per zone through Authorize incl. the **US-person export gate** and **company
  scoping**; `open()` re-authorizes before redirecting (defense-in-depth for CUI/ITAR);
  `GET /api/v1/documents` (export-controlled excluded for API clients); Graph client
  ready for live SharePoint resolve (`Documents`, `DocumentsController`, `app_documents.php`).
- **Task Orders module** (Annex H): `/app/task-orders` (create/edit/award/delete),
  program- and company-scoped; award emits `taskorder.awarded` webhook + audit;
  `GET /api/v1/task-orders` (`TaskOrders`, `TaskOrdersController`, `app_taskorders.php`).
- **Jobs module** (Annex H): `/app/jobs` (create/edit/post/close/delete), draft→open
  workflow; posting emits `job.posted` webhook + audit; `GET /api/v1/jobs`.
  **Full targeting**: program-wide / by-company / by-contract (task order),
  settable per req and **filterable** in the list (All / Program-wide / Targeted /
  My company / per-contract); server-side visibility so contractors see only the
  openings for their company/contract (`Jobs`, `JobsController`, `app_jobs.php`).
- **Directory module** (Annex H): `/app/directory` — visibility-trimmed contacts,
  manage via `contact.manage`; `GET /api/v1/directory` (`Directory`, `DirectoryController`).
- **Search** (§13): `/app/search` + `GET /api/v1/search` — global and
  **permission-trimmed** by reusing each module's own authorized listing, so it
  can never surface anything the caller cannot access (`Search`, `SearchController`).
- Shared `Audience` helper for audience/visibility token evaluation.

**All 7 MVP modules are now built** (IAM, Announcements, Documents, Task Orders,
Jobs, Directory, Search).
- **Notifications** (in-portal): publish events (announcement / task-order award /
  job posting) fan out to exactly the members authorized to see the item (reusing
  each module's visibility predicate — never leaks); notification center
  `/app/notifications` (open / mark-read / mark-all) + header bell with unread
  count (`Notifications`, `NotificationsController`, `app_notifications.php`).
- **Settings + Branding** (mandatory standard): `/app/admin/settings` — per-program
  logo (URL or uploaded data: URL), display name, accent color, applied **live** in
  the header (accent var, brand mark, name); plus a program-level job-targeting
  default. Sanitized (logo http(s)/data:image only; accent hex only); persisted to
  `program_config` (`Settings`, `SettingsController`, `app_settings.php`, `settings.js`).
- **Content Administration console** (§15): `/app/admin/content` — one hub that
  aggregates only the content types the user may author, with visible counts and
  quick links; grants no authority of its own (`ContentAdminController`).
- **Automated test suite** (zero-dependency): `php tests/run.php` — 82 checks
  (15 authorization-engine logic + 67 live-DB assertions: modules, job targeting,
  Settings/Branding sanitization, notification fan-out, onboarding lifecycle, local
  auth, sample loader) via `tests/lib/T` + `tests/lib/Seed`; DB tests self-skip
  unless `REDOUBT_TEST_DB=1`. An end-to-end HTTP flow (setup → local login → app)
  is also verified.
- **CI**: `.github/workflows/redoubt-ci.yml` lints every PHP file and runs the full
  suite against a PostgreSQL 16 service on changes under `redoubt/`.

- **Onboarding / offboarding** (`/app/admin/access`): sponsored access lifecycle —
  request (`access.request`) → approve/deny (`access.grant`, provisions app_user +
  program/company membership with US-person attestation) → offboard
  (`access.revoke`, removes membership; marks account removed when none remain).
  All audited (`AccessRequests`, `AccessController`, `app_access.php`). Entra B2B
  invite + session-kill on offboard remain a follow-on Graph/Entra step.

- **Standalone operation (no external API):** built-in **local email/password**
  auth (`Auth::attemptLocal`/`checkLocalCredentials`) + **first-run `/setup`**
  (creates program + enterprise admin, optional sample data) + **login page**;
  Entra SSO is now optional (a "Sign in with Microsoft" button when configured).
  `/` → app/sign-in; discovery docs moved to `/about` (linked as "Docs" in-app).
  Verified end to end (setup → local login → app → sample content → live branding).
- **Sample data loader** (`Sample::load`) populates one program across every module.

**Phase 2 (next):**

| Item | Impact | Suggested action |
|------|--------|------------------|
| Local document upload/storage | Documents open without SharePoint/Graph | Store uploaded files (DB/object store); `open()` serves with authz |
| Milestones / Calendar, FAQ, Quick Links modules | Remaining content modules | Per module standard (Annex H) |

**Ongoing / ops:**

| Item | Impact | Suggested action |
|------|--------|------------------|
| **Security review of `Oidc`** | Hand-rolled token verification | Review before enabling prod sign-in; consider a vetted JOSE lib |
| Live Graph document resolve (Sites.Selected) | Metadata + gating done; live SharePoint open needs creds | Test `Documents::resolveOpenUrl` against a real drive/site |
| Remaining API module endpoints (jobs/contacts/milestones/search) | Replace 501 stubs | Implement per Annex I, permission-trimmed |
| Webhook admin UI + durable retry queue/worker | Reliable delivery | Move `Webhooks::dispatch` behind a queue |
| Live Entra GCC High sign-in test | Only auth path still unexercised | Run against a real app registration |
| CD (deploy), dev/test/prod envs, secrets management, image signing | Ops (CI test/lint now in place) | DevSecOps |

## Notes / known limitations

- **Verified (2026-09-19):** `php -l` passes on all files (PHP 8.5.10); config-free
  routes behave correctly (`/`, `/health`, `/app`→login 302, `/auth/login` 503
  unconfigured, `/api` 401, 404, CSP+nonce, IAM save POST-only 405); the
  **authorization engine passed 15/15** logic assertions (export gate, grant/deny
  denials-win, company scoping, membership, aliases); and the **data path passed
  12/12** against a live PostgreSQL 16 (schema.sql loads clean; announcement
  create/update/publish; JSONB round-trip; `string_agg` user/roles query; IAM
  grant `ON CONFLICT` upsert→deny→delete; webhook_delivery + audit rows written).
  **Documents + Task Orders: 17/17** against a live PostgreSQL 16 — zone gating,
  company scoping, the US-person export gate (non-US-person blocked from an ITAR
  doc via list/canSee/API), `open()` re-authorization, and the `taskorder.awarded`
  webhook. **Jobs + Directory + Search: 13/13** against a live DB — draft→open +
  `job.posted` webhook, visibility-trimmed directory, and **permission-trimmed
  search that does not leak the ITAR doc to a non-US person**. Bugs caught & fixed
  These checks are now a **repeatable suite** (`php tests/run.php`, 82 checks —
  incl. job targeting by company/contract/program-wide + filters, and
  Settings/Branding persistence + sanitization) enforced in CI. Bugs caught & fixed in the process: PHP `bool` bound via native
  prepares became `''` (Db now emits `true`/`false`); webhook `@>` needed `::jsonb`;
  reused `:pid` broke native prepares; `Db::update` now appends `updated_at` only
  when the column exists (+ `updated_at` on `announcement`/`app_user`/`task_order`).
  **Only path still unexercised:** live Entra GCC High sign-in + live Graph
  document resolve (both need real credentials).
- `Oidc` token verification is hand-rolled and MUST be security-reviewed before
  production sign-in (see `app/Support/README.md`).
- `Webhooks` dispatch is synchronous; a durable retry queue is Phase 2.
- Discovery microsite loads Mermaid from a CDN for two diagrams; it degrades to
  showing diagram source if blocked (air-gap friendly). Phase 1 should vendor or
  pre-render diagrams for a fully self-contained enclave build.
- `render.yaml` is scoped to a non-CUI pilot boundary by design.
