# REDOUBT — GMRE Program Portal Framework

> **Status: Discovery Package + Phase 1 skeleton.** This directory ships the
> **Product & Architecture Discovery Package** (served at `/`) plus a **Phase 1
> framework skeleton**: Entra GCC High OIDC sign-in, the authorization policy
> engine (program × company × role × zone + US-person export gate), audit logging,
> a Microsoft Graph client, a permission-aware REST API (`/api/v1`), and signed
> webhooks. **All 7 MVP modules built:** two-pane **IAM console**, **Announcements**,
> **Documents** (zone-gated with the US-person export gate), **Task Orders**
> (company-scoped, award webhook), **Jobs** (draft→post webhook), **Directory**
> (visibility-trimmed), and **Search** (global, permission-trimmed) — each with a
> permission-aware `/api/v1` endpoint. **Verified on PHP 8.5 + PostgreSQL 16:** lint
> passes; the authorization engine passed 15/15 logic checks and the data path
> 12/12 + 17/17 + 13/13 against a live DB (incl. CUI/ITAR export gating, company
> isolation, and search non-leakage); only live Entra GCC High sign-in and live
> Graph document resolve remain unexercised (need real credentials). See [`OPEN_ITEMS.md`](OPEN_ITEMS.md). The `Oidc` token
> verification must be security-reviewed before production sign-in.

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

See also: [`docs/`](docs) and [`deployments/`](deployments).
