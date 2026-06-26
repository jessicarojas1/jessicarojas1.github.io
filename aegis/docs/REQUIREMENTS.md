# AEGIS GRC — Functional Requirements (Inferred from Implementation)

> **Status of this document:** Every requirement below is marked **_Inferred from implementation_**. There is no separate, authoritative product specification in the repository; these requirements were reverse-engineered by reading the actual PHP source (`index.php`, `controllers/*.php`, `src/*.php`, `database/migrations/*.sql`, `config/app.php`). Where the implementation is ambiguous or a behavior is absent, that is stated explicitly. File paths and (where useful) line numbers are cited so a new engineer can trace any requirement back to the code that produced it.

---

## 1. Document Overview

### 1.1 Purpose

AEGIS is a server-rendered **Governance, Risk, and Compliance (GRC)** platform built in PHP 8.2 using a hand-rolled MVC pattern (no framework, no Composer dependencies). It persists data in PostgreSQL with **row-level-security (RLS) multitenancy** and renders HTML server-side, enhanced with a vanilla `app.js` / `app.css` front end. It is deployed on Render via Docker.

This document captures **what the system does** at a functional level, expressed as testable requirements, so that a new engineering team can understand, maintain, and extend the platform.

### 1.2 Architecture Context (grounding for the requirements)

- **Front controller:** `index.php` (~1,222 lines) bootstraps the app, loads environment/secrets, runs idempotent runtime schema migrations, sets security headers, binds the per-request tenant, then dispatches to a controller via a static route table (`$routes`) plus regex `$dynamicRoutes`. Roughly 400+ route entries exist across `GET` and `POST`.
- **Controllers:** 51 `*Controller.php` files in `controllers/`. Every controller action is a public method invoked by `dispatch()` (`index.php:1151`). `dispatch()` coerces regex-captured path params to the method's declared scalar types via reflection (`index.php:1158-1165`).
- **Core services:** 21 classes in `src/` — notably `Security`, `Auth`, `Database`, `JWT`, `TOTP` (referenced via `AuthController`), `SSO`, `Ssrf`, `Storage`, `Secrets`, `Kms`, `Mailer`, `Webhook`, `RiskScore`, `Branding`, `CustomFields`, `Csv`, `DueStatus`, `PgSessionHandler`, `AIAdvisor`, `Errors`.
- **Views:** 41 directories of server-rendered `*.php` templates under `views/`, sharing `views/layout.php`.
- **API:** A separate JSON API is mounted under `/api/` (`index.php:722-726`), with Swagger UI at `/api/docs` (`index.php:717-720`). Both are dispatched outside the main route table.

### 1.3 Cross-Cutting Conventions (apply to all modules)

These conventions are observed in essentially every controller and are therefore stated once and referenced by module requirements as **"the standard control envelope."**

- **Authentication / authorization:** read actions call `Auth::requirePermission('<module>.<action>')`; mutating actions do the same before any side effect (`Auth.php:430-437`). `Auth::requireAuth()` (`Auth.php:363-428`) enforces session validity, idle timeout, server-side revocation, forced password change, and password expiry.
- **CSRF:** every `POST` action validates `Security::validateCsrf($_POST['csrf_token'] ?? '')` and returns HTTP 403 on failure (`Security.php:27-38`). Tokens are single-use (rotated after successful validation) and expire after `csrf_lifetime` (2 hours, `config/app.php:8`).
- **Input handling:** free-text inputs pass through `Security::sanitizeInput()` (`Security.php:49-51`); rich HTML through `Security::sanitizeHtml()` (`Security.php:58-129`); numeric/enum inputs are clamped or allow-listed in the controller.
- **Output encoding:** all dynamic output is escaped with `Security::h()` (`Security.php:45-47`) in views.
- **Audit logging:** state changes call `Auth::log($action, $entityType, $entityId, $changes)` which appends to a tamper-evident HMAC hash chain (`Auth.php:506-548`).
- **Persistence:** parameterized queries only, via `Database::query/fetchOne/fetchAll/insert/update` (no string concatenation of user input into SQL).
- **Tenancy:** every request for an authenticated user binds `Auth::activeTenantId()` to the DB session for RLS filtering and write stamping (`index.php:1179-1187`).

---

## 2. Authentication & Session Management

**Source:** `controllers/AuthController.php`, `src/Auth.php`, `src/Security.php`, `config/app.php`.

### REQ-AUTH-001 — Email/password login _(Inferred from implementation)_
- **Description:** Users authenticate with email and password at `POST /login`.
- **Business objective:** Restrict access to authorized personnel and establish an auditable identity for all subsequent actions.
- **Expected behavior:** `AuthController::login()` validates CSRF, requires both fields, lowercases/trims the email, and delegates to `Auth::login()`. `Auth::login()` rate-limits by client IP and by hashed email, looks up an **active** user, and verifies the password hash (`Auth.php:443-477`). On success it regenerates the session ID, populates `$_SESSION['user']`, updates `last_login`, and writes a `login` audit entry.
- **Inputs:** `email`, `password`, `csrf_token`.
- **Outputs:** Redirect to the post-login target (`/` or a validated stored redirect); flash error otherwise.
- **Validation:** CSRF required; both fields required; account must exist and be `is_active = TRUE`; Argon2id password verification (`Security.php:131-141`).
- **Security considerations:** Failed logins are audit-logged with the attempted email (`Auth.php:452-457`). Session fixation prevented by `session_regenerate_id(true)`. Stored post-login redirect is re-validated against a path regex and may not target `/admin`, `/login`, or `/mfa` (`AuthController.php:62-68`) — open-redirect protection.
- **Dependencies:** `users` table; `rate_limits` table; `activity_log`.
- **Acceptance criteria:**
  - Given valid credentials for an active user, the user is logged in and a `login` row is appended to `activity_log`.
  - Given an inactive user or wrong password, login fails and a `login_failed` audit row is written.
  - A missing/invalid CSRF token redirects to `/login` with an error and no authentication occurs.

### REQ-AUTH-002 — Brute-force rate limiting & lockout _(Inferred from implementation)_
- **Description:** Login attempts are throttled per IP and per email.
- **Business objective:** Mitigate credential-stuffing / brute-force attacks.
- **Expected behavior:** `Security::checkRateLimit()` (`Security.php:291-321`) allows up to `login_attempts` (5) per `window_seconds` (300s) window; exceeding it sets `blocked_until` = now + `lockout_seconds` (900s) (`config/app.php:9-13`). On successful login the IP limiter is reset.
- **Inputs:** rate-limit key (`login_<ip>`, `login_email_<sha256(email)>`).
- **Outputs:** Boolean allow/deny; persisted counters in `rate_limits`.
- **Validation:** Window expiry resets the counter; block window enforced.
- **Security considerations:** Email is hashed before being used as a key, avoiding plaintext-email storage in the limiter.
- **Acceptance criteria:** After 5 failed attempts within 5 minutes from one IP, the 6th attempt is denied until 15 minutes elapse.

### REQ-AUTH-003 — Multi-factor authentication (TOTP) _(Inferred from implementation)_
- **Description:** Users may enable TOTP-based MFA; certain roles may have MFA enforced.
- **Business objective:** Strengthen authentication for privileged access (aligns with NIST 800-171 IA controls, per code comments).
- **Expected behavior:** After password success, if the user has `mfa_enabled` + `mfa_secret`, the session is reduced to an `mfa_pending` state and the user is redirected to `/mfa/verify` (`AuthController.php:42-58`). If a role appears in the `mfa_enforcement` setting but the user has no MFA configured, they are forced into `/mfa/setup` (`AuthController.php:32-41`). Setup, verify, disable, and backup-code flows exist (`/mfa/setup`, `/mfa/setup/verify`, `/mfa/verify`, `/mfa/disable`, `/mfa/backup-codes`, `/mfa/backup-verify`).
- **Inputs:** TOTP code; backup code; `csrf_token`.
- **Outputs:** Completed authentication; QR/secret provisioning on setup; backup codes on generation.
- **Validation:** TOTP replay prevented via `totp_used_codes` (unique `user_id, window_counter`) created at `index.php:316-333`.
- **Security considerations:** MFA pending state destroys and recreates the session so an un-verified user holds no authenticated session.
- **Acceptance criteria:**
  - A user with MFA enabled cannot reach authenticated pages until a valid TOTP/backup code is submitted.
  - The same TOTP code cannot be reused within its validity window.
  - A user whose role is listed in `mfa_enforcement` and lacks MFA is redirected to setup before any other page.

### REQ-AUTH-004 — Session lifetime, idle timeout & server-side revocation _(Inferred from implementation)_
- **Description:** Sessions expire on inactivity and can be invalidated server-side.
- **Business objective:** Limit exposure of abandoned sessions; allow administrators to revoke access immediately (SOC 2 CC6.5 / NIST AC-2 per comments).
- **Expected behavior:** `Auth::requireAuth()` logs the user out if `last_activity` exceeds `session_lifetime` (8 hours, `config/app.php:7`). On every protected request it re-checks the DB: if the account is deactivated, or `sessions_revoked_at` is later than login time, the session is destroyed and redirected with a reason code (`Auth.php:379-396`).
- **Inputs:** Session state; `users.sessions_revoked_at`, `users.is_active`.
- **Outputs:** Redirect to `/login?reason=timeout|account_disabled|revoked`.
- **Security considerations:** Cookies set `HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS, with `__Host-` prefix (`index.php:111-123`).
- **Dependencies:** `users`, `active_sessions`, optional `PgSessionHandler` for shared sessions (`SESSION_DRIVER=pg`).
- **Acceptance criteria:** After an admin sets `sessions_revoked_at` to now, the targeted user's next request forces a logout.

### REQ-AUTH-005 — Forced password change & password expiry _(Inferred from implementation)_
- **Description:** Users can be required to change their password before continuing, and passwords can expire after a configurable number of days.
- **Business objective:** Enforce first-login credential rotation and periodic rotation (NIST 800-171 3.5.6 per comments).
- **Expected behavior:** If `users.force_password_change` is set, all routes except `/profile/edit`, `/logout`, `/login` redirect to `/profile/edit` with a warning (`Auth.php:398-407`). If the `password_expiry_days` setting > 0 and the password is older, the same redirect occurs (`Auth.php:408-424`).
- **Inputs:** `users.force_password_change`, `users.password_changed_at`, `settings.password_expiry_days`.
- **Outputs:** Forced redirect to profile edit.
- **Acceptance criteria:** A user flagged for forced change cannot view any module page until the password is updated.

### REQ-AUTH-006 — Password reset & forgot-password _(Inferred from implementation)_
- **Description:** Users can request a password reset link and set a new password via token.
- **Business objective:** Self-service credential recovery without admin intervention.
- **Expected behavior:** `GET/POST /forgot-password` and tokenized `GET/POST /reset-password/<token>` (`index.php:804, 903, 970, 1062`). The token route accepts base64url-style tokens.
- **Validation:** New passwords are validated against the configurable policy (`Security::validatePasswordPolicy()`, `Security.php:160-195`): min length (default 12), uppercase, number, special character.
- **Security considerations:** Reset tokens are constrained by a strict character-class regex in routing; password history (`password_history` table, `index.php:393-409`) supports reuse prevention.
- **Acceptance criteria:** A password that fails the configured policy is rejected with field-level errors; a valid reset completes and updates `password_changed_at`.

### REQ-AUTH-007 — Single Sign-On (SSO) _(Inferred from implementation)_
- **Description:** Optional SSO login via `SSOController` (`/sso/login`, `/sso/callback`) configured under `/admin/settings/sso`.
- **Business objective:** Federated authentication / centralized identity.
- **Expected behavior:** Login initiation, provider callback, and admin-side settings save (`index.php:778-780, 886`). Role mapping uses the canonical `Auth::ROLES` map (`Auth.php:144-153`).
- **Dependencies:** `src/SSO.php`.
- **Acceptance criteria:** SSO settings can be saved by an admin; the callback establishes an authenticated session mapped to a valid role.

---

## 3. Authorization, Roles & Permissions (IAM)

**Source:** `src/Auth.php`, `controllers/AdminController.php` (`permissions`, `updatePermissions`).

### REQ-IAM-001 — Role-based default permissions _(Inferred from implementation)_
- **Description:** Eight roles grant a fixed set of `module.action` permissions by default.
- **Business objective:** Least-privilege access aligned to job function.
- **Expected behavior:** Roles are `admin, manager, auditor, control_owner, risk_owner, analyst, executive, viewer` (`Auth.php:144-153`). `admin` implicitly has `*` (all permissions; `Auth.php:173`, `can()` short-circuits at `Auth.php:330`). Other roles' defaults are defined in `$roleDefaults` (`Auth.php:5-138`) and flattened by `roleDefaultPermissions()` into `module.action` strings.
- **Inputs:** Role string from session.
- **Outputs:** Boolean permission decisions from `Auth::can()`.
- **Validation:** `isValidRole()` gates role assignment (`Auth.php:162-165`).
- **Acceptance criteria:**
  - A `viewer` can pass `risk.view` but not `risk.create`.
  - A `manager` can pass `risk.delete`, `compliance.import`, `policy.publish`.
  - An `admin` passes every permission check.

### REQ-IAM-002 — Explicit per-user permission grants (override layer) _(Inferred from implementation)_
- **Description:** Administrators can grant individual permissions beyond a user's role defaults.
- **Business objective:** Fine-grained exceptions without inventing custom roles.
- **Expected behavior:** `Auth::can()` merges role defaults with explicit grants loaded from `user_permissions` (`Auth.php:336-350`), cached per request. `POST /admin/permissions/<id>/update` persists grants.
- **Inputs:** `user_permissions(user_id, module, permission)`.
- **Outputs:** Effective permission set = role defaults ∪ explicit grants.
- **Security considerations:** Only `admin` (via `requireAdmin`/`requirePermission('admin')`) may modify permissions.
- **Acceptance criteria:** Granting `risk.accept` to an `analyst` lets that user accept risks while other analysts cannot.

### REQ-IAM-003 — Backward-compatible permission aliases _(Inferred from implementation)_
- **Description:** Coarse legacy permission strings (`module.read/write/edit`) resolve to granular actions.
- **Business objective:** Keep older code/checks working after the move to granular permissions (migration 021).
- **Expected behavior:** `$aliases` (`Auth.php:185-220`) maps e.g. `risk.write` → `[risk.create, risk.edit, risk.delete, risk.accept, risk.review, risk.treatment, risk.scenarios]`; `can()` returns true if **any** aliased permission is granted (`Auth.php:353-358`).
- **Acceptance criteria:** A check for `compliance.read` succeeds whenever the user has `compliance.view`.

### REQ-IAM-004 — Two-pane permission editor UI _(Inferred from implementation; per project IAM standard)_
- **Description:** `/admin/permissions` provides per-user, per-module granular permission management.
- **Business objective:** Operable least-privilege administration.
- **Expected behavior:** `AdminController::permissions()` renders the editor; `updatePermissions()` saves grants. (Project rules require a two-pane layout, per-module accordions, granular module×action toggles, and AJAX save with CSRF rotation; this requirement records the route/controller surface.)
- **Acceptance criteria:** An admin can view a user, toggle granular permissions, and persist them; non-admins receive 403.

---

## 4. Multi-Tenancy & Platform Administration

**Source:** `src/Auth.php` (tenant methods), `controllers/PlatformController.php`, migrations 026–029, 031.

### REQ-TENANT-001 — Per-request tenant binding with RLS isolation _(Inferred from implementation)_
- **Description:** Every authenticated request operates within exactly one tenant, enforced at the database via Row-Level Security.
- **Business objective:** Hard data isolation between tenants in a shared database (SaaS multitenancy).
- **Expected behavior:** `index.php:1179-1187` calls `Database::useTenant()` (write stamping) and `Database::setTenant()` (sets the RLS GUC migration 028 policies filter on). The bound tenant is `Auth::activeTenantId()` (`Auth.php:262-273`).
- **Validation:** In single-tenant deployments, rows fall back to default `tenant_id = 1` and the RLS policy is permissive while the GUC matches.
- **Security considerations:** `setTenant()` failures are caught and logged so a pre-migration DB cannot brick the request, but isolation depends on the GUC being set.
- **Acceptance criteria:** A user in tenant A cannot read or write rows belonging to tenant B.

### REQ-TENANT-002 — Platform admin cross-tenant switch (time-boxed, audited) _(Inferred from implementation)_
- **Description:** A platform admin (SaaS operator) may temporarily act inside another tenant.
- **Business objective:** Operator support across tenants without a permanent backdoor.
- **Expected behavior:** Platform-admin status comes from a dedicated session flag `is_platform_admin`, deliberately **not** derived from tenant roles (`Auth.php:248-250`). `switchTenant()` validates the target tenant exists and is active, stores it with a 1-hour TTL, and audit-logs `platform.tenant_switch` (`Auth.php:296-315`). `exitTenant()` reverts and logs `platform.tenant_exit`. Routes: `POST /platform/switch-tenant`, `POST /platform/exit-tenant`, `GET /platform/tenants`.
- **Validation:** `requirePlatformAdmin()` gates platform routes (`Auth.php:281-288`); tenant id must be a positive integer; switch auto-expires after `TENANT_SWITCH_TTL` (3600s).
- **Security considerations:** Switches are explicit, audited, and time-boxed; a tenant `admin` role can never gain cross-tenant power.
- **Acceptance criteria:**
  - A non-platform-admin requesting `/platform/tenants` receives 403.
  - A platform admin switch is logged and automatically reverts to the home tenant after 1 hour.

---

## 5. Risk Management

**Source:** `controllers/RiskController.php`, `RiskAcceptanceController`, `RiskExceptionController`, `RiskReviewController`, `TreatmentController`, `ScenarioController`, `BowTieController`, `src/RiskScore.php`, migrations 004–007, 010, 023.

### REQ-RISK-001 — Create risk with auto-generated identifier and inherent score _(Inferred from implementation)_
- **Description:** Authorized users create risks via `POST /risk/create`.
- **Business objective:** Maintain a structured risk register supporting quantitative scoring.
- **Expected behavior:** `RiskController::create()` (`RiskController.php:238-314`) requires `risk.create`, validates CSRF, sanitizes title/description, clamps `likelihood`/`impact`/`velocity` to 1–5, allow-lists `proximity`, `risk_source`, `confidence`, and `treatment_strategies` (against `mitigate/accept/transfer/avoid`). It generates a sequential `risk_id` (`RSK-0001`), computes `inherent_score = likelihood × impact`, inserts the risk with `status='open'`, `assessment_status='draft'`, records an initial `risk_score_history` row, and audit-logs `create_risk`.
- **Inputs:** `title` (required), `description`, `category_id`, `likelihood`, `impact`, `velocity`, `proximity`, `risk_source`, `confidence`, `owner_id`, `review_date`, `treatment_strategies[]`, financial min/likely/max, `parent_risk_id`.
- **Outputs:** New risk row; redirect to `/risk/<id>`.
- **Validation:** Title required (else flash error + redirect); enum allow-lists; numeric clamping; financial fields cast to float or null.
- **Security considerations:** Standard control envelope (auth + CSRF + sanitize + parameterized insert + audit).
- **Acceptance criteria:**
  - Creating a risk with likelihood 5 and impact 5 yields `inherent_score = 25` and a `risk_score_history` row noting "Risk created".
  - An out-of-range likelihood (e.g., 9) is clamped to 5.
  - Submitting without a title returns to the form with an error and creates nothing.

### REQ-RISK-002 — Risk register listing & filtering _(Inferred from implementation)_
- **Description:** `GET /risk` lists risks with summary counts.
- **Business objective:** Portfolio-level visibility of the risk register.
- **Expected behavior:** `index()` builds a filtered query and a summary aggregating totals by severity band and status (`RiskController.php:~180-225`).
- **Outputs:** Risk list view with counts of critical/high/medium/low and open/in_review/monitoring/accepted/closed and overdue reviews.
- **Acceptance criteria:** The summary counts match the underlying `risks` rows for the active tenant.

### REQ-RISK-003 — Risk dashboard (heat map, trends, appetite, controls) _(Inferred from implementation)_
- **Description:** `GET /risk/dashboard` aggregates portfolio analytics.
- **Business objective:** Executive/operational situational awareness.
- **Expected behavior:** `dashboard()` (`RiskController.php:13-`) computes: severity/status counts using `RiskScore::sqlCondition()` bands; a 5×5 likelihood×impact heat map of active risks; top 10 open risks; a 12-week average-score trend from `risk_score_history`; risks exceeding category appetite (`risk_appetite`); high-score risks with no linked controls; upcoming reviews (next 45 days); and recent score changes (last 7 days).
- **Outputs:** Dashboard view with heat map, trend, appetite breaches, uncontrolled-risk list.
- **Dependencies:** `risks`, `risk_categories`, `risk_appetite`, `risk_control_links`, `risk_score_history`.
- **Acceptance criteria:** A risk whose `inherent_score` exceeds its category's `max_score` appears in the "exceeding appetite" list; a high-score risk with no `risk_control_links` row appears in the "uncontrolled" list.

### REQ-RISK-004 — Risk review workflow (submit / approve / reject) _(Inferred from implementation)_
- **Description:** Risks move through an assessment review workflow.
- **Business objective:** Governance sign-off on risk assessments before they are treated as approved.
- **Expected behavior:** `POST /risk/<id>/submit-review`, `/risk/<id>/approve`, `/risk/<id>/reject-review` transition `assessment_status` (e.g., draft → in review → approved). The dashboard counts `assessment_status='approved'`.
- **Security considerations:** Approve/reject require `risk.review`/appropriate permission per the standard envelope.
- **Acceptance criteria:** Approving a risk sets its `assessment_status` to `approved` and is reflected in dashboard counts.

### REQ-RISK-005 — Risk treatment plans & milestones _(Inferred from implementation)_
- **Description:** Each risk can have a treatment plan with milestones (`TreatmentController`).
- **Business objective:** Track remediation/mitigation progress against the risk.
- **Expected behavior:** `GET/POST /risk/<id>/treatment/create`, `POST /treatment/<id>/update`, `/treatment/<id>/milestone/add`, `/treatment/milestone/<id>/complete|delete`.
- **Acceptance criteria:** Adding a milestone and completing it updates the treatment's progress representation.

### REQ-RISK-006 — Risk acceptance (formal, with renewal/revocation) _(Inferred from implementation)_
- **Description:** Residual risk can be formally accepted (`RiskAcceptanceController`).
- **Business objective:** Documented, time-bounded acceptance of residual risk with accountable approver.
- **Expected behavior:** `GET/POST /risk/<id>/accept`, `GET /risk-acceptances/<id>/renew`, `POST /risk-acceptances/<id>/revoke`, listing at `/risk-acceptances`.
- **Security considerations:** Acceptance requires `risk.accept` (granted to `manager`, `risk_owner` by default; `Auth.php`).
- **Acceptance criteria:** An accepted risk is recorded with an acceptance record that can later be renewed or revoked.

### REQ-RISK-007 — Risk exceptions with decision workflow _(Inferred from implementation)_
- **Description:** Time-limited exceptions to controls/policy on a risk (`RiskExceptionController`).
- **Expected behavior:** `GET/POST /risk/<id>/exception/create`, `GET /risk/exception/<id>`, `POST /risk/exception/<id>/decide`.
- **Acceptance criteria:** An exception can be created and then approved/rejected via the decide action.

### REQ-RISK-008 — Bow-tie analysis (causes, consequences, barriers) _(Inferred from implementation)_
- **Description:** `GET /risk/<id>/bowtie` and associated POST actions model preventive/mitigative barriers.
- **Business objective:** Visualize causal pathways and control barriers for a risk.
- **Expected behavior:** Add/remove causes, consequences, and barriers (`BowTieController`).
- **Acceptance criteria:** Causes, consequences, and barriers can be independently added and removed for a risk.

### REQ-RISK-009 — Risk scenarios _(Inferred from implementation)_
- **Description:** Scenario modeling per risk (`ScenarioController`): `GET/POST /risk/<id>/scenario/create`, `POST /risk-scenarios/<id>/delete`, listing at `/risk/scenarios`.
- **Acceptance criteria:** A scenario can be created against a risk and later deleted.

### REQ-RISK-010 — Risk controls & relationship linking _(Inferred from implementation)_
- **Description:** Risks link to compliance controls and to related risks.
- **Expected behavior:** `POST /risk/<id>/link-control`, `/risk/control-link/<id>/remove`, `/risk/<id>/link-related`, `/risk/related-link/<id>/remove`.
- **Acceptance criteria:** Linking a control creates a `risk_control_links` row, removing it from the "uncontrolled risks" dashboard list.

### REQ-RISK-011 — Bulk update, roadmap & matrix configuration _(Inferred from implementation)_
- **Description:** `POST /risk/bulk-update` for multi-risk edits; `GET /risk/roadmap` timeline; `GET /risk/matrix` heat-matrix view; admin matrix config at `/admin/risk-matrix` with default seeded at `index.php:229-244`.
- **Acceptance criteria:** The risk matrix uses the active `risk_matrix_config` (5×5 default with labels, thresholds, colors); admins can update it.

### REQ-RISK-012 — Configurable risk scoring bands _(Inferred from implementation)_
- **Description:** Severity classification (critical/high/medium/low) is centralized in `RiskScore`.
- **Expected behavior:** `RiskScore::sqlCondition('<band>')` provides SQL predicates used in dashboard aggregation (`RiskController.php:19-22`). Migration 023 backs risk scoring config.
- **Acceptance criteria:** Changing scoring thresholds changes the band into which a given `inherent_score` falls consistently across listing and dashboard.

---

## 6. Compliance Management

**Source:** `controllers/ComplianceController.php`, migrations 001–003, 020, 024.

### REQ-COMP-001 — Compliance packages (frameworks), domains & controls _(Inferred from implementation)_
- **Description:** Compliance is organized as packages → domains → controls.
- **Business objective:** Track adherence to standards/frameworks (e.g., NIST, ISO, CMMC) as structured control sets.
- **Expected behavior:** Full CRUD: create/update/delete packages (`POST /compliance/create|<id>/update|<id>/delete`), domains (`/compliance/<id>/domain/add|update|delete`), and controls (`/compliance/<id>/domain/<id>/control/add`, `/compliance/<id>/control/<id>/update|delete`). All package/structure mutations require `compliance.create` (`ComplianceController.php:38-215`).
- **Inputs:** Package metadata, domain names, control identifiers/text.
- **Outputs:** Hierarchical compliance package; redirect to package view.
- **Validation:** Standard envelope (auth + CSRF) on every mutation.
- **Acceptance criteria:** Creating a package then adding a domain and a control yields a navigable package at `/compliance/<id>`.

### REQ-COMP-002 — Control assessment & bulk status/assessment _(Inferred from implementation)_
- **Description:** Controls are assessed (status + notes); bulk operations apply across many controls.
- **Business objective:** Efficiently record control implementation status at scale.
- **Expected behavior:** `POST /compliance/<id>/bulk-status` requires `compliance.create`; `POST /compliance/<id>/bulk-assess` requires `compliance.assess` (`ComplianceController.php:222-267`). Single-control add via `POST /compliance/add-single-control`.
- **Acceptance criteria:** A bulk-assess applies the chosen status/result to all selected controls in one transaction-scoped operation.

### REQ-COMP-003 — Control testing (effectiveness) _(Inferred from implementation)_
- **Description:** Controls can be tested for operating effectiveness.
- **Expected behavior:** `GET /compliance/control/<id>/test` and `POST /compliance/control/<id>/test/save`; a testing dashboard at `/compliance/testing`.
- **Permissions:** `compliance.test`.
- **Acceptance criteria:** Saving a control test records a test result associated with the control and surfaces it in the testing dashboard.

### REQ-COMP-004 — Gap analysis & scorecard _(Inferred from implementation)_
- **Description:** `GET /compliance/gap-analysis` and `GET /compliance/<id>/scorecard` summarize compliance posture.
- **Business objective:** Identify unmet controls and report a coverage score.
- **Acceptance criteria:** The scorecard reflects the proportion of controls in a satisfied status for the package.

### REQ-COMP-005 — CSV/Excel import & templates _(Inferred from implementation)_
- **Description:** Controls can be bulk-imported; downloadable templates are provided.
- **Expected behavior:** `GET /compliance/import` + `POST /compliance/import`; template downloads `/compliance/csv-template`, `/compliance/excel-template`. Uses `src/Csv.php`.
- **Validation:** Import requires `compliance.create`/`import` permission and CSRF.
- **Security considerations:** Uploaded files must be parsed as data (CSV), not executed; see REQ-SEC-005 for upload controls.
- **Acceptance criteria:** Importing a well-formed CSV creates the corresponding controls; a malformed file is rejected without partial corruption.

### REQ-COMP-006 — AI control suggestions _(Inferred from implementation)_
- **Description:** `GET /compliance/<id>/ai-suggestions` proposes control content/mappings via `AIAdvisor`.
- **Expected behavior:** Calls are logged to `ai_inference_log` for governance (`index.php:368-391`).
- **Security considerations:** Every AI inference records provider, model, action, input hash, tokens, duration, and success (ISO 42001 governance per comments).
- **Acceptance criteria:** Requesting AI suggestions writes a row to `ai_inference_log` and returns suggestions without leaking raw input (only a hash is stored).

### REQ-COMP-007 — Clear-all / delete-selected maintenance _(Inferred from implementation)_
- **Description:** `POST /compliance/clear-all` and `/compliance/delete-selected` support bulk cleanup.
- **Permissions:** `compliance.create`.
- **Acceptance criteria:** Delete-selected removes only the chosen packages and is audit-logged.

---

## 7. Audit Management

**Source:** `controllers/AuditController.php`, `AuditFindingController.php`.

### REQ-AUDIT-001 — Audit engagement lifecycle _(Inferred from implementation)_
- **Description:** Create, view, edit, update, and complete audits.
- **Business objective:** Plan and execute internal/external audit engagements with traceable items and evidence.
- **Expected behavior:** `GET /audit`, `/audit/create`, `GET /audit/<id>`, `/audit/<id>/edit`, `POST /audit/<id>/update`, `/audit/<id>/complete`, item updates `/audit/<id>/item/<id>/update`, item evidence `/audit/<id>/item/<id>/evidence`, and package export `/audit/<id>/export`.
- **Permissions:** `audit.view/create/edit/findings/close` per role defaults (`Auth.php`).
- **Acceptance criteria:** Completing an audit transitions its status and is reflected in the audit listing.

### REQ-AUDIT-002 — Audit findings with updates, closure & deletion _(Inferred from implementation)_
- **Description:** Findings track issues discovered during audits.
- **Expected behavior:** `GET /audit-findings`, `/audit-findings/<id>`, `POST /audit-findings/create|<id>/update|<id>/add-update|<id>/close|<id>/delete`. Findings can be linked to a specific audit via `audit_id` (migration applied at `index.php:538-548`).
- **Acceptance criteria:** A finding can be created, commented on, closed, and (with permission) deleted; closing it records closure metadata.

---

## 8. Policy Management

**Source:** `controllers/PolicyController.php`, migrations 001–003.

### REQ-POLICY-001 — Policy authoring & publishing lifecycle _(Inferred from implementation)_
- **Description:** Policies are authored, edited, and published.
- **Business objective:** Maintain an approved, versioned policy library mapped to controls.
- **Expected behavior:** `GET /policy`, `/policy/create`, `/policy/<id>`, `/policy/<id>/edit`; `POST /policy/create`, `/policy/<id>/update`. Publishing-related transitions in `update()` require `policy.publish` (`PolicyController.php:236-244`).
- **Validation:** Policy bodies are rich text sanitized via `Security::sanitizeHtml()` (strips scripts, event handlers, `data-*`, dangerous URI schemes).
- **Acceptance criteria:** A draft policy can be edited and published; publishing requires the `policy.publish` permission.

### REQ-POLICY-002 — Policy-to-control mapping _(Inferred from implementation)_
- **Description:** Policies map to compliance objectives/controls.
- **Expected behavior:** `GET /policy/mapping`; `POST /policy/<id>/map`, `/policy/<id>/unmap/<id>` (require `policy.edit`).
- **Acceptance criteria:** Mapping a policy to an objective creates a retrievable association shown on the mapping view.

### REQ-POLICY-003 — Attestation campaigns & user attestations _(Inferred from implementation)_
- **Description:** Users attest to policies through campaigns.
- **Business objective:** Demonstrate workforce acknowledgement of policies (awareness/compliance evidence).
- **Expected behavior:** `GET /policy/attestations`, `/policy/attestations/create`, `/policy/attestations/<id>`, `/my-attestations`; `POST /policy/attestations/save`; per-policy attestation `GET /policy/<id>/attest`, `POST /policy/<id>/attest` (require `policy.attest`).
- **Acceptance criteria:** A campaign assigns policies to users; a user attesting records an attestation visible in `/my-attestations`.

---

## 9. Incident Management

**Source:** `controllers/IncidentController.php`, `PlaybookController.php`, migration runtime columns (`index.php:411-491`).

### REQ-INC-001 — Create incident with auto-numbering _(Inferred from implementation)_
- **Description:** `POST /incident/create` records security/operational incidents.
- **Business objective:** Structured incident intake supporting response and breach-notification tracking.
- **Expected behavior:** `IncidentController::create()` (`IncidentController.php:71-129`) requires `incident.create`, validates CSRF, requires a title, allow-lists `severity` (critical/high/medium/low, default medium) and `category`, generates `INC-0001`-style numbers, defaults `detected_at` to now, sets `status='open'`, and audit-logs `create_incident`.
- **Inputs:** `title` (required), `description`, `severity`, `category`, `affected_systems`, `impact_description`, `assigned_to`, `detected_at`.
- **Outputs:** New incident; flash success; redirect to `/incident/<id>`.
- **Validation:** Invalid severity → `medium`; invalid category → `Other`.
- **Acceptance criteria:** Submitting without a title returns to the form with an error; a valid submission yields a sequential `INC-####` number.

### REQ-INC-002 — Incident lifecycle, updates & closure _(Inferred from implementation)_
- **Description:** Incidents progress through statuses with timeline updates.
- **Expected behavior:** `GET /incident/<id>`, `POST /incident/<id>/update`, `/incident/<id>/add-update`, `/incident/<id>/close`. Status CHECK widened to include `contained` (`index.php:475-482`): `open, investigating, contained, resolved, closed`. Update entries carry an `update_type` (default `comment`).
- **Permissions:** `incident.edit` for updates; `incident.close` for closure.
- **Acceptance criteria:** Adding an update appends a timeline entry; closing requires the `incident.close` permission.

### REQ-INC-003 — Breach / PHI notification tracking _(Inferred from implementation)_
- **Description:** Incidents capture HIPAA breach-notification fields.
- **Business objective:** Track regulatory breach-notification obligations (HIPAA §164.400 per comments).
- **Expected behavior:** Columns `phi_involved`, `breach_notification_required`, `breach_notification_sent_at`, plus `root_cause`, `lessons_learned`, `contained_at` (`index.php:411-436`).
- **Acceptance criteria:** An incident flagged `phi_involved` with `breach_notification_required` can record `breach_notification_sent_at`.

### REQ-INC-004 — SLA reporting _(Inferred from implementation)_
- **Description:** `GET /incident/sla` reports against SLA policy (`/admin/sla-policy`).
- **Expected behavior:** SLA report requires `incident.view`; SLA policy edited by admins via `POST /admin/sla-policy/save`.
- **Acceptance criteria:** The SLA report flags incidents breaching configured response/resolution targets.

### REQ-INC-005 — Response playbooks _(Inferred from implementation)_
- **Description:** Reusable playbooks drive incident response steps (`PlaybookController`).
- **Expected behavior:** `GET /playbooks`, `/playbooks/create`, `/playbooks/<id>`; toggle active `POST /playbooks/<id>/toggle`; start a run on an incident `POST /incident/<id>/playbook/start`; complete a step `POST /playbooks/run/<id>/complete-step`.
- **Acceptance criteria:** Starting a playbook on an incident creates a run whose steps can be completed in sequence.

---

## 10. Vendor / Third-Party Risk Management

**Source:** `controllers/VendorController.php`, `QuestionnaireController.php`, runtime vendor columns (`index.php:183-303`).

### REQ-VENDOR-001 — Vendor registry _(Inferred from implementation)_
- **Description:** Create/view/update vendors with enterprise attributes.
- **Business objective:** Maintain a third-party inventory with risk tiering.
- **Expected behavior:** `GET /vendor`, `/vendor/create`, `/vendor/<id>`; `POST /vendor/create`, `/vendor/<id>/update`. Enterprise columns auto-added if missing: `vendor_code`, `risk_tier` (critical/high/medium/low), `primary_contact`, `country`, `data_access`, `critical_service`, `contract_start/end` (`index.php:189-204`).
- **Permissions:** `vendor.view/create/edit`.
- **Acceptance criteria:** A vendor can be created with a risk tier and viewed in the registry.

### REQ-VENDOR-002 — Vendor assessments _(Inferred from implementation)_
- **Description:** Vendors undergo periodic risk assessments.
- **Expected behavior:** `POST /vendor/<id>/assessment`, `/vendor/<id>/assessment/<id>/update` (require `vendor.assess`). Assessment columns `overall_score` (0–100), `risk_rating`, `next_assessment_date` auto-added (`index.php:286-303`).
- **Acceptance criteria:** Recording an assessment stores an `overall_score` and schedules the next assessment date.

### REQ-VENDOR-003 — Vendor contracts _(Inferred from implementation)_
- **Description:** Track vendor contracts and lifecycle.
- **Expected behavior:** `GET /vendor/contracts`, `/vendor/<id>/contract/create`; `POST /vendor/<id>/contract/save`, `/vendor/contract/<id>/update` (require `vendor.contracts`).
- **Acceptance criteria:** A contract can be saved against a vendor and updated.

### REQ-VENDOR-004 — External vendor portal (tokenized, unauthenticated) _(Inferred from implementation)_
- **Description:** Vendors submit information via a tokenized link without an internal account.
- **Business objective:** Collect attestations/questionnaire responses from third parties securely.
- **Expected behavior:** Internal user generates a link `POST /vendor/<id>/portal-link` (requires `vendor.assess`); external `GET /vendor/portal/<token>` and `POST /vendor/portal/<token>/submit` are token-gated rather than session-gated (`VendorController.php:343-381`).
- **Security considerations:** Portal token is constrained by a strict character-class regex in routing; portal actions are not behind `requirePermission` and must validate the token server-side.
- **Acceptance criteria:** A valid portal token renders the submission form; an invalid/expired token does not expose vendor data.

### REQ-VENDOR-005 — Security questionnaires _(Inferred from implementation)_
- **Description:** Assignable questionnaires (`QuestionnaireController`).
- **Expected behavior:** `GET /questionnaire`, `/questionnaire/create`, `/questionnaire/<id>`; assign `POST /questionnaire/<id>/assign`; respond `GET /questionnaire/assignment/<id>/respond`, submit `POST /questionnaire/assignment/<id>/submit`.
- **Acceptance criteria:** A questionnaire can be created, assigned, completed, and its responses viewed.

---

## 11. Key Risk Indicators (KRIs) & Metrics

**Source:** `controllers/KRIController.php`, `MetricsController.php`, runtime KRI column widening (`index.php:166-181`).

### REQ-KRI-001 — Define and manage KRIs _(Inferred from implementation)_
- **Description:** Create KRIs with thresholds and direction.
- **Business objective:** Quantitatively monitor risk indicators against thresholds.
- **Expected behavior:** `GET /kris`, `/kris/create`, `/kris/<id>`; `POST /kris/create` (requires `kri.manage`), toggle `POST /kris/<id>/toggle`. KRI `unit` widened to varchar(50) and `direction` to varchar(20) to fit values like `higher_worse`/`lower_worse` (`index.php:166-179`).
- **Acceptance criteria:** A KRI can be created with a unit and a direction and listed.

### REQ-KRI-002 — Record KRI measurements _(Inferred from implementation)_
- **Description:** Record point-in-time KRI values.
- **Expected behavior:** `POST /kris/<id>/record` requires `kri.record` (`KRIController.php:121-`).
- **Acceptance criteria:** Recording a value beyond threshold flags the KRI as breached per its `direction`.

### REQ-METRICS-001 — Metrics dashboard & scheduled snapshots _(Inferred from implementation)_
- **Description:** `GET /metrics` aggregates platform metrics; schedules can be saved/deleted.
- **Expected behavior:** `POST /metrics/schedule/save`, `POST /metrics/schedule/<id>/delete`.
- **Acceptance criteria:** A metrics schedule can be created and removed.

---

## 12. Business Continuity (BCP)

**Source:** `controllers/BCPController.php`.

### REQ-BCP-001 — Business continuity plans _(Inferred from implementation)_
- **Description:** Author and maintain BCP plans.
- **Expected behavior:** `GET /bcp`, `/bcp/create`, `/bcp/<id>`; `POST /bcp/create`, `/bcp/<id>/update` (require `bcp.edit`).
- **Acceptance criteria:** A plan can be created and edited by a user with `bcp.edit`.

### REQ-BCP-002 — BCP exercises _(Inferred from implementation)_
- **Description:** Record continuity exercises against a plan.
- **Expected behavior:** `POST /bcp/<id>/add-exercise` requires `bcp.exercise` (`BCPController.php:96-99`).
- **Acceptance criteria:** Adding an exercise records the exercise outcome against the plan; requires the `bcp.exercise` permission.

---

## 13. Asset & Threat Management

**Source:** `controllers/AssetController.php`, `ThreatController.php`, runtime asset column (`index.php:218-227`).

### REQ-ASSET-001 — Asset inventory with risk linking _(Inferred from implementation)_
- **Description:** Maintain assets and link them to risks.
- **Expected behavior:** `GET /assets`, `/assets/create`, `/assets/<id>`; `POST /assets/create`, `/assets/<id>/update`; link/unlink risk `POST /assets/<id>/link-risk`, `/assets/<id>/unlink-risk/<id>`. `created_by` column auto-added (`index.php:218-227`).
- **Permissions:** `asset.view/create/edit`.
- **Acceptance criteria:** An asset can be created and linked to one or more risks.

### REQ-THREAT-001 — Threat catalog with risk linking _(Inferred from implementation)_
- **Description:** Maintain a threat catalog associated with risks.
- **Expected behavior:** `GET /threats`, `/threats/create`, `/threats/<id>`; `POST /threats/create`, `/threats/<id>/update`; link/unlink risk `POST /threats/<id>/link-risk`, `/threats/<id>/unlink-risk/<id>`.
- **Permissions:** `threat.view/create/edit`.
- **Acceptance criteria:** A threat can be created and linked to a risk; the link can be removed.

---

## 14. Issues & Change/Approval Workflow

**Source:** `controllers/IssueController.php`, `ApprovalController.php`, runtime issue columns (`index.php:449-491`).

### REQ-ISSUE-001 — Issue tracking _(Inferred from implementation)_
- **Description:** Track issues with status, updates, resolution, and recurrence prevention.
- **Expected behavior:** `GET /issue`, `/issue/create`, `/issue/<id>`; `POST /issue/create`, `/issue/<id>/update`, `/issue/<id>/add-update`. Status CHECK includes `open, in_progress, pending_review, resolved, closed, wont_fix` (`index.php:484-491`); `resolution` and `recurrence_prevention` columns added (`index.php:449-462`).
- **Permissions:** `issue.view/create/edit`.
- **Acceptance criteria:** An issue can transition to `wont_fix` and record recurrence-prevention notes.

### REQ-APPROVAL-001 — Approval workflow & templates _(Inferred from implementation)_
- **Description:** Pending approvals are reviewed and decided; admins manage templates.
- **Expected behavior:** `GET /approvals`, `/approvals/<id>/review`; `POST /approvals/<id>/decide`. Templates at `/admin/approval-templates` with `POST /admin/approval-templates/save` and `/admin/approval-templates/<id>/toggle`.
- **Permissions:** `approval.view`; `approval.approve` to decide (default for `manager`, `risk_owner`, `executive`).
- **Acceptance criteria:** An approver can approve/reject a pending item; the decision is recorded.

---

## 15. Documents & Evidence

**Source:** `controllers/DocumentController.php`, `EvidenceController.php`, `src/Storage.php`.

### REQ-DOC-001 — Document library with versioning _(Inferred from implementation)_
- **Description:** Upload and version-control documents.
- **Expected behavior:** `GET /documents`, `/documents/create`, `/documents/<id>`; `POST /documents/create`, `/documents/<id>/update`, `/documents/<id>/upload-version`.
- **Acceptance criteria:** Uploading a new version preserves prior versions and updates the current pointer.

### REQ-EVIDENCE-001 — Evidence attachments _(Inferred from implementation)_
- **Description:** Attach evidence files to entities (audits, controls, etc.).
- **Expected behavior:** List `GET /evidence/list`, upload `POST /evidence/upload`, download `GET /evidence/<id>/download`, delete `POST /evidence/<id>/delete`. Storage backend abstracted by `src/Storage.php` (configurable, `/admin/storage`).
- **Security considerations:** See REQ-SEC-005 for upload validation; downloads must be access-controlled per the standard envelope.
- **Acceptance criteria:** An authorized user can upload evidence and download it; deletion requires permission and is audit-logged.

---

## 16. Reporting & Export

**Source:** `controllers/ReportController.php`, `ExportController.php`, `AdminController` (scheduled reports).

### REQ-REPORT-001 — Standard reports _(Inferred from implementation)_
- **Description:** Compliance, executive, risk, board, and risk-detail reports.
- **Expected behavior:** `GET /report`, `/report/compliance`, `/report/executive`, `/report/risk`, `/report/board` (+ `/report/board-pack` alias), `/report/risk-detail`.
- **Permissions:** `report.view`.
- **Acceptance criteria:** Each report renders for a user with `report.view` and reflects current data.

### REQ-EXPORT-001 — Data export / download _(Inferred from implementation)_
- **Description:** `GET /export`; `POST /export/download`, `/export/download-all`.
- **Security considerations:** Branding (logo) is applied to generated reports/PDF/print output per project standard.
- **Acceptance criteria:** A download produces a file containing the requested module data for the active tenant only.

### REQ-REPORT-002 — Scheduled reports _(Inferred from implementation)_
- **Description:** Admins schedule recurring report delivery.
- **Expected behavior:** `/admin/scheduled-reports`, create/edit/update/delete via the corresponding routes; delivery via `src/Mailer.php` and cron in `scripts/`.
- **Acceptance criteria:** A scheduled report can be created with a cadence and later deleted.

---

## 17. NIST 800-171 / CMMC Toolset (SSP, POA&M, CUI, ODP, SPRS)

**Source:** `controllers/SSPController.php`, `POAMController.php`, `CUIController.php`, `ODPController.php`, `SPRSController.php`, migrations 013–014, 018–020.

### REQ-SSP-001 — System Security Plan with generation & diagrams _(Inferred from implementation)_
- **Description:** Author SSPs, link compliance packages, and generate output.
- **Expected behavior:** `GET /ssp`, `/ssp/create`, `/ssp/<id>`; `POST /ssp/create`, `/ssp/<id>/update|delete`, `/ssp/<id>/add-package`, `/ssp/<id>/remove-package/<id>`, `/ssp/<id>/statement/<id>/save`; generate `GET /ssp/<id>/generate`; download diagrams `GET /ssp/<id>/download/network-arch|data-flow`. Diagram upload columns added at `index.php:520-536` (filename + base64 data).
- **Acceptance criteria:** An SSP can link a compliance package, capture control statements, attach network/data-flow diagrams, and generate a document.

### REQ-POAM-001 — Plan of Action & Milestones _(Inferred from implementation)_
- **Description:** Track remediation items with milestones; auto-generate from gaps.
- **Expected behavior:** `GET /poam`, `/poam/<id>`; `POST /poam/create`, `/poam/generate` (from compliance gaps), `/poam/import` (CSV), `/poam/<id>/update|delete`, `/poam/<id>/milestone/add`, `/poam/<id>/milestone/<id>/complete`. POA&M actions require `compliance.assess` (`POAMController.php:50-448`); listing requires `compliance.view`.
- **Acceptance criteria:** Generating a POA&M from compliance gaps creates remediation items whose milestones can be completed.

### REQ-CUI-001 — CUI inventory _(Inferred from implementation)_
- **Description:** Track Controlled Unclassified Information assets/flows.
- **Expected behavior:** `GET /cui`, `/cui/create`, `/cui/<id>`; `POST /cui/create`, `/cui/<id>/update|delete`.
- **Acceptance criteria:** A CUI record can be created, edited, and deleted.

### REQ-ODP-001 — Organization-Defined Parameters _(Inferred from implementation)_
- **Description:** Manage ODP values used by control packages.
- **Expected behavior:** `GET /odp`, `/odp/package/<id>`; `POST /odp/save`.
- **Acceptance criteria:** ODP values can be saved and viewed per package.

### REQ-SPRS-001 — SPRS score _(Inferred from implementation)_
- **Description:** `GET /sprs` computes/presents the Supplier Performance Risk System score.
- **Acceptance criteria:** The SPRS view derives a score from the current control implementation status.

---

## 18. Privacy & Data Protection

**Source:** `controllers/PrivacyController.php`, runtime privacy tables (`index.php:641-683`).

### REQ-PRIV-001 — Processing records (RoPA) & DPIA tracking _(Inferred from implementation)_
- **Description:** Maintain data-processing records with legal basis and DPIA flags.
- **Expected behavior:** `GET /privacy`, `/privacy/create`, `/privacy/<id>`; `POST /privacy/create`, `/privacy/<id>/delete`. `privacy_records` captures controller/processor, purpose, `legal_basis`, data categories, transfers, retention, and `dpia_required/completed/date` (`index.php:643-666`).
- **Acceptance criteria:** A processing record can flag DPIA-required and later record completion.

### REQ-PRIV-002 — Data subject requests (DSAR) _(Inferred from implementation)_
- **Description:** Track data-subject requests with due dates and assignment.
- **Expected behavior:** `GET /privacy/requests`; `POST /privacy/requests/create`, `/privacy/requests/<id>/update`. `data_subject_requests` captures `request_type`, subject identity, `status`, `due_date`, `assigned_to` (`index.php:667-682`).
- **Acceptance criteria:** A DSAR can be created with a due date and progressed through status updates.

---

## 19. Awareness Training & Account Reviews

**Source:** `controllers/AwarenessController.php`, runtime tables (`index.php:558-619`).

### REQ-AWARE-001 — Awareness training programs & assignments _(Inferred from implementation)_
- **Description:** Publish training programs and assign them to users.
- **Expected behavior:** `GET /awareness`, `/awareness/create`, `/awareness/<id>`; `POST /awareness/create`, `/awareness/<id>/assign`, `/awareness/<id>/complete`, `/awareness/<id>/delete`. `awareness_programs` + `awareness_assignments` (unique program/user) (`index.php:558-586`).
- **Permissions:** `awareness.view`/`awareness.manage`.
- **Acceptance criteria:** Assigning a program to a user creates a completable assignment recorded with `completed_at`.

### REQ-AWARE-002 — Periodic account access reviews _(Inferred from implementation)_
- **Description:** Conduct access recertification reviews with per-account decisions.
- **Expected behavior:** Backed by `account_reviews` + `account_review_items` (`index.php:588-619`), each item recording `decision` (pending/…) and reviewer.
- **Acceptance criteria:** A review can record approve/revoke decisions per account with reviewer attribution.

---

## 20. Projects, RACI, Dashboards & Calendar

**Source:** `controllers/ProjectController.php`, `RACIController.php`, `CustomDashboardController.php`, `CalendarController.php`, migrations 015, 017.

### REQ-PROJ-001 — GRC projects with tasks & links _(Inferred from implementation)_
- **Description:** Manage projects with tasks and links to GRC entities.
- **Expected behavior:** `GET /projects`, `/projects/create`, `/projects/<id>`; `POST /projects/create`, `/projects/<id>/update|delete`, `/projects/<id>/task/add`, `/projects/<id>/task/<id>/complete|delete`, `/projects/<id>/link/add`, `/projects/<id>/link/<id>/remove`.
- **Acceptance criteria:** A project can hold tasks that can be completed and links to other entities that can be removed.

### REQ-RACI-001 — RACI & responsibility matrices _(Inferred from implementation)_
- **Description:** Define Responsible/Accountable/Consulted/Informed assignments.
- **Expected behavior:** `GET /raci`, `/raci/<id>`, `/raci/<id>/responsibility`; `POST /raci/<id>/save`, `/raci/<id>/responsibility/save`.
- **Acceptance criteria:** A RACI matrix can be saved and re-rendered with assignments intact.

### REQ-DASH-001 — Custom dashboards with widgets _(Inferred from implementation)_
- **Description:** Users build dashboards from widgets.
- **Expected behavior:** `GET /dashboards`, `/dashboards/<id>`; `POST /dashboards/create`, `/dashboards/<id>/add-widget`, `/dashboards/<id>/widget/<id>/remove`, `/dashboards/<id>/delete`.
- **Acceptance criteria:** A widget added to a dashboard renders and can be removed.

### REQ-DASH-002 — Default dashboard & alerts _(Inferred from implementation)_
- **Description:** `GET /` renders the home dashboard (`DashboardController::index`); alerts can be marked read (`POST /alerts/<id>/read`).
- **Acceptance criteria:** The landing dashboard renders for any authenticated user; marking an alert read removes it from the unread set.

### REQ-CAL-001 — Calendar & iCal feed _(Inferred from implementation)_
- **Description:** `GET /calendar` and a feed endpoint `GET /calendar/feed` surface due dates (reviews, assessments, contracts).
- **Dependencies:** `src/DueStatus.php`.
- **Acceptance criteria:** The calendar feed returns upcoming due items for the active tenant.

---

## 21. Search & Tagging

**Source:** `controllers/SearchController.php`, `TagController.php`.

### REQ-SEARCH-001 — Global search _(Inferred from implementation)_
- **Description:** `GET /search` provides cross-module search.
- **Acceptance criteria:** A query returns matching entities the user is permitted to see within the active tenant.

### REQ-TAG-001 — Entity tagging _(Inferred from implementation)_
- **Description:** Tag entities and manage the tag taxonomy.
- **Expected behavior:** Admin taxonomy `/admin/tags`, create/delete; per-entity `GET /tags/entity`, `POST /tags/add`, `/tags/remove`.
- **Acceptance criteria:** A tag can be added to and removed from an entity.

---

## 22. Automation & Webhooks

**Source:** `controllers/AutomationController.php`, `WebhookController.php`, `src/Webhook.php`, `src/Ssrf.php`, migration 016.

### REQ-AUTO-001 — Automation rules _(Inferred from implementation)_
- **Description:** Configure automation rules that run on events.
- **Expected behavior:** `GET /automation`, `/automation/create`, `/automation/<id>`; `POST /automation/create`, `/automation/<id>/toggle|delete|test`.
- **Permissions:** `automation.view`/`automation.manage`.
- **Acceptance criteria:** An automation rule can be created, test-run, toggled, and deleted.

### REQ-WEBHOOK-001 — Outbound webhooks with delivery log _(Inferred from implementation)_
- **Description:** Deliver event payloads to external endpoints.
- **Expected behavior:** `GET /admin/webhooks`, `/admin/webhooks/create`, `/admin/webhooks/<id>/edit`, `/admin/webhooks/<id>/deliveries`; `POST /admin/webhooks/create`, `/<id>/update|toggle|delete`.
- **Security considerations:** `src/Ssrf.php` exists to validate outbound URLs and block Server-Side Request Forgery to internal/metadata addresses — webhook targets must pass SSRF validation.
- **Acceptance criteria:** A webhook delivery to an internal/loopback/metadata address is blocked; successful and failed deliveries are recorded and viewable.

---

## 23. Administration & Configuration

**Source:** `controllers/AdminController.php`, `src/Branding.php`, `src/Secrets.php`, `src/Kms.php`, `src/Storage.php`.

### REQ-ADMIN-001 — User administration _(Inferred from implementation)_
- **Description:** Admins create, edit, and delete users.
- **Expected behavior:** `GET /admin/users`, `/admin/users/<id>/edit`; `POST /admin/users/create`, `/admin/users/<id>/update|delete`. Role must be a valid `Auth::ROLES` key.
- **Permissions:** admin only.
- **Acceptance criteria:** Creating a user with a valid role makes them able to log in; deleting/deactivating triggers session revocation (REQ-AUTH-004).

### REQ-ADMIN-002 — System settings & security policy _(Inferred from implementation)_
- **Description:** Manage global settings, security policy, password policy, MFA enforcement, SLA policy, risk appetite, custom fields, and module visibility.
- **Expected behavior:** Routes under `/admin/*` including `/admin/settings`, `/admin/security-policy`, `/admin/sla-policy`, `/admin/risk-appetite`, `/admin/custom-fields`, `/admin/module-visibility`, with corresponding `*/save` POST actions. Risk-appetite defaults seeded if empty (`index.php:493-518`).
- **Security considerations:** Sensitive setting values are encrypted at rest via `Security::encryptSetting()` (AES-256-GCM, `Security.php:202-229`).
- **Acceptance criteria:** Saving the password policy changes the rules enforced by `Security::validatePasswordPolicy()`; saving `mfa_enforcement` forces MFA setup for the listed roles at next login.

### REQ-ADMIN-003 — Branding _(Inferred from implementation; per project standard)_
- **Description:** Configure logo (URL or uploaded data URL), display name, and accent color.
- **Expected behavior:** `POST /admin/settings/branding/save`, `/admin/settings/upload-logo`, `/admin/settings/remove-logo`; settings rows seeded at `index.php:621-639`. Applied via `src/Branding.php`.
- **Security considerations:** Logo URLs must be sanitized to `http(s)://` or `data:image/...`; CSP allows `img-src https:` for externally hosted logos (`Security.php:354-356`).
- **Acceptance criteria:** A saved logo replaces the default brand mark in the header (which links home), document title, and report output; a broken logo URL degrades to the default mark.

### REQ-ADMIN-004 — API keys _(Inferred from implementation)_
- **Description:** Issue and revoke API keys for the JSON API.
- **Expected behavior:** `GET /admin/api-keys`; `POST /admin/api-keys/create`, `/admin/api-keys/<id>/revoke`. Keys are HMAC-SHA256 hashed at rest (`Security::generateApiKey/validateApiKey`, `Security.php:264-289`); legacy SHA-256 keys upgrade on first use.
- **Acceptance criteria:** A revoked or expired key fails `validateApiKey`; only the prefix is shown after creation (the full key is not stored in plaintext).

### REQ-ADMIN-005 — Session & active-session management _(Inferred from implementation)_
- **Description:** View active sessions and kill them.
- **Expected behavior:** `GET /admin/sessions`; `POST /admin/sessions/<sid>/kill`. Active sessions tracked on every request (`index.php:1190-1202`).
- **Acceptance criteria:** Killing a session terminates that user's authenticated access.

### REQ-ADMIN-006 — Data retention _(Inferred from implementation)_
- **Description:** Configure and run data-retention purges.
- **Expected behavior:** `GET /admin/retention`; `POST /admin/retention/save`, `/admin/retention/run`.
- **Acceptance criteria:** Running retention removes records older than the configured horizon and is audit-logged.

### REQ-ADMIN-007 — Email configuration & templates _(Inferred from implementation)_
- **Description:** Configure SMTP, test delivery, and manage email templates.
- **Expected behavior:** `/admin/email` (`POST /admin/email/save`, `/admin/email/test`), `/admin/email-templates` (edit/preview/update), `/admin/email-delivery`. Delivery via `src/Mailer.php`.
- **Acceptance criteria:** A test email sends successfully when SMTP is correctly configured; template edits are reflected in subsequent sends.

### REQ-ADMIN-008 — Audit log viewer & export _(Inferred from implementation)_
- **Description:** `GET /admin/logs`; `POST /admin/logs/export`.
- **Acceptance criteria:** The log view shows `activity_log` entries; export produces a file of those entries.

### REQ-ADMIN-009 — Storage backend configuration _(Inferred from implementation)_
- **Description:** Configure and test the file-storage backend.
- **Expected behavior:** `GET /admin/storage`; `POST /admin/storage/save`, `/admin/storage/test`.
- **Acceptance criteria:** Saving and testing a storage configuration confirms connectivity before files are stored there.

---

## 24. Cross-Cutting Security Requirements

**Source:** `src/Security.php`, `index.php`, `src/Ssrf.php`, `src/Secrets.php`, `src/Kms.php`.

### REQ-SEC-001 — Content Security Policy with per-request nonce _(Inferred from implementation)_
- **Description:** A strict, nonce-based CSP is set on every response.
- **Expected behavior:** `Security::setSecurityHeaders()` (`Security.php:337-380`) emits a CSP with `script-src 'self' 'nonce-…' cdn.jsdelivr.net` (no `unsafe-inline` for scripts), `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, plus `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, COOP/CORP, `Permissions-Policy`, and HSTS over HTTPS.
- **Acceptance criteria:** Inline event handlers are not used; every `<script>` carries the request nonce; the response includes the listed headers.

### REQ-SEC-002 — CSRF protection on all state changes _(Inferred from implementation)_
- **Description:** Single-use, time-limited CSRF tokens guard every POST.
- **Expected behavior:** `generateCsrfToken`/`validateCsrf`/`csrfField` (`Security.php:19-43`); tokens rotate on success and expire after 2 hours.
- **Acceptance criteria:** A reused or expired token is rejected with 403.

### REQ-SEC-003 — XSS prevention (output encoding + HTML sanitization) _(Inferred from implementation)_
- **Description:** All output is encoded; rich text is sanitized.
- **Expected behavior:** `Security::h()` for escaping; `Security::sanitizeHtml()` removes `script/style/iframe/...`, `on*` and `data-*` attributes, and non-`http(s)/mailto` URI schemes (`Security.php:58-129`).
- **Acceptance criteria:** A submitted `<script>` or `onclick=` in a policy body is stripped before storage/render.

### REQ-SEC-004 — SQL-injection prevention _(Inferred from implementation)_
- **Description:** All queries are parameterized.
- **Expected behavior:** Controllers use `Database::query/insert/update/fetch*` with bound parameters; no observed concatenation of user input into SQL.
- **Acceptance criteria:** Inputs containing SQL metacharacters are stored/queried literally, not interpreted.

### REQ-SEC-005 — Secure file uploads _(Inferred from implementation)_
- **Description:** Evidence, documents, SSP diagrams, logos, and CSV imports accept uploads.
- **Expected behavior:** Uploads are routed through dedicated handlers (`EvidenceController::upload`, `DocumentController::uploadVersion`, `AdminController::uploadLogo`, SSP diagram columns). Storage abstracted by `src/Storage.php`.
- **Security considerations:** Per project rules, uploads require MIME validation, an extension allowlist, and randomized stored filenames; logo URLs/data restricted to `http(s)`/`data:image`. _New engineers must verify these controls per handler when extending upload surfaces._
- **Acceptance criteria:** A file with a disallowed type/extension is rejected; stored filenames are not attacker-controlled.

### REQ-SEC-006 — SSRF protection for outbound requests _(Inferred from implementation)_
- **Description:** Outbound HTTP (webhooks, possibly logo fetch/AI) validates targets.
- **Expected behavior:** `src/Ssrf.php` blocks internal/loopback/link-local/metadata addresses.
- **Acceptance criteria:** A webhook or fetch aimed at `169.254.169.254` or `127.0.0.1` is refused.

### REQ-SEC-007 — Secrets management & envelope encryption _(Inferred from implementation)_
- **Description:** Secrets resolve from env, `*_FILE` mounts, and optional KMS-wrapped keys.
- **Expected behavior:** `Secrets::hydrate()` resolves `*_FILE` mounts; `Kms::hydrate()` unwraps `APP_ENCRYPTION_KEY_CIPHERTEXT` into `APP_ENCRYPTION_KEY` in-process (`index.php:83-92`). Startup guards require `JWT_SECRET` (≥32 chars), a DB connection, and `APP_URL` in production (`index.php:94-109`).
- **Security considerations:** Settings encryption uses a dedicated `APP_ENCRYPTION_KEY` (key separation from `JWT_SECRET`); audit HMAC uses a dedicated `AUDIT_HMAC_KEY` when set (`Security.php:237-262`).
- **Acceptance criteria:** Booting without a valid `JWT_SECRET`/DB/`APP_URL` (in production) yields an operator-safe configuration-error page, not a stack trace.

### REQ-SEC-008 — Tamper-evident audit trail _(Inferred from implementation)_
- **Description:** Security-relevant actions append to an HMAC hash chain.
- **Expected behavior:** `Auth::appendAuditLog()` (`Auth.php:506-540`) serializes appends under a PostgreSQL advisory lock and computes `log_hash = HMAC-SHA256(prev_hash | user | action | entity_type | entity_id | changes | ip)` keyed by `Security::auditKey()`.
- **Security considerations:** An attacker who can write the DB but not read the key cannot forge the chain. Failed logins, system actions, and user actions are all chained.
- **Acceptance criteria:** Altering any historical `activity_log` row breaks verification of all subsequent hashes; concurrent appends do not fork the chain.

### REQ-SEC-009 — Error handling without information disclosure _(Inferred from implementation)_
- **Description:** Uncaught errors render a generic 500 keyed by a request correlation ID; only `RuntimeException` (operator-safe config errors) shows its message.
- **Expected behavior:** Global exception handler (`index.php:28-63`); `X-Request-Id` header on every response; `display_errors` off.
- **Acceptance criteria:** A `PDOException` produces a generic 500 page with a reference ID and no SQL/path leakage; the detail appears only in the server log under that ID.

---

## 25. Operations & Health

**Source:** `index.php` (`/health`), `controllers/HealthController.php`, `render.yaml`, `startup.sh`, `install.php`, `scripts/`.

### REQ-OPS-001 — Health & readiness probes _(Inferred from implementation)_
- **Description:** Unauthenticated health endpoints for load balancers / uptime monitoring.
- **Expected behavior:** `GET /health` checks DB connectivity and disk free space, returns 200/`healthy` or 503/`degraded` with minimal info to avoid fingerprinting (`index.php:691-714`). `GET /healthz` (live) and `GET /readyz` (ready) via `HealthController`.
- **Acceptance criteria:** `/health` returns 503 when the database is unreachable and 200 when healthy; the body discloses no version/internal detail.

### REQ-OPS-002 — Idempotent installation & schema maintenance _(Inferred from implementation)_
- **Description:** `install.php` is the authoritative installer; `database/schema.sql` is a complete, idempotent reference; runtime guards self-heal missing columns/tables.
- **Expected behavior:** `index.php` runs guarded, idempotent `ALTER/CREATE ... IF NOT EXISTS`-style migrations on every request (`index.php:164-683`), each wrapped in `try { … } catch (Throwable) {}` so they no-op when already applied.
- **Acceptance criteria:** Running the app against a fresh DB produced from `database/schema.sql` yields a fully functional schema; running it against an older schema self-applies the missing columns/tables without failing the request.

### REQ-OPS-003 — Scheduled jobs (cron) _(Inferred from implementation)_
- **Description:** `scripts/` contains cron entrypoints (e.g., scheduled reports, digests, due-status notifications).
- **Dependencies:** `src/Mailer.php`, `src/DueStatus.php`, notification preferences (`user_notification_prefs`, `index.php:265-284`).
- **Acceptance criteria:** Scheduled report and digest jobs run on cadence and send via the configured mailer, respecting per-user notification preferences and unsubscribe tokens (`UnsubscribeController`).

---

## 26. Traceability Notes & Gaps

- **No standalone product spec exists** in the repo; all requirements here are inferred from code. Treat them as a faithful description of current behavior, not as approved intent.
- **API surface (`/api/*`)** is dispatched outside the main route table (`index.php:722-726`) and documented separately via Swagger (`/api/docs`); its detailed endpoint requirements were not enumerated here and should be derived from `api/` and `api/docs.php`.
- **Upload validation specifics (REQ-SEC-005)** are mandated by project rules but must be confirmed per handler (`EvidenceController`, `DocumentController`, `AdminController::uploadLogo`, SSP diagrams) when modifying upload code.
- **Some modules' field-level validation** (e.g., BCP, CUI, ODP, RACI) follows the same standard envelope but was not exhaustively read field-by-field; acceptance criteria above target the observable route/permission behavior.

---

_End of document._
