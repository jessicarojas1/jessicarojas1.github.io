# ADR-0012: Migration of existing process documents from on-prem Confluence

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed (owner fact, 2026-10-25: current documents live in on-prem Confluence)
- **Date:** 2026-10-25

## Decision
A one-way, repeatable **import tool** (a Symfony console command), run against a read-only Confluence service account through Confluence's REST API.

1. **Extract:** spaces, page tree, page bodies (storage-format XHTML), labels, attachments, authors/editors and, if required, version history. Exact endpoints depend on the Confluence version (D19).
2. **Transform:**
   - Page bodies → PAL native documents (ProseMirror JSON, ADR-0009).
   - Attachments (Word/Excel/PDF) → file documents or revision attachments.
   - Labels → keywords. Space or page tree → category and department.
   - Confluence macros with no PAL equivalent (Jira issues, includes, dynamic lists) are **flagged for manual review**, never silently dropped.
   - Each imported item gets a mapping record (Confluence page ID ↔ PAL document ID) so re-runs are idempotent.
3. **Load:** imported content becomes a **released historical revision** marked `imported` (with the source page ID, version and import batch). Effective dates are attested by the document owner, because Confluence has no PAL-grade approval evidence. Exception: if an approvals app (for example Comala Document Management) holds real approval history, it is mapped across.
4. **Reconcile:** a dry-run report for each batch lists counts per space, attachment hashes, unmapped macros, unresolved users and permission mappings. A **pilot space** goes first.
5. **Cut over:** freeze migrated pages in Confluence (read-only + banner linking to PAL), then redirect.

## Consequences
- Migration becomes its own tracked workstream (Phase 6b), and it can start once Phase 3 (revisions + editor) is done.
- **RISK:** Confluence Server reached end of support in February 2024, and Atlassian has announced an end-of-life timeline for Data Center. The edition and version in use (D19) determine the API surface and urgency.
