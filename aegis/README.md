# AEGIS GRC Platform

![PHP 8.2](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white)
![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)

AEGIS is a self-hosted Governance, Risk, and Compliance (GRC) platform built with PHP 8.2 and PostgreSQL. It consolidates compliance tracking, risk management, audit workflows, and policy lifecycle management into a single cohesive application — with no external framework dependencies, no build pipeline, and a single Docker container deployment model. AEGIS ships with a pre-seeded CMMC 2.0 Level 2 compliance package and supports importing custom standards via JSON.

---

## Features

- **Compliance Management** — Track compliance packages against standards (CMMC, custom), manage control implementations, attach evidence, and link policies to objectives
- **Risk Register** — Create and score risks using a configurable likelihood × impact matrix, assign owners, record treatments, and visualize exposure on a 5×5 heatmap
- **Audit Workflows** — Schedule and conduct audits against compliance packages, manage checklist items, and record scores through to completion
- **Policy Lifecycle** — Draft, version, approve, publish, and review policies with full mapping to compliance controls
- **GRC Metrics Dashboard** — Compliance percentage, risk trends, audit scores, and policy lifecycle status rendered as interactive Chart.js visualizations
- **REST API v1** — Full API with API-key and JWT authentication, rate limiting, and CORS origin enforcement
- **Role-Based Access Control** — Five built-in roles (admin, manager, auditor, analyst, viewer) with per-user per-module permission overrides
- **Export Engine** — Per-module CSV and XLSX exports plus a full-platform ZIP bundle; formula-injection safe
- **Admin Panel** — User management, API key management, alert configurations, workflow builder, risk matrix configurator, and permission matrix editor
- **Security-First Design** — Argon2ID hashing, CSRF protection, CSP/HSTS headers, brute-force lockout, PDO prepared statements throughout

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
│   ├── AdminController.php        # Admin: users, risk matrix, workflows, alerts, API keys,
│   │                              # per-user permissions
│   ├── AuditController.php        # Audits: list, create, view, update, complete, audit items
│   ├── AuthController.php         # Login form, login POST (open-redirect fix), logout
│   ├── ComplianceController.php   # Packages, objectives, control implementations, JSON import
│   ├── DashboardController.php    # Dashboard KPIs, due-items widget, mark-alert-read AJAX
│   ├── DocsController.php         # In-app documentation module
│   ├── ExportController.php       # CSV/XLSX/ZIP exports for 6 data modules
│   ├── MetricsController.php      # GRC metrics: compliance %, risks, audits, policies;
│   │                              # Chart.js data endpoints
│   ├── PolicyController.php       # Policies: CRUD, versioning, approve/publish, control mapping
│   └── RiskController.php         # Risk register: CRUD, likelihood×impact scoring, treatment,
│                                  # risk matrix
│
├── database/
│   ├── schema.sql                 # 18 CREATE TABLE statements in aegis schema + 13 indexes
│   └── seeds/
│       └── cmmc_l2.json           # CMMC 2.0 Level 2 seed data (domains, practices, objectives)
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
    ├── layout.php                 # Shell: sidebar nav, topbar, alert panel fly-out,
    │                              # Chart.js + app.js script loads
    ├── auth/
    │   └── login.php              # Login form
    ├── dashboard/
    │   └── index.php              # KPI cards, due-items widget (overdue/7d/30d/expired tabs),
    │                              # activity log
    ├── compliance/
    │   ├── index.php              # Package grid with per-package compliance progress bars
    │   ├── package.php            # Domain tree + control list with status filters
    │   ├── objective.php          # Single control detail: status form, evidence,
    │   │                          # policy mappings, audit findings
    │   └── import.php             # JSON upload form for importing custom standards
    ├── audit/
    │   ├── index.php              # Audit list with status badges
    │   ├── create.php             # Create audit form
    │   └── view.php               # Audit detail: item checklist, score, complete button
    ├── policy/
    │   ├── index.php              # Policy list with lifecycle status
    │   ├── create.php             # Create policy form with rich textarea
    │   └── view.php               # Policy detail: content, mappings, version history,
    │                              # approve/publish actions
    ├── risk/
    │   ├── index.php              # Risk register table with category + score badges
    │   ├── create.php             # Create risk form with scoring sliders
    │   ├── view.php               # Risk detail: treatment history
    │   └── matrix.php             # Interactive 5×5 risk heatmap (Chart.js scatter + CSS grid)
    ├── export/
    │   └── index.php              # Card grid: per-module CSV/XLSX download forms + ZIP export
    ├── metrics/
    │   └── index.php              # 4 KPI rings + 5 Chart.js charts (stacked bar, doughnut,
    │                              # horizontal bar, 2 line charts) + 2 data tables
    ├── docs/
    │   └── index.php              # In-app documentation (scrollspy sidebar + rich content)
    ├── admin/
    │   ├── index.php              # Admin overview: user count, API keys, activity log, settings
    │   ├── users.php              # User management CRUD table
    │   ├── risk_matrix.php        # Risk matrix configurator (labels, thresholds, colors)
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

policies ──< policy_mappings  ──> compliance_objectives
policies ──< policy_versions
policies ──< policy_reviews

risks ──< risk_treatments
risks ──> risk_categories
risks ──> users (owner_id, created_by)

users ──< api_keys
users ──< user_permissions  (module + permission grants)
users ──< alerts
users ──< activity_log
```

---

## Database Schema

All tables live in the `aegis` PostgreSQL schema (isolated from `public`). The schema is created by `install.php` using `database/schema.sql`.

| Table | Description |
|---|---|
| `users` | User accounts: email, hashed password, role, active flag |
| `api_keys` | API keys per user: SHA-256 hash of the key, name, last-used timestamp |
| `standards` | Compliance framework definitions (e.g., CMMC 2.0) |
| `compliance_packages` | Instances of a standard assigned for tracking within the org |
| `compliance_objectives` | Individual controls/practices within a package; tree structure via `parent_id` |
| `control_implementations` | One-to-one implementation record per objective: status, evidence, assignee, reviewer |
| `audits` | Audit records tied to a compliance package: title, scope, status, scheduled date |
| `audit_schedules` | Recurring audit schedule definitions |
| `audit_items` | Individual checklist items within an audit, mapped to compliance objectives |
| `policies` | Policy documents: title, content, owner, lifecycle status |
| `policy_versions` | Immutable version snapshots of policy content on each revision |
| `policy_mappings` | Many-to-many join between policies and compliance objectives |
| `policy_reviews` | Scheduled or completed review records for a policy |
| `risk_categories` | Taxonomy of risk categories (configurable) |
| `risks` | Risk register entries: title, description, likelihood, impact, owner, status |
| `risk_treatments` | Treatment actions (accept/mitigate/transfer/avoid) linked to a risk |
| `risk_matrix_config` | Admin-configurable labels, thresholds, and colors for the risk scoring matrix |
| `workflows` | Workflow definitions: trigger condition + ordered action list |
| `alerts` | Per-user alert notifications with read/unread state |
| `alert_configs` | Alert trigger configurations (thresholds, channels, recipients) |
| `settings` | Key-value store for application-level settings |
| `activity_log` | Audit trail of all user actions: user, action, resource type/id, IP, timestamp |
| `rate_limits` | Request count windows per IP for API rate limiting |
| `user_permissions` | Per-user, per-module explicit permission grants that extend or override role defaults |

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
script-src 'self' 'nonce-{per-request-nonce}' 'unsafe-inline';
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
| `X-XSS-Protection` | `1; mode=block` | Legacy browser XSS filter (complementary) |
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
   - Executes `php install.php` (idempotent — safe to re-run on redeploy; creates the schema and seeds CMMC 2.0 data on first run)
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
| **Additional Modules** | Issue tracker; Document management with version uploads; Change management; Business Continuity Plans; Asset register with risk linking; Threat register; Key Risk Indicators (KRIs); Risk treatment plans; Risk exceptions; Risk reviews; Risk acceptance; Approval workflows; Questionnaires; Calendar; Playbooks; Tags system; Evidence file upload (randomised filenames, SHA-256 integrity, PHP-gated download); SSO (SAML/OIDC) settings; Scheduled reports; Email templates; Webhook configurations; Search |

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

### Planned — Phase 6

The following items are scoped for future development.

| Area | Description |
|---|---|
| **AI-Assisted Gap Analysis** | Claude API integration to analyse a compliance package, identify unaddressed control gaps relative to existing policies and risks, and generate a prioritised remediation narrative |
| **Control Testing Dashboard** | Dedicated dashboard aggregating all control test results (`control_tests` table) with pass/fail trend charts, overdue retests, and effectiveness heatmap by domain |
| **Evidence Attachments on Controls** | File upload directly from the control assess page and the bulk assess modal, linked to `control_implementations` rather than the generic evidence store |
| **Compliance Scorecard PDF Export** | One-click PDF export of the per-package scorecard view (domain breakdown, compliance percentages, non-compliant control list) using a server-side HTML-to-PDF renderer |
| **Risk Roadmap Gantt** | Gantt-style visualisation of open treatment plans on the risk roadmap view, with drag-to-reschedule and owner swimlanes |
| **Automated Evidence Collection** | Webhook receiver that accepts structured evidence payloads from CI/CD pipelines, cloud platforms, and security tools and attaches them to mapped controls automatically |
| **Attestation Campaigns v2** | Bulk-assign policy attestation campaigns by role or department; track completion percentage; send reminder emails via the configured SMTP provider |
| **SSO / SAML2 live integration** | Complete the SAML2 authentication flow (`SSOController`) with IdP metadata exchange, SP-initiated login, and attribute mapping for role assignment |
| **Multi-tenant organisations** | Namespace all tables under an `organisation_id` to allow a single AEGIS instance to serve multiple independent tenants with data isolation |
| **Mobile-first view layer** | Responsive card-based views for the compliance package and risk register pages optimised for small screens; swipe gestures for quick status updates |

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
