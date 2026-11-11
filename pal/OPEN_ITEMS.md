# PAL — Open Items

An honest production-readiness register. **Phase 1 (foundation) and Phase 2 (repository + walking skeleton) are implemented and tested, together with local username/password accounts (ADR-0014). Phases 3–8 are not started.** PAL is not production-ready until Phase 8 exits.

Status key: ✅ done · 🟡 partial · ⛔ not started / blocked

## Done in Phase 1
| Area | What is in place |
|---|---|
| Sign-in | ✅ Entra ID OIDC via BFF (PKCE, state, nonce, RS256 validation), server-side PostgreSQL sessions, idle and absolute limits |
| Request security | ✅ CSRF header on all API writes; rate limits on sign-in, the callback and the API; Host allowlist; security headers; strict CSP on the SPA |
| Authorization | ✅ Granular permissions and a voter, roles mapped from directory groups, reloaded on every request. A sysadmin has no document authority, and self-granting document roles is refused |
| Directory | ✅ Read-only Graph sync of tracked groups with membership history and disabled-account detection |
| Audit | ✅ Append-only hash chain: triggers, DB role separation (verified against PostgreSQL), verifier, anchor writer, revokes re-applied on migrate |
| Platform | ✅ Health/readiness, request IDs, JSON errors, typed row access, Messenger + Scheduler with an advisory lock |
| UI | ✅ Sign-in, dashboard, preferences, branding, role mappings. 0 axe violations (WCAG 2.0–2.2 A/AA) in both themes |
| Delivery | ✅ One non-root image, Compose for on-prem, DB roles script, CI (tests, PHPStan, Deptrac, audits, Trivy, gitleaks). **CI green 2026-10-26**: image builds, starts as uid 10001, refuses missing config, serves health and the SPA, and has no HIGH/CRITICAL fixable CVEs |
| Docs | ✅ README, CLAUDE.md, `docs/` ×4, `deployments/` ×6, ADR-0001…0013, decision log, traceability matrix |

## Done in Phase 2 (2026-10-26)
| Area | What is in place |
|---|---|
| Documents | ✅ Types (seeded) and departments with admin UI; fixed numbering `{TYPE}-{DEPT}-{SEQ:4}`; documents with versioned revisions; one open draft per document; immutability and draft-only-file triggers |
| Uploads | ✅ Size limit → SHA-256 → libmagic allow-list (PDF/DOCX/XLSX/PPTX/TXT) with extension match → ClamAV INSTREAM, fail-closed → write-once content-addressed blob store (`PAL_BLOB_DIR`). Every attempt recorded and audited. `PAL_ALLOW_UNSCANNED_UPLOADS` honoured only outside `prod` |
| Approval / publication | ✅ Single-approver workflow with SoD; decisions bound to the manifest hash, re-verified at release; immediate, idempotent release; `EXCLUDE` constraints on effective/visible periods; supersession keeps history |
| Access | ✅ Document grants to users and groups, restricted documents, 404 for anything not visible, authorization applied in SQL for library/search |
| Library | ✅ PostgreSQL FTS + number match, type/department filters, pagination, authorized download (`Content-Disposition: attachment`, sandbox CSP) |
| Local accounts | ✅ argon2id, 5-failure/15-min lockout, per-IP + per-username throttling, identical failure responses, login CSRF, forced change of temporary passwords (server gate 403 `password_change_required`), users admin (create, roles, activate, reset), `pal:users:bootstrap-admin` run by `migrate` |
| UI | ✅ Library, document page, workspace, new document, types/departments admin, sign-in page (password + Entra when connected), change password, users admin, sign-in methods page |
| Operations | ✅ `/health/ready` gains module probes; `storage` fails when `PAL_BLOB_DIR` is missing or unwritable. Image creates `/srv/pal/data/blobs` (uid 10001); PHP `upload_max_filesize` 50M / `post_max_size` 52M; nginx 52m bodies on `/api/uploads` only; Compose adds `clamd` (`clamav/clamav:1.5.4`) and the `pal-blobs` volume; entrypoint tolerates its own path as the first argument; `render.yaml` rewritten for full Phase 2 |
| Tests | ✅ Backend `phpunit` OK (156 tests) and frontend `npm test` (54 tests) after the audit fixes, locally on PHP 8.5.10. Scenarios 1, 3, 7, 8 automated |
| Accessibility | ✅ axe: 0 violations on all pages, both themes (run before the latest UI fixes). A manual screen-reader pass is still due (below) |
| Docs | ✅ Doc set updated for Phase 2; ADR-0014; decision log #21–22; traceability Phase 2 status |

## Done: native editor v1 and templates (2026-11-10, decision #26)
| Area | What is in place |
|---|---|
| Authoring | ✅ Write documents in PAL: headings 1–4, bold/italic/underline/strike, lists, quotes, divider, links, tables. Autosave with optimistic concurrency; a revision can have editor content, a file, or both |
| Integrity | ✅ Canonical, schema-validated body; immutable once submitted (DB trigger); approvals bind to its SHA-256; release re-verifies it |
| Templates | ✅ Administrators (`admin.templates`) create, edit, deactivate per-type templates (new ones start from a 7-section outline); authors pick one on New document; the content is copied into the draft |
| Reading | ✅ Readers, approvers and publishers see the formatted content read-only; the editor bundle is loaded only for people editing a draft; body text is searchable |
| Tests | ✅ Backend 269 tests OK, frontend 74 passed; security and UI audits run and their findings fixed (remaining Lows listed below); browser check on local dev (template create, create-from-template, autosave, metadata save after autosave, approver view in dark and light themes) |

## Not verified (must be confirmed before go-live)
| Item | Impact | Action |
|---|---|---|
| Sign-in and Graph sync never tested against a real Entra tenant | Claim validation, the certificate assertion (`x5t`/`x5t#S256`) and Graph permissions are unproven | Register a dev app; run sign-in, `pal:roles:map` and `pal:directory:sync` end to end |
| Production session handler (`PdoSessionHandler`) | ✅ **Done (2026-11-08):** `SessionHandlerTest` builds the handler exactly as `services.yaml` does (real `DATABASE_URL`, `sessions` table, advisory lock) and exercises it against PostgreSQL: write→read round-trip, destroy, and idle garbage collection (an expired `sess_lifetime` row is collected on `close()`, a fresh one survives). This also proves the handler parses the Doctrine-style `DATABASE_URL`. The HTTP tests still use the mock file store for speed; the advisory per-session **lock** is not deterministically unit-tested (it would block a single-threaded test, same constraint as the audit chain lock) | — |
| One unexplained PHPUnit failure on the first run in a fresh checkout (seen once in Phase 1, 1 of 101 tests; later runs clean, including the 156-test run at commit 30bd8f48) | Possible flaky test | Watch CI; if it recurs, capture the failing test name and fix the root cause |
| Render deployment | 🟡 **Deployed 2026-11-07** as the scanner-less single Web Service + shared PostgreSQL (DEPLOYMENT §12.3), live and `/health/ready` green (database/migrations/storage ok). Confirmed in the process: Docker build context must be `pal`; `DATABASE_URL` needs `?serverVersion=<N>` (DBAL 4 requires it); `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` must be a username, not an email. **Not yet exercised on Render:** the full Blueprint path (`fromService … hostport`, `preDeployCommand`, disk mount) and real ClamAV | Run the full `render.yaml` Blueprint once to prove the Blueprint-specific pieces |
| Bootstrap-admin error messages | ✅ `pal:users:bootstrap-admin` now reports a clear reason when `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME`/`PASSWORD` is invalid, instead of a generic "fields are invalid". Username rule shared via `LocalAccountService::usernameRejectionReason()` (test: `UsernameRuleTest`) | — |
| **Render disk ownership unverified** | If the disk at `/srv/pal/data` is not writable by uid 10001, readiness reports `"storage":"fail"` and the deploy never goes live | Check on the first deploy; if it fails, decide on a fix (e.g. an init step) rather than running as root |
| **Compose stack with ClamAV not run locally** (no Docker on the development machine): `clamdcheck.sh` healthcheck, `pal-blobs` volume ownership on first use, `web` waiting on `clamd` healthy, real EICAR scan | The ClamAV integration is tested only against an in-process protocol stand-in (`tests/Support/fake-clamd.php`) | Run `docker compose -f deploy/compose.yaml --profile bundled-db up -d`, then the upload checks in [SINGLE_LINUX_SERVER.md §7](deployments/SINGLE_LINUX_SERVER.md#7-verification) |
| Scheme-less `PAL_CLAMD_ADDRESS` (`clamd:3310`, Render `hostport`) | ✅ Covered by a unit test (`StoragePrimitivesTest::testSchemelessHostPortAddressConnectsOverTcp`, 2026-11-07): a scheme-less `host:port` connects over TCP and scans clean | — |
| Audit chain under concurrent writers | ✅ **Done (2026-11-08):** `AuditConcurrencyTest` proves against real PostgreSQL the guarantee the single chain rests on — the `pg_advisory_xact_lock` chain lock is exclusive and held until commit (a second session's `pg_try_advisory_xact_lock` fails while a writer's transaction is open and succeeds after it commits), so no concurrent writer can read a stale latest-hash and fork — and that a run of writes yields one linear chain (distinct `prev_hash` = row count, i.e. no fork) that the verifier accepts. It proves the serialization mechanism deterministically rather than spawning OS-parallel writers (avoids a flaky process-racing harness) | — |
| **Editor under the production CSP not checked** (dev server has no CSP; nginx sets `style-src 'self'`) | TipTap's `<style>` injection is disabled and tables use CSSOM, but a blocked inline style would only show in the browser console | Open a draft on the Compose stack or Render, check the console for CSP violations while editing a table |
| **Editor accessibility not audited** (axe, keyboard-only, screen reader) | Toolbar has labels, `aria-pressed` and a `role="toolbar"`; arrow-key roving focus is not implemented | Run axe on the document page with a draft open and on Document templates; do a keyboard-only pass |
| Manual screen-reader pass (NVDA, VoiceOver) not done | Automated checks miss some issues | Do it at the Phase 6/7 accessibility audit |
| `init-roles.sh` password setting not fully tested (local PostgreSQL trusts local logins) | Bundled-DB passwords unproven | Test on a host with `scram-sha-256` auth |
| Phase 1 exit criterion "restore of an empty DB from backup demonstrated" | Required by the plan | Run the DISASTER_RECOVERY runbook once on staging |

## Security (Phase 2 and local accounts)
| Item | Severity | Status / action |
|---|---|---|
| Local accounts have no MFA | Medium | ⛔ Keep local accounts to break-glass admins where Entra is available; add TOTP/WebAuthn if local accounts are used in production (owner decision, ADR-0014) |
| No breached-password check (only a small deny list) | Low | ⛔ Add a k-anonymity or offline corpus check (offline needed for air-gapped) |
| Disabling a person in Entra does not disable their local account | Low | Documented (SECURITY.md §2.7). Deactivate local accounts in Administration › Users |
| The bootstrap account is an **Owner** (superuser / god mode), so one person holds full authority at the root | By design | 🟡 **Changed 2026-11-09 (decision #25):** the bootstrap account is now the explicit root Owner, not a `SYSTEM_ADMINISTRATOR + PAL_ADMINISTRATOR` pair. This is the deliberate account root of trust (every system has one); Owner is granted/revoked only by another Owner and is never directory-mappable. For separation of duties among **delegated** administrators, grant `SYSTEM_ADMINISTRATOR` / `PAL_ADMINISTRATOR` to separate people and reserve Owner for a minimal, audited set. D20 (second control on delegated document-role mappings) still applies to lesser admins |
| Bootstrap password sits in env/platform config until removed | Low | Documented: remove `PAL_BOOTSTRAP_LOCAL_ADMIN_*` after first sign-in. The password is temporary (forced change) |
| SSO placeholders: Okta, Google Workspace, SAML are listed as "Not connected" and **not implemented** | Info | ⛔ Implement only on an owner decision; until then they are labels only |
| Single DB role on Render (runtime role owns `audit_event`) | Low | 🟡 Accepted for demo (decision #22). Never use a single role in production |
| **Audit fixes applied (2026-11-01):** no document authority through user admin without PAL Administrator; last-administrator guard; owner and editors cannot approve, approver grant re-checked at decision; group grants authorized before directory lookup; strict post-sign-in redirect; login CSRF before credentials; per-user upload rate limit and daily quota; unlock CLI; full edit diff in audit | — | ✅ Done, with regression tests |
| Role mappings: a System Administrator alone can map a group they are not in onto a document role (only self-grant is refused); `role_escalation_forbidden` covers the users API only | Medium | 🟡 Part of D20 |
| Account lockout can be triggered by anyone who knows a username (5 failures, 15 minutes), including the only admin | Medium | 🟡 Recovery: `pal:users:unlock`. Avoid guessable admin usernames. Owner decision: hard lock vs progressive delay |
| Blobs of never-attached uploads are not reclaimed; quota bounds growth but does not free space | Medium | ✅ **Done (2026-11-08, owner decision #24: 30-day window, deletion of upload rows authorised).** `pal:storage:unattached` reports never-attached uploads (read-only; `--older-than`, `--fail-over-bytes` for alerting) and, with `--delete`, reclaims them: it deletes the `upload` + `stored_file` rows (the `upload.stored_sha256` FK + `ck_upload_clean_stored` check leave no alternative; the `audit_event` trail is retained) in one transaction per blob, re-checking under a row lock that the blob is still unattached and older than the window, and auditing `UPLOAD_RECLAIMED`. `--delete` requires a positive retention window (default 30d) so an upload a user has not yet attached is protected. It leaves the now-unreferenced blob on disk for `pal:storage:gc --delete`, which frees disk race-safely against a concurrent re-upload. `UnattachedUploadScanner` + `UnattachedUploadReclaimer` + `UnattachedUploadScannerTest`. Any signed-in user can upload (the Storage module cannot see document grants) |
| Sessions and password changes | ✅ Done (2026-11-07): a credential version (`password_changed_at`) is compared in `PalUser::isEqualTo`, so a password change or admin reset revokes every **other** session on its next request; the session that performs a self-change is kept valid (MeController refreshes its token). `/api/me/password` now rate-limits (shared username limiter) and audits (`USER_PASSWORD_CHANGE_FAILED`) a wrong current password. Covered by `PalUserEqualityTest` + a `LocalSignInTest` case | — |
| Username timing difference (existing account does extra DB and audit writes), not measured | Low | ⛔ Measure; pad to a minimum duration if significant |
| `GET /auth/methods` creates a DB session for each cookieless request | Low | ✅ Done (2026-11-07): `AuthReadRateLimitSubscriber` applies a dedicated per-IP `auth_read` limiter (60/min) to `GET /auth/methods` and `/auth/login`, returning 429 before a session is created. Unit-tested (`AuthReadRateLimitSubscriberTest`). Correct client IPs still depend on `TRUSTED_PROXIES`; nginx `limit_req` remains a complementary front-line option |
| Bootstrap password handling | ✅ Done (2026-11-08): the bootstrap password is set in one audited transaction (`LocalAccountService::setBootstrapPassword` records `USER_PASSWORD_CHANGED`; `BootstrapPasswordTest`), and temporary (must-change) passwords now **expire** after `PAL_TEMP_PASSWORD_MAX_AGE_HOURS` (default **0 = disabled**, so an upgrade introduces no lockout risk; operators opt in). A correct but expired temporary password is refused at sign-in with `403 password_expired` (audited `USER_SIGN_IN_FAILED` / reason `temporary_password_expired`, never recorded as a success). Recovery: an administrator reset or a re-run of `pal:users:bootstrap-admin` stamps a fresh `password_changed_at` and restarts the clock. Covered by `TemporaryPasswordExpiryTest` (pure predicate) and three `LocalSignInTest` cases | — |
| Dev-only npm advisories: 17 moderate through `@vue/test-utils` and ESLint (`glob`, `minimatch`); none high or critical; production dependencies clean | Low | Upgrade when non-breaking fixes ship; CI fails on high and above |
| IAM UI differs from the repository standard: role-based only, with read-only effective permissions. No per-user module × action grants, Grant All / Clear All, or rotated CSRF token (CSRF is session-scoped) | Medium | 🟡 Owner decision. Recommended: keep role-only RBAC, which preserves separation of duties and the server-side model |
| Free single Web Service on a shared database (DEPLOYMENT 12.3): files wiped on spin-down, no worker, shared DB role | Medium | 🟡 Owner's choice for the demo. Missing files are reported and block release. Never use for real documents |
| Unreferenced blobs can remain after a failed DB write (store-then-insert) | Info | ✅ `pal:storage:gc` (2026-11-07) reclaims on-disk orphans (no `stored_file` row) and abandoned `.incoming-*` temporaries; dry-run by default, `--delete` to act, `--older-than` age guard. Never touches a recorded blob (`StorageGarbageCollector` + test) |
| App user can delete blobs at the filesystem level (code never does except `pal:storage:gc`) | Low | Operator control: deny delete on the blob directory where the storage supports it (SECURITY.md §5). Where delete is denied, `pal:storage:gc --delete` reports the blobs it could not remove; run it as an operator with delete rights |

## Security (from the Phase 1 audit)
| Item | Severity | Status / action |
|---|---|---|
| Sysadmin could self-grant document authority (M2) | Medium | 🟡 Self-grant now refused (403 `self_grant_forbidden`, audited). Collusion between two admins is still possible. **Decision D20:** separate permission or second approver for document-role mappings |
| Junk callbacks could flood the audit trail (M1) | Medium | ✅ Callback rate-limited; only failures with a real pending sign-in are audited |
| Client IP spoofable with `TRUSTED_PROXIES=private_ranges` (L1) | Low | 🟡 Guidance and env example changed to exact proxy IPs plus overwriting X-Forwarded-For. The image default is still broad |
| Every account that can sign in becomes a Reader (L3) | Low | 🟡 Documented as mandatory: Entra "Assignment required = Yes". Optional: require a PAL-users group in code (Phase 2) |
| Single-role deployments (Render, local) are tamper-evident only (L4) | Low | 🟡 Documented. Use separate owner/app roles in production |
| Base images pinned by tag, not digest (L5) | Low | ⛔ Pin digests in the registry mirror; add Renovate |
| SIEM copy of an audit event is logged before commit (I1) | Info | ✅ Done (2026-11-07): the SIEM forward is buffered during the transaction and emitted after commit (`AuditForwardFlushSubscriber` on kernel/console/worker terminate), forwarding only events actually present in `audit_event` — so a rolled-back event is never forwarded. `AuditForwardTest` covers both cases. |
| Client-supplied `X-Request-Id` lands in audit `correlation_id` (I2) | Info | ✅ Done (2026-11-07): `RequestIdSubscriber` honours an upstream `X-Request-Id` only from a trusted proxy (`isFromTrustedProxy()`); direct clients get a fresh ID. `RequestIdSubscriberTest` covers both. |
| Revocation latency up to the sync interval or session limits (I7) | Info | Documented; inherent to the BFF design |
| Large-group role mapping syncs synchronously and may time out (I8) | Info | ✅ Done (2026-11-08): adding a mapping dispatches `SyncDirectoryGroup` to the `async` transport (consumed by the existing `messenger:consume async` worker), so a large group's initial membership pull runs off-request. The handler is idempotent and, on a Graph failure, records `sync_error` and defers to the 15-min scheduled sync (mirrors `syncAll`). **Exception:** mapping a group to a *document-authority* role still syncs membership synchronously first — the separation-of-duties self-grant check requires fresh membership before the grant, so that one path cannot be deferred. Covered by two `ApiAuthorizationTest` cases |
| GitHub Pages serves the `pal/` source publicly (I9) | Info | No secrets committed. Moves to a private repo at handover |

## Product and UX gaps
| Item | Impact | Action |
|---|---|---|
| Editor v1 scope: no images, callouts, cross-references or section numbering; no PDF rendition; no comparison between revisions | Authors needing images still attach a file | Remaining ADR-0009 Phase 3 items |
| No soft checkout lock | Two people editing one draft: the second save gets "changed elsewhere" and must reload (no silent overwrite, but lost typing) | Add the checkout indicator from ADR-0009 |
| Templates are not versioned documents | The audit trail keeps only hashes of earlier template bodies, so an old template's wording cannot be shown | Decide (below) whether templates must be controlled documents themselves |
| Editor UI audit leftovers (Low): headings inside the editor are real `<h1>`–`<h4>` while editing (the read-only view maps them to `<h3>`–`<h6>`); the toolbar has no arrow-key roving focus; a failed reload after an action can leave a stale row version (next autosave shows a false conflict) | Heading outline is off only while editing; toolbar is a long Tab sequence | Custom heading `renderHTML` keeping the visual classes; roving tabindex; reset the row version when a reload fails |
| Editor bundle is ~140 KB gzipped | Slower first open of a draft on slow links | Loaded only when editing; acceptable for v1 |
| No warning before idle session expiry (WCAG 2.2.1) | Users lose unsaved input | A 401 now redirects to sign-in with "session expired". Add a warning plus "stay signed in" in Phase 6 |
| Branding not yet in print/PDF output (repo branding standard) | Standard incomplete until reports exist | Add with reports/print styles (Phase 6). The "printed output" claim was removed from the UI |
| Breadcrumbs (repo UI standard) | Not added in Phase 2 (the library has a filter toggle; no breadcrumbs anywhere) | Add with categories (Phase 3) |
| Custom confirm dialog with `aria-labelledby` (Quasar's plugin dialog has no accessible name) | Screen readers announce an unnamed dialog | Not built in Phase 2; build a `ConfirmDialog` component (Phase 3) |
| Brief branding flash before the organisation's name and logo load | Cosmetic | Inline branding in the HTML response, or show a skeleton |

## Configuration and operations
| Item | Impact | Action |
|---|---|---|
| ClamAV memory and plan sizing not measured (~1.5 GB for signatures per compose/render comments; Render plan `2c-4g`) | Under-sized hosts OOM-kill clamd; uploads then fail closed (503 `scanner_unavailable`) | Measure clamd RSS during a signature reload; size hosts and the Render plan from that |
| Upload limit coupling: `PAL_MAX_UPLOAD_BYTES` must stay ≤ clamd `StreamMaxLength` (default 25 MiB per the repo's comments) and ≤ PHP `upload_max_filesize` (50M) / nginx 52m | Above clamd's limit, scans fail and uploads are refused; above PHP's, PHP rejects first (413) | `deploy/pal.env.example` and `render.yaml` set 26214400 (25 MiB) to stay within it; the app default is 52428800 (`backend/.env.example`), which a deployment without an override inherits. Raise clamd's limit before raising the app limit |
| Blob verification / garbage-collection command | ✅ **Done (2026-11-07):** `pal:storage:verify` (exists + SHA-256, used by DISASTER_RECOVERY §4 step 8a) and `pal:storage:gc` (reclaims on-disk orphans + stale `.incoming-*`, dry-run default, `--delete`/`--older-than`). Both backend-agnostic/filesystem, unit-tested against the real store | Follow-ups: disk-usage visibility now covered by `pal:storage:unattached` (read-only report + `--fail-over-bytes` for alerting); actual never-attached-upload reclamation still needs a retention-policy decision |
| S3-compatible blob backend | Only filesystem/NAS today | Phase 6 (PHASE2_PLAN.md) |
| `PAL_DEFAULT_TIMEZONE` is only a fallback; the stored setting has no admin UI | Changing the org timezone needs SQL | Add an admin setting (Phase 5, with scheduling) |
| Audit anchors need a write-once mount; Compose has none (read-only FS) | `--anchor` fails in default Compose | Mount a WORM/append-only share at `PAL_AUDIT_ANCHOR_DIR`. `pal:audit:verify` does not yet compare against anchors automatically |
| Scheduler state and rate-limit counters are per container (tmpfs) | Limits per replica; schedule state resets on restart | Shared cache pool (DBAL) if running more than one replica |
| `Version20261026000001::down()` drops the audit table | Data loss if run on real data | Never run down-migrations in production (documented) |
| OpenTelemetry tracing (Phase 1 plan) | Logs only, no traces | Add with Phase 6 observability |
| Secrets via files | 🟡 ✅ `*_FILE` support added (2026-11-07): the entrypoint reads `APP_SECRET`, `DATABASE_URL`, `DATABASE_URL_MIGRATIONS`, `OIDC_CLIENT_SECRET`, `OIDC_CLIENT_KEY_PASSPHRASE` and `PAL_BOOTSTRAP_LOCAL_ADMIN_PASSWORD` from a `<NAME>_FILE` path (Docker/K8s secrets, Vault/CSI mounts), trailing newline stripped, erroring if both forms are set. Passphrase-protected private keys are now supported: `OIDC_CLIENT_KEY_PASSPHRASE` (optional, default empty) decrypts an encrypted `OIDC_CLIENT_KEY_PATH` when building the certificate assertion (`ClientCredentials`; `ClientCredentialsTest`) | ✅ Done |
| No managed/workload identity for the Entra credential and no token-based DB auth | Certificate and DB passwords must be managed and rotated | Evaluate Azure workload identity federation / IAM DB auth if a cloud target is chosen |
| Database TLS not configured in-app | Two connections (Doctrine + sessions) rely on `PGSSLMODE`/`PGSSLROOTCERT` | 🟡 **Documented (2026-11-08):** [DEPLOYMENT.md §4.4](docs/DEPLOYMENT.md#44-database-tls) gives the `sslmode=verify-full` + `sslrootcert` config for both connections (they share `DATABASE_URL`) via DSN or `PGSSL*`, plus a `pg_stat_ssl` verification query. **Still open:** end-to-end test against a TLS-enabled server (needs staging with TLS Postgres) |
| Readiness detects pending migrations and an unwritable blob directory; newer-schema detection added, scanner status still not surfaced | ✅ **Newer-schema done (2026-11-08):** `/health/ready` adds a `schema_version` check (`getExecutedUnavailableMigrations`) that fails when the DB has executed a migration this image lacks, so an older image against a newer schema now reports `not_ready` (503) instead of `ready` — a lingering old replica during a rolling deploy is pulled from rotation. `HealthTest` covers the fail path. **Still open:** uploads can fail while the app reports ready (ClamAV is intentionally not part of readiness; consider a non-blocking scanner status) |
| Cache volumes hide the build-time warmed cache; OIDC discovery/JWKS cache per container | Slower first request after start | Warm cache at start, or move cache.app to a shared pool |
| Offline/enclave builds need package mirror build args (Alpine, npm, Composer) | Air-gapped builds need Dockerfile edits | Add build args for package sources |
| Fully disconnected (air-gapped) operation: local accounts now allow sign-in without Entra, but OIDC still assumes Entra (issuer shape, `tid`/`oid`, Graph) and there is no group sync | Enclave users must be managed one by one in PAL; no other IdP | Generalise the OIDC validator and add an LDAP/AD directory adapter if required. ClamAV signatures need an offline mirror (AIRGAPPED.md §8.5) |
| Image does not use a FIPS-validated crypto module | Matters for Azure Government / GovCloud | Use a FIPS base image if a Gov target is chosen |
| `admin.workflows` sits with System Administrator | May conflict with SoD once workflows exist | Revisit in Phase 4 (decision) |

## Decisions still open
| ID | Decision | Recommendation |
|---|---|---|
| D10 | RPO/RTO (provisional 15 min / 4 h) | IT confirms before Phase 7 |
| D14 / D15 | In-browser Office editing; real-time co-authoring | Defer to Phase 9 |
| D16 | Graph read-only consent | Entra admin grants it (certificate credential) |
| D17 | Sentinel collector and uptime monitor | SOC confirms |
| D19 | Confluence inventory | Needed before Phase 6b |
| — | Must templates be controlled (versioned, approved) like documents? | v1: administrator-maintained and audited by hash. If ISO/CMMI auditors require template history, keep each template's prior bodies or manage templates as `TPL` documents |
| D20 | Second control on document-role mappings | Separate permission (`admin.roles.document`) held by a different group |
| — | Record the "no CUI" constraint formally | Add to requirements and the decision log |
