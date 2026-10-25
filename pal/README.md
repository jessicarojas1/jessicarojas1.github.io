# PAL — Enterprise Process Asset Library

An enterprise **document management and document control** system for organizational process assets (policies, processes, procedures, standards, work instructions, templates, forms). It covers authoring by upload, review and approval workflows, scheduled publication with separate effective dates, immutable revisions, supersession, archival, search, RBAC and a tamper-evident audit trail. It is designed to support ISO 27001, CMMI Level 3 and CMMC Level 2 process documentation.

**Scope is document management only.** Training, CAPA, nonconformance, risk, audit management and acknowledgement campaigns are explicitly out of scope.

## Status

**Phase 0: Discovery & Architecture.** There is no application code yet. Phase 1 starts after the architecture is approved and the open decisions are answered.

| Document | Purpose |
|---|---|
| [Phase 0 Architecture & Implementation Proposal](docs/PHASE0_ARCHITECTURE_PROPOSAL.md) | Spec analysis, technology evaluation, data model, state machine, workflow, publication, RBAC, audit, deployment, threat model, plan, acceptance criteria, decisions |
| [Architecture Decision Records](docs/adr/) | ADR-0001 … ADR-0007 |
| [Decision Log](docs/DECISION_LOG.md) | Running log of decisions |
| [Requirements Traceability Matrix](docs/REQUIREMENTS_TRACEABILITY.md) | Spec → design → phase → verification |
| [Open Items](OPEN_ITEMS.md) | What is not done yet |

## Proposed technology (pending approval)

ASP.NET Core (.NET 10 LTS) modular monolith + worker · Vue 3 / TypeScript / Quasar SPA via BFF · PostgreSQL 17 (full-text search, exclusion constraints) · content-addressed immutable blob storage (filesystem/NAS, Azure Blob, S3) · Entra ID OIDC · Quartz.NET + transactional outbox · Gotenberg (isolated) for previews · ClamAV / Defender for Storage · Docker; Azure Container Apps or on-prem Docker Compose.

## Deployment models (planned)

On-premises Linux (Docker Compose) and Microsoft Azure (Container Apps + Azure Database for PostgreSQL + Blob + Key Vault). The standard `deployments/` guides, `docs/` set, `Dockerfile` and `render.yaml` land in Phase 1, when there is real code to describe. Writing them now would mean documenting commands and settings that do not exist yet.
