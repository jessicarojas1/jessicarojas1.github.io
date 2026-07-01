# APEX — Security Guide

Security model for APEX: identity & authentication, authorization, data
protection, auditability, data classification & DLP, FIPS readiness, operator
responsibilities, secrets rotation, and vulnerability reporting.

> Cross-links: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)

---

## 1. Identity & authentication

APEX simulates a DoD CAC/PIV login flow with three steps: select identity → enter
PIN → verify.

- **CAC/PIV simulation.** A simulated smart-card identity is chosen in the UI.
  This models the CAC/PIV enrollment step; in a real accreditation the front step
  would be replaced by client-certificate / PKI middleware at the reverse proxy.
- **PIN verification.** The 4–8 digit PIN is verified **server-side** against a
  bcrypt hash (`PASSWORD_BCRYPT`, cost 12) stored in `users.pin_hash`. Login
  returns a uniform `Invalid credentials` regardless of whether the identity or
  the PIN was wrong (no user-enumeration oracle).
- **Session token.** On success, an **HS256 JWT** with an **8-hour** expiry is
  issued, signed with `JWT_SECRET`. It is delivered as:
  - the `apex_token` cookie — **HttpOnly**, **SameSite=Lax**, `Secure` when
    `APP_ENV=production`; and/or
  - an `Authorization: Bearer <token>` header (used by the SPA from in-memory
    storage).
- **Fail-closed signing.** In production, `Auth::secret()` throws if `JWT_SECRET`
  is missing or `< 32` chars — the app refuses to sign/verify with a guessable
  key rather than falling back. The dev-only fallback key is never used when
  `APP_ENV=production`.
- **Token verification.** `verifyJWT()` recomputes the HMAC with `hash_equals()`
  (constant-time) and rejects expired tokens. Only HS256 is accepted.
- **Default PINs.** The seed accounts have well-known PINs, accepted **only** when
  `APEX_ALLOW_DEFAULT_PINS=1` and **never** in production. Rotate them at first
  login and keep the flag `0`.

---

## 2. Authorization (RBAC)

Role hierarchy: **`viewer` (1) < `member` (2) < `admin` (3)**, enforced by
`Auth::requireRole()`. `requireRole('member')` admits members and admins.

| Capability | Minimum role |
|------------|--------------|
| Read projects you belong to, tickets, comments, history, notifications | `member` (project membership enforced; admins bypass) |
| Create/update tickets, comments, status transitions, watch | `member` |
| Create/edit projects, manage members, labels, sprints | `admin` |
| Delete tickets, manage users, change branding | `admin` |

Project-scoped reads additionally enforce **membership** (a member of one project
cannot read another project's data; admins bypass the membership gate). Every
mutating route calls `requireAuth()`/`requireRole()`; the public routes are only
`GET /api/health`, `POST /api/auth/login`, and `GET /api/settings/branding` (read
of non-sensitive branding for the pre-auth login screen).

PIN changes: an admin may change any user's PIN; a member may change their own —
enforced in `PATCH /api/users/{id}/pin`.

---

## 3. Data protection

**In transit**
- Apache forces HTTPS via `public/.htaccess` (redirects unless
  `X-Forwarded-Proto: https` or `HTTPS=on`) and sets **HSTS**
  (`max-age=31536000; includeSubDomains; preload`).
- App→DB traffic honors `?sslmode=` in `DATABASE_URL`; use `require` or
  `verify-full` in production.
- The auth cookie is `Secure` in production and always `HttpOnly` + `SameSite=Lax`.

**At rest**
- PINs are never stored in plaintext — only bcrypt hashes.
- `JWT_SECRET` and `DATABASE_URL` live in the platform secret manager, not in the
  image or repo.
- Database encryption at rest is provided by the managed platform KMS (AWS KMS /
  Azure Key Vault / provider-managed keys). APEX writes no files to disk; branding
  logos are `data:` URLs stored inside Postgres, so they inherit DB-at-rest
  encryption.

**Browser hardening** (set in `public/.htaccess`):

| Header | Value |
|--------|-------|
| Content-Security-Policy | `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: blob: https:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'` |
| Strict-Transport-Security | `max-age=31536000; includeSubDomains; preload` |
| X-Frame-Options | `DENY` |
| X-Content-Type-Options | `nosniff` |
| Referrer-Policy | `strict-origin-when-cross-origin` |
| Permissions-Policy | `geolocation=(), microphone=(), camera=(), payment=()` |
| Cross-Origin-Opener-Policy / Resource-Policy | `same-origin` |
| X-Powered-By | unset |

`script-src` is strict `'self'` (only `/app/apex.js`, no inline/third-party JS).
`style-src` allows `'unsafe-inline'` because the SPA shell uses inline `style=`
attributes; there are no inline event handlers (CSP-compliant per repo rules).

**Injection defenses**
- SQL: parameterized queries only, via `Database::query()` with bound params;
  `PDO::ATTR_EMULATE_PREPARES => false`. No string-concatenated user input.
- XSS: JSON responses use `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE`; the
  SPA escapes user output client-side; branding display name is control-char
  stripped and length-capped.
- Branding logo URLs are allowlisted to `http(s)://` or `data:image/…;base64,…`
  only — `javascript:`, `data:text/html`, etc. are rejected to an empty string.

---

## 4. Auditability

- Every ticket mutation writes a row to the **`history`** table: `event`,
  `field`, `from_val`, `to_val`, `user_id`, `timestamp`. Exposed via
  `GET /api/tickets/{id}/history` and `GET /api/projects/{id}/history` (200 most
  recent, project-wide).
- Status changes and comments fan out **notifications** to the assignee + watcher
  set, providing an activity trail per user.
- Uncaught server errors are logged to stderr as `[apex-api] <Class>: <message>`
  and captured by the platform log pipeline. Ship these to a central,
  tamper-evident log store (CloudWatch/Log Analytics/SIEM) for retention and
  alerting.
- **Gap to close for accreditation:** authentication events (login success/fail,
  logout, PIN change) are not yet written to `history`. Add an auth-audit sink and
  centralize logs before an ATO. See [OPEN_ITEMS.md](../OPEN_ITEMS.md).

---

## 5. Classification & DLP (CUI / data handling)

- The data model carries a `users.clearance` attribute (e.g. `UNCLASSIFIED`,
  `SECRET`, `TS/SCI`) and projects/tickets are program artifacts. Treat ticket
  bodies, comments, and descriptions as potentially **CUI**.
- APEX does **not** currently enforce clearance-based access control on ticket
  content — clearance is displayed, not gated. Do not store data above the
  system's accreditation boundary; enforce classification via the enclave's
  network/labeling controls until row-level clearance gating is implemented.
- Mark and handle the deployment at the appropriate impact level (e.g. IL2/IL4/
  IL5) and place it in a correspondingly accredited enclave.
- DLP: since logos are `data:` URLs and there are no file uploads, the main
  egress surface is the JSON API — front it with the enclave's DLP/proxy controls.
- Redact CUI from logs; the app avoids logging request bodies, but centralized log
  scrubbing should be configured at the pipeline.

---

## 6. FIPS readiness

- **Crypto in use:** bcrypt (PIN hashing) and HMAC-SHA-256 (JWT signing).
  HMAC-SHA-256 is FIPS 140-validated when provided by a validated module; bcrypt
  is **not** a FIPS-approved algorithm.
- **To run FIPS-compliant:** deploy on a host/base image with a FIPS-validated
  OpenSSL module (FIPS mode enabled), and terminate TLS on FIPS-validated
  endpoints (ALB/App Gateway/managed TLS; on GovCloud/Azure Gov use the
  FIPS/regional endpoints — see [DEPLOYMENT.md](DEPLOYMENT.md) and the AWS/Azure
  target guides). PostgreSQL and KMS should use FIPS endpoints.
- **PIN hashing under FIPS:** if strict FIPS validation of the password KDF is
  required, migrate PIN hashing from bcrypt to a FIPS-approved KDF (e.g. PBKDF2-
  HMAC-SHA-256). Tracked in [OPEN_ITEMS.md](../OPEN_ITEMS.md).
- Use FIPS-validated KMS/Key Vault for at-rest encryption of the database and
  secrets.

---

## 7. Operator responsibilities

- Set `APP_ENV=production` and a strong `JWT_SECRET` (≥32 random chars); keep
  `APEX_ALLOW_DEFAULT_PINS=0`.
- Rotate all seed PINs immediately after first login; provision real users.
- Terminate TLS and set `X-Forwarded-Proto: https` at the proxy so the HTTPS
  redirect and `Secure` cookie engage.
- Run the DB with a least-privilege (non-superuser) role; keep it off the public
  internet; enforce `sslmode`.
- Enforce runtime hardening: non-root container, read-only rootfs, `/tmp` tmpfs,
  drop-ALL capabilities (the image is built for this).
- Centralize and retain logs; alert on 5xx spikes and repeated auth failures.
- Keep the doc set current (this file, `DEPLOYMENT.md`, `DISASTER_RECOVERY.md`,
  `ARCHITECTURE.md`, `README.md`, `OPEN_ITEMS.md`) as the app changes.
- Rebuild the image to pick up base-image CVE patches (base images are digest-
  pinned; bump the digest on patch).

---

## 8. Secrets rotation

| Secret | Rotate when | Procedure | Impact |
|--------|-------------|-----------|--------|
| `JWT_SECRET` | ≥ every 90 days, or on suspected exposure | Set a new ≥32-char value in the secret store; redeploy | All existing tokens invalidated — users re-login (no data impact) |
| DB credentials / `DATABASE_URL` | ≥ every 90 days, or on exposure | Rotate DB role password (or use rotating managed creds), update the secret, redeploy | Brief connection cycle |
| User PINs | On first login, on compromise, per policy | `PATCH /api/users/{id}/pin` (admin any user; member self) | Affected user re-authenticates |
| TLS certificates | Per CA lifetime / automation | Managed at the LB/ingress | — |

Prefer managed rotating credentials (Secrets Manager rotation / Key Vault) over
manual rotation where the target supports it.

---

## 9. Vulnerability reporting

- Report suspected vulnerabilities privately to the program security POC; do not
  file public issues with exploit details.
- Include: affected version/commit, environment, reproduction steps, and impact.
- Triage SLA (recommended): acknowledge ≤ 3 business days; remediation target by
  severity — Critical ≤ 7 days, High ≤ 30 days, Medium ≤ 90 days.
- For DoD deployments, follow the program's incident-response and DISA/RMF
  reporting requirements in addition to this process.
