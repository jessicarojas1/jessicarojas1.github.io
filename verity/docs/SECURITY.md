# VERITY — Security Guide

## Identity & authentication

Local email + password only, today. `Auth` (`app/Support/Auth.php`):

- Passwords are verified with `password_verify()` against a hash produced by
  PHP's `password_hash()` default algorithm (`PASSWORD_DEFAULT`); the app
  does not pin a specific algorithm and will follow whatever PHP's own
  default migration path is.
- A successful login regenerates the session id (`Session::regenerate()`)
  before writing user data into `$_SESSION`, which mitigates session
  fixation.
- A failed login attempt is audited (`auth.login_failed`) with the
  attempted email as the target, but does **not** reveal whether the email
  exists — the same generic "Incorrect email or password" message is shown
  either way.
- Sessions cookie: `VERITY_SID`, `HttpOnly`, `SameSite=Lax`, `Secure`
  whenever the request is HTTPS (directly or via `X-Forwarded-Proto`).
- There is **no MFA** — still an open item, see `OPEN_ITEMS.md`.
- **Login rate limiting** (`Auth::isLoginThrottled()`): 10 failed attempts
  against one account, or 30 failed attempts from one source IP across any
  accounts, within a rolling 15-minute window, each independently triggers a
  soft, time-boxed lockout (expires on its own — not permanent, so an
  attacker can't lock out a legitimate user just by guessing their password
  wrong on purpose). Enforced against the existing `audit_event` log, not a
  separate table. The time window is computed inside the SQL itself
  (`NOW() - make_interval(...)`) rather than passed as a PHP-formatted
  timestamp — an earlier version did the latter and the threshold was
  silently wrong (Postgres interpreted the naive UTC string against its own
  session timezone, not UTC, pushing the cutoff hours into the future).
- Password policy is length-first, per NIST SP 800-63B (no forced
  symbol/digit mixing): `Auth::MIN_PASSWORD_LENGTH` (12) /
  `Auth::MAX_PASSWORD_LENGTH` (128), plus a breach-list check, both enforced
  by `Auth::passwordPolicyError()` everywhere a password is set —
  self-service change, admin creation, and admin reset alike.
  `Auth::isPasswordBreached()` queries the Have I Been Pwned Pwned
  Passwords range API using k-anonymity: only the first 5 hex characters of
  the password's SHA-1 hash are ever sent, with response padding
  (`Add-Padding: true`) requested so a network observer cannot infer the
  real match count from response size — the plaintext password and the
  full hash never leave this server. The check **fails open** (treats the
  password as not breached) on any network error, timeout, or malformed
  response, since a third-party outage must never block sign-in or a
  password change; it is disabled entirely, with no outbound call made at
  all, via `PASSWORD_BREACH_CHECK_ENABLED=false` (required for air-gapped
  deployments — see `deployments/AIRGAPPED.md`). The seeded demo password
  (`ChangeMe123!`, used only by `database/seed.php`, which bypasses this
  policy check entirely since it is not reachable by the public) is itself
  a known-breached string — expected and harmless for a value whose name
  says to change it immediately, but a sign that any copy-pasted "obviously
  temporary" password is a bad real-world choice.

**Self-service password change** (`/app/profile`, `ProfileController`) is
available to every authenticated user regardless of role — it only ever acts
on the caller's own account, so it needs no `Authorize` permission check,
only `Auth::requireAuth()`. Requires the current password (verified via
`Auth::verifyPassword()`) before accepting a new one, rejects a new password
identical to the current one, and regenerates the session id on success.

**Admin user management** (`iam.manage` permission, inside the Admin IAM
console) covers the full lifecycle: `Users::create()` requires an initial
password and sets the account `active` immediately — there is no invitation-
email flow in this build (see `OPEN_ITEMS.md`), so a password-less
`'invited'` account would be permanently unusable; a prior version of this
code had exactly that bug, since fixed. Admins can also edit a user's
display name/email/linked identity (`Users::updateDetails()`), reset a
user's password (`Auth::setPassword()` via `IamController::resetPassword()`
— no email delivery, the admin shares the new password out of band), and
activate/disable an account (`Users::setStatus()`). **An admin cannot disable
their own account** — enforced server-side in `IamController::setStatus()`,
not just hidden in the UI, so there is always at least one way to regain
enterprise_admin access without direct database surgery.

**Authorization state is re-validated live, every request — never trusted
from a login-time snapshot.** `Auth::user()` re-fetches the signed-in user's
`status`, roles, and permission grants from the database on every request
(cached only for the duration of that one request, never across requests).
Practical effect: a permission change, role change, or account disable takes
effect on that user's *very next request* — not merely their next login.
Disabling an account terminates its already-active session immediately
(verified: the next request 302s to `/auth/login` and an
`auth.session_terminated` row is written to `audit_event`). A password
reset *alone*, without also disabling, does not retroactively invalidate an
already-issued session cookie — the live check covers status/roles/grants,
not `password_hash`; to fully cut off an account right now, disable it.

**Microsoft Entra ID (GCC High) SSO is planned, not implemented.**
`GET /auth/sso` returns a deliberate `503` with an explanatory message
(`AuthController::ssoUnavailable()`) rather than a fake or partial sign-in
flow. The Entra app-registration environment variables
(`ENTRA_TENANT_ID`/`ENTRA_CLIENT_ID`/`ENTRA_CLIENT_SECRET`/`ENTRA_REDIRECT_URI`)
are read by `Config` so the connector framework has a correct place to look
once built, but nothing consumes them yet.

## Authorization

This is the architecturally strongest part of the application — see also
`docs/ARCHITECTURE.md`'s "dual DB-backed authorization scoping" section for
the mechanism, and `app/Support/Authorize.php` for the implementation.

**Three-state model**, resolved in `Authorize::effectivePermissions()`:

1. **Role defaults** — granular `module.action` permission strings a role
   carries by default (`Roles::DEFAULTS`). Five roles exist:
   `enterprise_admin` (wildcard `*`), `security_admin`, `supervisor`,
   `system_owner`, `auditor`.
2. **Explicit grants** — per-user additions stored in
   `user_permission_grant` (`effect = 'grant'`), layered on top of role
   defaults.
3. **Explicit denials** — per-user removals in the same table
   (`effect = 'deny'`). **Denials always win**, regardless of role default
   or explicit grant — enforced by applying denials last in
   `effectivePermissions()`.

**Two additional DB-backed scopes**, enforced inside `Authorize::can()`
*after* the three-state check passes:

- **Reporting-chain scope** (`*.reports`, `matrix.view.supervisor`) — a
  recursive CTE over `person.manager_person_id` confirms the caller is
  actually in the subject's management chain
  (`Authorize::isInReportingChain()`), or is viewing their own record.
  `Authorize::reportsOf()` provides the matching "which people can I see"
  list for list-level views (the Matrix "Supervisor" view).
- **Application-ownership scope** (`*.owned`) — a direct lookup confirms
  the caller is the recorded `application.system_owner_person_id`
  (`Authorize::ownsApplication()`).

**Every protected route calls `Authorize::requirePermission()`** (30 call
sites across the controllers, verified by direct search) before touching
data; a denial is both enforced (`403`, plain-text body) and audited
(`authz.deny.<permission>` in `audit_event`) by the same call —
`Authorize::requirePermission()` does both, so a controller cannot audit
without also enforcing or vice versa. **Views never make their own
authorization decisions** — they call `Authorize::can()` purely to decide
what to *show* (nav links, buttons), and every one of those same
permissions is independently re-checked server-side by the controller
behind the link. UI hiding is cosmetic only, by construction.

**Standing rule for new modules:** a new permission check must call
`Authorize::can()`/`requirePermission()` — never a parallel, module-local
check. The `ApiRouter` REST surface demonstrates this: it reuses the exact
same `Authorize` engine as the HTML routes rather than re-implementing
authorization for the API.

**A related, narrower trust-boundary rule — audit metadata is
server-verified, never client-asserted.** The deterministic
account-matching engine (`Accounts::suggestMatch()`, Unmatched Accounts)
is the example today: a request can ask to record `link_method =
'deterministic'` (the "Accept Suggested Match" button), but
`AccountsController::link()` never takes that claim on faith — it
re-runs `suggestMatch()` itself and only records 'deterministic' if the
server's own fresh computation agrees with the submitted `person_id`,
falling back to 'manual' otherwise. Verified directly: a bypassing API
call that claimed the flag with a mismatched `person_id` was correctly
recorded as 'manual'. The general principle for any future feature:
**authorization** (can this caller do this?) is not the only thing that
must be server-verified — **provenance** (how/why a value in the audit
trail came to be true) must be too, whenever a client could otherwise
claim a stronger label for its own action than it actually earned.

**A third authorization pattern, alongside the three-state model and the
two scoped families above: direct, re-verified ownership — certification
campaign reviews.** `Campaigns::decide()` doesn't add a new scoped
permission family to `Authorize` for "can this caller review this item";
a campaign item's reviewer was already decided once, at campaign-launch
time (`Campaigns::create()`'s `reviewer_strategy` resolution), and is
stored directly on the item (`reviewer_person_id`). Acting on it is then
a simple equality check against the caller's own `person_id` — the same
shape as `ProfileController` checking "is this your own account," not a
new `Authorize::can()` scope. `decide()` re-runs this check itself
(`WHERE id = :id AND reviewer_person_id = :pid`) rather than trusting the
controller to have verified it first, so there is no path to deciding
someone else's review item even if a future caller forgets. Verified
live: the correct reviewer could decide an item; a different person
attempting the same item (same approach used to test any reviewer
mismatch) was rejected with a specific error, not a generic failure.

## Data protection

- **In transit:** TLS is provided by the hosting platform (Render
  terminates TLS; a self-hosted target needs its own reverse proxy with a
  valid certificate) — the application sets HSTS when it detects HTTPS, but
  does not and cannot terminate TLS itself.
- **At rest:** encryption at rest is whatever the managed Postgres provider
  supplies by default (e.g. Render's managed Postgres, RDS, Azure Database
  for PostgreSQL). The application performs **no field-level encryption**
  of its own — there is no column-level encryption for any table, including
  `connector.credential_reference` (which is a *reference string* into a
  secrets manager, never an actual secret, by design — see
  `app/Support/Connectors.php`).
  Logos are `data:` URLs stored as text in Postgres (`app_config`),
  stripped to only `http(s)://` or `data:image/...` by
  `Settings::safeLogoUrl()` — never a file on disk. The CSV import
  connector (`ApplicationsController::syncCsv()`) is the one place a file
  upload exists: PHP's own upload mechanism creates a transient,
  randomly-named temp file for the duration of that one request only; the
  app reads it once into a string and never copies it anywhere
  permanent or web-reachable. The client's original filename and
  self-reported MIME type are never trusted for anything beyond a coarse
  `.csv` extension check — see `docs/CONNECTOR_DEVELOPMENT_GUIDE.md` §4
  for the full file-upload-trust checklist this follows.
- **Output encoding:** every piece of user/content data rendered into HTML
  is escaped through `Security::h()` (`htmlspecialchars` with
  `ENT_QUOTES | ENT_SUBSTITUTE`); every value embedded into a `<script>`
  context goes through `Security::jsonForScript()`
  (`JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`).
- **CSP / no-inline-handlers:** a strict, per-request-nonce CSP
  (`script-src 'self' 'nonce-…'`, `style-src 'self' 'nonce-…' 
  https://fonts.googleapis.com`, no `unsafe-inline` anywhere) is set on
  every HTML response. There are **zero inline event handler attributes**
  (`onclick=`, etc.) anywhere in `app/Views` — `public/assets/confirm.js`
  handles every `data-confirm="…"` button via one delegated listener. There
  are also **zero inline `style=""` attributes** anywhere in `app/Views` —
  a CSP nonce covers `<style>` elements and `<link>` stylesheets but **not**
  the `style=""` HTML attribute, a real gap that was found and fixed during
  this build. One-off styling lives in small utility classes in
  `public/assets/app.css`; the one page that needs a live-updated custom
  property (Settings → Branding accent-color preview) rewrites the
  `textContent` of a nonce'd `<style id="livePreviewStyle">` element instead
  of mutating `element.style`.
- **CSRF:** every state-changing POST carries a `_csrf` token
  (`Security::csrfField()` / the JSON-body equivalent for AJAX saves), and
  every corresponding controller method validates it
  (`Security::validateCsrf()`) with a constant-time comparison
  (`hash_equals`) before acting. The IAM save endpoint
  (`IamController::save()`) **rotates** the CSRF token on success and
  returns the new one in the JSON response (`{"ok":true,...,"csrf":"…"}"`)
  so the client can keep using the page without a stale token.
- **SQL injection:** `Db` (`app/Support/Db.php`) is parameterized-query-only
  via PDO prepared statements with `PDO::ATTR_EMULATE_PREPARES` disabled.
  Table/column identifiers used in generated SQL (`insert()`, `update()`,
  `delete()`) are validated against an allowlist regex
  (`^[A-Za-z_][A-Za-z0-9_]*$`) before being quoted — they are never
  user-derived in practice, but the allowlist is defense in depth regardless.

## Auditability

`audit_event` (`Audit`, `app/Support/Audit.php`) is an append-only table:
actor, action, target, before/after JSON, justification, correlation id
(stable per request so related rows can be grouped), IP, and a result of
`success` / `denied` / `failure`. Every authentication event, every
administrative change (IAM grants, connector/application edits, dynamic
field lifecycle, settings/branding saves, account correlation link/unlink),
every CSV export, and every authorization denial writes a row.

**No code path in this application ever issues `UPDATE` or `DELETE` against
`audit_event`** — corrections are additional rows, never mutations. That is
enforced today only by convention (the application code simply doesn't do
it); database-level enforcement is available as a ready-to-run script,
**`database/restrict_audit_event_grants.sql`**:

```bash
psql "$DATABASE_URL" -v app_role=your_runtime_role_name \
  -f database/restrict_audit_event_grants.sql
# add -v app_schema=verity if using DB_SCHEMA isolation (default: public)
```

This revokes every privilege the named role holds on `audit_event` and
grants back exactly `INSERT`/`SELECT` — idempotent, safe to re-run, and
verified directly against a real PostgreSQL 16 instance before being
written (including the DB_SCHEMA-isolated-schema case, the default-schema
case, and the missing-argument case, which fails with a clear message
instead of a confusing SQL error).

**Read the limitation in the script's own header before relying on it as
your only control.** It was verified that `REVOKE` genuinely restricts a
table's *owner* role in PostgreSQL (ownership does not exempt a role from
explicit DML privilege checks — this was tested, not assumed), so this
works even in this app's common single-role deployment shape. But it was
equally verified that an owner role retains the inherent, ownership-derived
authority to `GRANT` itself the privilege back, with no superuser needed —
so this script is real protection against an *accidental* `UPDATE`/`DELETE`
from a future application bug, but **not** defense-in-depth against a
fully arbitrary-SQL-execution compromise (e.g. a hypothetical future
SQL-injection bug), since that class of attacker could simply re-run
`GRANT` first. Genuine defense-in-depth against that threat model requires
the application's runtime role to not own `audit_event` (or any table) —
a larger change (a second, non-owner role; splitting which connection
string handles schema/seed operations vs. ordinary request traffic) that
this script deliberately does not attempt on your behalf, since it depends
on deployment-specific decisions (role-naming conventions, who manages
credential rotation) that belong to whoever owns this database's role
layout, not to an assumption baked into a script.
This is an infrastructure/DBA-level configuration step, not something
`schema.sql` itself can express or enforce — see the callout at the bottom
of `database/schema.sql`.

## Classification & DLP

`application.classification` (`public` / `internal` / `confidential` /
`restricted`) exists as **metadata only**. It is recorded per application
in the Application Catalog and displayed, but nothing in the application
enforces it — there is no access restriction, no redaction, and no data-loss
-prevention engine keyed off this field. Treat it as informational until a
policy engine is built against it.

## FIPS readiness

**Not evaluated.** This application has not been assessed for FIPS 140
compliance of its cryptographic operations (PHP's `password_hash`/
`password_verify`, TLS as provided by the hosting platform, `random_bytes`
for CSRF/nonce generation). `Config`'s GCC-High-flavored endpoint defaults
(`graph.microsoft.us`, `login.microsoftonline.us`, `portal.azure.us`) are a
convention — pointing at the correct government-cloud hosts if those
integrations are ever built — not evidence that this app's own crypto has
been validated against FIPS. Do not represent this app as FIPS-ready without
an actual assessment.

## Operator responsibilities

Summarized from the sections above — the operator (not the application) is
responsible for: TLS termination and certificate management; database
encryption-at-rest configuration; the `audit_event` INSERT/SELECT-only role
grant described above; scheduling and verifying backups
(`docs/DISASTER_RECOVERY.md`); rotating the secrets described next; and
disabling/rotating the seeded demo accounts (`docs/DEPLOYMENT.md`) before
any non-demo use.

## Secrets rotation

- **`DATABASE_URL`** — rotation is an infrastructure-level action: rotate
  the database credential at the Postgres/provider level, then update the
  environment variable (or secret-manager reference) the deployment target
  injects, and redeploy/restart so the new process picks it up. The
  application itself has no role in this beyond reading whatever
  `DATABASE_URL` it's given at process start — there is no live credential
  reload.
- **`connector.credential_reference`** — this column is designed from the
  start to hold a *reference string* into a secrets manager, never an
  actual secret value (see `app/Support/Connectors.php`). No connector
  execution engine exists yet to consume it, so there is nothing to rotate
  in practice today, but the intended handoff is: rotate the real credential
  in the secrets manager, leave the reference string in Postgres unchanged
  (it points at the secret, not the value).
- **Entra app-registration secret (`ENTRA_CLIENT_SECRET`)** — same handoff
  pattern as `DATABASE_URL`: rotate in Entra, update the environment/secret
  store, redeploy. Currently unused by any implemented sign-in flow.

## Reporting a security issue

Verity is a personal portfolio project, not a commercially supported
product — there is no `security@` mailing address and no bug bounty
program. If you find a security issue, contact the repository owner
directly (see the repository's own contact information) rather than filing
a public issue, to allow time to address it first.
