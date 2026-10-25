# ADR-0007: Decompose the 12-state lifecycle across revision, workflow, publication and document

- **Status:** Proposed
- **Date:** 2026-10-25

## Context
The spec lists 12 states. A revision can be released while a newer draft is in review. Statuses such as Superseded and Published depend on *time* and on *other revisions*.

## Decision
- `revision.state`: DRAFT, IN_WORKFLOW, CHANGES_REQUESTED, REJECTED, APPROVED, WITHDRAWN (stored)
- `workflow_instance.status` + active stage kind: gives Submitted, In Review, Pending Approval (derived)
- `publication` rows with visible/effective periods: give Scheduled, Published, Effective, Superseded (derived)
- `document.disposition`: ACTIVE, OBSOLETE, ARCHIVED (stored)

The API returns a computed `displayStatus` with the full spec vocabulary.

## Consequences
- Supersession is one atomic publication change, never a multi-row status rewrite. This addresses the crash-recovery requirement (Scenario 9).
- Point-in-time queries are range lookups.
- Reports must use the derivation function, not raw columns. A shared SQL view (`v_revision_status`) provides it.
