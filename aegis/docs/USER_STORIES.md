# AEGIS GRC — User Story Catalog

> **Audience:** A brand-new engineering team with zero prior knowledge of AEGIS.
> **Method:** Every story below is reverse-engineered from the actual code — routes
> in `index.php`, the RBAC model in `src/Auth.php`, and the controller methods that
> back each route. Permission strings, validation rules, status enums, and redirect
> behavior are quoted from the source, with file paths and line numbers where useful.
> If a behavior is not in the code, it is not in this document.

---

## How to read this catalog

Each story uses the canonical form:

> **As a** `<role>`, **I want** `<capability>`, **so that** `<benefit>`.

…and is followed by:

- **Permission** — the exact `module.action` string passed to `Auth::requirePermission()` (or `Auth::requireAdmin()` / `Auth::requirePlatformAdmin()`).
- **Route(s)** — HTTP method + path → `Controller::method` from `index.php`.
- **Acceptance criteria** — observable, testable conditions.
- **Happy path** — the nominal flow.
- **Alternate paths** — valid variations.
- **Failure paths** — error / rejection flows the code actually implements.
- **Validation rules** — server-side constraints enforced in the controller.

### Roles (the RBAC model)

From `src/Auth.php` — `Auth::ROLES` (the assignable set) and `$roleDefaults`
(the per-module default actions). `admin` is special: `Auth::can()` returns
`true` for every permission (`roleDefaultPermissions('admin') === ['*']`,
`Auth.php:173`).

| Role (`key`) | Label | Posture (from `$roleDefaults`, `Auth.php:5-138`) |
|---|---|---|
| `admin` | Administrator | All permissions (`*`); only role that passes `requireAdmin()`. |
| `manager` | Manager | Broadest non-admin: create/edit/delete/accept/review across risk; full compliance, audit, policy, vendor, incident, KRI, BCP. |
| `auditor` | Auditor | Broad read + full ownership of audits & findings (`audit.create/edit/findings/close`), `compliance.test/gap`, `vendor.assess`, `issue.*`. |
| `control_owner` | Control Owner | Implements/evidences controls: `compliance.assess/test`, `risk.treatment`, `asset.*`, `kri.record`, `ssp.edit`, `policy.attest`. |
| `risk_owner` | Risk Owner | Owns risk lifecycle incl. `risk.accept`; `kri.manage/record`; `approval.approve`. |
| `analyst` | Analyst | Create/edit risks (no delete/accept), `compliance.assess/test/gap`, `audit.findings`, `vendor.assess`, `kri.record`. |
| `executive` | Executive | Read-everything + `risk.export` + `approval.approve`. |
| `viewer` | Viewer | Read-only across all modules, plus `policy.attest`. |

> **Permission resolution** (`Auth::can()`, `Auth.php:328-361`): role defaults are
> merged with explicit per-user grants from `user_permissions` (DB), cached per
> request. Legacy coarse strings (e.g. `risk.write`) resolve via the `$aliases`
> map (`Auth.php:185-220`) — if **any** aliased granular permission is held, the
> coarse check passes.

### Cross-cutting rules (apply to nearly every story)

These are enforced consistently and are **not** repeated in each story:

- **Authentication gate** — protected routes call `Auth::requireAuth()` (directly or via `requirePermission`). Unauthenticated users are redirected to `/login`, and the original URL is stored in `redirect_after_login` (`Auth.php:363-368`).
- **Session lifetime** — 8 hours (`config/app.php:7`, `session_lifetime => 3600*8`). Idle past that → forced logout, redirect `/login?reason=timeout`.
- **Server-side revocation** — if an admin deactivated the account or bumped `sessions_revoked_at` after login, the next request forces logout (`/login?reason=account_disabled` or `reason=revoked`, `Auth.php:379-396`).
- **Forced password change / expiry** — `force_password_change` or expired `password_changed_at` (per `settings.password_expiry_days`) redirects all routes except `/profile/edit`, `/logout`, `/login` (`Auth.php:398-424`).
- **CSRF** — every POST handler calls `Security::validateCsrf($_POST['csrf_token'])`; on failure it returns HTTP 403 (`Security.php:27-38`). Tokens expire after 2 h (`csrf_lifetime`) and **rotate on every successful validation** (single-use). Forms embed `Security::csrfField()`.
- **Permission denial** — `requirePermission()` failure renders `views/errors/403.php` with HTTP 403 (`Auth.php:430-437`).
- **Input sanitization** — text inputs pass through `Security::sanitizeInput()` (strips tags + null bytes + trims, `Security.php:49-51`); policy bodies use `Security::sanitizeHtml()` (DOMDocument allowlist, `Security.php:58+`).
- **Audit trail** — state changes call `Auth::log(action, entity, id, changes)`, appended to a tamper-evident HMAC-chained `activity_log` (`Auth.php:490-548`).
- **Multitenancy** — every authenticated request binds the active tenant to the DB (RLS GUC + write stamping) from `Auth::activeTenantId()` (`index.php:1179-1187`).

---

## 1. Authentication & Account Security

### 1.1 Sign in with email + password

> **As a** user, **I want** to sign in with my email and password, **so that** I can access the platform.

- **Route:** `GET /login → AuthController::loginForm`; `POST /login → AuthController::login`
- **Permission:** none (public).
- **Acceptance criteria:**
  - Valid credentials for an `is_active = TRUE` user establish a session and redirect to the stored post-login URL or `/`.
  - The login form is not shown to already-authenticated users (redirect `/`).
- **Happy path:** Submit valid email + password → `Auth::login()` verifies the password hash, regenerates the session id, stores the user, updates `last_login`, logs `login` → redirect to safe `redirect_after_login` or `/`.
- **Alternate paths:**
  - **MFA enabled** (`mfa_enabled` + `mfa_secret`): session is torn down to a pending state and the user is sent to `/mfa/verify` (story 1.2).
  - **MFA enforced for role** (`settings.mfa_enforcement` lists the role) but not yet set up: logged in with `force_mfa_setup` flag, redirected to `/mfa/setup`.
- **Failure paths:**
  - Missing email or password → `login_error`, redirect `/login`.
  - Invalid CSRF → `login_error` "Invalid request", redirect `/login`.
  - Bad credentials / inactive account → generic "Invalid email or password, or your account is locked", redirect `/login`; a `login_failed` audit row is written (`Auth.php:453-456`).
  - **Rate limit:** per-IP (`login_<ip>`) and per-email-hash (`login_email_<sha256>`) — 5 attempts / 300 s window, 900 s lockout (`config/app.php:9-13`); over limit → `Auth::login()` returns false.
- **Validation rules:** email lowercased + sanitized; redirect target must match `^/[a-zA-Z0-9/_?=&%.@-]*$` and must not start with `/admin`, `/login`, or `/mfa`, else falls back to `/` (open-redirect guard, `AuthController.php:63-68`).

### 1.2 Complete two-factor (TOTP) verification

> **As a** user with MFA enabled, **I want** to enter my authenticator code after my password, **so that** my account is protected by a second factor.

- **Route:** `GET /mfa/verify → mfaVerifyForm`; `POST /mfa/verify → mfaVerify`
- **Permission:** none (gated by the `mfa_pending` session flag).
- **Acceptance criteria:** a correct, unused TOTP code within the ±1 time window completes login; replayed or wrong codes are rejected.
- **Happy path:** Enter 6-digit code → matches `TOTP::getCode()` for offset −1/0/+1 → not in `totp_used_codes` → record the window counter, rebuild a clean session, set `last_login`, log `mfa_login`, redirect to validated `mfa_redirect` or `/`.
- **Alternate paths:** user can switch to a backup code (`POST /mfa/backup-verify → mfaBackupVerify`, story 1.4).
- **Failure paths:**
  - No `mfa_pending` → redirect `/login`.
  - Invalid CSRF → `mfa_error`, redirect `/mfa/verify`.
  - Wrong code → `mfa_error` "Invalid code".
  - **Replay:** code already in `totp_used_codes` for that window → "This code has already been used".
  - **Rate limit:** `mfa_<userId>` over limit → "Too many failed attempts".
- **Validation rules:** code whitespace-stripped; replay window rows older than 10 minutes are purged (`AuthController.php:147`).

### 1.3 Enroll / disable an authenticator app

> **As a** user, **I want** to set up or remove TOTP MFA, **so that** I control my own second factor.

- **Route:** `GET /mfa/setup → mfaSetupForm`; `POST /mfa/setup/verify → mfaSetupVerify`; `POST /mfa/disable → mfaDisable`
- **Permission:** `Auth::requireAuth()` (any signed-in user).
- **Acceptance criteria:** the secret is **only** persisted after a code generated from it verifies; disabling clears `mfa_secret` and `mfa_enabled`.
- **Happy path:** Scan QR (pending secret kept in session, never written), enter code → `TOTP::verify()` passes → `mfa_secret`/`mfa_enabled` saved, log `enable_mfa`.
- **Failure paths:** invalid CSRF → 403; rate limit `mfa_setup_<id>` → "Too many attempts"; wrong code → `flash_error`, redirect `/mfa/setup`.

### 1.4 Generate and use MFA backup codes

> **As a** user with MFA, **I want** one-time backup codes, **so that** I can log in if I lose my device.

- **Route:** `GET /mfa/backup-codes → backupCodesForm`; `POST /mfa/backup-codes/generate → generateBackupCodes`; `POST /mfa/backup-verify → mfaBackupVerify`
- **Permission:** `Auth::requireAuth()`; requires `mfa_enabled`.
- **Acceptance criteria:** generating produces 8 `XXXX-XXXX` codes (Argon2id-hashed), invalidating any previous set; each code works once.
- **Failure paths:** MFA not enabled → `flash_error`, redirect `/mfa/setup`; invalid/used code on verify → `mfa_error`; rate limit `mfa_backup_<id>`.

### 1.5 Reset a forgotten password

> **As a** user who forgot my password, **I want** to request a reset link by email, **so that** I can regain access without an admin.

- **Route:** `GET /forgot-password → forgotPasswordForm`; `POST /forgot-password → forgotPassword`; `GET|POST /reset-password/{token} → resetPasswordForm|resetPassword`
- **Permission:** none (public).
- **Acceptance criteria:**
  - The response is **identical whether or not the email exists** ("If that email is registered…") to prevent account enumeration.
  - A token is a 64-hex string, stored only as `sha256`, valid 1 hour, single-use.
- **Happy path:** Request → if active user exists, upsert `password_reset_tokens`, email a link; submit new password on the token page → passes policy + history check → password updated, token marked used, log `password_reset` (system).
- **Failure paths:**
  - Invalid CSRF → flash + redirect back.
  - Rate limit `forgot_password_<ip>` → "Too many requests".
  - Token missing/expired/used → "invalid or has expired", redirect `/forgot-password`.
  - Passwords mismatch → "Passwords do not match".
  - Policy failure → policy messages (`Security::validatePasswordPolicy`).
  - **Reuse:** matches one of last 12 `password_history` hashes → "used recently" (`AuthController.php:372-384`).
- **Validation rules:** password policy from `settings` (default min length **12**, require upper/number/special — `Security.php:160-182`, `config/app.php:14-19`).

### 1.6 Log out

> **As a** user, **I want** to log out, **so that** my session ends.

- **Route:** `POST /logout → logout`
- **Permission:** `Auth::requireAuth()`.
- **Happy path:** valid CSRF → log `logout`, destroy + restart session, redirect `/login`.
- **Failure paths:** invalid CSRF → 403.

---

## 2. Risk Management

Backed by `RiskController.php`. Enums: strategies `mitigate|accept|transfer|avoid`; statuses `open|in_review|monitoring|accepted|closed|transferred`; sources, proximities as defined `RiskController.php:6-10`. Inherent score = `likelihood × impact`.

### 2.1 Browse and filter the risk register

> **As a** viewer (or any role), **I want** to list and filter risks, **so that** I can find the ones I care about.

- **Route:** `GET /risk → index`
- **Permission:** `risk.view`
- **Acceptance criteria:** risks render ordered by `inherent_score DESC, created_at DESC`; filters narrow the list; a summary counts critical/high/medium/low and by status.
- **Happy path:** Apply filters → server validates each against its allowlist and builds parameterized `WHERE` → list + summary.
- **Validation rules:** `status` must be in `STATUSES`; `treatment` in `STRATEGIES`; `source` in `SOURCES`; `level` in `RiskScore::levels()`; `category`/`owner` cast to int; `search` ILIKE across title/risk_id/description. Unknown filter values are ignored, not errored.

### 2.2 View the risk dashboard

> **As a** risk owner, **I want** a dashboard with heat map, top risks, appetite breaches, and review schedule, **so that** I can steer the portfolio.

- **Route:** `GET /risk/dashboard → dashboard`; `GET /risk/matrix → matrix`; `GET /risk/roadmap → roadmap`
- **Permission:** `risk.view`
- **Acceptance criteria:** dashboard shows the L×I heat map, top-10 open risks, portfolio score trend (12 weeks), risks exceeding `risk_appetite.max_score`, uncontrolled high risks, upcoming reviews, recent score changes, and treatment backlog.

### 2.3 Create a risk

> **As an** analyst, **I want** to register a new risk, **so that** it enters the managed register.

- **Route:** `GET /risk/create → createForm`; `POST /risk/create → create`
- **Permission:** `risk.create`
- **Acceptance criteria:** a sequential `RSK-####` id is assigned; inherent score is computed and stored; an initial `risk_score_history` row ("Risk created") is written; redirect to the new risk.
- **Happy path:** Submit title (required) + L/I/velocity/etc. → insert risk (status `open`, assessment `draft`) → history row → log `create_risk` → redirect `/risk/{id}`.
- **Failure paths:** invalid CSRF → 403; missing title → `risk_error`, redirect `/risk/create`.
- **Validation rules:** likelihood/impact/velocity clamped to **1–5**; `proximity` defaults `medium_term`; `risk_source` must be valid else `null`; `confidence` ∈ `low|medium|high` (default `medium`); strategies filtered to the allowlist; owner defaults to the creator; financials cast to float or null.

### 2.4 Edit a risk and capture score history

> **As an** analyst, **I want** to update a risk's scoring, treatment, and status, **so that** the register stays current and auditable.

- **Route:** `GET /risk/{id}/edit → editForm` (renders the view); `POST /risk/{id}/update → update`
- **Permission:** `risk.edit`
- **Acceptance criteria:** every update writes a `risk_score_history` row (full audit trail), even when the score is unchanged; an approved assessment is **downgraded to `draft`** when edited (`assessment_status = CASE WHEN 'approved' THEN 'draft' …`, `RiskController.php:561`).
- **Validation rules:** inherent + residual + target L/I clamped 1–5; status/proximity/source/confidence allowlisted; strategies filtered; `update_note` captured into history.

### 2.5 Submit, approve, or reject a risk assessment

> **As a** manager/reviewer, **I want** to move a risk through review → approved/rejected, **so that** assessments are governed.

- **Route:** `POST /risk/{id}/submit-review → submitReview`; `/risk/{id}/approve → approve`; `/risk/{id}/reject-review → rejectReview`
- **Permission:** `risk.review` (all three)
- **Happy path:** submit → `assessment_status = pending_review`; approve → `approved`, stamps `reviewed_by`/`reviewed_at`/notes; reject → back to `draft` with notes. Each logs a distinct action.
- **Failure paths:** invalid CSRF → 403.

### 2.6 Link controls and related risks

> **As an** analyst, **I want** to link a risk to implemented controls and to other risks, **so that** I can model coverage and relationships.

- **Route:** `POST /risk/{id}/link-control → linkControl`; `/risk/control-link/{id}/remove → removeControlLink`; `/risk/{id}/link-related → linkRelated`; `/risk/related-link/{id}/remove → removeRelatedLink`
- **Permission:** `risk.edit`
- **Acceptance criteria:** control links carry an `effectiveness` (`none|partial|substantial|full`, default `partial`) and upsert on conflict; related links carry a `link_type` (`related|causes|caused_by|aggregates`) and ignore self-links and duplicates. The view derives a **suggested residual score** from the best linked-control effectiveness (`RiskController.php:482-504`).

### 2.7 Manage response actions (treatments)

> **As a** risk owner, **I want** to add and update response actions, **so that** mitigation progress is tracked.

- **Route:** `POST /risk/{id}/response-action → addResponseAction`; `/risk/response-action/{id}/update → updateResponseAction`
- **Permission:** `risk.edit`
- **Validation rules:** `action_type` ∈ strategies (default `mitigate`); description required (else `flash_error`); status ∈ `planned|in_progress|completed|cancelled`; completing auto-stamps `completion_date`.

### 2.8 Bulk-update and delete risks

> **As a** manager, **I want** to bulk-change status/strategy/submit-for-review and to delete risks, **so that** I can manage at scale.

- **Route:** `POST /risk/bulk-update → bulkUpdate`; `POST /risk/{id}/delete → delete`
- **Permission:** bulk → `risk.edit`; delete → `risk.delete` (only `manager`/`admin` hold `risk.delete` by default).
- **Failure paths:** empty selection or unknown action → `flash_error`; invalid CSRF → 403.

### 2.9 Accept a risk (formal acceptance certificate)

> **As a** risk owner, **I want** to formally accept a risk with an expiry, **so that** residual risk is governed and time-boxed.

- **Route:** `GET /risk/{id}/accept → RiskAcceptanceController::createForm`; `POST /risk/{id}/accept → create`; `POST /risk-acceptances/{id}/revoke → revoke`; `GET /risk-acceptances/{id}/renew → renew`; `GET /risk-acceptances → index`
- **Permission:** create/revoke/renew → `risk.accept`; index → `risk.view`. (`risk.accept` is held by `manager`, `risk_owner`, `admin`.)

### 2.10 Risk exceptions, treatments, bow-tie, scenarios

> **As a** risk practitioner, **I want** exceptions, treatment plans, bow-tie diagrams, and scenarios, **so that** I can model risk in depth.

- **Routes:** `/risk/{id}/exception/* → RiskExceptionController`; `/risk/{id}/treatment/* + /treatment/* → TreatmentController` (`risk.treatment`); `/risk/{id}/bowtie* → BowTieController` (`risk.bowtie`); `/risk/{id}/scenario/* + /risk/scenarios → ScenarioController` (`risk.scenarios`); `/risk/reviews/* → RiskReviewController` (`risk.review`).
- **Permission:** as noted per sub-module above.

---

## 3. Compliance Management

Backed by `ComplianceController.php`. Hierarchy: `compliance_packages` → `compliance_objectives` (level 1 = domain, level 2 = control) → `control_implementations`. Implementation statuses: `not_started|compliant|partial|non_compliant|not_applicable`.

### 3.1 Browse compliance packages

> **As a** viewer, **I want** to see all active frameworks with implementation rollups, **so that** I can gauge posture.

- **Route:** `GET /compliance → index`
- **Permission:** `compliance.view`
- **Acceptance criteria:** packages list with control counts and compliant/partial/non-compliant tallies, ordered by `imported_at`.

### 3.2 Create a package manually and manage its structure

> **As a** manager, **I want** to create a package and add domains/controls, **so that** I can model a custom framework.

- **Route:** `GET /compliance/create → createForm`; `POST /compliance/create → create`; domain CRUD `…/domain/*`; control CRUD `…/control/*`; `POST /compliance/{id}/update|delete`, `/compliance/clear-all`, `/compliance/delete-selected`, `/compliance/add-single-control`
- **Permission:** `compliance.create` (create/update/delete/domain/control/clearAll); `compliance.import` for `addSingleControl`.
- **Validation rules:** package `name` required; domain & control require `code` + `title`; `objectives_count` is kept in sync via `syncCount()`. Deletes cascade carefully — `audit_items` rows are removed and `audits.package_id` nulled first (no DB CASCADE).

### 3.3 Import a framework from a file

> **As a** compliance lead, **I want** to import packages from JSON/CSV/Excel/PDF, **so that** I don't hand-enter controls.

- **Route:** `GET /compliance/import → importForm`; `POST /compliance/import → import`; template downloads `…/csv-template`, `…/excel-template`
- **Permission:** `compliance.import`
- **Acceptance criteria:** the selected format must match the detected MIME; on success redirect `/compliance?imported=1`.
- **Validation rules / failure paths:**
  - Max upload **20 MB** ("File too large").
  - MIME allowlist per format (JSON: `application/json`/`text/plain`; CSV; Excel `.xlsx`/`application/zip`; PDF `application/pdf`). Mismatch → "File type does not match selected import format."
  - JSON must decode; CSV must contain the 5 required columns (`package_name, domain_code, domain_title, control_code, control_title`) else a specific "missing required column" error.
  - PDF import requires `pdftotext` (poppler) on the server; image-only PDFs → "no selectable text". Filenames are sanitized before use as package names.
  - No file → "No file uploaded." Errors surface via `import_errors` on `/compliance/import`.

### 3.4 Assess controls (single, bulk, AJAX)

> **As a** control owner, **I want** to set implementation status, notes, evidence, owner, and due date per control, **so that** posture reflects reality.

- **Route:** `POST /compliance/{pkg}/objective/{obj}/update → updateObjective`; `POST /compliance/{pkg}/bulk-status → bulkStatus`; `POST /compliance/{pkg}/bulk-assess → bulkAssess`
- **Permission:** `updateObjective`/`bulkAssess` → `compliance.assess`; `bulkStatus` → `compliance.create`.
- **Acceptance criteria:** bulk endpoints return JSON `{ok, updated, new_csrf}` and **only touch controls that belong to the package** (server re-validates ownership of every id, `ComplianceController.php:240-244,289-293`).
- **Failure paths:** invalid CSRF → JSON `{ok:false}`; invalid status or empty selection → JSON error. Status must be in the 5-value allowlist.

### 3.5 Test a control and record effectiveness

> **As an** auditor/control owner, **I want** to record control test results, **so that** operating effectiveness is evidenced over time.

- **Route:** `GET /compliance/control/{obj}/test → testControl`; `POST /compliance/control/{obj}/test/save → saveTest`; dashboard `GET /compliance/testing → testingDashboard`
- **Permission:** test/save → `compliance.test`; dashboard → `compliance.view`.
- **Validation rules:** `result` ∈ `pass|fail|partial|not_tested`; `effectiveness` clamped **0–100**; saving updates the control's `last_reviewed`. Logged as `control_tested`.

### 3.6 Gap analysis and AI suggestions

> **As a** compliance lead, **I want** cross-framework gap analysis and AI-suggested control gaps, **so that** I can prioritize remediation.

- **Route:** `GET /compliance/gap-analysis → gapAnalysis`; `GET /compliance/{id}/scorecard → scorecard`; `GET /compliance/{id}/ai-suggestions → aiSuggestions`
- **Permission:** gap → `compliance.gap`; scorecard → `compliance.view`; AI → `compliance.view`.
- **Acceptance criteria:** AI suggestions are **rate-limited to 5 / hour per user** (`ai_suggest_<id>`) → HTTP 429 on excess; output includes a fixed `AIAdvisor::DISCLAIMER`.

---

## 4. Internal Audit

Backed by `AuditController.php`. Audit statuses: `planned|in_progress|completed|overdue|cancelled`. Item statuses: `not_assessed|compliant|non_compliant|partial|not_applicable`.

### 4.1 Plan an audit

> **As an** auditor, **I want** to schedule an audit against a framework, **so that** controls get systematically tested.

- **Route:** `GET /audit → index`; `GET /audit/create → createForm`; `POST /audit/create → create`
- **Permission:** index → `audit.view`; create → `audit.create`.
- **Acceptance criteria:** a sequential `AUD-####` number is assigned; if a package is selected, one `audit_item` per level-2 control is auto-created; recurring frequencies create an `audit_schedules` row with the next due date.
- **Validation rules:** `name` + `scheduled_date` required (else `audit_error`); `audit_type` ∈ `internal|external|gap|follow_up`; `frequency` ∈ `one_time|monthly|quarterly|biannual|annual`.

### 4.2 Conduct an audit (assess items, attach evidence)

> **As an** auditor, **I want** to set each control item's result and attach evidence files, **so that** findings are documented.

- **Route:** `POST /audit/{id}/item/{item}/update → updateItem` (AJAX, returns JSON); `GET /audit/{id}/item/{item}/evidence → itemEvidence`
- **Permission:** update → `audit.edit`; evidence list → `audit.view`.
- **Validation rules (file uploads, `AuditController.php:175-216`):** max **10 files** per submission; each ≤ **20 MB**; MIME must be in the image/PDF/Office/text allowlist **and** extension in the matching allowlist; stored filename randomized (`bin2hex(random_bytes(16)).ext`); SHA-256 hash recorded. Item status allowlisted; `risk_level` ∈ `low|medium|high|critical` or null.

### 4.3 Update audit status and complete with score

> **As an** auditor, **I want** to advance and close out an audit, **so that** a compliance score is recorded.

- **Route:** `POST /audit/{id}/update → update`; `POST /audit/{id}/complete → complete`
- **Permission:** update → `audit.edit`; complete → `audit.close`.
- **Acceptance criteria:** completing sets status `completed`, stamps `completed_date`, and computes `score = round(compliant/total × 100, 2)`. Moving to `in_progress` sets `start_date` if unset.

### 4.4 Export an audit evidence package (ZIP)

> **As an** auditor, **I want** to export a ZIP of findings + evidence, **so that** I can hand a package to an external assessor.

- **Route:** `GET /audit/{id}/export → exportPackage`
- **Permission:** `audit.view`
- **Acceptance criteria:** ZIP contains `findings.csv`, `README.txt` summary (score, auditor, timestamp, exporter), audit-level evidence, and control-level evidence foldered by control code. Stored filenames are validated against `^[0-9a-f]+\.[a-z0-9]+$` before inclusion (path-traversal guard).
- **Failure paths:** missing `ZipArchive` → HTTP 500 with a clear message; audit not found → 404.

### 4.5 Track external audit findings (CAPA)

> **As an** auditor, **I want** to log external findings with severity, owner, and deadline, **so that** corrective actions are managed to closure.

- **Route:** `GET /audit-findings → index`; `GET /audit-findings/{id} → view`; `POST /audit-findings/create|{id}/update|{id}/add-update|{id}/close|{id}/delete`
- **Permission:** `audit.findings` (held by `manager`, `auditor`, `analyst`, `control_owner`, `admin`).
- **Validation rules:** severity ∈ `critical|high|medium|low|info`; status ∈ `open|in_progress|resolved|risk_accepted|closed`; source ∈ `external_audit|pentest|certification|assessment|regulatory|other`; title required (`AuditFindingController.php:6-8,89`).

---

## 5. Policy Management

Backed by `PolicyController.php`. Policy statuses: `draft|under_review|published|archived`.

### 5.1 Author a policy with versioning

> **As a** policy author, **I want** to create a policy with a rich body and review cadence, **so that** governance documents are managed.

- **Route:** `GET /policy/create → createForm`; `POST /policy/create → create`; `GET /policy/{id}/edit → editForm`
- **Permission:** create → `policy.create`; edit form → `policy.edit`.
- **Acceptance criteria:** a `POL-####` number is assigned; an initial `policy_versions` row ("Initial version", `1.0`) is created; if no review date is given, it's derived from `review_frequency`.
- **Validation rules:** title required; body sanitized via `Security::sanitizeHtml()`; `review_frequency` ∈ `monthly|quarterly|biannual|annual|biennial`.

### 5.2 Edit, version, and govern policy lifecycle

> **As a** policy owner, **I want** to save edits, submit for review, publish, approve, or archive, **so that** the policy lifecycle is enforced.

- **Route:** `POST /policy/{id}/update → update` (the `action` field selects the operation)
- **Permission:** base → `policy.edit`; **`publish`, `approve`, and `archive` additionally require `policy.publish`** (`PolicyController.php:235-245`). `submit_review` needs only `policy.edit`.
- **Acceptance criteria:** publishing stamps `published_at`; approving stamps `approved_at` + `approver_id`; providing `new_version` writes a new `policy_versions` row and bumps `policies.version`.
- **Failure paths:** invalid CSRF → 403; policy not found → 404; lacking `policy.publish` for a privileged action → 403.

### 5.3 Map policies to controls

> **As a** compliance lead, **I want** to map policies to control objectives, **so that** I can show which policy satisfies which control.

- **Route:** `GET /policy/mapping → mapping`; `POST /policy/{id}/map → mapObjective`; `POST /policy/{id}/unmap/{mapId} → unmapObjective`
- **Permission:** mapping view → `policy.view`; map/unmap → `policy.edit`.

### 5.4 Run attestation campaigns

> **As a** compliance manager, **I want** to launch a campaign requiring users to attest to a published policy, **so that** I can evidence awareness.

- **Route:** `GET /policy/attestations → attestations`; `GET /policy/attestations/create → createCampaign`; `POST /policy/attestations/save → saveCampaign`; `GET /policy/attestations/{id} → viewCampaign`
- **Permission:** list/view → `policy.view`; create/save → `policy.attest`.
- **Acceptance criteria:** the campaign view shows an **attested vs. pending** matrix across all active users.
- **Validation rules:** `policy_id` + `title` required; only `published` policies are selectable.

### 5.5 Attest to a policy (end user)

> **As a** viewer, **I want** to read a policy and sign off, **so that** I record my acknowledgment.

- **Route:** `GET /policy/{id}/attest → attestForm`; `POST /policy/{id}/attest → attest`; `GET /my-attestations → myAttestations`
- **Permission:** `policy.attest` (held even by `viewer`).
- **Acceptance criteria:** attestation records IP + optional notes and **upserts** on `(policy_id, user_id)` so re-attesting refreshes the timestamp.
- **Failure paths:** confirmation checkbox not ticked → `flash_error` "You must check the confirmation box"; invalid CSRF → 403; policy not found → 404.

---

## 6. Vendor / Third-Party Risk

Backed by `VendorController.php`. Tiers: `critical|high|medium|low`. Vendor statuses: `active|inactive|under_review|terminated`.

### 6.1 Maintain the vendor inventory

> **As a** vendor manager, **I want** to list, filter, create, and edit vendors, **so that** third-party risk is inventoried.

- **Route:** `GET /vendor → index`; `GET /vendor/create → createForm`; `POST /vendor/create → create`; `POST /vendor/{id}/update → update`; `GET /vendor/{id} → view`
- **Permission:** view/index → `vendor.view`; create → `vendor.create`; update → `vendor.edit`.
- **Acceptance criteria:** a `VND-####` code is assigned; list is ordered by tier then name; stats show total/active/critical/data-access counts.
- **Validation rules:** name required; `risk_tier`/`status` allowlisted (default `medium`/`active`); `category` coerced to `Other` if outside the allowlist; **website URL scheme must be http/https** else dropped (`VendorController.php:83-86`); booleans `data_access`/`critical_service` from checkbox presence.

### 6.2 Schedule and complete vendor assessments

> **As an** analyst, **I want** to schedule security/privacy/BCP assessments and record findings, **so that** vendors are periodically evaluated.

- **Route:** `POST /vendor/{id}/assessment → addAssessment`; `POST /vendor/{id}/assessment/{aid}/update → updateAssessment`
- **Permission:** `vendor.assess`
- **Validation rules:** `assessment_type` ∈ `security|privacy|business_continuity|financial|operational`; scheduled date required to create; on update, `status` ∈ `planned|in_progress|completed|overdue`, `overall_score` clamped 0–100, `risk_rating` allowlisted; completing auto-stamps `completed_date`.

### 6.3 Send a vendor self-assessment portal link

> **As a** vendor manager, **I want** to generate a tokenized public questionnaire link, **so that** vendors can self-assess without an account.

- **Route:** `POST /vendor/{id}/portal-link → generatePortalLink`; **public** `GET /vendor/portal/{token} → portalView`; **public** `POST /vendor/portal/{token}/submit → portalSubmit`
- **Permission:** generate → `vendor.assess`; portal view/submit → **none (public)**.
- **Acceptance criteria:** a 64-hex token is stored only as `sha256`, expires in **30 days**, single-use (`used_at`); the portal pre-loads 10 default security questions.
- **Failure paths (public):** invalid/expired token → 404 page; already submitted → HTTP 410; CSRF mismatch (HMAC of token with `JWT_SECRET`) → 403; missing required answers → HTTP 422 with the list. On submit, a best-effort `vendor_assessments` record (status `completed`) is created.

### 6.4 Manage vendor contracts

> **As a** vendor manager, **I want** to track contracts and renewal windows, **so that** I'm warned before expiry.

- **Route:** `GET /vendor/contracts → contracts`; `GET /vendor/{id}/contract/create → createContract`; `POST /vendor/{id}/contract/save → saveContract`; `POST /vendor/contract/{id}/update → updateContract`
- **Permission:** `vendor.contracts`
- **Acceptance criteria:** the contracts page surfaces agreements expiring within 60 days. `title` + `start_date` required; status ∈ `draft|active|expired|terminated`; currency truncated to a 3-char uppercase code.

### 6.5 Vendor questionnaires (internal)

> **As an** analyst, **I want** internal questionnaire templates and assignments, **so that** I can run structured assessments.

- **Route:** `/questionnaire*` → `QuestionnaireController`
- **Permission:** `vendor.questionnaire` (held by `manager`/`admin`).

---

## 7. Key Risk Indicators (KRI)

Backed by `KRIController.php`. Directions: `higher_worse|lower_worse`; frequencies `daily|weekly|monthly|quarterly`; RAG computed from thresholds.

### 7.1 Define a KRI with thresholds

> **As a** risk owner, **I want** to define a KRI with green/amber/red thresholds and a direction, **so that** breaches are flagged automatically.

- **Route:** `GET /kris/create → createForm`; `POST /kris/create → create`
- **Permission:** `kri.manage`
- **Acceptance criteria:** thresholds must be **monotonic in the direction** — `green ≤ amber ≤ red` for `higher_worse`, `green ≥ amber ≥ red` for `lower_worse` — otherwise creation is rejected (`KRIController.php:69-77`).
- **Validation rules:** title required; direction & frequency allowlisted (defaults `higher_worse`/`monthly`); owner/linked-risk cast to int or null.

### 7.2 Record KRI values and view RAG status

> **As a** control owner, **I want** to record periodic measurements, **so that** trend and RAG status update.

- **Route:** `GET /kris → index`; `GET /kris/{id} → view`; `POST /kris/{id}/record → recordValue`
- **Permission:** index/view → `kri.view`; record → `kri.record`.
- **Acceptance criteria:** the list shows each KRI's latest value and computed RAG (`grey` when no data); the view shows the last 24 readings.
- **Failure paths:** non-numeric value → `flash_error` "Value must be numeric"; KRI not found → 404; invalid CSRF → 403.

### 7.3 Activate / deactivate a KRI

> **As a** risk owner, **I want** to toggle a KRI active state, **so that** retired indicators drop off the dashboard.

- **Route:** `POST /kris/{id}/toggle → toggle`
- **Permission:** `kri.manage`

---

## 8. Administration & RBAC

Backed by `AdminController.php`. All methods call `Auth::requireAdmin()` (i.e. effective permission `admin`, which only the `admin` role / `*` satisfies).

### 8.1 Manage users

> **As an** administrator, **I want** to create, edit, and deactivate users, **so that** I control who has access.

- **Route:** `GET /admin/users → users`; `POST /admin/users/create → createUser`; `GET /admin/users/{id}/edit → editUser`; `POST /admin/users/{id}/update → updateUser`; `POST /admin/users/{id}/delete → deleteUser`
- **Permission:** `admin` (`requireAdmin`)
- **Acceptance criteria:**
  - New users get a hashed password and a 24 h email-verification token (best-effort email).
  - Deactivating a user (update with `is_active` off, or delete) sets `sessions_revoked_at = NOW()` and disables their API keys — forcing immediate logout on their next request.
  - **You cannot delete your own account** (`deleteUser` guards `id === Auth::id()`).
- **Validation rules:** name + email required; password ≥ **8** chars on create and on password change; email must be unique; `role` must pass `Auth::isValidRole()` else coerced to `viewer`.
- **Failure paths:** invalid CSRF → 403; validation errors collected into `user_errors` and shown on `/admin/users`.

### 8.2 Edit granular per-user permissions (IAM two-pane)

> **As an** administrator, **I want** to grant or revoke specific `module.action` permissions per user on top of their role, **so that** access is least-privilege.

- **Route:** `GET /admin/permissions → permissions`; `POST /admin/permissions/{userId}/update → updatePermissions`
- **Permission:** `admin` (`requireAdmin`)
- **Acceptance criteria:**
  - The editor enumerates every module × action (`AdminController.php:438-456`); role defaults are shown distinctly from explicit grants.
  - Save replaces all explicit grants for the user (delete-then-insert) and **validates every `module.action` against the module allowlist and the 26-value action allowlist** before inserting (`AdminController.php:566-601`).
  - **Admin users cannot be targeted** — the lookup excludes `role = 'admin'`.
  - AJAX requests get JSON `{ok:true, csrf:<rotated>}` so the client rotates its in-memory token; non-AJAX redirects to `/admin/permissions?saved=<id>`.
- **Failure paths:** invalid CSRF → 403 (JSON when `X-Requested-With: XMLHttpRequest`); invalid/admin target → 422 JSON or `?error=invalid`.

### 8.3 Configure the platform

> **As an** administrator, **I want** settings, branding, risk matrix, appetite, security policy, SLA, email, storage, retention, and webhooks, **so that** the platform fits our org.

- **Route(s):** `/admin/settings*`, `/admin/settings/branding/save`, `/admin/settings/upload-logo`, `/admin/risk-matrix*`, `/admin/risk-appetite*`, `/admin/security-policy*`, `/admin/sla-policy*`, `/admin/email*`, `/admin/storage*`, `/admin/retention*`, `/admin/custom-fields*`, `/admin/module-visibility*`, `/admin/email-templates*`, `/admin/scheduled-reports*`, `/admin/alerts*`, `/admin/api-keys*`, `/admin/sessions*`, `/admin/logs*` → various `AdminController` methods; `/admin/webhooks* → WebhookController`; `/admin/settings/sso* → SSOController`.
- **Permission:** `admin` (all). SSO save likewise requires admin.
- **Notable behaviors:** sensitive settings (`sso_client_secret`, `smtp_password`, `webhook_secret`) are masked as `••••••••` in the admin index; branding accepts a `logoUrl` and a `data:` upload and a `brand_accent` color (seeded in `index.php:621-639`).

### 8.4 Branding (Settings → Branding)

> **As an** administrator, **I want** to set a logo (URL or uploaded `data:` image), display name, and accent color, **so that** the app reflects our brand everywhere.

- **Route:** `POST /admin/settings/branding/save → saveBranding`; `/admin/settings/upload-logo → uploadLogo`; `/admin/settings/remove-logo → removeLogo`
- **Permission:** `admin`
- **Acceptance criteria:** logo URLs are sanitized to `http(s)://` or `data:image/...`; values persist in `settings` and apply live across the header, title, reports, and login (per the project Branding standard and `src/Branding.php`).

### 8.5 Review the tamper-evident audit log

> **As an** administrator, **I want** to view and export the activity log, **so that** I have a forensic trail.

- **Route:** `GET /admin/logs → logs`; `POST /admin/logs/export → exportLogs`
- **Permission:** `admin`
- **Acceptance criteria:** entries are HMAC-chained (`log_hash`) so tampering is detectable (`Auth.php:490-540`).

### 8.6 Manage active sessions and API keys

> **As an** administrator, **I want** to see active sessions and revoke them, and to mint/revoke API keys, **so that** I can contain compromised access.

- **Route:** `GET /admin/sessions → sessions`; `POST /admin/sessions/{sid}/kill → killSession`; `GET /admin/api-keys → apiKeys`; `POST /admin/api-keys/create → createApiKey`; `POST /admin/api-keys/{id}/revoke → revokeApiKey`
- **Permission:** `admin`

---

## 9. Approvals Workflow

Backed by `ApprovalController.php`.

### 9.1 Act on pending approvals

> **As an** approver (risk_owner/executive/manager/admin), **I want** to review and decide on approval requests, **so that** governed changes get sign-off.

- **Route:** `GET /approvals → pending`; `GET /approvals/{id}/review → review`; `POST /approvals/{id}/decide → decide`
- **Permission:** decide → `approval.approve` (held by `manager`, `risk_owner`, `executive`, `admin`). `approval.view` is held by all roles.

### 9.2 Manage approval templates

> **As an** administrator, **I want** approval templates, **so that** workflows are reusable.

- **Route:** `GET /admin/approval-templates → templates`; `…/create → createTemplate`; `POST /admin/approval-templates/save → saveTemplate`; `…/{id}/toggle → toggleTemplate`
- **Permission:** as enforced by `ApprovalController` (admin-scoped routes).

---

## 10. Operational GRC Modules (Incidents, Issues, Assets, Threats, BCP, Awareness, Privacy, SSP, POA&M, Projects, Automation, Documents, Change, CUI, RACI, Dashboards)

These modules follow the same controller pattern (index / create / view / update, CSRF on POST, `requirePermission` per action). Stories are summarized; permission strings are taken from `index.php` routing + each controller.

### 10.1 Incident & playbook management

> **As an** incident responder, **I want** to log incidents, run playbooks, and track breach-notification fields, **so that** response is coordinated and compliant.

- **Routes:** `/incident/sla → IncidentController::slaReport`; `/playbooks*` + `/incident/{id}/playbook/start → PlaybookController`.
- **Permission:** `incident.view|create|edit|close|playbook` (per route). Incident statuses include `contained` (migration in `index.php:476-482`); HIPAA breach fields (`phi_involved`, `breach_notification_*`) exist on `incidents`.

### 10.2 Issue / CAPA tracking

> **As an** analyst, **I want** to log issues, post updates, and resolve them, **so that** corrective actions close out.

- **Routes:** `/issue`, `/issue/create`, `/issue/{id}`, `/issue/{id}/update`, `/issue/{id}/add-update`.
- **Permission:** `issue.view|create|edit`. Statuses widened to `open|in_progress|pending_review|resolved|closed|wont_fix` (`index.php:485-491`).

### 10.3 Asset register (with risk links)

> **As a** control owner, **I want** to inventory assets and link them to risks, **so that** I can reason about exposure.

- **Routes:** `/assets`, `/assets/create`, `/assets/{id}`, `/assets/{id}/update`, `/assets/{id}/link-risk`, `/assets/{id}/unlink-risk/{rid}`.
- **Permission:** `asset.view|create|edit`.

### 10.4 Threat catalog

> **As an** analyst, **I want** to track threats and link them to risks, **so that** the threat landscape feeds risk.

- **Routes:** `/threats*`, `/threats/{id}/link-risk`, `/threats/{id}/unlink-risk/{rid}`.
- **Permission:** `threat.view|create|edit`.

### 10.5 Business Continuity (BCP) & exercises

> **As a** continuity manager, **I want** BCP plans and exercise records, **so that** resilience is tested.

- **Routes:** `/bcp`, `/bcp/create`, `/bcp/{id}`, `/bcp/{id}/update`, `/bcp/{id}/add-exercise`.
- **Permission:** `bcp.view|edit|exercise`.

### 10.6 Security awareness training

> **As an** awareness manager, **I want** to publish training programs and track completion, **so that** staff are trained.

- **Routes:** `/awareness*`, `/awareness/{id}/assign`, `/awareness/{id}/complete`, `/awareness/{id}/delete`.
- **Permission:** `awareness.view|manage`.

### 10.7 Data privacy (RoPA & DSARs)

> **As a** privacy officer, **I want** processing records and data-subject requests, **so that** GDPR/CCPA obligations are met.

- **Routes:** `/privacy`, `/privacy/create`, `/privacy/{id}`, `/privacy/requests`, `/privacy/requests/create`, `/privacy/requests/{id}/update`.
- **Permission:** privacy routes are gated by `PrivacyController` (admin/compliance scoped). Tables seeded in `index.php:641-683`.

### 10.8 System Security Plans (SSP) & POA&M (FedRAMP/CMMC)

> **As a** compliance engineer, **I want** SSPs (with diagrams) and POA&Ms, **so that** authorization packages are maintained.

- **Routes:** `/ssp*` (incl. `…/generate`, `…/download/network-arch`, `…/download/data-flow`, statements, packages) → `SSPController` (`ssp.view|edit`); `/poam*` (incl. `…/generate`, `…/import`, milestones) → `POAMController`.

### 10.9 Automation rules

> **As a** GRC engineer, **I want** automation rules, **so that** routine actions run without manual effort.

- **Routes:** `/automation*`, `/automation/{id}/toggle|delete|test`.
- **Permission:** `automation.view|manage`.

### 10.10 Documents, Change requests, CUI, Projects, RACI, Custom dashboards

> **As a** GRC user, **I want** controlled documents, change requests, CUI tracking, projects/tasks, RACI matrices, and custom dashboards, **so that** the program is fully managed in one place.

- **Routes:** `/documents*` (versioned uploads); `/change*` (status incl. `approve`, `change.view|create|edit|approve`); `/cui*`; `/projects*` (tasks + links); `/raci*` (responsibility matrix); `/dashboards*` (widgets) → respective controllers. Permissions follow the same `module.action` pattern.

---

## 11. Reporting, Search, Calendar, Export

### 11.1 Run reports

> **As an** executive, **I want** executive/risk/compliance/board reports, **so that** I can brief leadership.

- **Route:** `GET /report`, `/report/executive`, `/report/risk`, `/report/compliance`, `/report/board` (`board-pack`), `/report/risk-detail` → `ReportController`.
- **Permission:** `report.view` (held by all roles; `executive` and `manager` included).

### 11.2 Export and bulk-download data

> **As a** manager, **I want** to export module data, **so that** I can analyze or archive it.

- **Route:** `GET /export → index`; `POST /export/download → download`; `POST /export/download-all → downloadAll` → `ExportController`.

### 11.3 Global search & compliance calendar

> **As a** user, **I want** global search and a deadline calendar (with an ICS feed), **so that** I can navigate and never miss a due date.

- **Route:** `GET /search → SearchController::index`; `GET /calendar → CalendarController::index`; `GET /calendar/feed → feed`.

### 11.4 Metrics & scheduled reports

> **As an** administrator, **I want** metrics and scheduled report delivery, **so that** stakeholders get periodic updates.

- **Route:** `GET /metrics → MetricsController::index`; `POST /metrics/schedule/save`, `…/{id}/delete`; admin scheduled reports under `/admin/scheduled-reports*`.

---

## 12. Multi-Tenant Platform Operations (SaaS operator)

Backed by `PlatformController` + `Auth` platform helpers. **Platform admin is a dedicated flag (`is_platform_admin`), NOT a role** — tenant `admin` does not grant it (`Auth.php:248-250`).

### 12.1 Switch into a tenant (time-boxed, audited)

> **As a** platform admin, **I want** to switch my active tenant context, **so that** I can support a customer without a separate login.

- **Route:** `GET /platform/tenants → tenants`; `POST /platform/switch-tenant → switchTenant`; `POST /platform/exit-tenant → exitTenant`
- **Permission:** `Auth::requirePlatformAdmin()` (renders 403 for non-platform-admins).
- **Acceptance criteria:**
  - Switching validates the target tenant exists and `is_active`, then sets an `active_tenant` that **auto-expires after 1 hour** (`TENANT_SWITCH_TTL`, `Auth.php:241`).
  - Both switch and exit write `platform.tenant_switch` / `platform.tenant_exit` audit rows with from/to tenant ids.
  - `activeTenantId()` reverts to the home tenant once the switch expires; all reads/writes are RLS-isolated to the active tenant.
- **Failure paths:** non-platform-admin → `RuntimeException` / 403; non-positive or missing/inactive tenant → exception.

---

## Appendix A — Permission → Default-Role Matrix (core actions)

Derived from `Auth::$roleDefaults` (`Auth.php:5-138`). ✓ = granted by role default. `admin` holds all (`*`).

| Permission | manager | auditor | control_owner | risk_owner | analyst | executive | viewer |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| `risk.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `risk.create` | ✓ | | | ✓ | ✓ | | |
| `risk.delete` | ✓ | | | | | | |
| `risk.accept` | ✓ | | | ✓ | | | |
| `risk.review` | ✓ | | | ✓ | ✓ | | |
| `risk.export` | ✓ | | | ✓ | | ✓ | |
| `compliance.assess` | ✓ | | ✓ | | ✓ | | |
| `compliance.test` | ✓ | ✓ | ✓ | | ✓ | | |
| `compliance.gap` | ✓ | ✓ | | | ✓ | | |
| `compliance.import` | ✓ | | | | | | |
| `audit.create` | ✓ | ✓ | | | | | |
| `audit.findings` | ✓ | ✓ | ✓ | | ✓ | | |
| `audit.close` | ✓ | ✓ | | | | | |
| `policy.publish` | ✓ | | | | | | |
| `policy.attest` | ✓ | | ✓ | | ✓ | | ✓ |
| `vendor.assess` | ✓ | ✓ | | | ✓ | | |
| `vendor.contracts` | ✓ | | | | | | |
| `kri.manage` | ✓ | | | ✓ | | | |
| `kri.record` | ✓ | | ✓ | ✓ | ✓ | | |
| `approval.approve` | ✓ | | | ✓ | | ✓ | |
| `admin` (requireAdmin) | | | | | | | |

> Note: `admin` is intentionally blank across the row because it is satisfied
> only by the `admin` role's `*` wildcard, never by a default action grant.

## Appendix B — Source map

- RBAC roles & resolution: `src/Auth.php`
- Routing (≈407 routes): `index.php` (`$routes`, `$dynamicRoutes`)
- Security primitives (CSRF, sanitize, password policy, rate limit): `src/Security.php`
- App config (session/CSRF lifetimes, rate limits, password defaults): `config/app.php`
- Module controllers: `controllers/*Controller.php` (cited inline per story)
