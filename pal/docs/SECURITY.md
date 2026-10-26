# PAL — Security Guide

The security model of PAL as implemented in Phase 1, the controls planned for later phases, and what operators must do to keep a deployment secure.

> **Target.** OWASP ASVS **Level 2** ([Proposal §15](PHASE0_ARCHITECTURE_PROPOSAL.md#15-threat-model)). A requirement-by-requirement ASVS checklist with evidence is a Phase 7 exit artifact; it does not exist in the repository yet. Nothing in this guide is a claim that PAL is ASVS L2 compliant today.

Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [Threat model (Proposal §15)](PHASE0_ARCHITECTURE_PROPOSAL.md#15-threat-model)

---

## Contents

1. [Security overview](#1-security-overview)
2. [Identity and authentication](#2-identity-and-authentication)
3. [Sessions and request protection](#3-sessions-and-request-protection)
4. [Authorization](#4-authorization)
5. [Data protection](#5-data-protection)
6. [Auditability](#6-auditability)
7. [Classification and DLP](#7-classification-and-dlp)
8. [FIPS readiness](#8-fips-readiness)
9. [Secrets rotation](#9-secrets-rotation)
10. [Operator responsibilities](#10-operator-responsibilities)
11. [Known gaps and residual risks](#11-known-gaps-and-residual-risks)
12. [Reporting a vulnerability](#12-reporting-a-vulnerability)

---

## 1. Security overview

| Area | Implemented in Phase 1 | Planned |
|---|---|---|
| Authentication | Entra ID OIDC, authorization code + PKCE, BFF session | Re-authentication before approvals (D9) — Phase 4 |
| Sessions | Server-side in PostgreSQL, idle + absolute limits, `__Host-` cookie | — |
| CSRF | `X-CSRF-Token` header on every state-changing `/api` call + `SameSite=Lax` | — |
| Authorization | 5 global roles from directory groups, 21 granular permissions, voter, self-grant block on role mappings | Category/document-scoped grants, SQL-level filtering, 404-not-403 for hidden drafts — Phase 2+ |
| Audit | Append-only, hash-chained, same-transaction audit; DB role separation; triggers; daily verification; file anchoring; SIEM copy | Audit export, `/admin/audit/verify` UI — Phase 6 |
| Input handling | Strict JSON decoding, field validation, branding sanitizer | Upload pipeline (type detection, ClamAV, quarantine), editor JSON schema validation — Phases 2–3 |
| Transport / headers | HSTS (when proxied over HTTPS), strict CSPs, frame denial, nosniff | — |
| Rate limiting | `/auth/login` and `/auth/callback` 20/min per client IP; `/api` 600/min per user or IP (per replica) | Upload/conversion limits — Phase 2+ |
| Supply chain | Lockfiles, `composer audit`, `npm audit`, Trivy image scan, gitleaks, SHA-pinned GitHub Actions | SBOM (CycloneDX), Renovate/Dependabot ([Proposal T12](PHASE0_ARCHITECTURE_PROPOSAL.md#15-threat-model)) |

## 2. Identity and authentication

PAL signs users in with **Microsoft Entra ID** over OpenID Connect and uses Entra for nothing else except read-only Graph group sync (owner decision D18).

### 2.1 Sign-in flow (`Identity/Oidc`, `Identity/Security`)

1. `GET /auth/login?returnTo=/path` is rate-limited (`auth_login`: sliding window, 20 per minute per client IP; exceeded → redirect `/sign-in?auth_error=too_many_attempts`).
2. PAL creates a pending authorization with a random `state`, `nonce` and PKCE `code_verifier` (32 random bytes each, base64url). It is stored in the server-side session, is **single-use**, expires after **10 minutes**, and at most five are kept (multiple tabs).
3. The browser goes to the Entra authorization endpoint with `response_type=code`, `response_mode=query`, `scope=openid profile email`, `code_challenge_method=S256`.
4. `GET /auth/callback` is rate-limited per client IP by the same `auth_login` limiter. The `state` must match a pending entry (consumed on first use); the code (≤ 4096 chars) is exchanged at the token endpoint with the PKCE verifier and PAL's client credential.
5. The ID token is validated by `IdTokenValidator`:
   - signature with keys from the provider's JWKS, **pinned to RS256** (`JWK::parseKeySet(..., 'RS256')`), so `none`/HS256 algorithm confusion is not possible;
   - on an unknown `kid`, the JWKS is re-fetched **once** (key rotation), otherwise rejected;
   - `exp`/`iat` present, lifetime checked with 120 s leeway;
   - `iss` equals `<OIDC_AUTHORITY>/<tenant>/v2.0`;
   - `aud` equals the client ID (with multiple audiences, `azp` must also equal it);
   - `tid` equals the configured tenant;
   - `nonce` equals the pending nonce (constant-time compare);
   - `oid` is a GUID.
6. The user is upserted by the immutable **`(tid, oid)`** pair, never by email or UPN. A user marked inactive is refused (`account_disabled`). `USER_PROVISIONED` and `USER_SIGNED_IN` are audited in the same transaction.
7. Group memberships are refreshed from Graph (best effort: a Graph outage does not block sign-in; the last synced memberships apply).
8. Symfony migrates the session ID (fixation protection) and records the authentication time. The redirect target is re-validated by `ReturnToValidator`.

Failures redirect to `/sign-in?auth_error=<code>` with a fixed vocabulary (`too_many_attempts`, `sign_in_unavailable`, `sign_in_failed`, `sign_in_cancelled`, `sign_in_expired`, `account_disabled`); details go to the application log only. `USER_SIGN_IN_FAILED` is audited (with the client IP) **only when the callback carried a valid pending sign-in state**, i.e. a sign-in this browser actually started. Callbacks with missing, unknown or expired state go to the application log only, so unauthenticated junk requests cannot flood the audit trail.

### 2.2 Provider metadata hardening

- The discovery document and JWKS are fetched over HTTPS with a 10 s timeout and **no redirects**, cached for 24 h.
- Every discovered endpoint (authorization, token, JWKS, end-session) must be `https` **on the configured authority host**; otherwise sign-in fails with `provider_untrusted`.
- `OIDC_AUTHORITY` must be `https`. Tenant and client IDs must be real GUIDs (the all-zero placeholder is rejected).

### 2.3 Client credential

Exactly one of:

- **Certificate (recommended for production):** a signed client assertion (RFC 7523 `private_key_jwt`, RS256, 5-minute lifetime, unique `jti`, `x5t` and `x5t#S256` thumbprints) built from `OIDC_CLIENT_CERT_PATH` and `OIDC_CLIENT_KEY_PATH`. The files are read when an assertion is built.
- **Client secret (development):** `OIDC_CLIENT_SECRET`.

### 2.4 Directory sync (Microsoft Graph)

- Application permissions, **read-only**: `GroupMember.Read.All` (memberships) and `User.Read.All` (`accountEnabled`, to detect disabled accounts). Admin consent is decision D16 (open).
- Only groups that PAL tracks (those referenced by a role mapping) are synced, every 15 minutes and at sign-in for the signing-in user.
- The Graph access token is held **in memory only**, never cached to disk.
- Pagination links (`@odata.nextLink`) are followed only when they stay on the configured `GRAPH_BASE_URL` origin; at most 200 pages; requests use a 15 s timeout and no redirects; 429/503/504 are retried up to twice honoring `Retry-After` (capped at 5 s).
- Users disabled in the directory are deactivated (`USER_DEACTIVATED`) and lose access on their next request.
- **Revocation latency:** a removal or disablement in Entra reaches PAL at the next sync, so access can persist for up to **15 minutes** (the sync interval). A disabled user who is in no tracked group is not seen by the sync at all; their existing session ends at the idle (default 1 h) or absolute (default 12 h) limit. For urgent revocation run `pal:directory:sync` or delete the user's sessions.
- Adding a role mapping syncs the group's members **synchronously** in the request. For a very large group this may exceed the request timeout; the mapping is still saved and the scheduled sync completes the membership.

### 2.5 Mandatory Entra control: assignment required

PAL grants **Reader** to every account that completes sign-in; it has no allowlist of its own. The Entra enterprise application for PAL **must** have **Assignment required = Yes**, with only the intended users or groups assigned. Without it, every account in the tenant, **guest accounts included**, can sign in and read the library. This is an operator control in Entra; PAL cannot detect whether it is set.

### 2.6 Sign-out

`POST /api/session/logout` (CSRF header required; other methods → 405) invalidates the PAL session, audits `USER_SIGNED_OUT`, and returns Entra's `end_session_endpoint` (with `post_logout_redirect_uri = <PAL_BASE_URL>/`) so the SPA can end the Entra session too. Local sign-out succeeds even if Entra is unreachable.

## 3. Sessions and request protection

### 3.1 Sessions

| Property | Value | Source |
|---|---|---|
| Storage | PostgreSQL table `sessions` via Symfony `PdoSessionHandler`, on its own PDO connection, **advisory-lock mode** (`lock_mode: 1`) | `config/services.yaml` |
| Cookie | `HttpOnly`, `SameSite=Lax`, session-only (`cookie_lifetime: 0`) | `config/packages/framework.yaml` |
| Name / Secure | `__Host-pal_session` + `Secure` in the image (`PAL_SESSION_COOKIE_NAME`, `PAL_SESSION_COOKIE_SECURE=1`); `pal_session` / not Secure in local dev | `Dockerfile`, `.env.example` |
| Idle limit | `PAL_SESSION_IDLE_SECONDS` (default 3600): session `gc_maxlifetime`; expired sessions are not read back | `framework.yaml` |
| Absolute limit | `PAL_SESSION_ABSOLUTE_SECONDS` (default 43200): checked on every `/api` request from the recorded auth time; exceeded → session invalidated, 401 `session_expired` | `SessionLifetimeSubscriber` |
| Revocation | Immediate: delete the row (or `TRUNCATE sessions` for everyone) | Server-side storage |
| Fixation | Session ID migrated on successful sign-in | Symfony security default |

Roles are re-resolved on **every request** (`PalUserProvider::refreshUser`), so removing a group mapping or a membership, or deactivating a user, takes effect on the next request after the change reaches the database.

### 3.2 CSRF

Every `/api` request with a method other than GET/HEAD/OPTIONS must include `X-CSRF-Token` with the session's token (ID `pal_api`, returned by `GET /api/me`). The check runs **before the firewall** (`CsrfHeaderSubscriber`, priority 16), so it covers logout too. Failure → 403 `csrf_invalid`. This is defence in depth on top of `SameSite=Lax`; no state changes are exposed on GET.

### 3.3 Rate limiting

| Limiter | Policy | Applied in | Key |
|---|---|---|---|
| `auth_login` | sliding window, 20 / minute | `AuthController::login` and the `/auth/callback` handling | client IP |
| `api` | sliding window, 600 / minute | `ApiRateLimitSubscriber` (all `/api` paths, after the firewall) | `user:<id>` when signed in, else `ip:<client IP>` |

Both limiters are active. Exceeding the API budget returns 429 `too_many_requests` with `Retry-After`. Limiter state uses the Symfony app cache (`cache.app`, the container's tmpfs filesystem), so **limits are per replica and reset on restart**: with N web replicas the effective budget is up to N times the configured limit. Correct client IPs depend on `TRUSTED_PROXIES` (§3.4).

### 3.4 Host and proxy trust

- `TRUSTED_HOSTS` (regex) rejects requests with any other `Host` header, preventing host-header injection into generated URLs.
- `TRUSTED_PROXIES` lists who may set `X-Forwarded-For`, `-Proto`, `-Port` (`X-Forwarded-Host` is deliberately not trusted). In production set the **exact** reverse-proxy IP(s), and configure the proxy to **overwrite** `X-Forwarded-For` rather than append to it. The image default `private_ranges` allows client-IP spoofing from internal networks: any internal host that can reach the container can choose the IP that rate limiting, logs and audit events see ([DEPLOYMENT.md §11.2](DEPLOYMENT.md#112-transport--exposure)).
- A well-formed `X-Request-Id` sent by the client is accepted as the correlation ID (by nginx and Symfony). Correlation IDs are therefore caller-influenced: use them for tracing, not as unique or trusted identifiers. Strip the header at the proxy if that matters.
- The OIDC redirect URI is built from `PAL_BASE_URL`, never from request headers.

### 3.5 Security headers

| Response | Headers |
|---|---|
| Symfony (all `/api`, `/auth`, `/health` responses) | `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Cross-Origin-Opener-Policy: same-origin`, `Cross-Origin-Resource-Policy: same-origin`; `Cache-Control: no-store, private` on `/api` and `/auth` |
| SPA (nginx, `location /`) | `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: https:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `Cache-Control: no-cache` |
| All (nginx) | `Strict-Transport-Security: max-age=31536000; includeSubDomains` **only** when the proxy sends `X-Forwarded-Proto: https`; `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`; `server_tokens off`; dotfiles return 404 |

The SPA CSP allows no inline script at all (no nonces needed). `img-src https:` exists so an administrator can set an external logo URL. ESLint enforces `vue/no-v-html: error`.

## 4. Authorization

### 4.1 Model

- **Global roles** (`GlobalRole`): `SYSTEM_ADMINISTRATOR`, `PAL_ADMINISTRATOR`, `PUBLISHER`, `AUTHOR`, `READER`. Every active user implicitly holds `READER`. Other roles come from **directory group → role mappings** (`global_role_mapping`) for groups the user currently belongs to (open membership, tracked group), plus `SYSTEM_ADMINISTRATOR` for object IDs in `PAL_BOOTSTRAP_ADMIN_OIDS`.
- **Permissions** are granular strings checked with `#[IsGranted(Permission::X)]` and `PermissionVoter`, which grants a permission if any of the user's roles carries it in `RolePermissionMap`. Effective permissions are the union over roles.
- `security.yaml` `access_control` is a coarse gate only: `/auth/login` and `/auth/callback` and `GET /api/branding` are public, every other `/api/` path requires authentication. `/health/*` has no firewall.
- The SPA's route guards and hidden menu items are UX only; the API enforces every check.

### 4.2 Role × permission matrix

Copied from `pal/backend/src/Identity/Authorization/RolePermissionMap.php`. ✓ = the role grants the permission.

| Permission | READER | AUTHOR | PUBLISHER | PAL_ADMINISTRATOR | SYSTEM_ADMINISTRATOR |
|---|:-:|:-:|:-:|:-:|:-:|
| `library.read` | ✓ | | | | |
| `document.create` | | ✓ | | ✓ | |
| `document.change_owner` | | | | ✓ | |
| `document.obsolete` | | | | ✓ | |
| `document.archive` | | | | ✓ | |
| `document.legal_hold` | | | | ✓ | |
| `workflow.reassign` | | | | ✓ | |
| `workflow.cancel` | | | | ✓ | |
| `publication.schedule` | | | ✓ | | |
| `publication.release` | | | ✓ | | |
| `publication.emergency` | | | | ✓ | |
| `admin.types` | | | | ✓ | |
| `admin.numbering` | | | | ✓ | |
| `admin.retention` | | | | ✓ | |
| `admin.workflows` | | | | | ✓ |
| `admin.roles` | | | | | ✓ |
| `admin.directory` | | | | | ✓ |
| `audit.read` | | | | ✓ | ✓ |
| `audit.export` | | | | ✓ | |
| `report.export` | | | | ✓ | |
| `system.config` | | | | | ✓ |

Because `READER` is implicit, every signed-in user also has `library.read`.

**Enforced today:** `system.config` (`PUT /api/admin/branding`) and `admin.roles` (all `/api/admin/role-mappings` endpoints). The other permissions are defined for the document modules and are not yet attached to any endpoint (*Planned — Phases 2–6*). Document-scoped permissions (`revision.edit`, `workflow.decide`, …) and category/document access grants to groups ([ADR-0010](adr/ADR-0010-group-based-access.md)) are *Planned — Phase 2+*.

### 4.3 Separation of duties

- `SYSTEM_ADMINISTRATOR` configures the system and **holds no document authority**: it cannot create, release, publish, obsolete or archive documents by virtue of that role.
- `PAL_ADMINISTRATOR` cannot schedule or release normal publications (`PUBLISHER` does that); it holds only the emergency-publication permission.
- **Self-grant block:** a user cannot map a directory group they currently belong to onto `PAL_ADMINISTRATOR`, `PUBLISHER` or `AUTHOR`; the request is refused with **403 `self_grant_forbidden`**. This stops a System Administrator from giving themselves document authority alone.
- **Residual risk:** a System Administrator can still map a group they are **not** in, and group membership is managed in Entra outside PAL, so two people acting together (or an administrator who can also edit Entra groups) can still obtain document authority. This is detected, not prevented: every change is audited (`ROLE_MAPPING_ADDED` / `ROLE_MAPPING_REMOVED`) and forwarded to Sentinel. A stronger control — a separate permission for document-role mappings, or a second approver — is an open owner decision (**D20**).
- `admin.workflows` sits with `SYSTEM_ADMINISTRATOR`. Once workflow definitions exist (Phase 4), configuring them influences who approves; revisit this assignment with the owner before Phase 4.
- Decision-time SoD rules (author ≠ approver, etc.) are *Planned — Phase 4*.

## 5. Data protection

| Data | Protection |
|---|---|
| Data in transit (browser) | TLS at the operator's reverse proxy; HSTS; `Secure` + `__Host-` session cookie |
| Data in transit (to Entra / Graph) | HTTPS, no redirects, host-pinned endpoints |
| Data in transit (to PostgreSQL) | **Not enforced by PAL.** Add `sslmode=require` (or `verify-full`) to the DSNs when the database is on another host. |
| Data at rest | PAL does not encrypt columns. Use encrypted volumes / storage for PostgreSQL, backups and anchors. |
| Tokens | No OIDC tokens are given to the browser (BFF). The ID token is used only to validate sign-in. Graph tokens stay in worker/web memory. |
| Personal data held | Entra object/tenant IDs, display name, email, UPN, timezone preference, group memberships with validity ranges, client IP in sign-in-failure audit events, actor snapshots in audit events |
| Logs | Structured JSON; tokens, secrets and request bodies are not logged; token-endpoint errors log only error codes and trace IDs; nginx logs the path without the query string. Audit SIEM records contain actor snapshot and subject, not document content. |
| Branding logo | `https://` URL (≤ 2048 chars) or base64 raster data URL (PNG, JPEG, GIF, WebP; ≤ 400 000 chars). `http://` is rejected (the SPA CSP `img-src 'self' data: https:` would block it anyway); SVG is refused (scriptable). An external logo URL makes browsers contact that host; prefer an uploaded data URL or an internal host. |
| Error responses | Generic messages; internals never returned; 500s carry only a correlation ID |

## 6. Auditability

### 6.1 What is recorded

Event types emitted in Phase 1: `USER_PROVISIONED`, `USER_SIGNED_IN`, `USER_SIGN_IN_FAILED`, `USER_SIGNED_OUT`, `USER_PREFERENCES_CHANGED`, `USER_DEACTIVATED`, `DIRECTORY_GROUP_TRACKED`, `DIRECTORY_GROUP_SYNCED`, `ROLE_MAPPING_ADDED`, `ROLE_MAPPING_REMOVED`, `BRANDING_UPDATED`, `AUDIT_CHAIN_VERIFIED`. Each row stores event ID, UTC timestamp, type, actor user ID and snapshot, correlation (request) ID, subject, from/to state, field-level changes and reason.

### 6.2 Protections (layered)

1. **Same transaction.** `AuditLogger::record()` joins the caller's transaction, so a change and its audit row commit or roll back together.
2. **Hash chain.** Each row's `hash` = SHA-256 of the previous hash and a canonical serialization of the row (sorted keys, floats rejected). A transaction-scoped advisory lock serializes writers so the chain is linear. `hash` and `event_id` are unique.
3. **Triggers.** `trg_audit_event_no_update_delete` and `trg_audit_event_no_truncate` raise on any UPDATE, DELETE or TRUNCATE, for every role including the owner (a backstop against mistakes).
4. **Role separation.** The runtime app role has no `UPDATE`, `DELETE` or `TRUNCATE` on `audit_event` (`roles.sql`, re-applied by `migrate` when `PAL_APP_DB_ROLE` is set) and cannot alter tables, so it cannot disable the triggers.
5. **Verification.** `pal:audit:verify` (and the daily 02:15 UTC job) recomputes every hash and detects edits, deletions, insertions and reordering. A failure is logged `critical` and deliberately not appended to the chain.
6. **External anchoring.** With `PAL_AUDIT_ANCHOR_DIR` set, the verified head (`last_event_id`, `head_hash`) is appended to `audit-anchors.jsonl`. A database owner who rewrites rows **and** recomputes all later hashes passes `pal:audit:verify`, but no longer matches the anchors. The anchor's value depends entirely on that directory being write-once storage the DBAs cannot alter.
7. **SIEM copy.** Every audit row is also emitted on the `pal_audit` log channel for Microsoft Sentinel ([ADR-0011](adr/ADR-0011-logging-to-microsoft-sentinel.md)). The log line is written **inside** the database transaction, so if the transaction later rolls back, Sentinel can hold a phantom record with no audit row. The database is authoritative; reconcile by `event_id`.

**Limits, stated openly:** the trail is tamper-*evident*, not tamper-*proof*. A PostgreSQL superuser or the owner role can disable triggers and rewrite rows; detection then relies on the anchors and the SIEM copy. Anchoring is off by default and the default Compose file does not mount an anchor directory. On **Render or any single-role deployment** (the connecting role owns the tables) the trail is tamper-evident but **not tamper-resistant**: the application's own credentials can disable the triggers. Comparing the database with the anchors is a manual step ([DISASTER_RECOVERY.md §4](DISASTER_RECOVERY.md#4-restore-runbook) step 8); `pal:audit:verify` does not read the anchor file.

## 7. Classification and DLP

- **CUI is out of scope.** Per the PAL specification as stated by the project owner, PAL is not intended to store Controlled Unclassified Information. (This exclusion is not yet recorded in the repository's Phase 0 documents; it should be added to the requirements and decision log.) PAL holds organisational process assets — policies, procedures, standards, templates — that support ISO 27001, CMMI and CMMC Level 2 programmes.
- **What that means in practice:**
  - PAL has **no** CUI marking, banner, dissemination-control or export-control features, and none are planned.
  - Documents that are or contain CUI (or classified, export-controlled, or other regulated data) **must not** be uploaded. The organisation must enforce this through policy, author training and review; PAL cannot detect it.
  - Keeping CUI out keeps the PAL hosts outside the CUI assessment boundary. If that changes, PAL would need re-assessment against NIST SP 800-171 controls, including FIPS-validated cryptography (§8), and its hosting, backups and SIEM forwarding would enter scope.
- **DLP:** PAL implements no content inspection, classification labels or DLP. Phase 2 uploads will be scanned for malware (ClamAV), not for sensitive content. If DLP is required, it must be provided by the organisation's existing tooling or added as a new requirement.
- **Public repository:** the code repository contains placeholders only; real tenant IDs, hostnames, organisation structure and document content must never be committed (decision D12).

## 8. FIPS readiness

**PAL does not enable or claim FIPS 140 mode.** Honest status:

| Function | Implementation | FIPS consideration |
|---|---|---|
| ID-token signature verification, client assertion signing | `firebase/php-jwt` → PHP `openssl` extension (RS256 = RSA PKCS#1 v1.5 with SHA-256) | Approved algorithm. Uses whatever OpenSSL the PHP build links. The image uses Alpine's OpenSSL 3 with no FIPS provider configured. |
| Audit hash chain | PHP `hash('sha256', ...)` | Approved algorithm, but PHP's `hash` extension uses its **own** implementation, not OpenSSL, so it would not run inside a validated module even on a FIPS host. |
| Random values (state, nonce, PKCE, UUIDs) | `random_bytes()` → OS CSPRNG | Depends on the kernel/OS configuration. |
| Certificate thumbprint `x5t` | SHA-1 | Used as an identifier header required by Entra, not as a security function; `x5t#S256` is also sent. |
| TLS to users | Operator's reverse proxy | FIPS depends on the proxy's TLS library and configuration. |
| TLS to Entra/Graph | Symfony HttpClient (curl or PHP streams) → OpenSSL | Depends on the OpenSSL build. |
| TLS to PostgreSQL | libpq → OpenSSL | Not enabled by default (§5). |

To pursue FIPS: run on a base image and host whose OpenSSL is a FIPS-validated module operating in FIPS mode (for example a RHEL/UBI-based PHP image on a host in FIPS mode), terminate TLS on a FIPS-validated proxy, enable database TLS, and decide whether the PHP-native SHA-256 used for the audit chain must move to `openssl_digest()`. None of this has been built or tested.

## 9. Secrets rotation

| Secret | Procedure | Effect |
|---|---|---|
| `APP_SECRET` | Generate a new value (`openssl rand -hex 32`), update it in the secret store, restart **all** web and worker containers together. | Based on code inspection (not tested): Phase 1 does not derive sessions or CSRF tokens from `APP_SECRET`. Sessions are server-side rows and CSRF tokens are random values stored in the session, so rotating `APP_SECRET` alone does **not** sign users out. To force everyone to sign in again after a suspected compromise, also run `TRUNCATE sessions` (see below). Re-check this when features that sign data with the kernel secret (signed URLs, remember-me, login links) are added. |
| Sessions (emergency revocation) | `psql "$ADMIN_URL" -c "TRUNCATE sessions"` | All users must sign in again; CSRF tokens are discarded with the sessions. |
| Entra client certificate | 1) Create the new certificate and upload its public part to the app registration (Entra allows several at once). 2) Replace the files at `OIDC_CLIENT_CERT_PATH` / `OIDC_CLIENT_KEY_PATH` (recreate the containers if they come from Docker secrets). 3) Verify sign-in and `pal:directory:sync`. 4) Remove the old certificate from Entra. | No downtime if done in this order. Files are read each time an assertion is built. |
| Entra client secret (dev) | Add a new secret in Entra, update `OIDC_CLIENT_SECRET`, restart web and worker, delete the old secret. | Brief restart. |
| DB app role password | `ALTER ROLE pal_app PASSWORD '...'` (use `\password pal_app` to keep it out of history), update `DATABASE_URL`, restart web and worker. | Brief interruption: PostgreSQL holds one password per role. Do it in a maintenance window, or create a second login role with the same grants and switch to it. |
| DB owner role password | `\password pal_owner`, update `DATABASE_URL_MIGRATIONS`. | No runtime impact; only `migrate` uses it. |
| Bundled-db secret files | `deploy/secrets/db_*_password.txt` are applied **only on first initialisation**. Changing a file later does not change the password; run `ALTER ROLE` as above. | — |
| Graph access token | Not stored; obtained per process and renewed before expiry. | — |
| Handover | Before moving PAL to the private repository, rotate every credential ever used in development ([Proposal §18.3](PHASE0_ARCHITECTURE_PROPOSAL.md#183-handover-to-the-private-company-repo-d12)). | — |

## 10. Operator responsibilities

PAL's controls assume the operator:

1. Terminates TLS correctly, forwards `X-Forwarded-Proto`, and keeps port 8080 reachable only by the proxy.
2. Sets `TRUSTED_HOSTS` and a narrow `TRUSTED_PROXIES`.
3. Runs web/worker with the **app** DB role, keeps the owner role credentials only in the migrate job, sets `PAL_APP_DB_ROLE`, and re-runs `roles.sql` after every migration.
4. Sets **Assignment required = Yes** on the Entra enterprise application, uses a certificate credential, consents only the read-only Graph permissions, and removes `PAL_BOOTSTRAP_ADMIN_OIDS` after setup.
5. Mounts `PAL_AUDIT_ANCHOR_DIR` on write-once storage owned by a different team, and periodically compares anchors with the database.
6. Forwards logs to Microsoft Sentinel and alerts on `Audit chain verification FAILED`, bursts of `USER_SIGN_IN_FAILED`, 429 spikes, and `ROLE_MAPPING_ADDED` for privileged roles.
7. Reviews `SYSTEM_ADMINISTRATOR` and `PAL_ADMINISTRATOR` mappings regularly (at least quarterly).
8. Patches the host, Docker, PostgreSQL and rebuilds the image for base-image fixes; pins base images by digest.
9. Encrypts database storage, backups and the anchor store; enables database TLS when the database is remote.
10. Keeps CUI and other regulated content out of PAL (§7).

## 11. Known gaps and residual risks

| Item | Impact | Suggested action |
|---|---|---|
| Administrators can map groups they are not in to document roles (§4.3) | SoD against collusion is detective (audit), not preventive | Owner decision D20: separate permission or second approver |
| SIEM audit line written inside the transaction | Rolled-back changes can leave phantom Sentinel records | Reconcile by `event_id`; or emit after commit |
| Client-supplied `X-Request-Id` accepted | Correlation IDs can be forged or reused | Strip/overwrite at the proxy if needed |
| Large-group role mapping syncs synchronously | Request may time out (mapping still saved) | Move the initial sync to the worker queue |
| Single-role deployments (Render) | Audit trail tamper-evident, not tamper-resistant | Use the owner/app role split in production |
| Rate-limit and OIDC metadata caches are per container (tmpfs) | Limits multiply with replicas and reset on restart | Accept for one web instance; use a shared cache pool before scaling out |
| `TRUSTED_PROXIES=private_ranges` default | Client-IP spoofing from internal networks | Exact proxy IPs; proxy overwrites `X-Forwarded-For` |
| Entra **Assignment required** not enforced by PAL | Every tenant account, guests included, becomes a Reader if unset | Mandatory operator setting (§2.5) |
| Database TLS not configured by default | Credentials and data in clear on the network if the DB is remote | Add `sslmode` to DSNs |
| Anchoring off by default; Compose mounts no anchor directory | Whole-chain rewrite by a DBA would go undetected | Mount write-once storage, set `PAL_AUDIT_ANCHOR_DIR` |
| Revocation latency | Up to 15 min (sync interval) for tracked groups; up to the idle (1 h) / absolute (12 h) session limit for users in no tracked group | Accept, run a manual sync for urgent cases, or shorten limits |
| `PdoSessionHandler` is replaced by a mock session store in the test environment | PostgreSQL session handling (locking, idle expiry) has no automated test | Add an integration test against PostgreSQL |
| ASVS L2 checklist not started | No evidence-backed compliance claim | Start the checklist (planned from Phase 1) |
| SBOM and automated dependency update PRs absent | Slower response to new CVEs | Add CycloneDX and Renovate/Dependabot |

## 12. Reporting a vulnerability

- **Do not open a public GitHub issue** for a suspected vulnerability; this repository is public.
- Report it privately to the repository owner, using GitHub's private vulnerability reporting for this repository if it is enabled, with a description, affected component and version (commit), reproduction steps and impact.
- Do not include real tenant IDs, hostnames, credentials or document content in the report.
- After handover to the private company repository (decision D12), that organisation's security reporting process replaces this section.
