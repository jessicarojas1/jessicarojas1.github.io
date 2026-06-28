# AEGIS GRC Platform

![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

AEGIS is a self-hosted Governance, Risk, and Compliance (GRC) platform built with PHP 8.2 and PostgreSQL. It consolidates compliance tracking, risk management, audit workflows, and policy lifecycle management into a single cohesive application — with no external framework dependencies, no build pipeline, and a single Docker container deployment model. Import any compliance standard via JSON or create custom packages from scratch.

### Documentation

| Doc | Covers |
|-----|--------|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Request lifecycle, layout, core libraries, data model |
| [`SECURITY.md`](SECURITY.md) | Security controls + automated CI verification gates |
| [`PERMISSIONS_MODEL.md`](PERMISSIONS_MODEL.md) | RBAC roles, granular `module.action` permissions |
| [`AUDIT_TRAIL.md`](AUDIT_TRAIL.md) | Tamper-evident hash-chained audit log + verification |
| [`API.md`](API.md) | REST API: auth, pagination, rate limits, endpoints |
| [`RISK_MODULE.md`](RISK_MODULE.md) | Risk scoring engine, bands, appetite |
| [`AIADVISOR.md`](AIADVISOR.md) | AI governance: kill-switch, redaction, audit |
| [`DEPLOYMENT.md`](DEPLOYMENT.md) | Env vars, Docker/Render, cron, backup/restore, checklist |

### Quality gates (run locally or in CI)

```bash
php tests/run.php                  # unit suite (64 assertions)
php scripts/verify_migrations.php  # migration registration/ordering/idempotency
php scripts/check_ui.php           # CSP: no inline handlers, scripts carry a nonce
php scripts/check_route_auth.php   # every public action enforces authorization
php scripts/check_csrf.php         # every POST route validates CSRF
```

---

## Features

### Core GRC Modules

- **Compliance Management** — Track compliance packages against any imported standard, manage control implementations, attach evidence, and link policies to objectives; bulk status/assess operations; multi-format import (JSON, CSV, XLSX, PDF, manual entry)
- **Risk Register** — Create and score risks using a configurable likelihood × impact matrix, assign owners, record treatments, and visualize exposure on a 5×5 heatmap; BowTie diagrams; risk scenarios; multi-select treatment strategies
- **Audit Workflows** — Schedule and conduct audits against compliance packages, manage checklist items, and record scores through to completion; recurring audit schedules
- **Audit Findings** — Track external audit findings, penetration-test results, certification findings, and regulatory observations with severity ratings and remediation timelines
- **Policy Lifecycle** — Draft, version, approve, publish, and review policies with full mapping to compliance controls; attestation campaigns; review scheduling
- **Change Management** — Change request tracking with CAB (Change Advisory Board) approvals and change advisory workflows
- **Incident Management** — Incident CRUD with severity classification, SLA tracking, update timeline, acknowledge and close flows
- **Issue Tracking** — Issue register linked to compliance, risk, and audit entities
- **Vendor Management** — Vendor register with risk tier, data access tracking, contract dates, vendor assessments, and portal link generation for external questionnaire responses
- **Asset Management** — Asset register with risk linking and categorization
- **Business Continuity Planning (BCP/DRP)** — Business continuity and disaster recovery plan management
- **Threat Register** — Threat catalogue with severity and status tracking
- **Workforce & Awareness Training** — Training program management, user assignment tracking, and completion recording
- **Account Reviews (Access Certification)** — Periodic access review campaigns for certifying user entitlements
- **Privacy Management** — Privacy impact assessments and data handling records

### Risk Management (Extended)

- **Key Risk Indicators (KRIs)** — Define and track KRI thresholds with breach alerting
- **Risk Treatment Plans** — Structured treatment action management linked to risks
- **Risk Exceptions** — Formal risk exception requests with approval tracking
- **Risk Reviews** — Scheduled review records and risk acceptance documentation
- **Risk Appetite** — Configurable risk appetite thresholds per category (Financial, Operational, Strategic, Compliance, Technology, Reputational)
- **Risk Score History** — Automatic score-change logging for trend analysis

### Compliance & Assurance (Extended)

- **System Security Plans (SSP)** — Full SSP authoring with a 7-tab view (Overview, Approval, Organization, Boundary, Environment, Inventory, Compliance); multiple presentation modes (Standard, Military, Corporate, Air Force, DoD); JSONB-backed hardware, software, network, server, and data inventories; versioning and authorization signatures
- **POA&M** — Plans of Action and Milestones with milestone tracking, linked to compliance packages and POA&M numbers
- **CUI Inventory** — Controlled Unclassified Information inventory management
- **SPRS** — Supplier Performance Risk System score tracking
- **ODP** — Organizational-Defined Parameters management for NIST controls
- **RACI Matrix** — Responsibility assignment matrix per compliance package and control
- **Cross-Framework Control Mapping** — Map controls across multiple compliance standards

### Automation & Integration

- **Workflow Automation** — Rules engine with configurable triggers and action chains; execution history; cooldown periods; 46+ built-in templates
- **Webhook Integration** — 11 supported providers: Slack, Microsoft Teams, Discord, Jira, PagerDuty, ServiceNow, Google Chat, OpsGenie, Datadog, Splunk HEC, and Generic HTTP; delivery log per endpoint
- **Approval Workflows** — Multi-step approval chains for risks, policies, audits, vendors, and incidents; configurable approval templates
- **Scheduled Reports** — Automated report generation and delivery
- **Email Templates** — Customizable notification email templates

### Analytics & Reporting

- **GRC Metrics Dashboard** — Compliance percentage, risk trends, audit scores, and policy lifecycle status rendered as interactive Chart.js visualizations
- **Custom Dashboards** — User-configurable dashboard widgets
- **Export Engine** — Per-module CSV and XLSX exports plus a full-platform ZIP bundle; formula-injection safe
- **GRC Projects** — Project tracking for GRC initiatives with tasks and entity links

### Platform & Administration

- **REST API v1** — Full API with API-key and JWT authentication, rate limiting, and CORS origin enforcement
- **Role-Based Access Control** — Five built-in roles (admin, manager, auditor, analyst, viewer) with per-user per-module permission overrides
- **Dark Mode** — Full dark/light theme toggle persisted via localStorage with CSS custom properties
- **Admin Panel** — User management, API key management, alert configurations, workflow builder, risk matrix configurator, permission matrix editor, module visibility controls, SMTP settings, and SSO configuration
- **Document Management** — Document store with version upload history
- **Questionnaires** — Configurable questionnaire builder for vendor and internal assessments
- **Playbooks** — Operational playbook library linked to incidents and risks
- **Calendar** — GRC event and due-date calendar view
- **Tags** — Cross-module tagging system
- **Evidence Management** — File upload with randomized filenames, SHA-256 integrity hashing, and PHP-gated downloads
- **Search** — Global full-text search across modules
- **Notification Preferences** — Per-user notification channel and frequency configuration
- **Security-First Design** — Argon2ID hashing, CSRF protection, CSP/HSTS headers, brute-force lockout, PDO prepared statements throughout, TOTP MFA with backup codes, tamper-evident audit log with SHA-256 hash chain

---

## Tech Stack

### Backend

| Component | Choice |
|---|---|
| Language | PHP 8.2 (strict types enabled) |
| Web Server | Apache 2 with `mod_rewrite` + `mod_headers` |
| Database | PostgreSQL (accessed via PDO / PDO_PGSQL) |
| Authentication | Session-based + HS256 JWT (pure-PHP implementation) |
| Password Hashing | Argon2ID (65536 KB memory, 4 time cost, 2 threads) |
| Export | ZipArchive + manual XLSX generation (built-in PHP only) |
| Dependency Manager | None — zero Composer dependencies |

### Frontend

| Component | Choice |
|---|---|
| Markup / Style | Vanilla HTML5 / CSS3 (no framework) |
| JavaScript | Vanilla ES2020 (no build step, no bundler) |
| Charts | Chart.js 4.4.3 (CDN) |
| Icons | Bootstrap Icons 1.11.3 (CDN, icon font only) |
| Typography | Inter via Google Fonts (CDN) |
| Theming | CSS custom properties (variables) |

### Infrastructure

| Component | Choice |
|---|---|
| Container | Docker (`php:8.2-apache` base image) |
| Hosting | Render.com (Docker web service, free tier) |
| CI/Deploy | `render.yaml` manifest + `scripts/startup.sh` startup hook |
| DB Isolation | PostgreSQL `aegis` schema (separate from `public`) |

---

## Directory Structure

```
aegis/
├── .htaccess                      # URL rewriting, security headers, access rules
│                                  # Blocks install.php and sensitive directories post-deploy
├── Dockerfile                     # php:8.2-apache image; installs pdo_pgsql + zip extensions
├── docker-compose.yml             # Local dev stack: app container + postgres container
├── render.yaml                    # Render.com deployment manifest (web service + DB reference)
├── index.php                      # Front controller: loads .env, starts session, routes requests
├── install.php                    # One-shot DB schema installer + seed runner (idempotent;
│                                  # blocked by .htaccess after first run in production)
│
├── api/
│   └── index.php                  # REST API v1: API-key/JWT auth, rate limiting, all endpoints
│
├── config/
│   ├── app.php                    # App-level config: JWT secret, rate limits, password policy,
│   │                              # CSRF token lifetime
│   └── database.php               # DSN builder: reads DATABASE_URL env var → PDO connection
│                                  # string with search_path=aegis
│
├── src/
│   ├── Auth.php                   # Session auth, login/logout, RBAC, permission cache,
│   │                              # activity logging
│   ├── Database.php               # PDO singleton with query/fetchOne/fetchAll/insert/update
│   │                              # convenience helpers
│   ├── JWT.php                    # Pure-PHP HS256 JWT: encode/decode/issue/verify
│   │                              # (exp claim required)
│   └── Security.php               # CSRF tokens, Argon2ID hashing, rate limiting,
│                                  # CSP/HSTS headers, XSS output helpers
│
├── controllers/
│   ├── AccountReviewController.php  # Access certification / account review campaigns
│   ├── AdminController.php          # Admin: users, risk matrix, workflows, alerts, API keys,
│   │                                # per-user permissions, module visibility, SMTP
│   ├── ApprovalController.php       # Multi-step approval chains for risks, policies, vendors, etc.
│   ├── AssetController.php          # Asset register with risk linking
│   ├── AuditController.php          # Audits: list, create, view, update, complete, items, schedules
│   ├── AuditFindingController.php   # External audit findings: severity, status, remediation timeline
│   ├── AuthController.php           # Login form, login POST (open-redirect fix), logout, MFA
│   ├── AutomationController.php     # Workflow automation rules engine; trigger/action builder
│   ├── AwarenessController.php      # Awareness training programs and user completion tracking
│   ├── BCPController.php            # Business continuity and disaster recovery plans
│   ├── BowTieController.php         # BowTie risk diagram view
│   ├── CalendarController.php       # GRC event and due-date calendar
│   ├── ChangeController.php         # Change management with CAB approvals
│   ├── ComplianceController.php     # Packages, objectives, control implementations, bulk ops,
│   │                                # multi-format import (JSON/CSV/XLSX/PDF/manual)
│   ├── CUIController.php            # Controlled Unclassified Information (CUI) inventory
│   ├── CustomDashboardController.php# User-configurable dashboard widgets
│   ├── DashboardController.php      # Dashboard KPIs, due-items widget, mark-alert-read AJAX
│   ├── DocumentController.php       # Document management with version upload history
│   ├── DocsController.php           # In-app documentation module
│   ├── EvidenceController.php       # Evidence file upload, integrity hashing, gated download
│   ├── ExportController.php         # CSV/XLSX/ZIP exports for all data modules
│   ├── ImportController.php         # Compliance import: JSON, CSV, XLSX, PDF, single-control
│   ├── IncidentController.php       # Incident CRUD, severity, SLA tracking, timeline
│   ├── IssueController.php          # Issue tracker linked to compliance/risk/audit
│   ├── KRIController.php            # Key Risk Indicators with threshold breach alerting
│   ├── MetricsController.php        # GRC metrics: compliance %, risks, audits, policies;
│   │                                # Chart.js data endpoints
│   ├── ODPController.php            # Organizational-Defined Parameters for NIST controls
│   ├── PlaybookController.php       # Operational playbooks linked to incidents and risks
│   ├── POAMController.php           # Plans of Action and Milestones with milestone tracking
│   ├── PolicyController.php         # Policies: CRUD, versioning, approve/publish, control mapping,
│   │                                # attestation campaigns, review scheduling
│   ├── PrivacyController.php        # Privacy impact assessments and data handling records
│   ├── ProfileController.php        # User profile, password change, notification preferences
│   ├── ProjectController.php        # GRC project tracking with tasks and entity links
│   ├── QuestionnaireController.php  # Questionnaire builder for vendor and internal assessments
│   ├── RACIController.php           # RACI responsibility assignment matrix per compliance package
│   ├── ReportController.php         # Scheduled report generation and delivery
│   ├── RiskAcceptanceController.php # Formal risk acceptance documentation
│   ├── RiskController.php           # Risk register: CRUD, likelihood×impact scoring, treatments,
│   │                                # scenarios, risk matrix, BowTie, score history
│   ├── RiskExceptionController.php  # Risk exception requests with approval tracking
│   ├── RiskReviewController.php     # Scheduled risk review records
│   ├── ScenarioController.php       # Risk scenario modelling
│   ├── SearchController.php         # Global full-text search across modules
│   ├── SPRSController.php           # Supplier Performance Risk System (SPRS) score tracking
│   ├── SSOController.php            # SSO settings + live OIDC/OAuth2 login & callback (SAML2 not implemented)
│   ├── SSPController.php            # System Security Plans: 7-tab authoring, versioning,
│   │                                # presentation modes, JSONB inventories
│   ├── TagController.php            # Cross-module tagging system
│   ├── ThreatController.php         # Threat register with severity and status tracking
│   ├── TreatmentController.php      # Risk treatment plan management
│   ├── UnsubscribeController.php    # Email unsubscribe handling
│   ├── VendorController.php         # Vendor register: risk tier, assessments, questionnaire portal
│   └── WebhookController.php        # Webhook endpoints for 11 providers (Slack, Teams, Discord,
│                                    # Jira, PagerDuty, ServiceNow, Google Chat, OpsGenie,
│                                    # Datadog, Splunk HEC, Generic HTTP); delivery log
│
├── database/
│   ├── schema.sql                 # 18 CREATE TABLE statements in aegis schema + 13 indexes
│   └── seeds/
│       └── seed_frameworks.php    # CLI tool for importing framework JSON files
│
├── scripts/
│   └── startup.sh                 # Docker CMD: runs install.php (idempotent) then starts Apache
│
├── public/
│   ├── css/
│   │   └── app.css                # All styles: CSS variables, sidebar, topbar, cards, tables,
│   │                              # forms, module-specific layouts
│   └── js/
│       └── app.js                 # Sidebar toggle, alert panel, AJAX markAlertRead, modal
│                                  # helpers, time-ago formatting
│
└── views/
    ├── layout.php                 # Shell: collapsible accordion sidebar nav (8 sections),
    │                              # topbar with dark-mode toggle, alert panel fly-out,
    │                              # Chart.js + app.js script loads
    ├── auth/
    │   └── login.php              # Login form; MFA verification; backup code recovery
    ├── dashboard/
    │   └── index.php              # KPI cards, due-items widget (overdue/7d/30d/expired tabs),
    │                              # activity log
    ├── compliance/
    │   ├── index.php              # Package grid with per-package compliance progress bars;
    │   │                          # multi-select delete
    │   ├── package.php            # Domain tree + control list; bulk status/assess actions;
    │   │                          # floating action bar; inline CSRF refresh
    │   ├── objective.php          # Single control detail: status form, evidence, policy
    │   │                          # mappings, audit findings, additional information
    │   └── import.php             # Multi-format import: JSON, CSV, XLSX, PDF, single-control;
    │                              # CSV/Excel template downloads
    ├── audit/
    │   ├── index.php              # Audit list with status badges
    │   ├── create.php             # Create audit form
    │   └── view.php               # Audit detail: item checklist, score, complete button
    ├── audit_findings/            # External audit finding list and detail views
    ├── policy/
    │   ├── index.php              # Policy list with lifecycle status
    │   ├── create.php             # Create policy form with rich textarea
    │   └── view.php               # Policy detail: content, mappings, version history,
    │                              # approve/publish actions
    ├── risk/
    │   ├── index.php              # Risk register table with category + score badges
    │   ├── create.php             # Create risk form with scoring sliders
    │   ├── view.php               # Risk detail: treatment history, score history
    │   └── matrix.php             # Interactive 5×5 risk heatmap (Chart.js scatter + CSS grid)
    ├── change/                    # Change request list, create, and view (with CAB approval)
    ├── incident/                  # Incident list, create, view (with SLA timeline)
    ├── issue/                     # Issue tracker list, create, view
    ├── vendor/                    # Vendor register, assessments, questionnaire portal
    ├── assets/                    # Asset register list, create, view
    ├── bcp/                       # BCP/DRP plan list, create, view
    ├── threat/                    # Threat register list, create, view
    ├── awareness/                 # Training programs and user assignment tracking
    ├── account_review/            # Access certification campaigns
    ├── privacy/                   # Privacy impact assessments
    ├── ssp/
    │   ├── index.php              # SSP plan list
    │   ├── create.php             # Create SSP form
    │   ├── view.php               # 7-tab SSP detail: Overview, Approval, Organization,
    │   │                          # Boundary, Environment, Inventory, Compliance;
    │   │                          # presentation mode badge (Standard/Military/Corporate/
    │   │                          # Air Force/DoD); JSONB inventory tables (hardware,
    │   │                          # software, network, server, data)
    │   └── document.php           # SSP document/export view
    ├── poam/                      # POA&M item list, create, view with milestones
    ├── kri/                       # Key Risk Indicators list and detail
    ├── treatment/                 # Risk treatment plan management
    ├── raci/                      # RACI matrix per compliance package
    ├── automation/
    │   ├── index.php              # Automation rules list
    │   ├── create.php             # Rule builder: trigger selector + action chain
    │   └── view.php               # Rule detail with execution history
    ├── questionnaire/             # Questionnaire builder and response views
    ├── playbook/                  # Playbook library
    ├── calendar/                  # GRC event calendar
    ├── projects/                  # GRC project list, create, view with tasks
    ├── dashboards/                # Custom dashboard widget configuration
    ├── odp/                       # ODP management views
    ├── cui/                       # CUI inventory views
    ├── sprs/                      # SPRS score tracking views
    ├── export/
    │   └── index.php              # Card grid: per-module CSV/XLSX download forms + ZIP export
    ├── metrics/
    │   └── index.php              # 4 KPI rings + 5 Chart.js charts (stacked bar, doughnut,
    │                              # horizontal bar, 2 line charts) + 2 data tables
    ├── report/                    # Scheduled report configuration and delivery
    ├── search/                    # Global search results
    ├── documents/                 # Document management with version history
    ├── docs/
    │   └── index.php              # In-app documentation (scrollspy sidebar + rich content)
    ├── profile/                   # User profile, password change, notification preferences
    ├── admin/
    │   ├── index.php              # Admin overview: user count, API keys, activity log, settings
    │   ├── users.php              # User management CRUD table
    │   ├── risk_matrix.php        # Risk matrix configurator (labels, thresholds, per-cell colors)
    │   ├── workflows.php          # Workflow builder (trigger + actions)
    │   ├── alerts.php             # Alert configurations + recent alert log
    │   ├── api_keys.php           # API key management (create, revoke, copy-to-clipboard)
    │   └── permissions.php        # Per-user, per-module permission matrix (sticky-column table)
    └── errors/
        ├── 403.php                # Forbidden error page
        └── 404.php                # Not found error page
```

---

## Architecture

### Web Request Lifecycle

```
Browser Request
      │
      ▼
Apache (.htaccess)
  ├── Block: install.php, /config/, /src/, /database/, /scripts/
  ├── Set security headers (X-Frame-Options, X-Content-Type-Options, etc.)
  └── RewriteRule: everything → index.php (front controller)
      │
      ▼
index.php
  ├── Load .env / environment variables
  ├── Start session (httponly, samesite=strict, secure flag on HTTPS)
  ├── Autoload src/ classes (Auth, Database, Security, JWT)
  ├── Set CSP / HSTS headers via Security.php
  └── Router: match URI against static + regex route tables
      │
      ▼
Controller::method()
  ├── Auth::requireAuth()         → checks $_SESSION['user'] + session timeout
  ├── Auth::requirePermission()   → RBAC check (role defaults + DB overrides)
  ├── Database::fetchAll/One/insert/update (PDO prepared statements)
  └── require views/module/page.php
      │
      ▼
View (PHP template)
  ├── ob_start() → render module HTML into $content
  └── require views/layout.php
      │
      ▼
layout.php → full HTML response
  (sidebar, topbar, alert panel, $content, Chart.js, app.js)
```

### API Request Lifecycle

```
API Client Request  (URI: /api/...)
      │
      ▼
index.php → delegates to api/index.php
      │
      ▼
api/index.php
  ├── CORS check: Origin must match APP_URL (no wildcard)
  ├── Authentication (one of):
  │     X-API-Key header → SHA-256 hash lookup in api_keys table
  │     Authorization: Bearer <token> → JWT HS256 verify (exp required)
  ├── Rate limit: 60 req/min per IP (tracked in rate_limits table)
  └── match(true) dispatch → inline handler lambda
      │
      ▼
JSON response
```

### Data Relationships

```
standards (1) ──────────< compliance_packages (1) ──────────< compliance_objectives
                                                                   (tree: parent_id)
                                                                         │
                                                              control_implementations
                                                              (1:1 per objective)
                                                                    ├── assigned_to → users
                                                                    └── reviewed_by → users

compliance_packages ──< audits ──< audit_items ──> compliance_objectives
compliance_packages ──< poam_items ──< poam_milestones
compliance_packages ──< raci_assignments ──> users
compliance_packages ──< ssp_plans (via compliance linkage tab)
compliance_objectives ──< control_framework_mappings (cross-framework)
compliance_objectives ──< audit_findings ──< finding_updates

policies ──< policy_mappings  ──> compliance_objectives
policies ──< policy_versions
policies ──< policy_reviews

risks ──< risk_treatments
risks ──< risk_score_history
risks ──< risk_scenarios
risks ──< risk_bowtie_events
risks ──< risk_reviews
risks ──< risk_acceptances
risks ──> risk_categories
risks ──> users (owner_id, created_by)

workflows ──< workflow_executions
approval_templates ──< approval_instances ──< approval_decisions ──> users

webhook_endpoints ──< webhook_delivery_log

awareness_programs ──< awareness_assignments ──> users
account_reviews ──< account_review_items ──> users

grc_projects ──< grc_project_tasks
grc_projects ──< grc_project_links (polymorphic: risk/policy/audit/etc.)

custom_dashboards ──< dashboard_widgets ──> users

users ──< api_keys
users ──< user_permissions  (module + permission grants)
users ──< user_notification_prefs
users ──< alerts
users ──< activity_log  (SHA-256 hash chain per entry)
```

---

## Database Schema

All tables live in the `aegis` PostgreSQL schema (isolated from `public`). The base schema is created by `install.php` using `database/schema.sql`; all subsequent feature additions are applied via numbered migration scripts in `database/migrations/`.

### Base Tables (schema.sql)

| Table | Description |
|---|---|
| `users` | User accounts: email, hashed password, role, active flag; MFA secret, SSO linking columns |
| `api_keys` | API keys per user: SHA-256 hash of the key, name, last-used timestamp |
| `standards` | Compliance framework definitions (ISO 27001, NIST, SOC 2, etc.) |
| `compliance_packages` | Instances of a standard assigned for tracking within the org |
| `compliance_objectives` | Individual controls/practices within a package; tree structure via `parent_id`; `additional_information` column |
| `control_implementations` | One-to-one implementation record per objective: status, evidence, assignee, reviewer |
| `audits` | Audit records tied to a compliance package: title, scope, status, scheduled date |
| `audit_schedules` | Recurring audit schedule definitions |
| `audit_items` | Individual checklist items within an audit, mapped to compliance objectives |
| `policies` | Policy documents: title, content, owner, lifecycle status |
| `policy_versions` | Immutable version snapshots of policy content on each revision |
| `policy_mappings` | Many-to-many join between policies and compliance objectives |
| `policy_reviews` | Scheduled or completed review records for a policy |
| `risk_categories` | Taxonomy of risk categories with amber/red appetite thresholds |
| `risks` | Risk register entries: title, description, likelihood, impact, owner, status, JSONB treatment strategies |
| `risk_treatments` | Treatment actions (accept/mitigate/transfer/avoid) linked to a risk |
| `risk_matrix_config` | Admin-configurable labels, thresholds, colors, and per-cell JSONB treatment config |
| `workflows` | Workflow definitions: trigger condition + ordered action list; cooldown; execution tracking |
| `alerts` | Per-user alert notifications with read/unread state |
| `alert_configs` | Alert trigger configurations (thresholds, channels, recipients) |
| `settings` | Key-value store for application-level settings |
| `activity_log` | Tamper-evident audit trail: user, action, entity, IP, user agent, SHA-256 hash chain |
| `rate_limits` | Request count windows per IP for API rate limiting |
| `user_permissions` | Per-user, per-module explicit permission grants that extend or override role defaults |

### Migration-Added Tables

| Migration | Tables Added | Purpose |
|---|---|---|
| `001_enterprise_phase1.sql` | `workflow_executions`, `approval_templates`, `approval_steps`, `approval_instances`, `approval_decisions` | Workflow execution history; multi-step approval chains |
| `002_phase2.sql` | `control_framework_mappings` | Cross-framework control mapping |
| `003_phase3.sql` | `webhook_endpoints`, `webhook_delivery_log` | Webhook integration for 11 external providers |
| `004_risk_enhancements.sql` | — (columns only) | Multi-select treatment strategies (JSONB) on `risks` |
| `005_risk_enterprise.sql` | `risk_score_history`, `risk_scenarios`, `risk_bowtie_events` | Risk score trend logging; scenario modelling; BowTie diagram data |
| `006_email_risk_review.sql` | `email_templates`, `risk_reviews` | Customizable email templates; scheduled risk review records |
| `007_risk_extensions.sql` | `risk_acceptances`, `risk_exceptions`, `risk_bowtie` | Formal risk acceptance and exception records; BowTie structure |
| `008_notification_prefs.sql` | `user_notification_prefs` | Per-user notification channel and frequency preferences |
| `009_remove_seeded_packages.sql` | — | Data cleanup: removes auto-seeded compliance packages |
| `010_risk_matrix_cells.sql` | — (columns only) | Per-cell JSONB treatment config on `risk_matrix_config` |
| `011_drop_builtin_columns.sql` | — | Removes legacy `is_builtin`/`is_paid` columns |
| `012_awareness_account_reviews_privacy.sql` | `awareness_programs`, `awareness_assignments`, `account_reviews`, `account_review_items`, `privacy_assessments` | Awareness training; access certification campaigns; privacy impact assessments |
| `013_ssp.sql` | `ssp_plans` (with JSONB inventory columns: `hardware_inventory`, `software_inventory`, `network_devices`, `server_inventory`, `data_inventory`, `team_contacts`, `contracts`, `user_device_types`, `other_connected_systems`) | System Security Plan authoring with structured inventory data |
| `014_poam.sql` | `poam_items`, `poam_milestones` | Plans of Action and Milestones with milestone tracking |
| `015_projects.sql` | `grc_projects`, `grc_project_tasks`, `grc_project_links` | GRC project tracking with tasks and entity links |
| `016_findings_automation.sql` | `audit_findings`, `finding_updates`, `automation_rules`, `automation_rule_actions` | External audit findings; automation rules engine |
| `017_dashboards_raci.sql` | `custom_dashboards`, `dashboard_widgets`, `raci_assignments` | Custom dashboard widgets; RACI responsibility matrix |
| `018_ssp_versioning.sql` | — (columns only) | SSP version, revision, and authorization signature fields |
| `019_ssp_extended.sql` | — (columns only) | SSP company info, approval, certification, boundary, and environment detail fields |

---

## Security

AEGIS was designed with security as a first-class concern throughout. The following measures are implemented across the application layer, transport layer, and infrastructure layer.

---

### Authentication & Session Management

**Password hashing — Argon2ID**
All passwords are hashed using PHP's `PASSWORD_ARGON2ID` algorithm with hardened parameters: 65,536 KB memory cost, 4 time-cost iterations, and 2 parallel threads. These exceed OWASP's minimum recommended parameters and make offline brute-force attacks computationally prohibitive. Plaintext passwords are never stored, logged, or compared directly.

**Session fixation prevention**
The session ID is regenerated using `session_regenerate_id(true)` immediately after every successful authentication event — including password login, TOTP MFA verification, and MFA backup code use. This prevents an attacker who can observe a pre-login session ID from hijacking the authenticated session.

**Session hardening**
Sessions are configured with the following flags in every environment:

| Flag | Value | Effect |
|---|---|---|
| `session.cookie_httponly` | `1` | Prevents JavaScript access to the session cookie |
| `session.cookie_samesite` | `Strict` | Blocks the cookie from being sent in cross-site requests |
| `session.use_strict_mode` | `1` | Rejects unrecognised session IDs (prevents session adoption) |
| `session.use_only_cookies` | `1` | Prevents session ID from appearing in URLs |
| `session.cookie_secure` | `1` (HTTPS only) | Cookie is never sent over plain HTTP |

**Session timeout**
Authenticated sessions expire after 60 minutes of inactivity. The `last_activity` timestamp is checked on every request in `Auth::requireAuth()` and the session is destroyed and redirected to login if the threshold is exceeded.

**Secure logout**
`Auth::logout()` calls `session_destroy()` followed by `session_start()` to clear all session state server-side. The logout endpoint requires a valid CSRF token (POST method) to prevent logout CSRF attacks.

---

### Multi-Factor Authentication (MFA)

TOTP-based MFA is supported for all user accounts using the standard RFC 6238 algorithm, compatible with Google Authenticator, Authy, and any standards-compliant authenticator app.

- The TOTP secret is stored encrypted server-side and never returned to the client after initial setup
- MFA verification is a separate session state (`mfa_pending`) — the full authenticated session is only established after the code is validated
- **Backup codes**: Eight single-use recovery codes are generated at setup time, hashed with `password_hash` (bcrypt), and stored as hashes only. Each code is marked as used after consumption
- Failed MFA attempts follow the same rate-limiting path as login attempts (see below)

---

### Brute-Force & Rate Limiting

All authentication endpoints are rate-limited per IP address using a database-backed token-bucket implementation in `Security::checkRateLimit()`:

- **Login**: 5 failed attempts per 5-minute sliding window → 15-minute lockout
- **API token endpoint**: same policy, independently tracked
- **API endpoints**: 60 requests per minute per IP (separate counter)
- Rate limit state is stored in the `rate_limits` table; `Security::resetRateLimit()` clears the counter on successful authentication

On lockout, the response is deliberately generic — the error message does not distinguish between "account not found", "wrong password", and "locked out" to prevent user enumeration.

---

### CSRF Protection

Every state-changing request (all HTTP POST handlers, including logout) requires a valid CSRF token. The implementation in `Security.php`:

- Tokens are generated with `random_bytes(32)` and stored in the session
- Tokens have a 2-hour expiry and are rotated after each successful validation
- Comparison uses `hash_equals()` — constant-time string comparison that prevents timing attacks
- All forms include a `<?= Security::csrfField() ?>` hidden input; all POST controllers call `Security::validateCsrf()` as the first operation before any data access

API endpoints are authenticated via API key or JWT header rather than cookies, so CSRF does not apply to the API surface.

---

### SQL Injection Prevention

The application uses **PDO with parameterized prepared statements exclusively**. The `Database` class (`src/Database.php`) exposes only four query methods — `query()`, `fetchOne()`, `fetchAll()`, and `insert()` — all of which accept SQL strings with `?` placeholders and a separate parameter array. User input is never interpolated directly into a SQL string anywhere in the codebase.

The database connection uses `search_path=aegis`, isolating all application tables in a dedicated PostgreSQL schema and preventing accidental access to `public` schema objects.

---

### Cross-Site Scripting (XSS) Prevention

**Output encoding**
All user-supplied values rendered into HTML views pass through `Security::h()`, which applies `htmlspecialchars()` with `ENT_QUOTES | ENT_HTML5` encoding. This is enforced by convention throughout every template, converting `<`, `>`, `"`, `'`, and `&` into their safe HTML entities.

**Rich HTML content sanitization**
Policy documents and similar fields that intentionally store formatted HTML are sanitized using `Security::sanitizeHtml()` — a server-side DOMDocument-based sanitizer that:
- Removes the entire subtree of dangerous tags: `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<form>`, `<link>`, `<meta>`, `<base>`, and others
- Strips all event-handler attributes (`onclick`, `onload`, `onerror`, `onmouseover`, etc.) from every element
- Strips `javascript:` and `data:text/` URI schemes from `href` and `src` attributes

Sanitization is applied at both write time (in the controller before the value reaches the database) and read time (in the view before the value reaches the browser), providing defense in depth.

---

### Content Security Policy (CSP)

A `Content-Security-Policy` header is set on every response by `Security::setSecurityHeaders()`, called from the front controller before any output. The policy:

```
default-src 'self';
script-src 'self' 'nonce-{per-request-nonce}';
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net;
font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net;
img-src 'self' data: blob:;
connect-src 'self';
frame-ancestors 'none';
base-uri 'self';
form-action 'self';
```

Key points:
- A cryptographically random **per-request nonce** (`random_bytes(18)` → base64) is generated once per request and injected into every `<script nonce="...">` tag and the CSP header simultaneously. Nonce values are unpredictable and unreusable
- `frame-ancestors 'none'` prevents the application being embedded in iframes on other origins (clickjacking defence, complementing the `X-Frame-Options: DENY` header)
- `form-action 'self'` prevents forms from being submitted to external origins
- `base-uri 'self'` prevents `<base>` tag injection from redirecting relative URLs

---

### HTTP Security Headers

The following headers are set on every response:

| Header | Value | Purpose |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | Forces HTTPS for one year; eligible for browser preload lists |
| `X-Frame-Options` | `DENY` | Prevents all iframe embedding (clickjacking defence) |
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing of responses |
| `Content-Security-Policy` | per-request nonce (see `Security.php`) | Primary XSS defence; restricts script/style/connect origins |
| `X-XSS-Protection` | `0` | Disables the deprecated legacy XSS filter (OWASP/CISA guidance); CSP above is the real defence |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Limits referrer leakage on cross-origin navigation |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=()` | Explicitly disables sensitive browser APIs |
| `Cross-Origin-Opener-Policy` | `same-origin` | Prevents cross-origin window references |
| `Cross-Origin-Resource-Policy` | `same-origin` | Prevents cross-origin resource loading |
| `X-Powered-By` | *(removed)* | Suppresses PHP version disclosure |

HSTS is set both in `.htaccess` (via `mod_headers`) and in PHP (via `Security::setSecurityHeaders()`), so it is present regardless of which layer handles the response first.

---

### File Upload Security

Evidence file uploads are handled by `EvidenceController` with the following controls:

- **Extension allowlist**: only the extensions configured in the admin settings (default: `pdf`, `doc`, `docx`, `xls`, `xlsx`, `png`, `jpg`, `jpeg`, `gif`, `txt`, `csv`, `zip`) are accepted. The check is performed on the server-side extension after `pathinfo()`, not on the client-supplied `Content-Type` header
- **Size limit**: configurable maximum (default: 20 MB); enforced before the file is moved from the temp directory
- **Randomised filenames**: uploaded files are stored using `bin2hex(random_bytes(16))` as the filename. The original filename is preserved in the database for display but never used as a filesystem path
- **SHA-256 integrity hash**: a hash of the stored file is computed immediately after upload and recorded in `evidence_files.file_hash`, enabling future integrity verification
- **No web-accessible storage**: the `/uploads/` directory is blocked at two layers — the main `.htaccess` denies HTTP requests matching `^(uploads)(/|$)`, and a dedicated `/uploads/.htaccess` denies all access with `Deny from all`, disables `ExecCGI`, and explicitly blocks PHP execution via `php_flag engine off`
- **Access control on download**: the download endpoint verifies that the requesting user has read permission on the owning entity (risk, control, audit, etc.) before serving the file via PHP, preventing direct-object-reference bypasses

---

### Open Redirect Prevention

The post-login redirect destination is taken from the session (never directly from a query parameter on the login form) and validated against a strict same-origin regex pattern before the `Location` header is set:

```php
if (!preg_match('#^/[a-zA-Z0-9/_?=&%.@-]*$#', $redirect)) {
    $redirect = '/';
}
```

This pattern requires the path to start with `/`, permits only safe URL characters, and rejects any value containing `://`, `//`, encoded sequences, or other characters that could construct an off-site redirect. The same validation is applied in the MFA verification flow, the MFA backup-code flow, and the `redirectBack()` helper used by evidence uploads.

---

### API Security

The REST API (`/api/`) supports two authentication methods:

**API keys**
Keys are generated with `random_bytes(32)`, displayed once at creation, and stored only as a SHA-256 hash in the `api_keys` table — the raw key is never persisted. Verification computes the hash of the submitted key and compares it to the stored hash (constant-time via PDO parameter binding).

**JWT**
Tokens are issued as HS256 JWTs signed with `JWT_SECRET` (a long random value from the environment). The `exp` claim is mandatory and enforced on every verification. Tokens expire after 24 hours and cannot be renewed — clients must re-authenticate.

**CORS**
The `Origin` header is validated against `APP_URL` on every API request. Requests from unlisted origins receive a 403 response before any handler executes. No wildcard (`*`) origin is permitted.

**Rate limiting**
API requests are rate-limited to 60 per minute per IP address using the same database-backed mechanism as login rate limiting. The `/api/auth/token` endpoint has its own independent counter.

---

### Role-Based Access Control (RBAC)

Every controller method calls one of `Auth::requireAuth()`, `Auth::requireAdmin()`, or `Auth::requirePermission($module)` as its first statement. The permission model has two layers:

1. **Role defaults** — five built-in roles (`admin`, `manager`, `auditor`, `analyst`, `viewer`) each carry a predefined set of module read/write/edit grants
2. **Per-user overrides** — the `user_permissions` table allows individual grants to extend or restrict the role default for a specific user and module combination

Permission checks are performed server-side on every request. There is no client-side permission state that could be tampered with.

---

### Audit Logging

Every significant user action is recorded in the `activity_log` table via `Auth::log()`. Log entries include the authenticated user ID, action type, entity type and ID, IP address, user agent, a JSON snapshot of changed fields, and a **SHA-256 hash chain** that links each entry to the previous one:

```
log_hash = SHA-256( prev_hash | user_id | action | entity_type | entity_id | changes | ip )
```

The hash chain makes retroactive tampering detectable — altering or deleting any row breaks the chain for all subsequent entries. The chain is anchored at a `genesis` string for the first entry.

---

### Sensitive Directory Protection

The `.htaccess` front controller blocks direct HTTP access to all sensitive application directories:

```apache
RewriteRule ^(config|src|database|scripts|uploads)(/|$) - [F,L]
RewriteRule ^\.env - [F,L]
RewriteRule ^\.git - [F,L]
RewriteRule ^install\.php$ - [F,L]
```

The `uploads/` directory additionally carries its own `.htaccess` with `Deny from all`, `Options -Indexes -ExecCGI`, and `php_flag engine off` to prevent PHP execution even if the rewrite rule were somehow bypassed.

The `Options -Indexes` directive is set globally, preventing directory listing across the entire application.

---

### Input Validation & Output Encoding Summary

| Source | Treatment |
|---|---|
| `$_POST` plain text fields | `Security::sanitizeInput()` — `strip_tags()` + `trim()` |
| `$_POST` rich HTML fields (policy content) | `Security::sanitizeHtml()` — DOMDocument tag/attribute allowlist |
| `$_GET` numeric IDs | Cast to `(int)` immediately; negative values rejected by query constraints |
| `$_GET` string filters | `Security::sanitizeInput()` then matched against explicit allowlists |
| Database values rendered in HTML | `Security::h()` — `htmlspecialchars(ENT_QUOTES\|ENT_HTML5)` |
| Database values rendered in JSON | `json_encode()` — encodes all special characters by default |
| CSV/XLSX exports | Leading `=`, `+`, `-`, `@` characters prefixed with `'` to prevent spreadsheet formula injection |
| File upload filenames | `basename()` + `pathinfo()` extension extracted; stored filename replaced with random hex |

---

## Deployment on Render

### Prerequisites

- A [Render.com](https://render.com) account
- A fork or clone of this repository pushed to GitHub

### Steps

1. **Create a new Web Service** on Render, connected to your GitHub repository. Select **Docker** as the environment — Render will use the `Dockerfile` automatically.

2. **Create a PostgreSQL database** on Render (free tier). Copy the **Internal Database URL** from the database dashboard.

3. **Set environment variables** on the web service:

   | Variable | Value |
   |---|---|
   | `DATABASE_URL` | Internal Database URL from step 2 |
   | `JWT_SECRET` | A long random string (Render can auto-generate) |
   | `APP_ENV` | `production` |
   | `APP_NAME` | Your platform name (e.g., `AEGIS`) |
   | `APP_URL` | The Render-assigned public URL (e.g., `https://aegis.onrender.com`) |
   | `ADMIN_EMAIL` | Email address for the initial admin account |
   | `ADMIN_PASSWORD` | Password for the initial admin account |

4. **Deploy.** Render builds the Docker image and runs `scripts/startup.sh` as the container command, which:
   - Executes `php install.php` (idempotent — safe to re-run on redeploy; creates the schema on first run)
   - Starts Apache via `apache2-foreground`

5. **Access the application** at your Render URL. Log in with the `ADMIN_EMAIL` / `ADMIN_PASSWORD` credentials set in step 3.

> **Note:** `install.php` is blocked by `.htaccess` in production and cannot be accessed via HTTP after deployment. It is only executed as a subprocess by the startup script.

---

## Local Development

### Prerequisites

- Docker and Docker Compose installed

### Quick Start

```bash
# 1. Clone and enter the aegis directory
git clone <your-repo-url>
cd aegis

# 2. Copy and configure the environment file
cp .env.example .env
# Edit .env: set DB_HOST, DB_USER, DB_PASS, DB_NAME, JWT_SECRET, APP_URL, ADMIN_EMAIL, ADMIN_PASSWORD

# 3. Start the stack (app on :8080, postgres on :5432)
docker compose up -d

# 4. Run the installer once to create the schema and seed data
docker compose exec app php install.php

# 5. Open in your browser
open http://localhost:8080
```

### Notes

- `docker compose up -d` starts two containers: the PHP/Apache app and a PostgreSQL instance.
- `install.php` is idempotent — re-running it is safe and will not destroy existing data.
- To reset the database entirely, bring down the stack with `docker compose down -v` (drops the volume) and re-run `install.php`.
- No `npm install`, `composer install`, or build step is required. The application runs directly from source.

---

## User Roles

| Role | Description | Default Permissions |
|---|---|---|
| `admin` | Full platform access | All modules, all actions, admin panel |
| `manager` | Operational management | All modules: read, write, edit |
| `auditor` | Audit-focused access | Compliance: read; Audit: read/write/edit; Policy: read; Risk: read |
| `analyst` | Risk-focused access | Compliance: read; Audit: read; Policy: read; Risk: read/write/edit |
| `viewer` | Read-only across all modules | All modules: read only |

Explicit per-user, per-module permission grants stored in the `user_permissions` table **extend or override** these role defaults. The permission matrix editor is available in the admin panel at `/admin/permissions`.

---

## REST API Quick Reference

All API endpoints are prefixed with `/api/`. Authenticate using either:
- `X-API-Key: <key>` header (API key created in the admin panel)
- `Authorization: Bearer <token>` header (HS256 JWT)

Rate limit: **60 requests per minute** per IP.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/risks` | List all risks in the register |
| `POST` | `/api/risks` | Create a new risk |
| `GET` | `/api/risks/{id}` | Get a single risk by ID |
| `PUT` | `/api/risks/{id}` | Update a risk |
| `DELETE` | `/api/risks/{id}` | Delete a risk |
| `GET` | `/api/compliance/packages` | List all compliance packages |
| `GET` | `/api/compliance/packages/{id}` | Get a compliance package and its objectives |
| `GET` | `/api/compliance/objectives/{id}` | Get a single control objective |
| `PUT` | `/api/compliance/objectives/{id}` | Update control implementation status/evidence |
| `GET` | `/api/audits` | List all audits |
| `POST` | `/api/audits` | Create a new audit |
| `GET` | `/api/audits/{id}` | Get audit detail with items |
| `PUT` | `/api/audits/{id}` | Update an audit |
| `GET` | `/api/policies` | List all policies |
| `POST` | `/api/policies` | Create a new policy |
| `GET` | `/api/policies/{id}` | Get a policy with version history |
| `PUT` | `/api/policies/{id}` | Update a policy |
| `GET` | `/api/metrics` | Get GRC platform metrics summary |
| `POST` | `/api/auth/token` | Exchange credentials for a JWT |

> Full request/response schemas and example payloads are documented in the in-app documentation module at `/docs`.

---

## Limitations

The following are known constraints and trade-offs in the current release.

### Infrastructure & Deployment

| Limitation | Detail |
|---|---|
| **Ephemeral file storage** | Uploaded evidence files are stored on the container's local filesystem. On Render's free tier (or any ephemeral container), a redeploy or restart wipes all uploads unless a persistent disk is attached and `UPLOAD_PATH` is pointed to the mounted volume |
| **No horizontal scaling** | PHP file-based sessions and local file storage mean the application cannot run behind a load balancer with multiple replicas without additional session-sharing infrastructure |
| **Render free-tier cold starts** | On Render's free plan the service spins down after 15 minutes of inactivity. The first request after a cold start can take 30–60 seconds while the container restarts |
| **PostgreSQL only** | The database layer uses PostgreSQL-specific SQL (schemas, `SERIAL`, `ON CONFLICT`). MySQL, MariaDB, and SQLite are not supported |

### Features & Functionality

| Limitation | Detail |
|---|---|
| **No compliance frameworks included** | The platform ships with no pre-loaded standards. All compliance packages must be imported via JSON, CSV, or Excel upload, or created manually |
| **No background job runner** | There is no built-in cron or queue system. Scheduled features (metric snapshots, due-date reminders, SLA alerts) rely on web-request triggers or an external cron call; missed triggers are not retried |
| **SMTP required for email** | Email notifications, review reminders, and attestation campaigns only function when a working SMTP server is configured in admin settings. Failed sends are silently dropped — there is no queue or retry mechanism |
| **SAML2 SSO not implemented** | **OIDC / OAuth2 SSO is fully live** — `SSOController::login`/`callback` + `src/SSO.php` (discovery, authorization URL, state validation, code exchange, JIT user provisioning with role mapping, MFA hand-off, open-redirect-guarded post-login redirect). Only **SAML2** specifically is not implemented; it is scoped for a future phase |
| **Single-tenant only** | All data lives in one PostgreSQL schema. There is no multi-organisation mode; separate deployments are required for data isolation between organisations |
| **AI Advisor requires an API key** | The AI-assisted gap analysis and risk advisor features require an Anthropic (Claude) API key configured in admin settings. Without it those features are inactive |

### Export & Import

| Limitation | Detail |
|---|---|
| **XLSX uses Excel 2003 XML format** | Exports use SpreadsheetML (`.xls`) rather than modern OOXML (`.xlsx`), avoiding a ZipArchive dependency. Modern Excel features (tables, charts, rich formatting) are not available |
| **PDF import quality varies** | The PDF import path calls the `pdftotext` binary (poppler-utils). Parsing quality depends on the PDF's embedded text layer; scanned PDFs without OCR will produce empty or incomplete extractions |

### Performance & Scalability

| Limitation | Detail |
|---|---|
| **Rate limiting is database-backed** | API rate limit counters are stored in the `rate_limits` PostgreSQL table rather than an in-memory store (Redis, Memcached). Under sustained high request volume this can create database contention |
| **No query caching** | All data reads go directly to PostgreSQL. Dashboards with many aggregations (compliance %, risk counts) re-query on every page load |

---

## Implementation Roadmap

This section documents the full development history of AEGIS — what was built, what was fixed, and what is planned next.

---

### Phase 1 — Core Platform

The initial build established the foundational architecture, data model, and all primary GRC modules.

#### Delivered

| Area | What was built |
|---|---|
| **Front controller + routing** | `index.php` with a static route table and a regex dynamic route table; spl_autoload for controllers and src classes |
| **Authentication** | Session-based login with Argon2ID password hashing, session fixation prevention, 60-minute inactivity timeout, and secure logout |
| **Multi-Factor Authentication** | TOTP (RFC 6238) setup flow, verification form, single-use backup codes (bcrypt-hashed), and `mfa_pending` session state |
| **RBAC** | Five built-in roles with per-user per-module override grants; `user_permissions` table; `Auth::requirePermission()` enforced in every controller |
| **Compliance Management** | Compliance packages linked to standards; two-level domain/control tree (`compliance_objectives`); `control_implementations` with status, evidence, notes, assignee, due date, and reviewer |
| **Risk Register** | Risk CRUD with likelihood × impact scoring; configurable 5×5 risk matrix with editable labels, thresholds, and cell colors; risk treatments; BowTie diagram view |
| **Audit Workflows** | Audit create/view/complete; checklist items mapped to compliance objectives; audit schedules; per-item scoring |
| **Policy Lifecycle** | Policy CRUD; draft → review → approved → published → retired status flow; version snapshots; control mappings; review scheduling |
| **GRC Metrics** | KPI summary cards; Chart.js charts (compliance %, risk trends, audit scores, policy lifecycle); scheduled metric snapshots |
| **Vendor Management** | Vendor register with risk tier, data access, contract dates; vendor assessments; portal link generation for external responses |
| **Incident Management** | Incident CRUD; severity, SLA tracking; update timeline; acknowledge and close flows |
| **REST API v1** | Full CRUD API for risks, compliance, audits, policies, and metrics; API key + JWT authentication; 60 req/min rate limiting; CORS origin enforcement |
| **Export Engine** | CSV/XLSX per-module exports; full-platform ZIP bundle; formula-injection-safe cell encoding |
| **Admin Panel** | User management; risk matrix configurator; workflow builder; alert configurations; API key management; per-user permission matrix; module visibility settings |
| **Security layer** | CSP with per-request nonce; HSTS; CSRF tokens (CSPRNG, 2-hour expiry, constant-time comparison); SQL injection prevention (PDO parameterized statements throughout); XSS encoding via `Security::h()`; DOMDocument HTML sanitizer for rich content; open-redirect prevention; audit log with SHA-256 hash chain |
| **Infrastructure** | Docker (`php:8.2-apache`); `render.yaml` Render.com manifest; `scripts/startup.sh` idempotent startup hook; PostgreSQL `aegis` schema isolation |
| **Additional Modules** | Issue tracker; Document management with version uploads; Change management with CAB approvals; Business Continuity Plans; Asset register with risk linking; Threat register; Key Risk Indicators (KRIs); Risk treatment plans; Risk exceptions; Risk reviews; Risk acceptance; Approval workflows; Questionnaires; Calendar; Playbooks; Tags system; Evidence file upload (randomised filenames, SHA-256 integrity, PHP-gated download); SSO (SAML/OIDC) settings; Scheduled reports; Email templates; Webhook configurations (11 providers); Search; Awareness training; Account reviews (access certification); Privacy assessments; System Security Plans (7-tab, JSONB inventories); POA&M; CUI inventory; SPRS; ODP; RACI matrix; Automation rules engine; Audit findings; Custom dashboards; GRC projects; Cross-framework control mapping |

---

### Phase 2 — Architecture Hardening & Bug Resolution

A comprehensive audit identified and resolved structural issues before feature work continued.

#### Delivered

| Fix | Detail |
|---|---|
| **Double layout.php inclusion** | All controller methods that wrapped views in `ob_start()`/`require layout.php` were rewritten to use the self-contained view pattern (`ob_start()` in the view, `$content = ob_get_clean()`, `require layout.php` at the end of the view) |
| **500 errors on missing schema columns** | Runtime migration block in `index.php` added for each column gap discovered; migrations use `information_schema` checks so they are safe no-ops after first run |
| **CSP blocking inline JavaScript** | Every inline `<script>` tag across all views had `nonce="<?= Security::nonce() ?>"` added; CSP header updated to use the nonce-based policy |
| **Eval in risk scoring sliders** | Risk matrix view replaced `eval()` with direct property access; eliminated the CSP `unsafe-eval` exception |
| **BowTie form action URLs** | BowTie controller routes were registered in the dynamic route table; broken form actions corrected |
| **Add Domain modal conflict** | `closeModal()` function name conflicted with a global helper; compliance package view renamed its function to `pkgCloseModal()` throughout |
| **Mobile sidebar double-fire** | Hamburger button event listener was registered inside `DOMContentLoaded` and also via inline `onclick`, causing the sidebar to open and immediately close on mobile; reduced to a single `addEventListener` |
| **File upload drop zone click** | File drop zones used `onclick` on a `<div>` that did not receive click events on iOS; replaced with `<label for="...">` wrapping the hidden `<input type="file">` |
| **Compliance package delete cascade** | `compliance_domains` table referenced in delete query did not exist in the schema; corrected to use `compliance_objectives` with the `package_id` FK |
| **Compliance Clear All permission** | Action was incorrectly gated on `admin` role; changed to `compliance.write` to match all other write operations in the module |
| **User edit inline JSON** | Inline `onclick` passing a PHP-encoded JSON object caused XSS-filter false positives and broke on special characters in names; replaced with `data-*` attributes |
| **KRI unit column length** | `kris.unit` was `VARCHAR(10)`, truncating common unit strings; migrated to `VARCHAR(50)` |

---

### Phase 3 — Compliance Import Expansion

Extended the compliance import system to support multiple file formats and manual entry.

#### Delivered

| Feature | Detail |
|---|---|
| **CSV import** | Upload a `.csv` file with headers `package_name`, `package_version`, `package_description`, `domain_code`, `domain_title`, `control_code`, `control_title`, `control_description`; auto-groups rows by domain; creates a new package on import |
| **Excel import (.xlsx)** | Upload an `.xlsx` file; server-side parser reads `xl/sharedStrings.xml` and `xl/worksheets/sheet1.xml` via ZipArchive; converts to CSV format and reuses the CSV processor |
| **PDF import** | Extracts text via `pdftotext` (poppler-utils); regex parser auto-detects section and control codes from the extracted text; creates domains and controls automatically; falls back to a single placeholder control if the PDF cannot be parsed |
| **JSON import** | Existing JSON format; supports both 2-level (domains → controls) and flat (legacy) structures; can carry an embedded `standard` definition that is upserted into the `standards` table |
| **Single-control manual entry** | "Single Control" tab on the import page; form with package selector, domain code/title, control code/title, description; finds or creates the domain automatically |
| **CSV template download** | `/compliance/csv-template` endpoint; serves a pre-filled `.csv` with correct headers and three example rows |
| **Excel template download (SpreadsheetML)** | `/compliance/excel-template` endpoint; generates SpreadsheetML (Excel 2003 XML format) — no ZipArchive or other PHP extensions required; downloads as `.xls` |
| **Module visibility admin** | Admin panel controls which modules appear in the sidebar; stored in `settings` table; resolved discoverability issues where new modules were hidden by default |
| **Multi-select package delete** | Compliance index page gained checkboxes and a "Delete Selected" action with a count-specific confirmation label |
| **Redesigned risk matrix** | Per-cell editable treatment configuration; cells show risk level, score range, and recommended treatment; configurable via admin panel |

---

### Phase 4 — UX Polish & Mobile Fixes

Quality-of-life improvements focused on mobile usability and data integrity.

#### Delivered

| Feature / Fix | Detail |
|---|---|
| **Threat Register sidebar icon** | `bi-biohazard` does not exist in Bootstrap Icons 1.11.3; changed to `bi-shield-exclamation` |
| **Risk appetite deduplication** | Runtime migration in `index.php` deletes duplicate rows (keeping the lowest `id` per category) then seeds six default categories (Financial, Operational, Strategic, Compliance, Technology, Reputational) if the table is empty |
| **Backup code button — mobile** | The TOTP verify page auto-focused the code input on load, which raised the virtual keyboard on iOS/Android and pushed the "Use Backup Code" button off-screen; auto-focus now only runs on non-touch devices (`window.matchMedia('(hover: none)')`) |
| **Backup verify silent failure** | `mfaBackupVerify()` stored its error in `$_SESSION['flash_error']`; the MFA verify view reads `$_SESSION['mfa_error']`; the session key was corrected and the failure redirect now includes `?mode=backup` so the backup section auto-opens |
| **Sidebar scroll persistence** | On each page navigation the sidebar `overflow-y: auto` container scrolled back to the top; the current scroll position is now saved to `sessionStorage` on `beforeunload` and restored on `DOMContentLoaded` |

---

### Phase 5 — Accordion Navigation & Compliance Bulk Operations

Major UX feature additions: collapsible sidebar nav, bulk compliance actions, and extended import fields.

#### Delivered

| Feature | Detail |
|---|---|
| **Collapsible accordion sidebar** | The flat sidebar nav was reorganised into eight labelled accordion sections: Overview, Compliance, Operations, Risk, Analytics, Resources, Administration, Account. Each section has a chevron that animates on open/close. State (which sections are open or closed) is persisted in `sessionStorage` so the layout is preserved across page navigations. On first load, the section containing the active page is opened automatically. Transitions are disabled during initial paint to prevent a "flash of collapsed content" |
| **Accordion mobile compatibility** | Section headers have `min-height: 44px` (Apple HIG touch target), `-webkit-tap-highlight-color: transparent`, and `touch-action: manipulation` to prevent the 300 ms tap delay and eliminate the blue flash on iOS. Tested on iOS Safari and Android Chrome |
| **Compliance bulk status update** | Each control row in the compliance package view gained a checkbox; each domain header gained a select-all checkbox with indeterminate state. A sticky floating action bar (indigo, `position: sticky; top: 0`) appears when any controls are selected showing the count and five one-click status buttons (Compliant, Partial, Non-Compliant, Not Started, N/A). Clicking a button POSTs to `/compliance/{id}/bulk-status` via `fetch`; the response updates the status icons inline without a page reload; CSRF token is refreshed from `new_csrf` in the JSON response for consecutive operations |
| **`control_additional_information` column** | New optional column added to CSV/Excel import format and the single-control manual entry form; `additional_information TEXT` column added to `compliance_objectives` via runtime migration; stored and displayed on the individual control assess page |
| **Remove built-in standards from import** | The "Built-in Standards" card was removed from the import page; standards are now only managed through the admin panel or embedded in JSON imports |
| **Excel template fix** | The Excel template download was crashing with "Class ZipArchive not found" on Render because the PHP ZipArchive extension is not installed. The `downloadExcelTemplate()` method was rewritten to generate SpreadsheetML (Excel 2003 XML) format which requires no PHP extensions, and the file is now served as `.xls` with `application/vnd.ms-excel` content type |
| **Bulk Assess modal** | The compliance package bulk action bar gained a "Bulk Assess" button (indigo). Clicking it opens a modal that mirrors the individual control assess page: radio buttons for Implementation Status (styled with colour and description), Assigned To user dropdown, Due Date picker, Implementation Notes textarea, and Evidence textarea. Submitting POSTs to `/compliance/{id}/bulk-assess` via `fetch`; all selected controls are upserted with every field simultaneously; the control row status icons and assignee names update inline; a purple toast confirms the count |

---

### Phase 6 — Advanced Modules & Integrations (Delivered)

| Area | What was built |
|---|---|
| **System Security Plans (SSP)** | Full 7-tab SSP authoring (Overview, Approval, Organization, Boundary, Environment, Inventory, Compliance); presentation modes (Standard, Military, Corporate, Air Force, DoD); versioning with revision numbers and authorization signatures; JSONB-backed hardware, software, network, server, data inventories; extended company/org fields (DUNS, certifications, environment detail) via migrations 013, 018, 019 |
| **POA&M** | Plans of Action and Milestones with numbered POA&M IDs, milestone tracking, and linkage to compliance packages |
| **Audit Findings** | External audit finding tracking with severity (critical/high/medium/low/info), status lifecycle (open/in_progress/resolved/risk_accepted/closed), source classification (external_audit/pentest/certification/assessment/regulatory), finding update timeline, compliance objective and package linkage |
| **Automation Rules Engine** | Configurable rules with triggers and action chains; execution history per rule; cooldown period enforcement; separate from the admin workflow builder |
| **Custom Dashboards** | User-configurable dashboard widgets stored per-user |
| **RACI Matrix** | Responsibility assignment (Responsible/Accountable/Consulted/Informed) per compliance package and control objective |
| **GRC Projects** | Project tracking with code, tasks, milestones, and polymorphic entity links (risk/policy/audit/etc.) |
| **Awareness Training** | Training programs with content types (document/video/link); per-user assignment and completion tracking |
| **Account Reviews** | Periodic access certification campaigns with per-item certify/revoke decisions |
| **Privacy Management** | Privacy impact assessment records linked to data processing activities |
| **CUI Inventory** | Controlled Unclassified Information tracking by category |
| **SPRS** | Supplier Performance Risk System score management |
| **ODP** | Organizational-Defined Parameters management for NIST control tailoring |
| **Cross-Framework Mapping** | Map controls across multiple compliance standards via `control_framework_mappings` |
| **Webhook Integration** | 11-provider delivery engine (Slack, Teams, Discord, Jira, PagerDuty, ServiceNow, Google Chat, OpsGenie, Datadog, Splunk HEC, Generic HTTP) with per-endpoint delivery log |
| **Dark Mode** | Full dark/light theme toggle persisted via `localStorage`; implemented with CSS custom properties across all views and modules |

---

### Planned — Future Work

| Area | Description |
|---|---|
| **AI-Assisted Gap Analysis** | Claude API integration to analyse a compliance package, identify unaddressed control gaps relative to existing policies and risks, and generate a prioritised remediation narrative |
| **Control Testing Dashboard** | Dedicated dashboard aggregating all control test results with pass/fail trend charts, overdue retests, and effectiveness heatmap by domain |
| **Evidence Attachments on Controls** | File upload directly from the control assess page and the bulk assess modal, linked to `control_implementations` rather than the generic evidence store |
| **Compliance Scorecard PDF Export** | One-click PDF export of the per-package scorecard view (domain breakdown, compliance percentages, non-compliant control list) |
| **Risk Roadmap Gantt** | Gantt-style visualisation of open treatment plans with drag-to-reschedule and owner swimlanes |
| **Automated Evidence Collection** | Webhook receiver accepting structured evidence payloads from CI/CD pipelines and security tools, auto-attached to mapped controls |
| **Attestation Campaigns v2** | Bulk-assign policy attestation campaigns by role or department; track completion percentage; reminder emails via SMTP |
| **SAML2 SSO** | OIDC/OAuth2 SSO is already live; add a SAML2 path alongside it (IdP metadata exchange, SP-initiated login, assertion validation, role attribute mapping) for IdPs that don't speak OIDC |
| **Multi-tenant organisations** | Namespace all tables under an `organisation_id` for data isolation between tenants on a single AEGIS instance |
| **Mobile-first view layer** | Responsive card-based views for compliance and risk modules optimised for small screens; swipe gestures for quick status updates |

---



This project is licensed under the [MIT License](https://opensource.org/licenses/MIT).

```
MIT License

Copyright (c) 2025 AEGIS GRC

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
