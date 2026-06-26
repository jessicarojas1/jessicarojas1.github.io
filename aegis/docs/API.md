# AEGIS GRC — Route & Endpoint Catalog

> Authoritative reference for every URL the application answers. Every route in
> this document was read directly from the front controller
> [`index.php`](../index.php) and verified against the controller method it
> dispatches to. Authorization gates are quoted exactly as they appear in the
> controller source.

---

## 1. How Routing Works (Read This First)

AEGIS is **not a token-based REST API**. It is a **server-rendered, session-based
PHP MVC application**. Almost every route returns a full **HTML page** (rendered
through `views/layout.php`), is protected by a **PHP session cookie**, and — for
state-changing requests — by a **CSRF token**. There is no `Authorization:
Bearer` header on the web routes; the browser session cookie *is* the credential.

A small, separate **machine-to-machine JSON API** lives under `/api/` (API key /
JWT, rate-limited). It is documented in [Section 9](#9-the-machine-api-apiv1).
Do not confuse the two: the web routes and the `/api/` routes are dispatched by
completely different code paths.

### 1.1 The front controller

All requests are rewritten to [`index.php`](../index.php) (via `.htaccess`),
which:

1. Bootstraps the environment, runs **startup guards** (`JWT_SECRET`,
   `DATABASE_URL`, `APP_URL`), and configures the session
   ([`index.php:95-137`](../index.php)). On HTTPS the session cookie is named
   `__Host-AEGIS` with `Secure`, `HttpOnly`, `SameSite=Strict`.
2. Runs a long block of **idempotent runtime migrations** on every request
   ([`index.php:164-683`](../index.php)) — these add columns / seed defaults and
   are wrapped in `try { … } catch (Throwable) {}` so they never break a request.
3. Short-circuits a few special endpoints **before** the route table:
   `/health`, `/api/docs`, and anything under `/api/` (see
   [`index.php:691-726`](../index.php)).
4. Binds the per-request tenant for Row-Level-Security
   ([`index.php:1179-1187`](../index.php)) and upserts the row in
   `active_sessions` for admin session management.
5. Matches the request against **four route tables** and `dispatch()`es to a
   `Controller::action`. A miss renders `views/errors/404.php`.

### 1.2 The four route tables

| Table | Source | Shape | Example |
|-------|--------|-------|---------|
| Static GET | `$routes['GET']` ([`index.php:730-855`](../index.php)) | Exact path → `[Controller, action]` | `/risk` → `RiskController::index` |
| Static POST | `$routes['POST']` ([`index.php:856-938`](../index.php)) | Exact path → `[Controller, action]` | `/risk/create` → `RiskController::create` |
| Dynamic GET | `$dynamicRoutes['GET']` ([`index.php:943-1011`](../index.php)) | Regex with capture groups → `[Controller, action]` | `#^/risk/(\d+)$#` → `RiskController::view` |
| Dynamic POST | `$dynamicRoutes['POST']` ([`index.php:1012-1148`](../index.php)) | Regex with capture groups → `[Controller, action]` | `#^/risk/(\d+)/update$#` → `RiskController::update` |

Static tables are checked first (`isset($routes[$method][$uri])`), then the
dynamic patterns are tried in order. Regex capture groups become method
arguments; `dispatch()` casts them to the parameter's declared scalar type via
reflection ([`index.php:1151-1168`](../index.php)) — so a controller signature
`view(int $id)` receives a real integer.

### 1.3 Authentication & authorization model

Every protected action calls one of three gates at the **top of the method**
(defined in [`src/Auth.php`](../src/Auth.php)):

| Gate | Meaning | On failure |
|------|---------|-----------|
| `Auth::requireAuth()` | Any logged-in user. | Redirect to `/login` (or `/login?reason=…` for timeout / revoked / disabled). |
| `Auth::requirePermission('module.action')` | Logged in **and** holds the granular permission (role default **or** explicit grant). | `403` → `views/errors/403.php`. |
| `Auth::requireAdmin()` | Shorthand for `requirePermission('admin')`. | `403`. |

Notes on the gate implementation ([`src/Auth.php:363-441`](../src/Auth.php)):

- `requireAuth()` also enforces **idle-session timeout**, **server-side session
  revocation** (`users.sessions_revoked_at`), **force-password-change**, and
  **password expiry** — redirecting to `/profile/edit` or `/login` as needed.
- `requireAdmin()` is literally `requirePermission('admin')`.
- A handful of routes are **deliberately public** (no gate): login, password
  reset, SSO, email verify/unsubscribe, vendor portal, and the health probes.
  These are called out in the tables below.

### 1.4 CSRF — applies to ALL POST routes

Every state-changing POST validates a CSRF token. There is **one rule for the
entire app**: each POST controller method calls
`Security::validateCsrf($_POST['csrf_token'] ?? '')` early
([`src/Security.php:27`](../src/Security.php)); each POST form embeds the token
via `Security::csrfField()` ([`src/Security.php:40`](../src/Security.php)). On
failure the method returns `403` (HTML) or, for AJAX endpoints, a JSON error
body. **Assume CSRF is required on every POST in this document** — it is not
repeated per-row. Several AJAX endpoints additionally **rotate** the token and
return the fresh value (`csrf` / `new_csrf`) so the page can keep submitting
without a reload.

### 1.5 HTML vs JSON

The overwhelming majority of routes render **HTML**. The few routes that emit
`Content-Type: application/json` (the AJAX endpoints used by inline UI widgets,
plus the health probes and calendar feed) are flagged **JSON** in the tables and
fully specified in [Section 8](#8-json--ajax-endpoints-web-app). A full OpenAPI
spec is **not applicable** to the web app because it is not a REST API; an
OpenAPI document *does* exist for the separate `/api/v1` machine API and is
served at `/api/docs` (see [Section 9](#9-the-machine-api-apiv1)).

---

## 2. Special / Pre-Route Endpoints

These are handled directly in `index.php` **before** the route tables, or by the
unauthenticated `HealthController`.

| Method | Path | Handler | Auth | Returns | Notes |
|--------|------|---------|------|---------|-------|
| GET | `/health` | inline ([`index.php:691-714`](../index.php)) | **none** | JSON | LB/uptime probe; `200` healthy / `503` degraded; reports `database` + `disk`. |
| GET | `/healthz` | `HealthController::live` | **none** | JSON | Liveness — always `200` while the process serves. |
| GET | `/readyz` | `HealthController::ready` | **none** | JSON | Readiness — `200` if DB reachable, else `503`. |
| GET | `/api/docs` | `api/docs.php` | **none** | HTML | Swagger UI for the machine API. |
| ANY | `/api/*` | `api/index.php` | API key / JWT | JSON | Machine API — see [Section 9](#9-the-machine-api-apiv1). |

---

## 3. Authentication, MFA & Profile

CSRF applies to all POST rows. Public rows are marked.

### Auth (`AuthController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/login` | `loginForm` | **public** | HTML |
| POST | `/login` | `login` | **public** | HTML (redirect) |
| POST | `/logout` | `logout` | `requireAuth` | HTML (redirect) |
| GET | `/forgot-password` | `forgotPasswordForm` | **public** | HTML |
| POST | `/forgot-password` | `forgotPassword` | **public** | HTML |
| GET | `/reset-password/{token}` | `resetPasswordForm` | **public** | HTML — token regex `[A-Za-z0-9+/=_-]+` |
| POST | `/reset-password/{token}` | `resetPassword` | **public** | HTML |

### MFA / TOTP (`AuthController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/mfa/verify` | `mfaVerifyForm` | **public** (mid-login) | HTML |
| POST | `/mfa/verify` | `mfaVerify` | **public** (mid-login) | HTML |
| POST | `/mfa/backup-verify` | `mfaBackupVerify` | **public** (mid-login) | HTML |
| GET | `/mfa/setup` | `mfaSetupForm` | `requireAuth` | HTML |
| POST | `/mfa/setup/verify` | `mfaSetupVerify` | `requireAuth` | HTML |
| POST | `/mfa/disable` | `mfaDisable` | `requireAuth` | HTML |
| GET | `/mfa/backup-codes` | `backupCodesForm` | `requireAuth` | HTML |
| POST | `/mfa/backup-codes/generate` | `generateBackupCodes` | `requireAuth` | HTML |

> The MFA verify / backup-verify routes run during the login handshake (the user
> has passed password but is not yet a full session), so they cannot require a
> completed auth; identity is carried in a pending-MFA session value.

### SSO (`SSOController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/sso/login` | `login` | **public** | redirect to IdP |
| GET | `/sso/callback` | `callback` | **public** | HTML (redirect) |
| GET | `/admin/settings/sso` | `settingsForm` | `requireAdmin` | HTML |
| POST | `/admin/settings/sso/save` | `saveSettings` | `requireAdmin` | HTML |

### Email lifecycle (`UnsubscribeController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/verify-email/{token}` | `verifyEmail` | **public** | HTML — token `[A-Za-z0-9]+` |
| GET | `/unsubscribe/{token}` | `unsubscribe` | **public** | HTML — token `[A-Za-z0-9_-]+` |

### Profile (`ProfileController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/profile/edit` | `editForm` | `requireAuth` | HTML |
| POST | `/profile/update` | `update` | `requireAuth` | HTML |
| POST | `/profile/change-password` | `changePassword` | `requireAuth` | HTML |
| GET | `/profile/notifications` | `notifications` | `requireAuth` | HTML |
| POST | `/profile/notifications/save` | `saveNotifications` | `requireAuth` | HTML |
| POST | `/profile/notifications/digest` | `saveNotificationDigest` | `requireAuth` | HTML |

---

## 4. Core GRC Modules

CSRF applies to every POST. Permission strings are quoted exactly from the
controller source.

### 4.1 Dashboard & Search
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/` | `DashboardController::index` | `requireAuth` | HTML |
| POST | `/alerts/{id}/read` | `DashboardController::markAlertRead` | `requireAuth` | **JSON** |
| GET | `/search` | `SearchController::index` | `requireAuth` | HTML |
| GET | `/docs` | `DocsController::index` | `requireAuth` | HTML |

### 4.2 Risk (`RiskController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/risk` | `index` | `requirePermission('risk.view')` |
| GET | `/risk/dashboard` | `dashboard` | `requirePermission('risk.view')` |
| GET | `/risk/matrix` | `matrix` | `requirePermission('risk.view')` |
| GET | `/risk/roadmap` | `roadmap` | `requirePermission('risk.view')` |
| GET | `/risk/create` | `createForm` | `requirePermission('risk.create')` |
| POST | `/risk/create` | `create` | `requirePermission('risk.create')` |
| GET | `/risk/{id}` | `view` | `requirePermission('risk.view')` |
| GET | `/risk/{id}/edit` | `editForm` | `requirePermission('risk.edit')` |
| POST | `/risk/{id}/update` | `update` | `requirePermission('risk.edit')` |
| POST | `/risk/{id}/delete` | `delete` | `requirePermission('risk.delete')` |
| POST | `/risk/bulk-update` | `bulkUpdate` | `requirePermission('risk.edit')` |
| POST | `/risk/{id}/response-action` | `addResponseAction` | `requirePermission('risk.edit')` |
| POST | `/risk/response-action/{id}/update` | `updateResponseAction` | `requirePermission('risk.edit')` |
| POST | `/risk/{id}/submit-review` | `submitReview` | `requirePermission('risk.review')` |
| POST | `/risk/{id}/approve` | `approve` | `requirePermission('risk.review')` |
| POST | `/risk/{id}/reject-review` | `rejectReview` | `requirePermission('risk.review')` |
| POST | `/risk/{id}/link-control` | `linkControl` | `requirePermission('risk.edit')` |
| POST | `/risk/control-link/{id}/remove` | `removeControlLink` | `requirePermission('risk.edit')` |
| POST | `/risk/{id}/link-related` | `linkRelated` | `requirePermission('risk.edit')` |
| POST | `/risk/related-link/{id}/remove` | `removeRelatedLink` | `requirePermission('risk.edit')` |

All return HTML.

### 4.3 Risk — Treatment / Acceptance / Exception / Review / Scenario / Bow-Tie / Project / RACI
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/treatment` | `TreatmentController::index` | `requirePermission('risk.treatment')` |
| GET | `/risk/{id}/treatment/create` | `TreatmentController::createForm` | `requirePermission('risk.treatment')` |
| POST | `/risk/{id}/treatment/create` | `TreatmentController::create` | `requirePermission('risk.treatment')` |
| GET | `/treatment/{id}` | `TreatmentController::view` | `requirePermission('risk.treatment')` |
| POST | `/treatment/{id}/update` | `TreatmentController::update` | `requirePermission('risk.treatment')` |
| POST | `/treatment/{id}/milestone/add` | `TreatmentController::addMilestone` | `requirePermission('risk.treatment')` |
| POST | `/treatment/milestone/{id}/complete` | `TreatmentController::completeMilestone` | `requirePermission('risk.treatment')` |
| POST | `/treatment/milestone/{id}/delete` | `TreatmentController::deleteMilestone` | `requirePermission('risk.treatment')` |
| GET | `/risk-acceptances` | `RiskAcceptanceController::index` | `requirePermission('risk.view')` |
| GET | `/risk/{id}/accept` | `RiskAcceptanceController::createForm` | `requirePermission('risk.accept')` |
| POST | `/risk/{id}/accept` | `RiskAcceptanceController::create` | `requirePermission('risk.accept')` |
| POST | `/risk-acceptances/{id}/revoke` | `RiskAcceptanceController::revoke` | `requirePermission('risk.accept')` |
| GET | `/risk-acceptances/{id}/renew` | `RiskAcceptanceController::renew` | `requirePermission('risk.accept')` |
| GET | `/risk/exceptions` | `RiskExceptionController::index` | `requireAuth` |
| GET | `/risk/{id}/exception/create` | `RiskExceptionController::createForm` | `requireAuth` |
| POST | `/risk/{id}/exception/create` | `RiskExceptionController::create` | `requireAuth` |
| GET | `/risk/exception/{id}` | `RiskExceptionController::view` | `requireAuth` |
| POST | `/risk/exception/{id}/decide` | `RiskExceptionController::decide` | `requireAdmin` |
| GET | `/risk/reviews` | `RiskReviewController::index` | `requirePermission('risk.review')` |
| GET | `/risk/reviews/create` | `RiskReviewController::createForm` | `requirePermission('risk.review')` |
| POST | `/risk/reviews/create` | `RiskReviewController::create` | `requirePermission('risk.review')` |
| GET | `/risk/reviews/{id}` | `RiskReviewController::view` | `requirePermission('risk.review')` |
| POST | `/risk/reviews/{id}/start` | `RiskReviewController::start` | `requirePermission('risk.review')` |
| POST | `/risk/reviews/{id}/complete` | `RiskReviewController::complete` | `requirePermission('risk.review')` |
| POST | `/risk/reviews/{id}/cancel` | `RiskReviewController::cancel` | `requirePermission('risk.review')` |
| POST | `/risk/reviews/{id}/item/{itemId}/update` | `RiskReviewController::updateItem` | `requirePermission('risk.review')` |
| GET | `/risk/scenarios` | `ScenarioController::index` | `requirePermission('risk.scenarios')` |
| GET | `/risk/{id}/scenario/create` | `ScenarioController::createForm` | `requirePermission('risk.scenarios')` |
| POST | `/risk/{id}/scenario/create` | `ScenarioController::create` | `requirePermission('risk.scenarios')` |
| POST | `/risk-scenarios/{id}/delete` | `ScenarioController::delete` | `requirePermission('risk.scenarios')` |
| GET | `/risk/{id}/bowtie` | `BowTieController::view` | `requirePermission('risk.bowtie')` |
| POST | `/risk/{id}/bowtie/add-cause` | `BowTieController::addCause` | `requirePermission('risk.bowtie')` |
| POST | `/risk-bowtie/cause/{id}/remove` | `BowTieController::removeCause` | `requirePermission('risk.bowtie')` |
| POST | `/risk/{id}/bowtie/add-consequence` | `BowTieController::addConsequence` | `requirePermission('risk.bowtie')` |
| POST | `/risk-bowtie/consequence/{id}/remove` | `BowTieController::removeConsequence` | `requirePermission('risk.bowtie')` |
| POST | `/risk/{id}/bowtie/add-barrier` | `BowTieController::addBarrier` | `requirePermission('risk.bowtie')` |
| POST | `/risk-bowtie/barrier/{id}/remove` | `BowTieController::removeBarrier` | `requirePermission('risk.bowtie')` |
| GET | `/projects` | `ProjectController::index` | `requirePermission('risk.view')` |
| GET | `/projects/create` | `ProjectController::createForm` | `requirePermission('risk.edit')` |
| POST | `/projects/create` | `ProjectController::create` | `requirePermission('risk.edit')` |
| GET | `/projects/{id}` | `ProjectController::view` | `requirePermission('risk.view')` |
| POST | `/projects/{id}/update` | `ProjectController::update` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/delete` | `ProjectController::delete` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/task/add` | `ProjectController::addTask` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/task/{taskId}/complete` | `ProjectController::completeTask` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/task/{taskId}/delete` | `ProjectController::deleteTask` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/link/add` | `ProjectController::addLink` | `requirePermission('risk.edit')` |
| POST | `/projects/{id}/link/{linkId}/remove` | `ProjectController::removeLink` | `requirePermission('risk.edit')` |
| GET | `/raci` | `RACIController::index` | `requirePermission('risk.view')` |
| GET | `/raci/{id}` | `RACIController::view` | `requirePermission('risk.view')` |
| POST | `/raci/{id}/save` | `RACIController::save` | `requirePermission('risk.edit')` |
| GET | `/raci/{id}/responsibility` | `RACIController::responsibilityMatrix` | `requirePermission('risk.view')` |
| POST | `/raci/{id}/responsibility/save` | `RACIController::saveResponsibility` | `requirePermission('risk.edit')` |

All return HTML.

### 4.4 Compliance (`ComplianceController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/compliance` | `index` | `requirePermission('compliance.view')` |
| GET | `/compliance/create` | `createForm` | `requirePermission('compliance.create')` |
| POST | `/compliance/create` | `create` | `requirePermission('compliance.create')` |
| GET | `/compliance/import` | `importForm` | `requirePermission('compliance.import')` |
| POST | `/compliance/import` | `import` | `requirePermission('compliance.import')` |
| GET | `/compliance/csv-template` | `downloadCsvTemplate` | `requirePermission('compliance.import')` |
| GET | `/compliance/excel-template` | `downloadExcelTemplate` | `requirePermission('compliance.import')` |
| POST | `/compliance/add-single-control` | `addSingleControl` | `requirePermission('compliance.import')` |
| POST | `/compliance/clear-all` | `clearAll` | `requirePermission('compliance.create')` |
| POST | `/compliance/delete-selected` | `deleteSelected` | `requirePermission('compliance.create')` |
| GET | `/compliance/gap-analysis` | `gapAnalysis` | `requirePermission('compliance.gap')` |
| GET | `/compliance/testing` | `testingDashboard` | `requirePermission('compliance.view')` |
| GET | `/compliance/{id}` | `viewPackage` | `requirePermission('compliance.view')` |
| GET | `/compliance/{id}/scorecard` | `scorecard` | `requirePermission('compliance.view')` |
| GET | `/compliance/{id}/ai-suggestions` | `aiSuggestions` | `requirePermission('compliance.view')` |
| GET | `/compliance/{id}/objective/{objId}` | `viewObjective` | `requirePermission('compliance.view')` |
| POST | `/compliance/{id}/objective/{objId}/update` | `updateObjective` | `requirePermission('compliance.assess')` |
| POST | `/compliance/{id}/update` | `updatePackage` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/delete` | `deletePackage` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/domain/add` | `addDomain` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/domain/{dId}/update` | `updateDomain` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/domain/{dId}/delete` | `deleteDomain` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/domain/{dId}/control/add` | `addControl` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/control/{cId}/update` | `updateControl` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/control/{cId}/delete` | `deleteControl` | `requirePermission('compliance.create')` |
| POST | `/compliance/{id}/bulk-status` | `bulkStatus` | `requirePermission('compliance.create')` — **JSON** |
| POST | `/compliance/{id}/bulk-assess` | `bulkAssess` | `requirePermission('compliance.assess')` — **JSON** |
| GET | `/compliance/control/{id}/test` | `testControl` | `requirePermission('compliance.test')` |
| POST | `/compliance/control/{id}/test/save` | `saveTest` | `requirePermission('compliance.test')` |

All HTML except the two bulk endpoints (JSON, see [Section 8](#8-json--ajax-endpoints-web-app)).

### 4.5 Audit & Findings
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/audit` | `AuditController::index` | `requirePermission('audit.view')` |
| GET | `/audit/create` | `AuditController::createForm` | `requirePermission('audit.create')` |
| POST | `/audit/create` | `AuditController::create` | `requirePermission('audit.create')` |
| GET | `/audit/{id}` | `AuditController::view` | `requirePermission('audit.view')` |
| GET | `/audit/{id}/edit` | `AuditController::editForm` | `requirePermission('audit.edit')` |
| GET | `/audit/{id}/export` | `AuditController::exportPackage` | `requirePermission('audit.view')` |
| GET | `/audit/{id}/item/{itemId}/evidence` | `AuditController::itemEvidence` | `requirePermission('audit.view')` |
| POST | `/audit/{id}/update` | `AuditController::update` | `requirePermission('audit.edit')` |
| POST | `/audit/{id}/complete` | `AuditController::complete` | `requirePermission('audit.close')` |
| POST | `/audit/{id}/item/{itemId}/update` | `AuditController::updateItem` | `requirePermission('audit.edit')` |
| GET | `/audit-findings` | `AuditFindingController::index` | `requirePermission('audit.findings')` |
| POST | `/audit-findings/create` | `AuditFindingController::create` | `requirePermission('audit.findings')` |
| GET | `/audit-findings/{id}` | `AuditFindingController::view` | `requirePermission('audit.findings')` |
| POST | `/audit-findings/{id}/update` | `AuditFindingController::update` | `requirePermission('audit.findings')` |
| POST | `/audit-findings/{id}/add-update` | `AuditFindingController::addUpdate` | `requirePermission('audit.findings')` |
| POST | `/audit-findings/{id}/close` | `AuditFindingController::close` | `requirePermission('audit.findings')` |
| POST | `/audit-findings/{id}/delete` | `AuditFindingController::delete` | `requirePermission('audit.findings')` |

All return HTML.

### 4.6 Policy & Attestations (`PolicyController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/policy` | `index` | `requirePermission('policy.view')` |
| GET | `/policy/mapping` | `mapping` | `requirePermission('policy.view')` |
| GET | `/policy/create` | `createForm` | `requirePermission('policy.create')` |
| POST | `/policy/create` | `create` | `requirePermission('policy.create')` |
| GET | `/policy/{id}` | `view` | `requirePermission('policy.view')` |
| GET | `/policy/{id}/edit` | `editForm` | `requirePermission('policy.edit')` |
| POST | `/policy/{id}/update` | `update` | `requirePermission('policy.edit')` |
| POST | `/policy/{id}/map` | `mapObjective` | `requirePermission('policy.edit')` |
| POST | `/policy/{id}/unmap/{objId}` | `unmapObjective` | `requirePermission('policy.edit')` |
| GET | `/policy/attestations` | `attestations` | `requirePermission('policy.view')` |
| GET | `/policy/attestations/create` | `createCampaign` | `requirePermission('policy.attest')` |
| POST | `/policy/attestations/save` | `saveCampaign` | `requirePermission('policy.attest')` |
| GET | `/policy/attestations/{id}` | `viewCampaign` | `requirePermission('policy.view')` |
| GET | `/policy/{id}/attest` | `attestForm` | `requirePermission('policy.attest')` |
| POST | `/policy/{id}/attest` | `attest` | `requirePermission('policy.attest')` |
| GET | `/my-attestations` | `myAttestations` | `requirePermission('policy.attest')` |

All return HTML.

### 4.7 Documents (`DocumentController`)
> Documents are gated on the **policy** permission family.

| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/documents` | `index` | `requirePermission('policy.view')` |
| GET | `/documents/create` | `createForm` | `requirePermission('policy.create')` |
| POST | `/documents/create` | `create` | `requirePermission('policy.create')` |
| GET | `/documents/{id}` | `view` | `requirePermission('policy.view')` |
| POST | `/documents/{id}/update` | `update` | `requirePermission('policy.edit')` |
| POST | `/documents/{id}/upload-version` | `uploadVersion` | `requirePermission('policy.edit')` |

### 4.8 Incidents & Playbooks
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/incident/sla` | `IncidentController::slaReport` | `requirePermission('incident.view')` |
| GET | `/playbooks` | `PlaybookController::index` | `requirePermission('incident.playbook')` |
| GET | `/playbooks/create` | `PlaybookController::createForm` | `requirePermission('incident.playbook')` |
| POST | `/playbooks/create` | `PlaybookController::create` | `requirePermission('incident.playbook')` |
| GET | `/playbooks/{id}` | `PlaybookController::view` | `requirePermission('incident.playbook')` |
| POST | `/playbooks/{id}/toggle` | `PlaybookController::toggle` | `requirePermission('incident.playbook')` |
| POST | `/incident/{id}/playbook/start` | `PlaybookController::startRun` | `requirePermission('incident.playbook')` |
| POST | `/playbooks/run/{id}/complete-step` | `PlaybookController::completeStep` | `requirePermission('incident.playbook')` |

> `IncidentController` also exposes `index`, `createForm`, `create`, `view`,
> `update`, `addUpdate`, `close`, `acknowledge` (gated `incident.view` /
> `incident.create` / `incident.edit` / `incident.close`). Those methods exist
> in the controller but **no route in `index.php` maps to them** other than
> `/incident/sla` — they appear to be reached through other modules or are
> not currently wired. Documented here for completeness; verify before relying
> on a standalone `/incident` URL.

### 4.9 Issues (`IssueController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/issue` | `index` | `requirePermission('issue.view')` |
| GET | `/issue/create` | `createForm` | `requirePermission('issue.create')` |
| POST | `/issue/create` | `create` | `requirePermission('issue.create')` |
| GET | `/issue/{id}` | `view` | `requirePermission('issue.view')` |
| POST | `/issue/{id}/update` | `update` | `requirePermission('issue.edit')` |
| POST | `/issue/{id}/add-update` | `addUpdate` | `requirePermission('issue.edit')` |

### 4.10 Vendors, Contracts, Questionnaires & Portal
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/vendor` | `VendorController::index` | `requirePermission('vendor.view')` |
| GET | `/vendor/create` | `VendorController::createForm` | `requirePermission('vendor.create')` |
| POST | `/vendor/create` | `VendorController::create` | `requirePermission('vendor.create')` |
| GET | `/vendor/{id}` | `VendorController::view` | `requirePermission('vendor.view')` |
| POST | `/vendor/{id}/update` | `VendorController::update` | `requirePermission('vendor.edit')` |
| POST | `/vendor/{id}/assessment` | `VendorController::addAssessment` | `requirePermission('vendor.assess')` |
| POST | `/vendor/{id}/assessment/{aId}/update` | `VendorController::updateAssessment` | `requirePermission('vendor.assess')` |
| POST | `/vendor/{id}/portal-link` | `VendorController::generatePortalLink` | `requirePermission('vendor.assess')` |
| GET | `/vendor/contracts` | `VendorController::contracts` | `requirePermission('vendor.contracts')` |
| GET | `/vendor/{id}/contract/create` | `VendorController::createContract` | `requirePermission('vendor.contracts')` |
| POST | `/vendor/{id}/contract/save` | `VendorController::saveContract` | `requirePermission('vendor.contracts')` |
| POST | `/vendor/contract/{id}/update` | `VendorController::updateContract` | `requirePermission('vendor.contracts')` |
| GET | `/vendor/portal/{token}` | `VendorController::portalView` | **public** (token-scoped) |
| POST | `/vendor/portal/{token}/submit` | `VendorController::portalSubmit` | **public** (token-scoped) |
| GET | `/questionnaire` | `QuestionnaireController::index` | `requirePermission('vendor.questionnaire')` |
| GET | `/questionnaire/create` | `QuestionnaireController::createForm` | `requirePermission('vendor.questionnaire')` |
| POST | `/questionnaire/create` | `QuestionnaireController::create` | `requirePermission('vendor.questionnaire')` |
| GET | `/questionnaire/{id}` | `QuestionnaireController::view` | `requirePermission('vendor.questionnaire')` |
| POST | `/questionnaire/{id}/assign` | `QuestionnaireController::assign` | `requirePermission('vendor.questionnaire')` |
| GET | `/questionnaire/assignment/{id}/respond` | `QuestionnaireController::respond` | `requirePermission('vendor.questionnaire')` |
| POST | `/questionnaire/assignment/{id}/submit` | `QuestionnaireController::submitResponse` | `requirePermission('vendor.questionnaire')` |

> The vendor **portal** routes carry their own opaque token (regex
> `[A-Za-z0-9_-]+`) and are reachable without a session so external vendors can
> respond — authorization is the token itself, validated inside the controller.

### 4.11 Assets (`AssetController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/assets` | `index` | `requirePermission('asset.view')` |
| GET | `/assets/create` | `createForm` | `requirePermission('asset.create')` |
| POST | `/assets/create` | `create` | `requirePermission('asset.create')` |
| GET | `/assets/{id}` | `view` | `requirePermission('asset.view')` |
| POST | `/assets/{id}/update` | `update` | `requirePermission('asset.edit')` |
| POST | `/assets/{id}/link-risk` | `linkRisk` | `requirePermission('asset.edit')` |
| POST | `/assets/{id}/unlink-risk/{riskId}` | `unlinkRisk` | `requirePermission('asset.edit')` |

### 4.12 KRIs (`KRIController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/kris` | `index` | `requirePermission('kri.view')` |
| GET | `/kris/create` | `createForm` | `requirePermission('kri.manage')` |
| POST | `/kris/create` | `create` | `requirePermission('kri.manage')` |
| GET | `/kris/{id}` | `view` | `requirePermission('kri.view')` |
| POST | `/kris/{id}/record` | `recordValue` | `requirePermission('kri.record')` |
| POST | `/kris/{id}/toggle` | `toggle` | `requirePermission('kri.manage')` |

### 4.13 Threats (`ThreatController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/threats` | `index` | `requirePermission('threat.view')` |
| GET | `/threats/create` | `createForm` | `requirePermission('threat.create')` |
| POST | `/threats/create` | `create` | `requirePermission('threat.create')` |
| GET | `/threats/{id}` | `view` | `requirePermission('threat.view')` |
| POST | `/threats/{id}/update` | `update` | `requirePermission('threat.edit')` |
| POST | `/threats/{id}/link-risk` | `linkRisk` | `requirePermission('threat.edit')` |
| POST | `/threats/{id}/unlink-risk/{riskId}` | `unlinkRisk` | `requirePermission('threat.edit')` |

### 4.14 BCP (`BCPController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/bcp` | `index` | `requirePermission('bcp.view')` |
| GET | `/bcp/create` | `createForm` | `requirePermission('bcp.edit')` |
| POST | `/bcp/create` | `create` | `requirePermission('bcp.edit')` |
| GET | `/bcp/{id}` | `view` | `requirePermission('bcp.view')` |
| POST | `/bcp/{id}/update` | `update` | `requirePermission('bcp.edit')` |
| POST | `/bcp/{id}/add-exercise` | `addExercise` | `requirePermission('bcp.exercise')` |

### 4.15 Awareness Training (`AwarenessController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/awareness` | `index` | `requirePermission('awareness.view')` |
| GET | `/awareness/create` | `createForm` | `requirePermission('awareness.manage')` |
| POST | `/awareness/create` | `create` | `requirePermission('awareness.manage')` |
| GET | `/awareness/{id}` | `view` | `requirePermission('awareness.view')` |
| POST | `/awareness/{id}/complete` | `complete` | `requirePermission('awareness.view')` |
| POST | `/awareness/{id}/assign` | `assign` | `requirePermission('awareness.manage')` |
| POST | `/awareness/{id}/delete` | `delete` | `requirePermission('awareness.manage')` |

### 4.16 Automation (`AutomationController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/automation` | `index` | `requirePermission('automation.view')` |
| GET | `/automation/create` | `createForm` | `requirePermission('automation.manage')` |
| POST | `/automation/create` | `create` | `requirePermission('automation.manage')` |
| GET | `/automation/{id}` | `view` | `requirePermission('automation.view')` |
| POST | `/automation/{id}/toggle` | `toggle` | `requirePermission('automation.manage')` |
| POST | `/automation/{id}/delete` | `delete` | `requirePermission('automation.manage')` |
| POST | `/automation/{id}/test` | `testRun` | `requirePermission('automation.manage')` — **JSON** |

### 4.17 Approvals (`ApprovalController`)
| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/approvals` | `pending` | `requirePermission('approval.view')` |
| GET | `/approvals/{id}/review` | `review` | `requirePermission('approval.view')` |
| POST | `/approvals/{id}/decide` | `decide` | `requirePermission('approval.approve')` |

### 4.18 Evidence (`EvidenceController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/evidence/list` | `listForEntity` | `requireAuth` (+ per-entity IDOR check) | **JSON** |
| POST | `/evidence/upload` | `upload` | `requireAuth` | HTML (redirect) |
| GET | `/evidence/{id}/download` | `download` | `requireAuth` | file stream |
| POST | `/evidence/{id}/delete` | `delete` | `requireAuth` | HTML (redirect) |

### 4.19 Tags (`TagController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/admin/tags` | `index` | `requireAdmin` | HTML |
| POST | `/admin/tags/create` | `create` | `requireAdmin` | HTML |
| POST | `/admin/tags/{id}/delete` | `delete` | `requireAdmin` | HTML |
| GET | `/tags/entity` | `entityTags` | `requireAuth` | **JSON** |
| POST | `/tags/add` | `addToEntity` | `requireAuth` | **JSON** |
| POST | `/tags/remove` | `removeFromEntity` | `requireAuth` | **JSON** |

### 4.20 Calendar (`CalendarController`)
| Method | Path | Action | Auth | Returns |
|--------|------|--------|------|---------|
| GET | `/calendar` | `index` | `requirePermission('risk.view')` | HTML |
| GET | `/calendar/feed` | `feed` | `requirePermission('risk.view')` | **JSON** |

---

## 5. NIST 800-171 / CMMC Modules

These modules reuse the **compliance** and **ssp** permission families.

| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/ssp` | `SSPController::index` | `requirePermission('ssp.view')` |
| GET | `/ssp/create` | `SSPController::createForm` | `requirePermission('ssp.edit')` |
| POST | `/ssp/create` | `SSPController::create` | `requirePermission('ssp.edit')` |
| GET | `/ssp/{id}` | `SSPController::view` | `requirePermission('ssp.view')` |
| GET | `/ssp/{id}/generate` | `SSPController::generate` | `requirePermission('ssp.view')` |
| GET | `/ssp/{id}/download/network-arch` | `SSPController::downloadNetworkArch` | `requirePermission('ssp.view')` |
| GET | `/ssp/{id}/download/data-flow` | `SSPController::downloadDataFlow` | `requirePermission('ssp.view')` |
| POST | `/ssp/{id}/update` | `SSPController::update` | `requirePermission('ssp.edit')` |
| POST | `/ssp/{id}/delete` | `SSPController::delete` | `requirePermission('ssp.edit')` |
| POST | `/ssp/{id}/add-package` | `SSPController::addPackage` | `requirePermission('ssp.edit')` |
| POST | `/ssp/{id}/remove-package/{pId}` | `SSPController::removePackage` | `requirePermission('ssp.edit')` |
| POST | `/ssp/{id}/statement/{sId}/save` | `SSPController::saveStatement` | `requirePermission('ssp.edit')` |
| GET | `/odp` | `ODPController::index` | `requirePermission('ssp.view')` |
| GET | `/odp/package/{id}` | `ODPController::packageView` | `requirePermission('ssp.view')` |
| POST | `/odp/save` | `ODPController::save` | `requirePermission('ssp.edit')` |
| GET | `/poam` | `POAMController::index` | `requirePermission('compliance.view')` |
| GET | `/poam/{id}` | `POAMController::view` | `requirePermission('compliance.view')` |
| POST | `/poam/generate` | `POAMController::generate` | `requirePermission('compliance.assess')` |
| POST | `/poam/create` | `POAMController::create` | `requirePermission('compliance.assess')` |
| POST | `/poam/import` | `POAMController::importCsv` | `requirePermission('compliance.assess')` |
| POST | `/poam/{id}/update` | `POAMController::update` | `requirePermission('compliance.assess')` |
| POST | `/poam/{id}/delete` | `POAMController::delete` | `requirePermission('compliance.assess')` |
| POST | `/poam/{id}/milestone/add` | `POAMController::addMilestone` | `requirePermission('compliance.assess')` |
| POST | `/poam/{id}/milestone/{mId}/complete` | `POAMController::completeMilestone` | `requirePermission('compliance.assess')` |
| GET | `/cui` | `CUIController::index` | `requirePermission('compliance.view')` |
| GET | `/cui/create` | `CUIController::createForm` | `requirePermission('compliance.assess')` |
| POST | `/cui/create` | `CUIController::create` | `requirePermission('compliance.assess')` |
| GET | `/cui/{id}` | `CUIController::view` | `requirePermission('compliance.view')` |
| POST | `/cui/{id}/update` | `CUIController::update` | `requirePermission('compliance.assess')` |
| POST | `/cui/{id}/delete` | `CUIController::delete` | `requirePermission('compliance.assess')` |
| GET | `/sprs` | `SPRSController::index` | `requirePermission('compliance.view')` |

All return HTML except download/generate which stream files.

---

## 6. Privacy Module (`PrivacyController`)

> Gated on the **compliance** permission family.

| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/privacy` | `index` | `requirePermission('compliance.view')` |
| GET | `/privacy/create` | `createForm` | `requirePermission('compliance.assess')` |
| POST | `/privacy/create` | `create` | `requirePermission('compliance.assess')` |
| GET | `/privacy/{id}` | `view` | `requirePermission('compliance.view')` |
| POST | `/privacy/{id}/delete` | `delete` | `requirePermission('compliance.assess')` |
| GET | `/privacy/requests` | `requests` | `requirePermission('compliance.view')` |
| POST | `/privacy/requests/create` | `createRequest` | `requirePermission('compliance.assess')` |
| POST | `/privacy/requests/{id}/update` | `updateRequest` | `requirePermission('compliance.assess')` |

---

## 7. Reporting, Metrics, Export, Import & Custom Dashboards

| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/report` | `ReportController::index` | `requirePermission('report.view')` |
| GET | `/report/compliance` | `ReportController::compliance` | `requirePermission('report.view')` |
| GET | `/report/executive` | `ReportController::executive` | `requirePermission('report.view')` |
| GET | `/report/risk` | `ReportController::risk` | `requirePermission('report.view')` |
| GET | `/report/risk-detail` | `ReportController::riskDetail` | `requirePermission('report.view')` |
| GET | `/report/board` | `ReportController::board` | `requirePermission('report.view')` |
| GET | `/report/board-pack` | `ReportController::board` | `requirePermission('report.view')` |
| GET | `/metrics` | `MetricsController::index` | `requirePermission('report.view')` |
| POST | `/metrics/schedule/save` | `MetricsController::saveSchedule` | `requireAdmin` |
| POST | `/metrics/schedule/{id}/delete` | `MetricsController::deleteSchedule` | `requireAdmin` |
| GET | `/export` | `ExportController::index` | `requireAuth` |
| POST | `/export/download` | `ExportController::download` | `requireAuth` |
| POST | `/export/download-all` | `ExportController::downloadAll` | `requireAuth` |
| GET | `/import` | `ImportController::index` | `requirePermission('compliance.import')` |
| POST | `/import/upload` | `ImportController::upload` | `requirePermission('compliance.import')` |
| GET | `/dashboards` | `CustomDashboardController::index` | `requireAuth` |
| POST | `/dashboards/create` | `CustomDashboardController::create` | `requireAuth` |
| GET | `/dashboards/{id}` | `CustomDashboardController::view` | `requireAuth` |
| POST | `/dashboards/{id}/add-widget` | `CustomDashboardController::addWidget` | `requireAuth` |
| POST | `/dashboards/{id}/widget/{wId}/remove` | `CustomDashboardController::removeWidget` | `requireAuth` |
| POST | `/dashboards/{id}/delete` | `CustomDashboardController::delete` | `requireAuth` |

Reports/metrics/exports return HTML pages or stream files (PDF/CSV/XLSX).

---

## 8. Admin & Platform

### 8.1 Admin (`AdminController`) — all `requireAdmin` unless noted

> `requireAdmin()` ≡ `requirePermission('admin')`. Two branding methods call the
> long form `requirePermission('admin')` directly (identical effect).

| Method | Path | Action |
|--------|------|--------|
| GET | `/admin` | `index` |
| GET | `/admin/users` | `users` |
| POST | `/admin/users/create` | `createUser` |
| GET | `/admin/users/{id}/edit` | `editUser` |
| POST | `/admin/users/{id}/update` | `updateUser` |
| POST | `/admin/users/{id}/delete` | `deleteUser` |
| GET | `/admin/permissions` | `permissions` |
| POST | `/admin/permissions/{id}/update` | `updatePermissions` — **JSON (AJAX)** |
| GET | `/admin/risk-matrix` | `riskMatrix` |
| POST | `/admin/risk-matrix/update` | `updateRiskMatrix` |
| GET | `/admin/workflows` | `workflows` |
| POST | `/admin/workflows/create` | `createWorkflow` |
| GET | `/admin/workflows/{id}/edit` | `editWorkflow` |
| POST | `/admin/workflows/{id}/toggle` | `toggleWorkflow` |
| GET | `/admin/alerts` | `alerts` |
| GET | `/admin/alerts/config/create` | `alertConfigForm` |
| GET | `/admin/alerts/config/{id}/edit` | `alertConfigForm` |
| POST | `/admin/alerts/config/save` | `saveAlertConfig` |
| POST | `/admin/alerts/config/{id}/delete` | `deleteAlertConfig` |
| GET | `/admin/api-keys` | `apiKeys` |
| POST | `/admin/api-keys/create` | `createApiKey` |
| POST | `/admin/api-keys/{id}/revoke` | `revokeApiKey` |
| GET | `/admin/logs` | `logs` |
| POST | `/admin/logs/export` | `exportLogs` |
| GET | `/admin/email` | `email` |
| POST | `/admin/email/save` | `saveEmail` |
| POST | `/admin/email/test` | `testEmail` |
| GET | `/admin/email-templates` | `emailTemplates` |
| GET | `/admin/email-templates/{id}/edit` | `emailTemplateForm` |
| GET | `/admin/email-templates/{id}/preview` | `previewEmailTemplate` |
| POST | `/admin/email-templates/update` | `updateEmailTemplate` |
| POST | `/admin/email-templates/{id}/update` | `updateEmailTemplate` |
| GET | `/admin/email-delivery` | `emailDelivery` |
| GET | `/admin/scheduled-reports` | `scheduledReports` |
| GET | `/admin/scheduled-reports/create` | `scheduledReportForm` |
| GET | `/admin/scheduled-reports/{id}/edit` | `scheduledReportForm` |
| POST | `/admin/scheduled-reports/create` | `createScheduledReport` |
| POST | `/admin/scheduled-reports/{id}/update` | `updateScheduledReport` |
| POST | `/admin/scheduled-reports/{id}/delete` | `deleteScheduledReport` |
| GET | `/admin/settings` | `settings` |
| POST | `/admin/settings/save` | `saveSettings` |
| POST | `/admin/settings/branding/save` | `saveBranding` — gate `requirePermission('admin')` |
| POST | `/admin/settings/upload-logo` | `uploadLogo` — gate `requirePermission('admin')` |
| POST | `/admin/settings/remove-logo` | `removeLogo` |
| GET | `/admin/module-visibility` | `moduleVisibility` |
| POST | `/admin/module-visibility/save` | `saveModuleVisibility` |
| GET | `/admin/storage` | `storage` |
| POST | `/admin/storage/save` | `saveStorage` |
| POST | `/admin/storage/test` | `testStorage` — **JSON** |
| GET | `/admin/retention` | `retention` |
| POST | `/admin/retention/save` | `saveRetention` |
| POST | `/admin/retention/run` | `runRetention` — **JSON** |
| GET | `/admin/sessions` | `sessions` |
| POST | `/admin/sessions/{sid}/kill` | `killSession` — session-id regex `[a-zA-Z0-9]+` |
| GET | `/admin/security-policy` | `securityPolicy` |
| POST | `/admin/security-policy/save` | `saveSecurityPolicy` |
| GET | `/admin/custom-fields` | `customFields` |
| POST | `/admin/custom-fields/save` | `saveCustomField` |
| POST | `/admin/custom-fields/{id}/delete` | `deleteCustomField` |
| GET | `/admin/risk-appetite` | `riskAppetite` |
| POST | `/admin/risk-appetite/save` | `saveRiskAppetite` |
| GET | `/admin/sla-policy` | `slaPolicy` |
| POST | `/admin/sla-policy/save` | `saveSlaPolicy` |

### 8.2 Approval templates (`ApprovalController`) — `requireAdmin`
| Method | Path | Action |
|--------|------|--------|
| GET | `/admin/approval-templates` | `templates` |
| GET | `/admin/approval-templates/create` | `createTemplate` |
| POST | `/admin/approval-templates/save` | `saveTemplate` |
| POST | `/admin/approval-templates/{id}/toggle` | `toggleTemplate` |

### 8.3 Webhooks (`WebhookController`) — `requirePermission('admin')`
| Method | Path | Action |
|--------|------|--------|
| GET | `/admin/webhooks` | `index` |
| GET | `/admin/webhooks/create` | `createForm` |
| POST | `/admin/webhooks/create` | `create` |
| GET | `/admin/webhooks/{id}/edit` | `editForm` |
| GET | `/admin/webhooks/{id}/deliveries` | `deliveries` |
| POST | `/admin/webhooks/{id}/update` | `update` |
| POST | `/admin/webhooks/{id}/toggle` | `toggleActive` |
| POST | `/admin/webhooks/{id}/delete` | `delete` |

### 8.4 Platform / Multi-tenant (`PlatformController`)
> Platform-admin only (the gate is enforced inside the controller body, not in
> the first lines, but these are restricted to platform administrators who can
> impersonate / switch tenants). Verify in
> [`controllers/PlatformController.php`](../controllers/PlatformController.php).

| Method | Path | Action | Returns |
|--------|------|--------|---------|
| GET | `/platform/tenants` | `tenants` | HTML |
| POST | `/platform/switch-tenant` | `switchTenant` | HTML (redirect) |
| POST | `/platform/exit-tenant` | `exitTenant` | HTML (redirect) |

---

## 9. JSON / AJAX Endpoints (Web App)

These are the **only** routes in the web application that return JSON. They are
called by inline JavaScript widgets (`app.js`) using `fetch()`/XHR. They still
ride on the session cookie and (for POST) the CSRF token — they are **not** part
of the machine API. Request bodies are standard `application/x-www-form-urlencoded`
form posts (or query strings for GET) unless stated.

### 9.1 `POST /admin/permissions/{id}/update` — save user permissions
Source: [`AdminController::updatePermissions`](../controllers/AdminController.php) (`:532-609`).

- **Auth:** `requireAdmin`. Target user must exist and not be an `admin` role.
- **Request (form):** `csrf_token`, `permissions[]` — array of granular strings
  like `risk.view`, `compliance.assess`. Each is validated against an allowlist
  of modules and actions; unknown values are silently dropped. Existing explicit
  grants are deleted and replaced.
- **Detection:** AJAX is detected via `X-Requested-With: XMLHttpRequest`.
- **Response (200, AJAX):**
  ```json
  { "ok": true, "csrf": "<rotated-token>" }
  ```
  The client must store the rotated `csrf` for the next save.
- **Errors:** `403 {"ok":false,"message":"CSRF validation failed"}`;
  `422 {"ok":false,"message":"Invalid user"}`. Non-AJAX callers get a redirect /
  bare status instead.

### 9.2 `POST /compliance/{id}/bulk-status` — bulk control status
Source: [`ComplianceController::bulkStatus`](../controllers/ComplianceController.php) (`:222-261`).

- **Auth:** `requirePermission('compliance.create')`.
- **Request (form):** `csrf_token`, the selected control/objective IDs, and the
  new status. Invalid IDs are filtered.
- **Response (200):**
  ```json
  { "ok": true, "updated": 12, "new_csrf": "<rotated-token>" }
  ```
- **Errors:** `{"ok":false,"error":"Invalid CSRF token"}`,
  `{"ok":false,"error":"Invalid input"}`.

### 9.3 `POST /compliance/{id}/bulk-assess` — bulk assessment
Source: [`ComplianceController::bulkAssess`](../controllers/ComplianceController.php) (`:264+`).

- **Auth:** `requirePermission('compliance.assess')`.
- **Response shape:** same `{ "ok": …, "error"? }` JSON envelope as bulk-status.

### 9.4 `POST /automation/{id}/test` — dry-run automation rule
Source: [`AutomationController::testRun`](../controllers/AutomationController.php) (`:142-188`).

- **Auth:** `requirePermission('automation.manage')`.
- **Behaviour:** evaluates the rule's trigger against current data **without**
  firing actions.
- **Response (200):**
  ```json
  { "ok": true, "dry_run": true, "result": [ /* matched entities */ ] }
  ```

### 9.5 `GET /calendar/feed` — calendar events
Source: [`CalendarController::feed`](../controllers/CalendarController.php) (`:32-57`).

- **Auth:** `requirePermission('risk.view')`.
- **Query:** `month` (1–12), `year` (2000–2099). Both default to the current
  month/year and are range-clamped.
- **Response (200):** a flat JSON array:
  ```json
  [ { "date": "2026-06-30", "title": "Risk review due", "type": "risk_review", "url": "/risk/reviews/42" } ]
  ```

### 9.6 `GET /evidence/list` — evidence files for an entity
Source: [`EvidenceController::listForEntity`](../controllers/EvidenceController.php) (`:216-241`).

- **Auth:** `requireAuth` **plus** an IDOR check — the caller must have read
  access to the parent entity (`canAccessEntity`).
- **Query:** `entity_type`, `entity_id`.
- **Response (200):** JSON array of `{ id, original_name, file_size, mime_type,
  description, expires_at, created_at, uploaded_by_name }`. Empty/invalid params
  return `[]`. `403 {"error":"Access denied"}` if the IDOR check fails.

### 9.7 Tags AJAX (`TagController`)
Source: [`TagController`](../controllers/TagController.php) (`:54-108`).

| Endpoint | Auth | Response |
|----------|------|----------|
| `GET /tags/entity?entity_type=…&entity_id=…` | `requireAuth` | JSON array of tags (or `[]`) |
| `POST /tags/add` | `requireAuth` | `{ "ok": true }` / `400 {"error":"Invalid parameters"}` |
| `POST /tags/remove` | `requireAuth` | `{ "ok": true }` |

### 9.8 `POST /alerts/{id}/read` — mark dashboard alert read
Source: [`DashboardController::markAlertRead`](../controllers/DashboardController.php) (`:168-179`).

- **Auth:** `requireAuth`.
- **Response:** `{ "success": true }` on success;
  `{ "success": false, "error": "CSRF validation failed" }` on bad token.

### 9.9 Admin AJAX utilities
| Endpoint | Auth | Response |
|----------|------|----------|
| `POST /admin/storage/test` | `requireAdmin` | `{ "ok": true, "message": "Storage test passed." }` or `{ "ok": false, "error": "<detail>" }` |
| `POST /admin/retention/run` | `requireAdmin` | `{ "ok": true, "results": [ … ] }` |

### 9.10 Health probes (JSON, unauthenticated)
Already listed in [Section 2](#2-special--pre-route-endpoints). Shapes:

```jsonc
// GET /health
{ "status": "healthy", "timestamp": "…", "checks": { "database": "ok", "disk": "ok" } }
// GET /healthz
{ "status": "ok", "request_id": "…", "time": "…" }
// GET /readyz
{ "status": "ready", "checks": { "database": "ok" }, "request_id": "…", "time": "…" }
```

---

## 10. The Machine API (`/api/v1`)

A **separate, genuine REST-ish JSON API** lives under `/api/` and is handled by
[`api/index.php`](../api/index.php) — **not** the web route tables. It is the
one part of AEGIS where an OpenAPI spec applies; the spec is committed at
[`api/openapi.json`](../api/openapi.json) and served as Swagger UI at
**`GET /api/docs`** (no auth).

### 10.1 Authentication
Read by `authenticateApi()` ([`api/index.php:84-118`](../api/index.php)). Two
schemes:

- **`X-API-Key: <key>`** — looked up by HMAC-SHA256 (new keys) or legacy
  SHA-256; must be active and unexpired. Permissions (`read`/`write`) come from
  the key row. Legacy keys are silently upgraded to HMAC on first use.
- **`Authorization: Bearer <jwt>`** — JWT verified via `JWT::verify`; grants
  `['read','write']`. Issued by the token endpoint below.

`write` access requires the key's permissions to include `write` **or** the
user's role to be `admin` (`$canWrite`). The web session cookie is **not**
accepted here.

### 10.2 Rate limiting & CORS
- **Rate limit:** 60 requests/minute per client IP, tracked in the
  `rate_limits` table; exceeding returns `429` ([`api/index.php:120-135`](../api/index.php)).
- **CORS:** `Access-Control-Allow-Origin` echoes the request origin only when it
  exactly equals `APP_URL`. `OPTIONS` preflight returns `204`.
- **No CSRF** — this is a stateless token API, so CSRF does not apply.

### 10.3 Envelope conventions
```jsonc
// success
{ "success": true, "data": <payload>, "meta": { "timestamp": "…", "version": "v1" } }
// list (paginated)
{ "success": true, "data": [ … ], "meta": { …, "pagination": { "page", "per_page", "total", "total_pages" } } }
// error
{ "success": false, "error": "<message>", "meta": { "timestamp": "…", "request_id": "…" } }
```
List endpoints accept `?page`, `?per_page` (max 100), and `?sort` (a `-` prefix
means DESC; only allowlisted columns are honoured).

### 10.4 Endpoints
URLs may be prefixed with `/api/v1` (stripped) or hit directly under `/api`.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/health` (or `/api/health`) | **public** | DB readiness probe; `200`/`503`. |
| POST | `/api/v1/auth/token` | **public** (rate-limited) | Issue a 1-hour JWT. Body: `{email, password, totp_code?}`. Returns `{token, expires_in, user}`. MFA enforced if enabled. |
| POST | `/api/ingest/{tenable\|qualys\|wiz\|generic}` | `X-API-Key` + `write` | Scanner/SIEM finding ingestion → creates `risks`; deduped by external id (30 days). See [`api/ingest.php`](../api/ingest.php). |
| GET | `/api/v1/compliance/packages` | key/JWT | List active compliance packages (paginated). |
| GET | `/api/v1/compliance/packages/{id}` | key/JWT | One package. |
| GET | `/api/v1/compliance/packages/{id}/objectives` | key/JWT | Objectives + implementation status. |
| GET | `/api/v1/standards` | key/JWT | List active standards. |
| GET | `/api/v1/risks` | key/JWT | List risks (paginated, sortable). |
| GET | `/api/v1/risks/{id}` | key/JWT | One risk. |
| POST | `/api/v1/risks` | key/JWT + `write` | Create a risk (`201`). Body: `{title, description, likelihood, impact, owner_id?}`. |
| GET | `/api/v1/policies` | key/JWT | List policies. |
| GET | `/api/v1/audits` | key/JWT | List audits. |
| PUT | `/api/v1/compliance/objectives/{id}/status` | key/JWT + `write` | Set an objective's implementation status (allowlisted values). |
| GET | `/api/v1/dashboard/stats` | key/JWT | Aggregate counts (packages, compliant controls, open risks, published policies). |
| GET | `/api/v1/users` | key/JWT + **role=admin** | List users. |
| * | anything else | — | `404 {"success":false,"error":"Endpoint not found"}`. |

> The `/api/ingest/{scanner}` endpoint accepts a JSON body in the scanner's
> native export shape (Tenable / Qualys VMDR / Wiz / a generic
> `{findings:[…]}`), normalises it, and returns
> `{ "success": true, "data": { "scanner", "created", "skipped" }, "ts": … }`.

---

## 11. Permission Vocabulary (Quick Reference)

The granular permission strings used by `requirePermission()` across the app
(canonical list also defined in the permissions editor at
[`AdminController::updatePermissions:546-564`](../controllers/AdminController.php)):

| Module | Actions seen on routes |
|--------|------------------------|
| `risk` | `view`, `create`, `edit`, `delete`, `accept`, `review`, `treatment`, `scenarios`, `bowtie` |
| `compliance` | `view`, `create`, `assess`, `import`, `test`, `gap` |
| `audit` | `view`, `create`, `edit`, `findings`, `close` |
| `policy` | `view`, `create`, `edit`, `attest` (also drives Documents) |
| `incident` | `view`, `create`, `edit`, `close`, `playbook` |
| `vendor` | `view`, `create`, `edit`, `assess`, `contracts`, `questionnaire` |
| `issue` | `view`, `create`, `edit` |
| `threat` | `view`, `create`, `edit` |
| `asset` | `view`, `create`, `edit` |
| `kri` | `view`, `manage`, `record` |
| `bcp` | `view`, `edit`, `exercise` |
| `ssp` | `view`, `edit` (also drives ODP) |
| `awareness` | `view`, `manage` |
| `automation` | `view`, `manage` |
| `approval` | `view`, `approve` |
| `report` | `view` |
| `admin` | the super-gate (`requireAdmin`) for all `/admin/*`, webhooks, tags admin |

> **Reuse note:** several modules deliberately reuse another module's namespace —
> Documents use `policy.*`; Projects, RACI and Calendar use `risk.*`; Privacy,
> POAM, CUI and SPRS use `compliance.*`; ODP uses `ssp.*`. This is intentional in
> the source and is reflected exactly above.

---

## 12. Error Responses

| Condition | Web app | Machine API |
|-----------|---------|-------------|
| Not authenticated | `302` → `/login` | `401` JSON |
| Authenticated, missing permission | `403` → `views/errors/403.php` | `404`/`403` JSON |
| CSRF invalid (POST) | `403` (HTML) or JSON `{ok:false,…}` for AJAX | n/a (no CSRF) |
| Unknown route | `404` → `views/errors/404.php` | `404 {"success":false,"error":"Endpoint not found"}` |
| Unhandled exception | Generic `500` page keyed by `X-Request-Id`; `RuntimeException` shows a safe config message ([`index.php:28-63`](../index.php)) | `500` (logged, generic) |
| Rate limit exceeded | n/a | `429` JSON |

Every response carries an `X-Request-Id` header (`AEGIS_REQUEST_ID`) so a
user-reported error can be traced to its log line without leaking internals.
