# PAL — Phase 2 plan: document repository + walking skeleton

**Status:** in progress (started 2026-10-26) · **Exit criteria:** proposal §16, Phase 2 row

## Goal
Get one document through its whole life, thinly but correctly:

> An author creates a document, uploads its file, and submits it. An approver approves it. It is published as revision 1.0. Every employee finds it in the library and downloads exactly the approved file. A reader cannot reach the draft, through the UI or directly through the API.

This proves the identity → revision → approval → publication → audit chain end to end (Scenarios 1 and 7) before Phases 3–5 deepen each part.

## In scope
| Area | What Phase 2 builds |
|---|---|
| Reference data | Document types (seeded: POL, PRC, SOP, STD, WI, TPL, FRM, GDL, REF), departments (org units), admin CRUD for both |
| Numbering | Fixed scheme `{TYPE}-{DEPT}-{SEQ:4}` (e.g. `SOP-QA-0001`) from a per-type/department counter locked in the creating transaction. Configurable patterns come in Phase 3 |
| Files | Immutable, content-addressed filesystem store (`sha256/ab/cd/<hash>`) |
| Upload pipeline | Stream to quarantine while hashing → size limit → magic-byte type detection (extension ignored) → allow-list → ClamAV scan → promote. Fails closed when the scanner is unavailable |
| Documents & revisions | Document identity separate from revisions. One open draft per document. Draft content editable only in `DRAFT`; the content manifest hash is frozen at submission |
| Access | Document grants to users or directory groups (`OWNER`, `AUTHOR`, `APPROVER`, `READER`). Default audience is all employees; documents can be marked *restricted*. A voter enforces all of it, and list/search queries filter in SQL |
| Approval (skeleton) | Single-approver workflow: submit → approve / reject / request changes, with comments. Separation of duties: the author or submitter cannot approve. Each decision is bound to the manifest hash |
| Publication (skeleton) | Immediate release only. A `publication` row records visible and effective periods, with PostgreSQL **exclusion constraints** guaranteeing one effective revision per document per scope. Release is one transaction and idempotent |
| Library | Published documents only, metadata full-text search (PostgreSQL FTS + trigram on numbers), filters (type, department), detail page, revision history, authorized download |
| UI | Library, document detail, My workspace (my drafts), new document / edit draft, approvals inbox, admin: document types and departments |
| Audit | Every create, edit, upload, submit, decision, release, grant and admin change |

## Deliberately deferred (with the phase that delivers it)
| Item | Phase | Why not now |
|---|---|---|
| Native in-browser editor (TipTap) | 3 | Revisions and approvals must be solid before a second content kind arrives |
| Minor/major numbering choice, revision comparison, rollback | 3 | Phase 2 releases 1.0, then 2.0, 3.0… automatically |
| Multi-stage, parallel, quorum workflows; delegation; escalation | 4 | Skeleton proves the binding and SoD; the engine comes next |
| Scheduled publication, separate effective dates, supersession UI | 5 | Schema supports it now (ranges + constraints); behaviour comes in Phase 5 |
| Category hierarchy grants, `document_access` materialization | 3 | Document-level grants cover Phase 2; category inheritance needs the materialized table |
| File preview (Gotenberg), text extraction, full-text of content | 6 | Search covers metadata in Phase 2 |
| S3-compatible blob backend | 6 | On-prem filesystem/NAS is the selected primary (D2) |

## Data model (Migration 2)
`org_unit`, `document_type`, `numbering_counter`, `upload`, `stored_file`, `document`, `document_grant`, `revision`, `revision_file`, `workflow_instance`, `workflow_task`, `approval_decision`, `publication` (with `EXCLUDE USING gist` on effective and visible periods), plus `btree_gist` and `pg_trgm`. Field-level design follows proposal §8.2. Differences:
- `revision.state`: `DRAFT | IN_WORKFLOW | CHANGES_REQUESTED | REJECTED | APPROVED | WITHDRAWN` (ADR-0007). `PUBLISHED`/`SUPERSEDED` are derived from `publication`.
- Content immutability is enforced by a trigger. `revision_file` rows can change only while the revision is `DRAFT`; an `APPROVED` revision's snapshot columns can never change.

## Exit criteria (from the plan, made concrete)
1. **Scenario 1** (create → submit → approve → publish 1.0 → readers find and download) passes as an automated functional test.
2. **Scenario 7**: a reader requesting a draft's metadata or file by ID gets **404**, and drafts never appear in search. Automated.
3. An EICAR test file stays quarantined and never becomes a stored file. A file with a spoofed extension is rejected. Automated (scanner faked in tests; real ClamAV in the Compose stack).
4. The exclusion constraint rejects two overlapping effective publications (negative test).
5. The approval's manifest hash must equal the released revision's hash, verified at release.
6. All existing gates stay green: PHPStan max, Deptrac, axe 0 violations on new pages, CI.
