# PALADIN — Security Model

This document describes the security controls implemented in PALADIN and how they
map to the requirements of regulated environments (ISO 27001, SOC 2, CMMC/CUI,
FDA 21 CFR Part 11). It reflects the controls present in the codebase; file
references are given so each claim can be verified.

> Report a vulnerability: open a private advisory or email the maintainer listed
> in the repository. Do not file public issues for security reports.

---

## 1. Transport & HTTP headers

Set centrally for every response in `Security::setSecurityHeaders()`
(`src/Security.php`):

| Header | Value |
|--------|-------|
| `Content-Security-Policy` | `default-src 'self'`; **per-request script nonce** (no `'unsafe-inline'` for scripts); `frame-ancestors 'none'`; `base-uri 'self'`; `form-action 'self'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` (when HTTPS) |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Cross-Origin-Opener-Policy` / `Cross-Origin-Resource-Policy` | `same-origin` |
| `Permissions-Policy` | geolocation/microphone/camera/payment/interest-cohort disabled |

## 2. Content Security Policy & XSS

- Every `<script>` carries `nonce="<?= Security::nonce() ?>"`; the nonce is
  generated once per request. There are **no inline event handlers** in views —
  behaviour is wired through `data-*` attributes handled by `public/js/app.js`.
- All user output is escaped with `Security::h()` (`htmlspecialchars`,
  `ENT_QUOTES | ENT_HTML5`).
- Rich text (page/document bodies, comments) is sanitised server-side with a
  DOM-based allowlist in `Security::sanitizeHtml()` — dangerous tags
  (`script`, `iframe`, `object`, `form`, …) are removed, `on*` handler
  attributes are stripped, and URL attributes are restricted to
  `http`/`https`/`mailto`/relative.
- JSON embedded in script contexts uses `JSON_HEX_TAG | JSON_HEX_AMP`.

## 3. CSRF

- `Security::csrfField()` injects a per-session token into every state-changing
  form; `Security::validateCsrf()` checks it on every POST handler and **rotates
  the token after each successful validation** (anti-replay) with a lifetime
  bound.

## 4. SQL injection

- All database access goes through `Database` (PDO) with **parameterised
  queries**. No untrusted input is concatenated into SQL.

## 5. Authentication & sessions

- Passwords hashed with **Argon2id** (`Security::hashPassword`,
  `memory_cost=64MB, time_cost=4, threads=2`).
- Session cookies: `HttpOnly`, `SameSite=Strict`, `use_strict_mode`,
  `__Host-` prefix + `Secure` under HTTPS (`index.php`).
- **Session ID is regenerated** on login and on privilege change
  (`session_regenerate_id(true)` in `src/Auth.php`).
- **Brute-force protection** via `Security::checkRateLimit()` (DB-backed,
  windowed, with lockout):
  - login — per IP and per email;
  - **MFA verification** — per IP and per pending user (TOTP is only 6 digits);
  - password reset.
- **MFA**: TOTP (`src/TOTP.php`) with hashed, single-use **recovery codes**
  (`mfa_recovery_codes`). MFA secret stored server-side; recovery codes stored
  hashed.

## 6. Enterprise identity (SSO / provisioning)

- **SAML 2.0** (`src/Saml.php`): signed assertions (XML-DSig verification),
  encrypted-assertion support, metadata import, NameID/email + role mapping.
- **OIDC** (`src/Oidc.php`): Authorization Code + **PKCE (S256)**, `state` and
  `nonce` validation, JWKS signature verification, issuer/audience checks.
- **SCIM 2.0** (`scim/`): user create/update/deactivate, group sync, idempotent
  provisioning; provisioning changes are audited.

## 7. Authorization

- Granular `module.action` permission strings enforced **server-side** in every
  controller via `Auth::requirePermission()` / `Auth::can()` — never trusting
  client-side checks. See `PERMISSIONS_MODEL.md`.
- **Object-level access (anti-IDOR)** layered on top of global permissions:
  - private-space membership (`SpaceAccess::canView`);
  - per-page view/edit restrictions with ancestor **inheritance**
    (`PageAccess`);
  - attachment download/delete re-checks the parent entity's access
    (`AttachmentController::canAccessParent`).

## 8. Electronic signatures (21 CFR Part 11)

When `require_esignature` is enabled, an approve/reject decision requires the
signer to **type their full name exactly** *and* **re-authenticate with their
password** at the moment of signing (`ApprovalController::decide`). The immutable
record binds **signer identity, decision, meaning statement, timestamp, IP and
user-agent** into a SHA-256 `signature_hash`, and writes a dedicated
`esignature` entry to the tamper-evident audit trail. Failed re-authentication is
rejected and audited (`esignature_failed`).

## 9. File uploads & storage

- MIME-type validation + extension allowlist, randomised stored filenames, file
  hashing (`src/Upload.php`, `src/Storage.php`).
- Download responses send `X-Content-Type-Options: nosniff` and
  `Content-Disposition: attachment`.
- Object-level access enforced on every attachment fetch (§7).

## 10. Outbound requests (SSRF)

- `Security::safeOutboundIp()` validates any outbound URL: http(s) only, resolves
  the host (A/AAAA), and rejects unless **every** resolved address is public —
  blocking loopback, link-local (incl. cloud metadata `169.254.169.254`),
  private and reserved ranges (IPv4 + IPv6).
- `Webhook::deliver()` calls the guard before connecting, **pins the validated IP**
  via `CURLOPT_RESOLVE` (defeats DNS-rebinding), restricts protocols to HTTP(S),
  disables redirects, verifies TLS, and applies short timeouts. Payloads are
  signed with `X-Paladin-Signature: sha256=…` (HMAC) when a secret is set.

## 11. Exports

- **CSV / formula injection** is neutralised centrally by `Csv::put()` — cells
  beginning with `= + - @` or a leading tab/CR are prefixed with `'` while
  genuine numbers are preserved. All register/report/audit CSV exports use it.

## 12. Open redirects

- Redirect targets (e.g. post-action `Referer`, post-login `redirect`) are
  validated against strict same-origin path regexes before use; host comparison
  ignores the port (`DocumentController::safeReferer`, login redirect checks).

## 13. Error handling

- A global exception handler logs the detail server-side and renders a generic
  configuration-error page — **no stack traces, SQL, paths, secrets or
  environment data are leaked** to users (`index.php`).

## 14. Audit trail

- All authentication and administrative/regulated actions are recorded in a
  **hash-chained, tamper-evident** `activity_log`. See `AUDIT_TRAIL.md`.

---

### Verification quick-reference

| Control | Code |
|---------|------|
| Headers / CSP / nonce | `src/Security.php` |
| HTML sanitiser | `Security::sanitizeHtml` |
| CSRF | `Security::validateCsrf` |
| Argon2id / rate limit | `Security::hashPassword`, `Security::checkRateLimit` |
| Session regen | `src/Auth.php` |
| SAML / OIDC / SCIM | `src/Saml.php`, `src/Oidc.php`, `scim/` |
| Object-level access | `PageAccess.php`, `SpaceAccess.php`, `AttachmentController.php` |
| E-signature re-auth | `ApprovalController::decide` |
| SSRF guard | `Security::safeOutboundIp`, `Webhook::deliver` |
| CSV injection guard | `src/Csv.php` |
| Audit chain | `Auth::log` |
