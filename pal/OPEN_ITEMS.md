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

## Not verified (must be confirmed before go-live)
| Item | Impact | Action |
|---|---|---|
| Sign-in and Graph sync never tested against a real Entra tenant | Claim validation, the certificate assertion (`x5t`/`x5t#S256`) and Graph permissions are unproven | Register a dev app; run sign-in, `pal:roles:map` and `pal:directory:sync` end to end |
| Production session handler (`PdoSessionHandler`) has no automated test (tests use the mock store) | Idle expiry and locking are unproven in tests | Add an integration test against PostgreSQL, or a manual check on staging |
| One unexplained PHPUnit failure on the first run in a fresh checkout (seen once in Phase 1, 1 of 101 tests; later runs clean, including the 156-test run at commit 30bd8f48) | Possible flaky test | Watch CI; if it recurs, capture the failing test name and fix the root cause |
| Render deployment | 🟡 **Deployed 2026-11-07** as the scanner-less single Web Service + shared PostgreSQL (DEPLOYMENT §12.3), live and `/health/ready` green (database/migrations/storage ok). Confirmed in the process: Docker build context must be `pal`; `DATABASE_URL` needs `?serverVersion=<N>` (DBAL 4 requires it); `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME` must be a username, not an email. **Not yet exercised on Render:** the full Blueprint path (`fromService … hostport`, `preDeployCommand`, disk mount) and real ClamAV | Run the full `render.yaml` Blueprint once to prove the Blueprint-specific pieces |
| Bootstrap-admin error messages | ✅ `pal:users:bootstrap-admin` now reports a clear reason when `PAL_BOOTSTRAP_LOCAL_ADMIN_USERNAME`/`PASSWORD` is invalid, instead of a generic "fields are invalid". Username rule shared via `LocalAccountService::usernameRejectionReason()` (test: `UsernameRuleTest`) | — |
| **Render disk ownership unverified** | If the disk at `/srv/pal/data` is not writable by uid 10001, readiness reports `"storage":"fail"` and the deploy never goes live | Check on the first deploy; if it fails, decide on a fix (e.g. an init step) rather than running as root |
| **Compose stack with ClamAV not run locally** (no Docker on the development machine): `clamdcheck.sh` healthcheck, `pal-blobs` volume ownership on first use, `web` waiting on `clamd` healthy, real EICAR scan | The ClamAV integration is tested only against an in-process protocol stand-in (`tests/Support/fake-clamd.php`) | Run `docker compose -f deploy/compose.yaml --profile bundled-db up -d`, then the upload checks in [SINGLE_LINUX_SERVER.md §7](deployments/SINGLE_LINUX_SERVER.md#7-verification) |
| Scheme-less `PAL_CLAMD_ADDRESS` (`clamd:3310`, Render `hostport`) | ✅ Covered by a unit test (`StoragePrimitivesTest::testSchemelessHostPortAddressConnectsOverTcp`, 2026-11-07): a scheme-less `host:port` connects over TCP and scans clean | — |
| Audit chain under concurrent writers has no automated test | Ordering is reasoned, not proven | Add a parallel-writer integration test |
| Manual screen-reader pass (NVDA, VoiceOver) not done | Automated checks miss some issues | Do it at the Phase 6/7 accessibility audit |
| `init-roles.sh` password setting not fully tested (local PostgreSQL trusts local logins) | Bundled-DB passwords unproven | Test on a host with `scram-sha-256` auth |
| Phase 1 exit criterion "restore of an empty DB from backup demonstrated" | Required by the plan | Run the DISASTER_RECOVERY runbook once on staging |

## Security (Phase 2 and local accounts)
| Item | Severity | Status / action |
|---|---|---|
| Local accounts have no MFA | Medium | ⛔ Keep local accounts to break-glass admins where Entra is available; add TOTP/WebAuthn if local accounts are used in production (owner decision, ADR-0014) |
| No breached-password check (only a small deny list) | Low | ⛔ Add a k-anonymity or offline corpus check (offline needed for air-gapped) |
| Disabling a person in Entra does not disable their local account | Low | Documented (SECURITY.md §2.7). Deactivate local accounts in Administration › Users |
| The bootstrap local administrator holds both `SYSTEM_ADMINISTRATOR` and `PAL_ADMINISTRATOR`, so one person can confer document authority | Medium | 🟡 Known limitation until D20 (second control) is decided. Split the roles across two people after bootstrap |
| Bootstrap password sits in env/platform config until removed | Low | Documented: remove `PAL_BOOTSTRAP_LOCAL_ADMIN_*` after first sign-in. The password is temporary (forced change) |
| SSO placeholders: Okta, Google Workspace, SAML are listed as "Not connected" and **not implemented** | Info | ⛔ Implement only on an owner decision; until then they are labels only |
| Single DB role on Render (runtime role owns `audit_event`) | Low | 🟡 Accepted for demo (decision #22). Never use a single role in production |
| **Audit fixes applied (2026-11-01):** no document authority through user admin without PAL Administrator; last-administrator guard; owner and editors cannot approve, approver grant re-checked at decision; group grants authorized before directory lookup; strict post-sign-in redirect; login CSRF before credentials; per-user upload rate limit and daily quota; unlock CLI; full edit diff in audit | — | ✅ Done, with regression tests |
| Role mappings: a System Administrator alone can map a group they are not in onto a document role (only self-grant is refused); `role_escalation_forbidden` covers the users API only | Medium | 🟡 Part of D20 |
| Account lockout can be triggered by anyone who knows a username (5 failures, 15 minutes), including the only admin | Medium | 🟡 Recovery: `pal:users:unlock`. Avoid guessable admin usernames. Owner decision: hard lock vs progressive delay |
| Blobs of never-attached uploads are not reclaimed; quota bounds growth but does not free space | Medium | ⛔ Add a garbage-collection job and disk-usage alerting. Any signed-in user can upload (the Storage module cannot see document grants) |
| Sessions survive a password change or reset; `/api/me/password` failures are not throttled or audited | Low | ⛔ Store a credential version in the session; apply the username limiter and audit failures |
| Username timing difference (existing account does extra DB and audit writes), not measured | Low | ⛔ Measure; pad to a minimum duration if significant |
| `GET /auth/methods` creates a DB session for each cookieless request, unthrottled | Low | ⛔ Rate-limit `/auth/` (nginx `limit_req` needs real client IPs behind the proxy, or apply the `auth_login` limiter) |
| Bootstrap command sets the password outside the create transaction, without an audit event; temporary passwords never expire | Low | ⛔ Audit it; add an expiry on `must_change` |
| Dev-only npm advisories: 17 moderate through `@vue/test-utils` and ESLint (`glob`, `minimatch`); none high or critical; production dependencies clean | Low | Upgrade when non-breaking fixes ship; CI fails on high and above |
| IAM UI differs from the repository standard: role-based only, with read-only effective permissions. No per-user module × action grants, Grant All / Clear All, or rotated CSRF token (CSRF is session-scoped) | Medium | 🟡 Owner decision. Recommended: keep role-only RBAC, which preserves separation of duties and the server-side model |
| Free single Web Service on a shared database (DEPLOYMENT 12.3): files wiped on spin-down, no worker, shared DB role | Medium | 🟡 Owner's choice for the demo. Missing files are reported and block release. Never use for real documents |
| Unreferenced blobs can remain after a failed DB write (store-then-insert) | Info | 🟡 Harmless (content-addressed); verification now exists (`pal:storage:verify`). Garbage collection (`pal:storage:gc`) still to add |
| App user can delete blobs at the filesystem level (code never does) | Low | Operator control: deny delete on the blob directory where the storage supports it (SECURITY.md §5) |

## Security (from the Phase 1 audit)
| Item | Severity | Status / action |
|---|---|---|
| Sysadmin could self-grant document authority (M2) | Medium | 🟡 Self-grant now refused (403 `self_grant_forbidden`, audited). Collusion between two admins is still possible. **Decision D20:** separate permission or second approver for document-role mappings |
| Junk callbacks could flood the audit trail (M1) | Medium | ✅ Callback rate-limited; only failures with a real pending sign-in are audited |
| Client IP spoofable with `TRUSTED_PROXIES=private_ranges` (L1) | Low | 🟡 Guidance and env example changed to exact proxy IPs plus overwriting X-Forwarded-For. The image default is still broad |
| Every account that can sign in becomes a Reader (L3) | Low | 🟡 Documented as mandatory: Entra "Assignment required = Yes". Optional: require a PAL-users group in code (Phase 2) |
| Single-role deployments (Render, local) are tamper-evident only (L4) | Low | 🟡 Documented. Use separate owner/app roles in production |
| Base images pinned by tag, not digest (L5) | Low | ⛔ Pin digests in the registry mirror; add Renovate |
| SIEM copy of an audit event is logged before commit (I1) | Info | ⛔ Emit after commit (post-commit hook or Messenger) |
| Client-supplied `X-Request-Id` lands in audit `correlation_id` (I2) | Info | ⛔ Accept only from trusted proxies, or store separately |
| Revocation latency up to the sync interval or session limits (I7) | Info | Documented; inherent to the BFF design |
| Large-group role mapping syncs synchronously and may time out (I8) | Info | ⛔ Move the initial sync to Messenger (the async transport exists) |
| GitHub Pages serves the `pal/` source publicly (I9) | Info | No secrets committed. Moves to a private repo at handover |

## Product and UX gaps
| Item | Impact | Action |
|---|---|---|
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
| Blob verification / garbage-collection command | 🟡 **Verification done:** `pal:storage:verify` (2026-11-07) checks every `stored_file` exists + matches its SHA-256, exits non-zero on any missing/corrupt, and DISASTER_RECOVERY §4 step 8a now uses it (`StorageVerifier` + `StorageVerifierTest`). **Still open:** a garbage-collection command for unreferenced blobs | Add `pal:storage:gc` to reclaim unreferenced blobs |
| S3-compatible blob backend | Only filesystem/NAS today | Phase 6 (PHASE2_PLAN.md) |
| `PAL_DEFAULT_TIMEZONE` is only a fallback; the stored setting has no admin UI | Changing the org timezone needs SQL | Add an admin setting (Phase 5, with scheduling) |
| Audit anchors need a write-once mount; Compose has none (read-only FS) | `--anchor` fails in default Compose | Mount a WORM/append-only share at `PAL_AUDIT_ANCHOR_DIR`. `pal:audit:verify` does not yet compare against anchors automatically |
| Scheduler state and rate-limit counters are per container (tmpfs) | Limits per replica; schedule state resets on restart | Shared cache pool (DBAL) if running more than one replica |
| `Version20261026000001::down()` drops the audit table | Data loss if run on real data | Never run down-migrations in production (documented) |
| OpenTelemetry tracing (Phase 1 plan) | Logs only, no traces | Add with Phase 6 observability |
| Secrets only via environment variables: no `*_FILE` support for `DATABASE_URL`, `APP_SECRET`, `OIDC_CLIENT_SECRET`; no passphrase-protected keys | Secrets sit in `pal.env` / platform env; Fargate needs an init container for the certificate | Add `*_FILE` variants and an optional key passphrase |
| No managed/workload identity for the Entra credential and no token-based DB auth | Certificate and DB passwords must be managed and rotated | Evaluate Azure workload identity federation / IAM DB auth if a cloud target is chosen |
| Database TLS not configured in-app | Two connections (Doctrine + sessions) rely on `PGSSLMODE`/`PGSSLROOTCERT` | Document and test `sslmode=verify-full` end to end |
| Readiness detects pending migrations and an unwritable blob directory, not a newer schema or an unreachable ClamAV | An older image against a newer schema still reports ready; uploads can fail while the app reports ready | Compare the latest known migration with the DB version; consider a non-blocking scanner status in readiness |
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
| D20 | Second control on document-role mappings | Separate permission (`admin.roles.document`) held by a different group |
| — | Record the "no CUI" constraint formally | Add to requirements and the decision log |
