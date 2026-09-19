# ORRERY — Architecture

> Discovery-phase. Describes the intended architecture; only the discovery
> microsite + scaffold are implemented today (see `../OPEN_ITEMS.md`).

## Platform

ORRERY is a **PHP presentation/orchestration layer** over enterprise systems of
record. It aggregates and links; it does not duplicate authoritative data.

- **Runtime:** PHP 8.2 (PSR-4, `Orrery\` namespace), Apache in the container.
- **Portal data:** PostgreSQL (config, portal-owned content, metadata/refs, audit).
- **Document SoR:** Microsoft 365 / SharePoint Online via Microsoft Graph.
- **Identity:** Microsoft Entra ID (OIDC SSO, MFA, B2B guests, Conditional Access).

## Design principles

Secure by design · configuration over custom code · authoritative systems of
record · role-based experiences · program & company isolation · low admin
burden · self-service content management · reusability/modularity ·
auditability · maintainability.

## Component overview

| Component | Responsibility |
|-----------|----------------|
| Front controller (`public/index.php`) | Routing, security headers, CSP nonce |
| AuthN (Phase 1) | Entra OIDC + MFA |
| AuthZ policy engine (Phase 1) | Authorize every request/result by (program × company × role × zone) |
| Modules (Phase 1) | Announcements, documents, task orders, jobs, directory, admin |
| Graph client (Phase 1) | Read/browse SharePoint documents |
| Audit logger (Phase 1) | Append-only record of access/admin/publish/provision/revoke |

## Monorepo placement & internal layout

Lives in the `jessicarojas1.github.io` repo under `orrery/`. Internal layout:
`public/` (web root), `app/` (`Views/`, `Support/`), `database/`, `docs/`,
`deployments/`, plus `Dockerfile`, `render.yaml`, `composer.json`.

## Configuration model

Environment variables (never committed) drive runtime; per-program behavior is
data in `program` + `program_config` (JSONB), enabling new programs by
configuration. See `render.yaml` for the variable surface.

## Request & error contract

- All non-file requests route through the front controller.
- `/health` returns JSON `{status, service, phase, time}`.
- Unknown routes return 404 (discovery serves only `/` and `/health`).
- Phase 1: writes require CSRF tokens; every response authorized server-side.

## Security model

See [`SECURITY.md`](SECURITY.md). Summary: least privilege, MFA, program/company
isolation enforced at the query, immutable audit, strict CSP + nonce, secrets
from environment/secret manager.

## Observability

Structured logs, health checks, and (Phase 1) an append-only audit trail with
alerting on authentication anomalies.

## Deployment topology

Container (Docker) behind a TLS reverse proxy. Stateless app scales
horizontally; PostgreSQL is the stateful tier. Non-CUI pilots may run on Render;
CUI production runs in an authorized Gov/enclave boundary.
