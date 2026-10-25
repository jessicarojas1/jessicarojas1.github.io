# CLAUDE.md — PAL (Enterprise Process Asset Library)

Inherits the repository root `CLAUDE.md` and global rules.

## Project rules
- **Scope is DMS only.** Refuse scope creep into training, CAPA, NCR, risk, audit management, acknowledgements, ERP or PM.
- **Phase gate:** no application code until the Phase 0 proposal is approved. Each phase exits only when its criteria in `docs/PHASE0_ARCHITECTURE_PROPOSAL.md` §16 are verified.
- **Invariants that must never regress:** approved revisions are immutable; approvals are bound to a manifest hash; exactly one effective revision per document per scope (DB exclusion constraint); release is idempotent; audit is append-only and written in the same transaction; all authorization is server-side and applied in queries.
- Keep `docs/DECISION_LOG.md`, `docs/REQUIREMENTS_TRACEABILITY.md`, ADRs and `OPEN_ITEMS.md` current with every change.
- From Phase 1, keep the standard doc set (`deployments/` ×6, `docs/` ×4, README, OPEN_ITEMS, Dockerfile, render.yaml, `database/schema.sql`) accurate in the same change as any feature, migration or config change.
- **Public repo:** never commit real tenant IDs, hostnames, org structure, document content or secrets.
- End every session with: work completed, files changed, tests executed, outstanding defects, architectural decisions, recommended next task.
