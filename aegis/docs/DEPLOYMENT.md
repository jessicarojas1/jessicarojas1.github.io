# AEGIS GRC — Deployment, CI/CD & Configuration Guide

This guide is written for an engineering team with **zero prior knowledge** of
AEGIS. It documents exactly how the app is built, shipped, configured, and
booted, based entirely on the code in the repository. Every claim cites the file
it came from. If a behaviour is absent from the code, this guide says so rather
than inventing it.

AEGIS is a **server-rendered PHP 8.2 MVC application** (no framework, no Composer
dependencies) backed by **PostgreSQL** (with Row-Level-Security multitenancy),
served by **Apache** inside a **Docker** image, and deployed on **Render** (with
alternate Azure Container Apps and Kubernetes-friendly hardened-image paths).

---

## Table of Contents

1. [Architecture at a Glance](#1-architecture-at-a-glance)
2. [The Build Process (Docker)](#2-the-build-process-docker)
3. [The Bootstrap / Install Flow](#3-the-bootstrap--install-flow)
4. [The Deploy Process (Render)](#4-the-deploy-process-render)
5. [Alternate Deploy: Azure Container Apps](#5-alternate-deploy-azure-container-apps)
6. [The CI/CD Pipeline](#6-the-cicd-pipeline)
7. [Environment Variable Reference](#7-environment-variable-reference)
8. [Secret Mounts (`*_FILE`) and KMS Envelope Encryption](#8-secret-mounts-_file-and-kms-envelope-encryption)
9. [The Least-Privilege Database Role](#9-the-least-privilege-database-role)
10. [Deploy-From-Scratch Runbook](#10-deploy-from-scratch-runbook)
11. [Troubleshooting](#11-troubleshooting)
12. [Deployment Models & Target Guides](#12-deployment-models--target-guides)
13. [The Background / Cron Worker Process](#13-the-background--cron-worker-process)
14. [AI Advisor & Ollama (Self-Hosted / Air-Gapped LLM)](#14-ai-advisor--ollama-self-hosted--air-gapped-llm)
15. [Production Readiness Checklist](#15-production-readiness-checklist)

---

## 1. Architecture at a Glance

| Layer | Technology | Source |
| --- | --- | --- |
| Web server | Apache 2 (`php:8.3-apache` base image) | `Dockerfile` |
| Language runtime | PHP 8.3 in the image; CI lints/tests target PHP 8.2 | `Dockerfile`, `.github/workflows/ci.yml` |
| Front controller | `index.php` (all requests rewrite to it via `.htaccess`) | `index.php` |
| Database | PostgreSQL 16 (CI), connected via PDO `pdo_pgsql` | `config/database.php` |
| Schema namespace | All objects live in the `aegis` schema; search path is `aegis,public` | `config/database.php`, `install.php` |
| Installer | `install.php` (authoritative — runs schema + migrations + seed) | `install.php` |
| Health endpoints | `/healthz` (liveness), `/readyz` (readiness) | `index.php:732-733` |

The PHP runtime reads **all** configuration from environment variables. There is
no compiled config — `config/app.php` and `config/database.php` simply project
`$_ENV` values into arrays at request time.

---

## 2. The Build Process (Docker)

There are **two** Dockerfiles. Both start from the same digest-pinned base
image `php:8.3-apache@sha256:954d6198...` and install the same PHP extensions.

### 2.1 `./Dockerfile` — the Render / default image

`render.yaml` points `dockerfilePath: ./Dockerfile`, so this is the image that
ships to production on Render.

What it does (`Dockerfile`):

1. **Installs system libs + PHP extensions** — `libpq-dev`, GD image libs,
   `poppler-utils` (PDF tooling), `curl`; then builds `pdo`, `pdo_pgsql`, `gd`,
   `opcache`; enables Apache `rewrite` and `headers` modules.
2. **Hardens `php.ini`** — copies `php.ini-production`, then sets
   `expose_php = Off`, `display_errors = Off`, `log_errors = On`, and turns on
   OPcache with `opcache.validate_timestamps=0` (code is immutable at runtime).
3. **Runs entirely as non-root.** Apache is reconfigured to:
   - `AllowOverride All` (so `.htaccess` rewrites work),
   - `Listen 8080` (an unprivileged port — no root needed to bind),
   - send `ErrorLog` to `/dev/stderr`, `TransferLog` to `/dev/stdout`,
   - put `PidFile` and `Mutex` under `/tmp`.
   The image then `USER www-data` and `EXPOSE 8080`.
4. **Copies the app** to `/var/www/html`, creates `uploads/evidence`,
   `uploads/documents`, and `logs/` (mode `750`), and `chown`s everything to
   `www-data`.
5. **Installs the entrypoint** `scripts/startup.sh` at `/startup.sh`.
6. **Declares a `HEALTHCHECK`** that curls `http://localhost:8080/healthz` every
   30s.
7. **`CMD ["/startup.sh"]`** — see [§3](#3-the-bootstrap--install-flow).

### 2.2 `docker/Dockerfile.hardened` — the orchestrated / read-only-rootfs image

For Kubernetes / IL4+ / government deployments (`docker/Dockerfile.hardened`).
It is nearly identical to the default image but:

- It is built for `runAsNonRoot` + `drop ALL caps` + `readOnlyRootFilesystem`
  (writes only to tmpfs paths — logs/run/lock under `/tmp`).
- It **`rm -f install.php`** — the installer is install-time only and is removed
  from the runtime image.
- Its entrypoint is `CMD ["apache2-foreground"]` directly (it does **not** run
  the installer at boot — schema/migrations are applied out-of-band by the
  owner role; see [§9](#9-the-least-privilege-database-role)).

> The CI `image-smoke` job builds this hardened image and asserts it boots as
> uid **33** (`www-data`) and serves `/healthz` (see [§6](#6-the-cicd-pipeline)).

The `release-aegis-image.yml` and `azure-deploy.yml` pipelines build with
**context `aegis/`**; the hardened image is the one published to GHCR.

---

## 3. The Bootstrap / Install Flow

The Render image's container entrypoint is `scripts/startup.sh`:

```bash
#!/bin/bash
set -e
echo "[AEGIS] Running database install/migration..."
php /var/www/html/install.php || echo "[AEGIS] Install skipped or DB not ready — continuing"
echo "[AEGIS] Starting Apache..."
exec apache2-foreground
```

So **on every container start**, `install.php` runs first, then Apache starts.
The installer is **idempotent** and **self-healing** — running it against an
already-installed DB just applies any new migrations. (Note: a failed install is
swallowed by `|| echo ...` so Apache still starts; if the DB is unreachable the
app boots but will error on first DB use — see [§11](#11-troubleshooting).)

### 3.1 What `install.php` does (`install.php`)

1. **Refuses HTTP access.** If not run from CLI and `AEGIS_INSTALL_ALLOWED` is
   not defined, it returns `403`. It is CLI-only by design.
2. **Loads env** from `.env.local` then `.env` (if present), then merges the
   real process environment via `getenv()` so containers/K8s/CI (which provide
   env, not a file) are honoured.
3. **Ensures the `aegis` schema** exists (`CREATE SCHEMA IF NOT EXISTS aegis`)
   and pins `search_path TO aegis`.
4. **Detects state** via `Database::tableExists('users')`:
   - **Already installed** → calls `runMigrations()` and exits.
   - **Fresh install** → runs the full path below.
5. **Loads and executes `database/schema.sql`** — the complete idempotent base
   schema.
6. **Creates the default admin user.** Requires `ADMIN_EMAIL` **and**
   `ADMIN_PASSWORD` env vars; if either is missing it logs
   `FATAL: ADMIN_EMAIL and ADMIN_PASSWORD ... must be set` and exits `1`. The
   password is hashed with `Security::hashPassword()`.
7. **Seeds defaults**: a `Default 5x5` risk matrix, six default risk categories
   (Cybersecurity, Operational, Compliance, Strategic, Financial, Reputational),
   and a block of default `settings` rows (org name/logo, date format, timezone,
   session timeout, version, upload limits, SMTP placeholders, AI advisor JSON),
   all inserted with `ON CONFLICT (key) DO NOTHING`.
8. **Runs `runMigrations()`**.

### 3.2 What `runMigrations()` does

`runMigrations()` (bottom of `install.php`) does three things, all idempotent:

1. **Inline `CREATE TABLE IF NOT EXISTS`** for the incidents, vendors,
   vendor_assessments, issues, issue_updates, and evidence_files tables, plus
   `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` for MFA columns on `users`
   (`mfa_secret`, `mfa_enabled`), plus an additional `settings` upsert block,
   plus a tolerant `CREATE INDEX IF NOT EXISTS` loop (index failures are logged,
   not fatal).
2. **Applies the 32 numbered SQL migration files** from `database/migrations/`,
   in order, `001_enterprise_phase1.sql` … `032_remove_modules.sql`. Each file
   is executed inside a `try/catch`: a `PDOException` is logged as a **warning**
   and the loop continues, so a migration that is already partially applied
   cannot wedge the boot.
3. Logs `Migrations applied.`

> The list of 32 migration files is **hard-coded** in `install.php`. The
> migration-integrity CI job (`scripts/verify_migrations.php`) statically checks
> that every file on disk is registered (see [§6](#6-the-cicd-pipeline)). When
> you add a migration file, you **must** also add it to that list.

### 3.3 Runtime "self-healing" migrations in `index.php`

Beyond the installer, `index.php` (around `index.php:160+`) runs a small set of
**idempotent `ALTER TABLE` guards on every request** (e.g. widening the `kris`
`unit`/`direction` columns). These are wrapped in `try/catch`. Under the
DML-only runtime DB role these guards **fail closed and become harmless no-ops**
(see [§9](#9-the-least-privilege-database-role)). Real schema changes must go
through migrations run as the owner role.

---

## 4. The Deploy Process (Render)

Render config lives in `render.yaml`. Deployment is **GitOps from `main`**:
Render watches the connected repo and rebuilds the Docker image on every push to
the default branch (Render's `autoDeploy`-from-`main` model). There is no
explicit deploy step in CI for Render — pushing to `main` *is* the deploy.

### 4.1 `render.yaml` — what it provisions

**Web service** (`type: web`, `name: aegis-grc`):

- `env: docker`, `dockerfilePath: ./Dockerfile`, `plan: free`.
- `healthCheckPath: /healthz` — Render only routes traffic once this returns
  healthy.
- Env vars wired into the service:

  | Key | Source in `render.yaml` |
  | --- | --- |
  | `DATABASE_URL` | `fromDatabase` → the `aegis-db` connection string |
  | `JWT_SECRET` | `generateValue: true` (Render mints a random value) |
  | `APP_ENV` | `value: production` |
  | `APP_NAME` | `value: AEGIS GRC` |
  | `ADMIN_EMAIL` | `sync: false` (you set it in the dashboard) |
  | `ADMIN_PASSWORD` | `generateValue: true` |
  | `APP_URL` | `sync: false` (you set it once the URL is known) |

**Managed database** (`databases:` → `name: aegis-db`, `databaseName: aegis`,
`user: aegis`, `plan: free`).

### 4.2 Deploy sequence on Render

1. Push to `main`.
2. Render builds `./Dockerfile`.
3. Render starts the container → entrypoint `scripts/startup.sh` runs
   `install.php` against `DATABASE_URL` (schema + migrations + seed, including
   the admin user from `ADMIN_EMAIL`/`ADMIN_PASSWORD`).
4. Apache starts on `:8080`; Render's health check polls `/healthz`.
5. Once healthy, traffic is routed.

> **Note on `render.yaml`'s env wiring:** the startup guards in `index.php`
> require `APP_URL` in production. `APP_URL` is `sync: false`, so you **must**
> set it in the Render dashboard or the app will throw the configuration-error
> page (see [§7](#7-environment-variable-reference) and [§11](#11-troubleshooting)).

---

## 5. Alternate Deploy: Azure Container Apps

`aegis/.github/workflows/azure-deploy.yml` provides a full **build → attest →
verify → promote** pipeline for Azure Container Apps. It triggers on push to
`main` (paths `aegis/**`) and `workflow_dispatch`.

**Job `build-and-push`:**
- Azure login via **OIDC** (`AZURE_CLIENT_ID`/`TENANT_ID`/`SUBSCRIPTION_ID`
  secrets), `az acr login`.
- Tags the image `${GITHUB_SHA::8}` + `latest`, builds the **`./aegis` context**
  and pushes to ACR with `provenance: true` + `sbom: true`.
- Mints a GitHub-native SLSA build-provenance attestation
  (`actions/attest-build-provenance`).

**Job `deploy`** (needs `build-and-push`):
- **Verify-before-promote gate (fail-closed):** `gh attestation verify
  oci://<image>@<digest>` for the exact digest. If verification fails the job
  stops and the Container App is **never** updated.
- `az containerapp update --image ...:<sha8>` to promote.
- Health check: curls `https://<fqdn>/health`.

This pipeline reads repo-level `vars` (`ACR_NAME`, `ACR_LOGIN_SERVER`,
`CONTAINER_APP_NAME`, `CONTAINER_APP_ENV`, `RESOURCE_GROUP`) and `secrets`
(`AZURE_*`).

---

## 6. The CI/CD Pipeline

CI lives in the **repo root** `.github/workflows/`. AEGIS is part of a
heterogeneous monorepo, so several jobs belong to sibling apps (CITADEL,
Sentinel, Compliance Copilot, etc.). The **AEGIS-relevant** jobs are documented
below; sibling jobs are summarized at the end.

### 6.1 `ci.yml` — PR-blocking CI

Triggers: `pull_request` and `push` to `main`. `concurrency` cancels superseded
runs. `permissions: contents: read`. All jobs run in parallel.

#### AEGIS-specific jobs in `ci.yml`

| Job | What it runs | What it gates |
| --- | --- | --- |
| **PHP lint (8.2)** (`php-lint`) | `php -l` over every `.php` in `aegis/` and `apex/` (parallel via `xargs -P4`) | Any PHP syntax error fails the build |
| **AEGIS tests (8.2)** (`aegis-tests`) | `php tests/run.php` (unit suite); then `scripts/verify_migrations.php`, `scripts/check_ui.php`, `scripts/check_route_auth.php`, `scripts/check_csrf.php`, `scripts/check_csv_export.php` | Unit-test failures, **unregistered migrations**, UI/CSP violations, routes missing auth, POST routes missing CSRF, unsafe CSV exports |
| **PALADIN** (`paladin`) | `php -l` over `paladin/`, then `php paladin/tests/security_test.php` (CSV/formula injection, SSRF guard, HTML sanitiser, output escaping) | Security regression-test failures |
| **AEGIS supply chain** (`aegis-supply-chain`) | CycloneDX SBOM via Syft (uploaded as artifact `aegis-sbom.cyclonedx.json`); **Trivy filesystem scan** (`severity HIGH,CRITICAL`, `ignore-unfixed: true`, `exit-code: 1`); Hadolint on the hardened Dockerfile (`continue-on-error: true`) | **Trivy gates** on fixable HIGH/CRITICAL deps. Hadolint is advisory |

The `aegis-tests` job runs **without a database** — these tests cover pure logic
(SSRF guard, JWT, RiskScore, AIAdvisor redaction) and static checks.

#### Sibling jobs in `ci.yml` (not AEGIS, listed for completeness)

- **CITADEL (Node 20)** — `npm ci` → `node --check` → ESLint → `npm audit
  --audit-level=high` (gating) → smoke test → SARIF export validation →
  accuracy benchmark gate (`FAIL_UNDER_RECALL=0.9`, `FAIL_UNDER_PRECISION=0.9`).
- **Sentinel QMS frontend (Node 20)** — `npm ci` → typecheck → vitest; lint is
  non-blocking (`continue-on-error`).
- **Compliance Copilot (Node 20)** — `npm ci` → `tsc --noEmit`.
- **Python compile-check (3.12)** — `py_compile` across the Python apps.

### 6.2 `aegis-integration.yml` — DB-backed integration tests

Triggers: `pull_request` / `push` to `main` on `aegis/**` (or the workflow file
itself). Spins up a **`postgres:16-alpine`** service. Job env sets
`DATABASE_URL`, `AUDIT_HMAC_KEY=ci-test-audit-hmac-key`,
`JWT_SECRET=ci-test-jwt-secret-at-least-32-characters`. Uses PHP **8.3** with
`pdo_pgsql, sodium`.

**Job `db-integration`** — proves runtime behaviour unit tests can't:

1. **Bootstrap** via `php install.php` (with `ADMIN_EMAIL`/`ADMIN_PASSWORD`) —
   the authoritative installer (schema + migrations + seed) against a real DB.
2. **Audit chain — seed** (`tests/integration/audit_db.php`).
3. **Audit chain — verify integrity** expecting INTACT
   (`scripts/verify_audit_log.php`).
4. **Audit chain — tamper detection**: `UPDATE activity_log SET action='tampered'`
   on one row, then assert `verify_audit_log.php --quiet` exits **non-zero**. If
   the verifier still passes, the job fails with
   `Tampering was NOT detected`.
5. **Least-privilege DB role** — applies `database/roles.sql`, sets a password
   for `aegis_app`, runs `tests/integration/roles_test.sql` (DML allowed, DDL
   denied).
6. **Row-Level Security** — `tests/integration/rls_test.sql` (tenant isolation).
7. **Tenancy helper** — `tests/integration/tenancy_db.php` (`setTenant()` drives
   RLS).
8. **Shared session store** — `tests/integration/session_db.php` (Postgres
   session handler round-trip).
9. **Platform admin** — `tests/integration/platform_db.php` (audited
   cross-tenant switch).

**Job `image-smoke`** (`continue-on-error: true` — advisory, "advisory then
promote" convention): builds `docker/Dockerfile.hardened`, runs it, polls
`/healthz`, and asserts the container's uid is **33** (non-root `www-data`).

### 6.3 `release-aegis-image.yml` — AEGIS supply-chain release

Triggers: tags matching **`aegis-v*`** and `workflow_dispatch` only (never on
`pull_request`, since it pushes images and mints attestations). Publishes to
**GHCR** as `ghcr.io/<owner>/aegis-server`.

Pipeline (`build-sign-attest`):
1. Buildx + cosign install; GHCR login.
2. `docker/metadata-action` derives semver + ref + sha + optional dispatch tag.
3. **Build & push** the hardened image (`context: aegis`,
   `file: aegis/docker/Dockerfile.hardened`) with BuildKit `sbom: true` +
   `provenance: mode=max`.
4. **GitHub build-provenance attestation** (`actions/attest-build-provenance`,
   `push-to-registry`).
5. **CycloneDX SBOM** of the pushed image (Syft).
6. **Keyless cosign sign** the digest (Sigstore / GitHub OIDC — no long-lived
   key).
7. **Cosign attest** the CycloneDX SBOM.
8. **Verify signature (gate)** — `cosign verify` against the workflow's OIDC
   identity; **fails the release if unsigned/unverifiable**.
9. **Verify build provenance (gate)** — `gh attestation verify oci://...`.
10. Upload SBOM artifact + job summary.

> `release-image.yml` in the same folder is the **CITADEL** release pipeline
> (triggers `citadel-v*` / `v*`, image `citadel-server`). It is **not** AEGIS.

### 6.4 What gates a merge / release (summary)

- **Merge to `main`** is blocked by `ci.yml` (PHP lint, AEGIS tests + static
  checks, PALADIN, Trivy supply-chain) and `aegis-integration.yml`
  (`db-integration`). `image-smoke` is currently advisory.
- **An AEGIS image release** (`aegis-v*` tag) cannot go green unless the image
  is **both signed and verifiable** (cosign verify + provenance verify gates).
- **An Azure deploy** cannot promote unless the build-provenance verify gate
  passes.

---

## 7. Environment Variable Reference

This table lists **every environment variable the application code reads**
(`$_ENV[...]` / `getenv(...)` across `src/`, `index.php`, `api/`, `config/`,
`controllers/`, `scripts/`, and `install.php`), plus the variables only
referenced as templates in `.env.example`. "Required?" reflects what the code
actually enforces at the **startup guards** in `index.php:95-109` and in
`install.php`.

### 7.1 Core / required

| Variable | Purpose | Required? | Default | Read in |
| --- | --- | --- | --- | --- |
| `JWT_SECRET` | Signs JWTs; HMAC key for API keys; **fallback** for the audit HMAC key and the settings-encryption key when dedicated keys aren't set | **Yes** — startup guard throws if empty or `< 32` chars | none | `index.php:95`, `config/app.php`, `src/Security.php` (auditKey, API keys, settings key), `api/index.php`, `api/ingest.php`, `controllers/VendorController.php` |
| `DATABASE_URL` | Postgres connection string; parsed into host/port/db/user/pass | **Yes** (unless `DB_HOST` is set instead) | none | `config/database.php`, startup guard `index.php:101` |
| `APP_URL` | CORS allow-origin + base for absolute links (emails, SSO redirect URI, vendor portal links) | **Yes in production** (`APP_ENV=production` and empty → startup guard throws) | `''` (some consumers fall back to `http://localhost`) | `index.php:107`, `src/SSO.php`/`SSOController`, `controllers/AdminController.php`, `controllers/AuthController.php`, `controllers/VendorController.php`, `api/index.php`, cron scripts |
| `APP_ENV` | Environment mode; `production` enables the `APP_URL` guard | No | `production` | `config/app.php`, `index.php:107` |
| `APP_NAME` | Display name of the app | No | `AEGIS GRC` | `config/app.php` |

### 7.2 Discrete DB vars (alternative to `DATABASE_URL`)

Used only when `DATABASE_URL` is **not** set (`config/database.php`).

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `DB_HOST` | Postgres host | One of `DATABASE_URL`/`DB_HOST` required | `localhost` |
| `DB_PORT` | Postgres port | No | `5432` |
| `DB_NAME` | Database name | No | `aegis` |
| `DB_USER` | DB user | No | `aegis` |
| `DB_PASS` | DB password (file-backed: `DB_PASS_FILE`) | No | `''` |
| `DB_PASSWORD` | Alternate password var, file-backed only (`DB_PASSWORD_FILE` in `Secrets`) | No | — |

### 7.3 Admin bootstrap (install-time only)

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `ADMIN_EMAIL` | Email of the seeded admin user | **Yes for a fresh install** — `install.php` exits `1` if missing | none |
| `ADMIN_PASSWORD` | Password of the seeded admin user (hashed at install) | **Yes for a fresh install** | none |

> These are only consumed on a *first* install (when the `users` table doesn't
> exist). On subsequent boots `install.php` skips straight to migrations.

### 7.4 Cryptographic key separation (optional, recommended)

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `AUDIT_HMAC_KEY` | Dedicated key for the tamper-evident audit hash chain. Store where the DB role **cannot** read it for true integrity separation | No | Derived from `JWT_SECRET` (`aegis_audit_v1:` prefix) | `src/Security.php:256` |
| `APP_ENCRYPTION_KEY` | Encrypts sensitive settings at rest (SMTP/S3/AI keys). File-backed via `APP_ENCRYPTION_KEY_FILE` | No | Derived from `JWT_SECRET` (legacy v1 key still decrypts old ciphertext) | `src/Security.php:237` |
| `SESSION_SECRET` | **Reserved/unused** by PHP sessions today — `.env.example` notes it is for "future signed cookies" | No | — (template only) |

### 7.5 KMS envelope encryption (optional — see [§8](#8-secret-mounts-_file-and-kms-envelope-encryption))

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `KMS_PROVIDER` | `none` (default) \| `vault` \| `exec` | No | `none` (inert) |
| `APP_ENCRYPTION_KEY_CIPHERTEXT` | Wrapped data key the KMS unwraps into `APP_ENCRYPTION_KEY` in-process. File-backed | No | — |
| `VAULT_ADDR` | HashiCorp Vault address (for `KMS_PROVIDER=vault`) | If `vault` | — |
| `VAULT_TOKEN` | Vault token (prefer `VAULT_TOKEN_FILE`) | If `vault` | — |
| `VAULT_TRANSIT_KEY` | Vault transit key name | If `vault` | — |
| `KMS_DECRYPT_CMD` | Shell command that reads ciphertext on stdin, writes plaintext key on stdout (for `KMS_PROVIDER=exec` — AWS/GCP/Azure CLIs) | If `exec` | — |

### 7.6 Networking / proxy

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `TRUSTED_PROXY_IPS` | Comma-separated proxy IPs whose `X-Real-IP` header is trusted for client-IP resolution | No | `127.0.0.1` | `src/Security.php:13` |

### 7.7 Sessions & storage

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `SESSION_DRIVER` | `pg` to store sessions in Postgres (required for horizontal scaling); anything else uses local-file sessions | No | `files` | `index.php:129` |
| `STORAGE_DRIVER` | `local` or `s3` (template in `.env.example`; runtime storage settings are read from DB settings, not env) | No | `local` (template) |

### 7.8 Email (SMTP)

Read by the cron mailers (`scripts/send_scheduled_reports.php`,
`scripts/send_notifications.php`, `scripts/run_workflows.php`) and `src/Mailer.php`.

| Variable | Purpose | Required? | Default |
| --- | --- | --- | --- |
| `SMTP_HOST` | SMTP server hostname (mail is skipped if unset → `smtp_not_configured`) | No | `''` |
| `SMTP_PORT` | SMTP port | No | `587` |
| `SMTP_USER` | SMTP username | No | `''` |
| `SMTP_PASS` | SMTP password (file-backed: `SMTP_PASS_FILE`) | No | `''` |
| `SMTP_FROM` | From address | No | falls back to `SMTP_USER` |
| `SMTP_FROM_NAME` | From display name | No | `AEGIS GRC` (template/DB setting) |

### 7.9 Template-only variables (in `.env.example`, not read directly by app code)

These appear in `.env.example` as configuration scaffolding but are **not** read
via `$_ENV` in the PHP source. SMTP/S3/AI runtime config is generally read from
the encrypted `settings` table seeded at install, and `HTTP_PORT`/`HTTPS_PORT`
are consumed by `docker-compose`, not the app:

| Variable | Where it's used |
| --- | --- |
| `S3_BUCKET`, `S3_REGION`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_ENDPOINT`, `S3_PUBLIC_URL` | `.env.example` template / DB settings (S3 storage) |
| `AI_PROVIDER`, `AI_API_KEY` | `.env.example` template; AI advisor config lives in the `ai_settings` setting (seeded by `install.php`) |
| `HTTP_PORT`, `HTTPS_PORT` | `docker-compose` only |

> If you need any of these S3/AI values active, set them through the in-app
> Settings UI (which writes the encrypted `settings` table) rather than the
> environment — the code path that reads them is the DB settings, not `$_ENV`.

---

## 8. Secret Mounts (`*_FILE`) and KMS Envelope Encryption

These are resolved **early in bootstrap** (`index.php:85-92`), before the
startup guards and any consumer read the keys.

### 8.1 `*_FILE` mounts (`src/Secrets.php`)

`Secrets::hydrate()` resolves the "`<NAME>_FILE` points at a mounted file"
convention. For each supported variable, if `<NAME>_FILE` points at a readable
file, its trimmed contents become `<NAME>` — **unless** a direct value is
already set (a direct value always wins). File-backed variables
(`Secrets::FILE_BACKED`):

`JWT_SECRET`, `AUDIT_HMAC_KEY`, `APP_ENCRYPTION_KEY`,
`APP_ENCRYPTION_KEY_CIPHERTEXT`, `VAULT_TOKEN`, `DB_PASS`, `DB_PASSWORD`,
`SMTP_PASS`.

This keeps secrets out of the process environment (where they leak via `/proc`,
crash dumps, child processes). Use it with Docker/Compose secrets, Kubernetes
Secrets (CSI/projected volumes), Vault Agent, or KMS sidecars.

### 8.2 KMS unwrap (`src/Kms.php`)

`Kms::hydrate()` runs after `Secrets::hydrate()`. It is **inert by default**
(`KMS_PROVIDER` unset / `none`). When configured, it unwraps
`APP_ENCRYPTION_KEY_CIPHERTEXT` into `APP_ENCRYPTION_KEY` **in-process only** —
the plaintext key never sits in config or on disk. An explicit
`APP_ENCRYPTION_KEY` always wins (emergency override). Providers:

- **`vault`** — POSTs to `{VAULT_ADDR}/v1/transit/decrypt/{VAULT_TRANSIT_KEY}`
  with `X-Vault-Token: {VAULT_TOKEN}`.
- **`exec`** — runs `KMS_DECRYPT_CMD` (ciphertext on stdin → plaintext on
  stdout); universal escape hatch for AWS KMS / GCP KMS / Azure Key Vault.

If unwrap fails, `Kms::hydrate()` throws a `RuntimeException` (fail-closed) — the
app shows the configuration-error page rather than booting with a bad key.

---

## 9. The Least-Privilege Database Role

`database/roles.sql` separates the **owner/migration role** (full DDL) from the
**runtime role** the app connects as (DML only). Rationale: a SQL-injection or
app compromise then cannot alter/drop schema, add triggers, or disable
constraints — it is confined to data operations (NIST 800-53 AC-6 / CMMC
AC.L2-3.1.5).

What `roles.sql` does:

1. Creates `aegis_app` (`CREATE ROLE aegis_app LOGIN`) if it doesn't exist. Set
   its password out-of-band: `ALTER ROLE aegis_app PASSWORD '...';`.
2. Grants `USAGE` on schemas `aegis, public` (connect + read — **not** create).
3. Grants `SELECT, INSERT, UPDATE, DELETE` on all tables and
   `USAGE, SELECT, UPDATE` on all sequences in `aegis`.
4. Sets `ALTER DEFAULT PRIVILEGES` so the same DML applies to tables/sequences
   created by **future** migrations (run as the owner).
5. `REVOKE CREATE ON SCHEMA aegis FROM aegis_app` — defence in depth.
6. **Optional WORM audit log** (commented out): `REVOKE UPDATE, DELETE,
   TRUNCATE ON aegis.activity_log FROM aegis_app` makes the audit log
   append-only at the DB level (recommended for CUI / legal-hold). Trade-off:
   the admin "audit retention/prune" feature stops working for the app role —
   run retention as a separate audited maintenance role.

### How to wire it up

1. Apply schema + migrations as the **owner** (superuser or schema owner) via
   `install.php`.
2. Run `database/roles.sql` **once** as that owner.
3. Point the app at the runtime role:
   `DATABASE_URL=postgres://aegis_app:<password>@host:5432/aegis`. Keep the owner
   credentials in a separate, restricted secret used only by the installer/CI.

> Under the DML-only role, the idempotent `ALTER TABLE` self-healing guards in
> `index.php` fail closed and become harmless no-ops (they are caught). Apply
> real schema changes through migrations run as the owner. The CI
> `db-integration` job proves this role denies DDL while allowing DML.

---

## 10. Deploy-From-Scratch Runbook

### 10.1 Render (recommended path — matches `render.yaml`)

1. **Create the service from the repo.** In Render, create a new Blueprint from
   the repo so it reads `render.yaml`. This provisions the `aegis-grc` web
   service (Docker, `./Dockerfile`) and the `aegis-db` Postgres database.
2. **Set the `sync: false` env vars** in the Render dashboard:
   - `ADMIN_EMAIL` — the first admin login.
   - `APP_URL` — the public HTTPS URL (set after Render assigns one, then
     redeploy). **Required in production** or the app throws the configuration
     error page.
   - (`JWT_SECRET`, `ADMIN_PASSWORD` are auto-generated; `DATABASE_URL` is wired
     from the DB.)
3. **Push to `main`.** Render builds the image and deploys.
4. **First boot.** `scripts/startup.sh` runs `install.php` → creates the `aegis`
   schema, applies `schema.sql` + all 32 migrations, seeds defaults, and creates
   the admin user from `ADMIN_EMAIL`/`ADMIN_PASSWORD`.
5. **Health.** Render polls `/healthz`; traffic routes once healthy.
6. **Harden the DB (recommended).** Connect as the DB owner, run
   `database/roles.sql`, set the `aegis_app` password, then switch
   `DATABASE_URL` to `aegis_app`. Optionally enable the WORM audit revoke.
7. **Log in** at `APP_URL` with `ADMIN_EMAIL` + the generated `ADMIN_PASSWORD`
   (visible in the Render dashboard), then configure SMTP/S3/AI/branding in the
   in-app Settings UI.

### 10.2 Generic Docker / Kubernetes (hardened image)

1. **Provision Postgres 16** and create the `aegis` database.
2. **Set required env** (or `*_FILE` mounts): `DATABASE_URL` (owner role for the
   install step), `JWT_SECRET` (≥ 32 chars), `APP_URL`, `APP_ENV=production`,
   `ADMIN_EMAIL`, `ADMIN_PASSWORD`. Strongly recommended: `AUDIT_HMAC_KEY`,
   `APP_ENCRYPTION_KEY`.
3. **Run the installer once** (the hardened image removes `install.php`, so run
   it from the default image or a maintenance pod):
   `php install.php` (CLI). This applies schema + migrations + seed.
4. **Apply the least-privilege role**: `psql ... -f database/roles.sql`, set the
   `aegis_app` password, and switch the runtime `DATABASE_URL` to `aegis_app`.
5. **Deploy the hardened image** (`docker/Dockerfile.hardened`) with the matching
   `securityContext` (runAsNonRoot, drop ALL caps, readOnlyRootFilesystem) and
   tmpfs mounts for `/tmp`, `logs/`, `uploads/`. It serves on `:8080`; front it
   with nginx (public surface).
6. **Liveness**: `/healthz`. **Readiness**: `/readyz`.

### 10.3 Local development

1. `cp .env.example .env` and fill `DB_PASS` and `JWT_SECRET` (the compose stack
   refuses to start without them). Generate `JWT_SECRET` with
   `php -r "echo bin2hex(random_bytes(32));"`.
2. Set `APP_ENV=development` to relax the `APP_URL` startup guard.
3. Bring up Postgres + the app (via `docker-compose`), then run `php install.php`
   with `ADMIN_EMAIL`/`ADMIN_PASSWORD` set.

---

## 11. Troubleshooting

### "Configuration Error" page on boot

`index.php`'s top-level handler renders an operator-facing page for
`RuntimeException`s (the startup guards). It explicitly lists `JWT_SECRET`,
`DATABASE_URL`, `APP_URL`. Causes:

| Symptom | Cause | Fix |
| --- | --- | --- |
| "JWT_SECRET must be set and at least 32 characters" | `JWT_SECRET` missing or `< 32` chars | Set a 64-hex value (`php -r "echo bin2hex(random_bytes(32));"`) |
| "No database configured" | Neither `DATABASE_URL` nor `DB_HOST` set | Set `DATABASE_URL` (or the `DB_*` vars) |
| "APP_URL must be set in production" | `APP_ENV=production` and `APP_URL` empty | Set `APP_URL` in the dashboard (it's `sync: false` in `render.yaml`) |
| "Could not unwrap APP_ENCRYPTION_KEY via KMS" | `KMS_PROVIDER` set but unwrap failed | Check KMS provider creds (`VAULT_*` or `KMS_DECRYPT_CMD`) |

Every 500 page includes a **request correlation ID** (`X-Request-Id` header /
"Reference:" on the page). Grep the server log for that ID to find the full error
— internal detail is logged, never shown to the user.

### App boots but DB calls fail

`scripts/startup.sh` swallows installer failures (`|| echo ... — continuing`) so
Apache starts even if the DB was unreachable at boot. If the first install never
completed, tables won't exist. **Fix:** ensure `DATABASE_URL` is reachable and
re-run the container (or `php install.php`). The installer is idempotent.

### Admin user not created

`install.php` exits `1` (fresh install only) if `ADMIN_EMAIL` **or**
`ADMIN_PASSWORD` is unset. Set both, then re-run the installer. On an
already-installed DB the installer skips user creation entirely.

### Migration appears not to apply

Migrations are applied tolerantly: a `PDOException` is **logged as a warning**
and the loop continues. Check the container log for `Migration NNN_... warning:`.
Also confirm the file is **registered** in the `$migrationFiles` list in
`install.php` — `scripts/verify_migrations.php` (CI) fails if a file on disk
isn't registered.

### DDL fails at runtime under `aegis_app`

Expected. The runtime role is DML-only (`database/roles.sql`). The
self-healing `ALTER TABLE` guards in `index.php` are designed to fail closed as
no-ops under this role. Apply real schema changes via migrations run as the
**owner** role.

### Sessions not shared across instances

Default sessions are local-file. For multiple instances behind a load balancer,
set `SESSION_DRIVER=pg` (Postgres-backed handler). If the handler can't
register, `index.php` logs a warning and falls back to file sessions
(login still works on a single instance).

### Email not sending

The cron mailers return `smtp_not_configured` when `SMTP_HOST`/`SMTP_USER` are
unset. Set the `SMTP_*` env vars (or the corresponding DB settings) and confirm
the mailer cron scripts are scheduled.

### Health checks

- `/healthz` — liveness (used by the Docker `HEALTHCHECK`, Render
  `healthCheckPath`, and the CI smoke test).
- `/readyz` — readiness.
- Azure deploy curls `/health` (note: the Azure verify step uses `/health`,
  while the canonical liveness route in `index.php` is `/healthz`).

---

## 12. Deployment Models & Target Guides

AEGIS is a single stateless container + Postgres + object storage, so it maps
onto every common topology. Pick the target guide that matches your environment;
each one is operator-grade (architecture, topology diagram, prerequisites,
identity/least-privilege, env tables, verification, day-2 ops, troubleshooting):

| Model | When to use | Guide |
|---|---|---|
| **Local development** | Laptop/dev via `docker-compose`; fast inner loop | [`../deployments/LOCAL_DEVELOPMENT.md`](../deployments/LOCAL_DEVELOPMENT.md) |
| **Single Linux server** | One VM, nginx + TLS, systemd or compose, local backups | [`../deployments/SINGLE_LINUX_SERVER.md`](../deployments/SINGLE_LINUX_SERVER.md) |
| **Kubernetes** | Manifests/Helm, ingress, CSI/ExternalSecrets, HPA/PDB, probes | [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md) |
| **Azure** | Commercial **and Azure Government** — AKS/App Service + Azure DB for PostgreSQL + Blob + Key Vault + Managed Identity + Entra ID | [`../deployments/AZURE.md`](../deployments/AZURE.md) |
| **AWS** | **Commercial and GovCloud** — ECS/Fargate or EKS + RDS + S3 + Secrets Manager + IAM roles/IRSA + KMS | [`../deployments/AWS.md`](../deployments/AWS.md) |
| **Air-gapped / IL5+** | Offline install, private registry mirror, no-internet secrets, **self-hosted LLM via Ollama** | [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md) |
| **Managed PaaS (Render)** | Fastest hosted path; blueprint provided | This doc, [§4](#4-the-deploy-process-render) + `render.yaml` |

Related docs: [`ARCHITECTURE.md`](ARCHITECTURE.md) ·
[`SECURITY.md`](SECURITY.md) · [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md) ·
[`DATABASE.md`](DATABASE.md) · [`DEPENDENCIES.md`](DEPENDENCIES.md).

---

## 13. The Background / Cron Worker Process

AEGIS's time-based features are driven by **CLI scripts under `scripts/`**, not by
an in-app scheduler or queue daemon. Every script is **CLI-guarded** (rejects a
non-CLI SAPI) and **self-gating** (queries the DB for what is actually due), so it
is safe to run on a fixed schedule and a no-op when nothing is pending.

| Script | Purpose | Recommended cadence | `render.yaml` |
|---|---|---|---|
| `scripts/run_workflows.php` | Evaluate active automation rules; fire `create_alert` / `send_email` / `create_issue` on matching triggers (risk threshold, audit/policy/vendor overdue, incident severity, non-compliant control, scheduled) | script header says `*/5`; blueprint uses `*/15 * * * *` | `aegis-cron-workflows` |
| `scripts/dispatch_webhooks.php` | Deliver pending `webhook_deliveries` (POST); exponential back-off, gives up after 5 attempts | `* * * * *` (every minute) | `aegis-cron-webhooks` |
| `scripts/send_notifications.php` | 11 categories of email notifications by user preference; immediate + daily/weekly digest | `0 * * * *` (hourly) | `aegis-cron-notifications` |
| `scripts/send_scheduled_reports.php` | Send scheduled GRC report emails; picks which schedules are due | `0 * * * *` (hourly) | `aegis-cron-reports` |
| `scripts/drain_email_queue.php` | Retry emails that failed their immediate send (`email_queue`); exponential back-off, marks `failed` after max attempts | `*/5 * * * *` (every 5 min) | `aegis-cron-email` |
| `scripts/capture_metrics_snapshot.php` | Daily GRC metrics snapshot for trend charts | `0 1 * * *` (daily 01:00) | `aegis-cron-metrics` |

**How to run them per target:**

- **Render** — `render.yaml` defines six `cron` services (one per script), each
  running the **same Docker image** with `dockerCommand: php scripts/<name>.php`,
  inheriting `JWT_SECRET` from the web service via `fromService` and reading the
  shared `aegis-secrets` env-var group. Two operator caveats: Render `cron`
  requires a **paid** instance plan (services are `plan: starter` while the web
  service/DB are `free`), and the per-minute webhook cron cold-starts a container
  each run. You may consolidate several hourly jobs into one service
  (`sh -c 'php a && php b'`) to cut cost.
- **docker-compose** — a dedicated `cron` service runs `run_workflows.php` +
  `dispatch_webhooks.php` in a `while true; … sleep 60` loop as non-root
  `www-data` with `cap_drop: ALL`. Add the other scripts to that loop or a host
  crontab as needed.
- **Kubernetes** — one `CronJob` per script (see
  [`../deployments/KUBERNETES.md`](../deployments/KUBERNETES.md)).
- **Single VM** — host `crontab` entries running `php /path/scripts/<name>.php`.

> **Run from one scheduler only.** The jobs are idempotent but you should not fan
> the same job across every app replica, or notifications/webhooks can double-send.
> Every cron process must share the **same** `JWT_SECRET` / `APP_ENCRYPTION_KEY` /
> `AUDIT_HMAC_KEY` as the web tier, or settings won't decrypt and the audit HMAC
> chain will diverge across processes.

---

## 14. AI Advisor & Ollama (Self-Hosted / Air-Gapped LLM)

AEGIS's optional **AI Advisor** (`src/AIAdvisor.php`) provides *advisory-only*
control-gap suggestions and compliance narratives. It is **opt-in** (disabled
until an admin sets a provider + key), has a global kill-switch (`ai_enabled`
setting), redacts secrets/PII before egress, and logs every call to
`ai_inference_log` and the audit trail. See [`SECURITY.md`](SECURITY.md) and the
root `AIADVISOR.md` for the governance model.

### 14.1 Hosted providers (current code)

The shipped clients call **fixed, hard-coded HTTPS endpoints** (no user-supplied
URL → no SSRF surface), configured via the encrypted `settings` table
(`ai_settings` JSON, or `ai_provider` + `ai_api_key`), **not** environment
variables:

| Provider (`ai_provider`) | Endpoint | Model |
|---|---|---|
| `claude` (default) | `https://api.anthropic.com/v1/messages` | `claude-haiku-4-5-20251001` |
| `openai` | `https://api.openai.com/v1/chat/completions` | `gpt-4o-mini` |

For a hosted deployment, enable AI in the in-app **Settings** UI (provider + API
key, encrypted at rest); no env var is involved.

### 14.2 Ollama for self-hosted / air-gapped inference

Air-gapped and data-sovereign deployments cannot reach `api.anthropic.com` or
`api.openai.com`. **[Ollama](https://ollama.com)** runs open-weight models
(Llama 3.x, Mistral, Qwen, etc.) entirely inside your network and exposes an
**OpenAI-compatible Chat Completions API** at
`http://<ollama-host>:11434/v1/chat/completions`.

> **Implementation status (honest):** the current `src/AIAdvisor.php` OpenAI path
> is pinned to `https://api.openai.com/...`. To route AEGIS to Ollama you must add
> a small provider branch (or config-driven base URL) — the request/response
> shape is already OpenAI-compatible, so the change is a **base-URL + model-name
> swap**, plus (recommended) allowing the Ollama host through the `Ssrf` infra
> guard since it is operator-configured internal infrastructure. Until that branch
> exists, treat Ollama support as an **integration point**, not a shipped feature.
> When AEGIS is deployed fully offline **without** that branch, simply leave AI
> **disabled** (`ai_enabled=0`); every AI surface degrades gracefully to nothing.

**Reference Ollama setup (Docker):**

```bash
# Pull and run Ollama, then load a model. Keep it on the internal network only.
docker run -d --name ollama -p 11434:11434 \
  -v ollama:/root/.ollama ollama/ollama:latest
docker exec -it ollama ollama pull llama3.1:8b        # or mistral, qwen2.5, etc.

# Smoke test the OpenAI-compatible endpoint AEGIS would call:
curl -s http://localhost:11434/v1/chat/completions \
  -H 'Content-Type: application/json' \
  -d '{"model":"llama3.1:8b","messages":[{"role":"user","content":"ping"}]}'
```

Then point AEGIS's AI base URL at `http://ollama:11434/v1` (via the provider
branch above) and set the model to the pulled tag. Full offline procedure — model
bundling, private registry mirror, no-internet secrets — is in
[`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md).

### 14.3 GPU Acceleration for Ollama

Ollama runs on CPU by default and **degrades gracefully to CPU** if no GPU is
present (slower, but functional for small models). For acceptable latency on
7B–70B models, use a GPU:

| Environment | How to enable GPU | Degrade-to-CPU |
|---|---|---|
| **Docker (single host)** | Install the NVIDIA Container Toolkit, then `docker run --gpus all ... ollama/ollama`. Requires host NVIDIA driver + CUDA-capable GPU | Omit `--gpus`; Ollama auto-falls back to CPU |
| **Kubernetes** | Deploy the **NVIDIA device plugin** DaemonSet; request `resources.limits: nvidia.com/gpu: 1` on the Ollama pod; schedule to a GPU node pool (taint/toleration or nodeSelector) | Pods without a GPU limit run CPU-only |
| **AWS** | GPU instance types (e.g. `g5`/`g4dn`) for the node running Ollama; ECS with GPU task resource or EKS GPU node group + device plugin | CPU instance types run Ollama CPU-only |
| **Azure** | GPU VM SKUs (NC/ND series) for the AKS GPU node pool + NVIDIA device plugin | Standard SKUs run CPU-only |

Sizing rules of thumb: 8B models fit in ~6–8 GB VRAM (quantized); 70B needs
~40+ GB VRAM or multi-GPU. Keep Ollama on a **private** network segment;
AEGIS reaches it over cluster-internal DNS, never the public internet. AEGIS
itself needs no GPU — only the Ollama inference host does.

---

## 15. Production Readiness Checklist

Work through this before declaring a deployment production-ready. Cross-links to
the controls that implement each item.

### 15.1 Secrets & identity

- [ ] `JWT_SECRET` set to a unique ≥ 32-char value (`php -r "echo bin2hex(random_bytes(32));"`); **not** the same across environments.
- [ ] Dedicated `APP_ENCRYPTION_KEY` **and** `AUDIT_HMAC_KEY` set (do not rely on the `JWT_SECRET`-derived fallback in production — see [`SECURITY.md`](SECURITY.md) §12/§14).
- [ ] Secrets delivered via `*_FILE` mounts or a secret manager / **KMS envelope** (`KMS_PROVIDER=vault|exec`), never plaintext env in a shared config. See [§8](#8-secret-mounts-_file-and-kms-envelope-encryption).
- [ ] Cloud identity uses **IAM roles / workload identity / managed identity** (IRSA, Azure Managed Identity) — no long-lived static cloud keys.
- [ ] `ADMIN_PASSWORD` rotated after first login; the generated bootstrap password not left in place.
- [ ] Same crypto keys shared by the web tier **and every cron process**.
- [ ] `.env` never committed (only `.env.example`).

### 15.2 Transport & exposure

- [ ] TLS terminated in front of the app (nginx/ingress/LB); app container never public directly.
- [ ] `APP_URL` set to the exact public HTTPS origin (it is the CORS allow-origin and the production startup guard).
- [ ] HSTS confirmed present on HTTPS responses (`Strict-Transport-Security`, auto-added by `Security::setSecurityHeaders`).
- [ ] CSP intact (nonce-based, no external origins); `scripts/check_ui.php` green.
- [ ] Trusted-proxy config correct (`TRUSTED_PROXY_IPS`) so client IP / rate-limit keys are accurate behind the LB.
- [ ] Only ports 80/443 exposed publicly; DB and Ollama on private segments.

### 15.3 Hardening

- [ ] Runtime DB role is the **DML-only `aegis_app`** (`database/roles.sql`), not the migration owner.
- [ ] Prefer the **hardened image** (`docker/Dockerfile.hardened`) with `runAsNonRoot`, `drop ALL` caps, `readOnlyRootFilesystem` + tmpfs for `/tmp`, `logs/`, `uploads/`.
- [ ] Container runs as non-root `www-data` (uid 33) — verified by the CI `image-smoke` job.
- [ ] Optional **WORM audit log**: enable the `REVOKE UPDATE, DELETE, TRUNCATE ON aegis.activity_log` block in `roles.sql` for CUI / legal-hold (see [`SECURITY.md`](SECURITY.md)).
- [ ] MFA enforcement configured for privileged roles (`mfa_enforcement` setting); SSO/OIDC wired if used.
- [ ] Supply-chain gates green: SBOM + Trivy (HIGH/CRITICAL), cosign signature + provenance verified for released images.

### 15.4 Resilience & operations

- [ ] Managed / HA PostgreSQL (Multi-AZ or replica + failover); automated backups / PITR enabled.
- [ ] Object storage on **S3-class** storage (versioned) for any multi-replica or production deployment — not a local `uploads/` volume.
- [ ] `SESSION_DRIVER=pg` set when running **more than one** app replica (shared sessions).
- [ ] Background cron/worker jobs scheduled and running from **one** scheduler ([§13](#13-the-background--cron-worker-process)); SMTP configured so notifications actually send.
- [ ] Health probes wired: `/healthz` (liveness), `/readyz` (readiness); orchestrator restart/rollback tested.
- [ ] Backups **and restore drills** in place per [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md); keys escrowed separately from the DB.
- [ ] Logs shipped from container stdout/stderr to an aggregator; alerting on backup-job and cron failures.
- [ ] `php tests/run.php` + `scripts/verify_migrations.php` + `verify_audit_log.php` green against the target.

---

*Everything above is derived from the repository sources: `render.yaml`,
`Dockerfile`, `docker/Dockerfile.hardened`, `docker-compose.yml`, `docker/initdb.sh`,
`scripts/startup.sh`, `scripts/*.php` (cron), `install.php`, `config/app.php`,
`config/database.php`, `.env.example`, `database/roles.sql`, `src/AIAdvisor.php`,
`src/Security.php`, `src/Secrets.php`, `src/Kms.php`, `src/Storage.php`,
`index.php`, and the workflows in `.github/workflows/` (`ci.yml`,
`aegis-integration.yml`, `release-aegis-image.yml`) and
`aegis/.github/workflows/azure-deploy.yml`.*
