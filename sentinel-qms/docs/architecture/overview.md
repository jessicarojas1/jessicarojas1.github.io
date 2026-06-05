# Architecture Overview

This document describes the Sentinel QMS architecture using the **C4 model** (System Context →
Containers → Components), summarizes the technology stack, walks the end-to-end request lifecycle, and
explains the multi-tenant model. Rendered Mermaid diagrams live in
[architecture-diagram.md](architecture-diagram.md).

---

## 1. System Context (C4 — Level 1)

Sentinel QMS is a self-contained quality system. It does not depend on any third-party SaaS to operate
inside a sovereign cloud boundary. Its external relationships are:

| Actor / System | Interaction |
|----------------|-------------|
| **Quality personnel** (Quality Manager, Engineer, Auditor, Operator, Read-Only) | Use the React SPA over TLS to manage quality records |
| **Supplier Quality users / external suppliers** | Submit SCAR responses, FAI packages, and corrective actions through scoped supplier access |
| **Enterprise Identity Provider** (Azure AD / Entra ID Gov, Okta, ADFS, DoD ICAM) | Federated SSO via OIDC or SAML; CAC/PIV smartcard authentication |
| **Email / notification relay** (SES Gov / Azure Communication Services) | Outbound workflow notifications (due dates, approvals, escalations) |
| **Object storage** (S3 in GovCloud / Azure Blob in Azure Gov) | Stores controlled documents, FAI attachments, evidence |
| **SIEM / log aggregation** (CloudWatch + Security Hub / Azure Monitor + Sentinel) | Receives application, audit, and infrastructure logs |

The platform's **trust boundary** is the cloud account/subscription plus the Kubernetes namespace it
runs in. All ingress terminates TLS at a load balancer inside a FIPS-validated boundary; all CUI and
export-controlled data remain inside the GovCloud/Azure Gov region.

---

## 2. Containers (C4 — Level 2)

| Container | Technology | Responsibility |
|-----------|------------|----------------|
| **Web SPA** | React + TypeScript, served by nginx | Renders the UI, enforces the CUI banner, holds no long-lived secrets; talks only to the API |
| **API service** | Python 3.12 + FastAPI (Uvicorn workers under Gunicorn) | Stateless REST API at `/api/v1`; authentication, authorization, business logic, validation, audit emission |
| **Relational database** | PostgreSQL 16 | System of record for all quality data, the immutable audit log, and e-signature manifests |
| **Object store** | S3 (GovCloud) / Azure Blob (Azure Gov) | Binary artifacts: controlled documents, drawings, FAI evidence, attachments |
| **Identity broker** | Pluggable OIDC / SAML / CAC-PIV adapter inside the API | Validates federated assertions and smartcard certificates; issues internal JWTs |
| **Background worker** | FastAPI/Celery-compatible task runner | Scheduled jobs: calibration-due scans, training-expiry checks, KPI rollups, notification dispatch, retention sweeps |
| **Reverse proxy / ingress** | nginx ingress controller + cloud LB | TLS termination, routing, rate limiting, security headers |

### Component view of the API service (C4 — Level 3)

```
app/
├── api/         FastAPI routers (one router per module + auth)
├── core/        config.py (settings), database.py (engine/session), security primitives
├── models/      SQLAlchemy ORM models (tables)
├── schemas/     Pydantic request/response models (validation + serialization)
├── services/    Domain/business logic (workflow state machines, numbering, scoring)
└── tests/       Unit + integration tests (pytest, httpx)
```

The API layers responsibilities cleanly:

1. **Router** — HTTP concerns, dependency injection of the current user, request/response schemas.
2. **Service** — domain rules (e.g., NCR disposition state machine, CAPA 8D gating, supplier rating math).
3. **Model / Repository** — persistence via SQLAlchemy 2.0 with parameterized queries only.
4. **Cross-cutting** — auth dependency, RBAC permission checks, audit emission, and e-signature capture
   are applied as FastAPI dependencies / decorators so every mutating endpoint is covered uniformly.

---

## 3. Technology Stack

| Layer | Choice | Notes |
|-------|--------|-------|
| Language (API) | Python 3.12 | `requires-python >= 3.12` |
| Web framework | FastAPI ≥ 0.111 | OpenAPI 3 auto-generated; type-driven validation |
| ASGI server | Uvicorn workers + Gunicorn | Process supervision and graceful reloads |
| ORM / migrations | SQLAlchemy 2.0 + Alembic | Versioned schema migrations |
| Database driver | psycopg 3 (binary) | PostgreSQL 16 |
| Validation/config | Pydantic v2 + pydantic-settings | Strongly-typed settings from env vars |
| AuthN | python-jose (JWT), passlib[bcrypt] | Local password hashing + JWT issuance |
| Storage SDKs | boto3 (S3) / azure-storage-blob | Region-pinned to GovCloud / Azure Gov |
| Logging | python-json-logger | Structured JSON logs for SIEM ingestion |
| Frontend | React + TypeScript, nginx | SPA with CUI banner |
| IaC | Terraform | AWS GovCloud + Azure Gov |
| Orchestration | Kubernetes + Helm | EKS / AKS |
| CI/CD | GitHub Actions | Build, test, scan, sign, deploy |
| Quality gates | ruff, pytest, pytest-cov | Lint + tests in CI |

---

## 4. Request Lifecycle

A representative authenticated write (e.g., dispositioning a nonconformance) flows as follows:

1. **Browser → Ingress.** The SPA issues `POST /api/v1/nonconformances/{id}/disposition` over TLS 1.2+.
   The cloud load balancer + nginx ingress terminate TLS (FIPS endpoints in GovCloud) and forward to the
   API service inside a private subnet.
2. **Authentication.** A FastAPI dependency extracts the bearer JWT, validates signature and expiry, and
   resolves the current `User`. Federated sessions were previously exchanged for an internal JWT by the
   identity broker (OIDC/SAML/CAC-PIV).
3. **Authorization.** An RBAC dependency checks the user's role against the required permission
   (`nonconformance:disposition`). MRB dispositions additionally require the Quality Manager or MRB role.
4. **Validation.** Pydantic validates the request body (disposition type, justification, affected qty).
5. **Domain logic.** The Nonconformance service applies the disposition state machine, enforcing that
   the NCR is in an eligible state and that an electronic signature is captured when required.
6. **Electronic signature.** If the action is signature-bearing, the user re-authenticates (password or
   CAC-PIN), and a signature manifest (who/what/when/why + record hash) is persisted.
7. **Persistence.** SQLAlchemy writes the change inside a transaction (parameterized SQL only).
8. **Audit.** An immutable audit-log row is appended (actor, action, entity, before/after hash,
   timestamp, source IP, session) within the same transaction.
9. **Notification.** A background task is enqueued (e.g., notify the originator and the CAPA owner).
10. **Response.** A serialized Pydantic response returns to the SPA; a structured JSON log line is emitted
    to the SIEM.

Read paths skip steps 5–9 but still enforce authn/authz and emit access logs.

---

## 5. Multi-Tenancy Model

Sentinel QMS supports two tenancy postures; the choice is driven by data-segregation and export-control
requirements:

### 5.1 Single-tenant (default for ITAR / dedicated programs)
Each customer/program gets a **dedicated deployment**: its own cloud account/subscription, database,
object store, and KMS keys. This is the recommended posture for ITAR-controlled programs because it
provides hard physical and cryptographic segregation and a clean authorization boundary for assessment.

### 5.2 Logical multi-tenant (shared platform, isolated org)
Within a single deployment, an **`organization` (tenant) scope** is attached to every business record.
Isolation is enforced at three layers:

1. **Application** — every query is filtered by the authenticated user's `organization_id`; the tenant
   scope is injected by a dependency and is not client-supplied.
2. **Database** — PostgreSQL **Row-Level Security (RLS)** policies key on the session tenant GUC, so even
   a logic bug cannot cross tenants.
3. **Storage** — object keys and (optionally) buckets/containers are namespaced per tenant; per-tenant
   KMS keys are supported.

> Mixing ITAR-controlled and non-controlled tenants in a single logical deployment is **not** permitted.
> See [../compliance/itar-ear-export-control.md](../compliance/itar-ear-export-control.md).

---

## 6. Statelessness & Scaling

The API service is **stateless** — no session affinity is required. Horizontal scaling is achieved by
adding API replicas behind the ingress. State lives in PostgreSQL (transactional), object storage
(binaries), and the SIEM (logs). Background workers scale independently. See
[../deployment/operations-runbook.md](../deployment/operations-runbook.md) for autoscaling guidance.

---

## 7. Key Architectural Decisions (ADR summary)

| # | Decision | Rationale |
|---|----------|-----------|
| ADR-1 | FastAPI + Pydantic v2 | Type-driven validation reduces injection/serialization risk; auto OpenAPI aids API governance |
| ADR-2 | PostgreSQL as single system of record (incl. audit log) | Transactional integrity links business writes and audit rows atomically |
| ADR-3 | Append-only audit table + DB triggers | Tamper-evident, supports 21 CFR Part 11 and NIST 800-53 AU controls |
| ADR-4 | JWT internal tokens behind federated IdP | Decouples app from any single IdP; supports OIDC/SAML/CAC-PIV |
| ADR-5 | Terraform + Helm, cloud-agnostic modules | Same topology reproducible in GovCloud and Azure Gov |
| ADR-6 | Region pinning + FIPS endpoints by default | Keeps CUI/ITAR data resident and uses validated cryptography |
