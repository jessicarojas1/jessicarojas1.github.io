# ADR-0009: Authoring: native in-browser editor plus Word/Excel/PDF uploads

- **Status:** Proposed (owner decision D3, 2026-10-25: "a full in-browser editor" plus uploads)
- **Date:** 2026-10-25

## Context
The owner wants documents authored in the browser **and** the ability to upload Word, Excel and PDF. Existing content lives in Confluence, which is page-based rich text. Every approval must bind to exact, immutable content.

## Decision
A revision's content is one of two kinds, and a document type allows one or both:

1. **Native documents**, written in PAL's editor. Built on **TipTap** (ProseMirror-based, MIT-licensed core) in Vue.
   - The canonical content is **ProseMirror JSON validated against PAL's allow-listed schema**: headings, paragraphs, lists, tables, images, links, callouts, cross-references to other PAL documents, and section numbering.
   - The JSON is stored as an immutable, hashed content blob, exactly like a file. That hash is what approvals bind to.
   - The server renders HTML **from the schema**. It never stores or serves client-supplied HTML. `symfony/html-sanitizer` runs as a second layer.
   - A controlled PDF rendition is produced by Gotenberg (Chromium HTML → PDF) for print and "uncontrolled when printed" copies.
   - Images go through the same quarantine, type-detection and malware-scan pipeline as uploads.
   - Drafts autosave with optimistic concurrency. A soft checkout lock shows who is editing.
   - Per-type templates seed new documents.
2. **File documents**: Word, Excel, PDF (and other allow-listed types) uploaded through the secure pipeline, previewed as PDF renditions, and revised by uploading a new version under checkout.

Native documents also get an **accurate structural comparison** between revisions, which the spec only asked to "evaluate" for files.

## Deferred / separate decisions
- **Real-time co-authoring** (several people typing in the same draft at once): needs a WebSocket collaboration server (e.g. Yjs/Hocuspocus, which is Node.js). That is a second runtime alongside PHP. **Deferred** (D15). v1 uses single-editor checkout.
- **Editor comments and track changes** inside the editor: some TipTap features in this area are paid "Pro" extensions. v1 uses PAL review comments on the revision plus revision comparison. Revisit if reviewers need inline annotation.
- **Editing Word/Excel files in the browser** (as opposed to PAL's native editor) requires a self-hosted office suite. **Microsoft is excluded** by the owner (2026-10-25). Candidates: **ONLYOFFICE Docs** (Community edition AGPL-licensed, with a cap on simultaneous connections; Enterprise is paid) and **Collabora Online** (LibreOffice-based; production use needs a subscription). Both integrate through a document-server API / WOPI. **D14**: recommend deferring to a post-v1 phase and keeping download → edit → upload for Office files until then.

## Consequences
- Authoring scope roughly doubles Phase 3. The phase plan adds an editor milestone.
- The Confluence import targets native documents (storage-format XHTML → ProseMirror JSON), which keeps migrated pages editable (see ADR-0012).
- Stored XSS is the main new threat. It is mitigated by schema-only rendering, the sanitizer, strict CSP with nonces, and no `v-html` with user data.
