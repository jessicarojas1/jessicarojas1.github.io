# AEGIS GRC — Enterprise Enhancement Roadmap

> **Purpose:** This file is the authoritative build log for upgrading AEGIS GRC to full enterprise grade.
> Every session picks up where the last left off by reading this file first.
> Status is updated after each item is completed — no need to re-scan the codebase to know where to resume.

---

## Current Architecture Baseline

| Layer | Implementation |
|-------|----------------|
| Runtime | PHP 8.1+ (custom MVC, no Composer) |
| Database | PostgreSQL (PDO, prepared statements throughout) |
| Auth | Session + Argon2ID + TOTP MFA + JWT API |
| Security | CSRF rotation, rate limiting, IDOR protection, path traversal prevention |
| Roles | admin / manager / auditor / analyst / viewer + per-user module grants |
| Frameworks | ISO 27001, ISO 42001, CMMC (importable JSON packages) |
| Modules | Compliance, Audit, Policy, Risk, Incident, Issue, Vendor, Evidence, Export, Metrics |

---

## Phase 1 — Security & Core Enterprise Blockers
**Target: Weeks 1–4 | Goal: Clear IT/Security procurement review**

| # | Feature | Status | Files Created / Modified |
|---|---------|--------|--------------------------|
| 1.1 | HSTS + HTTPS enforcement + Content-Security-Policy | ✅ Complete | `aegis/.htaccess`, `aegis/src/Security.php` |
| 1.2 | Tamper-evident audit log (SHA-256 hash chain) | ✅ Complete | `aegis/src/Auth.php`, `aegis/scripts/verify_audit_log.php`, `aegis/database/migrations/001_enterprise_phase1.sql` |
| 1.3 | OIDC / OAuth 2.0 SSO (Azure AD, Okta, Google) | ✅ Complete | `aegis/src/SSO.php`, `aegis/src/JWT.php`, `aegis/controllers/SSOController.php`, `aegis/views/auth/sso_error.php`, `aegis/views/admin/sso.php`, `aegis/index.php`, `aegis/views/auth/login.php` |
| 1.4 | Workflow automation executor (cron-driven) | ✅ Complete | `aegis/scripts/run_workflows.php`, `aegis/database/migrations/001_enterprise_phase1.sql` |
| 1.5 | Multi-level approval chains | ✅ Complete | `aegis/controllers/ApprovalController.php`, `aegis/views/approval/pending.php`, `aegis/views/approval/review.php`, `aegis/database/migrations/001_enterprise_phase1.sql`, `aegis/index.php` |

---

## Phase 2 — Compliance Completeness
**Target: Weeks 5–8 | Goal: Full compliance team sign-off**

| # | Feature | Status | Files Created / Modified |
|---|---------|--------|--------------------------|
| 2.1 | SOC 2 Type II framework package (JSON seed) | ✅ Complete | `aegis/database/seeds/soc2.json` |
| 2.2 | NIST 800-53 Rev 5 framework package | ✅ Complete | `aegis/database/seeds/nist80053.json` |
| 2.3 | HIPAA framework package | ✅ Complete | `aegis/database/seeds/hipaa.json` |
| 2.4 | PCI-DSS v4 framework package | ✅ Complete | `aegis/database/seeds/pcidss.json` |
| 2.5 | Cross-framework control mapping | ✅ Complete | `aegis/database/migrations/002_phase2.sql`, `aegis/controllers/ComplianceController.php` |
| 2.6 | Scheduled report delivery via email | ✅ Complete | `aegis/scripts/send_scheduled_reports.php`, `aegis/database/migrations/002_phase2.sql` |
| 2.7 | Bulk CSV import (risks, vendors, incidents) | ✅ Complete | `aegis/controllers/ImportController.php`, `aegis/views/import/index.php` |
| 2.8 | Custom fields (extensible metadata per entity) | ✅ Complete | `aegis/database/migrations/002_phase2.sql`, `aegis/src/CustomFields.php` |
| 2.9 | Metrics trending (daily snapshots → trend charts) | ✅ Complete | `aegis/database/migrations/002_phase2.sql`, `aegis/scripts/capture_metrics_snapshot.php`, `aegis/controllers/MetricsController.php`, `aegis/views/metrics/index.php` |
| 2.10 | Document management with version control + DLP metadata | ✅ Complete | `aegis/controllers/DocumentController.php`, `aegis/views/documents/index.php`, `aegis/views/documents/create.php`, `aegis/views/documents/view.php` |

---

## Phase 3 — Differentiators
**Target: Weeks 9–12 | Goal: Win the proposal vs commercial alternatives**

| # | Feature | Status | Files Created / Modified |
|---|---------|--------|--------------------------|
| 3.1 | Outbound webhook framework (Jira, Slack, ServiceNow, PagerDuty) | ✅ Complete | `aegis/src/Webhook.php`, `aegis/scripts/dispatch_webhooks.php`, `aegis/controllers/WebhookController.php`, `aegis/views/admin/webhooks.php`, `aegis/views/admin/webhook_deliveries.php` |
| 3.2 | SIEM/scanner ingestion API (Tenable, Qualys, Wiz) | ✅ Complete | `aegis/api/ingest.php` (POST /api/v1/ingest/{tenable\|qualys\|wiz\|generic}) |
| 3.3 | Assessment questionnaire builder + response ingestion | ✅ Complete | `aegis/controllers/QuestionnaireController.php`, `aegis/views/questionnaire/` (index, create, view, respond) |
| 3.4 | Change management module (RFC process, CAB approval) | ✅ Complete | `aegis/controllers/ChangeController.php`, `aegis/views/change/` (index, create, view) |
| 3.5 | Business continuity / DR module (BCP plans, RTO/RPO, tabletop) | ✅ Complete | `aegis/controllers/BCPController.php`, `aegis/views/bcp/` (index, create, view) |
| 3.6 | Data classification + asset inventory | ✅ Complete | `aegis/controllers/AssetController.php`, `aegis/views/assets/` (index, create, view) |
| 3.7 | Treatment plan Gantt / roadmap view | ✅ Complete | `aegis/views/risk/roadmap.php`, `aegis/controllers/RiskController.php::roadmap()` |
| 3.8 | Executive board dashboard with GRC score trends | ✅ Complete | `aegis/views/report/board.php`, `aegis/controllers/ReportController.php::board()` |
| 3.9 | Mobile-responsive overhaul + PWA manifest | ✅ Complete | `aegis/public/manifest.json`, CSS + JS mobile additions |
| 3.10 | AI-assisted control gap suggestions | ✅ Complete | `aegis/src/AIAdvisor.php` (Claude/OpenAI dual support) |

---

## Architecture Backlog (Cross-Cutting)

| Item | Status | Notes |
|------|--------|-------|
| Job queue (async email, workflow triggers) | 🔄 Partial — cron scripts created in Phase 1 | Full async queue needs Redis or DB-backed worker |
| File storage abstraction (S3/object store) | ✅ Complete | `aegis/src/Storage.php` — local + S3 (Sig V4) adapters; swap via `storage_driver` setting |
| DB connection pooling (PgBouncer) | ⏳ Pending | Document in deployment guide |
| Health check endpoint `/health` | ✅ Complete | `GET /health` — DB ping, disk space, PHP version, latency; returns 200/503 |
| Segregation of Duties enforcement | ✅ Complete | `ApprovalController::decide()` — blocks self-approval and creator-approval; logs SoD violations |
| Multi-tenancy (org scoping) | ⏳ Pending | Add `org_id` to all entity tables; routing by subdomain |
| Redis-backed rate limiting | ⏳ Pending | Current: PostgreSQL `rate_limits` table — survives restarts |
| Nonce-based CSP (eliminate unsafe-inline) | ✅ Complete | `Security::nonce()` + dynamic CSP header; `unsafe-inline` removed from script-src |
| AI gap analysis in compliance UI | ✅ Complete | Lazy-loaded panel on package view; `GET /compliance/{id}/ai-suggestions` JSON endpoint |

---

## Deployment / Ops Notes

### Running the workflow executor
```bash
# Add to crontab — runs every 5 minutes
*/5 * * * * php /var/www/aegis/scripts/run_workflows.php >> /var/log/aegis-workflows.log 2>&1
```

### Verifying audit log integrity
```bash
php /var/www/aegis/scripts/verify_audit_log.php
# Exits 0 if chain intact, 1 if tampering detected (prints first broken record ID)
```

### SSO Configuration (OIDC)
Set these keys in the `settings` table or via Admin → SSO Settings:
| Key | Example |
|-----|---------|
| `sso_enabled` | `1` |
| `sso_provider_name` | `Azure AD` |
| `sso_client_id` | `<app-client-id>` |
| `sso_client_secret` | `<client-secret>` |
| `sso_discovery_url` | `https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration` |
| `sso_default_role` | `viewer` |
| `sso_auto_provision` | `1` |
| `sso_role_claim` | `roles` |
| `sso_role_mapping` | `{"GRC-Admin":"admin","GRC-Manager":"manager","GRC-Auditor":"auditor"}` |

### Approval chain crontab (escalation reminders)
```bash
# Daily at 8am — sends reminder emails for pending approvals > 48h old
0 8 * * * php /var/www/aegis/scripts/run_workflows.php --mode=approval-reminders >> /var/log/aegis-approvals.log 2>&1
```

### Phase 3 crontabs
```bash
# Webhook dispatch — every minute
* * * * * php /var/www/aegis/scripts/dispatch_webhooks.php >> /var/log/aegis-webhooks.log 2>&1

# Metrics snapshot — daily at midnight
0 0 * * * php /var/www/aegis/scripts/capture_metrics_snapshot.php >> /var/log/aegis-metrics.log 2>&1

# Scheduled report delivery — hourly
0 * * * * php /var/www/aegis/scripts/send_scheduled_reports.php >> /var/log/aegis-reports.log 2>&1
```

### SIEM Ingestion API
```bash
# Tenable.io example (POST findings as JSON)
curl -X POST https://your-aegis-instance/api/v1/ingest/tenable \
  -H "X-API-Key: <your-api-key>" \
  -H "Content-Type: application/json" \
  -d '{"vulnerabilities": [{"plugin_id": "19506", "plugin_name": "...", "severity": "high", ...}]}'

# Wiz example
curl -X POST https://your-aegis-instance/api/v1/ingest/wiz \
  -H "X-API-Key: <your-api-key>" \
  -d '{"issues": [{"id": "...", "severity": "CRITICAL", "control": {...}}]}'
```

### AI Advisor setup
Set in the `settings` table or Admin → System Settings:
| Key | Value |
|-----|-------|
| `ai_provider` | `claude` or `openai` |
| `ai_api_key` | Your Anthropic or OpenAI API key |

---

## Session Log

| Date | Session Summary |
|------|----------------|
| 2026-05-25 | Initial security audit hardening (17 files): CSRF rotation, IDOR/path traversal on evidence, SMTP injection prevention, API CORS restriction, session hardening, XSS escaping, SQL injection (LIMIT/OFFSET parameterization), install.php HTTP block |
| 2026-05-25 | Phase 1 complete: HSTS/CSP, hash-chained audit log, OIDC SSO, workflow executor, approval chains |
| 2026-05-25 | Phase 2 complete: SOC2/NIST/HIPAA/PCI-DSS seeds, cross-framework mapping, scheduled reports, bulk CSV import, custom fields, metrics trending, document management |
| 2026-05-25 | Phase 3 complete: webhooks (Slack/Jira/PagerDuty/ServiceNow), SIEM ingest API (Tenable/Qualys/Wiz), questionnaire builder, change management, BCP/DR, asset inventory, risk roadmap, executive board dashboard, PWA/mobile, AI advisor |
| 2026-05-25 | Architecture backlog: nonce-based CSP (removed unsafe-inline), /health endpoint, S3 storage adapter (Sig V4), SoD enforcement in approvals, AI gap suggestions wired into compliance package view |
