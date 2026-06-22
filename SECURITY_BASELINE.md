# Platform Security Baseline

**Status:** Authoritative standard for all back-end applications in this mono-repo
**Owner:** Platform Security
**Last updated:** 2026-06-22
**Drivers:** Enterprise Security Review §14 (no shared baseline — each app re-implements auth/crypto/headers/rate-limiting at divergent quality), §25 (no central audit), §38 (enterprise recommendations), §40 (long-term roadmap).

---

## 0. Purpose & Scope

The review found a **bimodal maturity distribution**: `aegis`, `sentinel-qms`, and `citadel` are genuinely strong, while `apex` and the static site shipped exploitable issues, and every app re-invents the same primitives. This document fixes the divergence by naming **one canonical pattern per control**, each backed by a real in-repo reference implementation.

This baseline is **normative**. New back-end code MUST conform. Existing apps are tracked against it in the [Conformance Matrix](#13-per-app-conformance-matrix). Where a reference implementation is cited by `file:line`, that code is the contract — copy it, do not re-derive it.

Applies to: `aegis`, `paladin`, `apex`, `sentinel-qms`, `citadel`, `compliance-copilot`, `aeromarkup`. The root static portfolio is **out of scope as a security boundary** — client-side gating there is never authoritative (see review H2).

---

## 1. Reference Implementations (the canonical sources)

| Concern | Canonical reference | Path |
|---|---|---|
| Crypto, CSRF, CSP, escaping, nonces (PHP) | aegis `Security` | `aegis/src/Security.php` |
| Session/authn/authz, hash-chained audit (PHP) | aegis `Auth` | `aegis/src/Auth.php` |
| Hardened session cookie config | aegis bootstrap | `aegis/index.php:112-122` |
| HTML sanitizer, CSRF, headers (PHP, defense-in-depth) | paladin `Security` | `paladin/src/Security.php` |
| Object-level (record) authz | paladin `SpaceAccess` | `paladin/src/SpaceAccess.php` |
| bcrypt + DB login throttle, PAT design, granular RBAC, refresh-cookie + jti denylist | sentinel-qms backend | `sentinel-qms/backend/app/` |
| Magic-byte upload sniffing | sentinel-qms storage | `sentinel-qms/backend/app/services/storage.py` |
| SSRF resolve-once / pin-IP | sentinel-qms + citadel | `sentinel-qms/backend/app/core/net_guard.py`, `citadel/server/server.js:641-682` |
| `execFile` no-shell, container hardening | citadel server | `citadel/server/server.js`, `citadel/server/docker-compose.yml` |
| GovCloud Terraform (CMK/private/TLS) conventions | sentinel-qms + citadel | `sentinel-qms/infra/terraform/`, `citadel/deploy/aws-gov/` |
| SHA-pinned CI + SBOM + provenance + cosign | release pipelines | `.github/workflows/release-aegis-image.yml`, `sentinel-qms/.github/workflows/cd-aws-govcloud.yml` |

---

## 2. Secret Loading — fail closed

**Standard:** Secrets come from environment variables (locally) or a cloud secrets manager (deployed). Loaders MUST **fail closed in production**: a missing or weak secret is a startup error, never a silent fallback to a dev default.

**Reference (now fixed — this was review C1):** `apex/src/Auth.php:148-171`

```php
private static function secret(): string {
    $s   = getenv('JWT_SECRET') ?: '';
    $env = getenv('APP_ENV') ?: 'development';
    if ($env === 'production') {
        if (strlen($s) < 32) {
            throw new RuntimeException('JWT_SECRET is missing or too short (need >= 32 chars) in production.');
        }
        return $s;
    }
    if ($s === '') { $s = 'apex-dev-secret-please-override'; } // dev only
    return $s;
}
```

**Rules:**
- Production: a missing/short (`< 32 char`) secret MUST throw at startup. No dev fallback path may execute when `APP_ENV=production`.
- Compose/IaC MUST require values with the `${VAR:?set me}` pattern (paladin/apex compose) — never inline a default credential.
- Key derivation: separate purpose-bound keys via labeled SHA-256 (aegis: `aegis_settings_v2:`, `aegis_audit_v2:` — `aegis/src/Security.php:233-258`), with a documented legacy fallback for rotation only.
- Never commit `.env`; only `.env.example` with placeholders. Root `.gitignore` MUST exclude `.env`, `*.pem`, `*.key`, `secrets.*`.

---

## 3. Password Hashing

**Standard:** Argon2id (preferred) or bcrypt. Tunable cost. Server-side only — never accept a client-supplied `password_hash` (this was the aeromarkup Addendum A.1 critical).

**References:**
- Argon2id — `aegis/src/Security.php:127-137`: `password_hash($pw, PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>4,'threads'=>2])`, verify via `password_verify`.
- bcrypt — `sentinel-qms/backend/app/core/security.py:27-35`: `CryptContext(schemes=["bcrypt"], deprecated="auto")`.

**Rules:** Argon2id params ≥ 64 MiB / 4 iters / 2 threads, or bcrypt cost ≥ 12. Verification uses the library's constant-time verify. Rehash on login when params change.

---

## 4. Session & Token Policy

### 4.1 Server-side sessions (PHP apps: aegis, paladin, apex web)

**Reference:** `aegis/index.php:112-122`

| Directive | Value |
|---|---|
| `session.cookie_httponly` | `1` |
| `session.cookie_samesite` | `Strict` |
| `session.cookie_secure` | `1` (HTTPS) |
| `session.use_strict_mode` | `1` |
| `session.use_only_cookies` | `1` |
| session name (HTTPS) | `__Host-AEGIS` (the `__Host-` prefix forces Secure + Path=/ + no Domain) |

**Rules:** `session_regenerate_id(true)` on every privilege transition (login) — `aegis/src/Auth.php:461`. Enforce idle/absolute lifetime and server-side revocation (`sessions_revoked_at`) in `requireAuth()` — `aegis/src/Auth.php:363-428`.

### 4.2 JWT / SPA token policy — the **H6 pattern** (canonical)

This is the standard for any SPA/API token flow (sentinel-qms, citadel, apex API, compliance-copilot):

1. **Refresh token in an `HttpOnly; Secure; SameSite=Strict` cookie** — never in `localStorage`. JS cannot read it, so XSS cannot exfiltrate it.
2. **Access token held in memory only** (module variable), never persisted.
3. **`jti` minted on every token and checked on every request.** Logout and refresh-rotation add the `jti` to a **denylist with TTL**. Stateless logout is not acceptable.
4. **Refresh rotation with reuse detection** — a replayed refresh `jti` burns the whole session family.

**References (this is the fixed state of review H6):**
- Access in memory, refresh in HttpOnly cookie, transparent 401-refresh: `sentinel-qms/frontend/src/lib/api.ts:11-97`.
- `jti` minting: `sentinel-qms/backend/app/core/security.py:50,82`.
- Refresh rotation + reuse detection: `sentinel-qms/backend/app/services/refresh_tokens.py:46-105`.
- `jti` denylist (Redis with DB fallback, checked on every authed request): `sentinel-qms/backend/app/services/token_denylist.py:62-109`.
- citadel MFA + lockout + must-change flow: `citadel/server/server.js:327-426`.

**Cookie scope note:** apex currently uses `SameSite=Lax` (`apex/src/Auth.php:107-117`) to permit cross-site form posts; new flows MUST use `Strict` unless a documented cross-site requirement exists.

### 4.3 Personal Access Tokens (PATs)

**Reference:** `sentinel-qms/backend/app/services/api_tokens.py:24-88`

- 256-bit entropy (`secrets.token_urlsafe(32)`), human-readable prefix (`sntl_…`).
- **SHA-256 at rest only** — plaintext never stored; lookup by indexed digest.
- Scope enforcement against an allowlist; fail-closed on unknown scope.

---

## 5. Authorization

**Standard:** Deny-by-default. Two layers, both required where applicable:

1. **Function-level RBAC** — every controller/route calls `requirePermission('module.action')` (aegis) or the granular IAM check (sentinel). Granular `module.action` strings, not coarse read/write. Reference: `aegis/src/Auth.php:430-437`; `sentinel-qms/backend/app/core/iam.py:46-255,528-541`.
2. **Object-level (record) authz** — global permission is **not** sufficient to read/modify a specific record. Membership/ownership of the parent object MUST be checked. Reference: `paladin/src/SpaceAccess.php:41-62` (`canView/canContribute/canManage`). This closes the IDOR class (review H4).

**Rule:** Any list/search/export endpoint MUST filter by object-level access, not just gate the action.

---

## 6. Output Encoding & CSP — no `'unsafe-inline'`

**Standard:** All user output is escaped at the sink; CSP is **nonce-based** for scripts with **no `'unsafe-inline'` in `script-src`**. No inline event handlers (CSP + the project UI rules).

**References:**
- Escaping: `aegis/src/Security.php:45-47` / `paladin/src/Security.php:45-46` — `htmlspecialchars($v, ENT_QUOTES|ENT_HTML5, 'UTF-8')`.
- `json_encode` in script contexts MUST use `JSON_HEX_TAG | JSON_HEX_AMP` (project rule).
- Per-request nonce: `aegis/src/Security.php:326-331` (`base64_encode(random_bytes(18))`).
- CSP emission: `aegis/src/Security.php:333-376`; `paladin/src/Security.php:389-422`; API/SPA tiers in `sentinel-qms/backend/app/core/middleware.py:18-93`.
- HTML sanitizer (DOMDocument, blocks `script/style/iframe/object/embed/form/svg/math/foreignobject`, strips `on*` and namespaced `*:href`, rejects `javascript:`/`vbscript:`/`data:`): `paladin/src/Security.php:58-143`. **This is the fixed state of the SVG-bypass finding H3** — use it as the sanitizer reference.

**Open gap to close:** citadel frontend CSP still allows `style-src 'unsafe-inline'` (review Medium §9) — migrate interpolated inline styles to CSS custom properties; `script-src` must remain nonce-only everywhere.

---

## 7. CSRF

**Standard:** Synchronizer token on every state-changing request; constant-time compare; rotate after use; lifetime-bounded.

**Reference:** `paladin/src/Security.php:19-43` and `aegis/src/Security.php:19-43`.

```php
if (!hash_equals($_SESSION['csrf_token'], $token)) return false;
unset($_SESSION['csrf_token'], $_SESSION['csrf_time']); // rotate after use
```

**Rules:** Every POST form emits `Security::csrfField()`; every POST handler calls `Security::validateCsrf()`. AJAX endpoints that mutate state return a rotated token in the JSON response so the client refreshes its in-memory copy (project IAM-save rule). For SPA/cookie flows, pair the SameSite=Strict refresh cookie with a CSRF header check (`x-requested-with`) — see `compliance-copilot/app/api/ai/generate/route.ts:92-120`.

---

## 8. Rate Limiting — shared store

**Standard:** Rate limiting MUST use a **shared atomic store** (Redis `INCR`+`EXPIRE`) or be enforced at the gateway/WAF. Per-process in-memory buckets are **not acceptable** in multi-worker/multi-replica deployments (review H5) — with `WEB_CONCURRENCY ≥ 2` the effective limit becomes `N×` and requests load-balance across workers.

**References:**
- Current in-memory limiter with Redis fallback hook: `sentinel-qms/backend/app/core/middleware.py:135-244` — the Redis path is the target; the in-memory path is dev-only.
- DB-backed login throttle (acceptable because it is authoritative and shared): `sentinel-qms/backend/app/api/routers/auth.py:69-98`; citadel per-`email|ip` lockout `citadel/server/server.js:327-369`.

**Rule:** Emit `X-RateLimit-{Limit,Remaining,Reset}` + `Retry-After`. Fail closed if the limiter backend is unavailable on auth paths (citadel does this at `server.js:335-341`).

---

## 9. File Upload Validation — magic-byte sniffing

**Standard:** Never trust the client `Content-Type`. Validate via **magic-byte sniffing** against an allowlist; reject on mismatch; store under a randomized filename; RFC 5987-encode `Content-Disposition`.

**Reference:** `sentinel-qms/backend/app/services/storage.py:15-278` (signature table at `:38-50`, `sniff_matches_declared()` at `:263-278`, fail-closed on unknown type at `:277`) and the enforcement at `sentinel-qms/backend/app/api/routers/attachments.py:27-94`.

```python
# storage.py — authoritative content check, not the header
if not sniff_matches_declared(data, content_type):
    raise HTTPException(415, "File content does not match declared type")
```

**Rules:** Extension allowlist + MIME allowlist + magic-byte match (all three). Random stored filename. For `adm-zip`/archive handling, verify zip-slip (review §23). Field-reference key below every upload UI (project rule).

---

## 10. SSRF Defense — resolve once, pin the IP

**Standard:** For any server-initiated outbound request to a user-influenced URL: **resolve the hostname once, reject if any resolved address is private/loopback/link-local/metadata, then connect to the pinned IP** while using the original hostname for SNI/Host. This defeats DNS-rebinding/TOCTOU.

**References:**
- `sentinel-qms/backend/app/core/net_guard.py:62-87` (`resolve_public_url()` → `ResolvedTarget{ips, host}`) consumed by `sentinel-qms/backend/app/services/delivery.py:94-149` (connect to pinned IP, `server_hostname=target.host` for TLS).
- citadel git-clone: `citadel/server/server.js:641-682` — `dns.lookup(all:true)`, reject private, pin via `http.curloptResolve=${host}:${port}:${pinnedIp}`, `http.followRedirects=false`, no shell.

**Rule:** Block by name first (`localhost`, cloud metadata `169.254.169.254`), then by every resolved IP. Disable redirect-following or re-validate each hop.

---

## 11. Security Headers — the standard set

Every HTTP response from a back-end app MUST set:

| Header | Value |
|---|---|
| `Content-Security-Policy` | nonce-based, `script-src 'self' 'nonce-…'`, `frame-ancestors 'none'`, `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`; **no `'unsafe-inline'` in script-src** |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` (all prod envs, including demos) |
| `X-Frame-Options` | `DENY` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` (or `no-referrer`) |
| `Permissions-Policy` | disable unused features: `geolocation=(), microphone=(), camera=()` |
| `Cross-Origin-Opener-Policy` | `same-origin` |
| `Cross-Origin-Resource-Policy` | `same-origin` |
| `X-XSS-Protection` | **omitted / `0`** — the legacy auditor can itself introduce XSS; do not set `1; mode=block` (review Low §10) |

**References:** `aegis/.htaccess:10-17`, `paladin/.htaccess:13-22` (note the intentional X-XSS-Protection omission), `apex/public/.htaccess:18-26`, `sentinel-qms/backend/app/core/middleware.py:71-94`, `citadel/server/server.js:148-156`.

**Open gap:** `aegis/docker/nginx.conf` and `aeromarkup` lack the full set / enforce HSTS only outside dev — fix to match `.htaccess`. No demo may ship `ENVIRONMENT=development` (which drops HSTS) on a public URL.

---

## 12. Container Hardening — citadel/server model

**Standard:** Every production container runs **non-root, read-only root FS, all capabilities dropped, no privilege escalation**, with `tmpfs` for scratch (noexec) and explicit resource limits.

**Reference:** `citadel/server/Dockerfile:278-294` (uid/gid 10001, `USER 10001:10001`) + `citadel/server/docker-compose.yml:24-57`:

```yaml
read_only: true
cap_drop: [ALL]
security_opt: ["no-new-privileges:true"]
tmpfs:
  - /tmp:mode=1777,size=256m        # exec=false
deploy:
  resources:
    limits:   { cpus: "2.0", memory: 2g }
    reservations: { cpus: "0.5", memory: 1g }
```

**Rules:** Multi-stage build; pinned base image digest (no `:latest`); PID 1 not root (aegis/apex Dockerfiles currently violate this — review §21). Bind databases to `127.0.0.1`, never `0.0.0.0:5432` (review H10). K8s: `runAsNonRoot`, `readOnlyRootFilesystem: true`, `allowPrivilegeEscalation: false`, default-deny NetworkPolicy, restricted PodSecurity (sentinel k8s base is the model — review §22).

---

## 13. CI / Supply Chain — SLSA L2+

**Standard:**
- **SHA-pin every third-party action** (full commit SHA, never `@vX` or `@master`).
- **Generate + attest SBOM and provenance** on every production image (`provenance: true`, `sbom: true` on `docker/build-push-action`, plus `actions/attest-build-provenance`).
- **Sign images with cosign (keyless OIDC)** and **verify signatures at deploy** — no unsigned image may ship.
- **Make dependency/SAST scans gating** for new code (currently soft-fail).

**References (this is the largely-fixed state of review H9/H10):**
- SHA-pinned + SBOM + provenance: `sentinel-qms/.github/workflows/cd-aws-govcloud.yml:34-92` (`build-push-action` with `provenance: true, sbom: true`; `attest-build-provenance` at `:83-92`).
- Cosign keyless + provenance `mode=max` + verify gate: `.github/workflows/release-aegis-image.yml:48-122`.
- All actions SHA-pinned across `.github/workflows/ci.yml`, `sentinel-qms/.github/workflows/security-scan.yml`.

**Open gaps:** `security-scan.yml` Checkov/pip-audit/npm-audit are **soft-fail** (`soft_fail: true` at `:41-47`; `::warning::` at `:79,:85`) — promote to gating for changed paths. `workflow_dispatch`/composite-action inputs that flow into `run:` must be routed via `env:` (review §9). Add Dependabot/renovate across all apps.

---

## 14. Per-App Conformance Matrix

Legend: **OK** = meets baseline · **Partial** = partially / dev-only / fix in flight · **Gap** = does not meet · **N/A** = not applicable. "→" notes the target action.

| Control | aegis | paladin | apex | sentinel-qms | citadel | compliance-copilot | aeromarkup |
|---|---|---|---|---|---|---|---|
| **2. Fail-closed secrets** | OK | OK | OK *(fixed C1)* | OK | OK | OK *(503 in prod, H7 fixed)* | Gap → add startup guard |
| **3. Password hashing** | OK (Argon2id) | OK | OK | OK (bcrypt) | OK | N/A (Supabase auth) | Gap → **stop accepting client `password_hash`** (A.1 Critical) |
| **4.1 Session cookie hardening** | OK | OK | Partial (SameSite=Lax) | N/A (token) | N/A (token) | N/A | N/A |
| **4.2 H6 token pattern** | N/A | N/A | Partial (HttpOnly cookie; add jti denylist) | OK *(fixed H6)* | OK (MFA+lockout) | Partial (Supabase session) | Gap → **no authn at all** (A.1) |
| **4.3 PAT design** | N/A | N/A | N/A | OK | N/A | N/A | N/A |
| **5. RBAC + object-level authz** | OK | OK *(SpaceAccess; H4 fixed)* | Partial (role claim only) | OK (granular IAM) | OK | Gap → **no org scoping / `using(true)`** (H8) | Gap → **no authz** (A.1) |
| **6. Output encoding / nonce CSP** | OK | OK *(sanitizer; H3 fixed)* | OK (static CSP) | OK | Partial (`style-src 'unsafe-inline'`) | Partial | Partial (SPA encode-on-render, A.1 Low) |
| **7. CSRF** | OK | OK | Partial (SameSite + header) | OK | OK | OK (header + SameSite) | Gap |
| **8. Shared-store rate limiting** | App-level | App-level | Gap | Partial (Redis path; in-mem default H5) | OK (per email\|ip) | Partial (in-mem, best-effort) | Gap |
| **9. Magic-byte upload validation** | N/A | OK (attachments) | N/A | OK | N/A (scanner) | N/A | Partial (no sniffing) |
| **10. SSRF resolve-once/pin-IP** | N/A | N/A | N/A | OK | OK | N/A (no outbound user URLs) | N/A |
| **11. Security headers (full set)** | OK | OK | OK | OK | Partial (CSP unsafe-inline) | Partial | Gap → **no WAF/TLS, public app** (C2) |
| **12. Container hardening** | Partial (root PID 1) | Partial | Partial (root PID 1) | OK (k8s) | OK *(the model)* | N/A (Vercel) | Gap (public ACA, AUTO_MIGRATE on boot) |
| **13. SHA-pin + SBOM + provenance + cosign** | OK *(release pipeline)* | Inherits CI | Inherits CI | OK (cd-*; scans soft-fail) | OK | Partial | Partial |

**Production posture (from review §42):** `aegis`/`citadel`/`sentinel-qms` near-ready after Medium fixes + central audit; `paladin` ready after its (now-applied) H3/H4 fixes; `apex` ready after C1/H1 (C1 fixed); `compliance-copilot` not multi-tenant-ready until H8 (see `TENANT_ISOLATION.md`); `aeromarkup` and the static site are demo-tier and MUST NOT be positioned as security-bearing.

---

## 15. Enforcement

1. New PRs touching auth/crypto/headers/uploads/outbound-HTTP cite the baseline section they conform to.
2. The post-feature security audit agent (CLAUDE.md workflow rule 1) checks against §2–§13.
3. The Conformance Matrix is updated whenever an app closes or opens a gap.
4. Companion docs: `CENTRAL_AUDIT.md` (§25 audit aggregation) and `TENANT_ISOLATION.md` (§38.5 tenant model).
