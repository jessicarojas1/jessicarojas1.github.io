# ORRERY — Deployment Guide

> Discovery-phase. The discovery microsite deploys today; database, identity, and
> integrations are Phase 1 (see `../OPEN_ITEMS.md`).

## Contents

1. Deployment models
2. Prerequisites
3. Configuration & secrets
4. Database migrations
5. Background/worker process
6. Self-hosted LLM (Ollama) — future
7. GPU acceleration — future
8. Production checklist

## 1. Deployment models

| Model | Boundary | Status |
|-------|----------|--------|
| Local dev | workstation | Ready — see `../deployments/LOCAL_DEVELOPMENT.md` |
| Render (Docker) | Commercial (non-CUI) | Ready — `../render.yaml` |
| Single Linux server | as authorized | Tracked |
| Kubernetes | enclave/prod | Tracked |
| Azure / AWS (Commercial + Gov) | GCC High for CUI | Tracked |
| Air-gapped | enclave | Tracked |

## 2. Prerequisites

- Docker (or PHP 8.2 + Composer for non-container runs).
- Phase 1+: PostgreSQL 14+, an Entra ID app registration, SharePoint site(s).

## 3. Configuration & secrets

Provide configuration via environment variables. **Never commit secrets**; use
Render secret files, Key Vault, or Secrets Manager.

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `discovery` / `production` | Runtime mode |
| `PORT` | `8080` | Listen port (set by host) |
| `DATABASE_URL` (P1) | `postgres://…` | Portal database |
| `ENTRA_TENANT_ID` (P1) | GUID | Entra tenant |
| `ENTRA_CLIENT_ID` (P1) | GUID | App registration |
| `ENTRA_CLIENT_SECRET` (P1) | secret | OIDC client secret |
| `GRAPH_SCOPES` (P1) | `Sites.Read.All Files.Read.All` | Graph permissions |

## 4. Database migrations

Phase 1 ships an installer + migrations. Until then, the current combined design
schema is `../database/schema.sql` (PostgreSQL 10+):

```bash
psql "$DATABASE_URL" -f database/schema.sql
```

## 5. Background/worker process

Phase 2 introduces a worker for notifications/digests and scheduled
publish/expire of announcements. Not present in discovery.

## 6. Self-hosted LLM (Ollama) — future

Phase 5 permission-aware assistant runs an in-boundary model (e.g. Ollama);
no hosted AI API in CUI environments. Documented when built.

## 7. GPU acceleration — future

Applicable only to the Phase 5 LLM workload; documented when built.

## 8. Production checklist

**Secrets & identity** — secrets from a manager, MFA enforced, least-privilege
app registration, no static keys in source.
**Transport & exposure** — TLS only, HSTS, strict CSP + nonce, WAF, non-root container.
**Hardening** — CSRF on writes, parameterized queries, input validation,
server-side authorization on every request and search result.
**Resilience & operations** — health checks, backups + tested restore, structured
logs, audit retention (heightened for external access), alerting.
