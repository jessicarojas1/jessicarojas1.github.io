# PAL Requirements Traceability Matrix

Spec section → design location → phase → verification. See **Phase 1 status** and **Phase 2 status** below the table for what is implemented and tested.

| Req | Requirement (spec §) | Design | Phase | Verification |
|---|---|---|---|---|
| R1 | Identity separate from immutable revisions (§4) | Proposal §8.2, ADR-0007 | 2–3 | DB trigger test; Scenario 8 |
| R2 | Historical accuracy on people/org changes (§4) | §8.3 snapshots, assignment validity ranges | 2–4 | As-of reconstruction test |
| R3 | Server-enforced lifecycle state machine (§5) | §9, ADR-0007 | 3–5 | Transition matrix unit tests (all illegal transitions refused) |
| R4 | Published + draft + history coexist (§5) | §9.4 | 3 | Scenario 2 |
| R5 | Exactly one effective revision per scope (§5) | §11, ADR-0005 exclusion constraint | 5 | Negative constraint test; Scenario 5 |
| R6 | Configurable workflows: single, multi, sequential, parallel (§6) | §10 | 4 | Workflow engine tests per mode |
| R7 | Approval bound to revision + checksum (§6) | §10, §8.2 `approval_decision` | 4–5 | Scenario 8; verify-on-release tamper test |
| R8 | Separation of duties (§6) | §10 | 4 | SoD refusal tests |
| R9 | Approval ≠ publication; scheduled; separate effective date (§7) | §11 | 5 | Scenario 4 |
| R10 | Idempotent, crash-safe publication (§7) | §11 release transaction | 5 | Scenario 9 (fault injection) |
| R11 | Timezone/DST handling (§7) | §11 time handling | 5 | DST boundary tests |
| R12 | Versioning, numbering policies, rollback as new release (§8) | §8.2, C3 | 3 | Rollback history test |
| R13 | Revision comparison (§8) | §3 (metadata v1, text diff Phase 6) | 3/6 | Diff tests |
| R14 | Repository, categories, search, saved views (§9) | §7.3, §8.2 | 2/6 | Search permission tests; Scenario 7 |
| R15 | WCAG 2.2 AA UI (§10) | ADR-0003 gate | 1/6/7 | axe + manual SR tests |
| R16 | Global + document/category-scoped RBAC with group access, backend-enforced (§11, owner D5) | §12, ADR-0010 | 1–4 | Endpoint × role × scope matrix |
| R17 | Append-only, tamper-evident audit (§12) | §13 | 1–7 | Chain verification; privilege test (UPDATE denied) |
| R18 | ASVS L2 security baseline (§13) | §15 | 1–7 | ASVS checklist with evidence |
| R19 | Secure upload: type, size, malware, untrusted content (§13) | §7.2, §7.6, T1 | 2 | EICAR + spoofed-extension tests |
| R20 | Safe preview, isolated conversion (§14) | §7.4, T9 | 6 | Malicious sample containment test |
| R21 | Notifications + durable idempotent jobs (§15) | §7.5 outbox + Symfony Messenger/Scheduler | 4–6 | Retry/dedupe tests |
| R22 | Admin dashboard + CSV/XLSX reports (§16) | §16 Phase 6 | 6 | Report reconciliation test |
| R23 | On-prem Linux deployment, portable later (§17, owner D2) | §14.2, abstractions | 1 | Compose deployment on a clean Linux host from docs alone |
| R24 | Acceptance scenarios 1–10 (§20) | §16 phase exits | 2–5 | Automated E2E/integration suite |
| R25 | Full in-browser editor (owner D3) | §7.7, ADR-0009 | 3 | Editor E2E; XSS corpus; a11y |
| R26 | Upload Word/Excel/PDF (owner D3) | §7.2, ADR-0009 | 2 | Upload pipeline tests |
| R27 | Group-based access (owner D5) | ADR-0010 | 1–2 | Group grant/revoke + search-filter tests |
| R28 | Multi-timezone effective dates, single org scope, default `America/Denver` (owner D6/D6a/D7) | §11 | 5 | DST tests incl. the 1 Nov 2026 change; cross-timezone display tests |
| R29 | Logs to Microsoft Sentinel (owner) | ADR-0011 | 1/8 | Events visible in Sentinel workspace (org-run test) |
| R30 | Migrate from on-prem Confluence (owner) | ADR-0012 | 6b | Pilot reconciliation report |
| R32 | Entra ID sign-in + Graph group sync only (owner D18) | ADR-0004, ADR-0010 | 1 | Sign-in E2E; sync add/remove tests; disabled-user lockout test |
| R31 | Email via SMTP (owner; Graph mail out of v1 per D18) | §1a, §14.1 | 4–6 | Notification delivery tests |
| R33 | Local username/password accounts alongside Entra ID, with lockout, throttling and forced change of temporary passwords (owner, 2026-10-26) | ADR-0014 (amends ADR-0004) | 2 | `LocalSignInTest` |

## Phase 1 status (2026-10-26)

| Req | Status | Evidence |
|---|---|---|
| R16 | **Partly implemented.** Global roles, granular permissions and a voter are enforced server-side on every endpoint. Document- and category-scoped grants come in Phase 2. | `PermissionMatrixTest` (role × permission), `ApiAuthorizationTest` (endpoint × role) |
| R17 | **Implemented** for Phase 1 events: append-only triggers, hash chain, verifier, anchor writer, DB role separation. | `AuditTrailTest` (tamper/deletion detection, guards, rollback); role test against PostgreSQL (app role denied UPDATE/DELETE/TRUNCATE/ALTER) |
| R18 | **In progress.** Phase 1 controls are in place (OIDC/PKCE, CSRF, sessions, headers, rate limits, Host allowlist, CSP). The ASVS L2 checklist with evidence is due in Phase 7. | Security audit report (Phase 1), tests above |
| R23 | **Implemented.** Container image, Compose for on-prem Linux, roles script, CI container build and smoke test. | `pal/Dockerfile`, `pal/deploy/`, `.github/workflows/pal-ci.yml`. CI builds the image, smoke-tests it (starts, health, SPA, uid 10001, refuses missing config) and scans it with Trivy: all green. Compose itself has not been run end to end. |
| R27 | **Partly implemented.** Directory groups are synced (read-only Graph) with membership history, and roles are mapped to groups. Document/category group grants come in Phase 2. | `DirectorySyncTest` |
| R28 | **Partly implemented.** User timezone preference and the `America/Denver` organisation default are stored and displayed. Effective-date scheduling comes in Phase 5. | `ApiAuthorizationTest::testTimezonePreferenceIsValidatedAndAudited` |
| R29 | **Partly implemented.** A structured `pal_audit` log channel exists, as JSON to stderr in prod. Ingestion into Sentinel is not verified and depends on the organisation's collector (D17). | `config/packages/monolog.yaml` |
| R32 | **Implemented.** Entra ID sign-in (BFF) and Graph group/user sync. Tested end to end against an in-process fake Entra ID that signs real RS256 tokens; **not yet tested against a real tenant**. | `SignInFlowTest`, `IdTokenValidatorTest`, `GraphDirectoryAdapterTest` |
| R15 | **Phase 1 shell verified.** axe-core reports 0 WCAG 2.0–2.2 A/AA violations on all pages in both themes and with a hostile accent colour; the skip link works. A manual screen-reader pass is still due (Phase 6/7). | Browser verification, Phase 1 session report |

## Phase 2 status (2026-10-26)

Phase 2 (repository + walking skeleton, [PHASE2_PLAN.md](PHASE2_PLAN.md)) and local accounts (ADR-0014) are implemented. Evidence: after the audit fixes the backend suite (`php vendor/bin/phpunit`) ran **OK, 156 tests** against PostgreSQL `pal_test` (local PHP 8.5.10; CI uses 8.4), and the frontend suite (`npm test`) ran **54 tests passed**. Later changes need their own run. Test classes are under `pal/backend/tests/`.

| Req | Status | Code | Evidence (tests) |
|---|---|---|---|
| R1 | **Implemented (Phase 2 scope).** Document identity is separate from versioned revisions; approved revisions are immutable; files change only while `DRAFT` (DB triggers `trg_revision_immutable`, `trg_revision_file_draft_only`). | `Documents/LifecycleService`, migration `Version20261026000002` | `DocumentIntegrityTest::testApprovedRevisionsAreImmutable`, `::testContentChangesOnlyWhileDraft`, `::testFilesChangeOnlyWhileDraft`, `::testOnlyOneOpenDraftPerDocument` |
| R3 | **Partly implemented.** Server-enforced transitions for the skeleton (`DRAFT → IN_WORKFLOW → APPROVED / CHANGES_REQUESTED / REJECTED`, reopen, withdraw). The full transition matrix is Phase 3–5. | `LifecycleService::lockRevision()` | `DocumentLifecycleTest::testScenario3RejectionReworkAndResubmission` |
| R4 | **Partly implemented.** A published revision and the next draft coexist; readers see only the published one; history is kept on supersession. | `LifecycleService::newRevision()`, `release()` | `DocumentLifecycleTest::testPublishedDocumentHidesTheNextDraftFromReaders`, `::testReleasingTheNextRevisionSupersedesWithoutLosingHistory` |
| R5 | **Constraint implemented** (behaviour beyond immediate release is Phase 5). `EXCLUDE USING gist` on effective and visible periods per document and scope. | `publication` table | `DocumentIntegrityTest::testOverlappingEffectivePublicationsAreImpossible` |
| R7 | **Implemented for single-approver.** Each decision stores `manifest_hash_at_decision`; release re-hashes metadata and stored files and refuses on mismatch (409 `approval_integrity_failed`). | `ManifestHasher`, `LifecycleService::verifyApprovalIntegrity()` | `DocumentLifecycleTest::testScenario8TamperedApprovedContentIsNotReleased` |
| R8 | **Implemented for single-approver.** The owner, the author, the submitter and anyone who created, edited or attached a file to the revision cannot approve it (403 `separation_of_duties`, read from the audit trail); eligibility is re-checked at decision time (403 `approver_revoked`). | `LifecycleService::decide()`, `eligibleApprovers()` | `DocumentLifecycleTest::testSeparationOfDutiesAndApprovalRules`, `::testEligibleApproversAreListedForEditorsOnly`, `::testAnyoneWhoEditedARevisionCannotApproveIt`, `::testRevokedApproverCannotDecideAnOpenTask` |
| R10 | **Partly implemented.** Release is one transaction and idempotent. Crash/fault-injection tests are Phase 5. | `LifecycleService::release()` | `DocumentLifecycleTest::testReleaseIsIdempotent` |
| R12 | **Partly implemented.** Fixed numbering `{TYPE}-{DEPT}-{SEQ:4}` from a locked per-type/department counter; majors 1.0, 2.0… Configurable policies and rollback are Phase 3. | `LifecycleService::allocateNumber()` | `DocumentLifecycleTest::testDocumentNumbersAreSequentialPerTypeAndDepartment` |
| R14 | **Partly implemented.** Library of published documents with PostgreSQL FTS (`websearch_to_tsquery`, weighted vector incl. the document number) plus `ILIKE` on numbers, type/department filters, pagination; authorization applied in SQL. Categories and saved views are Phase 3/6. | `Documents/LibraryQuery`, `DocumentAccess::visibilitySql()` | `DocumentIntegrityTest::testSearchVectorIncludesTheDocumentNumberAndTitle`, `DocumentLifecycleTest::testScenario1CreateApprovePublishFindAndDownload`, `KeywordArrayRoundTripTest` |
| R16 | **Extended.** Document-level grants (`OWNER`, `AUTHOR`, `APPROVER`, `READER`) to users or directory groups; restricted documents; non-visible documents answer 404, never 403. New permissions in use: `document.create`, `document.read_all`, `document.manage_access`, `publication.release`, `admin.types`, `admin.departments`, `admin.users`. | `DocumentAccess`, `GrantService`, `RolePermissionMap` | `DocumentLifecycleTest::testRestrictedDocumentsAreVisibleOnlyToGrantees`, `::testDocumentCreationRequiresTheAuthorRole`, `::testPublishersCanReviewWhatTheyReleaseButNotDrafts`, `PermissionMatrixTest`, `LocalSignInTest::testNonAdministratorsCannotManageUsers` |
| R17 | **Extended** to every Phase 2 change (document, revision, upload, decision, release, grant, reference data, user administration), in the same transaction. Downloads go to the `pal_audit` log channel, not the audit table. | `AuditLogger` callers in `Documents/`, `Storage/`, `Identity/` | `AuditTrailTest` (chain), functional tests assert audit rows |
| R19 / R26 | **Implemented.** Upload pipeline: size limit (`PAL_MAX_UPLOAD_BYTES`) → SHA-256 → libmagic type detection with allow-list (PDF, DOCX, XLSX, PPTX, TXT) and extension match → ClamAV INSTREAM scan, **fail-closed** → write-once content-addressed store. Every attempt (clean, rejected, infected, error) recorded and audited. Unscanned mode only outside `prod`. | `Storage/UploadService`, `FileTypePolicy`, `ClamdScanner`, `FilesystemBlobStore` | `DocumentLifecycleTest::testMalwareAndDisguisedFilesNeverBecomeStoredFiles` (EICAR via a real-protocol clamd stand-in, spoofed extension), `::testUploadsFailClosedWhenTheScannerIsUnavailable`, `::testUploadsCanOnlyBeAttachedByTheirUploader`, `::testDailyUploadQuotaIsEnforced`, `StoragePrimitivesTest` (7 tests incl. `testUnscannedModeIsNeverAvailableInProduction`). **Real ClamAV not exercised** (Compose stack not run) |
| R23 | **Extended.** Image creates `/srv/pal/data/blobs` (uid 10001); Compose adds the `pal-blobs` volume and a healthchecked `clamd` service; `/health/ready` adds a `storage` check. | `Dockerfile`, `deploy/compose.yaml`, `StorageReadinessProbe` | `StorageReadinessProbeTest` (4 tests), `HealthTest::testLivenessAndReadiness`. Compose with ClamAV **not run** (no Docker on the development machine) |
| R24 | **Scenarios 1, 3, 7 and 8 automated.** | — | `DocumentLifecycleTest::testScenario1…`, `::testScenario3…`, `::testScenario7ReadersCannotReachDraftsByAnyRoute`, `::testScenario8…` |
| R27 | **Extended.** Document grants to directory groups (current memberships only). | `DocumentAccess::relations()` | `DocumentLifecycleTest::testRestrictedDocumentsAreVisibleOnlyToGrantees` |
| R32 | **Amended by ADR-0014.** Entra ID remains supported; local accounts were added alongside it. Still not tested against a real tenant. | — | `SignInFlowTest`, `LocalSignInTest::testEntraAndLocalUsersShareTheRoleModel` |
| R33 | **Implemented.** Local accounts: argon2id, 5-failure/15-min lockout, per-IP and per-username throttling, identical failure responses, login CSRF checked before credentials, session rotation, forced change of temporary passwords (server gate), users admin API, env bootstrap of the first admin. Okta / Google / SAML are "Not connected" placeholders only. | `Identity/Local/*`, `Identity/Security/LocalPasswordAuthenticator`, `PasswordChangeRequiredSubscriber`, `Controller/UserAdminController`, `Command/BootstrapLocalAdminCommand` | `LocalSignInTest` (methods exposure, session rotation + audit, indistinguishable failures, lockout, login CSRF, inactive accounts, temporary password gate, admin management, non-admin refusal, shared role model, bootstrap once, last active System Administrator, unlock command) |
| R15 | **Automated check clean.** axe: 0 violations on all pages, both themes (run before the latest UI fixes; no stored report artefact). Manual screen-reader pass still due. | `frontend/src/pages/*` | Browser verification reported for Phase 2 |

Phase 2 exit criteria ([proposal §16](PHASE0_ARCHITECTURE_PROPOSAL.md#16-phased-delivery-plan)): Scenario 1 and 7 automated ✅; EICAR stays quarantined ✅ (fake clamd); spoofed extension rejected ✅; reader gets 404 for a draft by ID ✅. The S3 blob backend listed in the proposal's Phase 2 row was moved to Phase 6 by [PHASE2_PLAN.md](PHASE2_PLAN.md).

## Native editor v1 status (2026-11-10)

Decision #26. Evidence: backend `php vendor/bin/phpunit` **OK, 270 tests** and frontend `npm test` **74 passed** (after the soft-checkout and accessibility follow-up), locally on PHP 8.5.10 against PostgreSQL `pal_test`.

| Req | Status | Code | Evidence (tests) |
|---|---|---|---|
| R25 | **Partly implemented (v1).** In-browser editor with headings, emphasis, lists, quotes, rules, links and tables; autosave; server-side allow-list schema with canonical output; per-type templates maintained by administrators. Soft checkout (advisory "who is editing") added the same day. Images, PDF rendition and comparison are deferred (ADR-0009 status section). | `Documents/RichTextDocument`, `Documents/TemplateRepository`, `Documents/Controller/TemplateController`, `LifecycleService::updateBody()`, `frontend/src/components/RichText{Editor,View}.vue`, `pages/TemplatesAdminPage.vue` | `RichTextDocumentTest` (XSS and malformed-content corpus incl.: raw HTML/iframe/image nodes, `javascript:`/`data:`/relative links, unknown marks, depth and size limits), `NativeEditorTest` (9 tests), `richText.spec.ts` (19 tests: link rules, renderer never emits markup/script/unsafe links), `NativeEditorTest::testEditorsSeeWhoElseIsEditingADraft`. axe 0 violations (WCAG 2.0–2.2 A/AA + best practice) on editor pages, both themes; keyboard toolbar pattern checked in the browser; production CSP checked on a local build |
| R1 | **Extended.** The body freezes with the revision: `trg_revision_immutable` covers `body_json`/`body_text`. | migration `Version20261110000001` | `NativeEditorTest::testTamperedApprovedBodyIsNotReleasedAndTheDatabaseRefusesDirectEdits`, `::testOnlyEditorsCanWriteTheBodyAndOnlyWhileDraft` |
| R7 | **Extended.** The manifest hash covers `SHA-256(body_json)` when present; release recomputes it from the stored bytes. Revisions without a body keep their original hash. | `ManifestHasher`, `LifecycleService::manifestHash()` | `RichTextDocumentTest::testManifestHashIsUnchangedForRevisionsWithoutABody`, `NativeEditorTest::testADocumentWrittenInPalIsApprovedPublishedReadAndFound`, `::testTamperedApprovedBody…` |
| R8 | **Extended.** Body edits are `REVISION_EDITED` audit events, so anyone who edits the body cannot approve it (same rule as metadata and files). | `LifecycleService::updateBody()` | `NativeEditorTest::testBodyEditsUseOptimisticConcurrencyAndAreAudited` |
| R14 | **Extended.** Body text is in the search vector (weight D, capped at 200,000 characters). | `pal_revision_search_vector()` | `NativeEditorTest::testADocumentWrittenInPalIsApprovedPublishedReadAndFound` |
| R16 | **Extended.** New permission `admin.templates` (PAL Administrator; Owner via `Permission::ALL`). Listing templates for a type needs `document.create`. | `Permission`, `RolePermissionMap`, `TemplateController` | `PermissionMatrixTest`, `NativeEditorTest::testAdministratorsMaintainTemplatesThatAuthorsStartFrom`, `::testReadersCannotSeeTheTemplateAdministration` |
| R17 | **Extended.** `REVISION_EDITED` (body: from/to SHA-256 and length), `REVISION_CREATED` (template id, name, body hash), `TEMPLATE_CREATED`, `TEMPLATE_UPDATED` (field diffs, body hashes). Template body history is **not** retained beyond hashes. | `LifecycleService`, `TemplateController` | `NativeEditorTest` asserts the audit rows |
