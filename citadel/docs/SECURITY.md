# CITADEL — Security Guide

Security architecture and operator responsibilities for CITADEL. CITADEL reviews
**untrusted, potentially sensitive (CUI) source code**, so its own posture must
be strong. Related: [ARCHITECTURE.md](ARCHITECTURE.md) · [RBAC.md](RBAC.md) ·
[UPLOAD-SECURITY.md](UPLOAD-SECURITY.md) · [ENV.md](ENV.md) ·
[DEPLOYMENT.md](DEPLOYMENT.md) · [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md).

---

## 1. Identity & authentication

- **Sessions:** HS256 **JWT** access tokens (short-lived, default 30 min) plus a
  long-lived **refresh token** bound to an **httpOnly, Secure, SameSite=Strict**
  cookie scoped to `/api/auth` — script (and therefore XSS) cannot read it. The
  access token is a Bearer token; the SPA silently refreshes.
- **JWT hardening:** algorithm is **pinned** (a tampered `alg:none`/`RS256`
  token is rejected); tamper and expiry are enforced and unit-tested
  (`test/lib.test.js`). Signing secret is `CITADEL_JWT_SECRET` (sealed at rest
  when `CITADEL_DATA_KEY` is set).
- **Passwords:** hashed with **scrypt** by default (or **PBKDF2-HMAC-SHA256**
  under `CITADEL_FIPS=1`), compared timing-safe. A forced-change gate blocks use
  of the app until an admin-set temporary password is rotated.
- **MFA:** optional **TOTP** with one-time backup codes; a short MFA step-up
  window (`CITADEL_MFA_TTL`).
- **SSO:** optional **OIDC Authorization-Code + PKCE**, with JIT provisioning,
  `OIDC_ADMIN_EMAILS` → admin, `OIDC_DEFAULT_ROLE` for everyone else, and
  `OIDC_ALLOWED_DOMAINS` to restrict sign-in.
- **Rate-limiting & lockout:** per-IP fixed-window limits on login/MFA/refresh/
  scan/scan-url/explain; account lockout after repeated failures. Heavy routes
  **fail closed** (503) if the limiter backend is down. `TRUST_PROXY_HOPS`
  prevents `X-Forwarded-For` IP spoofing of these keys.

---

## 2. Authorization (RBAC)

- Enforced **on the backend** — the SPA only hides controls for UX. Three
  middlewares in `server/server.js`: `requireAuth` (valid, non-revoked token),
  `requirePerm('<page>')` (page permission, or open when `enforce` is off),
  `requireAdmin` (`role==='admin'`, never bypassed by enforce).
- **Roles:** `admin`, `analyst`, `auditor`, `viewer`; admins can grant a custom
  per-page subset. Full matrix + permission ids in [RBAC.md](RBAC.md).
- **Ownership:** project-keyed resources enforce `ownsProject` so a non-admin
  cannot read/modify another user's data (**no IDOR**) — covered by an
  RBAC-negative API test.
- **Secure default:** a fresh instance runs with enforcement **off** but
  `requireAdmin` still protected; a loud startup warning fires on a
  production-looking deploy unless `CITADEL_ALLOW_OPEN=1`. Turn `enforce` **on**
  for production.

---

## 3. Data protection

- **In transit:** TLS is terminated in front (nginx / ALB / ingress); the app
  sets `Secure`/`SameSite` cookies and disables `x-powered-by`. Postgres and
  Redis connections support TLS with verification (`PGSSL_VERIFY`/`PGSSL_CA`,
  `REDIS_TLS_VERIFY`/`REDIS_TLS_CA`).
- **At rest:** `CITADEL_DATA_KEY` **AES-256-GCM-seals** the JWT secret and TOTP
  seeds. Postgres/volume encryption is provided by the platform (KMS / Key Vault
  / CMEK). Passwords are never stored in the clear.
- **Uploaded-artifact handling (critical):** uploaded code is treated as
  **hostile input** and handled under a strict boundary (details in
  [UPLOAD-SECURITY.md](UPLOAD-SECURITY.md)):
  - Only ever **read** — never executed, imported, built, or run.
  - Extracted into a **fresh per-scan workdir** under `CITADEL_TMP`
    (recommended: non-persistent, `exec=false` **tmpfs**); the workdir is
    removed in a `finally` block whether the scan succeeds or fails; originals
    are unlinked immediately after extraction.
  - **Zip-slip** blocked via `safeJoin`; **decompression bombs** capped by entry
    count and actual inflated bytes (a lying header cannot undercount).
  - Nothing uploaded is retained — no scan artifact persists by default.
- **Key management:** source `CITADEL_JWT_SECRET`, `CITADEL_DATA_KEY`, and
  provider keys from a **secret manager**; prefer **IAM roles / workload
  identity** over static credentials.

---

## 4. Auditability

- **Hash-chained audit log** (`server/lib/audit.js`, `citadel_audit`): each event
  links to the prior hash, so tampering is detectable via
  `GET /api/audit/verify`. Events include auth, admin, user, session, scan, and
  rate-limit-block actions with actor / IP / target.
- **Forward to SIEM:** `CITADEL_AUDIT_SINK_URL` (+ token) streams events to an
  external SIEM for a second, independent copy (recommended for compliance —
  see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).
- **What is never logged:** uploaded source code, secret values, passwords, and
  tokens are never written to logs; detected secrets are **masked** (PANs show
  only the last four digits). Application logs carry metadata only.

---

## 5. Classification & DLP (CUI / sensitive code)

Scanned code may be **CUI, ITAR/export-controlled, or proprietary**. CITADEL is
built to keep it inside the boundary:

- The reference image is labeled `io.citadel.classification="CUI"` and targets
  FedRAMP/CMMC-L2/NIST-800-171/IL4–IL5 postures.
- **Egress control:** set `CITADEL_AIRGAP=1` (or `CITADEL_NO_EGRESS=1`) to
  **hard-disable** AI remediation and all outbound enrichment, so source code can
  never be transmitted to an external LLM or CVE service. For AI in an enclave,
  point the SDK at an in-enclave Ollama endpoint (see [DEPLOYMENT.md §7](DEPLOYMENT.md#7-ollama--self-hosted-ai-explain--fix--air-gapped)).
- **SSRF protection:** `POST /api/scan-url` validates targets (blocks internal/
  metadata addresses) — covered by an API test.
- **Data minimization:** uploads are transient; only derived findings/reports are
  retained (and only when a database is attached). Treat the model host and DB as
  part of the CUI boundary; deploy to **AWS GovCloud** / **Azure Government** with
  FIPS endpoints for regulated workloads ([`../deploy/aws-gov/`](../deploy/aws-gov/),
  [`../deploy/azure-gov/`](../deploy/azure-gov/)).

---

## 6. FIPS readiness

- `CITADEL_FIPS=1` calls `fips.enable()` early (before any password/seed crypto),
  entering OpenSSL FIPS mode when the build supports it (no-op + warning
  otherwise) and forcing **PBKDF2-HMAC-SHA256** for password hashing.
- Pair with **FIPS-validated endpoints** on the platform (e.g. AWS
  `*-fips.<region>.amazonaws.com`, GovCloud partition) and a FIPS-mode OpenSSL in
  the base image / host.
- CITADEL also **maps findings to FIPS 140-3** as a compliance framework — that
  is analysis output about the *scanned* code, distinct from the runtime FIPS
  mode above.

---

## 7. Operator responsibilities

- [ ] Turn **enforcement on**; do not run open in production.
- [ ] Set stable `CITADEL_JWT_SECRET` and `CITADEL_DATA_KEY` from a secret manager.
- [ ] Terminate TLS; set correct `TRUST_PROXY_HOPS`; guard `/metrics`.
- [ ] Run the container **non-root, read-only root FS**, cap-dropped,
      `no-new-privileges`; mount `CITADEL_TMP` as non-persistent tmpfs.
- [ ] Enable MFA/SSO; scope `OIDC_ADMIN_EMAILS`/`OIDC_ALLOWED_DOMAINS` tightly.
- [ ] Forward audit to SIEM; set log retention; verify the audit chain regularly.
- [ ] Keep scanner DBs and the base image patched; back up Postgres (see DR).
- [ ] For CUI/ITAR: `CITADEL_AIRGAP=1` or a validated in-enclave LLM; gov endpoints.

---

## 8. Secrets rotation

| Secret | Rotate by | Impact |
|---|---|---|
| `CITADEL_JWT_SECRET` | Update in the secret manager + redeploy | Invalidates all active access/refresh tokens — users re-authenticate. |
| `CITADEL_DATA_KEY` | Re-seal existing material during a maintenance window (must remain resolvable) | A changed key **cannot unseal** existing JWT-secret/TOTP data — coordinate to avoid locking out MFA users. |
| Admin / user passwords | Admin reset (forces change on next login) | Per-user. |
| `ANTHROPIC_API_KEY` | Rotate in the secret manager | AI remediation only. |
| OIDC client secret | Rotate at the IdP + update env | SSO login. |
| DB / Redis creds | Rotate at the managed service; prefer IAM auth | Reconnect on redeploy. |

Rotate on a schedule and immediately on suspected compromise; record rotations
in the audit trail / change log.

---

## 9. Vulnerability reporting

- **Report a vulnerability** in CITADEL to the maintainer (Jessica Rojas) via the
  repository's security contact / private advisory; do not open a public issue
  for an unpatched flaw.
- **Include:** affected version (`/api/health` `version`), deployment model,
  reproduction, and impact.
- **Handling:** triage on receipt; a fix or mitigation for confirmed
  high/critical issues is prioritized, with a coordinated disclosure once a fix
  is available. Operators should track the base image + scanner-binary advisories
  as part of routine patching.

> **Scope note.** CITADEL's own analysis is **heuristic + real-scanner** and does
> not replace a credentialed penetration test or assessor review. Verify findings
> before acting, and pair CITADEL with a qualified assessment for an ATO.
