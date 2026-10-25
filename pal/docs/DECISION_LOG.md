# PAL Decision Log

| # | Date | Decision | Status | Record |
|---|---|---|---|---|
| 1 | 2026-10-25 | Modular monolith + worker; no microservices, no Kubernetes | Proposed | [ADR-0001](adr/ADR-0001-modular-monolith.md) |
| 2 | 2026-10-25 | ASP.NET Core (.NET 10 LTS) backend, conditional on D1 | **Superseded** by #8 | [ADR-0002](adr/ADR-0002-backend-framework.md) |
| 3 | 2026-10-25 | Vue 3 + TS + Quasar, gated by a11y spike | Proposed | [ADR-0003](adr/ADR-0003-frontend-framework.md) |
| 4 | 2026-10-25 | Entra ID OIDC SSO via BFF cookie session; no tokens in browser | Proposed | [ADR-0004](adr/ADR-0004-authentication-bff.md) |
| 5 | 2026-10-25 | PostgreSQL 17 + FTS; exclusion constraint for effectivity | Proposed | [ADR-0005](adr/ADR-0005-database-and-search.md) |
| 6 | 2026-10-25 | Immutable content-addressed storage abstraction (filesystem/NAS primary) | Proposed | [ADR-0006](adr/ADR-0006-immutable-content-addressed-storage.md) |
| 7 | 2026-10-25 | Lifecycle split across revision/workflow/publication/document | Proposed | [ADR-0007](adr/ADR-0007-lifecycle-state-decomposition.md) |
| 8 | 2026-10-25 | Owner: PHP. Recommend Symfony LTS + PHP 8.4+ (D13) | Proposed | [ADR-0008](adr/ADR-0008-php-symfony.md) |
| 9 | 2026-10-25 | Owner: full in-browser editor + Word/Excel/PDF uploads → TipTap native docs + file docs | Proposed | [ADR-0009](adr/ADR-0009-authoring-native-editor-and-files.md) |
| 10 | 2026-10-25 | Owner: group access → category/document grants to synced directory groups | Proposed | [ADR-0010](adr/ADR-0010-group-based-access.md) |
| 11 | 2026-10-25 | Owner: logs to Microsoft Sentinel → structured logs via existing collector | Proposed | [ADR-0011](adr/ADR-0011-logging-to-microsoft-sentinel.md) |
| 12 | 2026-10-25 | Owner: documents in on-prem Confluence → idempotent importer, Phase 6b | Proposed | [ADR-0012](adr/ADR-0012-confluence-migration.md) |
| 13 | 2026-10-25 | Owner: custom build, not on SharePoint/Microsoft platforms; on-prem Linux | Decided | Proposal §1a |
| 14 | 2026-10-25 | Owner: one organisation-wide scope; many time zones → single effective instant shown in local time | Decided | Proposal §11 |
| 15 | 2026-10-25 | Owner: portfolio repo now, private company repo at handover | Decided | Proposal §18.3 |
| 16 | 2026-10-25 | Owner (D18): Entra ID for sign-in and group sync only; "not Microsoft" = not SharePoint/M365 → SMTP-only email in v1 | Decided | Proposal §1a, ADR-0004, ADR-0010 |
| 17 | 2026-10-25 | Owner (D6a): organisation default timezone `America/Denver` | Decided | Proposal §11 |


Still open: D13 framework, D10 recovery targets (provisional), D14–D17, D19. See [proposal §18.2](PHASE0_ARCHITECTURE_PROPOSAL.md#182-still-open).
