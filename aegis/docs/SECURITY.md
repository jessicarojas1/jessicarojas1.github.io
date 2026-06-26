# AEGIS GRC — Security Architecture, Permission & Validation Matrices

> Audience: a brand-new engineering team with zero prior knowledge of AEGIS.
> Everything below is grounded in the actual source. File paths and line numbers
> are cited so you can verify each claim. Where a control is *absent* or only
> *best-effort*, this document says so explicitly.

AEGIS is a server-rendered PHP 8.2 MVC application with **no framework and no
Composer dependencies**. Security primitives are implemented directly in the
`src/` core classes. This document describes the defense-in-depth layers,
authentication, authorization, and the specific guards that protect the app,
then provides two reference tables: a **Permission Matrix** (roles × granular
`module.action` permissions) and a **Validation Matrix** (input → rule → where
enforced).

---

## Table of Contents

1. [Defense-in-Depth Overview](#1-defense-in-depth-overview)
2. [Bootstrap Security & Startup Guards](#2-bootstrap-security--startup-guards)
3. [Authentication](#3-authentication)
4. [Authorization (RBAC)](#4-authorization-rbac)
5. [Session Security & Revocation](#5-session-security--revocation)
6. [CSRF Protection](#6-csrf-protection)
7. [Content Security Policy & Security Headers](#7-content-security-policy--security-headers)
8. [XSS Defenses](#8-xss-defenses)
9. [SQL Injection Posture](#9-sql-injection-posture)
10. [SSRF Guard](#10-ssrf-guard)
11. [File Upload Security](#11-file-upload-security)
12. [Secrets, Encryption & KMS](#12-secrets-encryption--kms)
13. [JWT & API Keys](#13-jwt--api-keys)
14. [Immutable Audit Chain](#14-immutable-audit-chain)
15. [Multi-Tenancy & Row-Level Security](#15-multi-tenancy--row-level-security)
16. [Rate Limiting](#16-rate-limiting)
17. [Permission Matrix](#17-permission-matrix)
18. [Validation Matrix](#18-validation-matrix)
19. [Known Gaps & Notes](#19-known-gaps--notes)

---

## 1. Defense-in-Depth Overview

AEGIS layers independent controls so that the failure of any single layer does
not collapse the whole system. The layers, from the network edge inward:

| Layer | Control | Implemented in |
|-------|---------|----------------|
| Transport | HSTS (1 year, includeSubDomains, preload) when HTTPS detected | `src/Security.php:375-379` |
| Browser | Nonce-based CSP (no `unsafe-inline` for scripts), `X-Frame-Options: DENY`, COOP/CORP, `nosniff`, Permissions-Policy | `src/Security.php:337-380` |
| Session | `__Host-` prefixed, `HttpOnly`, `SameSite=Strict`, `Secure`, strict-mode, cookie-only | `index.php:111-123` |
| Authentication | Argon2id passwords, TOTP MFA + replay defense, backup codes, optional SSO/OIDC | `src/Security.php`, `src/TOTP.php`, `controllers/AuthController.php`, `src/SSO.php` |
| Authorization | RBAC with role defaults + explicit DB grants, granular `module.action` permissions | `src/Auth.php` |
| Request integrity | CSRF tokens (rotate-on-use), open-redirect path allowlist | `src/Security.php:27-43`, `controllers/AuthController.php:63-68` |
| Data access | Parameterized PDO queries; Postgres Row-Level Security per tenant | `src/Database.php` |
| Outbound | Centralized SSRF guard (IPv4 + IPv6, DNS-rebind pinning) | `src/Ssrf.php` |
| Storage | Extension denylist floor + per-call MIME/size/extension allowlists, randomized filenames | `src/Storage.php`, `controllers/EvidenceController.php` |
| Secrets | `*_FILE` secret mounts, AES-256-GCM at rest, optional KMS envelope encryption | `src/Secrets.php`, `src/Kms.php`, `src/Security.php:202-262` |
| Accountability | Tamper-evident HMAC-chained audit log | `src/Auth.php:484-553`, `scripts/verify_audit_log.php` |

---

## 2. Bootstrap Security & Startup Guards

The front controller `index.php` performs security setup **before any routing**:

- **Secret hydration** (`index.php:85-86`): `Secrets::hydrate()` resolves any
  `*_FILE` indirection so secrets come from mounted files rather than the
  process environment.
- **KMS unwrap** (`index.php:91-92`): `Kms::hydrate()` unwraps a wrapped data key
  if a KMS provider is configured (inert by default).
- **Hard fail-closed guards**:
  - `JWT_SECRET` must exist and be **≥ 32 chars** or the app throws
    (`index.php:95-97`).
  - A database must be configured (`index.php:101-103`).
  - In production, `APP_URL` must be set — it is the CORS allow-origin
    (`index.php:107-109`).
- **Session hardening** (`index.php:111-123`): `cookie_httponly=1`,
  `cookie_samesite=Strict`, `use_strict_mode=1`, `use_only_cookies=1`, and over
  HTTPS `cookie_secure=1` plus the **`__Host-AEGIS`** cookie name (the `__Host-`
  prefix forces `Secure`, `Path=/`, and no `Domain`, preventing subdomain cookie
  injection/hijack).
- **Version-disclosure suppression** (`index.php:140-141`): removes
  `X-Powered-By`, disables `expose_php`.
- **Security headers** sent via `Security::setSecurityHeaders()`
  (`index.php:162`).

---

## 3. Authentication

### 3.1 Passwords (Argon2id)

Passwords are hashed with **Argon2id** (`Security::hashPassword`,
`src/Security.php:131-137`) using:

```
memory_cost => 65536 (64 MiB), time_cost => 4, threads => 2
```

Verification is `password_verify` (`src/Security.php:139-141`). The login path
(`Auth::login`, `src/Auth.php:443-477`) lowercases/trims the email, applies two
rate-limit buckets (per-IP and per-email-hash), and on success calls
`session_regenerate_id(true)` to prevent session fixation.

**Password policy** is enforced in two places:

- `Security::validatePassword` (`src/Security.php:143-158`) reads
  `config/app.php` (`min_length=12`, require upper / number / special — all true).
- `Security::validatePasswordPolicy` (`src/Security.php:160-195`) reads
  **live policy from the `settings` table** (`password_min_length`,
  `password_require_uppercase`, `password_require_numbers`,
  `password_require_special`) with the same defaults, so admins can tighten it
  at runtime.

Additional lifecycle controls enforced in `Auth::requireAuth`
(`src/Auth.php:363-428`):

- **Force password change** (`force_password_change` column) redirects all but
  `/profile/edit` until changed.
- **Password expiry** (`password_expiry_days` setting > 0) redirects to
  `/profile/edit` when the password age exceeds the limit.

### 3.2 MFA / TOTP

`src/TOTP.php` is a self-contained RFC-6238 implementation:

- **Secret**: 160-bit (`random_bytes(20)`), Base32-encoded — meets NIST SP
  800-63B minimum (`TOTP.php:8-25`).
- **Algorithm**: SHA-1, 6 digits, 30-second period (`TOTP.php:41-49,51-62`).
- **Verification** (`TOTP::verify`, `TOTP.php:32-39`): rejects anything not
  `^\d{6}$`, then accepts the `-1, 0, +1` window (±30 s clock skew), comparing
  with `hash_equals` (constant-time).

**Login MFA flow** (`controllers/AuthController.php:28-58`):

1. After password success, if the role is in the `mfa_enforcement` setting list
   but the user has no MFA, they are forced to `/mfa/setup` (`:32-41`).
2. If MFA is enabled, the session is **destroyed and rebuilt** as an
   `mfa_pending` half-session (`:42-58`) — the user is not authenticated until
   TOTP passes.

**TOTP replay defense** (`AuthController.php:119-147`): the matched 30-second
window counter is recorded in `totp_used_codes` with a unique
`(user_id, window_counter)` constraint; a reused code in the same window is
rejected. Old rows are purged after 10 minutes. MFA attempts are rate-limited
per user (`:107-110`).

### 3.3 Backup Codes

`controllers/AuthController.php:432-457`:

- 8 codes are generated, each `XXXX-XXXX` from `random_bytes(4)`, **hashed with
  Argon2id** (`password_hash(..., PASSWORD_ARGON2ID)`), and stored in
  `mfa_backup_codes`. Plaintext is shown once via a one-time session value.
- Regenerating invalidates all prior codes (`DELETE ... WHERE user_id`).

**Backup-code login** (`AuthController.php:459-523`): rate-limited per user,
matches with `password_verify` against unused codes (`used_at IS NULL`), marks
the matched code used, then rebuilds the session and regenerates the ID.

### 3.4 SSO / OIDC

`src/SSO.php` implements OIDC with anti-forgery state + nonce:

- `state` and `nonce` are random 16-byte values stored in the session
  (`SSO.php:74-77`).
- The callback verifies `state` with `hash_equals` (`SSO.php:93`).
- The ID token is verified with **`JWT::verifyRS256`** against the provider's
  JWKS, checking `aud`, `iss`, `exp`, and `nonce` (`src/JWT.php:61-107`,
  invoked at `SSO.php:147-152`).
- The JWKS fetch and token endpoint calls are pinned through the SSRF guard
  (`Ssrf::curlResolve(..., true)` — HTTPS required; `SSO.php:50,115`,
  `JWT.php:109-126`).

---

## 4. Authorization (RBAC)

### 4.1 Model

Authorization is centralized in `src/Auth.php`. The permission string format is
**`module.action`** (e.g. `risk.accept`, `policy.publish`, `audit.close`).

- **`admin`** short-circuits everything: `Auth::can()` returns `true`
  immediately for role `admin` (`src/Auth.php:330`), and
  `roleDefaultPermissions('admin')` returns `['*']` (`:171-183`).
- For all other roles, `can()` builds the effective set as:
  **role defaults** (`$roleDefaults`, `:5-138`) **+ explicit DB grants** from
  the `user_permissions` table (`:336-350`), then checks membership.

### 4.2 Role Defaults vs Explicit Grants

- **Role defaults** are the static `module → [actions]` map in
  `$roleDefaults` (`src/Auth.php:5-138`). They are *pure* and require no DB
  lookup (`roleDefaultPermissions`, `:171-183`).
- **Explicit grants** are rows in `user_permissions (user_id, module,
  permission)`, loaded once per request and cached in `$permCache`
  (`:336-347`). They are **additive** — merged on top of role defaults
  (`:350`). There is no per-user *deny* in this code path.

### 4.3 Backward-Compat Aliases

Coarse legacy strings (e.g. `risk.write`, `policy.edit`) are mapped to arrays of
granular permissions via `$aliases` (`src/Auth.php:185-220`). When `can()` is
asked about an alias key, it returns true if **any** aliased granular permission
is granted (`:353-358`). This lets older call sites keep working as the codebase
migrates to granular strings.

### 4.4 Enforcement Helpers

- `Auth::requireAuth()` — must be logged in; also runs session-revocation,
  account-disabled, force-password-change, and password-expiry checks
  (`src/Auth.php:363-428`).
- `Auth::requirePermission('module.action')` — calls `requireAuth()` then
  `can()`, returning a `403` view on failure (`:430-437`).
- `Auth::requireAdmin()` → `requirePermission('admin')` (`:439-441`).
- `Auth::requirePlatformAdmin()` — gated on a dedicated session flag, **not** any
  tenant role (`:281-288`).

### 4.5 Platform Admin & Tenant Switching

Platform-admin power is deliberately **not** derived from role/permissions
(tenant `admin` bypasses `can()`), so it comes from a separate
`is_platform_admin` session flag (`Auth::isPlatformAdmin`, `src/Auth.php:248-250`).
Cross-tenant switching (`switchTenant`, `:296-315`) is **explicit, validated,
audited, and time-boxed** to 1 hour (`TENANT_SWITCH_TTL`, `:241`); it
auto-reverts on expiry (`activeTenantId`, `:262-273`).

---

## 5. Session Security & Revocation

On every authenticated request, `Auth::requireAuth` (`src/Auth.php:363-428`)
enforces:

- **Idle timeout**: `session_lifetime` = 8 hours (`config/app.php:7`); exceeding
  it logs out with `?reason=timeout`.
- **Account deactivation**: if the user row is gone or `is_active = FALSE`,
  force logout with `?reason=account_disabled` (`:380-389`).
- **Server-side session revocation**: if `users.sessions_revoked_at` is newer
  than this session's `login_time`, force logout with `?reason=revoked`
  (`:391-396`) — an admin can kill all of a user's live sessions.
- **Active session tracking**: each request upserts into `active_sessions`
  (`index.php:1190-1202`) for admin session management.

Session cookie attributes are set in bootstrap (see [§2](#2-bootstrap-security--startup-guards)).
Optional shared session storage via `PgSessionHandler` (`SESSION_DRIVER=pg`)
supports horizontal scaling, falling back to file sessions on error
(`index.php:129-136`).

---

## 6. CSRF Protection

Implemented in `src/Security.php`:

- **Token generation** (`generateCsrfToken`, `:19-25`): 32 random bytes,
  stored with a timestamp.
- **Hidden field helper** (`csrfField`, `:40-43`): emitted in every POST form;
  the project rules require this on all POST forms.
- **Validation** (`validateCsrf`, `:27-38`):
  - Expires after `csrf_lifetime` = 2 hours (`config/app.php:8`).
  - Constant-time compare via `hash_equals`.
  - **Rotate-on-use**: on success the token is **unset**, so each token is
    single-use and replay is prevented.

Every state-changing controller method validates the token, e.g. login
(`AuthController.php:14-18`), logout (`:78-80`), MFA verify (`:98-101`), backup
codes (`:434,468`), evidence upload (`EvidenceController.php:26-28`).

---

## 7. Content Security Policy & Security Headers

`Security::setSecurityHeaders` (`src/Security.php:337-380`) emits a
**nonce-based CSP without `script-src 'unsafe-inline'`**. The per-request nonce
is generated once from `random_bytes(18)` and reused across all `<script>` tags
(`Security::nonce`, `:327-335`). Every `<script>` must carry
`nonce="<?= Security::nonce() ?>"`.

The CSP directives (`:349-362`):

```
default-src 'self'
script-src  'self' 'nonce-{N}' https://cdn.jsdelivr.net
style-src   'self' 'unsafe-inline' https://cdn.jsdelivr.net
font-src    'self'
img-src     'self' data: blob: https:
connect-src 'self'
frame-ancestors 'none'
base-uri 'self'
form-action 'self'
object-src 'none'
```

Notes from the source comments:

- `script-src` allows `cdn.jsdelivr.net` only for Bootstrap, which is
  **SRI-pinned** in markup; for air-gapped/IL5+ deployments the comment instructs
  vendoring Bootstrap locally and dropping jsdelivr (`:343-348`).
- `style-src` still allows `'unsafe-inline'` (styles only, not scripts).
- `img-src https:` exists to permit an externally-hosted branding logo URL;
  `data:`/`blob:` cover uploads/inline images (`:354-356`).

Additional headers (`:364-374`):

| Header | Value |
|--------|-------|
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `X-XSS-Protection` | `0` (intentionally disabled per OWASP guidance — rely on CSP) |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `X-Permitted-Cross-Domain-Policies` | `none` |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=(), payment=(), interest-cohort=()` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` (HTTPS only) |

---

## 8. XSS Defenses

- **Output encoding**: `Security::h()` (`src/Security.php:45-47`) wraps
  `htmlspecialchars` with `ENT_QUOTES | ENT_HTML5` and UTF-8. The project rules
  require all user output to flow through `Security::h()`, and `json_encode` in
  script contexts to use `JSON_HEX_TAG | JSON_HEX_AMP`.
- **Plain-input sanitizing**: `Security::sanitizeInput()` (`:49-51`) strips null
  bytes, strips tags, and trims.
- **Rich-HTML sanitizing**: `Security::sanitizeHtml()` (`:58-129`) parses
  submitted HTML with `DOMDocument` and:
  - removes dangerous tags entirely (`script, style, iframe, object, embed,
    applet, form, input, button, select, textarea, link, meta, base`),
  - strips **all** `on*` event-handler attributes **and `data-*` attributes**
    (the latter to prevent stored content from triggering app.js delegation
    actions when an admin views it),
  - allowlists URI schemes on `href/src/action/...` to `http`, `https`,
    `mailto` (plus relative `/`, `#`, `?`), removing anything else (e.g.
    `javascript:`).
- **Defense in depth at storage**: the upload denylist (`src/Storage.php:29-35`)
  blocks `svg`, `svgz`, `html`, `htm`, `xhtml`, `shtml`, `xml`, `xsl`, `swf`,
  etc. — stored-XSS vectors — even if a caller forgets its own check.

---

## 9. SQL Injection Posture

All database access goes through `src/Database.php`, which uses **PDO prepared
statements with emulation disabled**:

- `PDO::ATTR_EMULATE_PREPARES => false` (`Database.php:14`) — real
  server-side parameter binding.
- `query/fetchOne/fetchAll` (`:29-42`) always `prepare()` then `execute($params)`.
- `insert()` and `update()` (`:44-58`) build column lists from **array keys**
  (quoted/escaped via a closure that strips `"`) and bind **all values** as `?`
  placeholders — user values are never concatenated into SQL.
- The tenant GUC is set with `set_config('aegis.tenant_id', ?, false)` — a
  **parameterized** call, explicitly to avoid making the tenant id an injection
  vector (`Database.php:77-82`).

The project rule is "parameterized queries only — no string concatenation of
user input into SQL." Column/table identifiers in dynamic builders come from
trusted code (array keys / fixed table names), not request input.

---

## 10. SSRF Guard

`src/Ssrf.php` is the single source of truth for validating outbound URLs
(webhooks, OIDC discovery/token exchange, branding logo URLs, AIAdvisor, S3
endpoint, URL imports).

**`Ssrf::inspect` / `Ssrf::isSafeUrl`** (`Ssrf.php:36-96`):

- Rejects malformed URLs and schemes other than `http`/`https`; can require
  HTTPS (`requireHttps`).
- Rejects URLs containing credentials (`user:pass@host`) — `:59-62`.
- Resolves **both A (IPv4) and AAAA (IPv6)** records and validates **every**
  result (`resolveAll`, `:114-135`) — closing the IPv4-only `gethostbyname()`
  bypass.
- Blocks (`ipIsBlocked`, `:141-188`): private (`10/8`, `172.16/12`,
  `192.168/16`), loopback (`127/8`, `::1`), link-local + **cloud metadata**
  (`169.254/16` incl. `169.254.169.254`), CGNAT (`100.64/10`), IETF/benchmark/
  multicast/reserved ranges, IPv6 ULA (`fc00::/7`), link-local (`fe80::/10`),
  unspecified (`::`), and **IPv4-mapped IPv6** (re-checks the embedded v4).

**DNS-rebinding (TOCTOU) protection** — `Ssrf::curlResolve` (`:104-112`) returns
a `CURLOPT_RESOLVE` entry pinning `host:port` to the **validated IP**, so the
actual request cannot be rebound to a different address after validation. JWT's
JWKS fetch uses this (`JWT.php:112-121`).

**`Ssrf::isDangerousInfraHost`** (`:199-250`) is a *narrower* guard for
operator-configured infrastructure (SMTP relay, S3/MinIO endpoint): it allows
RFC-1918 private ranges (legitimate internal hosts) but still blocks loopback,
cloud-metadata/link-local, and the unspecified block. `Storage::s3Request` uses
it (`Storage.php:214-216`).

---

## 11. File Upload Security

Two layers cooperate:

**Layer 1 — storage floor** (`src/Storage.php`):

- `DANGEROUS_EXTENSIONS` (`:29-35`) is a hard denylist (php variants, cgi/pl/py/
  rb/sh, exe/dll/so, asp/jsp, htaccess, **svg/svgz**, **html/htm/xhtml/shtml**,
  xml/xsl, swf, …). `Storage::put` refuses these outright regardless of any
  caller allowlist (CWE-434) — `:62-70`.
- Stored filenames are **randomized**: `bin2hex(random_bytes(16))` + extension
  (`:72`), defeating path traversal and overwrite.
- Local files are written with `mkdir 0750`, `chmod 0640` (**not executable**),
  preferring `move_uploaded_file` (which validates HTTP-upload origin) — `:79-92`.

**Layer 2 — per-upload validation** (e.g. `controllers/EvidenceController.php`):

- **IDOR check**: the uploader must have `module.write` for the target entity's
  module (`:41-49`).
- **Size**: `size > upload_max_size_mb` (default 20 MB) rejected (`:18-21,57-60`).
- **Extension allowlist**: from the `upload_allowed_types` setting (default
  `pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,txt,csv,zip`) — `:12-16,64-67`.
- **MIME sniff**: actual content type via `mime_content_type` / `finfo` is
  checked against an allowlist **before** the file is moved (`:69-82`).
- **Integrity**: SHA-256 of the stored file is recorded (`:92`).

CSV/import controllers (`POAMController`, `ImportController`,
`ComplianceController`, `AdminController`) apply the same `finfo` MIME +
extension pattern (see [§18](#18-validation-matrix)).

---

## 12. Secrets, Encryption & KMS

### 12.1 Secret loading (`src/Secrets.php`)

`Secrets::resolve/hydrate` (`:40-72`) implements the `*_FILE` convention: for any
of `JWT_SECRET`, `AUDIT_HMAC_KEY`, `APP_ENCRYPTION_KEY`,
`APP_ENCRYPTION_KEY_CIPHERTEXT`, `VAULT_TOKEN`, `DB_PASS(WORD)`, `SMTP_PASS`, if
`X_FILE` points to a readable file its trimmed contents become `X`. This keeps
secrets out of the process environment (`/proc`, crash dumps, child procs). An
explicit direct value always wins over the file (`:49-52`).

### 12.2 Encryption at rest (`src/Security.php`)

- `encryptSetting` / `decryptSetting` (`:202-229`) use **AES-256-GCM** via
  libsodium (`sodium_crypto_aead_aes256gcm_*`). Ciphertext is prefixed `enc:` +
  base64(nonce ∥ ciphertext) so plaintext legacy values are distinguishable.
- **Key separation** (`_settingsKey`, `:237-248`): a dedicated
  `APP_ENCRYPTION_KEY` is preferred (so rotating `JWT_SECRET` doesn't break
  stored secrets), with a legacy `JWT_SECRET`-derived key as decrypt-only
  fallback.
- `Storage` decrypts the S3 secret key on load (`Storage.php:53-58`).

### 12.3 KMS envelope encryption (`src/Kms.php`)

Inert by default (`KMS_PROVIDER` unset → `null` provider, `:64-73`). When
enabled, `Kms::hydrate` unwraps `APP_ENCRYPTION_KEY_CIPHERTEXT` into an
in-process `APP_ENCRYPTION_KEY` that never touches disk (`:46-89`). Providers:

- **vault** — HashiCorp Vault transit `decrypt` over TLS-verified HTTPS
  (`VaultTransitKmsProvider`, `:98-157`). Vault address is operator config, not
  user input (no SSRF surface).
- **exec** — runs `KMS_DECRYPT_CMD` with the ciphertext on **stdin** (never
  interpolated into the command string), reading plaintext from stdout
  (`ExecKmsProvider`, `:170-209`) — a generic escape hatch for AWS/GCP/Azure KMS
  CLIs.

---

## 13. JWT & API Keys

### 13.1 JWT (`src/JWT.php`)

- **HS256** issuance/verification (`encode/decode`, `:4-32`). `decode`:
  - validates `alg === HS256` **before** verifying — defends against
    `alg:none` attacks (`:18-19`),
  - `hash_equals` signature compare (`:22`),
  - requires and checks `exp` (`:26-27`),
  - rejects future `iat` beyond 60 s clock skew (`:28-29`).
- **RS256** verification (`verifyRS256`, `:61-107`) for OIDC ID tokens: checks
  `alg=RS256`, `aud`, `iss`, `exp`, `nonce`, fetches JWKS (SSRF-pinned), builds a
  PEM from JWK `n`/`e`, and verifies with `openssl_verify`.

### 13.2 API keys (`src/Security.php`)

- `generateApiKey` (`:264-269`): `aegis_` + 32 random bytes; stored as an
  **HMAC-SHA256** of the key (keyed by `JWT_SECRET`), with a 12-char prefix for
  lookup display.
- `validateApiKey` (`:271-289`): looks up by HMAC (and legacy plain SHA-256 for
  back-compat), requires `is_active` and unexpired, and **silently upgrades**
  legacy SHA-256 hashes to HMAC on first use.

---

## 14. Immutable Audit Chain

AEGIS maintains a **tamper-evident, hash-chained** audit log in `activity_log`
(`src/Auth.php:484-553`).

- **Keyed hashing**: `computeLogHash` (`:490-492`) is
  `hash_hmac('sha256', implode('|', $parts), Security::auditKey())`. Using an
  **HMAC keyed** by `AUDIT_HMAC_KEY` (with a `JWT_SECRET`-derived fallback,
  `Security::auditKey`, `src/Security.php:256-262`) means an attacker who can
  *write* the DB but cannot *read* the key cannot forge the chain — unlike a
  plain unkeyed SHA-256.
- **Chaining**: each row hashes `[prev_hash, user_id, action, entity_type,
  entity_id, changes, ip]`; the first row chains from the literal `genesis`
  (`appendAuditLog`, `:506-540`).
- **Serialization**: a Postgres advisory lock
  (`pg_advisory_lock(hashtext('aegis_audit_chain'))`) serializes concurrent
  appends so two requests can't read the same `prev_hash` and fork the chain.
  The lock is **best-effort** (the row is still written if the lock can't be
  taken) and always released in `finally` (`:510-539`).
- **Coverage**: user actions (`Auth::log`, `:542-548`), system actions
  (`logSystem`, `:550-553`), and **failed logins** are all chained
  (`Auth::login` logs `login_failed`, `src/Auth.php:452-457`).
- **Verification**: `scripts/verify_audit_log.php` walks the chain in `id` order,
  recomputes each hash, and accepts either the keyed HMAC (current) or legacy
  unkeyed SHA-256 (pre-migration rows). Exit `0` = intact, `1` = tampering
  detected (prints first bad ID), `2` = config error.

---

## 15. Multi-Tenancy & Row-Level Security

Tenancy is enforced at **two levels** so isolation holds even if application code
is bypassed:

1. **Write-path stamping** (PHP, no DB call): `Database::useTenant` sets a
   per-request tenant context (`Database.php:142-149`); `applyTenantStamp`
   (`:158-163`) auto-fills `tenant_id` on inserts into the
   `TENANT_TABLES` set (`:104-131`) when the caller didn't provide one.
2. **Read-path Row-Level Security** (Postgres): `Database::setTenant`
   (`:77-82`) sets the `aegis.tenant_id` session GUC via **parameterized**
   `set_config`. The per-table `tenant_isolation` RLS policies (migration 028)
   filter on this GUC, so reads/writes are isolated **in the database**.

The request lifecycle binds the active tenant after auth (`index.php:1179-1187`):
`useTenant(activeTenantId())` for writes and `setTenant(activeTenantId())` for
RLS reads. The active tenant is the user's home tenant for everyone, except a
platform admin in an unexpired switch ([§4.5](#45-platform-admin--tenant-switching)).
`setTenant` is wrapped in try/catch so a pre-migration DB cannot take down the
request; when the GUC is unset the policies are permissive, keeping single-tenant
deployments inert (rows fall back to the `tenant_id` DEFAULT of 1).

---

## 16. Rate Limiting

`Security::checkRateLimit` (`src/Security.php:291-321`) is a DB-backed sliding
window over the `rate_limits` table, configured in `config/app.php:9-13`:

- `login_attempts = 5` per `window_seconds = 300` (5 min); on exceed, set
  `blocked_until = now + lockout_seconds (900s / 15 min)`.

Applied to:

- **Login**: per-IP (`login_<ip>`) **and** per-email-hash
  (`login_email_<sha256(email)>`) — `Auth::login`, `src/Auth.php:447-448`. Reset
  on success (`:460`).
- **MFA verify**: `mfa_<userId>` (`AuthController.php:107-110`), reset on success
  (`:149`).
- **Backup-code verify**: `mfa_backup_<userId>` (`AuthController.php:470-473`).

Client IP comes from `Security::clientIp` (`src/Security.php:8-18`), which trusts
`X-Real-IP` **only** when the immediate `REMOTE_ADDR` is in `TRUSTED_PROXY_IPS`
(default `127.0.0.1`), preventing IP spoofing of the rate-limit key.

---

## 17. Permission Matrix

Roles × granular `module.action` defaults, taken **verbatim** from
`Auth::$roleDefaults` (`src/Auth.php:5-138`). Legend per cell:

- **A** = full module (admin; `can()` always true — `src/Auth.php:330`).
- A list = the exact default actions for that role/module.
- `view` = read-only; blank/`—` = no default (role gets nothing for that module
  unless an explicit `user_permissions` grant is added).

The eight assignable roles are defined in `Auth::ROLES` (`src/Auth.php:144-153`):
`admin, manager, auditor, control_owner, risk_owner, analyst, executive, viewer`.

| Module | admin | manager | analyst | auditor | control_owner | risk_owner | executive | viewer |
|--------|:-----:|---------|---------|---------|---------------|------------|-----------|--------|
| **risk** | A | view, create, edit, delete, accept, review, treatment, scenarios, bowtie, export | view, create, edit, review, treatment, scenarios, bowtie | view | view, treatment | view, create, edit, accept, review, treatment, scenarios, bowtie, export | view, export | view |
| **compliance** | A | view, create, assess, import, test, gap | view, assess, test, gap | view, test, gap | view, assess, test | view | view | view |
| **audit** | A | view, create, edit, findings, close | view, findings | view, create, edit, findings, close | view, findings | view | view | view |
| **policy** | A | view, create, edit, publish, attest | view, attest | view | view, attest | view | view | view, attest |
| **incident** | A | view, create, edit, close, playbook | view, create, edit | view | view | view, create | view | view |
| **vendor** | A | view, create, edit, assess, contracts, questionnaire | view, assess | view, assess | view | view | view | view |
| **issue** | A | view, create, edit | view, create, edit | view, create, edit | view, create, edit | view, create, edit | view | view |
| **change** | A | view, create, edit, approve | view, create | view | — | — | view | view |
| **threat** | A | view, create, edit | view, create | view | — | — | view | view |
| **awareness** | A | view, manage | view | view | — | — | view | view |
| **asset** | A | view, create, edit | view, create | view | view, create, edit | view | view | view |
| **kri** | A | view, manage, record | view, record | view | view, record | view, manage, record | view | view |
| **bcp** | A | view, edit, exercise | view | view | — | — | view | view |
| **ssp** | A | view, edit | view | view | view, edit | — | view | view |
| **report** | A | view | view | view | view | view | view | view |
| **automation** | A | view, manage | view | view | — | — | view | view |
| **approval** | A | view, approve | view | view | view | view, approve | view, approve | view |

> Notes:
> - `control_owner` and `risk_owner` have **no defaults** for several modules
>   (`change`, `threat`, `awareness`, `automation`, and for `risk_owner` also
>   `bcp`/`ssp`) — those cells are `—`. They get access only through explicit
>   `user_permissions` grants.
> - Explicit grants are **additive** on top of these defaults (`can()`,
>   `src/Auth.php:336-360`). There is no per-user deny.
> - Alias keys (`risk.write`, `policy.edit`, …) resolve to the granular actions
>   above via `$aliases` (`src/Auth.php:185-220`).

---

## 18. Validation Matrix

Input → validation rule → where enforced. All citations are to actual code.

| Input | Validation rule | Enforced in |
|-------|-----------------|-------------|
| CSRF token (all POST) | exists, not expired (2 h), `hash_equals`, **single-use rotate** | `Security::validateCsrf` `src/Security.php:27-38` |
| Password (set/change) | ≥ 12 chars; require upper, number, special (config + live `settings`) | `Security::validatePassword` `:143-158`; `validatePasswordPolicy` `:160-195` |
| Login email/password | required, lowercased/trimmed; Argon2id verify; dual rate-limit | `AuthController.php:20-26`; `Auth::login` `src/Auth.php:443-477` |
| Login attempts | 5 / 5 min, then 15 min lockout; per-IP + per-email-hash | `Security::checkRateLimit` `:291-321`; `Auth::login:447-448` |
| TOTP code | `^\d{6}$`; ±1 window; constant-time; **replay-blocked** per window | `TOTP::verify` `src/TOTP.php:32-39`; `AuthController.php:119-147` |
| MFA attempts | rate-limited `mfa_<userId>` | `AuthController.php:107-110` |
| Backup code | normalized; `password_verify` (Argon2id) vs unused codes; one-time | `AuthController.php:474-499` |
| Backup-code attempts | rate-limited `mfa_backup_<userId>` | `AuthController.php:470-473` |
| Post-login redirect | must match `^/[A-Za-z0-9/_?=&%.@-]*$`; not `/admin`,`/login`,`/mfa` | `AuthController.php:63-68, 153-157, 504-506` |
| Permission to act | `module.action` membership (role defaults + DB grants) | `Auth::can` / `requirePermission` `src/Auth.php:328-437` |
| Rich HTML body | DOMDocument strip dangerous tags + `on*`/`data-*` attrs; URI-scheme allowlist | `Security::sanitizeHtml` `src/Security.php:58-129` |
| Plain text field | strip null bytes, strip tags, trim | `Security::sanitizeInput` `:49-51` |
| HTML output | `htmlspecialchars` `ENT_QUOTES\|ENT_HTML5` UTF-8 | `Security::h` `:45-47` |
| Outbound URL (webhook/OIDC/logo/import) | scheme http(s), no creds, A+AAAA all public, IP-pinned | `Ssrf::inspect`/`isSafeUrl` `src/Ssrf.php:36-96` |
| Infra endpoint (SMTP/S3) | block loopback/metadata/link-local (allows RFC-1918) | `Ssrf::isDangerousInfraHost` `:199-250`; `Storage.php:214-216` |
| Upload — file type (floor) | extension **not** in `DANGEROUS_EXTENSIONS` (always) | `Storage::isDangerousExtension`/`put` `src/Storage.php:38-70` |
| Upload — extension | in `upload_allowed_types` setting allowlist | `EvidenceController.php:12-16, 64-67` |
| Upload — MIME | `mime_content_type`/`finfo` vs allowlist, before move | `EvidenceController.php:69-82`; `ImportController.php:46-55`; `POAMController.php:192-194` |
| Upload — size | ≤ `upload_max_size_mb` (default 20 MB) | `EvidenceController.php:18-21, 57-60` |
| Upload — target (IDOR) | uploader must have `module.write` for target entity | `EvidenceController.php:35-49` |
| Upload — stored name | randomized `bin2hex(random_bytes(16))`; `chmod 0640` | `Storage.php:72-92`; `EvidenceController.php:84` |
| Tenant id (RLS / switch) | positive integer; parameterized `set_config`; switch validates active tenant | `Database::setTenant` `:77-82`; `Auth::switchTenant` `src/Auth.php:296-315` |
| SQL parameters (all) | PDO prepared, `EMULATE_PREPARES=false`, `?` placeholders | `Database.php:14, 29-58` |
| JWT (HS256) | `alg=HS256` pre-check; `hash_equals`; `exp`; `iat` skew ≤ 60 s | `JWT::decode` `src/JWT.php:11-32` |
| JWT (RS256 / OIDC) | `alg=RS256`; `aud`/`iss`/`exp`/`nonce`; JWKS via SSRF guard | `JWT::verifyRS256` `:61-107` |
| SSO callback | `state` `hash_equals`; nonce echoed in ID token | `SSO.php:93`; `JWT.php:77` |
| API key | HMAC-SHA256 lookup; `is_active`; unexpired | `Security::validateApiKey` `src/Security.php:271-289` |
| Role assignment | must be in `Auth::ROLES` | `Auth::isValidRole` `src/Auth.php:162-165` |
| Audit log row | HMAC-SHA256 chained on `[prev,user,action,type,id,changes,ip]` | `Auth::computeLogHash`/`appendAuditLog` `:490-540` |
| Client IP (rate-limit key) | trust `X-Real-IP` only from `TRUSTED_PROXY_IPS` | `Security::clientIp` `src/Security.php:8-18` |

---

## 19. Known Gaps & Notes

These are **observations from the code**, not invented behaviors:

- **`style-src 'unsafe-inline'`** remains allowed in the CSP
  (`src/Security.php:351`). This applies to styles only; scripts are
  nonce-gated. Inline styles can still be injected if other layers fail.
- **`img-src https:`** is broad (any HTTPS host) to support branding logo URLs
  (`:356`). This is an intentional trade-off documented in-code.
- **CDN dependency**: `script-src`/`style-src` allow `cdn.jsdelivr.net`
  (Bootstrap, SRI-pinned). For air-gapped/IL5+ the code comments instruct
  vendoring locally and removing jsdelivr (`:343-348`).
- **Audit advisory lock is best-effort**: if `pg_advisory_lock` is unavailable,
  the row is still written (`src/Auth.php:510-517`), so under that failure mode
  concurrent appends *could* race the chain. Verification would surface the
  resulting break.
- **Audit verifier accepts legacy unkeyed SHA-256** for pre-migration rows
  (`scripts/verify_audit_log.php:78-85`); only new rows require the HMAC key to
  forge.
- **Authorization is additive only** — there is no per-user *deny* override of a
  role default in `Auth::can()` (`src/Auth.php:336-360`).
- **API-key & JWT signing fall back to `JWT_SECRET`-derived keys** when dedicated
  keys (`APP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`) are unset; for true key
  separation set those dedicated env vars (`src/Security.php:237-262`).
- **`Auth::log` uses `$_SERVER['REMOTE_ADDR']` directly** (`src/Auth.php:544`)
  rather than `Security::clientIp()`, so behind a proxy the audit IP is the proxy
  IP unless your nginx writes `REMOTE_ADDR` accordingly. Rate limiting *does* use
  `clientIp()`.

---

*Every statement above is grounded in source files under
`/home/user/jessicarojas1.github.io/aegis`. Re-verify against the cited line
numbers when the code changes.*
