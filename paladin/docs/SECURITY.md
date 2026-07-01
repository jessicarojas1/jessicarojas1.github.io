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

## 15. Identity & authentication (summary)

| Mechanism | Detail |
|---|---|
| **Local passwords** | Argon2id (`memory=64 MB, time=4, threads=2`); policy: min 12 chars, upper/number/special (`config/app.php`, `Security::validatePasswordPolicy`). Forced-change flag (`users.force_password_change`). |
| **MFA (TOTP)** | `src/TOTP.php`; secret stored server-side; single-use **recovery codes** hashed in `mfa_recovery_codes`. MFA policy is `off` / `admins` / `all` (`Auth::mfaPolicy`). MFA verification is rate-limited per IP and per pending user. |
| **SAML 2.0** | `src/Saml.php` — XML-DSig assertion verification, encrypted-assertion support, metadata import, NameID/email + role mapping. |
| **OIDC** | `src/Oidc.php` — Authorization Code + **PKCE (S256)**, `state` + `nonce` validation, JWKS RS256 signature verification (`JWT::verifyRS256`), issuer/audience checks. |
| **SCIM 2.0** | `scim/index.php` — Bearer-token auth (`scim_token`, encrypted at rest; constant-time compare); Users create/read/replace/patch/deactivate (soft delete); every change audited (`scim_*`). Gated by `scim_enabled`. |
| **API tokens** | Admin API keys (`api_keys`) and per-user **personal access tokens** (`personal_access_tokens`, `paladin_pat_*`, HMAC-SHA256 hashed, scoped, expiring). |

## 16. Authorization (RBAC + object-level)

- **Global permissions:** granular `module.action` strings (e.g. `document.publish`,
  `approval.approve`), enforced server-side in every controller
  (`Auth::requirePermission`) and gated in views (`Auth::can`). Nine built-in
  roles (`admin`, `pal_admin`, `compliance_admin`, `space_owner`, `contributor`,
  `reviewer`, `approver`, `auditor`, `viewer`) plus admin-defined **custom roles**;
  **explicit per-user grants** (`user_permissions`) override role defaults;
  backward-compatible coarse aliases (`module.read`/`module.write`).
- **Object-level (anti-IDOR):** access is the *conjunction* of authentication +
  global permission + space scope + object check — a global permission is
  necessary but not sufficient. Private-space membership (`SpaceAccess`), per-page
  view/edit restrictions with ancestor inheritance (`PageAccess`), and
  parent-entity re-checks on attachments (`AttachmentController::canAccessParent`).
  See `PERMISSIONS_MODEL.md`.

## 17. Data protection

- **In transit:** TLS terminated at the reverse proxy; **HSTS**
  (`max-age=31536000; includeSubDomains; preload`) set under HTTPS. In-container
  traffic is plain HTTP behind the proxy on a private network.
- **At rest (secrets):** sensitive `settings` (SMTP/S3 credentials, SCIM token)
  are encrypted with **AES-256-GCM** (`sodium_crypto_aead_aes256gcm`), key =
  `SHA-256('paladin_settings_v1:' + JWT_SECRET)`; format `enc:base64(nonce|ct)`
  (`Security::encryptSetting/decryptSetting`). **`JWT_SECRET` is therefore the
  master key** — protect and escrow it.
- **At rest (infrastructure):** enable KMS/CMK encryption on the database and the
  object store; PALADIN adds no plaintext of secrets to logs or error pages.
- **Passwords / tokens:** Argon2id for passwords; HMAC-SHA256 for API-key/PAT
  hashes; only hashes are stored.
- **Key management:** keep `JWT_SECRET` unique per environment in a secret
  manager; rotating it invalidates existing at-rest secret ciphertext and issued
  tokens (re-enter settings + re-issue tokens after rotation — see §20).

## 18. Classification & DLP (CUI / data handling)

- Controlled documents carry a **`classification`** field and a **`doc_type`**
  (policy, procedure, record, evidence, …); use private spaces to compartmentalise
  sensitive material (object-level access, §16).
- **Egress control:** the SSRF guard (`Security::safeOutboundIp`) blocks outbound
  requests to loopback/link-local/private/reserved ranges (incl. the cloud
  metadata endpoint `169.254.169.254`), so webhooks and OIDC/JWKS/AI calls cannot
  be steered at internal targets. Run air-gapped for the strongest DLP posture
  (`../deployments/AIRGAPPED.md`).
- **Export safety:** all CSV exports are neutralised against formula injection
  (`Csv::put`); downloads are served `nosniff` + `Content-Disposition: attachment`.
- **Handling guidance:** for CUI/regulated data, restrict the DB and object store
  to authorized networks, enable at-rest encryption, ship the audit log to
  WORM/SIEM, and set retention to the longest applicable requirement
  (`src/Retention.php`, Admin → Retention).

## 19. FIPS readiness

- Cryptographic primitives are standard, FIPS-approvable algorithms: **AES-256-GCM**
  (at-rest secrets), **SHA-256 / HMAC-SHA256** (audit chain, token/key hashing,
  SigV4), **RSA/SHA-256** (OIDC RS256), Argon2id for password storage (note:
  Argon2 is not FIPS 140-validated — where a validated KDF is mandated, front the
  app with an IdP via SAML/OIDC so password storage is delegated).
- For FIPS operation: run on a **FIPS-validated OpenSSL / libsodium** platform
  build, terminate TLS on FIPS-approved endpoints, and use **FIPS regional
  endpoints** for S3/KMS/STS (e.g. `s3-fips.us-gov-west-1.amazonaws.com`,
  set via `S3_ENDPOINT`). See `../deployments/AWS.md` (GovCloud) and
  `../deployments/AZURE.md` (Azure Government).

## 20. Operator responsibilities

- Provide secrets from a secret manager (never commit `.env`); prefer **IAM roles /
  IRSA / Managed Identity** over static keys.
- Keep `JWT_SECRET` unique, ≥ 64 hex, escrowed; rotate `ADMIN_PASSWORD` after first
  login; remove/disable demo seed users in production.
- Terminate TLS, enable HSTS, set `TRUSTED_PROXY_IPS` to the real proxy.
- Restrict the DB role on `activity_log` to `INSERT`/`SELECT`; ship the log to
  WORM/SIEM; enable DB + object-store encryption at rest and backups (see
  `DISASTER_RECOVERY.md`).
- Configure SSO + MFA policy; review webhook/SSRF allowlists; keep the doc set and
  migrations current.

## 21. Secrets rotation

| Secret | Rotate by | Impact |
|---|---|---|
| `JWT_SECRET` | Generate a new value, update the secret store, redeploy. | Invalidates issued JWTs/PATs **and** at-rest ciphertext — **re-enter** SMTP/S3/SCIM settings and re-issue tokens afterwards. Rotate on suspected compromise or per policy. |
| DB / S3 / SMTP credentials | Rotate in the provider + secret store, then in **Admin → Settings** (re-encrypted at rest). | Brief reconnection; no data change. |
| API keys / PATs | Revoke + reissue in **Admin → API Keys** / user token page (set `expires_at`, `is_active=false`). | Old key rejected immediately. |
| Admin / user passwords | User self-service or admin force-change (`force_password_change`). | Session revocation via `sessions_revoked_at`. |
| SCIM token | Regenerate in Admin → Settings (encrypted). | Update the IdP's SCIM connector. |

## 22. Vulnerability reporting

- **Report privately** — open a private security advisory or email the maintainer
  listed in the repository. **Do not file public issues** for security reports.
- Include affected version/commit, reproduction steps, and impact.
- **Target SLA:** acknowledge within **2 business days**; triage/severity within
  **5 business days**; fix or mitigation for high/critical issues prioritised in the
  next release cycle. Coordinated disclosure is preferred.

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
