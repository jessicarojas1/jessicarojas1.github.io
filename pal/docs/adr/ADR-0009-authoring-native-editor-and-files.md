# ADR-0009: Authoring: native in-browser editor plus Word/Excel/PDF uploads

- **Status:** Accepted (architecture approved 2026-10-25). Originally proposed (owner decision D3, 2026-10-25: "a full in-browser editor" plus uploads)
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

## Implementation status: native editor v1 (2026-11-10, decision #26)

**Implemented** (owner request 2026-11-10: write text inline in PAL, and create templates with headings):
- TipTap 3 editor in the browser (`frontend/src/components/RichTextEditor.vue`) with headings 1–4, bold, italic, underline, strikethrough, bulleted and numbered lists, quotes, divider lines, links and tables. Drafts autosave (debounced, optimistic concurrency on the revision row version, no-op when unchanged).
- Server-side schema (`backend/src/Documents/RichTextDocument.php`): the allow-list of nodes, marks and attributes; anything else is refused with 422. Accepted content is rebuilt **canonically** (fixed key order, no unknown attributes) so equal content always has equal bytes. Links: `http(s)://` and `mailto:` only.
- Approvals bind to the body: the manifest hash includes `SHA-256(body_json)` when a body exists. Revisions without a body hash exactly as before, so existing approvals still verify.
- Per-type **templates** maintained by PAL administrators (`admin.templates`), chosen by authors when creating a document; the template body is copied into the draft (later template edits never change existing documents).
- Body text is indexed for library search (weight D).
- A revision may have editor content, a file, or both; submission needs at least one.

**Deviations from the decision above** (accepted for v1; see decision log #26):
1. **Storage.** The canonical JSON is stored in `revision.body_json` (PostgreSQL) instead of as a content-addressed blob. Integrity is equivalent: it freezes under the same `trg_revision_immutable` trigger as the rest of the content, and release recomputes its SHA-256 against the approved manifest. It also keeps autosave from writing a new blob per keystroke burst.
2. **Rendering.** The browser renders the validated JSON node by node into a fixed set of elements (`RichTextView.vue`; no `v-html`, no `innerHTML`, link addresses re-checked). Server-side HTML rendering, and `symfony/html-sanitizer` as its second layer, arrive with the PDF rendition, which is the first consumer that needs server HTML.

**Soft checkout (added 2026-11-10):** `revision_editing` records who is typing in a draft (heartbeat at most every 60 s, claim lapses after 150 s, released on leaving the page); others see a banner. It is advisory: optimistic concurrency on `row_version` still decides which save wins, so nothing is overwritten silently. A new claim is audited `REVISION_EDITING_STARTED` (not a content change, so it does not bar approval).

**Images were added 2026-11-11 (decision #29):** through the upload/scan pipeline, re-encoded without metadata, referenced by SHA-256 with alt text, access-checked per revision or template.

**Deferred** (still part of this ADR, not built yet): callouts, cross-references and section numbering, Gotenberg PDF rendition. **Structural comparison was added 2026-11-11 (decision #28):** block-level diff of the canonical content with a word diff inside edited blocks, plus metadata and file changes, for anyone with work-in-progress access. **Template versioning was added 2026-11-11 (decision #27):** every content change is an immutable numbered version, and revisions record the version they started from; templates are still maintained by administrators rather than approved like documents.
