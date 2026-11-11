# PAL — Enterprise Process Asset Library

[![PAL CI](https://github.com/jessicarojas1/jessicarojas1.github.io/actions/workflows/pal-ci.yml/badge.svg)](https://github.com/jessicarojas1/jessicarojas1.github.io/actions/workflows/pal-ci.yml)

PAL is an enterprise **document management and document control** system for an organisation's process assets: policies, processes, procedures, standards, work instructions, templates and forms. It is built for organisations working to ISO 27001, CMMI Level 3 and CMMC Level 2, where an auditor must be able to see who approved exactly which content, when it took effect, and what was in force on a given date.

**Scope is document management only.** Training, CAPA, nonconformance, risk, audit management and acknowledgement campaigns are explicitly out of scope.

## Status

| Phase | State |
|---|---|
| 0: Discovery & architecture | **Approved** (rev 2, 2026-10-25) |
| 1: Foundation | **Implemented**. See [what Phase 1 delivers](#what-phase-1-delivers) |
| 2: Repository + walking skeleton | **Implemented** (2026-10-26). See [what Phase 2 delivers](#what-phase-2-delivers) and [OPEN_ITEMS.md](OPEN_ITEMS.md) for what is still unverified |
| 3: Versioning + editor | **Started**: native editor v1 and document templates (2026-11-10). See [what the editor delivers](#what-the-native-editor-v1-delivers) |
| 4–8: Workflow engine, publication, experience, hardening, go-live | Not started ([plan](docs/PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)) |

One document can now go through its whole life, thinly: create, upload, submit, approve, publish 1.0, find and download. Multi-stage workflows, scheduled publication, the native editor and previews come in later phases.

### What Phase 1 delivers
- **Sign-in with Entra ID** via a backend-for-frontend: authorization code + PKCE, state, nonce, RS256 ID-token validation, server-side PostgreSQL sessions, and no tokens in the browser.
- **Read-only Microsoft Graph sync** of the directory groups PAL uses, with membership history. Global roles are mapped to groups.
- **Granular, server-enforced permissions** with separation of duties: a System Administrator has no authority over documents. An **Owner** (superuser / god mode) role is available as the account's root of trust — granted only by another Owner, never through a directory group, and fully audited.
- **Append-only, hash-chained audit trail**: database triggers, least-privilege database roles, a chain verifier and an external anchor.
- **Health and readiness endpoints**, request correlation IDs, a JSON error contract, security headers, rate limits and a Host-header allowlist.
- **Branding**: logo by URL or upload, display name and accent colour, applied live and kept readable (WCAG AA) whatever colour is chosen.
- **Web client shell** in Vue 3 + Quasar: sign-in, dashboard, preferences, branding, role mappings.
- **Container image and deployment**: one container image (web / worker / migrate roles), Docker Compose for on-prem Linux, a Render reference config, and CI with tests, static analysis, dependency audits, a container scan and a secret scan.

### What Phase 2 delivers
- **Documents and revisions**: document types and departments (admin UI), numbers like `SOP-QA-0001`, one open draft per document, approved revisions immutable (enforced by database triggers).
- **Secure uploads**: size limit, content-based type allow-list (PDF, Word, Excel, PowerPoint, text) that must match the extension, **ClamAV scan that fails closed**, and a write-once, content-addressed file store on a filesystem/NAS volume (`PAL_BLOB_DIR`). Every attempt, including blocked ones, is recorded and audited.
- **Approval and publication (skeleton)**: one approver, separation of duties, each decision bound to a fingerprint (manifest hash) of the exact content and re-checked at release; immediate, idempotent release, with a database exclusion constraint guaranteeing one effective revision per document.
- **Access**: grants to people or directory groups, restricted documents, and 404 for anything a user may not see (drafts included), applied in SQL for lists and search.
- **Library**: full-text search over metadata and document numbers, filters, a document page with history, authorized download.
- **Local accounts** alongside Entra ID ([ADR-0014](docs/adr/ADR-0014-local-accounts.md)): username/password with argon2id, lockout for 15 minutes after 5 failures, throttling, forced change of temporary passwords, a Users admin page, and a first administrator created from environment variables at migrate time. Okta, Google Workspace and SAML appear on the sign-in page as "Not connected"; they are not implemented.
- **Operations**: `/health/ready` also checks that the blob directory is writable; Compose adds a ClamAV service and a blob volume; `render.yaml` deploys the full Phase 2 stack for demos.

### What the native editor v1 delivers
- **Write documents in PAL** instead of (or as well as) uploading a file: headings, bold/italic/underline/strikethrough, bulleted and numbered lists, quotes, divider lines, links and tables. Drafts save automatically ([ADR-0009](docs/adr/ADR-0009-authoring-native-editor-and-files.md#implementation-status-native-editor-v1-2026-11-10-decision-26), decision #26).
- **Document templates**: PAL administrators maintain templates per document type under *Administration → Document templates* (new ones start from a seven-section outline: Purpose, Scope, Definitions, Responsibilities, Procedure, Records, References). Authors pick one on *New document* and get their own editable copy.
- **Same controls as files**: content is validated against a server-side allow-list (no HTML, safe links only), freezes on submit, is covered by the approval fingerprint and re-verified at release, and is searchable. Readers and approvers see it formatted, read-only.
- **Who's editing**: when someone else is typing in the same draft, you see their name and since when (advisory; saves are still conflict-checked, so nothing is overwritten silently).
- **Not yet**: images in the editor, PDF export, comparison between revisions. See [OPEN_ITEMS.md](OPEN_ITEMS.md).

## Supported deployment models

| Target | Guide | Status |
|---|---|---|
| Local development | [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) | Primary for development |
| Single Linux server (Docker Compose) | [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) | **Selected production target (D2)** |
| Kubernetes | [deployments/KUBERNETES.md](deployments/KUBERNETES.md) | Reference |
| Azure (Commercial + Government) | [deployments/AZURE.md](deployments/AZURE.md) | Reference (not selected) |
| AWS (Commercial + GovCloud) | [deployments/AWS.md](deployments/AWS.md) | Reference (not selected) |
| Air-gapped | [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) | Reference (see limitations) |
| Render | [render.yaml](render.yaml), [DEPLOYMENT.md §12](docs/DEPLOYMENT.md#12-render-demo--evaluation) | Demo/evaluation only (not yet deployed; see below) |

### Deploying the demo on Render

`pal/render.yaml` is **not** referenced by the repository's root `render.yaml`, so nothing is created on Render until you do this deliberately. Every service in it is on a paid plan; check current prices first. Never load real organisational documents into a demo.

1. In the Render dashboard choose **New > Blueprint** and connect this repository.
2. Set **Blueprint Path** to `pal/render.yaml`.
3. Fill in the `sync: false` variables when prompted. On `pal-web`: `PAL_BASE_URL` and `DEFAULT_URI` (both `https://<your-service>.onrender.com`, no trailing slash), `TRUSTED_HOSTS` (`^<your-service>\.onrender\.com$`), `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` (e.g. `admin`) and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` (12+ characters, temporary). On `pal-worker`: `DEFAULT_URI`. Leave the `OIDC_*` and `PAL_BOOTSTRAP_ADMIN_OIDS` values empty unless you connect Entra ID.
4. Apply the Blueprint and wait. The `migrate` pre-deploy step creates the schema and the first administrator. ClamAV's first start downloads signatures for several minutes; uploads are refused until it is up.
5. Check that `https://<your-service>.onrender.com/health/ready` shows `"storage":"ok"`, then sign in with the bootstrap username and password. PAL makes you choose a new password before anything else.
6. **Delete** `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` from `pal-web`.

The known limits of this topology (single database role, single web instance because of the disk, unverified disk ownership) are listed in [DEPLOYMENT.md §12](docs/DEPLOYMENT.md#12-render-demo--evaluation) and [OPEN_ITEMS.md](OPEN_ITEMS.md).

The engineering guides are [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) and [docs/SECURITY.md](docs/SECURITY.md).

## Repository layout

```
pal/
├── backend/            Symfony 7.4 LTS application (PHP 8.4+)
│   ├── src/
│   │   ├── Platform/   HTTP plumbing, health, logging, typed row access, scheduler
│   │   ├── Audit/      hash-chained audit trail, verifier, anchor
│   │   ├── Identity/   OIDC + local sign-in, sessions, CSRF, roles & permissions, users admin, Graph sync
│   │   ├── Settings/   organisation settings and branding
│   │   ├── Storage/    upload pipeline, ClamAV client, content-addressed blob store
│   │   └── Documents/  documents, revisions, approval, publication, grants, library
│   ├── migrations/     Doctrine migrations (authoritative schema installer)
│   ├── config/         Symfony configuration
│   └── tests/          PHPUnit: Unit/, Integration/, Functional/
├── frontend/           Vue 3 + TypeScript + Quasar single-page app
├── database/schema.sql idempotent full schema (manual-setup reference; parity-tested)
├── deploy/             nginx, PHP-FPM, supervisord, entrypoint, Compose, SQL roles
├── Dockerfile          one image: web | worker | migrate | console
├── render.yaml         Render reference Blueprint
├── docs/               architecture proposal, ADRs, decision log, traceability, guides
└── deployments/        one operator guide per deployment target
```

## Technology

| Layer | Choice | Why (see ADRs) |
|---|---|---|
| Backend | PHP 8.4+, Symfony 7.4 LTS, Doctrine DBAL (no ORM) | [ADR-0008](docs/adr/ADR-0008-php-symfony.md), [ADR-0013](docs/adr/ADR-0013-phase1-implementation-decisions.md) |
| Frontend | Vue 3, TypeScript (strict), Quasar, Pinia, Vue Router, Vite | [ADR-0003](docs/adr/ADR-0003-frontend-framework.md) |
| Database | PostgreSQL 17 (16+ supported) | [ADR-0005](docs/adr/ADR-0005-database-and-search.md) |
| Identity | Entra ID (OIDC) for sign-in and read-only Microsoft Graph for group sync; local username/password accounts | [ADR-0004](docs/adr/ADR-0004-authentication-bff.md), [ADR-0010](docs/adr/ADR-0010-group-based-access.md), [ADR-0014](docs/adr/ADR-0014-local-accounts.md) |
| File storage | Content-addressed, write-once filesystem/NAS store; ClamAV (`clamd`, INSTREAM) malware scanning | [ADR-0006](docs/adr/ADR-0006-immutable-content-addressed-storage.md) |
| Jobs | Symfony Messenger (PostgreSQL transport) + Scheduler | [ADR-0001](docs/adr/ADR-0001-modular-monolith.md) |
| Logging | JSON to stderr; `pal_audit` channel for Microsoft Sentinel | [ADR-0011](docs/adr/ADR-0011-logging-to-microsoft-sentinel.md) |
| Runtime | nginx + PHP-FPM in one non-root container | [ADR-0013](docs/adr/ADR-0013-phase1-implementation-decisions.md) |

## Prerequisites (development)

- **PHP 8.4+** with extensions `pdo_pgsql`, `intl`, `ctype`, `iconv`, `openssl`, `sodium`, `mbstring`, `xml`
- **Composer 2**
- **Node.js 22+** (24 LTS recommended) and npm
- **PostgreSQL 16+** (migration 2 creates the `btree_gist` and `pg_trgm` extensions; both ship with standard PostgreSQL builds)
- PHP `fileinfo` extension (libmagic type detection; enabled by default in PHP builds)
- Optional: a ClamAV daemon (`clamd`) to test real scanning. Without one, set `PAL_ALLOW_UNSCANNED_UPLOADS=1` (development only; ignored in production)
- Optional: Docker (to build the image and run the Compose stack with ClamAV; CI builds the image on every change)

## Quick start (local development)

```bash
# 1. Database (a dev-only role; never use these credentials elsewhere)
psql -d postgres -c "CREATE ROLE pal LOGIN PASSWORD 'pal'"
psql -d postgres -c "CREATE DATABASE pal_dev OWNER pal"
psql -d postgres -c "CREATE DATABASE pal_test OWNER pal"

# 2. Backend
cd pal/backend
cp .env.example .env                      # before composer install: its scripts need .env
composer install
printf 'APP_SECRET=%s\n' "$(openssl rand -hex 32)" > .env.local
php bin/console doctrine:migrations:migrate --no-interaction
php -S 127.0.0.1:8000 -t public          # API on :8000

# 3. Frontend (second terminal)
cd pal/frontend
npm ci
npm run dev                               # http://localhost:5173 (proxies /api, /auth, /health)
```

To sign in without Entra and upload without ClamAV, add development values to `.env.local` and create the first local administrator (from `pal/backend`):

```bash
cat >> .env.local <<'ENV'
PAL_BLOB_DIR=/absolute/path/to/pal/backend/var/blobs
PAL_ALLOW_UNSCANNED_UPLOADS=1             # development only; ignored when APP_ENV=prod
PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME=admin
PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD=choose-a-temporary-passphrase
ENV
php bin/console pal:users:bootstrap-admin   # creates the account only if no local account exists
```

Sign in at `http://localhost:5173` and choose a new password when asked, then remove the two bootstrap lines. Entra sign-in additionally needs an app registration: set `OIDC_TENANT_ID`, `OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET` in `.env.local`, with the redirect URI `http://localhost:5173/auth/callback`; until then the sign-in page shows Entra as "Not connected". Full walkthrough: [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md).

## Common commands

| Task | Command (from `pal/backend` unless noted) |
|---|---|
| Run all backend tests | `php vendor/bin/phpunit` (rebuilds `pal_test` from migrations) |
| Static analysis | `php bin/console cache:warmup && vendor/bin/phpstan analyse && vendor/bin/phpstan analyse -c phpstan.tests.neon` |
| Module boundaries | `vendor/bin/deptrac analyse` |
| Dependency audit | `composer audit` · `npm audit` (in `pal/frontend`) |
| Apply migrations | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Verify the audit chain | `php bin/console pal:audit:verify [--anchor]` |
| Verify blob storage (exists + SHA-256) | `php bin/console pal:storage:verify` |
| Reclaim unreferenced blobs | `php bin/console pal:storage:gc [--delete] [--older-than=24h]` (dry-run without `--delete`) |
| Report never-attached uploads (read-only) | `php bin/console pal:storage:unattached [--older-than=30d] [--fail-over-bytes=N]` |
| Reclaim never-attached uploads (then run GC) | `php bin/console pal:storage:unattached --delete [--older-than=30d]` (deletes upload + stored_file rows; blob freed by `pal:storage:gc --delete`) |
| Sync directory groups now | `php bin/console pal:directory:sync` |
| Map a group to a role | `php bin/console pal:roles:map PUBLISHER <group-object-id>` |
| Create the first local Owner | `php bin/console pal:users:bootstrap-admin` (reads `PAL_BOOTSTRAP_LOCAL_ADMIN_*`; no-op once any local account exists) |
| Designate an Owner (superuser) | `php bin/console pal:users:grant-owner <username>` (add `--revoke` to remove; audited) |
| Clear a sign-in lockout | `php bin/console pal:users:unlock <username>` |
| Run the worker | `php bin/console messenger:consume async scheduler_default` |
| Frontend checks | `npm run lint && npm run typecheck && npm test && npm run build` (in `pal/frontend`) |
| Build the image | `docker build -t pal pal/` |
| Run on one host | `docker compose -f pal/deploy/compose.yaml --profile bundled-db up -d` (includes `clamd`) |

## Package dependencies

**Backend runtime** (`backend/composer.json`): `symfony/*` 7.4 (framework-bundle, security-bundle, security-csrf, messenger, doctrine-messenger, scheduler, monolog-bundle, http-client, validator, rate-limiter, uid, lock, clock, console, dotenv, runtime, yaml), `doctrine/dbal` 4, `doctrine/doctrine-bundle`, `doctrine/doctrine-migrations-bundle`, `firebase/php-jwt` 7.
**Backend dev:** `phpunit/phpunit` 13, `phpstan/phpstan` 2 + `phpstan/phpstan-symfony`, `deptrac/deptrac` 4, `symfony/browser-kit`, `symfony/css-selector`.
**Frontend runtime** (`frontend/package.json`, exact versions pinned): `vue`, `quasar`, `@quasar/extras`, `pinia`, `vue-router`, and the editor packages `@tiptap/vue-3`, `@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extension-table` (3.32.0, MIT; `@floating-ui/dom` arrives as their peer).
**Frontend dev:** `vite`, `@vitejs/plugin-vue`, `@quasar/vite-plugin`, `typescript` 6.0, `vue-tsc`, `eslint` + `eslint-plugin-vue` + `@vue/eslint-config-typescript` + `typescript-eslint`, `vitest`, `@vue/test-utils`, `jsdom`, `sass-embedded`, `axe-core`, `@types/node`.
**PHP extensions in the image:** `pdo_pgsql`, `intl`, `opcache` (plus the PHP image defaults, which include `fileinfo`).
**PostgreSQL extensions:** `btree_gist`, `pg_trgm` (created by migration `Version20261026000002`; trusted extensions, so the database owner can create them).
**Companion containers:** `clamav/clamav:1.5.4` (malware scanning; Compose and Render), `postgres:17-alpine` (optional bundled database).
**Container packages:** `nginx`, `supervisor`, `tini`, `libpq`, `icu-libs` (Alpine).

## Project records

[Architecture proposal](docs/PHASE0_ARCHITECTURE_PROPOSAL.md) · [ADRs](docs/adr/) · [Decision log](docs/DECISION_LOG.md) · [Requirements traceability](docs/REQUIREMENTS_TRACEABILITY.md) · [Open items](OPEN_ITEMS.md) · [Project rules](CLAUDE.md)

> This repository is public. It contains no organisation-specific configuration; real tenant IDs, hostnames and document content must never be committed. PAL moves to a private company repository at handover (proposal §18.3).
