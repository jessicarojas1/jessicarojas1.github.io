# PAL Decision Log

| # | Date | Decision | Status | Record |
|---|---|---|---|---|
| 1 | 2026-10-25 | Modular monolith + worker; no microservices, no Kubernetes | Proposed | [ADR-0001](adr/ADR-0001-modular-monolith.md) |
| 2 | 2026-10-25 | ASP.NET Core (.NET 10 LTS) backend, conditional on D1 | Proposed | [ADR-0002](adr/ADR-0002-backend-framework.md) |
| 3 | 2026-10-25 | Vue 3 + TS + Quasar, gated by a11y spike | Proposed | [ADR-0003](adr/ADR-0003-frontend-framework.md) |
| 4 | 2026-10-25 | Entra OIDC via BFF cookie session; no tokens in browser | Proposed | [ADR-0004](adr/ADR-0004-authentication-bff.md) |
| 5 | 2026-10-25 | PostgreSQL 17 + FTS; exclusion constraint for effectivity | Proposed | [ADR-0005](adr/ADR-0005-database-and-search.md) |
| 6 | 2026-10-25 | Immutable content-addressed storage abstraction | Proposed | [ADR-0006](adr/ADR-0006-immutable-content-addressed-storage.md) |
| 7 | 2026-10-25 | Lifecycle split across revision/workflow/publication/document | Proposed | [ADR-0007](adr/ADR-0007-lifecycle-state-decomposition.md) |

Pending owner decisions D0–D12: see [proposal §18](PHASE0_ARCHITECTURE_PROPOSAL.md#18-decisions-required-before-phase-1).
