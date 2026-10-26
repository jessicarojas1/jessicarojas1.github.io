# PAL — Enterprise Process Asset Library

[![PAL CI](https://github.com/jessicarojas1/jessicarojas1.github.io/actions/workflows/pal-ci.yml/badge.svg)](https://github.com/jessicarojas1/jessicarojas1.github.io/actions/workflows/pal-ci.yml)

PAL is an enterprise **document management and document control** system for an organisation's process assets: policies, processes, procedures, standards, work instructions, templates and forms. It is built for organisations working to ISO 27001, CMMI Level 3 and CMMC Level 2, where an auditor must be able to see who approved exactly which content, when it took effect, and what was in force on a given date.

**Scope is document management only.** Training, CAPA, nonconformance, risk, audit management and acknowledgement campaigns are explicitly out of scope.

## Status

| Phase | State |
|---|---|
| 0: Discovery & architecture | **Approved** (rev 2, 2026-10-25) |
| 1: Foundation | **Implemented**. See [what Phase 1 delivers](#what-phase-1-delivers) and [OPEN_ITEMS.md](OPEN_ITEMS.md) |
| 2–8: Repository, versioning, workflow, publication, experience, hardening, go-live | Not started ([plan](docs/PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)) |

There is no document repository yet. Phase 1 is the secure base everything else is built on.

### What Phase 1 delivers
- **Sign-in with Entra ID** via a backend-for-frontend: authorization code + PKCE, state, nonce, RS256 ID-token validation, server-side PostgreSQL sessions, and no tokens in the browser.
- **Read-only Microsoft Graph sync** of the directory groups PAL uses, with membership history. Global roles are mapped to groups.
- **Granular, server-enforced permissions** with separation of duties: a System Administrator has no authority over documents.
- **Append-only, hash-chained audit trail**: database triggers, least-privilege database roles, a chain verifier and an external anchor.
- **Health and readiness endpoints**, request correlation IDs, a JSON error contract, security headers, rate limits and a Host-header allowlist.
- **Branding**: logo by URL or upload, display name and accent colour, applied live and kept readable (WCAG AA) whatever colour is chosen.
- **Web client shell** in Vue 3 + Quasar: sign-in, dashboard, preferences, branding, role mappings.
- **Container image and deployment**: one container image (web / worker / migrate roles), Docker Compose for on-prem Linux, a Render reference config, and CI with tests, static analysis, dependency audits, a container scan and a secret scan.

## Supported deployment models

| Target | Guide | Status |
|---|---|---|
| Local development | [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md) | Primary for development |
| Single Linux server (Docker Compose) | [deployments/SINGLE_LINUX_SERVER.md](deployments/SINGLE_LINUX_SERVER.md) | **Selected production target (D2)** |
| Kubernetes | [deployments/KUBERNETES.md](deployments/KUBERNETES.md) | Reference |
| Azure (Commercial + Government) | [deployments/AZURE.md](deployments/AZURE.md) | Reference (not selected) |
| AWS (Commercial + GovCloud) | [deployments/AWS.md](deployments/AWS.md) | Reference (not selected) |
| Air-gapped | [deployments/AIRGAPPED.md](deployments/AIRGAPPED.md) | Reference (see limitations) |
| Render | [render.yaml](render.yaml) | Demo/evaluation only |

The engineering guides are [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md), [docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md) and [docs/SECURITY.md](docs/SECURITY.md).

## Repository layout

```
pal/
├── backend/            Symfony 7.4 LTS application (PHP 8.4+)
│   ├── src/
│   │   ├── Platform/   HTTP plumbing, health, logging, typed row access, scheduler
│   │   ├── Audit/      hash-chained audit trail, verifier, anchor
│   │   ├── Identity/   OIDC sign-in, sessions, CSRF, roles & permissions, Graph sync
│   │   └── Settings/   organisation settings and branding
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
| Identity | Entra ID (OIDC) for sign-in, read-only Microsoft Graph for group sync | [ADR-0004](docs/adr/ADR-0004-authentication-bff.md), [ADR-0010](docs/adr/ADR-0010-group-based-access.md) |
| Jobs | Symfony Messenger (PostgreSQL transport) + Scheduler | [ADR-0001](docs/adr/ADR-0001-modular-monolith.md) |
| Logging | JSON to stderr; `pal_audit` channel for Microsoft Sentinel | [ADR-0011](docs/adr/ADR-0011-logging-to-microsoft-sentinel.md) |
| Runtime | nginx + PHP-FPM in one non-root container | [ADR-0013](docs/adr/ADR-0013-phase1-implementation-decisions.md) |

## Prerequisites (development)

- **PHP 8.4+** with extensions `pdo_pgsql`, `intl`, `ctype`, `iconv`, `openssl`, `sodium`, `mbstring`, `xml`
- **Composer 2**
- **Node.js 22+** (24 LTS recommended) and npm
- **PostgreSQL 16+**
- Optional: Docker (to build the image; CI builds it on every change)

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

Sign-in needs an Entra app registration: set `OIDC_TENANT_ID`, `OIDC_CLIENT_ID` and `OIDC_CLIENT_SECRET` in `.env.local`, with the redirect URI `http://localhost:5173/auth/callback`. Until then, the sign-in page reports that sign-in is unavailable. Everything else (health, branding, tests) works without it. Full walkthrough: [deployments/LOCAL_DEVELOPMENT.md](deployments/LOCAL_DEVELOPMENT.md).

## Common commands

| Task | Command (from `pal/backend` unless noted) |
|---|---|
| Run all backend tests | `php vendor/bin/phpunit` (rebuilds `pal_test` from migrations) |
| Static analysis | `php bin/console cache:warmup && vendor/bin/phpstan analyse && vendor/bin/phpstan analyse -c phpstan.tests.neon` |
| Module boundaries | `vendor/bin/deptrac analyse` |
| Dependency audit | `composer audit` · `npm audit` (in `pal/frontend`) |
| Apply migrations | `php bin/console doctrine:migrations:migrate --no-interaction` |
| Verify the audit chain | `php bin/console pal:audit:verify [--anchor]` |
| Sync directory groups now | `php bin/console pal:directory:sync` |
| Map a group to a role | `php bin/console pal:roles:map PUBLISHER <group-object-id>` |
| Run the worker | `php bin/console messenger:consume async scheduler_default` |
| Frontend checks | `npm run lint && npm run typecheck && npm test && npm run build` (in `pal/frontend`) |
| Build the image | `docker build -t pal pal/` |
| Run on one host | `docker compose -f pal/deploy/compose.yaml --profile bundled-db up -d` |

## Package dependencies

**Backend runtime** (`backend/composer.json`): `symfony/*` 7.4 (framework-bundle, security-bundle, security-csrf, messenger, doctrine-messenger, scheduler, monolog-bundle, http-client, validator, rate-limiter, uid, lock, clock, console, dotenv, runtime, yaml), `doctrine/dbal` 4, `doctrine/doctrine-bundle`, `doctrine/doctrine-migrations-bundle`, `firebase/php-jwt` 7.
**Backend dev:** `phpunit/phpunit` 13, `phpstan/phpstan` 2 + `phpstan/phpstan-symfony`, `deptrac/deptrac` 4, `symfony/browser-kit`, `symfony/css-selector`.
**Frontend runtime** (`frontend/package.json`, exact versions pinned): `vue`, `quasar`, `@quasar/extras`, `pinia`, `vue-router`.
**Frontend dev:** `vite`, `@vitejs/plugin-vue`, `@quasar/vite-plugin`, `typescript` 6.0, `vue-tsc`, `eslint` + `eslint-plugin-vue` + `@vue/eslint-config-typescript` + `typescript-eslint`, `vitest`, `@vue/test-utils`, `jsdom`, `sass-embedded`, `axe-core`, `@types/node`.
**PHP extensions in the image:** `pdo_pgsql`, `intl`, `opcache` (plus the PHP image defaults).
**Container packages:** `nginx`, `supervisor`, `tini`, `libpq`, `icu-libs` (Alpine).

## Project records

[Architecture proposal](docs/PHASE0_ARCHITECTURE_PROPOSAL.md) · [ADRs](docs/adr/) · [Decision log](docs/DECISION_LOG.md) · [Requirements traceability](docs/REQUIREMENTS_TRACEABILITY.md) · [Open items](OPEN_ITEMS.md) · [Project rules](CLAUDE.md)

> This repository is public. It contains no organisation-specific configuration; real tenant IDs, hostnames and document content must never be committed. PAL moves to a private company repository at handover (proposal §18.3).
