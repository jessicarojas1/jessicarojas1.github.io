# CLAUDE.md — AEGIS GRC (project guidance)

Guidance for working in the **AEGIS GRC Platform** (`aegis/`). This inherits the
repo-root `CLAUDE.md` rules (security/UI/schema/branding standards) — those apply
here in full. This file adds AEGIS-specific stack, conventions, and locations.

## What AEGIS is

A self-hosted **Governance, Risk & Compliance** platform: compliance packages,
risk register, audits/findings, policy lifecycle, incidents, vendors, BCP, SSP,
POA&M, KRIs, and more. Server-rendered, multi-tenant (RLS), security-first.

## Stack (verify against code, don't assume)

- **PHP 8.2** target, runtime image **`php:8.3-apache`** — no framework, **no
  Composer**, no build step. All classes global, autoloaded from `src/` or
  `controllers/` (no namespaces).
- **PostgreSQL 16** via PDO (`pdo_pgsql`, `EMULATE_PREPARES=false`). Schema in the
  `aegis` schema; `search_path=aegis,public`.
- **Apache + mod_php** on `:8080`, non-root `www-data`. TLS terminated upstream.
- Single **Docker** container; deploys on **Render** (`render.yaml`), plus
  compose/K8s/AWS/Azure/air-gapped (see `docs/DEPLOYMENT.md` + `deployments/`).
- Health: `/healthz` (live), `/readyz` (ready), `/health` (JSON w/ DB latency).

## Where things live

| Thing | Path |
|---|---|
| Front controller (bootstrap, routes, dispatch, tenant binding) | `index.php` |
| Authoritative installer (schema + migrations + seed) | `install.php` |
| Controllers (one per feature) | `controllers/*Controller.php` |
| Views (server-rendered PHP) + error pages | `views/**`, `views/errors/{400,401,403,404,419,429,500}.php` |
| Core services | `src/*.php` (Auth, Security, Database, JWT, TOTP, SSO, Ssrf, Storage, Secrets, Kms, Mailer, Webhook, RiskScore, AIAdvisor, Branding, …) |
| Config (env → arrays) | `config/app.php`, `config/database.php` |
| Schema + migrations | `database/schema.sql`, `database/schema.full.sql`, `database/migrations/NNN_*.sql`, `database/roles.sql`, `database/tenancy/` |
| Cron/worker scripts | `scripts/*.php` (+ `scripts/startup.sh`) |
| Static analyzers / quality gates | `scripts/check_*.php`, `scripts/verify_*.php` |
| Tests | `tests/run.php`, `tests/*.php`, `tests/integration/` |
| API surface | `api/index.php`, `api/docs.php` (Swagger UI) |
| Docs (canonical) | `docs/ARCHITECTURE.md`, `docs/DEPLOYMENT.md`, `docs/SECURITY.md`, `docs/DISASTER_RECOVERY.md`, `docs/DATABASE.md`, `docs/DEPENDENCIES.md`, `docs/MODULES.md` |
| Deploy target guides | `deployments/*.md` |

## Conventions

- **Controllers** start each action with `Auth::requireAuth()` or
  `Auth::requirePermission('module.action')` (granular), then query via
  `Database::fetchOne/fetchAll/insert/update` and `require` a view. No base
  controller, no DI, no ORM.
- **Permissions** are `module.action` strings; role defaults in
  `Auth::$roleDefaults` + additive `user_permissions` grants; legacy coarse
  strings map via `$aliases`.
- **`Database::update()` auto-appends `updated_at = NOW()`** — never put
  `updated_at` in the data array.
- **Multitenancy**: writes stamped via `Database::useTenant()`; reads isolated by
  the `aegis.tenant_id` GUC + RLS (`Database::setTenant()`). Inert for
  single-tenant.
- **Config precedence**: env/`*_FILE`/KMS for bootstrap; **encrypted `settings`
  table** for SMTP/S3/AI/branding (not env vars).

## Security & UI rules (enforced — see root CLAUDE.md + `docs/SECURITY.md`)

- **CSP**: no inline event handlers (`onclick=`…); use `data-*` handled by app.js.
  Every `<script>` carries `nonce="<?= Security::nonce() ?>"`. No external CSP
  origins (all assets vendored + SRI-pinned).
- **XSS**: all output via `Security::h()`; `json_encode` in script context uses
  `JSON_HEX_TAG | JSON_HEX_AMP`.
- **CSRF**: every POST form has `Security::csrfField()`; every POST controller
  method calls `Security::validateCsrf()` (rotate-on-use).
- **SQLi**: parameterized queries only — no string concatenation of input.
- **Uploads**: MIME + extension allowlist + size + randomized stored filename
  (`Storage` denylist floor is always on).
- **Auth coverage**: every protected route enforces `Auth::require*`. Redirects
  validated against a strict path regex.
- **Secrets**: from env/`*_FILE`/KMS; never hardcode; never commit `.env`.
- **AI Advisor** is advisory-only, opt-in, kill-switchable, redacts before egress,
  and is audited — keep new AI features read-only.

## Build / test / deploy

```bash
# Quality gates (must pass — mirror CI):
php tests/run.php                  # unit suite
php scripts/verify_migrations.php  # every migration file registered & ordered
php scripts/check_ui.php           # CSP: no inline handlers, scripts carry nonce
php scripts/check_route_auth.php   # every public action enforces authz
php scripts/check_csrf.php         # every POST route validates CSRF
php scripts/check_csv_export.php   # formula-injection-safe CSV
php scripts/verify_audit_log.php   # audit hash-chain intact (needs DB + AUDIT_HMAC_KEY)

# Local run: docker-compose up  (needs DB_PASS + JWT_SECRET in .env), then
php install.php                    # with ADMIN_EMAIL + ADMIN_PASSWORD set

# Lint: php -l <file>
```

- **Migrations**: add `database/migrations/NNN_*.sql`, register it in the
  `$migrationFiles` list in `install.php`, and **update `database/schema.sql` /
  `schema.full.sql`** to reflect the combined schema (root rule #3).
- **Deploy**: push to `main` → Render builds `./Dockerfile` and runs
  `scripts/startup.sh` (`install.php` then Apache). Other targets in
  `docs/DEPLOYMENT.md` + `deployments/`.
- Run schema changes as the **owner**; the app runs as DML-only `aegis_app`
  (`database/roles.sql`).

## Standing rule — keep the doc set current

Whenever you change AEGIS's behavior, config, schema, deps, or deployment,
**update the doc set in the same change**:

- `docs/` (ARCHITECTURE, DEPLOYMENT, SECURITY, DISASTER_RECOVERY, DATABASE,
  DEPENDENCIES, MODULES) — the canonical, operator-grade references.
- `deployments/*.md` — target guides (local, single server, k8s, AWS±GovCloud,
  Azure±Gov, air-gapped).
- `README.md`, `OPEN_ITEMS.md` — keep quick-start, deps, and the open-items
  register honest and matching the code.
- `database/schema.sql` — must always reflect all migrations combined.

After any significant feature, run the security + UI audits per the root
`CLAUDE.md` roadmap, and fix all findings before marking a milestone complete.
</content>
