# AEGIS GRC — Modernization Report

Outcome of a structured, module-by-module modernization pass: a 12-agent parallel
audit produced a scored roadmap (180 findings); every finding was **verified
against real code before any change**, and all genuinely-real, safe fixes were
implemented and shipped in PR #345. This document is the durable record.

---

## 1. Module inventory

**51 controllers · 41 view directories · 407 routes · 32 migrations**, organized
into 12 audited clusters:

| Cluster | Scope |
|---|---|
| Auth & Access | login, SSO, MFA/TOTP, profile, sessions |
| Risk Core | register, acceptance, exceptions, reviews, scenarios, bow-tie |
| Risk Analytics | dashboards, KRIs, threats, treatments, metrics |
| Compliance | packages, control testing, gap analysis, SSP, CUI, ODP, SPRS |
| Vendor & Asset | vendor risk, contracts, asset inventory, tags |
| Operations | audits, findings, policies, playbooks, issues, BCP, incident SLA |
| Governance | questionnaires, awareness, privacy, projects, RACI, POA&M |
| Reporting & Docs | reports, board pack, documents, evidence, export/import, calendar |
| Admin & Platform | settings, RBAC, branding, webhooks, automation, approvals, tenancy |
| Search & Misc | search, tags, health, AI advisor |
| Security Infra | Security, Database/RLS, Storage, SSRF, Secrets, KMS, Mailer, routing/CSP |
| Frontend Shell | layout, nav, global JS/CSS, responsiveness, accessibility, dark mode |

---

## 2. Roadmap — audit scores (worst first)

| Score | Cluster | Crit | High | Med | Low |
|---|---|---|---|---|---|
| 6.5 | Search & Misc | 0 | 3 | 7 | 5 |
| 6.6 | Risk Analytics | 0 | 3 | 9 | 6 |
| 7.0 | Auth & Access | 1 | 4 | 7 | 1 |
| 7.0 | Compliance | 0 | 3 | 4 | 5 |
| 7.0 | Vendor & Asset | 0 | 1 | 5 | 6 |
| 7.0 | Operations | 0 | 1 | 11 | 4 |
| 7.0 | Reporting & Docs | 0 | 3 | 6 | 8 |
| 7.0 | Admin & Platform | 0 | 3 | 7 | 4 |
| 7.0 | Frontend Shell | 0 | 4 | 8 | 6 |
| 7.1 | Governance | 0 | 2 | 8 | 5 |
| 7.2 | Risk Core | 0 | 2 | 6 | 7 |
| 7.4 | Security Infra | 3 | 6 | 5 | 1 |

**Totals: 4 Critical · 35 High · 83 Medium · 58 Low.** Baseline 6.5–7.4 — a
healthy, well-built application; this was a polish/hardening effort, not a rescue.

---

## 3. Changes shipped (PR #345)

| # | Module | Change | Type |
|---|---|---|---|
| 1 | Auth | MFA-required login read `redirect_after_login` after `session_destroy()` → always landed on `/`. Captured before destroy. | Bug |
| 1 | Automation | `testRun()` returned raw `$e->getMessage()` in JSON → logged server-side, generic client message. | Security |
| 2 | Frontend | Global `:focus-visible` keyboard outline; aria-labels on icon-only logout/theme buttons; notification bell made a keyboard-operable `role=button` with Enter/Space handler. | A11y |
| 3 | Security Infra | `Ssrf::isDangerousInfraHost()` — blocks loopback + cloud-metadata/link-local for the **SMTP host** and **S3 endpoint**, while **allowing** private ranges (on-prem relays / self-hosted MinIO keep working). +11 unit tests. | Security |
| 4 | Search | Each result type gated by `Auth::can('<module>.view')` (no longer leaks titles/IDs of inaccessible records); removed dead `/incident/{id}` results (route retired in #282). | Security |
| 5 | Frontend | **Accessible modals app-wide** — one centralized `app.js` change gives all ~25 modals `role=dialog`, `aria-modal`, `aria-labelledby`, close-button labels, focus-in/return, and a Tab focus-trap. | A11y |
| 6 | Threat | `linkRisk()` validates the target risk exists (RLS-scoped) before inserting — blocks bogus/cross-tenant IDs. | Security |
| 7 | Governance | RACI `save()` / `saveResponsibility()` (writes) raised from `risk.view` → `risk.edit` — a read-only user could previously modify RACI/responsibility data. | Security |
| 7 | Risk Analytics | `KRIController::create()` validates threshold ordering vs direction (`green≤amber≤red` / reverse) so `ragStatus()` can't mis-classify. | Reliability |

**Verification gate:** 138 tests (+11 new) + UI / route-auth / CSRF analyzers all
green; `app.js` passes `node --check`.

---

## 4. Findings verified as FALSE POSITIVE or BY-DESIGN (intentionally not changed)

The audit's high-severity list materially over-flagged. These were checked against
real code and left unchanged for the stated reason:

| Finding | Reality |
|---|---|
| "Session revocation happens post-auth (escalation window)" — *Critical* | `Auth::requireAuth()` checks `is_active` + `sessions_revoked_at` on **every** protected request. No window. |
| "Dashboard queries lack tenant filtering" — *High* | Postgres **Row-Level Security** (migration 028, `setTenant` GUC) enforces tenant isolation at the DB layer; the tenancy integration test proves coverage. Explicit `WHERE tenant_id` is redundant. |
| "Dashboard chart hardcoded hex" — *High* | Charts already resolve colors via `getComputedStyle()`; the hex are fallbacks. |
| "Missing `session_regenerate_id` (fixation)" — *High* | Login regenerates the session id (`Auth` line ~461); the MFA branch destroys+restarts the session (new id). |
| "Residual score not updated on risk update" — *High* | `RiskController::update()` recomputes `residual_score = residual_likelihood × residual_impact`. |
| "AIAdvisor API key leaks in logs" — *High* | A `Bearer`-token redaction regex already exists; `curl_error()` does not contain request headers. |
| "Evidence upload lacks entity-level authz" — *High* | Gates on `$module.write` for upload and `.read` for download. RBAC is module-level **by design** (no per-record ACL in the model). |
| "Compliance PDF import unsafe filename" — *High* | Sanitized via `basename()` + `preg_replace('/[^a-zA-Z0-9._\- ]/','')`. |
| "Calendar / Compliance / Vendor JSON missing XSS flags" — *High* | Served with `Content-Type: application/json` (not an HTML/`<script>` context), so `JSON_HEX_TAG` is not an XSS vector here. |
| "Privacy `updateRequest` lacks object-level validation" — *High* | Permission-gated (`compliance.assess`) + CSRF + status-enum validated + RLS-scoped `UPDATE`. |
| "RiskException weak authorization" — *High* | The sensitive `decide()` (approve/reject) is `requireAdmin()`; creating a *request* is intentionally open to any authenticated user. |
| "Audit HMAC key defaults to JWT_SECRET" — *High* | Uses **domain-separated derivation** (`aegis_audit_v1:`), so there is real key separation; the fallback is documented and intentional. |
| "`applyTenantStamp` silent DEFAULT fallback" — *Medium* | Documented, intentional single-tenant behavior (rows fall back to the `tenant_id` DEFAULT). |
| "CSP `style-src 'unsafe-inline'`" — *High* | Intentional — the app relies on inline styles throughout; removing it requires a large refactor out of scope. |

---

## 5. Remaining backlog (real, deferred — prioritized)

These are genuine but were deferred (effort, design decision, or low real-world
impact). None are blocking; none are security-critical.

**Medium**
- **Admin list pagination** — `AdminController::users()` / `apiKeys()` load all rows.
  Add `LIMIT/OFFSET` + pager controls. Low urgency at typical GRC user counts.
- **Board KRI subquery** — correlated `LIMIT 1` latest-value subquery per KRI;
  rewrite as a `LATERAL` join or window function for large KRI sets.
- **CustomDashboard widget queries** — no caching; a dashboard with many widgets
  issues many queries per load. Candidate for short-TTL caching.

**Low (polish)**
- **Data-driven category-color hex** (threat/treatment views) — category/status
  color arrays use literal hex; needs a token mapping decision (which `--var` per
  semantic category) before converting for dark-mode fidelity.
- **Table semantics** — add `scope="col"` to `<thead>` headers and `<caption>`s
  across list views for screen-reader table navigation.
- **Empty-state / breadcrumb consistency** — a few views deviate from the
  `empty-state-sm` / `page-header` conventions.

**Needs a deployment decision**
- **SSRF depth for infra endpoints** — Batch 3 blocks metadata/loopback only. If a
  deployment never uses internal SMTP/S3, it could opt into full `isSafeUrl()`
  (block all private ranges) via config. Left as the safe default.

---

## 6. Testing

- **Automated:** 138 unit tests (run.php) + integration suite (audit chain, RLS
  tenant isolation, least-privilege role) + analyzers `check_route_auth`,
  `check_csrf`, `check_ui`, `verify_migrations`. All green after every batch.
- **Manual reasoning:** each fix traced through its call path; SSRF guard exercised
  against block/allow IP fixtures; modal a11y behavior reasoned against the
  centralized open/close/Escape paths.

## 7. Remaining risks

- The backlog items above (none security-critical).
- Visual/responsive QA across every page on real devices was not performed in this
  pass — the CSS/markup changes were kept conservative and theme-token-based to
  minimize that risk, but a device sweep is recommended before a major release.
