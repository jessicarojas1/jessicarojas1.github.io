# AEGIS — Security Model

AEGIS is built for security, compliance, audit, and risk teams in regulated
environments. This document describes the controls implemented in the codebase.
It is a living document; report gaps via an issue or to the maintainer.

## Transport & headers

- HTTPS expected in production. On HTTPS the session cookie is `Secure` and uses
  the `__Host-` prefix (forces Secure, `Path=/`, no `Domain`).
- `Security::setSecurityHeaders()` sets a strict **Content-Security-Policy** with
  a **per-request nonce**, plus HSTS, `X-Content-Type-Options`, frame options,
  and referrer policy. `X-Powered-By` and `expose_php` are suppressed.

## Content Security Policy (CSP)

- **No inline scripts** — every `<script>` carries `nonce="<?= Security::nonce() ?>"`.
- **No inline event handlers** — `onclick`, `onchange`, `onsubmit`, `oninput`,
  etc. are forbidden. Interactivity is delegated via `data-*` attributes handled
  in `public/js/app.js` (`data-click`, `data-submit`, `data-show-modal`,
  `data-confirm-click`, `data-toggle-class`, …).

## Output encoding (XSS)

- All user-influenced output is escaped with `Security::h()`.
- JSON embedded in `<script>` contexts is encoded with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

## SQL injection

- PDO prepared statements only, via `Database::query/fetchOne/fetchAll/insert/update`.
- No string concatenation of untrusted input into SQL. Table/column identifiers
  are never taken from request input.

## CSRF

- Every state-changing form includes `Security::csrfField()`.
- Every POST handler validates with `Security::validateCsrf()`.
- Tokens have a configurable lifetime (`config/app.php → csrf_lifetime`); expiry
  surfaces as a `419` response (`views/errors/419.php`).

## Authentication & sessions

- Passwords hashed with PHP `password_hash()` (strongest available algorithm).
- Strong password policy: min length, upper, number, special (`config/app.php`).
- Login brute-force throttling (attempts/window/lockout in `config/app.php`).
- Session: `HttpOnly`, `SameSite=Strict`, strict mode, cookies-only.
- **Server-side session revocation** — `requireAuth()` checks
  `users.sessions_revoked_at`; an admin deactivating an account or rotating
  sessions forces immediate logout of existing sessions.
- **Force password change** and **password expiry** enforcement redirect the
  user to update their password before continuing.

## Authorization (RBAC + object access)

- Granular `module.action` permissions; roles supply defaults, `user_permissions`
  stores explicit overrides. See `PERMISSIONS_MODEL.md`.
- Every protected controller method calls `Auth::requireAuth()` or
  `Auth::requirePermission('module.action')` — **authorization is enforced
  server-side, never by UI hiding alone**.
- Object-level checks (IDOR prevention) must accompany every record fetched by ID.

## API security

- CORS: `Access-Control-Allow-Origin` is echoed **only** when the request
  `Origin` exactly equals `APP_URL`. No wildcard.
- **API keys**: only the **HMAC-SHA256** hash is stored; the plaintext key is
  shown once. Keys support `is_active` (revocation), `expires_at`, scopes
  (`permissions`), and `last_used`. Legacy plain-SHA-256 keys are transparently
  upgraded to HMAC on first use.
- **JWT**: HS256 with the algorithm pinned; tokens **without `exp` are rejected**.

## SSRF protection (`src/Ssrf.php`)

All server-side URL fetches (webhooks, OIDC discovery/token exchange, and any
future logo/import/AIAdvisor fetch) are validated by a single guard:

- Allows only `http`/`https`; rejects embedded credentials and malformed URLs.
- Resolves **both A and AAAA** records and validates **every** address.
- Blocks private (RFC1918), loopback, link-local, **cloud metadata
  (169.254.169.254)**, CGNAT (100.64/10), IPv6 ULA (`fc00::/7`), IPv6 link-local
  (`fe80::/10`), unspecified, multicast, reserved, and **IPv4-mapped IPv6**
  (e.g. `::ffff:169.254.169.254`).
- Returns the validated IP so callers **pin the connection** with
  `CURLOPT_RESOLVE`, defeating **DNS rebinding** (TOCTOU between check and fetch).

Covered by `tests/test_ssrf.php` (17 assertions).

## Uploads

- MIME type validation, extension allowlist, size and count limits.
- Stored under randomized filenames; upload directories are not executable
  (enforced by `.htaccess` / container config).
- Optional malware-scan hook point for integration with an AV scanner.

## Exports

- CSV/spreadsheet exports neutralize formula injection (values beginning with
  `=`, `+`, `-`, `@`, tab, or CR are prefixed/escaped).

## Error handling (no information disclosure)

- `display_errors` is off; errors are logged, not shown.
- The top-level exception handler renders a **generic 500 page** with only a
  correlation ID. Internal messages — SQL, stack traces, filesystem paths, env
  values, network detail — are **never** sent to the user. The full detail is in
  the server log, keyed by `AEGIS_REQUEST_ID`.
- The single exception is `RuntimeException`, used exclusively for startup
  configuration guards (e.g. missing `JWT_SECRET`), whose messages are
  operator-safe by construction.

## Secrets & configuration

- No hardcoded secrets. Required env: `JWT_SECRET` (≥ 32 chars), `DATABASE_URL`,
  `APP_URL`. Startup aborts if `JWT_SECRET` is missing or too short.
- `.env` is never committed; only `.env.example` with placeholders.
- Sensitive settings are encrypted at rest via `Security::encryptSetting()`.

## Audit trail

Tamper-evident SHA-256 hash chain over all sensitive actions — see
`AUDIT_TRAIL.md`. Integrity is verifiable via `scripts/verify_audit_log.php`.

## Reporting a vulnerability

Open a private security advisory or contact the maintainer directly. Please do
not file public issues for undisclosed vulnerabilities.
