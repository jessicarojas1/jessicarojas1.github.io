# VERITY — Open Items / Production-Readiness Register

Honest status as of this review. Verity is a **working application**
(Phases 1–3 of an 8-phase build) — the Enterprise Dashboard, Identity
Directory, Access Inventory/Unmatched Accounts, Application Catalog +
connector catalog, Enterprise Access Matrix, Dynamic Fields, self-service
account/password management, a full admin user-management flow (create with
password, edit details, reset password, activate/disable) inside the Admin
IAM console, Settings/Branding, and Audit trail are built and exercised by
an automated test suite (78 assertions, all passing). Everything below is
grouped by theme, each with **Impact** and **Suggested action**. Nothing in
this list should be read as "broken" — it is "not yet built," stated plainly
rather than implied to exist.

## Integrations

| Item | Impact | Suggested action |
|---|---|---|
| No live connector synchronization/discovery engine | Application Catalog + connector catalog are configuration/metadata only; no account, entitlement, role, or group data is ever pulled from a real external system | Build a sync execution engine per `Connectors::CAPABILITY_KEYS`, starting with one real connector (CSV import is the lowest-effort first step given `csv_import` already exists as a catalog type) |
| Microsoft Entra ID (GCC High) is a mock catalog entry only | The flagship identity-source connector has no live API calls anywhere in the codebase; `connector_type = 'entra_gcc_high_mock'` is explicitly a stand-in | Implement the real Graph client against `GRAPH_BASE_URL`/`ENTRA_AUTHORITY_HOST` once credentials and scope are approved; never point it at commercial Graph endpoints |
| No deterministic/automatic identity-account matching | Every account-to-person correlation today is manual (`identity_account_link.link_method` is always `'manual'` outside seeded data); `'deterministic'` is a recognized value but nothing produces it at runtime | Build a matching engine (email/employee-ID heuristics) that writes `link_method = 'deterministic'` with a confidence/audit trail, reviewed before auto-applying at scale |
| No notifications (email/Teams) | No module emits any outbound notification on any event (link/unlink, settings change, permission grant) | Add an outbound notification hook once a concrete trigger set is prioritized |

## Certification & Workflow

| Item | Impact | Suggested action |
|---|---|---|
| No certification/access-review campaigns | `entitlement_assignment.last_certified_at` exists as a column but nothing drives a review cycle, reminders, or sign-off | Build a campaign module (scope → reviewer assignment → decision capture → `last_certified_at` update), reusing `Authorize` scoping for reviewer assignment |
| No approval workflows or visual workflow designer | No request/approve/deny lifecycle exists anywhere in the app | Scope a minimal request→approve model before attempting a general-purpose designer |
| No remediation task management | Exception-view findings (orphaned accounts, stale temporary access, disabled-but-assigned entitlements) are visible but there is no task/ticket to track fixing them | Add a lightweight remediation-task table keyed off Matrix "exception" view rows |
| No access requests | There is no self-service "request access" flow; all entitlement assignment today is seeded or implied by direct DB/admin action | Design around the existing `entitlement_assignment` table once approval workflow above exists |

## Governance & Risk

| Item | Impact | Suggested action |
|---|---|---|
| No risk scoring/intelligence | `entitlement.risk_level` and `application.classification` are stored but nothing computes or aggregates a risk score | Define a scoring model against existing fields before adding new ones |
| No separation-of-duties (SoD) rules engine | Nothing detects or flags conflicting entitlement combinations | Scope a rules table + a Matrix-query-based conflict scan once a first rule set is defined by the business |
| `application.classification` has no enforcement/DLP behind it | The field is metadata only — selecting `restricted` does not restrict anything today | Decide whether classification should drive access display, redaction, or export restrictions, then implement |
| No custom report builder beyond the existing Matrix CSV export | Reporting is limited to the Matrix view's own filters + CSV export (capped at 5,000 rows) | Treat the capped CSV export as the baseline and scope a report builder only if a concrete reporting gap is identified |

## Security Hardening

| Item | Impact | Suggested action |
|---|---|---|
| No MFA | Local sign-in is password-only | Add TOTP or WebAuthn before any production use beyond demos |
| ~~No account lockout / login rate limiting~~ — **resolved** | `Auth::isLoginThrottled()` enforces both a per-account limit (10 failures / 15 minutes, against one email regardless of source IP) and a broader per-IP limit (30 failures / 15 minutes, across any emails from one source — catches a credential-stuffing spray). Both are soft, time-boxed lockouts that expire on their own, not permanent — a hard per-account lock with no expiry would let an attacker lock out a legitimate user just by guessing their password wrong repeatedly. Reuses the existing `audit_event` log rather than a new table; two new composite indexes (`idx_audit_action_target_created`, `idx_audit_action_ip_created`) keep the per-request check fast as that table grows. Regression-tested (`tests/db_test.php`) and verified live via curl (11th failed attempt against one account correctly throttled; a different account's legitimate login in the same window was unaffected) | None — already shipped. No CAPTCHA/progressive-delay layer on top of the hard threshold; add one if 10/15min proves too permissive in practice |
| ~~No WAF guidance~~ — **resolved** | Target-specific WAF guidance added to `deployments/SINGLE_LINUX_SERVER.md` (ModSecurity + OWASP CRS in front of nginx), `AWS.md` (AWS WAF on the ALB/CloudFront, with GovCloud availability confirmed — `wafv2.us-gov-*` endpoints exist in both GovCloud regions), `AZURE.md` (Front Door or Application Gateway WAF v2, with the Azure Government BYOC-certificate caveat for Front Door), and `KUBERNETES.md` (ingress-level ModSecurity annotations or the cloud LB's own WAF, not both). Every guide calls out the same real risk specific to this app: this app's own inputs (Dynamic Fields' free-text values, the Matrix CSV export's query filters, JSON POST bodies) can trip a default CRS profile, so each recommends a detection-only burn-in before blocking. Cloud availability claims (Front Door in Azure Government; AWS WAF in GovCloud) were verified via web search rather than asserted from memory — one initial draft of the Azure Government claim was wrong (said Front Door was unavailable there; it is available, with a certificate caveat) and was corrected before committing | None — already shipped as guidance. Not yet actually deployed/configured against the live Render service (Render's own edge likely needs a different approach than any of these four targets — Render is not one of the six standard deployment targets this app documents) |
| `audit_event` is append-only by application convention only, not yet by DB grant | A compromised app process could, in theory, still issue `UPDATE`/`DELETE` against the audit table — no database role restricts it today | Configure the production database role with `INSERT`/`SELECT` only on `audit_event` (see `docs/SECURITY.md`); this is an infra/DBA step outside `schema.sql`'s reach |
| No secrets-manager integration | Configuration is "whatever environment variables the platform injects"; no AWS Secrets Manager / Azure Key Vault wiring exists | Wire in per target as part of each `deployments/*.md` guide |
| FIPS readiness not evaluated | No assessment has been done of whether this app's cryptographic operations meet FIPS 140 | Commission a FIPS assessment before any claim of FIPS readiness; do not infer it from the GCC High endpoint defaults, which are a convention, not a crypto validation |
| ~~Permission/role changes didn't take effect for an already-logged-in session~~ — **resolved** | `Auth::user()` used to return a snapshot cached in `$_SESSION` at login time. It now re-fetches roles/grants/status fresh from the DB on every request (cached per-*request*, never per-session) — a permission change takes effect on the signed-in user's very next request, not at their next login. Verified live: disabling a user mid-session now terminates that session immediately (confirmed via curl — the next request 302s to `/auth/login`, and `auth.session_terminated` is written to `audit_event`), with zero re-login cycle needed | None — already shipped. Three extra DB queries per authenticated request (user row + roles + grants) is the accepted cost for always-correct, live authorization; revisit only if this is ever shown to be a real bottleneck |
| A password reset alone (without also disabling the account) does not terminate the target user's already-active session | `Auth::user()`'s live re-check covers `status`, roles, and grants — not `password_hash`. An admin resetting a compromised user's password cuts off *future* logins immediately, but an attacker who already holds a valid session cookie keeps working until it naturally expires. This is standard behavior in most systems (a password reset isn't usually treated as a full kill-switch), but it should be the *documented*, intentional behavior, not an assumption: **to fully cut off an account right now, disable it — that already works instantly** | If "reset password" should also always kill existing sessions, that's a product decision, not a bug — flag it if wanted. Until then, document the distinction in incident-response guidance: disable = immediate, reset-password-only = blocks future logins only |
| ~~Password policy is length-only; no breach-list check~~ — **resolved** | `Auth::passwordPolicyError()` now also calls `Auth::isPasswordBreached()`, which queries the Have I Been Pwned range API via k-anonymity (only a 5-char SHA-1 prefix leaves the server; response padding requested; plaintext password and full hash never transmitted). Fails open on any network error/timeout so a third-party outage never blocks sign-in or a password change; disable entirely via `PASSWORD_BREACH_CHECK_ENABLED=false` (required for air-gapped — see `deployments/AIRGAPPED.md`, updated). Verified live end-to-end through the real `/app/profile` HTTP form: a known-breached password (`password123456`) was correctly rejected, a unique password was accepted, and a regression test (`tests/unit_test.php`) covers the pure response-parsing logic deterministically with no network dependency in the always-on test group. **Notable finding surfaced by this check:** the seeded demo password `ChangeMe123!` (used by `database/seed.php` only, which bypasses this policy check entirely) is itself a known-breached string — harmless for a value whose own name says to change it immediately, but confirms the check works correctly against a realistic weak password | None — already shipped. Requires the PHP `curl` extension in production; the Dockerfile was updated to compile it (`curl-dev` build dep + `docker-php-ext-install curl`) but **this container build has not been verified in this environment (no Docker available here)** — verify the next Render deploy picks it up cleanly |
| ~~No CI pipeline for this app~~ — **resolved** | `.github/workflows/verity-ci.yml` lints every PHP file and runs the full test suite (logic + live-DB groups, currently 78 assertions) against a fresh Postgres 16 service container, on every push/PR touching `verity/**`. Modeled on `redoubt-ci.yml`'s established pattern. Verified by simulating the exact same steps locally end-to-end against a throwaway database before committing (schema applied cleanly, all assertions passed) | None — already shipped. No deploy-gating yet (Render deploys are still manual/git-push triggered, independent of CI's pass/fail) — add a required-status-check branch rule if merges to `main` should be blocked on a red build |

## Performance & Scale

| Item | Impact | Suggested action |
|---|---|---|
| Unbenchmarked at scale | Seed data (150 people / 15 applications / ~750 accounts) is demonstration scale, far below the platform's eventual 100k-account/1M-assignment design target; Matrix queries use indexed joins with `LIMIT`/`OFFSET` but have never been load-tested | Benchmark against a synthetic dataset at target scale before any claim of production readiness at enterprise scale |
| Matrix CSV export capped at 5,000 rows | An enterprise-scale export (beyond the cap) is not supported | Documented as a Phase 8 follow-up in `MatrixController::export()`; revisit if a larger export is needed before then |

## Operations

| Item | Impact | Suggested action |
|---|---|---|
| **The production container runs PHP's built-in server** (`php -S`) | php.net's own documentation states this server is single-threaded and not intended for production use | Front the app with PHP-FPM + nginx or Caddy for any real production deployment; the Docker image would need a second stage |
| No automated database backups | Only a managed provider's own point-in-time recovery, or an operator-run `pg_dump`, exists as a backup path — neither is configured today | Enable managed-provider PITR, or schedule `pg_dump`, per `docs/DISASTER_RECOVERY.md` |
| No backup-restore drill has ever been performed | The restore runbook in `docs/DISASTER_RECOVERY.md` is unverified in practice | Run the drill against a disposable target; repeat quarterly once backups are automated |
| No high availability | Single app instance, single DB instance; PHP sessions are in-process, which blocks horizontal app-tier scaling until a shared session store is added | Add a shared session store (DB- or Redis-backed) before attempting multi-instance deployment; add DB failover separately |
| No metrics, tracing, or alerting | Observability today is `/health` + the `audit_event` table + `error_log`, per `docs/ARCHITECTURE.md` | Add a metrics/alerting integration once a target platform is chosen |
| Entra ID GCC High SSO not implemented | `GET /auth/sso` returns a deliberate `503`; local email/password is the only working sign-in path | Implement once a GCC High tenant + app registration are available and the hand-rolled-vs-vetted-library decision for token verification is made (see "Decisions required" below) |
| `GET /setup?token=...` is a remote-bootstrap endpoint, added for deploy targets with no shell access (e.g. Render's free compute plan) | Applies `schema.sql` and runs `seed.php` over HTTPS instead of a shell. Gated behind a `SETUP_TOKEN` env var (constant-time compare; 404s entirely when unset) and self-limiting even if the token leaks — schema application is idempotent and seeding only ever runs when `person` is empty, so it cannot disturb real data. Still, it's attack surface that doesn't need to exist once initial setup is done | **Unset the `SETUP_TOKEN` env var after first use** on any deployment (closes the endpoint back to a permanent 404). Consider removing the route/controller entirely in a follow-up once every target that needs it has been bootstrapped |
| ~~Code comments referenced documentation files that didn't exist~~ — resolved | `docs/GCC_HIGH_INTEGRATION.md` and `docs/API_SPECIFICATION.md` have been written; the remaining two dangling references (`docs/CONNECTOR_DEVELOPMENT_GUIDE.md`, `KNOWN_LIMITATIONS.md`) were redirected to point at this file instead, since a dedicated connector-development guide isn't warranted until a real connector exists | None — author `CONNECTOR_DEVELOPMENT_GUIDE.md` for real once the first live connector (see "First real connector to build" below) is built, not before |

## Decisions required

| Decision | Options | Recommendation |
|---|---|---|
| Entra token verification: hand-roll vs. vetted library | (a) Hand-roll JOSE/OIDC verification as REDOUBT's `Oidc` class does, with a mandatory security review before enabling production sign-in; (b) adopt a vetted third-party OIDC/JOSE library, which would be this app's first real Composer dependency | (b) — a vetted library removes an entire class of hand-rolled-crypto risk, and the "zero dependencies" principle should yield to correctness for authentication-critical code |
| First real connector to build | (a) CSV import (lowest effort, no live credentials needed); (b) Entra GCC High (highest value but needs tenant/app-registration access and GCC High compliance review) | (a) first, to prove the sync-engine shape end-to-end before taking on GCC High's compliance surface |

## Notes / known limitations

- **Verified in this review (2026-10-09):** `php tests/run.php` passes
  47/47 logic assertions with no database configured; against a throwaway
  local PostgreSQL 16 instance with `VERITY_TEST_DB=1` set, the full suite
  passes **78/78** (0 failed, 0 skipped), including the reporting-chain and
  application-ownership scoping tests against an isolated fixture that is
  rolled back afterward (confirmed empty post-run), and the user-creation/
  password-reset flow (regression-tested specifically because an earlier
  version of `Users::create()` produced permanently unusable accounts —
  see the Security Hardening and git history for the fix). Also verified
  live in a browser against the running app: self-service password change
  (wrong-current-password rejection, then a successful change, then
  confirmed the old password stops working and the new one works), admin
  "Invite User" with a generated password (confirmed the new account could
  sign in immediately), admin password reset (confirmed the new password
  works for login), the self-disable guard (blocked both in the UI and via
  a direct API call bypassing it), and the new breach-list password check
  (a known-breached password was correctly rejected by the live
  `/app/profile` change-password form, and a unique password was accepted
  — the full round trip through the real `Auth::isPasswordBreached()` →
  Have I Been Pwned HTTP call, not just the pure parsing logic). The CI
  pipeline's exact steps (lint, apply `database/schema.sql`, run the full
  suite) were also simulated locally against a disposable database before
  being committed. `database/schema.sql` applies cleanly start-to-finish
  against a fresh database with no errors.
- **Not verified in this environment: the production Docker build.** The
  Dockerfile was changed to compile the PHP `curl` extension (needed by
  the new breach check) alongside `pdo_pgsql`, but no Docker daemon is
  available in this environment to actually build the image — the change
  is reviewed and follows the exact same pattern as the existing
  `postgresql-dev`/`pdo_pgsql` build step, but `docker build .` should be
  run (or the next Render deploy watched) to confirm it compiles cleanly
  before relying on it in production.
- `person_relationship` (secondary manager/delegate relationships) exists in
  the schema but is additive and not yet surfaced anywhere in the UI.
- `connector_sync_job` exists in the schema to record sync job history but
  nothing writes to it yet, since there is no sync execution engine.
