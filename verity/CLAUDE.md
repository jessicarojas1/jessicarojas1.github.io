# CLAUDE.md — VERITY (Enterprise Identity & Access Governance)

Project guidance for VERITY. Follows the repo-wide rules in the parent
`../CLAUDE.md` (security/UI audits, standard doc set, schema currency, push
to main). This file adds VERITY-specific context.

## What this is

An **Enterprise Identity & Access Governance (IGA)** platform —
*"Unified Visibility. Verified Access. Complete Accountability."* Current
phase: **Phases 1–3 of an 8-phase build** are implemented and tested —
Enterprise Dashboard, Identity Directory, Access Inventory/Unmatched
Accounts (manual correlation plus deterministic match suggestions — see
`Accounts::suggestMatch()`), Application Catalog + connector catalog, a
real CSV import sync engine (`app/Support/CsvImport.php` — see
`docs/CONNECTOR_DEVELOPMENT_GUIDE.md`; pulled forward from Phase 4's
"live connector sync" ahead of its original gate, deliberately, with
`OPEN_ITEMS.md` updated in the same change this happened — the Entra GCC
High half of that Phase 4 item is still not started), Enterprise Access
Matrix (6 authorization-scoped views + CSV export + saved views), Dynamic
Fields, Admin IAM console, Settings/Branding, an append-only Audit trail,
and — pulled forward from Phase 5, same precedent as CSV import —
**certification campaigns** (`app/Support/Campaigns.php`: scope frozen at
launch, manager- or fixed-reviewer assignment, approve/revoke decision
capture, `entitlement_assignment.last_certified_at` driven by real
approvals), plus — pulled forward from Phase 6, same precedent again —
**remediation task tracking** (`app/Support/RemediationTasks.php`: a
campaign's "revoked" decision auto-opens a task, or one can be flagged
manually from any account's detail page; resolving/dismissing a task
records that a human handled it — this app still has no connector that
can execute a revocation, so a task is a to-do, never an executed
action), and **access requests / approval workflow**
(`app/Support/AccessRequests.php`: request an entitlement for an
*existing* account — this app provisions no new accounts, so there is
nothing to request on an app a person has no account in yet; approval is
scoped to the target application's system owner via the same
`Authorize::ownsApplication()` family the Matrix already uses, or an
admin; **approving genuinely creates the `entitlement_assignment` row**,
unlike a campaign revoke or a remediation task — see that class's own doc
comment for why that's not the same kind of claim). Do **not** claim the
rest of Phase 4+ (Entra GCC High SSO, MFA, risk scoring, SoD rules,
notifications) exists — see `OPEN_ITEMS.md` for the authoritative list of
what's missing.

## Standing rules for this project

- **The dual DB-backed `Authorize` scoping is the architectural core —
  never bypass it with a parallel check.** Beyond the ordinary
  role-default/explicit-grant/explicit-deny three-state model (denials
  always win), two scoped permission families are enforced by recursive/
  direct SQL inside `Authorize` itself, not by the caller:
  - `*.reports` / `matrix.view.supervisor` — reporting-chain scope, via a
    recursive CTE over `person.manager_person_id`
    (`Authorize::isInReportingChain()` / `reportsOf()`).
  - `*.owned` — application-ownership scope, via
    `application.system_owner_person_id`
    (`Authorize::ownsApplication()`).
  Every new permission check — in a controller, in the API router, in a
  future module — must go through `Authorize::can()` /
  `Authorize::requirePermission()`. Never add a module-local or
  query-engine-local authorization check "for efficiency." The Matrix query
  engine (`app/Support/Matrix.php`) is deliberately dumb about
  authorization for exactly this reason: there must be exactly one place
  this can go wrong, not two.
  **A real bug in exactly this engine was found and fixed during a security
  audit of this build:** `can()` used to check the wildcard (`*`,
  `enterprise_admin`'s role) before checking the user's explicit deny list.
  Because `effectivePermissions()` collapses a wildcard role down to the
  single literal key `'*'` (never the expanded list of every permission), an
  explicit deny on a specific permission was a silent no-op for any wildcard
  user — the one documented invariant of this engine ("denials always win")
  did not hold for the highest-privilege role. Fixed by checking the raw
  deny list first, before the wildcard short-circuit (see git history /
  `tests/unit_test.php`'s "denials always win... with no exceptions"
  regression test). When touching `Authorize::can()` again, preserve this
  ordering — deny-check before wildcard-check, always.
  **Also note:** permission/role changes do not take effect for an
  already-logged-in session — `Auth` caches `roles`/`grants` into
  `$_SESSION` at login time, and `Authorize::can()` reads only that cached
  snapshot. A revoked or newly-denied user stays as they were until they
  re-authenticate. See `OPEN_ITEMS.md`.
- **No `style=""` attributes, no `element.style.x` mutations — ever.** This
  codebase's CSP nonce covers `<style>` elements and `<link>` stylesheets
  but **not** the `style=""` HTML attribute. A real bug (an inline style
  slipping past the CSP) was found and fixed during this build. The
  standing rule going forward:
  - One-off styling is a utility class in `public/assets/app.css`
    (`.hidden`, `.mt-14`, `.flex-1-min260`, etc.), never an inline
    attribute.
  - JS-driven visibility/state toggles use `classList`, never
    `element.style.*`.
  - A live-updated CSS custom property (the one real case today: the
    Settings → Branding accent-color preview) is done by rewriting the
    `textContent` of a nonce'd `<style id="...">` element — see
    `app/Views/app_settings.php` + `public/assets/settings.js` as the
    reference pattern — never by setting `element.style.setProperty(...)`.
- **No inline event handlers.** `public/assets/confirm.js`, loaded on every
  authenticated page via `app/Views/partials/app_footer.php`, handles every
  `data-confirm="..."` button through one delegated click listener. This is
  this project's established pattern — simpler than a full
  `data-click`/dispatch system, and sufficient for what this app needs
  today. Follow it for any new confirm-before-action button rather than
  adding `onclick=`.
- **`Db` / `Auth` / `Authorize` naming (matches REDOUBT, diverges from the
  generic root `CLAUDE.md`'s literal `Database::`/`Auth::requirePermission()`
  examples) is an intentional, already-made decision, not an open
  question.** The root prompt's security-audit checklist item "every
  protected route calls `Auth::requireAuth()` or `Auth::requirePermission()`"
  maps onto this codebase as: every protected route calls
  `Auth::requireAuth()` (session check) **and** separately
  `Authorize::requirePermission()` (permission check) — two classes with
  one responsibility each, not one class doing both. Keep using `Db`,
  `Auth`, and `Authorize` as named; do not rename or consolidate them to
  match the root prompt's literal example names.
- **`Db::update()` auto-appends `updated_at = NOW()`** only when the
  target table actually has that column (`Db::hasColumn()`, cached per
  table) — never include `updated_at` in a data array passed to
  `Db::insert()`/`Db::update()`.
- **Any new connector sync engine follows `docs/CONNECTOR_DEVELOPMENT_GUIDE.md`'s
  pattern — `app/Support/CsvImport.php` is the reference implementation.**
  Open a `connector_sync_job` row before processing anything, upsert via
  `INSERT ... ON CONFLICT` keyed on each target table's existing UNIQUE
  constraint (never a separate check-then-insert — this is what makes a
  re-run idempotent), collect row-level failures instead of aborting the
  whole run on the first bad row, and close the job with real counts and a
  terminal status. Never trust a client-supplied MIME type/filename for a
  file-based connector; never persist the raw upload; cap size and row
  count before doing real work. See the guide's §4 for the full checklist
  and §5 for what additionally applies to a live API-based connector.
- **Connectors never claim a capability without confirming it.**
  `Connectors::defaultManifest()` starts every capability key at `false`
  and only flips one to `true` for a connector type that has actually
  earned it (and the GCC High manifest is explicitly labeled a mock in its
  own doc comment). When building real connector execution, update the
  manifest to reflect only what has been verified against the real source
  system — never what the source system is merely expected to support.
- **Dynamic fields are never hard-deleted.** Retiring a field flags it
  `active = false`; "replace" creates a new definition that points back at
  the old one via `replaces_field_definition_id`
  (`DynamicFields::replace()`). Preserve this lineage pattern for any
  future field-definition lifecycle work.
- **A certification campaign's scope is frozen at launch — never a live
  query.** `Campaigns::create()` snapshots every currently in-scope
  `entitlement_assignment` into `certification_campaign_item` the moment
  the campaign launches; the campaign is reviewed against that snapshot,
  never against whatever the Matrix would return if you asked the same
  question later. This is a deliberate product decision (a mid-review
  moving target defeats the point of a point-in-time attestation), not a
  performance shortcut — don't "simplify" this to a live join.
- **A "revoked" campaign decision records the reviewer's judgment — it
  does NOT delete the underlying `entitlement_assignment`.** No connector
  in this build claims `revoke_access`/`modify_access` (see
  `Connectors::defaultManifest()`), so there is no live system this app
  could push a revocation back to; silently deleting Verity's own
  inventory record would claim a removal that didn't actually happen
  anywhere, which is exactly the capability-manifest dishonesty that rule
  forbids. It instead auto-opens a `RemediationTasks` row to track it —
  see that class's own standing rule below. Actually removing the access
  is still a deliberate, separate human step (through the Access Matrix or
  the account's own detail page) — do not wire campaign revocation, or
  resolving the resulting remediation task, straight into deleting the
  assignment without a product decision to change this.
- **Reviewer authorization is a direct ownership check
  (`reviewer_person_id = the caller's own person_id`), re-verified inside
  `Campaigns::decide()` itself — not delegated to the controller, and not
  the reporting-chain scope.** A campaign item's reviewer was decided once,
  at snapshot time (`Campaigns::create()`'s `reviewer_strategy`
  resolution); "can this caller act on this item" is then a simple
  equality check against that stored value, the same pattern
  `ProfileController` uses for "is this your own account" — not a new
  entry in `Authorize`'s scoped-permission families. `decide()` re-checks
  this itself (`WHERE id = :id AND reviewer_person_id = :pid`) specifically
  so there is no path to deciding someone else's review item even if a
  future caller forgets to check first.
- **A remediation task is a tracked to-do, never an executed action —
  `RemediationTasks` has no code path that touches
  `entitlement_assignment`/`system_account` at all.** This mirrors
  `Campaigns`' revoke-doesn't-delete rule for the same underlying reason:
  no connector in this build claims `revoke_access`/`modify_access`/
  `disable_accounts`, so there is no live system this app could execute a
  removal against. `resolve()`/`dismiss()` only ever change the task's own
  `status` — never the account or assignment it references. The one place
  automation is allowed is *creating* the tracking row itself
  (`RemediationTasks::createFromCampaignRevoke()`, called from
  `Campaigns::decide()` on every 'revoked' decision): that's bookkeeping,
  not an access change, so it doesn't violate the no-silent-automation
  principle the way auto-resolving or auto-executing one would. If a task
  type is ever added whose resolution really should trigger something
  automatically, that needs its own manifested capability and its own
  product decision — not a quiet addition to `resolve()`.
- **An access request always targets an existing `system_account` — never
  a bare person+application pair.** This app provisions no accounts
  anywhere (no connector claims `provision_access`), so "request access to
  application X" is only answerable when the requested person already has
  *some* account in X; `AccessRequests::create()` deliberately has no path
  that creates a `system_account`. Don't "simplify" the request form to
  skip the account-selection step — that would silently imply this app can
  provision accounts, which it can't.
- **Approving an access request DOES create the `entitlement_assignment`
  row (`source = 'manual'`) — this is the one place in the Phase 5/6
  pulled-forward work where an automated write to that table is correct,
  not a violation of the "never auto-apply" pattern.** The distinction
  from a campaign's revoke-doesn't-delete rule: revoking claims an
  external system's access should stop existing, which this app cannot
  enforce or verify; approving a request is Verity recording its own
  grant decision in its own inventory, exactly the same meaning
  `source = 'manual'` already carries everywhere else (CSV import uses
  `source = 'csv_import'`/`'connector'` for discovered data; a human
  entering a grant directly has always meant `'manual'`). Do not change
  `AccessRequests::approve()` to merely mark the request approved without
  creating the assignment — that would make an approved request a dead
  end with no way to become real inventory.
- **Who can decide a request is a *live* `Authorize::ownsApplication()`
  check at decision time, never the snapshotted `approver_person_id`.**
  `approver_person_id` is informational only (who the system expected to
  decide it, captured at request-creation time for display) — ownership
  can change between request and decision, so
  `AccessRequestsController`'s approve/deny/cancel actions re-derive
  authorization through the same scoped `*.owned` permission family the
  Matrix and Application Catalog already use
  (`accessrequest.approve.owned` + `ctx['application_id']`), reusing the
  dual DB-backed `Authorize` scoping rather than trusting a value written
  days or weeks earlier.
- **`Users::create()` must always receive a password and set the account
  `active` immediately.** An earlier version inserted `status = 'invited'`
  with no `password_hash` at all — since there is no invitation-email flow
  in this build, that produced a permanently unusable account (nothing could
  ever set its password). Fixed, and guarded by a regression test
  (`tests/db_test.php`, "Users::create — a newly created user can log in
  immediately"). If an invitation-email flow is ever built, the `'invited'`
  status can become meaningful again — until then, every path that creates a
  user must supply a password through `Auth::passwordPolicyError()` /
  `password_hash()`, exactly like `IamController::createUser()` does.
- **Every password-setting path — self-service change
  (`ProfileController::changePassword()`), admin creation
  (`IamController::createUser()`), and admin reset
  (`IamController::resetPassword()`) — must validate through
  `Auth::passwordPolicyError()`.** Don't add a fourth path that skips it.
- **An admin can never disable their own account** — enforced server-side in
  `IamController::setStatus()`, not just hidden in the UI (verified during
  this build: a direct API call bypassing the UI was still correctly
  rejected). Keep this guard if `setStatus()` is ever refactored; without it,
  an `enterprise_admin` with no other admin account could lock themselves out
  with no way back in short of direct database access.
- **`Auth::user()` re-validates against the database on every request — it
  must never go back to trusting a snapshot cached in `$_SESSION` across
  requests.** This used to be a real gap: roles/grants were read once at
  login and stashed in the session, so a permission revocation or an
  account disable didn't take effect until the affected user's *next
  login* — confirmed live during this build (a fresh explicit deny was
  invisible to an already-authenticated session). Fixed by making
  `$_SESSION` hold only the user id; `Auth::user()` re-fetches
  status/roles/grants fresh every request, cached with a private static
  flag for the duration of that one request only (`self::$requestUser` /
  `self::$requestUserLoaded` — these must stay request-scoped, never
  persisted). If `status` is no longer `'active'`, the session is
  destroyed on the spot. The one known remaining gap, intentionally not
  "fixed" further without a product decision: a password reset *alone*
  (without also disabling) does not retroactively invalidate an
  already-issued session, since the live check covers status/roles/grants,
  not `password_hash` — see `OPEN_ITEMS.md`.
- **Never compare a PHP-formatted timestamp string against a `TIMESTAMPTZ`
  column — compute the interval inside the SQL instead.** A real bug: the
  original login-rate-limit check built its time threshold with PHP's
  `gmdate()` (UTC) and passed it as a plain `:since` parameter. Postgres
  interprets a naive (no-offset) string against the *session's* `TimeZone`
  setting, not UTC — the threshold silently landed hours in the future, and
  the failed-attempt count always read 0 (never throttled, no matter how
  many failures). Fixed by using `NOW() - make_interval(mins => :n)` so
  Postgres does all the time arithmetic itself, with no PHP/Postgres
  timezone boundary to get wrong. Apply the same pattern to any future
  "how many X happened in the last N minutes" query — see
  `Auth::isLoginRateLimited()` for the reference implementation.
- **`Auth::passwordPolicyError()` checks a breach list, not just length —
  keep the network call fail-open and config-gated.**
  `Auth::isPasswordBreached()` queries the Have I Been Pwned range API
  using k-anonymity (only a 5-char SHA-1 prefix ever leaves the server).
  Two invariants to preserve if this is ever touched again: (1) **fail
  open** — any network error, timeout, non-200, or malformed response must
  make the password pass the breach check, never fail it, since this
  app's own availability (sign-in, password change, account creation) must
  never depend on a third party's uptime; (2) **config-gated** —
  `Config::breachCheckEnabled()` (`PASSWORD_BREACH_CHECK_ENABLED`, default
  `true`) must stay checkable to `false` with zero outbound call made at
  all, since the air-gapped deployment target (`deployments/AIRGAPPED.md`)
  has no path to `api.pwnedpasswords.com`. This is also why the
  always-on, no-DB unit test group for `passwordPolicyError()` disables the
  check via `putenv()` first — those assertions must stay deterministic and
  network-free; the breach-check logic itself is tested separately via the
  pure `Auth::rangeResponseContainsSuffix()` parser against synthetic
  response bodies. The PHP `curl` extension is required in production —
  the Dockerfile compiles it alongside `pdo_pgsql` (`curl-dev` build dep +
  `docker-php-ext-install curl`).
- **`Accounts::suggestMatch()` only ever suggests — never call `Accounts::link()`
  from anywhere except a request a human submitted.** The deterministic
  matching engine (employee-ID/email heuristics) returns a candidate only
  when exactly one person qualifies under a given rule; ambiguous or
  no-match cases return `null` rather than guessing. There is no "auto-link
  all high-confidence suggestions" batch path, and none should be added
  without a product decision — see OPEN_ITEMS.md's "reviewed before
  auto-applying at scale" framing, which is the whole reason this stays a
  suggestion engine. Just as important: **the `link_method='deterministic'`
  audit label is server-verified, never client-asserted.**
  `AccountsController::link()` re-runs `suggestMatch()` itself before
  trusting a request's `accept_suggestion` flag, and only records
  'deterministic' if the server's own fresh computation agrees with the
  submitted `person_id` — otherwise it silently falls back to 'manual'.
  This was verified directly, not just written: a bypassing API call that
  claimed `accept_suggestion=1` with a `person_id` that didn't match the
  real suggestion was correctly recorded as 'manual'. Preserve this
  re-verification if `link()` is ever touched again — a client should
  never get to dictate what the audit trail says about *how* a decision
  was made, only what the decision *was*.
- **`Authorize::reportsOf()` / `isInReportingChain()`'s recursive CTEs
  must stay cycle-safe — never remove the path-tracking guard.**
  `person.manager_person_id` is operator-editable data, not a hierarchy
  this schema's constraints prevent from cycling. A plain `WITH RECURSIVE`
  over it (no guard) never terminates against a self-reference or a cycle,
  because `UNION ALL` doesn't deduplicate — a cyclic node keeps
  re-qualifying for the join every iteration forever, pegging the database
  connection's CPU indefinitely. Found live, not hypothetically: a
  20,000-row synthetic benchmark dataset (`database/benchmark.php`)
  produced a few self-referencing rows via a floating-point boundary case
  in its own generator, and the un-guarded query hung for minutes against
  a real PostgreSQL 16 instance before this fix. Both queries now carry a
  visited-ids array (`path`) and a `WHERE NOT (p.id = ANY(path))` guard;
  verified to terminate in milliseconds against a deliberately
  reintroduced self-reference and a 2-node cycle, and covered by a
  regression test (`tests/db_test.php`, "recursive CTEs terminate against
  cyclic manager_person_id data"). Preserve this guard — and apply the
  same pattern to any future recursive CTE walking operator-editable
  parent/child data — if these queries are ever touched again.
- **`Session::destroy()` guards `session_destroy()`/`setcookie()` behind
  `session_status() === PHP_SESSION_ACTIVE`.** Under CLI SAPI,
  `Session::start()` never calls the real `session_start()` (by design, to
  avoid header warnings in tests), so a CLI-context call to `destroy()` used
  to emit a PHP warning trying to tear down a session that was never
  started. Found when `Auth::user()`'s live re-validation started calling
  `Session::destroy()` from a code path a test could actually reach. Keep
  the guard if this method is ever touched again.
- **Keep the doc set current.** Update `docs/`, `deployments/`,
  `README.md`, `OPEN_ITEMS.md`, and `database/schema.sql` in the same
  change as any feature, migration, or config change. `schema.sql` must
  always reflect the full combined schema across all changes — it is the
  single source of truth; there is no separate migration history to keep
  in sync with it.
- **GCC High endpoints only, when that connector is ever built.**
  `Config`'s defaults (`graph.microsoft.us`, `login.microsoftonline.us`,
  `portal.azure.us`) must never be pointed at the commercial `.com`
  equivalents for this app's eventual Entra/Graph integration.
- **Deploy targets:** must stay Docker-deployable and Render-deploy
  compatible. The current container runs PHP's built-in server
  (`php -S`) directly — known non-production-hardened per php.net's own
  docs; see `OPEN_ITEMS.md`. Do not "fix" this silently by swapping in
  PHP-FPM/nginx without updating `docs/DEPLOYMENT.md`'s production
  checklist and `OPEN_ITEMS.md` in the same change.

## Roadmap gates (8-phase build)

Phase 1–3 (now, built + tested; now also includes a real CSV import sync
engine pulled forward from Phase 4, certification campaigns pulled
forward from Phase 5, and remediation task tracking + access requests/
approval workflow pulled forward from Phase 6 — see below) → Phase 4
integrations (Entra GCC High SSO remains the one item here not yet
started) → Phase 5 (certification campaigns are done) → Phase 6 (done —
both remediation task tracking and access requests/approval workflow
shipped) → Phase 7 risk scoring/SoD → Phase 8 reporting/analytics & scale
hardening. Do not build a later phase's feature ahead of its gate without
updating `OPEN_ITEMS.md` to reflect the new status in the same change —
CSV import, certification campaigns, remediation task tracking, and
access requests are the precedent for how to do this honestly: each was
pulled forward deliberately, each resolved its own "decision required" or
open row in `OPEN_ITEMS.md` in the same change the code landed, and each
kept the still-undone part of its original phase (Entra GCC High) clearly
separate rather than implying it moved too.

## Per-milestone checklist (repo standard)

- [ ] Security & compliance audit (CSP, XSS, CSRF, SQLi, authz — including
      both the three-state model and the two scoped families above,
      uploads, redirects)
- [ ] UI consistency check (handlers, modals, filters, empty states, dark
      mode, breadcrumbs, zero inline `style=""`)
- [ ] `database/schema.sql` updated to reflect all changes
- [ ] Standard doc set present & current (`deployments/` ×6, `docs/` ×4,
      root files)
- [ ] `php tests/run.php` passes (both the always-on logic group and, when
      touching `Authorize`/`Db`/any module's live-DB behavior, the
      `VERITY_TEST_DB=1` DB-backed group)
- [ ] Fix all findings before marking the milestone complete
