# ADR-0001: Modular monolith, no Kubernetes

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed (Phase 0)
- **Date:** 2026-10-25

## Context
PAL serves about 300 users initially, with a design target of 1,000+. The core invariants (one effective revision, approvals bound to file hashes, atomic supersession, audit written in the same transaction) are transactional and span documents, revisions, workflows, publications and audit.

## Decision
One deployable backend (`pal-api`) plus one worker process (`pal-worker`) from the same codebase, split into internal modules: Identity & Access, Repository, Revisions, Workflow, Publication, Notifications, Search, Audit, Administration, Reporting. Modules talk through in-process interfaces and domain events. Each module owns its tables, and no module writes to another module's tables. A converter sidecar (Gotenberg) and a malware scanner are separate containers only because they must be isolated for security.

Hosting: Docker Compose on on-prem Linux (owner decision D2). No Kubernetes.

## Alternatives
- **Microservices:** would turn local ACID transactions into distributed sagas for exactly the operations that must be atomic. No scaling or team-autonomy need justifies that.
- **Kubernetes:** adds a substantial operational surface (cluster upgrades, ingress, RBAC, secrets) for 3–4 containers.

## Consequences
- Simple deployment and debugging. Strong consistency comes from a single database transaction.
- Module boundaries must be enforced by convention plus architecture tests (e.g. Deptrac for PHP) so they don't erode.
- If a module ever needs to be extracted, its boundaries and events already exist.
