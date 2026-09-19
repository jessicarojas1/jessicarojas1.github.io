# REDOUBT — GMRE Program Portal Framework

> **Status: Discovery / Pre-decisional.** This directory currently ships a
> **Product & Architecture Discovery Package** served as a small PHP web
> application. **No program data, authentication, or content-management
> features are implemented yet** — those are Phase 1 (MVP). See
> [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and [`OPEN_ITEMS.md`](OPEN_ITEMS.md).

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
- **Compliance boundary:** built to hold controlled data up to **CUI** and **ITAR/export-controlled** — baseline **GCC High + a Gov-cloud (or air-gapped) enclave**, US-person access gating, FIPS-validated crypto.
- **Delivery:** **Docker**; commercial **Render** for the non-CUI discovery/pilot only; controlled-data instances run in the authorized Gov-cloud enclave / Kubernetes.

## Supported deployment models

| Model | Guide | Notes |
|------|-------|-------|
| Local development | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) | PHP built-in server or Docker |
| Render (Docker) | [`render.yaml`](render.yaml) | Non-CUI pilot boundary only |
| Single Linux server | _tracked_ (OPEN_ITEMS) | — |
| Kubernetes | _tracked_ (OPEN_ITEMS) | Enclave path |
| Azure (Commercial + Gov) | _tracked_ (OPEN_ITEMS) | GCC High for CUI |
| AWS (Commercial + GovCloud) | _tracked_ (OPEN_ITEMS) | — |
| Air-gapped | _tracked_ (OPEN_ITEMS) | Offline + self-hosted LLM |

## Repository layout

```
redoubt/
├─ public/
│  └─ index.php          Front controller (routes '/', '/health'; sets CSP/headers)
├─ app/
│  ├─ Views/
│  │  └─ discovery.php   The Discovery Package microsite (this deliverable)
│  └─ Support/           (Phase 1: Security, Auth, Graph client, AuthZ engine)
├─ database/
│  └─ schema.sql         Initial idempotent portal schema (design; pending decisions)
├─ docs/                 Architecture, Deployment, Disaster Recovery, Security
├─ deployments/          Operator guides (LOCAL_DEVELOPMENT present; others tracked)
├─ Dockerfile            Multi-stage, non-root, healthcheck
├─ render.yaml           Render Blueprint (discovery/non-CUI)
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
