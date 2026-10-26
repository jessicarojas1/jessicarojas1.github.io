# PAL — Open Items

An honest production-readiness register. **Phase 1 (foundation) is implemented and tested. Phases 2–8 are not started.** PAL is not production-ready until Phase 8 exits.

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
| Delivery | ✅ One non-root image, Compose for on-prem, DB roles script, CI (tests, PHPStan, Deptrac, audits, Trivy, gitleaks) |
| Docs | ✅ README, CLAUDE.md, `docs/` ×4, `deployments/` ×6, ADR-0001…0013, decision log, traceability matrix |

## Not verified (must be confirmed before go-live)
| Item | Impact | Action |
|---|---|---|
| Container image never built locally (no Docker on the dev machine) | The first build may fail | Check the first `PAL CI` container job; fix as needed |
| Sign-in and Graph sync never tested against a real Entra tenant | Claim validation, the certificate assertion (`x5t`/`x5t#S256`) and Graph permissions are unproven | Register a dev app; run sign-in, `pal:roles:map` and `pal:directory:sync` end to end |
| Production session handler (`PdoSessionHandler`) has no automated test (tests use the mock store) | Idle expiry and locking are unproven in tests | Add an integration test against PostgreSQL, or a manual check on staging |
| One unexplained PHPUnit failure on the first run in a fresh checkout (1 of 101 tests; 7 later runs clean, including a cold cache) | Possible flaky test | Watch CI; if it recurs, capture the failing test name and fix the root cause |
| Audit chain under concurrent writers has no automated test | Ordering is reasoned, not proven | Add a parallel-writer integration test |
| Manual screen-reader pass (NVDA, VoiceOver) not done | Automated checks miss some issues | Do it at the Phase 6/7 accessibility audit |
| `init-roles.sh` password setting not fully tested (local PostgreSQL trusts local logins) | Bundled-DB passwords unproven | Test on a host with `scram-sha-256` auth |
| Phase 1 exit criterion "restore of an empty DB from backup demonstrated" | Required by the plan | Run the DISASTER_RECOVERY runbook once on staging |

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
| Breadcrumbs and list filters (repo UI standard) | Shallow hierarchy today | Add with the document library (Phase 2) |
| Custom confirm dialog with `aria-labelledby` (Quasar's plugin dialog has no accessible name) | Screen readers announce an unnamed dialog | Build a `ConfirmDialog` component (Phase 2) |
| Brief branding flash before the organisation's name and logo load | Cosmetic | Inline branding in the HTML response, or show a skeleton |

## Configuration and operations
| Item | Impact | Action |
|---|---|---|
| `PAL_DEFAULT_TIMEZONE` is only a fallback; the stored setting has no admin UI | Changing the org timezone needs SQL | Add an admin setting (Phase 5, with scheduling) |
| Audit anchors need a write-once mount; Compose has none (read-only FS) | `--anchor` fails in default Compose | Mount a WORM/append-only share at `PAL_AUDIT_ANCHOR_DIR`. `pal:audit:verify` does not yet compare against anchors automatically |
| Scheduler state and rate-limit counters are per container (tmpfs) | Limits per replica; schedule state resets on restart | Shared cache pool (DBAL) if running more than one replica |
| `Version20261026000001::down()` drops the audit table | Data loss if run on real data | Never run down-migrations in production (documented) |
| OpenTelemetry tracing (Phase 1 plan) | Logs only, no traces | Add with Phase 6 observability |
| Secrets only via environment variables: no `*_FILE` support for `DATABASE_URL`, `APP_SECRET`, `OIDC_CLIENT_SECRET`; no passphrase-protected keys | Secrets sit in `pal.env` / platform env; Fargate needs an init container for the certificate | Add `*_FILE` variants and an optional key passphrase |
| No managed/workload identity for the Entra credential and no token-based DB auth | Certificate and DB passwords must be managed and rotated | Evaluate Azure workload identity federation / IAM DB auth if a cloud target is chosen |
| Database TLS not configured in-app | Two connections (Doctrine + sessions) rely on `PGSSLMODE`/`PGSSLROOTCERT` | Document and test `sslmode=verify-full` end to end |
| Readiness only detects pending migrations | An older image against a newer schema still reports ready | Also compare the latest known migration with the DB version |
| Cache volumes hide the build-time warmed cache; OIDC discovery/JWKS cache per container | Slower first request after start | Warm cache at start, or move cache.app to a shared pool |
| Offline/enclave builds need package mirror build args (Alpine, npm, Composer) | Air-gapped builds need Dockerfile edits | Add build args for package sources |
| Fully disconnected (air-gapped) operation not supported: code assumes Entra (issuer shape, `tid`/`oid`, Graph) | Needs code changes for another IdP | Generalise the OIDC validator and directory adapter if ever required |
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
