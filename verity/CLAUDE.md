# CLAUDE.md — VERITY (Enterprise Identity & Access Governance)

Project guidance for VERITY. Follows the repo-wide rules in the parent
`../CLAUDE.md` (security/UI audits, standard doc set, schema currency, push
to main). This file adds VERITY-specific context.

## What this is

An **Enterprise Identity & Access Governance (IGA)** platform —
*"Unified Visibility. Verified Access. Complete Accountability."* Current
phase: **Phases 1–3 of an 8-phase build** are implemented and tested —
Enterprise Dashboard, Identity Directory, Access Inventory/Unmatched
Accounts (manual correlation only), Application Catalog + connector catalog
(configuration/metadata only — no live sync execution), Enterprise Access
Matrix (6 authorization-scoped views + CSV export + saved views), Dynamic
Fields, Admin IAM console, Settings/Branding, and an append-only Audit
trail. Do **not** claim Phase 4+ features (live connector sync, Entra GCC
High SSO, MFA, certification campaigns, approval workflows, remediation
task management, access requests, risk scoring, SoD rules, notifications)
exist — see `OPEN_ITEMS.md` for the authoritative list of what's missing.

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

Phase 1–3 (now, built + tested) → Phase 4 integrations (live connector sync,
Entra GCC High SSO) → Phase 5 certification/review campaigns → Phase 6
workflow automation & remediation → Phase 7 risk scoring/SoD → Phase 8
reporting/analytics & scale hardening. Do not build a later phase's feature
ahead of its gate without updating `OPEN_ITEMS.md` to reflect the new status
in the same change.

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
