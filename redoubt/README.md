# REDOUBT — GMRE Program Portal Framework

> **Status: working application (standalone).** REDOUBT runs with **only a
> PostgreSQL database** — **built-in local email/password accounts** and a
> **first-run setup** (`/setup`) mean no Entra or external API is required; Entra
> SSO and Microsoft Graph/SharePoint are **optional enhancements**. Working modules:
> **IAM console, Announcements, Documents** (CUI/ITAR zone gating), **Task Orders,
> Jobs** (program/company/contract targeting), **Directory, Search, Notifications,
> Content Administration, Settings/Branding, and Onboarding/offboarding** — each
> with a permission-aware `/api/v1` endpoint and an append-only audit trail.
> **Verified on PHP 8.5 + PostgreSQL 16:** an **82-check suite** plus an end-to-end
> HTTP flow (setup → local login → app → sample data) pass. The optional `Oidc`
> (Entra) token verification should be security-reviewed before enabling SSO.
> See [`OPEN_ITEMS.md`](OPEN_ITEMS.md).
>
> **Run it:** set `DATABASE_URL`, open `/setup`, create your admin (optionally load
> sample data), sign in. On Render, the blueprint provisions the database for you.

## What it is

REDOUBT is a **reusable, secure program portal framework** for proposal/program
execution — a single "one-stop shop" entry point for prime staff,
subcontractors, and (where contractually permitted) the Government customer.
Like its namesake (a mechanical model in which many bodies orbit a shared core),
**one framework** powers **many isolated program instances**.

## Why it exists

Coordination cost grows with every added subcontractor. Today it runs through
email, calls, Teams, and ad-hoc file shares, overloading the PM and risking
cross-company disclosure. REDOUBT replaces that with a governed, role-aware hub
over the existing systems of record.

## Architecture at a glance

- **Presentation/orchestration:** custom **PHP 8.2** application (this repo).
- **Document system of record:** **Microsoft 365 / SharePoint Online** via Microsoft Graph (no duplication).
- **Identity:** **Microsoft Entra ID** (OIDC SSO, MFA, B2B guests, Conditional Access).
- **Portal data:** **PostgreSQL** (config, portal-owned content, metadata, audit).
- **Compliance boundary:** portable across three authorized boundaries — **on-prem**, **Azure Government**, **AWS GovCloud** — with identity + documents always in **Microsoft 365 / Azure GCC High** (endpoints `login.microsoftonline.us`, `graph.microsoft.us`, `*.sharepoint.us`). Supports **CUI** and **ITAR/export-controlled** data with US-person access gating and FIPS-validated crypto.
- **Delivery:** **Docker**; **Kubernetes is the primary runtime** (one image → on-prem / AKS Azure Gov / EKS AWS GovCloud); single hardened Linux host is the fallback. Commercial **Render** hosts only the non-CUI discovery microsite.

## Supported deployment models

| Model | Guide | Notes |
|------|-------|-------|
| Local development | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) | PHP built-in server or Docker |
| Render (Docker) | [`render.yaml`](render.yaml) | Non-CUI discovery microsite only |
| **Kubernetes (primary)** | [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) | **Production, HA — on-prem / AKS / EKS** |
| M365 GCC High + Azure Gov (AKS) | [`deployments/AZURE.md`](deployments/AZURE.md) | Back-end config (all targets) + AKS hosting |
| AWS GovCloud (EKS) | [`deployments/AWS.md`](deployments/AWS.md) | Cross-cloud hosting → M365 GCC High |
| Single Linux server (fallback) | [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) | Small footprint / no cluster |
| Air-gapped | _tracked_ (OPEN_ITEMS) | Offline + self-hosted LLM |

## Repository layout

```
redoubt/
├─ public/
│  └─ index.php          Front controller: /, /health, /auth/*, /app, /api/*
├─ app/
│  ├─ bootstrap.php      PSR-4 autoload (Composer or built-in) + env loading
│  ├─ Support/           Framework services (Redoubt\Support\*):
│  │                     Config, Session, Security, Db, Roles, Authorize,
│  │                     Oidc, Auth, Graph, Audit, ApiKey, Webhooks
│  ├─ Http/              AuthController, AppController, ApiRouter
│  └─ Views/             discovery.php (microsite), app_home.php (authed shell)
├─ database/
│  └─ schema.sql         Idempotent schema (IAM grants, API clients, webhooks…)
├─ docs/                 Architecture, Deployment, Disaster Recovery, Security
├─ deployments/          KUBERNETES (primary), AZURE, AWS, SINGLE_LINUX_SERVER, AIRGAPPED, LOCAL_DEVELOPMENT
├─ Dockerfile            Multi-stage, non-root, healthcheck, pdo_pgsql
├─ render.yaml           Render Blueprint (non-CUI discovery only)
├─ .env.example          Config template (GCC High endpoints)
├─ composer.json         PSR-4 autoload (Redoubt\)
└─ README.md
```

## Technology & prerequisites

- PHP 8.2+ (with Composer)
- Docker (optional, for container runs)
- PostgreSQL 14+ (Phase 1+)
- A Microsoft 365 tenant + Entra ID app registration (Phase 1+)

## Quick start (local)

```bash
cd redoubt
php -S 0.0.0.0:8080 -t public
# open http://localhost:8080  ·  health: http://localhost:8080/health
```

Or with Docker:

```bash
cd redoubt
docker build -t redoubt:discovery .
docker run --rm -p 8080:8080 redoubt:discovery
```

## Common commands

| Command | Purpose |
|---------|---------|
| `php -S 0.0.0.0:8080 -t public` | Run the discovery site locally |
| `docker build -t redoubt:discovery .` | Build the container |
| `curl -fsS localhost:8080/health` | Health check (JSON) |
| `php tests/run.php` | Run the test suite (logic always; DB tests need `REDOUBT_TEST_DB=1` + `DATABASE_URL`) |

## Testing & CI

Zero-dependency suite: `php tests/run.php` runs 44 checks — 15 authorization-engine
logic tests plus 29 live-database module assertions (announcements, IAM grants,
documents with the CUI/ITAR export gate, task orders, jobs, directory, and
permission-trimmed search). DB tests self-skip unless `REDOUBT_TEST_DB=1` and a
throwaway `DATABASE_URL` are set. `.github/workflows/redoubt-ci.yml` lints every
PHP file and runs the full suite against a PostgreSQL 16 service on every change
under `redoubt/`.

```bash
cd redoubt
DATABASE_URL=postgres://redoubt@127.0.0.1:5432/redoubt REDOUBT_TEST_DB=1 php tests/run.php
```

See also: [`docs/`](docs) and [`deployments/`](deployments).
