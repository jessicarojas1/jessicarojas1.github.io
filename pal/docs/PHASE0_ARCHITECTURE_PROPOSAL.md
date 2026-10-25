# PAL — Phase 0 Architecture & Implementation Proposal

**Product:** Enterprise Process Asset Library (PAL)
**Phase:** 0 — Discovery & Architecture (no application code)
**Status:** DRAFT **rev 2** (owner decisions incorporated 2026-10-25), awaiting owner approval
**Date:** 2026-10-25
**Companion records:** [ADRs](adr/) · [Decision Log](DECISION_LOG.md) · [Requirements Traceability Matrix](REQUIREMENTS_TRACEABILITY.md) · [Open Items](../OPEN_ITEMS.md)

> Labels used throughout: **FACT** (stated in the spec or verified), **FINDING** (a conclusion supported by evidence), **ASSUMPTION** (needed but not verified), **RECOMMENDATION**, **RISK**, **DECISION** (owner input needed).

---

## Contents

1. [Executive summary](#1-executive-summary)
   - [1a. Owner decisions, rev 2](#1a-owner-decisions-rev-2)
2. [Specification analysis: ambiguities, gaps, conflicts](#2-specification-analysis)
3. [Capabilities missing compared with commercial document control](#3-competitive-gap-analysis)
4. [Build vs. buy check](#4-build-vs-buy-check)
5. [Backend: PHP on Symfony](#5-backend-php-on-symfony)
6. [Frontend: Vue 3 + Quasar](#6-frontend-vue-3--quasar)
7. [Database, storage, search, preview and jobs](#7-database-storage-search-preview-jobs)
8. [Data model](#8-data-model)
9. [Lifecycle state machine](#9-lifecycle-state-machine)
10. [Workflow engine](#10-workflow-engine)
11. [Publication and effective dates](#11-publication-and-effective-dates)
12. [Roles and authorization model](#12-roles-and-authorization-model)
13. [Audit trail design](#13-audit-trail-design)
14. [Deployment architectures and cost](#14-deployment-architectures-and-cost)
15. [Threat model](#15-threat-model)
16. [Phased delivery plan](#16-phased-delivery-plan)
17. [Production readiness acceptance criteria](#17-production-readiness-acceptance-criteria)
18. [Decisions required before Phase 1](#18-decisions-required-before-phase-1)

---

## 1. Executive summary

**RECOMMENDATION (rev 2):** a **modular monolith** in **PHP 8.4+ on Symfony (LTS)**, hosted **on-premises on Linux** with Docker Compose (nginx + PHP-FPM, plus separate background-worker containers). The UI is a **Vue 3 + TypeScript + Quasar** single-page app that signs in through a **backend-for-frontend (BFF)** session cookie, so the browser never holds login tokens.

| Area | Choice |
|---|---|
| Authoring | A **native in-browser editor** (TipTap) for PAL documents, plus **Word / Excel / PDF uploads** |
| Data | **PostgreSQL 17**, which also provides search (built-in full-text) |
| Files | **Content-addressed, immutable storage** on filesystem/NAS, with an S3 option |
| Background jobs | **Symfony Messenger + Scheduler**, with the queue kept in PostgreSQL |
| Previews | **Gotenberg** in a network-isolated container |
| Malware scanning | **ClamAV** |
| Access | **Group-based** grants synced from the directory |
| Logs | Structured logs forwarded to **Microsoft Sentinel** |
| Existing documents | A repeatable importer from **on-prem Confluence** |

Five design choices carry most of the correctness requirements in the spec:

1. **Document identity is separate from immutable revisions.** A revision's content (editor JSON or uploaded files) freezes when it is submitted. An approved revision can never change.
2. **The 12 statuses in the spec are not stored as one column.** Each fact is stored where it belongs: revision state, workflow instance state, publication records, and document disposition. The user-facing status is computed from them (§9, ADR-0007).
3. **Publication is a time-bounded record.** "Exactly one effective revision" is enforced by a **PostgreSQL exclusion constraint** over effective periods (§11).
4. **Each approval is bound to a revision ID plus a SHA-256 hash of the exact content.** Release re-verifies that hash (§10).
5. **The audit log is append-only.** The application's database role has no UPDATE or DELETE rights on it, events are hash-chained with periodic external anchoring (§13), and a copy is forwarded to Sentinel.

**Challenges carried forward:**

- **Ship a thin end-to-end slice early.** Scenario 1 (create → review → approve → publish 1.0) should work by the end of Phase 2, and security checks should run in every phase (§16).
- **The full in-browser editor is the largest scope item.** v1 is a structured editor with single-editor checkout. Real-time co-authoring and in-browser editing of Word/Excel files are separate, deferred decisions (D14, D15).

---

## 1a. Owner decisions, rev 2

| # | Question | Owner answer (2026-10-25) | Design consequence |
|---|---|---|---|
| D0 | Build vs. SharePoint | Integrity controls are required. **Not built on SharePoint or Microsoft platforms.** | Custom build confirmed. No SharePoint, Power Platform, Office for the web or Azure hosting. Identity and directory sit behind adapters (D18). |
| D1 | Language | **PHP** | ADR-0008 (Symfony recommended, D13). ADR-0002 superseded. |
| D2 | First target | **On-prem Linux** | §14.2 is the primary design. The Azure option is retained only as a documented future path. |
| D3 | Authoring | **Full in-browser editor + upload Word, PDF, Excel** | ADR-0009. Native editor + file documents. Phase 3 grows. |
| D5 | Visibility | **Group access is required** | ADR-0010. Category/document grants to directory groups, enforced in SQL. |
| D6 | Effective-date semantics | **Many time zones** | §11: each change takes effect at a single moment, chosen with a date, a time and a timezone, and shown to every user in their own timezone. The organisation's default timezone is still to be named (D6a). |
| D7 | Publication scope | **One organisation-wide scope** | Confirmed. `scope_id` stays in the schema as a single fixed value. |
| D10 | Recovery / performance targets | **Unknown to owner** | Provisional defaults adopted (RPO 15 min, RTO 4 h, §17 performance). They need confirmation from the IT / business owner before Phase 7. Plain-language explanation in §18. |
| D12 | Repository | **Portfolio repo for now; private company repo at handover** | Keep all content generic. Handover checklist in §18. |
| — | Existing documents | **On-prem Confluence** | ADR-0012. Importer + Phase 6b. |
| — | Email | **SMTP and Graph** | `Mailer` transport adapter: SMTP first. Graph only if D18 keeps Microsoft identity. |
| — | Logging | **Microsoft Sentinel** | ADR-0011 |

---

## 2. Specification analysis

### 2.1 Ambiguities (need a ruling, mostly low cost to resolve)

| # | Spec text | Ambiguity | Proposed default |
|---|---|---|---|
| A1 | "Document creation and authoring" | Is authoring done **in the browser** (rich text / Markdown) or in **Word with upload**? In-browser authoring of Word-equivalent documents is a large product on its own. | Upload-based for Office/PDF. Optional in-app Markdown authoring for simple documents in Phase 6+. **DECISION D3** |
| A2 | "Effective date: November 5" | Is it a **date** or an **instant**? In which timezone? Does it start at 00:00? | Store the *local date + org timezone* as entered, plus the computed UTC instant (00:00 in the org timezone). **DECISION D6** |
| A3 | "exactly one current effective revision per document *within a given publication scope*" | "Publication scope" is undefined. Is it per site, language, department, or customer? | One scope (`global`) in v1. The schema carries `scope_id` so more can be added without migration rework. **DECISION D7** |
| A4 | "Reader — accesses *authorized* published documents" | Are all published documents visible to all ~300 employees, or are some restricted (HR, security procedures)? This decides whether search needs per-document ACL filtering. | Default visible to all authenticated employees, with an optional per-document "restricted audience" (Entra groups). **DECISION D5** |
| A5 | "Submitted for Review" vs. "In Review" | No defined difference. | *Submitted* = workflow created, no reviewer has acted. *In Review* = at least one reviewer has opened or acted. Display only; same stored state. |
| A6 | "Obsolete" vs. "Archived" vs. "Superseded" | They overlap. | *Superseded* = replaced by a newer revision of the **same** document (revision-level, derived). *Obsolete* = the **document** is withdrawn with no replacement (document-level). *Archived* = moved out of the default library after retention rules; still retrievable by authorized roles (document-level). |
| A7 | Revision numbering "1.0 / 1.1 / 2.0" | When is a number assigned? At draft creation (so drafts show "1.1") or at release? What about drafts that are abandoned? | Drafts carry a *proposed* label ("1.1 (draft)"). The number is **confirmed at approval** and is unique among released revisions. Abandoned drafts keep their label, marked withdrawn. **DECISION D8** |
| A8 | Document number | Assigned at creation or at first release? Are gaps acceptable? | Reserved at creation from a per-scheme counter. Gaps from abandoned documents are acceptable and audited. **DECISION D8** |
| A9 | "Encryption at rest" | Platform-managed keys, or customer-managed keys (CMK)? | Platform-managed (Azure SSE / LUKS on-prem). CMK optional. |
| A10 | "Malware scanning integration" | The engine is unspecified. | ClamAV (on-prem, rev 2). |
| A11 | Retention policies (admin menu) | No retention periods given. They may be driven by ISO 27001 / CMMC record-keeping needs. | Configurable per document type. **No automatic deletion in v1.** Disposition requires a manual, audited action. |

### 2.2 Gaps (missing requirements that change the design)

| # | Gap | Why it matters |
|---|---|---|
| G1 | **Migration of existing documents.** Where do the current process assets live (SharePoint, file share)? How many are there? Must their history come along? | Usually the largest single effort on day one of a DMS rollout. It needs a bulk import path that creates *historical* released revisions with backdated, attested metadata, which is itself a controlled and audited operation. |
| G2 | **Multi-file revisions.** Can one revision contain a main file plus appendices or attachments, or a source `.docx` plus a rendered `.pdf`? | Changes the `revision_file` cardinality and what the approval checksum covers. Proposed: a revision has 1..n files. Approval binds to a **manifest hash** over all file hashes. |
| G3 | **Source vs. rendition.** Do readers download the editable `.docx` or a controlled PDF? | Many document-control regimes publish a PDF rendition and restrict access to the source. Proposed: configurable per document type. |
| G4 | **Periodic review outcome.** What happens when a review comes due: re-approve unchanged, revise, or retire? | Needs a lightweight "reviewed, no change" path that extends the review date **without** a new revision but **with** an audit event and approver sign-off. |
| G5 | **Document relationships.** Procedure → form, policy → procedure, "references". | Needed for impact analysis ("what links to the policy I am superseding?"). Cheap to model now, expensive to retrofit. |
| G6 | **Electronic signature strength.** Is a logged-in click enough, or must approvers re-authenticate at signing? | Re-authentication (OIDC `prompt=login` / `max_age=0`) is easy if planned early. No regulated e-signature regime (e.g. 21 CFR Part 11) is stated. **DECISION D9** |
| G7 | **Uncontrolled-copy marking.** Should "Uncontrolled when printed / downloaded on <date>" be stamped on renditions? | Common in ISO and CMMI environments. Affects the preview/rendition pipeline. |
| G8 | **RPO/RTO targets.** Not stated. | They drive backup frequency, HA choices and cost (§14). Proposed: RPO ≤ 15 min, RTO ≤ 4 h. **DECISION D10** |
| G9 | **Email transport.** SMTP relay or Microsoft Graph? | Graph `Mail.Send` application permission must be scoped to one mailbox. Unscoped, the app can send as anyone. |
| G10 | **Legal hold.** | Must block disposition regardless of retention policy. Small to add, painful to miss. |
| G11 | **Delegation / out-of-office.** | Approvers go on leave. Admin reassignment exists in the spec, but *user-initiated, time-boxed delegation* is the norm in commercial products. |
| G12 | **Team skills and who operates it.** Not stated. | This is the strongest input to the backend choice (§5) and the on-prem vs. Azure choice (§14). **DECISION D1, D2** |

### 2.3 Conflicts and tensions

| # | Conflict | Resolution |
|---|---|---|
| C1 | "Prevent administrators from silently altering audit events" vs. administrators running the database | Not fully solvable inside one trust domain. Mitigate with role separation, a hash chain and **external anchoring** to storage the DBA cannot rewrite (§13). The goal is tamper-*evident*, not tamper-*proof*. State this honestly to auditors. |
| C2 | "Emergency publication" vs. "approvals must be valid before publication" and separation of duties | Emergency publication is a **separate, audited transition** that requires the PAL Administrator role plus a mandatory justification. It **creates a retroactive-review obligation** and shows a visible banner until a normal approval completes. It does not bypass the approval record. It flags its absence. |
| C3 | "Rollback" vs. "never overwrite history" | A rollback is a **new revision** whose content is copied from an earlier one. The copied file has the same hash, so it is stored once. The rollback goes through normal approval, or emergency publication. History stays linear and intact. |
| C4 | "Avoid vendor lock-in" vs. "Entra ID SSO" and Azure services | Use standard OIDC (any OIDC IdP works later) and the storage/secrets/email abstractions. Entra-specific claims (`oid`, `tid`, groups) are confined to one adapter. |
| C5 | A 12-state single lifecycle vs. a revision that is published **and** has a newer draft | Resolved by splitting state across entities (§9). |
| C6 | WCAG 2.2 AA vs. framework choice | Quasar is not certified accessible. Reaching AA requires component-level testing and some wrapping (§6). |

---

## 3. Competitive gap analysis

Capabilities commonly found in mature commercial document-control products (for example MasterControl, Veeva, Qualio, OpenText) that the spec omits or underspecifies. Items marked *Exclude* would pull PAL into QMS territory, which the spec rules out.

| Capability | In spec? | Recommendation |
|---|---|---|
| Bulk import / migration with history | No | **Include** (Phase 2/3), see G1 |
| Periodic review "no-change" re-certification | Partial | **Include** (Phase 5), see G4 |
| Document relationships / where-used | No | **Include** model in Phase 2, UI in Phase 6 |
| Controlled PDF renditions + "uncontrolled copy" watermark | No | **Include** as optional per type (Phase 6) |
| Re-authentication at approval (signature manifestation) | No | **Include** if D9 = yes (Phase 4) |
| Check-out / edit lock on drafts | Implied ("prevent overwriting") | **Include**: optimistic concurrency always, plus an advisory checkout flag |
| Delegation of approval authority | Partial (admin reassign) | **Include** user delegation with date range (Phase 4) |
| Legal hold | No | **Include** (Phase 5) |
| Review comments / annotations on the file | Comments only | Comments in v1. In-file annotation **defer**: needs a PDF annotation layer. |
| Redline / text comparison of Office files | "Evaluate" | Metadata diff in v1. Text diff of extracted text in Phase 6 as "best effort, not authoritative" |
| Co-authoring in Office (WOPI) | No | **Defer / exclude.** WOPI host integration with M365 needs Microsoft partner onboarding (Cloud Storage Partner Program) and is substantial work. Use download → edit → upload with checkout instead. |
| Training / read-and-understood | Excluded | **Exclude** per spec |
| CAPA / change requests | Excluded | **Exclude.** Document *change requests* are a borderline case. A simple "suggest a change" comment on published documents is within DMS scope. |

---

## 4. Build vs. buy check

> **Resolved (rev 2):** the owner confirmed the integrity controls are required and that PAL will **not** be built on SharePoint or other Microsoft platforms. The analysis below is kept for the record.

**FINDING:** the spec is a classic document-control system. The cheapest credible alternative is already in your tenant.

| Option | Covers well | Falls short on | Cost |
|---|---|---|---|
| **SharePoint Online + Power Automate** (M365 already licensed, implied by Entra) | Storage, versioning, metadata, search, permissions, simple approvals, retention labels | Approval bound to an exact file hash; separate publication and effective dates with an "exactly one effective" guarantee; point-in-time reconstruction; tamper-evident audit (the audit log is admin-searchable, retained 180 days to 1 year depending on licence tier); configurable SoD; idempotent scheduled release. Power Automate flows become fragile at this complexity. | Near $0 incremental licence; build effort in flows |
| **Open-source DMS** (e.g. Mayan EDMS, Alfresco Community) | Repository, OCR, workflows (Alfresco) | Document-control semantics (effectivity, supersession, approval integrity) still need custom work. Alfresco Community's operational footprint is heavy (Java, Solr). | $0 licence, high ops/customization |
| **Commercial eQMS / DMS** | Everything, validated | Typically priced per user per year. For 300 users, often a five- to six-figure annual sum (vendor quote needed). Usually bundles QMS modules you explicitly excluded. | High |
| **Custom PAL (this proposal)** | All stated requirements, exactly | Build and ownership cost; you operate it | On-prem infrastructure + engineering and operations (§14) |

**RECOMMENDATION:** proceed with the custom build **only if** the owner confirms that the integrity controls (hash-bound approvals, effectivity guarantees, historical reconstruction, tamper-evident audit) are genuine audit requirements for your ISO 27001 / CMMI / CMMC evidence. If "good enough versioning and approvals" would satisfy your auditors, SharePoint is the lower-risk choice. **DECISION D0.**

**In-repo precedent (FACT):** `sentinel-qms/` in this repository already implements document & records control (revision history, approval workflow, effectivity) on FastAPI + PostgreSQL + a Vite/TypeScript frontend. PAL is deliberately DMS-only and has a different stack baseline. Reuse is limited to *design lessons*, not code, unless D1 picks Python.

---

## 5. Backend: PHP on Symfony

The owner selected **PHP** (D1). The ASP.NET Core vs. NestJS comparison from rev 1 is preserved in [ADR-0002](adr/ADR-0002-backend-framework.md) (superseded).

**RECOMMENDATION: Symfony (current LTS), PHP 8.4+**, see [ADR-0008](adr/ADR-0008-php-symfony.md). Symfony's own components map directly onto PAL's hardest requirements:

| PAL requirement | Symfony component |
|---|---|
| Server-enforced lifecycle state machine (§9) | **Workflow** (state machines with guards and transition events) |
| Global + document/group-scoped authorization (§12) | **Security voters** (per-object decisions) |
| Durable, idempotent background jobs without Redis (§7.5) | **Messenger** (PostgreSQL transport) + **Scheduler** |
| PostgreSQL ranges / exclusion constraints | **Doctrine Migrations** with raw SQL |
| Safe rendering of editor content (ADR-0009) | **HtmlSanitizer** |
| CSRF, sessions, CSP nonces | Security CSRF tokens, PDO session handler, a CSP bundle (e.g. NelmioSecurityBundle) |

**Type safety in PHP** is enforced by `declare(strict_types=1)`, typed properties and enums, and **PHPStan at its strictest level as a CI gate**.

**Alternatives:** Laravel is acceptable if the receiving team knows it. The in-house PHP framework used elsewhere in this repo is **not recommended** for a company handover (ADR-0008).

**PHP-specific operational risk:** long-running Messenger consumers. Mitigation: memory and time limits per worker, automatic restart under supervisor, and idempotent handlers.

---

## 6. Frontend: Vue 3 + Quasar

**Assessment: acceptable, with conditions.**

- **Strengths:** a complete component set (tables with server-side pagination, forms, dialogs, layout), so the team builds no infrastructure UI. TypeScript support and Vite build. Responsive layout primitives. Pinia + Vue Router are standard.
- **Risks:**
  - **Accessibility.** Quasar does not claim WCAG conformance. Complex widgets (QSelect, QTable, QTree, date pickers) need verified keyboard and screen-reader behaviour. *Mitigation:* a Phase 1 accessibility spike covering the 8 components PAL depends on most, tested with axe-core plus manual NVDA/VoiceOver checks, before the design system is locked. Any failing component gets wrapped or replaced.
  - **Visual polish.** Quasar's default Material look reads as "admin dashboard". A design-token layer (colour, spacing, type scale, density) is needed to reach the "commercial SaaS" bar.
  - **Bus factor.** The project is smaller than Vuetify or PrimeVue. *Mitigation:* keep business logic out of components and wrap Quasar components in a thin PAL component layer, so a framework swap stays bounded.
- **Alternatives considered:** PrimeVue (strong a11y effort, unstyled mode suits a custom design system). Vuetify 3 (larger community, Material). Quasar wins only if a ready-made enterprise kit is valued over design freedom. **RECOMMENDATION:** keep the Quasar baseline unless the Phase 1 a11y spike fails. In that case, switch to PrimeVue before Phase 2. See [ADR-0003](adr/ADR-0003-frontend-framework.md).
- **Token handling: BFF, not SPA tokens.** The SPA never holds access or refresh tokens. The backend runs the OIDC code flow, keeps tokens server-side, and issues an `HttpOnly; Secure; SameSite=Lax` session cookie. This removes token theft via XSS as a risk class. CSRF protection is then required, and is provided by Symfony CSRF tokens on every state-changing request. See [ADR-0004](adr/ADR-0004-authentication-bff.md).

---

## 7. Database, storage, search, preview, jobs

### 7.1 Database: PostgreSQL 17
Chosen for its transactional DDL, **range types plus exclusion constraints** (the effectivity guarantee in §11), `SELECT … FOR UPDATE SKIP LOCKED` (job claiming), built-in full-text search, row-level privileges, and portability between on-prem and Azure Database for PostgreSQL Flexible Server. Required extension: `btree_gist`, available on Azure Flexible Server via the `azure.extensions` allow-list. See [ADR-0005](adr/ADR-0005-database-and-search.md).

### 7.2 Document storage: content-addressed and immutable
- Each file is stored under its hash: `blobs/sha256/ab/cd/<full-hash>`. Users never see this key. **Identical bytes are stored once**, which makes rollback free.
- Objects are **write-once**. The application identity can create and read, **but not overwrite or delete** (On-prem: storage directory owned by a separate account; the app user has create/read only, and a restricted disposition service account).
- The interface is `BlobStore { put(stream): Sha256; openRead(Sha256): stream; exists(Sha256): bool }`, with `FileSystemBlobStore` (local/NAS, **primary for on-prem**) and `S3BlobStore` (any S3-compatible store, optional). An Azure Blob adapter is not needed under D0/D2.
- **Upload pipeline:** stream to a quarantine area while hashing → size limit → magic-byte type detection (extension is ignored for trust) → allow-list check → malware scan → promote to the immutable store → record `stored_file`. Nothing can be attached to a revision until the scan passes.
- **On-prem object storage:** default to the filesystem/NAS backend. MinIO's community distribution changed materially in 2025 (admin console removed, binary releases moved to source-only). Treat it as an option to be re-evaluated, not a default. **RISK** noted.

### 7.3 Search: PostgreSQL full-text first
- A `tsvector` column on a `search_document` projection, covering title, number, description, keywords, owner, department and extracted text, with GIN index and `websearch_to_tsquery` + `ts_rank`. `pg_trgm` handles typo-tolerant title and number lookup.
- **The permission filter lives inside the SQL query**, never as a filter applied afterwards. Readers search the *published projection* only. Draft text is indexed in a separate projection visible only to users with scoped access to that draft.
- **FINDING:** at the stated scale (low thousands of documents, tens of thousands of revisions), Postgres FTS is sufficient. Elasticsearch/OpenSearch would add a stateful cluster to secure, back up and keep in sync. Revisit only if relevance or volume demands it.

### 7.4 Preview and text extraction
- **PDF** is rendered client-side with **PDF.js** from an authorized, streamed endpoint.
- **Word / Excel / PowerPoint** are converted to PDF by **Gotenberg (LibreOffice)** in a container with **no network egress**, read-only root filesystem, CPU/memory/time limits and non-root user. LibreOffice will otherwise follow external links in documents (an SSRF vector). The generated rendition is itself a hashed, stored file linked to its source.
- **Plain text** is served as `text/plain; charset=utf-8` with `X-Content-Type-Options: nosniff`, rendered as text, never HTML.
- **Text extraction for search** runs `pdftotext` (poppler) on the rendition in the same isolated worker. Office macros are never executed. LibreOffice's macro security is set to never run macros.
- **No live HTML preview** of untrusted content. HTML uploads are not on the default allow-list.

### 7.5 Background processing
- **Separate worker containers** from the same codebase, scalable and restartable without affecting the web tier.
- **Symfony Messenger** with the **PostgreSQL (Doctrine) transport** is the durable queue: no Redis or RabbitMQ to operate. **Symfony Scheduler** handles schedules (publication sweeps every minute, review-due checks, notification digests, directory sync, audit-chain verification).
- **Transactional outbox:** messages are written in the same DB transaction as the business change, so a notification or reindex happens if and only if the change committed. Delivery is at-least-once and every handler is idempotent.
- **Failure transport + retry policy** (exponential backoff, capped attempts). An admin page lists failed messages with retry and discard actions (both audited).

### 7.6 Malware scanning
- **On-prem:** ClamAV (`clamd`) sidecar. Signatures update via freshclam, or an internal mirror in restricted networks.
- *(Azure / Defender for Storage path removed in rev 2: not applicable under D0/D2.)*
- Both sit behind a `MalwareScanner` interface. **ClamAV is the scanner for the on-prem target (D2).** "Scanner unavailable" fails closed: the file stays quarantined.

### 7.7 Authoring: native editor + file uploads (ADR-0009)
- **Native documents:** TipTap editor in Vue. Content is canonical ProseMirror JSON, validated against PAL's schema, stored as an immutable hashed blob and rendered server-side from the schema. It is never stored or served as raw client HTML. PDF renditions are made by Gotenberg.
- **File documents:** Word / Excel / PDF uploads through the quarantine → type-detection → ClamAV → promote pipeline (§7.2).
- **v1 editing model:** autosave + optimistic concurrency + soft checkout lock. Real-time co-authoring is deferred (D15). In-browser editing of Office files via ONLYOFFICE or Collabora is deferred (D14), since Microsoft is excluded.
- **Comparison:** structural diff for native documents (accurate). Metadata + extracted-text diff for files (best effort, labelled as such).

### 7.8 Logging and SIEM (ADR-0011)
Structured JSON logs (Monolog) in two streams, operational and security/audit, forwarded to **Microsoft Sentinel** through the organisation's existing collector (Azure Monitor Agent via Arc, or a syslog/CEF forwarder). The database audit table stays the system of record.

### 7.9 Confluence migration (ADR-0012)
A repeatable, idempotent import command reads on-prem Confluence through its REST API. Pages become native documents and attachments become file documents. Macros with no PAL equivalent are flagged for manual review. Imported revisions are released as `imported` historical revisions with owner-attested effective dates. Each batch gets a reconciliation report, and a pilot space goes first.

---

## 8. Data model

The central rule: **`document` is the stable identity. `revision` is the unit of content and approval, immutable once submitted. `publication` is the time-bounded fact of release. `workflow_instance` is one attempt to approve one revision. `audit_event` records all of it.** Point-in-time questions are answered from ranges and events, never from the "current" columns alone.

### 8.1 Entity overview

```
org_unit ─┐                     document_type ── numbering_scheme ── numbering_counter
          │                           │
user ─────┼──< document_role_assignment >── document ──< document_relationship >── document
          │                                  │  1
          │                                  │
          │                                  ├──< revision ──< revision_file >── stored_file (content-addressed)
          │                                  │      │  │                              └── stored_file (rendition)
          │                                  │      │  └──< workflow_instance ──< workflow_stage ──< workflow_task ──< approval_decision
          │                                  │      │            └── workflow_definition_version ── workflow_definition
          │                                  │      └──< publication (effective/visible periods, exclusion constraint)
          │                                  └──< periodic_review
          │
          └── audit_event (append-only, hash-chained)   outbox_message   notification   search_document (projection)
```

### 8.2 Core tables (abridged; full DDL is a Phase 1 deliverable as migrations plus `database/schema.sql`)

**`app_user`**: `id uuid PK`, `idp_tenant_id`, `idp_object_id` (Entra `oid`, **the immutable identity key**, never email/UPN), `display_name`, `email`, `org_unit_id`, `is_active`, `created_at`. Unique (`idp_tenant_id`, `idp_object_id`).

**`org_unit`**: departments, hierarchical (`parent_id`). Renames keep the `id`. Historical display uses snapshots (below).

**`document_type`**: `code` (POL, PRC, SOP, STD, WI, TPL, FRM, GDL, REF + admin-defined), `name`, `numbering_scheme_id`, `default_workflow_definition_id`, `review_interval_months`, `retention_rule`, `publish_rendition_only bool`, `allowed_mime_types text[]`.

**`numbering_scheme`** / **`numbering_counter`**: pattern such as `{TYPE}-{DEPT}-{SEQ:4}`. The counter row is locked with `SELECT … FOR UPDATE` when a number is allocated. A Postgres sequence is avoided so numbers are per-scheme and the transaction stays auditable.

**`document`** (identity, small and slowly changing):
`id`, `document_number` (unique, immutable once assigned), `title`, `description`, `document_type_id`, `owning_org_unit_id`, `owner_user_id`, `keywords text[]`, `disposition` (`ACTIVE | OBSOLETE | ARCHIVED`), `obsoleted_at`, `archived_at`, `legal_hold bool`, `next_review_due date`, `audience` (`ALL_EMPLOYEES | RESTRICTED`), `row_version` (optimistic concurrency), `created_by/at`, `updated_by/at`.
*Title and description also live on each revision.* The document row holds the *current* values for listing. The revision holds the values *as approved*, which is what history shows.

**`revision`** (content + approval unit):
`id`, `document_id`, `major int`, `minor int`, `label text` (rendered "2.0"), `label_confirmed bool`, `state` (§9: `DRAFT | IN_WORKFLOW | CHANGES_REQUESTED | REJECTED | APPROVED | WITHDRAWN`), `change_summary` (revision description), `previous_revision_id` (lineage), `restored_from_revision_id` (rollback provenance), `title_snapshot`, `description_snapshot`, `owner_snapshot jsonb` (id + name + org unit **at submission**), `content_manifest_hash` (SHA-256 over sorted file hashes + roles; set at submission), `submitted_at`, `approved_at`, `approved_label`, `row_version`, `created_by/at`.
Constraints:
- unique (`document_id`, `major`, `minor`) **where `label_confirmed`**
- **at most one open draft per document:** partial unique index on (`document_id`) where `state IN ('DRAFT','IN_WORKFLOW','CHANGES_REQUESTED')`. This is a deliberate v1 simplification (see D8).
- a DB trigger rejects any UPDATE of content columns or `revision_file` rows unless `state = 'DRAFT'` (defence in depth behind the domain rule).

**`stored_file`**: `sha256 char(64) PK`, `size_bytes`, `detected_mime`, `scan_status` (`PENDING | CLEAN | INFECTED | ERROR`), `scan_engine`, `scanned_at`, `storage_backend`, `created_at`. No filename. Names belong to the usage, not the bytes.

**`revision_file`**: `revision_id`, `sha256 FK`, `content_kind` (`NATIVE_JSON | FILE`, rev 2), `role` (`PRIMARY | ATTACHMENT | RENDITION | EMBEDDED_IMAGE`), `original_filename` (sanitized for display; never used as a path), `rendition_of_sha256`, `sort_order`.

**`workflow_definition`** / **`workflow_definition_version`**: definitions are versioned and immutable once used. An instance always points at the exact version it ran under. A version's `spec jsonb` holds ordered stages, each `{ kind: REVIEW|APPROVAL, mode: ALL|ANY|QUORUM(n), participants: [user | role-in-org-unit | document-role], required: bool, due_days }` plus SoD policy flags. Selected by (document_type, org_unit), with fallback to the type default.

**`workflow_instance`**: `id`, `revision_id`, `definition_version_id`, `initiated_by`, `status` (`ACTIVE | APPROVED | CHANGES_REQUESTED | REJECTED | CANCELLED`), `bound_manifest_hash` (copied from the revision at start), `current_stage_ordinal`, `started_at`, `completed_at`, `outcome_reason`, `row_version`. Partial unique: one `ACTIVE` instance per revision.

**`workflow_stage`**: `instance_id`, `ordinal`, `kind`, `mode`, `status`, `started_at`, `completed_at`.

**`workflow_task`**: `stage_id`, `assignee_user_id`, `required bool`, `delegated_from_user_id`, `reassigned_by`, `status` (`OPEN | DONE | SKIPPED | REASSIGNED | CANCELLED`), `due_at`.

**`approval_decision`** (insert-only): `id`, `task_id`, `actor_user_id`, `actor_snapshot jsonb`, `decision` (`APPROVE | REJECT | REQUEST_CHANGES | REVIEWED_OK | COMMENT`), `comment`, `revision_id`, **`manifest_hash_at_decision`**, `reauthenticated_at` (if D9), `decided_at`. Unique (`task_id`) where decision is terminal. A task can be decided once. Concurrent clicks collide on the constraint, not in application memory.

**`publication`**: `id`, `revision_id`, `document_id`, `scope_id`, `status` (`SCHEDULED | RELEASED | CANCELLED | FAILED`), `publish_at timestamptz`, `effective_at timestamptz`, `effective_local_date date`, `effective_tz text`, `visible_period tstzrange`, `effective_period tstzrange`, `is_emergency bool`, `justification`, `scheduled_by`, `released_by` (user, or the system principal for scheduled release), `released_at`, `superseded_by_publication_id`, `idempotency_key uuid UNIQUE`.
- **Exactly one effective revision per document per scope at any instant:**
  `EXCLUDE USING gist (document_id WITH =, scope_id WITH =, effective_period WITH &&) WHERE (status = 'RELEASED')`
- Same constraint on `visible_period`.
- `effective_period` lower bound = `effective_at`. Upper bound = the successor's `effective_at`, open (`NULL`) while current.

**`periodic_review`**: `document_id`, `revision_id`, `due_date`, `outcome` (`NO_CHANGE | REVISION_STARTED | OBSOLETED`), `completed_by`, `completed_at`, `comment`.

**`document_role_assignment`** (superseded in rev 2 by `access_grant`, ADR-0010): `document_id`, `user_id` *or* `group_id`, `role` (`OWNER | AUTHOR | REVIEWER | APPROVER | READER`), `granted_by`, `valid_from`, `valid_to`. Ending a role sets `valid_to` rather than deleting the row, so who held a role on a past date stays answerable.

**`document_relationship`**: `from_document_id`, `to_document_id`, `kind` (`REFERENCES | IMPLEMENTS | USES_FORM | PARENT_OF`).

**`audit_event`**: see §13.

**Rev 2 additions:**
- **`directory_group`** (`id`, `directory_object_id` unique, `display_name`, `synced_at`) and **`group_membership`** (`group_id`, `user_id`, `valid_from`, `valid_to`). Membership history is kept, so "who could read this on date X?" stays answerable.
- **`access_grant`** (`scope_type CATEGORY|DOCUMENT`, `scope_id`, `principal_type USER|GROUP`, `principal_id`, `role`, `valid_from`, `valid_to`, `granted_by`) and **`category`** (hierarchical, `restricted bool`).
- **`document_access`**: materialized effective access (`document_id`, `principal_id`, `role`) joined inside every list/search query.
- **`app_user.timezone`** (IANA name, defaulting from the browser and changeable by the user) and **`org_setting.default_timezone`**.
- **`import_mapping`** (`source_system`, `source_id`, `source_version`, `document_id`, `revision_id`, `batch_id`) for the idempotent Confluence import.

**`outbox_message`**, **`notification`**, **`job_failure`**, **`search_document`** (projection keyed by `(revision_id, visibility_class)`).

### 8.3 Historical accuracy rules
- People and departments are referenced by stable `id` **and** snapshotted (`*_snapshot jsonb`) at the moment of a governed action (submission, decision, release). Renames and departures never rewrite history.
- "Current" columns (`document.title`, `document.owner_user_id`) are a convenience for listings. Every governance question is answered from revisions, decisions, publications, role-assignment validity ranges and audit events.

### 8.4 How the required audit questions map to the model

| Question | Answered by |
|---|---|
| Who authored this document? | `revision.created_by` per revision + AUTHOR assignments (with validity ranges) + audit events |
| Who approved revision 2.0? | `approval_decision` ⋈ `workflow_task` ⋈ `workflow_instance` where `revision.label='2.0'` and outcome `APPROVED` |
| What exact file was approved? | `approval_decision.manifest_hash_at_decision` → `revision_file` → `stored_file.sha256` (re-hash the bytes to prove it) |
| When was 2.0 published? | `publication.released_at` (visible) and `effective_at` (effective), kept distinct |
| Which revision was effective on date D? | `SELECT … FROM publication WHERE document_id=$1 AND status='RELEASED' AND effective_period @> $D` (exactly 0 or 1 row, guaranteed by the constraint) |
| Why was a revision rejected? | `approval_decision.comment` where decision = REJECT + `workflow_instance.outcome_reason` |
| Who changed the owner? | `audit_event` type `DOCUMENT_OWNER_CHANGED` with before/after |
| Which revisions were superseded? | `publication` rows with closed `effective_period` / `superseded_by_publication_id` |

---

## 9. Lifecycle state machine

### 9.1 Where each spec status actually lives

| Spec status | Stored where | Stored or derived |
|---|---|---|
| Draft | `revision.state = DRAFT` | Stored |
| Submitted for Review / In Review | `revision.state = IN_WORKFLOW` + active `workflow_stage.kind = REVIEW` | Derived |
| Changes Requested | `revision.state = CHANGES_REQUESTED` | Stored |
| Pending Approval | `IN_WORKFLOW` + active stage kind `APPROVAL` | Derived |
| Rejected | `revision.state = REJECTED` | Stored |
| Approved | `revision.state = APPROVED`, no publication | Derived |
| Scheduled for Publication | `APPROVED` + `publication.status = SCHEDULED` | Derived |
| Published | `publication.status = RELEASED`, `visible_period` contains now | Derived |
| (Effective) | `effective_period` contains now. **Not in the spec's list but needed:** "Published, effective from Nov 5" | Derived |
| Superseded | Released publication whose period has closed because a successor released | Derived |
| Obsolete | `document.disposition = OBSOLETE` | Stored (document) |
| Archived | `document.disposition = ARCHIVED` | Stored (document) |

**Why:** if all 12 lived in one revision column, "Published" and "Superseded" would have to be updated on the *old* revision whenever the *new* one releases, which is an extra write that can fail halfway (Scenario 9). Deriving them from publication ranges makes supersession a single atomic insert/update on `publication`.

### 9.2 Revision state transitions (enforced in the domain layer, re-checked by DB constraints)

```
                 submit (author/owner; content frozen, manifest hash computed)
   ┌────────┐ ───────────────────────────────────────────▶ ┌─────────────┐
   │ DRAFT  │                                              │ IN_WORKFLOW │
   └────────┘ ◀───────── cancel workflow (initiator/admin) ─└─────────────┘
     ▲  ▲  │                                                 │    │    │
     │  │  │ withdraw (owner)                 request changes│    │    │ all required approvals complete
     │  │  ▼                                                 ▼    │    ▼
     │  │ ┌───────────┐   revise (author) ┌───────────────────┐   │  ┌──────────┐
     │  │ │ WITHDRAWN │ (terminal)        │ CHANGES_REQUESTED │   │  │ APPROVED │ (immutable, terminal for content)
     │  │ └───────────┘                   └───────────────────┘   │  └──────────┘
     │  └─────────────────── revise ────────────┘                 │       │ schedule / publish (Publisher) → publication rows
     │                                                   reject   ▼
     └─────────────── reopen as draft (owner, explicit) ── ┌──────────┐
                                                           │ REJECTED │
                                                           └──────────┘
```

Rules:
1. Content (files, title/description snapshot) is editable **only in `DRAFT`**. Enforced by the domain service and the DB trigger.
2. `submit` computes `content_manifest_hash` and starts a `workflow_instance` bound to that hash.
3. `CHANGES_REQUESTED → DRAFT` (revise) and `REJECTED → DRAFT` (reopen, an explicit and audited action with a reason) re-open the **same revision** for edits. The old workflow instance stays `CHANGES_REQUESTED`/`REJECTED` forever. A resubmission creates a **new** instance bound to the **new** hash, so earlier decisions can never carry over.
4. `APPROVED` is final for content. Any change, including a typo, requires a **new revision** (Scenario 8).
5. Every transition is a single DB transaction that checks the expected `row_version`, writes the change, the audit event and outbox messages, then commits. A stale `row_version` causes the transition to be rejected with HTTP 409.
6. **Server-side only.** The API exposes commands (`POST /revisions/{id}:submit`), not `PATCH state`. The frontend shows only actions the backend reports as permitted (`allowedActions` in each response), but it is never the enforcement point.

### 9.3 Document-level transitions
`ACTIVE → OBSOLETE` (PAL Admin, reason required. Closes the current effective period at a chosen instant. No replacement.) · `OBSOLETE → ARCHIVED` (retention rule or PAL Admin) · `ARCHIVED → ACTIVE` (System/PAL Admin, audited, rare). Legal hold blocks disposition.

### 9.4 Concurrency: published, draft and history at once
`document` → current effective `publication` (rev 1.0) + one open draft revision (1.1) + N superseded publications. A reader always resolves "current" via `publication`, never via "latest revision", so draft 1.1 is invisible to readers until its publication releases (Scenario 2).

---

## 10. Workflow engine

- **Definitions are data, versioned** (§8.2). Supported: single approver, ALL (parallel), ANY, QUORUM(n), sequential stages, required/optional participants, per-type and per-department selection, due dates with escalation.
- **Participant resolution** happens **at instance start** and is materialized as `workflow_task` rows, so later org changes don't silently alter an in-flight approval. Admin reassignment and user delegation create new tasks linked to the original and are audited.
- **Separation of duties** (configurable per definition): author ≠ approver (default ON), submitter ≠ approver, the same person may not act in two stages, and the publisher may not be the sole approver (optional). It is evaluated at task materialization **and again at decision time**, because roles can change in between.
- **Approval integrity (Scenario 8):** a decision records `manifest_hash_at_decision`. Stage completion requires every terminal decision's hash to equal `workflow_instance.bound_manifest_hash`. Release requires the revision's current manifest hash to equal the approved hash, and re-hashes the primary file bytes from storage if configured (verify-on-release).
- **Concurrent decisions (Scenario 10):** each decision is an INSERT protected by a unique constraint per task. Stage evaluation runs under `SELECT … FROM workflow_instance WHERE id=$1 FOR UPDATE`, so two final approvers who click at the same instant serialize. One completes the stage and the other's decision is still recorded. A REJECT that arrives concurrently with the last APPROVE is resolved deterministically by lock order and both decisions persist. Policy: **any required reject wins** if it commits before the stage closes. After closure, a late decision is refused with 409 and nothing is lost.
- **Cancellation:** the initiator or an admin, with a reason. Open tasks are cancelled and the revision returns to `DRAFT`.
- **Escalation:** the worker checks `due_at` and notifies the assignee, then the owner and PAL Admin. Escalation never auto-approves.

---

## 11. Publication and effective dates

- **Approval ≠ publication ≠ effectivity.** Three separate timestamps. `publish_at ≤ effective_at` is validated, and both may be "now".
- **Scheduling** creates `publication(status=SCHEDULED, idempotency_key)`. Cancel and reschedule are allowed until release, and each is audited.
- **Release transaction** (identical for immediate, scheduled and emergency):
  1. `SELECT … FROM document WHERE id=$1 FOR UPDATE` (serializes releases per document)
  2. `UPDATE publication SET status='RELEASED', released_at=now(), … WHERE id=$p AND status='SCHEDULED' RETURNING *`. **Zero rows means it is already released or cancelled: no-op, exit.** (Idempotency: the scheduler running twice cannot double-publish.)
  3. Verify approval integrity (§10).
  4. Close the predecessor's `visible_period` at `publish_at` and its `effective_period` at `effective_at`. Open the new one's. The exclusion constraint makes any overlap a hard error that rolls back the whole transaction.
  5. Write audit events, outbox messages (notifications, reindex) and commit.
- **Visible vs. effective (Scenario 4):** between Nov 1 and Nov 5, 2.0 is *published* (readers can view it, labelled "Effective from 5 Nov 2026") while 1.x is still *effective* (labelled "Current until 5 Nov"). The library defaults to showing the effective revision, with a clear banner for the upcoming one. **DECISION D11** confirms this display rule.
- **Time handling across many time zones (rev 2, D6):** with one organisation-wide scope, a revision becomes effective at **one instant for everyone**. Per-user local midnight would mean two revisions are effective at once somewhere in the world, which breaks the core guarantee.
  - The publisher picks **date + time + timezone**. The timezone defaults to the organisation's default timezone (D6a), and the time defaults to 00:00.
  - PAL stores the UTC instant **and** the entered local values (`effective_local`, `effective_tz`) for display and audit.
  - Every user sees times in their own timezone, labelled. For example: "Effective 5 Nov 2026, 00:00 America/New_York (06:00 your time, Europe/Berlin)". Notification emails show both.
  - DST is resolved by the IANA timezone database at entry time. Nonexistent or ambiguous local times during DST changes (e.g. 02:30 on a spring-forward night) are **rejected with a clear message**, not guessed.
  - The scheduler sweeps every minute for `publish_at ≤ now() AND status='SCHEDULED'`, so **downtime self-heals**: overdue releases run on restart. Effectivity needs no job at all, because it is computed from ranges at read time.
- **Crash mid-release (Scenario 9):** the release is one DB transaction. A crash before commit rolls back completely, and the next sweep retries. A crash after commit is caught by step 2 on retry. Side effects (email, reindex) go through the outbox, so they cannot run unless the release committed, and they retry until delivered.
- **Emergency publication** (C2): same transaction, `is_emergency=true`, PAL Admin only, justification mandatory, and it creates a follow-up review obligation and banner.

---

## 12. Roles and authorization model

**Two layers, both enforced in the backend:**

1. **Global roles** (from Entra app roles or group mapping, per D4): `SystemAdministrator`, `PalAdministrator`, `Publisher`, `Reader` (all employees by default). Global `Author` means "may create new documents".
2. **Category- and document-scoped roles granted to users or directory groups** (`access_grant`, rev 2, ADR-0010): `Owner`, `Author`, `Reviewer`, `Approver`, `Reader`. Documents inherit their category's grants and can add their own. Grants are allow-only, with no deny rules. A category or document marked *restricted* drops the default all-employee read access. Groups are synced from the directory (Entra via Graph, or LDAP/AD, per D18), never taken from token claims.

**Permissions are fine-grained strings, checked per action**, for example:
`document.create`, `document.edit_metadata`, `document.change_owner`, `revision.create_draft`, `revision.edit`, `revision.submit`, `revision.withdraw`, `workflow.decide`, `workflow.cancel`, `workflow.reassign`, `publication.schedule`, `publication.release`, `publication.emergency`, `document.obsolete`, `document.archive`, `document.legal_hold`, `admin.workflows`, `admin.numbering`, `admin.types`, `admin.retention`, `audit.read`, `audit.export`, `report.export`, `system.config`.

**Evaluation:** Symfony **security voters** (`DocumentVoter`, `RevisionVoter`, `WorkflowTaskVoter`) combine global role → permission map, document-scoped assignments valid *now*, the revision state (e.g. `revision.edit` only in `DRAFT`) and SoD rules. Every handler, query and download goes through it.

**Notable rules:**
- `SystemAdministrator` does **not** imply document authority. Sysadmins configure the system but cannot approve or publish unless separately assigned. This limits insider blast radius and supports SoD.
- Drafts and in-workflow revisions are visible only to the owner, authors, assigned reviewers and approvers, and PAL Admins. Everything else returns **404, not 403**, so their existence doesn't leak (Scenario 7).
- Authorization is applied in **queries** (list/search filtered in SQL), not only on single-object fetches. That closes the IDOR-via-listing class.

---

## 13. Audit trail design

- **`audit_event`**: `id bigint identity`, `event_id uuid`, `occurred_at`, `event_type`, `actor_user_id`, `actor_snapshot`, `actor_ip` (optional; privacy review), `correlation_id` (request trace id), `document_id`, `revision_id`, `workflow_instance_id`, `publication_id`, `from_state`, `to_state`, `changes jsonb` (field-level before/after for metadata), `reason`, `prev_hash`, `hash` (SHA-256 of canonical JSON of the row + `prev_hash`).
- Written **in the same transaction** as the change it describes. If the change commits, the audit commits too. No audit, no change.
- **Privilege separation:** `pal_migrator` owns the schema (deploy-time only, credentials not available to the running app). `pal_app` has `INSERT, SELECT` on `audit_event` and **no UPDATE/DELETE/TRUNCATE**. A trigger also raises on UPDATE/DELETE as a backstop against owner-level mistakes.
- **Tamper evidence:** the hash chain is verified by a scheduled job and on demand (`/admin/audit/verify`). The chain head is **anchored daily** to an external write-once location: on-prem, a WORM / append-only share or immutable S3-compatible bucket (object lock) owned by a different team, or a signed digest sent to a compliance mailbox. A DBA who rewrites rows and recomputes hashes still cannot rewrite the anchors.
- **Export:** CSV/JSON by date range, document or actor, including chain-verification status. Exporting is itself audited.
- **Reconstruction:** the "as-of" view of a document renders metadata, effective revision, decisions and assignments at timestamp T from publications, assignment validity ranges and audit events (Scenario 6).

---

## 14. Deployment architectures and cost

Same container images in every environment. Environment differences are **configuration only** (dev / test / staging / prod).

### 14.1 Components

| Component | Mandatory | Notes |
|---|---|---|
| `pal-web` (nginx + PHP-FPM / Symfony; serves the SPA and the BFF API) | Yes | Stateless, horizontally scalable; sessions in PostgreSQL |
| `pal-worker` (Messenger consumers) + `pal-scheduler` | Yes | Same image, different entrypoint; ≥1 consumer, exactly 1 scheduler |
| PostgreSQL 17 | Yes | `btree_gist`, `pg_trgm` |
| Blob storage (filesystem/NAS; S3 optional) | Yes | Immutable, write-once permissions, snapshots |
| Malware scanner | Yes | ClamAV (`clamd`) |
| `pal-converter` (Gotenberg) | Yes for Office preview | No egress; internal only |
| Reverse proxy / TLS | Yes | nginx on-prem (internal CA or public cert) |
| SMTP relay (Graph optional, D18) | Yes | Dedicated sender address |
| Log forwarding to Microsoft Sentinel | Yes (prod) | AMA via Arc, or existing syslog/CEF forwarder (ADR-0011) |
| Health / uptime monitoring | Yes (prod) | Sentinel is not uptime monitoring; target TBD (D17) |
| WAF | Optional | Recommended if internet-exposed |
| Redis | **No** | Not required by this design |
| Kubernetes | **No** | Not justified at this scale (see ADR-0001) |

### 14.2 Option A: On-premises (Linux), **selected (D2)**

```
            Users (corporate network / VPN)
                        │ HTTPS 443
                 ┌──────▼───────┐
                 │ nginx (TLS)  │  host A (app host, Docker Compose)
                 └──────┬───────┘
          ┌─────────────┼───────────────┐
     ┌────▼────┐   ┌────▼─────┐   ┌─────▼──────┐   ┌──────────┐
     │ pal-web │   │pal-worker│──▶│pal-converter│   │  clamd   │
     └────┬────┘   └────┬─────┘   │ (no egress) │   └──────────┘
          │             │          └────────────┘
   ┌──────▼─────────────▼──────┐        ┌───────────────────────┐
   │ PostgreSQL 17 (host B)    │        │ NAS / blob volume      │
   │ WAL archiving → backup    │        │ (immutable perms, snaps)│
   └───────────────────────────┘        └───────────────────────┘
        Outbound only: identity provider (OIDC), directory (Graph or LDAP), SMTP relay, ClamAV mirror,
        log collector → Microsoft Sentinel
```
- Two VMs minimum for prod (app; DB). DB on its own host for resource isolation and backup control.
- Backups: `pgBackRest` (full weekly, incremental daily, continuous WAL → RPO minutes), NAS snapshots + off-site copy, restore drills quarterly.
- Logs: structured JSON → the organisation's Sentinel collector (ADR-0011). Health/uptime monitoring target is still unknown (D17).
- **Cost:** $0 software licences. Real costs: 2–3 VMs (existing virtualization), backup storage, and **operations labour** (patching OS/Postgres/ClamAV, backups, certs). Labour is typically the dominant cost and is usually underestimated.

### 14.3 Option B: Microsoft Azure, **not selected** (D0/D2; kept only as a documented future path)

```
 Users ──HTTPS──▶ Azure Container Apps environment (VNet-integrated)
                   ├─ pal-api      (ingress: external or internal)
                   ├─ pal-worker   (no ingress)
                   └─ pal-converter(internal ingress, egress blocked via NSG/UDR)
                         │  Managed Identity (no secrets)
      ┌──────────────────┼──────────────────────┬──────────────────────┐
 Azure DB for PostgreSQL  Azure Blob Storage      Azure Key Vault         Entra ID (OIDC)
 Flexible Server (private (quarantine + immutable (cert, any residual     
 endpoint, zone-redundant  containers, versioning, secrets)
 HA optional, PITR)        Defender malware scan)
                              │
                        Log Analytics + Application Insights (OpenTelemetry)
```
- **Hosting choice:** Azure Container Apps (scale-to-1, jobs, internal ingress, managed identity). App Service for Containers is an equally valid, simpler alternative if the org already standardizes on it. AKS is not justified.
- **Email:** Microsoft Graph `sendMail` with application permission **scoped to one mailbox** (Exchange RBAC for Applications / application access policy), or Azure Communication Services Email.
- **Indicative monthly cost, production only (USD, Commercial cloud, list price, order of magnitude only; validate with the Azure Pricing Calculator for your region and agreement):**

| Item | Indicative range |
|---|---|
| PostgreSQL Flexible Server, General Purpose 2 vCore + 128 GB, backups | ~$150–$250 (zone-redundant HA roughly doubles compute) |
| Container Apps (api ×2, worker ×1, converter ×1, always-on, small) | ~$80–$250 |
| Blob Storage (≤ 500 GB, hot/cool mix, versioning) | ~$10–$30 |
| Defender for Storage (per-account fee + per-GB scanned) | ~$10–$40 at modest upload volume |
| Key Vault | < $5 |
| Log Analytics / App Insights (depends heavily on ingestion volume) | ~$20–$150 |
| **Production total** | **~$300–$700 / month** |
| Non-prod (dev/test/staging, smaller SKUs, scale-down) | add ~40–70% |

  **These figures are estimates, not quotes.** Azure Government pricing is typically higher than Commercial. Reserved capacity reduces DB compute materially.

---

## 15. Threat model

STRIDE-oriented, top risks ranked by likelihood × impact for this system.

| # | Threat | Vector | Mitigation |
|---|---|---|---|
| T1 | **Malicious upload** (macro malware, polyglots, zip bombs, parser exploits) | Upload | Magic-byte detection, MIME allow-list, size + decompression-ratio limits, AV scan before promotion (fail closed), macros never executed, conversion in a sandbox with no egress and resource limits |
| T2 | **Draft / restricted content leakage** | Search, list, download, preview, notifications | Authorization in SQL for every list/search; separate published vs. draft search projections; 404-not-403; every download/preview authorized per request; emails contain links, never content |
| T3 | **IDOR on files** | Guessing IDs/hashes in download URLs | Downloads keyed by `(revision_id, file_id)` and authorized; never by raw hash/path; Azure SAS only as short-lived (≤5 min) *user-delegation* SAS minted after authorization, or (preferred) stream through the API |
| T4 | **Approval integrity bypass** | Editing a file after approval, swapping blobs | Content freeze on submit, DB trigger, hash-bound decisions, verify-on-release, write-once storage |
| T5 | **Privilege abuse / insider** | Admin self-approves or edits audit | Sysadmin ≠ document authority, SoD at decision time, audit role separation + hash chain + external anchoring, admin actions themselves audited |
| T6 | **Session / token theft** | XSS, token leakage | BFF (no tokens in browser), strict CSP with nonces, HttpOnly/Secure/SameSite cookies, short idle timeout, OIDC re-auth for approvals (if D9) |
| T7 | **CSRF** | Cookie session | Symfony CSRF tokens on all state-changing requests + SameSite=Lax + no state change on GET |
| T8 | **XSS** | Titles, comments, filenames rendered in UI | Vue's default escaping, no `v-html` with user data (lint rule), CSP, filename sanitization, `Content-Disposition: attachment` for downloads |
| T9 | **SSRF / XXE** | LibreOffice fetching external resources, OOXML parsers | Converter has no network; XML parsing with DTD/external entities disabled |
| T10 | **Path traversal** | Original filenames | Filenames never touch the filesystem; storage keys are hashes |
| T11 | **Scheduler race / double publish** | Duplicate workers, retries | Row lock + conditional update + exclusion constraint + idempotency key |
| T12 | **Supply chain** | NuGet/npm packages, base images | Lockfiles, Dependabot/Renovate, SCA in CI (fail on high/critical), pinned and scanned container images (Trivy), SBOM (CycloneDX), secret scanning (gitleaks) |
| T13 | **Secrets exposure** | Config, logs | Managed identity on Azure, Key Vault/Docker secrets on-prem, no secrets in repo/images, structured logging with redaction |
| T14 | **Mail abuse** | Over-privileged Graph permission | Scope `Mail.Send` to one mailbox |
| T16 | **Stored XSS via the editor** | Crafted editor JSON, pasted HTML, embedded images/links | Schema-validated JSON only; server-side rendering from the schema; HtmlSanitizer as a second layer; `javascript:` and data URLs blocked in links; images through the upload pipeline; strict CSP with nonces |
| T17 | **Import as an attack path** | Malicious Confluence content or attachments | Same sanitize/scan pipeline as uploads; read-only service account; import runs offline from user traffic |
| T15 | **DoS** | Large uploads, expensive search/conversion | Request size limits, rate limiting (Symfony RateLimiter) per user/IP, conversion queue with concurrency cap and timeouts |

**Verification target:** OWASP ASVS **Level 2**. A requirement-by-requirement checklist with evidence links is a Phase 7 exit artifact, started in Phase 1.

---

## 16. Phased delivery plan

Changes from the spec's plan: (1) a **thin end-to-end slice by the end of Phase 2**, (2) **security and test gates in every phase**, (3) migration/import added. Each milestone ends with a demo, passing CI, an updated traceability matrix, and a session report.

| Phase | Scope | Exit criteria (objective) |
|---|---|---|
| **0 — Architecture** *(this doc)* | Proposal, ADRs, data model, state machine, threat model, plan | Owner approves; D0–D11 answered or deferred explicitly |
| **1 — Foundation** | Repo layout (`pal/backend`, `pal/frontend`, `pal/deploy`), Symfony app with module boundaries (Deptrac), Doctrine migrations + `database/schema.sql`, BFF + OIDC (IdP per D18), directory group sync, role mapping, permission handler skeleton, health/readiness, OpenTelemetry logging, CI (build, test, SAST, SCA, secret scan, container scan), Docker Compose, Render/Azure config, Quasar shell + design tokens, **a11y spike**, the standard doc set | Login works against a dev Entra tenant; unauthenticated → 401; permission test matrix green; CI fails on seeded vulnerable dependency; axe clean on shell; restore of empty DB from backup demonstrated |
| **2 — Repository + walking skeleton** | Document/type/number entities, upload pipeline (quarantine, detect, scan, promote), blob abstraction (filesystem/NAS + S3), metadata, library list/search (FTS), details page, **single-approver workflow + immediate publish (thin)** | **Scenario 1 and 7 pass as automated tests**; infected test file (EICAR) stays quarantined; spoofed extension rejected; reader cannot fetch draft by ID (404) |
| **3 — Versioning + native editor** | Revisions, content freeze, manifest hashing, numbering policies, history, rollback-as-new-revision, metadata diff. **Native editor v1** (ADR-0009): schema, autosave, checkout, templates, images, server-side rendering, PDF rendition, structural diff | **Scenario 2, 8** pass, for both native and file documents; DB trigger blocks direct content update; XSS test corpus produces no script execution; editor passes axe + keyboard checks |
| **4 — Workflow engine** | Definitions/versions, sequential/parallel/quorum, required/optional, SoD, reassignment/delegation, cancellation, escalation, re-auth (if D9) | **Scenario 3, 10** pass, including a concurrency test firing N parallel decisions; SoD violations refused with audit |
| **5 — Publication** | Scheduling, separate effective dates, timezone handling, supersession, emergency publish, periodic review, obsolete/archive, legal hold | **Scenario 4, 5, 9** pass, including fault injection (kill worker mid-release, double scheduler); DST-boundary tests; exclusion constraint proven by negative test |
| **6 — Experience** | Dashboard, saved views, previews (PDF.js + converter), notifications (email + in-app), admin consoles, reports (CSV/XLSX), as-of view, audit export | **Scenario 6** passes; previews of malicious samples are contained; WCAG 2.2 AA audit on core journeys |
| **6b — Confluence migration** (can run in parallel after Phase 3) | Import command, macro mapping, user/permission mapping, dry-run + reconciliation reports, pilot space, cutover runbook | Pilot space imported with 100% of pages and attachments accounted for (imported or flagged); re-run is idempotent; owner signs off the pilot |
| **7 — Security & quality** | ASVS L2 evidence, pen-test (external if possible), load test, DR drill, a11y manual testing | All criteria in §17 |
| **8 — Production readiness** | Admin/user guides, runbook, monitoring dashboards and alerts, Sentinel detections handed to the SOC, release checklist, UAT | Owner sign-off on UAT; go-live checklist complete |
| **9 — Optional, post-v1** | In-browser Office editing (D14), real-time co-authoring (D15) | Separate approval |

Sizing is deliberately not estimated in calendar time until D1/D2 (team size and skills) are known.

---

## 17. Production readiness acceptance criteria

1. **Functional:** all 10 acceptance scenarios are automated (integration or E2E) and pass in CI on the release commit.
2. **Security:** ASVS L2 checklist complete with evidence. Zero open critical/high findings from SAST, SCA, container scan and pen-test. Secret scan clean. CSP enforced with no `unsafe-inline` scripts. Authorization test matrix (every endpoint × every role × in-scope/out-of-scope document) green.
3. **Integrity:** audit chain verification passes over the full dataset. The exclusion-constraint negative test passes. Verify-on-release detects a tampered blob in test.
4. **Performance** (targets to confirm, D10): with 100 concurrent active users and a dataset of 5,000 documents / 50,000 revisions, library search p95 ≤ 800 ms, document details p95 ≤ 500 ms, 50 MB upload completes with scan in ≤ 60 s. Zero errors over a 30-minute soak.
5. **Resilience:** a restore drill meets RPO ≤ 15 min and RTO ≤ 4 h. Worker kill/restart during scheduled release leaves no inconsistency. DB failover tested if HA is chosen.
6. **Accessibility:** axe-core shows zero serious/critical violations on all primary views. Manual keyboard + screen reader pass on Scenarios 1, 3 and 4 journeys.
7. **Operability:** health/readiness endpoints, dashboards and alerts (error rate, job failures, queue age, scan failures, cert expiry, backup age). Runbook covers the top 10 failure modes.
8. **Documentation:** the standard doc set (deployments ×6, docs ×4, README, OPEN_ITEMS, CLAUDE.md, Dockerfile, render.yaml) is accurate to the release.

---

## 18. Decisions required before Phase 1

### 18.1 Answered (rev 2)
D0 custom build, not on SharePoint/Microsoft · D1 PHP · D2 on-prem Linux · D3 full in-browser editor + Word/Excel/PDF uploads · D5 group access · D6 many time zones → effective at one instant, shown in local time · D7 one organisation-wide scope · D12 portfolio now, private repo at handover. Defaults accepted with no objection: D4 (directory groups → global roles), D8 (numbering), D9 (re-sign-in to approve), D11 (show the effective revision plus an "upcoming revision" banner).

### 18.2 Still open

| ID | Decision | Options | Recommendation |
|---|---|---|---|
| **D18** | **Identity provider.** "Not Microsoft" vs. the spec's Entra ID SSO. Does sign-in still use Entra ID? | Entra ID (OIDC) / on-prem AD via LDAP + an OIDC broker (e.g. Keycloak) / another IdP | Keep **Entra ID for sign-in only** if that's where your accounts live. PAL talks plain OIDC, so it can be swapped later. |
| **D13** | **PHP framework** | Symfony / Laravel / in-house | Symfony (ADR-0008) |
| D6a | Organisation's default timezone for scheduling | Headquarters timezone / UTC | Headquarters timezone (most intuitive for publishers); users still see their own local time |
| **D10** | **Recovery targets (provisional)** | — | **RPO 15 min** (at most the last 15 minutes of edits could be lost after a server failure) and **RTO 4 h** (PAL back within half a working day). Confirm with IT; tightening later mostly adds a standby DB server, not a redesign. |
| D14 | In-browser editing of Word/Excel files | Defer / ONLYOFFICE Docs / Collabora Online | Defer to Phase 9; download → edit → upload until then |
| D15 | Real-time co-authoring in the native editor | Defer / add a Node.js collaboration service | Defer; single-editor checkout in v1 |
| D16 | Directory read permission | Graph `GroupMember.Read.All` (admin consent) / LDAP read-only bind | Depends on D18 |
| D17 | Sentinel forwarding path and uptime monitoring | Azure Monitor Agent via Arc / existing syslog-CEF forwarder; monitoring target TBD | Use whatever your SOC already runs |
| D19 | Confluence facts | Edition + version; number of spaces/pages; must page history migrate? Is an approvals app (e.g. Comala) in use? | Inventory before Phase 6b |

### 18.3 Handover to the private company repo (D12)
Before handover: move `pal/` to the private repository with full history (`git subtree split`), rotate any credentials ever used in development, and keep only a summary card and generic architecture here afterwards. Until then: no real tenant IDs, hostnames, org structure, Confluence content or document content in this repo.

> **D12 note (RISK):** this repository is public (GitHub Pages). Generic architecture is fine to publish. Real tenant IDs, hostnames, document content, org structure or deployment specifics of the organization must **not** be committed here. If PAL is built for a real employer, recommend developing in a private repository and keeping only a portfolio summary here.
