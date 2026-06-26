# AEGIS GRC — Module Functional Specification

This document describes every major functional module of the AEGIS GRC platform: its purpose, features, responsibilities, inputs, outputs, business rules, validation rules, the exact `module.action` permission strings it enforces, error conditions, and edge cases.

It is written for an engineering team with **zero prior knowledge** of the codebase. Everything here is derived directly from the controller (`controllers/*Controller.php`) and view (`views/`) source code, with file paths and line references where useful. Where a behaviour is *absent* from the code, that is stated explicitly rather than invented.

---

## 0. Cross-Cutting Conventions (read this first)

These conventions apply to almost every module and are not repeated in each section.

### 0.1 The permission model

Permissions are checked through `src/Auth.php`. The relevant API:

- `Auth::requireAuth()` — redirects unauthenticated users to `/login` (storing the requested URI for post-login redirect), enforces session timeout, server-side session revocation (`sessions_revoked_at`), forced password change, and password expiry. (`src/Auth.php:363`)
- `Auth::requirePermission('module.action')` — calls `requireAuth()`, then `can()`; on failure returns HTTP 403 and renders `views/errors/403.php`. (`src/Auth.php:430`)
- `Auth::requireAdmin()` — alias for `requirePermission('admin')`. (`src/Auth.php:439`)
- `Auth::requirePlatformAdmin()` — gate for the SaaS-operator tier above tenant admins (`src/Auth.php:281`).
- `Auth::can('module.action')` — boolean test. Admin role short-circuits to `true`. Otherwise the granted set = role defaults (`roleDefaultPermissions()`) merged with per-user explicit DB grants from `user_permissions`. (`src/Auth.php:328`)

**Roles** (`Auth::ROLES`, `src/Auth.php:144`): `admin`, `manager`, `auditor`, `control_owner`, `risk_owner`, `analyst`, `executive`, `viewer`. Admin implicitly holds **all** permissions (`roleDefaultPermissions()` returns `['*']`).

**Backward-compat aliases** (`Auth::$aliases`, `src/Auth.php:185`): coarse strings like `risk.write`, `compliance.edit`, `audit.read`, `vendor.write`, etc. map to arrays of granular `module.action` strings. `Auth::can()` treats an alias as satisfied if **any** aliased granular permission is granted. This lets older code (e.g. `EvidenceController` checking `module.write`) keep working against the granular grant store.

The canonical module → action catalogue lives in `AdminController::permissions()` (`controllers/AdminController.php:438`) and is mirrored in `updatePermissions()`:

| Module | Actions |
| --- | --- |
| `risk` | view, create, edit, delete, accept, review, treatment, scenarios, bowtie, export |
| `compliance` | view, create, assess, import, test, gap |
| `audit` | view, create, edit, findings, close |
| `policy` | view, create, edit, publish, attest |
| `incident` | view, create, edit, close, playbook |
| `vendor` | view, create, edit, assess, contracts, questionnaire |
| `issue` | view, create, edit |
| `change` | view, create, edit, approve |
| `threat` | view, create, edit |
| `awareness` | view, manage |
| `asset` | view, create, edit |
| `kri` | view, manage, record |
| `bcp` | view, edit, exercise |
| `ssp` | view, edit |
| `report` | view |
| `automation` | view, manage |
| `approval` | view, approve |

> Several modules reuse another module's permission namespace: SSP/ODP use `ssp.*`; CUI, Privacy, POA&M, SPRS use `compliance.*`; Documents reuse `policy.*`; Playbooks use `incident.playbook`; Questionnaires use `vendor.questionnaire`; Calendar, RACI, Projects use `risk.view`/`risk.edit`. This is intentional and is noted per module.

### 0.2 CSRF

Every state-changing POST handler validates `Security::validateCsrf($_POST['csrf_token'] ?? '')` and returns HTTP 403 on mismatch. AJAX endpoints that mutate state typically return `{"ok":true,"new_csrf":...}` / `{"ok":true,"csrf":...}` so the client can rotate its in-memory token (e.g. `ComplianceController::bulkStatus`, `SSPController::saveStatement`, `TreatmentController::completeMilestone`).

### 0.3 Output & input safety

- All user output in views is wrapped in `Security::h()`.
- `Security::sanitizeInput()` is applied to scalar inputs; `Security::sanitizeHtml()` is used where rich content (policy/document bodies) is allowed.
- Enumerated fields are validated against in-code allow-lists; invalid values fall back to a safe default (not an error) in most handlers.
- Auto-incrementing human codes (`RSK-0001`, `VND-0001`, `AUD-0001`, etc.) are generated from `COALESCE(MAX(id),0)+1`.

### 0.4 Audit logging

Almost every mutation calls `Auth::log(action, entity_type, entity_id, context)` writing to `activity_log`. Password reset (no session) uses `Auth::logSystem()`.

### 0.5 Multitenancy

Postgres row-level security scopes all queries to the active tenant; `index.php` binds the tenant per request. Cross-tenant access is only possible for platform admins via the **Platform** module.

---

## 1. Risk (Core)

**Controller:** `controllers/RiskController.php` · **Views:** `views/risk/`

### Purpose
Central risk register: identify, score (likelihood × impact), treat, review, link to controls/KRIs/scenarios, and report on enterprise risks.

### Features
- Risk dashboard with heat map (L×I cells), top-10 open risks, 12-week portfolio score trend (`risk_score_history`), appetite-exceedance list, uncontrolled-risk list, upcoming reviews, recent score changes, treatment backlog.
- Filterable register (status, category, treatment strategy, source, owner, level, free-text search).
- 5×5 matrix view, treatment roadmap, bulk update.
- Inherent / residual / target scoring, velocity, proximity, confidence, financial impact (min/likely/max), parent/child hierarchy, related-risk links, control links with effectiveness, response actions (`risk_treatments`).
- Assessment workflow: draft → pending_review → approved (or back to draft).
- Control-effectiveness → suggested residual score (`view()`, `controlEffSuggestion`).

### Responsibilities
Owns the `risks` table plus `risk_score_history`, `risk_control_links`, `risk_related_links`, `risk_treatments`. Writes a `risk_score_history` row on create and on every update (full audit trail), even when the score is unchanged.

### Inputs
`title`, `description`, `category_id`, `likelihood`, `impact`, `velocity`, `proximity`, `risk_source`, `confidence`, `treatment_strategies[]`, `treatment_description`, `owner_id`, `parent_risk_id`, `financial_min/likely/max`, `review_date`, residual/target L&I (update), control-link and related-link params, response-action params, bulk-action params.

### Outputs
Rendered dashboard/index/view/matrix/roadmap; redirects to `/risk/{id}`; flash messages.

### Business Rules
- `inherent_score = likelihood * impact`.
- Editing a risk whose `assessment_status='approved'` resets it to `draft` (update SQL `CASE WHEN ... 'approved' THEN 'draft'`).
- Allowed strategies: `mitigate, accept, transfer, avoid`; statuses: `open, in_review, monitoring, accepted, closed, transferred`; sources: 9 values (`strategic`…`project`); proximities: `immediate, short_term, medium_term, long_term`.
- Risk level thresholds (via `RiskScore::sqlCondition`) used on dashboard; index summary uses score bands (`>14` critical, `10–14` high, `5–9` medium, `≤4` low). Roadmap uses different bands (`≥20`, `15–19`, `8–14`, `<8`).
- Control-link effectiveness ∈ `none, partial, substantial, full`; related link type ∈ `related, causes, caused_by, aggregates`.
- Bulk actions: status changes, strategy changes, or `submit_review`.

### Validation Rules
- `title` required (else `$_SESSION['risk_error']`).
- L/I/velocity clamped to 1–5 (`max(1,min(5,…))`).
- Enum fallbacks for proximity/source/confidence/status/strategy.
- Related link rejected if `relatedId === id`.

### Permissions
- `risk.view` — dashboard, index, view, matrix, roadmap.
- `risk.create` — createForm, create.
- `risk.edit` — update, linkControl/removeControlLink, linkRelated/removeRelatedLink, addResponseAction/updateResponseAction, bulkUpdate, editForm.
- `risk.review` — submitReview, approve, rejectReview.
- `risk.delete` — delete.

### Error Conditions
404 + `views/errors/404.php` when a risk id is not found in `view`/`update`-fetch paths; 403 on CSRF failure or missing permission; response-action requires non-empty description.

### Edge Cases
- `view()` wraps optional lookups (KRIs, active acceptance, scenarios, appetite) in `try/catch` so missing tables degrade gracefully.
- `delete()` is a hard `DELETE FROM risks`.
- Control suggestion only computed when controls are linked and L,I > 0.

---

## 2. Risk Acceptance (Certificates)

**Controller:** `controllers/RiskAcceptanceController.php` · **Views:** `views/risk/acceptances.php`, `acceptance_form.php`

### Purpose
Issue, renew, and revoke formal risk-acceptance certificates with validity windows and the score/level captured at the moment of acceptance.

### Features
List with status ordering (active → expired → superseded → revoked) and summary stats (active, expired, revoked, superseded, expiring-soon ≤30 days); issue form; renewal form pre-populated from an existing certificate; revoke with reason.

### Inputs
`acceptance_reason`, `conditions`, `valid_until`, `renewal_required`, `renewed_from`; `revocation_reason` (revoke).

### Outputs
Redirect to `/risk/{id}` (issue) or `/risk-acceptances` (revoke); flash messages.

### Business Rules
- Issuing supersedes any existing `active` acceptance for the risk (sets it `superseded`).
- Captures `risk_score_at_acceptance` and derived `risk_level_at_acceptance` (`scoreToLevel`: `>14` critical, `>9` high, `>4` medium, else low).
- `renewed_from` only honoured if the referenced acceptance belongs to the same risk.

### Validation Rules
`acceptance_reason` and `valid_until` required; `valid_until` must parse and be a **future** date (`<= today` rejected).

### Permissions
- `risk.view` — index.
- `risk.accept` — createForm, create, revoke, renew.

### Error Conditions
404 if risk or acceptance not found; flash errors with redirect back to the accept/renew form on validation failure.

### Edge Cases
Renewal carries forward reason/conditions/renewal_required as prefill; revoking a non-active certificate is allowed (no state guard beyond existence).

---

## 3. Risk Exceptions & Waivers

**Controller:** `controllers/RiskExceptionController.php` · **Views:** `views/risk/exceptions.php`, `exception_create.php`, `exception_view.php`

### Purpose
Request, review, and approve/reject time-boxed exceptions (waivers) against risks.

### Features
Role-scoped list (managers/admins see all; others see only their own requests) with summary stats (pending, approved, expiring-soon ≤30 days); request form; detail view; admin decision (approve/reject); CLI/admin expiry sweep.

### Inputs
`rationale`, `compensating_controls`, `expiry_date`, `exception_type`, `residual_risk_acknowledged`; decision `action` (`approve`/`reject`) + `rejection_reason`.

### Business Rules
- Exception types: `accept, transfer, defer` (default `accept`).
- New requests start `pending`; approval sets `approved_by/approved_at`; rejection records `rejection_reason`.
- `checkExpired()` flips `approved` exceptions past `expiry_date` to `expired`.

### Validation Rules
`rationale` required; if provided, `expiry_date` must be a future date.

### Permissions
Uses **role-based gates, not `requirePermission`** here: `Auth::requireAuth()` for index/create/view; `Auth::requireAdmin()` for `decide()` and (web) `checkExpired()`. Manager/admin visibility computed via `Auth::role()`.

### Error Conditions
404 if risk/exception missing; 403 on CSRF failure.

### Edge Cases
`checkExpired()` runs without auth when `php_sapi_name()==='cli'` (cron); otherwise requires admin. Expired-count is computed via a 5-second-window heuristic on `updated_at`.

---

## 4. Risk Reviews (Review Sessions)

**Controller:** `controllers/RiskReviewController.php` · **Views:** `views/risk/reviews.php`, `review_form.php`, `review_view.php`

### Purpose
Schedule periodic/triggered/board risk-review sessions that auto-populate a scoped set of risks, then walk reviewers through confirming/adjusting each.

### Features
List + status summary; scheduling form with scope filters (category, owner, min score, status); per-item review (confirm score, propose new L/I, treatment adequacy, action required, notes); start/complete/cancel; auto reschedule of `review_date` on completion based on score.

### Business Rules
- Review types: `periodic, triggered, ad_hoc, board`.
- On create, builds `risk_review_items` from the scope filter (excluding closed/transferred) and stores `total_risks`.
- Item statuses: `pending, reviewed, escalated, deferred, not_applicable`.
- If `score_confirmed=false` and new L/I supplied and differ, the **risk itself** is updated and a `risk_score_history` row written.
- Completion blocked while any item is `pending`. On completion, reviewed risks get `review_date = CURRENT_DATE + (90/180/365 days)` by inherent score band (`>14`/`>9`/else).

### Validation Rules
`title` + `scheduled_date` required; new L/I clamped 1–5; status filter limited to `open, in_review, monitoring`.

### Permissions
All methods require `risk.review`.

### Error Conditions
404 if review not found; flash error blocking completion when items pending.

### Edge Cases
`treatment_adequate='partial'` stores NULL (neither true nor false). `start()` only transitions reviews that are still `planned`.

---

## 5. Risk Scenarios

**Controller:** `controllers/ScenarioController.php` · **Views:** `views/risk/scenarios_index.php`, `scenario_form.php`

### Purpose
What-if scenario modelling per risk (stress/base/optimistic/catastrophic/regulatory) using likelihood/impact multipliers.

### Features
Global scenario list with per-type counts, highest scenario score, total financial estimate; per-risk create form; delete.

### Business Rules
- Scenario types: `stress, base, optimistic, catastrophic, regulatory` (default `stress`).
- Multipliers clamped to `[0.1, 5.0]`; `scenario_likelihood = min(5, round(risk.likelihood × lMult))`, same for impact; `scenario_score = L×I`.
- `probability` clamped 0–100.

### Validation Rules
`name` required; scenario type enum fallback.

### Permissions
All methods require `risk.scenarios`.

### Error/Edge
404 if risk/scenario not found; delete redirects to the owning risk.

---

## 6. Bow-Tie Analysis

**Controller:** `controllers/BowTieController.php` · **View:** `views/risk/bowtie.php`

### Purpose
Bow-tie diagrams per risk: causes (left), consequences (right), and preventive (left) / recovery (right) barriers, optionally linked to control implementations.

### Features
View the diagram with available controls for barrier linking; add/remove causes, consequences, and barriers (with side, type, effectiveness, sort order).

### Business Rules / Validation
- Cause type ∈ `threat, vulnerability, hazard, event`; likelihood contribution ∈ `low, medium, high`.
- Consequence type ∈ `financial, operational, reputational, legal, safety, impact`; severity ∈ `low, medium, high, critical`.
- Barrier side ∈ `left, right`; type ∈ `control, procedure, training, technology, monitoring`; effectiveness ∈ `degraded, partial, substantial, full`.
- Description required for each add; invalid enums fall back to defaults.

### Permissions
All methods require `risk.bowtie`.

### Error/Edge
404 if risk not found; remove operations redirect to the owning risk (or `/risk` if the row was already gone).

---

## 7. Treatment Plans

**Controller:** `controllers/TreatmentController.php` · **Views:** `views/treatment/`

### Purpose
Structured treatment plans (with milestones) attached to a risk, beyond ad-hoc response actions.

### Features
Plan list + stats (active, completed, overdue); create with inline milestones; detail with progress %; update; add/complete/delete milestones (AJAX milestone toggle returns progress JSON).

### Business Rules
- Plan code `TRT-####`; strategies `mitigate, transfer, accept, avoid`; statuses `draft, active, completed, cancelled`.
- `completeMilestone()` toggles completion and recomputes progress; auto-completes nothing else.
- A **completed** milestone cannot be deleted.

### Validation Rules
Plan `title` and milestone `title` required.

### Permissions
All methods require `risk.treatment`.

### Error/Edge
404 if risk/plan/milestone not found; CSRF failure on the AJAX milestone-complete returns JSON `{"ok":false,"error":"CSRF mismatch"}`.

---

## 8. KRI (Key Risk Indicators)

**Controller:** `controllers/KRIController.php` · **Views:** `views/kri/`

### Purpose
Define KRIs with RAG thresholds and record time-series values; surface RAG status linked to risks.

### Features
KRI dashboard with latest value (LATERAL join) and computed RAG; create; detail with last 24 values; record value; activate/deactivate toggle.

### Business Rules
- Direction ∈ `higher_worse, lower_worse`; frequency ∈ `daily, weekly, monthly, quarterly`.
- **Threshold ordering enforced**: for `higher_worse`, green ≤ amber ≤ red; for `lower_worse`, green ≥ amber ≥ red (else rejected).
- RAG (`ragStatus`): no value → `grey`; otherwise green/amber/red by direction-aware comparison.

### Validation Rules
`title` required; recorded `value` must be numeric.

### Permissions
- `kri.view` — index, view.
- `kri.manage` — createForm, create, toggle.
- `kri.record` — recordValue.

### Error/Edge
404 if KRI not found; create-form lists only `status='open'` risks for linking.

---

## 9. Threat Register

**Controller:** `controllers/ThreatController.php` · **Views:** `views/threat/`

### Purpose
Threat catalogue with likelihood/impact scoring and many-to-many links to risks.

### Features
Filterable list (category, status) with linked-risk counts and per-category stats; create; detail; update; link/unlink risks.

### Business Rules / Validation
- Threat number `THR-####`.
- Category ∈ `people, process, technology, natural, regulatory, financial`; status ∈ `active, mitigated, accepted, retired`.
- L/I clamped 1–5; `threat_score = likelihood × impact`.
- `linkRisk` validates the target risk exists **within the tenant** (RLS-scoped) before inserting, preventing cross-tenant linking; duplicate link insert caught → "Already linked".

### Permissions
- `threat.view` — index, view.
- `threat.create` — createForm, create.
- `threat.edit` — update, linkRisk, unlinkRisk.

### Error/Edge
404 if threat not found; `title` required on create/update.

---

## 10. Compliance (Packages, Controls, Testing, Gap)

**Controller:** `controllers/ComplianceController.php` (1131 lines) · **Views:** `views/compliance/`

### Purpose
Core compliance engine: frameworks (`standards`) → packages (`compliance_packages`) → domains (level 1) and controls (level 2, `compliance_objectives`) → control implementations (`control_implementations`), plus control testing, gap analysis, AI suggestions, and multi-format import.

### Features
- Package list with per-package compliance rollups; create/update/delete package; delete-selected; clear-all.
- Domain CRUD; control CRUD; single-control add; bulk status update; bulk assess (status + notes/evidence/due/assignee).
- Objective detail with implementation, child controls, mapped policies, recent audit findings.
- Control testing (`testControl`/`saveTest` → `control_tests`) and a testing dashboard.
- Scorecard per package; gap analysis (per-package stats, top gaps, cross-framework overlaps).
- AI suggestions via `AIAdvisor` (rate-limited).
- Import: JSON, CSV, Excel (.xlsx via ZipArchive, plus SpreadsheetML), PDF (poppler `pdftotext`). CSV/Excel templates downloadable.

### Business Rules
- Control statuses: `compliant, partial, non_compliant, not_started, not_applicable`.
- Levels: domains `level=1`, controls `level=2`; child controls cascade on domain delete.
- Deleting a package first removes dependent `audit_items`, nulls `audits.package_id`, and clears `audit_schedules` (no FK CASCADE on those).
- Test result ∈ `pass, fail, partial, not_tested`; effectiveness clamped 0–100; saving a test updates `control_implementations.last_reviewed`.
- AI suggestions rate-limited to 5/hour per user (HTTP 429 on exceed).

### Validation Rules
Package/domain/control require name/code/title as applicable; import enforces MIME + 20MB cap and required CSV columns (`package_name, domain_code, domain_title, control_code, control_title`).

### Permissions
- `compliance.view` — index, viewPackage, viewObjective, scorecard, aiSuggestions, testingDashboard.
- `compliance.create` — package CRUD, domain CRUD, control CRUD, bulkStatus, clearAll, deleteSelected.
- `compliance.assess` — updateObjective, bulkAssess.
- `compliance.import` — importForm/import, template downloads, addSingleControl.
- `compliance.test` — testControl, saveTest.
- `compliance.gap` — gapAnalysis.

### Error Conditions
404 on missing package/objective; JSON `{"ok":false,...}` for invalid bulk input; import errors collected in `$_SESSION['import_errors']`; PDF import returns a friendly error when `pdftotext` is unavailable.

### Edge Cases
PDF parser sanitizes filename for the package name and falls back to a placeholder domain if nothing parses. Excel import round-trips through a temp CSV (formula-injection guard intentionally skipped there to preserve imported `=` values).

---

## 11. SSP (System Security Plan)

**Controller:** `controllers/SSPController.php` (696 lines) · **Views:** `views/ssp/`

### Purpose
Author NIST/FedRAMP-style System Security Plans that aggregate one or more compliance packages, capture system metadata + extensive inventories, store per-control SSP implementation statements, and generate a printable/Word/PDF document.

### Features
- Plan CRUD; link/unlink compliance packages; per-objective SSP control statement upsert (AJAX, rotates CSRF).
- Rich metadata: system identity, owners, authorizing official, impact (C/I/A), authorization/review dates, version/revision, certification & approval blocks, CMMC/CAGE/DUNS, and nine JSONB inventory arrays (team contacts, contracts, data, hardware/software, network devices, connected systems, servers, user devices).
- File uploads: network architecture & data-flow diagrams (stored base64; downloadable).
- `generate()` renders a self-contained interactive document, a print-optimized PDF HTML (auto `window.print()`), or a Word-compatible `.doc`.

### Business Rules / Validation
- `title` required; at least one package required on create.
- Enum allow-lists for operational status, system type, impacts, approval status, presentation mode.
- File upload: ≤10MB, extension allow-list (`pdf,png,jpg,jpeg,gif,vsdx,docx,pptx`) **and** MIME validation; downloads forced as `application/octet-stream` with sanitized filename to prevent SVG/HTML execution.

### Permissions
- `ssp.view` — index, view, generate, downloadNetworkArch, downloadDataFlow.
- `ssp.edit` — createForm/create, saveStatement, addPackage/removePackage, update, delete.

### Error/Edge
404 if plan missing; duplicate package links swallowed via `try/catch`; the generated document escapes all fields with a local `$h` helper and sets security headers + nonce on the inline print script.

---

## 12. CUI Inventory

**Controller:** `controllers/CUIController.php` · **Views:** `views/cui/`

### Purpose
Inventory of Controlled Unclassified Information assets (CMMC/NIST 800-171 support).

### Features
List with stats (total, encrypted, distinct categories); create/view/update/delete; optional asset linkage.

### Business Rules / Validation
- Inventory number `CUI-####` (derived from max existing number).
- Storage type ∈ `database, file_share, cloud, email, paper, other`.
- `data_description` required.

### Permissions
- `compliance.view` — index, view.
- `compliance.assess` — createForm, create, update, delete.

### Error/Edge
404 if record not found.

---

## 13. ODP (Organization-Defined Parameters)

**Controller:** `controllers/ODPController.php` · **Views:** `views/odp/`

### Purpose
Capture organization-defined parameter values per control objective (e.g. NIST ODPs).

### Features
Package overview with ODP counts; per-package control view with aggregated ODP entries (JSON-built); upsert ODP entry by `(objective_id, parameter_name)`.

### Business Rules / Validation
`objective_id` and `parameter_name` required; redirect target derived from a **sanitized** `HTTP_REFERER` path (regex-validated, falls back to `/odp`) to prevent open redirect.

### Permissions
- `ssp.view` — index, packageView.
- `ssp.edit` — save.

### Error/Edge
404 if package not found; entries upserted (update if name already exists for objective).

---

## 14. SPRS Score

**Controller:** `controllers/SPRSController.php` · **View:** `views/sprs/index.php`

### Purpose
Compute and display SPRS-style scores (NIST 800-171 baseline 110) across packages, separating NIST-171/CMMC packages from others.

### Features
Read-only dashboard. For each package: `sprs_score = 110 − (non_compliant×1 + partial×0.5 + not_assessed×1)`; `pct = compliant/total`; NIST-171 detection by code/name substring (`171`, `NIST-171`, `800-171`, `CMMC`).

### Permissions
`compliance.view` (index only).

### Error/Edge
No mutations; entirely derived from compliance data.

---

## 15. Vendor (TPRM)

**Controller:** `controllers/VendorController.php` (652 lines) · **Views:** `views/vendor/`

### Purpose
Third-party risk management: vendor inventory, assessments, contracts, and a **public** self-assessment portal.

### Features
- Filterable vendor list (risk tier, status, search) + stats; create/view/update.
- Assessments: schedule (`addAssessment`), update with score/rating/findings/recommendations.
- Contracts: list (with expiring-soon ≤60 days), create/save/update.
- Vendor portal: `generatePortalLink` issues a hashed 32-byte token (30-day expiry) with default 10-question self-assessment; `portalView`/`portalSubmit` are **public, no-auth** endpoints.

### Business Rules
- Vendor code `VND-####`; risk tier ∈ `critical, high, medium, low`; status ∈ `active, inactive, under_review, terminated`; category constrained to a fixed list (else `Other`).
- Website URL accepted only if scheme is `http`/`https`.
- Assessment type ∈ `security, privacy, business_continuity, financial, operational`; rating ∈ `critical, high, medium, low, acceptable`; status ∈ `planned, in_progress, completed, overdue` (completed auto-sets `completed_date`).
- Contract status ∈ `draft, active, expired, terminated`; currency truncated to 3 chars.
- Portal CSRF uses HMAC-SHA256 of the token with `JWT_SECRET` (not session CSRF, since the user is anonymous); token is single-use (`used_at`).

### Validation Rules
Vendor `name` required; assessment `scheduled_date` required; contract `title`+`start_date` required; portal validates required questions server-side and `overall_score` clamped 0–100.

### Permissions
- `vendor.view` — index, view.
- `vendor.create` — createForm, create.
- `vendor.edit` — update.
- `vendor.assess` — addAssessment, generatePortalLink, updateAssessment.
- `vendor.contracts` — contracts, createContract, saveContract, updateContract.
- `portalView`/`portalSubmit` — **no permission** (public token-gated).

### Error/Edge
Portal renders standalone HTML pages for invalid/expired (404), already-submitted (410), and validation (422) states; token sanitized to hex only. Portal submission best-effort creates a completed `vendor_assessments` row (failure non-fatal).

---

## 16. Asset Inventory

**Controller:** `controllers/AssetController.php` · **Views:** `views/assets/`

### Purpose
IT/asset inventory with criticality, classification, and many-to-many risk links.

### Features
Filterable list (type, criticality, status) + summary + by-type breakdown; create/view/update; link/unlink risks.

### Business Rules / Validation
- Asset code `AST-####`; type ∈ 9 values (`server`…`saas`); criticality ∈ `critical, high, medium, low`; status ∈ `active, decommissioned, maintenance`.
- IP validated with `FILTER_VALIDATE_IP` (invalid → null); tags parsed from comma-separated → JSON array.
- `name` required.

### Permissions
- `asset.view` — index, view.
- `asset.create` — createForm, create.
- `asset.edit` — update, linkRisk, unlinkRisk.

### Error/Edge
404 if asset not found; risk-link insert uses `ON CONFLICT DO NOTHING`.

---

## 17. Audit (Internal Audits)

**Controller:** `controllers/AuditController.php` · **Views:** `views/audit/`

### Purpose
Plan and execute internal compliance audits against a package; assess each control item with findings/evidence; export an evidence ZIP package.

### Features
Filterable list + summary; create (auto-populates `audit_items` from the package's level-2 controls and optionally seeds a recurring `audit_schedules` entry); per-item assessment with evidence file upload (AJAX, returns JSON); audit-level update; complete (computes score); evidence ZIP export; per-item evidence list (JSON).

### Business Rules
- Audit number `AUD-####`; type ∈ `internal, external, gap, follow_up`; frequency ∈ `one_time, monthly, quarterly, biannual, annual`; status ∈ `planned, in_progress, completed, overdue, cancelled`.
- Item status ∈ `not_assessed, compliant, non_compliant, partial, not_applicable`; risk level ∈ `low, medium, high, critical` or null.
- Completion score = compliant / total × 100.
- Setting status `in_progress` sets `start_date` if null.

### Validation Rules
`name` + `scheduled_date` required; evidence upload: ≤10 files/submission, ≤20MB each, MIME + extension allow-list, randomized stored filename (`bin2hex(16).ext`), SHA-256 hash recorded.

### Permissions
- `audit.view` — index, view, exportPackage, itemEvidence.
- `audit.create` — createForm, create.
- `audit.edit` — updateItem, update, editForm.
- `audit.close` — complete.

### Error/Edge
404 if audit missing; export requires `ZipArchive` (HTTP 500 otherwise); ZIP includes `findings.csv`, `README.txt`, and de-duplicated evidence paths; stored filenames validated against a hex regex before being added to the ZIP.

---

## 18. Audit Findings (External)

**Controller:** `controllers/AuditFindingController.php` · **Views:** `views/audit_findings/`

### Purpose
Track findings from external audits, pentests, certifications, regulators, etc., with severity, owner, deadline, threaded updates, and closure.

### Features
List ordered by severity + stats (total, open, critical/high open, overdue); create/view/update; add threaded update (`finding_updates`); close; delete.

### Business Rules / Validation
- Finding number `FIND-####`.
- Severity ∈ `critical, high, medium, low, info`; status ∈ `open, in_progress, resolved, risk_accepted, closed`; source ∈ `external_audit, pentest, certification, assessment, regulatory, other`.
- Closing sets `closed_at` and appends an automatic "Finding closed." update.
- `title` required on create; enum fallbacks on invalid input.

### Permissions
All methods require `audit.findings`. (`createForm` redirects to `/audit-findings` — creation is inline.)

### Error/Edge
404 if finding missing; empty update content rejected.

---

## 19. Policy

**Controller:** `controllers/PolicyController.php` (477 lines) · **Views:** `views/policy/`

### Purpose
Policy lifecycle (draft → review → published → archived), versioning, control mapping, and attestation campaigns.

### Features
Filterable list (status, package, review window) + summary; policy ↔ control mapping view; create (seeds `policy_versions` v1.0); view with versions/mappings/reviews/available objectives; update (save / submit_review / approve / publish / archive, optional new version); map/unmap objectives; attestation campaigns (list/create/view matrix) and per-user attest + "my attestations".

### Business Rules
- Policy number `POL-####`; review frequencies `monthly, quarterly, biannual, annual, biennial`; default `next_review_date` computed from frequency if not supplied.
- Status transitions gated: `publish`, `approve`, `archive` additionally require `policy.publish`; `submit_review` does not.
- Content stored via `Security::sanitizeHtml` (rich text allowed).
- Attestation upsert keyed `(policy_id, user_id)`, records IP and notes; campaigns reference `published` policies only.

### Validation Rules
`title` required (create); campaign requires `policy_id`+`title`; attest requires the `confirmed` checkbox.

### Permissions
- `policy.view` — index, mapping, view, attestations, viewCampaign.
- `policy.create` — createForm, create.
- `policy.edit` — update, mapObjective, unmapObjective, editForm.
- `policy.publish` — required (additionally) for publish/approve/archive inside `update()`.
- `policy.attest` — createCampaign, saveCampaign, attestForm, attest, myAttestations.

### Error/Edge
404 if policy/campaign missing; attestation failure caught and surfaced as a flash error.

---

## 20. Documents

**Controller:** `controllers/DocumentController.php` · **Views:** `views/documents/`

### Purpose
General document register with classification, lifecycle status, and versioned file uploads — **reuses the `policy.*` permission namespace.**

### Features
Filterable list (status, classification, search); create; view with version history; update; upload new version (file).

### Business Rules / Validation
- Classification ∈ `public, internal, confidential, restricted`; statuses include `draft, under_review, approved, published, archived, expired`.
- Upload: MIME allow-list (PDF/Office/text/CSV) + extension allow-list (`pdf,txt,csv,doc,docx,xls,xlsx`), ≤50MB, randomized stored filename, SHA-256 hash, stored under `uploads/documents/`.
- `title` required.

### Permissions
- `policy.view` — index, view.
- `policy.create` — createForm, create.
- `policy.edit` — update, uploadVersion.

### Error/Edge
404 if document missing; bad MIME/extension/size produce flash errors and redirect back.

---

## 21. Playbooks (Incident Response)

**Controller:** `controllers/PlaybookController.php` · **Views:** `views/playbook/`

### Purpose
Reusable incident-response playbooks with ordered steps; attach a playbook to an incident as a "run" and track step completion.

### Features
List with step/run counts; create with inline steps (role, due minutes); view with steps and recent runs; `startRun` (attach to incident); `completeStep` (AJAX, auto-completes the run when all steps done); activate/deactivate toggle.

### Business Rules / Validation
- `title` required.
- A run is unique per `(incident_id, playbook_id)` (duplicate attach caught → flash error).
- Step completion upserted on `(run_id, step_id)`; run marked complete when completed-steps ≥ total-steps.

### Permissions
All methods require `incident.playbook`.

### Error/Edge
404 if playbook/incident/run missing; only `is_active` playbooks can start a run.

---

## 22. Issues

**Controller:** `controllers/IssueController.php` · **Views:** `views/issue/`

### Purpose
Lightweight issue tracker that can originate from audits/risks/incidents/compliance or be created manually, with threaded updates.

### Features
Filterable list (severity, status, assignee) + stats; create; view with update thread; update; add update (optionally driving a status change).

### Business Rules / Validation
- Issue number `ISS-####`.
- Severity ∈ `critical, high, medium, low`; status ∈ `open, in_progress, pending_review, resolved, closed, wont_fix`; source type ∈ `audit, risk, incident, manual, compliance`.
- Setting status `resolved` sets `resolved_at`.
- Update type ∈ `comment, status_change, assignment`.
- `title` required (create); update content required.

### Permissions
- `issue.view` — index, view.
- `issue.create` — createForm, create.
- `issue.edit` — update, addUpdate.

### Error/Edge
404 if issue missing.

---

## 23. BCP / DR (Business Continuity)

**Controller:** `controllers/BCPController.php` · **Views:** `views/bcp/`

### Purpose
Business continuity / disaster recovery plans with RTO/RPO, sections, and exercises.

### Features
List with exercise/section counts; create with inline sections; view (sections + exercises); update; add exercise (sets plan `last_tested` when conducted).

### Business Rules / Validation
- Plan code `BCP-####`; status ∈ `draft, active, archived`.
- Exercise type ∈ `tabletop, walkthrough, full_scale`; outcome ∈ `passed, passed_with_findings, failed, cancelled` or null.
- `title` required; `title`/`version` length-capped (255/50).

### Permissions
- `bcp.view` — index, view.
- `bcp.edit` — createForm, create, update.
- `bcp.exercise` — addExercise.

### Error/Edge
404 if plan missing; missing title redirects to create with `?error=missing`.

---

## 24. Incidents & SLA

**Controller:** `controllers/IncidentController.php` · **Views:** `views/incident/`

### Purpose
Security-incident management with lifecycle, threaded updates, playbook runs, acknowledgement, and an SLA report.

### Features
Filterable list (severity, status, search) + summary; create; view (updates + playbook runs with step state + available playbooks to start); update; add update (optionally changing status); close; acknowledge (records `incident_sla_events`); SLA report computing per-incident ack/resolve SLA status.

### Business Rules
- Incident number `INC-####`.
- Severity ∈ `critical, high, medium, low`; status ∈ `open, investigating, contained, resolved, closed`; category constrained to a fixed list (else `Other`).
- Status `contained` sets `contained_at`; `resolved`/`closed` set `resolved_at`.
- SLA status (`slaStatus`): `met` if event recorded; else `breached`/`at_risk` (>75%)/`on_track` based on elapsed vs `incident_sla_policies` hours; `n/a` if no policy.
- Acknowledge is idempotent (one `acknowledged` event per incident).

### Validation Rules
`title` required (create); update content required.

### Permissions
- `incident.view` — index, view, slaReport.
- `incident.create` — createForm, create.
- `incident.edit` — update, addUpdate, acknowledge.
- `incident.close` — close.

### Error/Edge
404 if incident missing; `detected_at` defaults to now if not provided.

---

## 25. Questionnaires

**Controller:** `controllers/QuestionnaireController.php` (391 lines) · **Views:** `views/questionnaire/`

### Purpose
Reusable scored questionnaires (sectioned questions) assigned to users/entities, with respond + auto-scoring — **reuses `vendor.questionnaire`.**

### Features
List (with question/assignment counts) + "my assignments"; create with inline questions; view (grouped by section, assignments); assign to a user/entity with due date; respond (loads in-progress answers); submit response (auto-scores).

### Business Rules
- Entity types: `general, vendor, audit`; question types: `text, scale, boolean, choice`; weight clamped 1–5.
- Scoring on submit: `scale` → (value/5)×weight (maxScore += weight); `boolean` → weight if "yes" (maxScore += weight); `choice` → 1 if answered (maxScore += 1); `text` → unscored.
- Assignment marked `submitted` with `response_id`.

### Validation Rules
`title` required; only the assigned user **or an admin** may respond/submit (else 403).

### Permissions
All methods require `vendor.questionnaire` (plus assigned-user/admin check on respond/submit).

### Error/Edge
404 if questionnaire/assignment missing; `assign`/`submitResponse` require POST (GET redirects).

---

## 26. Awareness Training

**Controller:** `controllers/AwarenessController.php` · **Views:** `views/awareness/`

### Purpose
Security-awareness programs assigned to users, with completion tracking.

### Features
Program list with assigned/completed counts; create (assign selected users and/or all active users); view (assignment matrix); user self-mark complete; bulk assign; delete.

### Business Rules / Validation
- Content type ∈ `document, video, policy, quiz`.
- `title` required; duplicate assignments swallowed via `try/catch`.

### Permissions
- `awareness.view` — index, view, complete (a user marking *their own* completion).
- `awareness.manage` — createForm, create, assign, delete.

### Error/Edge
404 if program missing; `complete` updates only the current user's assignment row.

---

## 27. Data Privacy (RoPA & DSRs)

**Controller:** `controllers/PrivacyController.php` · **Views:** `views/privacy/`

### Purpose
GDPR-style Records of Processing Activities plus Data Subject Requests — **reuses `compliance.*`.**

### Features
Privacy dashboard (records + open DSRs + stats incl. DPIA-due); processing-activity CRUD; DSR list/create/update.

### Business Rules / Validation
- Legal basis ∈ `consent, legitimate_interest, contract, legal_obligation, vital_interests, public_task`.
- DSR type ∈ `access, erasure, rectification, portability, objection, restriction`; DSR status ∈ `open, in_progress, completed, rejected` (completed sets `completed_at`).
- Record `name` required.

### Permissions
- `compliance.view` — index, view, requests.
- `compliance.assess` — createForm/create, delete, createRequest, updateRequest. (Note: creating a DSR is deliberately an assess-level action so view-only users can't write — see inline comment at `createRequest`.)

### Error/Edge
404 if record missing.

---

## 28. GRC Projects

**Controller:** `controllers/ProjectController.php` · **Views:** `views/projects/`

### Purpose
Lightweight project management for GRC initiatives, with tasks and links to risks/controls/issues/findings — **reuses `risk.view`/`risk.edit`.**

### Features
Project list with task progress + stats (incl. total budget); create; view (tasks + links); update; delete; add/complete/delete task; add/remove entity link.

### Business Rules / Validation
- Project code `PRJ-####`; priority ∈ `low, medium, high, critical`; status ∈ `planning, active, on_hold, completed, cancelled`.
- Link entity types: `risk, control, issue, finding` (else rejected); links upserted `ON CONFLICT DO NOTHING`.
- Any task/link mutation bumps the project's `updated_at`.
- `title` required (create); task `title` required.

### Permissions
- `risk.view` — index, view.
- `risk.edit` — create, update, delete, task ops, link ops.

### Error/Edge
404 if project missing.

---

## 29. RACI & Shared Responsibility

**Controller:** `controllers/RACIController.php` · **Views:** `views/raci/`

### Purpose
Per-package RACI matrices (domain × user) and cloud shared-responsibility matrices (control × customer/provider/shared) — **reuses `risk.view`/`risk.edit`.**

### Features
Package list; RACI matrix view + save (delete-then-insert); shared-responsibility view + save (upsert).

### Business Rules / Validation
- RACI roles ∈ `responsible, accountable, consulted, informed` (invalid skipped).
- Responsibility ∈ `customer, provider, shared` (default `customer`).
- `save()` clears all assignments for the package, then re-inserts from the posted matrix.

### Permissions
- `risk.view` — index, view, responsibilityMatrix.
- `risk.edit` — save, saveResponsibility.

### Error/Edge
404 if package missing; duplicate RACI inserts swallowed.

---

## 30. POA&M (Plan of Action & Milestones)

**Controller:** `controllers/POAMController.php` · **Views:** `views/poam/`

### Purpose
FedRAMP/NIST POA&M items with milestones, auto-generated from non-compliant controls, manually created, or CSV-imported — **reuses `compliance.*`.**

### Features
List with milestone progress (ordered open → in_progress → closed → cancelled); auto-generate from a package's non-compliant/partial controls; manual create; CSV import; view (with linked control); update; delete; add/complete milestone.

### Business Rules
- POA&M number `POAM-####` (derived via regex on existing numbers).
- `generate()` skips controls that already have a POA&M (`objective_id` unique guard); only `non_compliant`/`partial`/unassessed controls.
- Status ∈ `open, in_progress, closed, cancelled`.
- CSV import maps `package` name → id and `owner_email` → user id; parses `scheduled_completion`.

### Validation Rules
`title` required (manual); CSV requires a `title` column, ≤5MB, MIME/extension checks; row column-count mismatch reported.

### Permissions
- `compliance.view` — index, view.
- `compliance.assess` — generate, create, importCsv, update, delete, addMilestone, completeMilestone.

### Error/Edge
404 if item/milestone missing; generate reports when no eligible controls exist or all already have items.

---

## 31. Reports

**Controller:** `controllers/ReportController.php` · **Views:** `views/report/`

### Purpose
Read-only management reporting: compliance status, executive summary, board pack, and risk register/detail reports.

### Features
- `compliance` — per-package status, non-compliant items, recent control changes, overall %.
- `executive` — weighted GRC score (compliance 40% / risk 30% / policy 20% / audit 10%), top risks, reviews due.
- `board` — comprehensive board pack: risk summary/top risks/trend/by-category, compliance per package, incident summary, upcoming reviews, appetite breaches, treatment backlog, KRI health.
- `risk` / `riskDetail` — risk register reports with category breakdowns and open treatments.

### Business Rules
- Org name pulled from `settings.org_name` (defaults to "Organization").
- Risk health penalizes critical (×20) and high (×10) per total.
- `executive` tolerates a missing `incidents` table (`try/catch`).

### Permissions
All methods require `report.view`.

### Error/Edge
Purely read; no 404 paths (aggregate queries).

---

## 32. Documents — Evidence Files

**Controller:** `controllers/EvidenceController.php` · **Views:** rendered inline / JSON

### Purpose
Generic evidence-file attachment for many entity types (control, risk, audit, incident, policy, vendor, issue) with IDOR protection.

### Features
Upload, download, delete, and list (JSON) evidence for an entity.

### Business Rules
- Entity types: `control, risk, audit, incident, policy, vendor, issue`; each maps to a permission module (`control→compliance`, etc.).
- **Upload** requires `Auth::can(module.write)` for the target entity (IDOR prevention).
- **Download/list** require `canAccessEntity()` → admin or `Auth::can(module.read)`.
- **Delete** allowed only to the uploader or an admin.
- Allowed extensions/size are **settings-driven** (`upload_allowed_types`, `upload_max_size_mb`); MIME validated against an allow-list; stored as `bin2hex(16).ext` under `uploads/`; SHA-256 recorded.
- Download forces `attachment`, RFC 5987 filename encoding, `nosniff`, and downgrades non-whitelisted MIME to `application/octet-stream`.

### Permissions
`Auth::requireAuth()` plus the per-entity `module.read`/`module.write` checks above (admin bypass).

### Error Conditions
403 on insufficient permission or CSRF; 404 if record/file missing; 400 on invalid `stored_name` (path-traversal guard via hex regex).

### Edge Cases
`redirectBack()` validates `HTTP_REFERER` to same-origin local paths only (open-redirect guard).

---

## 33. Export

**Controller:** `controllers/ExportController.php` · **Views:** `views/export/index.php`

### Purpose
Per-entity and multi-entity data export to CSV/JSON, with per-type permission gating.

### Features
Single-type download (`/export/download`) and multi-type ZIP (`/export/download-all`).

### Business Rules
- Export types and their required permission (`ExportController::$exportTypes`): `risks→risk.view`, `policies→policy.view`, `audits→audit.view`, `incidents→incident.view`, `vendors→vendor.view`, `controls→compliance.view`, `assets→report.view`, `activity_log→admin`.
- Each type's permission is enforced at download time (`requireAdmin()` for `activity_log`, else `requirePermission`).
- CSV output prepends a UTF-8 BOM and runs every row through `Csv::row()` (formula-injection guard).
- Multi-export filters out types the user lacks permission for.

### Permissions
`Auth::requireAuth()` to view; per-type permission at download.

### Error/Edge
400 on invalid export type; ZIP path requires `ZipArchive`; assets query wrapped in `try/catch` (table may not exist); activity_log capped at 50,000 rows.

---

## 34. Import (Bulk CSV)

**Controller:** `controllers/ImportController.php` · **Views:** `views/import/index.php`

### Purpose
Transactional bulk CSV import of risks, vendors, and incidents.

### Features
Upload + parse + validate + insert; redirects to the relevant module on success.

### Business Rules
- Types: `risks, vendors, incidents`.
- Risks require `title, likelihood, impact` (L/I must be 1–5; `inherent_score=L×I`); category resolved by name.
- Vendors require `name`; website scheme-validated.
- Incidents require `title, severity`; incident number `INC-####`.
- Row column-count mismatch aborts the whole import (no partial writes per file).

### Validation Rules
CSV/TXT extension + MIME allow-list; ≤10MB; required-header check per type.

### Permissions
All require `compliance.import`.

### Error/Edge
Errors surfaced via flash (first 5 shown); empty CSV / missing rows rejected. (Note: `importVendors` writes legacy columns `risk_rating`/`contact_name` — see Vendor module which uses `risk_tier`.)

---

## 35. Calendar

**Controller:** `controllers/CalendarController.php` · **Views:** `views/calendar/index.php`

### Purpose
Unified compliance calendar aggregating due dates across modules.

### Features
Month grid (`index`) and JSON feed (`feed`). Aggregates: controls due (non-compliant/not_applicable excluded from "due"), policy reviews (published), audit schedules (non-completed), and risk-treatment due dates.

### Business Rules / Validation
Month/year clamped (1–12; 2000–2099). Each event carries `title`, `type`, and a deep-link `url`.

### Permissions
Both methods require `risk.view`.

### Error/Edge
Read-only; events keyed by date.

---

## 36. Global Search

**Controller:** `controllers/SearchController.php` · **Views:** `views/search/index.php`

### Purpose
Cross-module full-text (ILIKE) search.

### Features
Searches up to 7 entity types: risks, policies, audits, vendors, controls, assets (each capped at 10 results), merged keyed by type.

### Business Rules
- **Each block is gated by the viewer's module permission** (`Auth::can('risk.view')`, etc.) so search never leaks records the user cannot open.
- Minimum query length 2 (shorter → "too short").
- Per-block queries wrapped in `try/catch` (logged, never fatal).

### Permissions
`Auth::requireAuth()` (then per-block `Auth::can(...)`).

### Error/Edge
Empty query renders the plain search page; closed risks excluded.

---

## 37. Approvals (Multi-Level Chains)

**Controller:** `controllers/ApprovalController.php` · **Views:** `views/approval/`

### Purpose
Template-driven multi-step approval workflows used to gate sensitive state changes (risk acceptance, policy publish, etc.), with Segregation-of-Duties enforcement.

### Features
- **Static API for other controllers:** `requiresApproval()`, `isPending()`, `createRequest()`.
- Pending-approvals inbox (filtered to the current step's required user/role, admin sees all); review page; decide (approve/reject); admin template management (list/create/save/toggle).
- On final approval, `applyApproval()` performs the originally-requested status change.

### Business Rules
- Templates match by `entity_type` + `trigger_condition` JSON (`min_score`, `status_change`, `risk_tier`).
- A request advances step-by-step; rejection finalizes immediately.
- **SoD:** the requester cannot approve their own request; the entity creator cannot approve their own risk/policy/change (both blocked and logged as `sod_violation_blocked`).
- Step notification writes `alerts` rows to the required user or all users of the required role.

### Validation Rules
Decision ∈ `approved, rejected`; template entity type ∈ `risk, policy, change, audit, incident, vendor`.

### Permissions
- `approval.view` — pending, review.
- `approval.approve` — decide.
- `Auth::requireAdmin()` — templates, createTemplate, saveTemplate, toggleTemplate.

### Error/Edge
404 if request missing; 403 if not authorized for the step or on CSRF; request already closed handled gracefully.

---

## 38. Automation Rules

**Controller:** `controllers/AutomationController.php` · **Views:** `views/automation/`

### Purpose
No-code automation: trigger conditions → actions (create issue, webhook, email, assign user), with logging and a dry-run tester.

### Features
Rule list with 7-day success/failure counts; create; view (config + last 20 logs); toggle; delete; `testRun` (AJAX dry-run that previews matching items per trigger type).

### Business Rules
- Triggers (`TRIGGER_LABELS`): `risk_score_high, control_non_compliant, audit_overdue, incident_created, policy_review_due, vendor_contract_expiring, scheduled_daily, scheduled_weekly`.
- Actions (`ACTION_LABELS`): `create_issue, send_webhook, send_email_notification, assign_user`.
- Trigger/action configs serialized to JSON (e.g. `threshold`, `days_before`, issue title/severity, webhook URL, recipients/subject, assignee).

### Validation Rules
`name` required; trigger and action must be known keys (else flash error).

### Permissions
- `automation.view` — index, view.
- `automation.manage` — createForm, create, toggle, delete, testRun.

### Error/Edge
404 if rule missing; `testRun` swallows query exceptions, logs server-side, and returns a generic message (no internal leakage).

---

## 39. Webhooks

**Controller:** `controllers/WebhookController.php` · **Views:** `views/admin/webhooks.php`, `webhook_form.php`, `webhook_deliveries.php`

### Purpose
Outbound webhook endpoints subscribing to a large catalogue of platform events, with provider presets and delivery history. **Admin-only.**

### Features
Endpoint list with delivery stats; create/edit; toggle active; delete (cascades deliveries); deliveries view (last 50).

### Business Rules
- ~31 event types across risks/incidents/audits/compliance/changes/policies/vendors/issues/assets/BCP.
- Providers: generic, slack, teams, jira, pagerduty, servicenow, discord, google_chat, opsgenie, datadog, splunk.
- Secret stored via `Security::encryptSetting()`; custom headers must be a valid JSON object.

### Validation Rules
`name` required; URL must validate as http/https; event types filtered to known keys; provider falls back to `generic`.

### Permissions
All methods require `admin` (`Auth::requirePermission('admin')`).

### Error/Edge
404 if endpoint missing; invalid headers/URL produce flash errors and redirect back.

---

## 40. Auth (Login, MFA, Password Reset)

**Controller:** `controllers/AuthController.php` (524 lines) · **Views:** `views/auth/`

### Purpose
Authentication: login, TOTP MFA (setup/verify/disable/backup codes), forgot/reset password.

### Features
- Login with CSRF + rate limit; MFA enforcement for privileged roles (`settings.mfa_enforcement`); MFA verify (TOTP ±1 window, replay protection via `totp_used_codes`, rate-limited); MFA setup (secret only persisted after successful verify); disable MFA; backup codes (8× `XXXX-XXXX`, Argon2id-hashed, single-use); forgot/reset password.

### Business Rules
- Post-login redirect honoured only for safe local paths and never to `/admin`, `/login`, `/mfa`.
- TOTP replay blocked within the 90-second window; used codes cleaned after 10 minutes.
- Password reset: token hashed (SHA-256, 1-hour expiry), single-use; new password must pass `validatePasswordPolicy`, must match confirm, and is checked against the last 12 password hashes (history reuse rejection).
- Forgot-password always returns the same message (no account enumeration).

### Validation Rules
Email + password required; reset passwords must match and satisfy policy + history; MFA codes whitespace-stripped.

### Permissions
- Public: loginForm, login, forgotPassword(Form), resetPassword(Form), mfaVerify(Form), mfaBackupVerify.
- `Auth::requireAuth()`: logout, mfaSetup(Form)/Verify, mfaDisable, backupCodes(Form), generateBackupCodes.

### Error Conditions
Invalid credentials/locked → generic error; rate-limit exceeded; invalid/expired reset token; invalid/used MFA or backup code.

### Edge Cases
On MFA pending, the session is destroyed/recreated and the post-login redirect captured **before** destruction (comment at `login()`). Backup-code login regenerates the session id (fixation protection).

---

## 41. Profile

**Controller:** `controllers/ProfileController.php` · **Views:** `views/profile/`

### Purpose
Self-service profile: notification preferences, profile edit, and password change.

### Features
Notification preferences (12 toggle types + a `__digest__` delivery row: immediate/daily/weekly + time); profile edit (name, email); change password.

### Business Rules / Validation
- Name 2–100 chars; email valid and unique (excluding self).
- Change password: verifies current password, confirm match, policy, and rejects reuse of the **last 10** passwords; records history (trimmed to 15) and clears `force_password_change`.
- Digest mode ∈ `immediate, daily, weekly`.

### Permissions
All methods require `Auth::requireAuth()` (no module permission — self-service).

### Error/Edge
403 on CSRF; flash errors on validation failure.

---

## 42. Admin (Administration Console)

**Controller:** `controllers/AdminController.php` (1654 lines) · **Views:** `views/admin/`

### Purpose
Tenant administration: users, granular permissions, branding, settings, security policy, storage, retention, sessions, API keys, audit-log viewer, risk-matrix config, custom fields, risk appetite, SLA policy, workflows, email config/templates, scheduled reports, alert configs, and module visibility.

### Features (representative methods)
- **Users:** create (sends email-verification token, 24h), update (password change, deactivation revokes sessions + API keys), soft-delete (cannot delete self), edit form.
- **Permissions:** two-pane IAM editor; `permissions()` exposes the full module→action catalogue, role defaults (manager/analyst/viewer), and per-user grants from `user_permissions`; `updatePermissions()` saves explicit grants (supports AJAX with JSON CSRF errors).
- **Branding:** `saveBranding`/`uploadLogo`/`removeLogo` (logo URL or uploaded data: URL, org name, accent color).
- **Settings/Security:** `saveSettings`, `securityPolicy`/`saveSecurityPolicy`, `storage`/`saveStorage`/`testStorage`, `retention`/`saveRetention`/`runRetention`.
- **Sessions:** list active sessions; `killSession`.
- **API keys:** create/revoke.
- **Audit log:** `logs` (viewer) + `exportLogs`.
- **Config:** `riskMatrix`/`updateRiskMatrix`, `customFields`/`saveCustomField`/`deleteCustomField`, `riskAppetite`/`saveRiskAppetite`, `slaPolicy`/`saveSlaPolicy`, `workflows`/`createWorkflow`/`toggleWorkflow`, `email`/`saveEmail`/`testEmail`, `emailTemplates`/`emailTemplateForm`/`updateEmailTemplate`/`previewEmailTemplate`, `scheduledReports` (+ CRUD), `alertConfig*`, `moduleVisibility`/`saveModuleVisibility`.

### Business Rules / Validation
- User create: name+email required, password ≥8 chars, email unique; role validated via `Auth::isValidRole()` (else `viewer`).
- User update: password (if supplied) ≥8 chars; deactivation sets `sessions_revoked_at` and disables API keys.
- Self-delete blocked; delete is a soft-delete (deactivate + revoke).

### Permissions
Almost every method uses `Auth::requireAdmin()` (i.e. `admin`). `saveBranding` and `uploadLogo` use `Auth::requirePermission('admin')` (equivalent).

### Error Conditions
403 on CSRF / non-admin; 404 on missing user/record; validation errors surfaced via `$_SESSION['user_errors']`.

### Edge Cases
Email-verification send wrapped in `try/catch` (non-fatal — the user can still log in unverified). `updatePermissions` returns a JSON 403 when called via XHR with a bad CSRF token.

---

## 43. Platform (Cross-Tenant Operator)

**Controller:** `controllers/PlatformController.php` · **Views:** `views/platform/tenants.php`

### Purpose
SaaS-operator tier (above tenant admins): switch into a tenant's context for support, audited and time-boxed.

### Features
Tenant picker (`tenants`); `switchTenant` (enters a tenant context, reverts automatically within the hour); `exitTenant` (return to home tenant).

### Business Rules
- All methods require `Auth::requirePlatformAdmin()`.
- Tenant binding takes effect on the **next** request (via `Auth::activeTenantId()` in `index.php`); switching is audited by `Auth::switchTenant()`; there is no implicit cross-tenant bypass.

### Permissions
Platform-admin only (a distinct tier; not a `module.action` permission).

### Error/Edge
403 on CSRF; switch failure surfaced as a flash error from the thrown exception message.

---

## Appendix A — Module → Permission Quick Reference

| Module | Permission strings enforced |
| --- | --- |
| Risk core | `risk.view`, `risk.create`, `risk.edit`, `risk.review`, `risk.delete` |
| Risk acceptance | `risk.view`, `risk.accept` |
| Risk exceptions | role-gated (`requireAuth` / `requireAdmin`) |
| Risk reviews | `risk.review` |
| Scenarios | `risk.scenarios` |
| Bow-tie | `risk.bowtie` |
| Treatment plans | `risk.treatment` |
| KRI | `kri.view`, `kri.manage`, `kri.record` |
| Threat | `threat.view`, `threat.create`, `threat.edit` |
| Compliance | `compliance.view`, `compliance.create`, `compliance.assess`, `compliance.import`, `compliance.test`, `compliance.gap` |
| SSP | `ssp.view`, `ssp.edit` |
| CUI | `compliance.view`, `compliance.assess` |
| ODP | `ssp.view`, `ssp.edit` |
| SPRS | `compliance.view` |
| Vendor | `vendor.view`, `vendor.create`, `vendor.edit`, `vendor.assess`, `vendor.contracts`; portal = public |
| Asset | `asset.view`, `asset.create`, `asset.edit` |
| Audit | `audit.view`, `audit.create`, `audit.edit`, `audit.close` |
| Audit findings | `audit.findings` |
| Policy | `policy.view`, `policy.create`, `policy.edit`, `policy.publish`, `policy.attest` |
| Documents | `policy.view`, `policy.create`, `policy.edit` |
| Playbooks | `incident.playbook` |
| Issues | `issue.view`, `issue.create`, `issue.edit` |
| BCP | `bcp.view`, `bcp.edit`, `bcp.exercise` |
| Incidents | `incident.view`, `incident.create`, `incident.edit`, `incident.close` |
| Questionnaires | `vendor.questionnaire` |
| Awareness | `awareness.view`, `awareness.manage` |
| Privacy | `compliance.view`, `compliance.assess` |
| Projects | `risk.view`, `risk.edit` |
| RACI | `risk.view`, `risk.edit` |
| POA&M | `compliance.view`, `compliance.assess` |
| Reports | `report.view` |
| Evidence | `requireAuth` + per-entity `module.read`/`module.write` |
| Export | `requireAuth` + per-type (`risk.view`…`admin`) |
| Import | `compliance.import` |
| Calendar | `risk.view` |
| Search | `requireAuth` + per-block `module.view` |
| Approvals | `approval.view`, `approval.approve`, `admin` (templates) |
| Automation | `automation.view`, `automation.manage` |
| Webhooks | `admin` |
| Auth | public / `requireAuth` |
| Profile | `requireAuth` |
| Admin | `admin` (`requireAdmin`) |
| Platform | platform-admin (`requirePlatformAdmin`) |

---

*Document generated from source review of `controllers/` and `views/` plus `src/Auth.php`. All permission strings are copied verbatim from `Auth::requirePermission(...)` call sites and the `AdminController::permissions()` catalogue.*
