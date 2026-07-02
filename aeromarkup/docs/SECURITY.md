# AeroMarkup — Security Guide

Security model, controls, and operator responsibilities for AeroMarkup — a
CUI-aware aerospace engineering-lifecycle platform for DoD programs, deployable
to Render, **AWS GovCloud**, and **Azure Government**.

Related docs: [ARCHITECTURE.md](ARCHITECTURE.md) · [DEPLOYMENT.md](DEPLOYMENT.md)
· [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · operator guides under
[`../deployments/`](../deployments/).

---

## 1. Identity & Authentication

- **Local user store.** Users live in the `aeromarkup.users` table. Passwords
  are hashed with werkzeug `generate_password_hash` / verified with
  `check_password_hash`. Plaintext passwords are never stored or logged.
- **No shipped default credential.** There is no built-in admin account. On a
  fresh install, `POST /api/auth/bootstrap` creates the initial admin **only
  while no user has a password set**. Constraints: username `≥ 3` chars,
  password `≥ 8` chars. Once an admin exists, the endpoint returns
  `already_initialized` (403).
- **Stateless signed sessions.** On login the server issues a **stateless signed
  token** (`itsdangerous` `URLSafeTimedSerializer`, salt `"aeromarkup-session"`,
  signed with `AEROMARKUP_SECRET`). There is **no server-side session store**.
  The token is carried in an **HttpOnly** cookie:

  | Cookie | Flags |
  | --- | --- |
  | `am_session` | `HttpOnly`, `max_age=SESSION_TTL_SECONDS` (default 43200 / 12h), `secure=IS_PROD`, `samesite=Strict` |

  Because the session token is **HttpOnly**, it is **not readable by JavaScript
  and therefore not exfiltratable via XSS** — a key design property. It is never
  placed in `localStorage`.
- **Secret requirement.** `AEROMARKUP_SECRET` must be `≥ 32` chars and is
  **REQUIRED in production when `DATABASE_URL` is set — the app REFUSES to boot
  otherwise.** It must be **identical across all replicas** or cross-replica
  session validation fails.

### Brute-force throttle

- In-memory **sliding window of FAILED logins** keyed on `(client IP, username)`.
- `≥ LOGIN_MAX_ATTEMPTS` (default 5) within `LOGIN_WINDOW_SECONDS` (default 300)
  → **HTTP 429** with a `Retry-After` header.
- Tracks up to `LOGIN_MAX_TRACKED` (default 8192) distinct keys.
- The real client IP is derived via `ProxyFix` **only when
  `TRUSTED_PROXY_HOPS > 0`**. The app **never reads `X-Forwarded-For` directly**,
  so an attacker cannot spoof the header to evade the limit.
- The throttle is **per-process** (per gunicorn worker / replica). For
  multi-replica deployments, **also enforce rate limiting at the gateway/WAF**
  so the aggregate limit is meaningful.

---

## 2. Authorization (RBAC)

Every state-changing request is authorized against the capability matrix `CAP`
in `server.py`, enforced by the `@requires(action)` decorator and inline
`_can()` checks. **`admin` bypasses all checks.**

### Roles

`viewer` · `engineer` · `inspector` · `approver` · `admin`

### Capability matrix

| Action | viewer | engineer | inspector | approver | admin |
| --- | :---: | :---: | :---: | :---: | :---: |
| `drawing.edit` | | ✔ | | | ✔ |
| `drawing.submit` | | ✔ | | ✔ | ✔ |
| `drawing.approve` | | | | ✔ | ✔ |
| `drawing.release` | | | | ✔ | ✔ |
| `ncr.create` | | ✔ | ✔ | | ✔ |
| `ncr.disposition` | | | | ✔ | ✔ |
| `inspection.perform` | | | ✔ | | ✔ |
| `project.manage` | | ✔ | | | ✔ |
| `comment.create` | | ✔ | ✔ | ✔ | ✔ |
| `user.manage` | | | | | ✔ |
| `audit.read` | | | | ✔ | ✔ |

Read access to project data is available to authenticated users per the app's
view routing; the table above governs consequential/mutating actions.

### Server-bound identity (anti-spoofing)

- The server **binds e-signature, NCR "raised-by", and inspector identity to the
  AUTHENTICATED user** — these are **never taken from client-supplied fields**.
  A client cannot claim to be another operator on an approval or NCR.
- `uuid_or_none()` guards FK columns: free-text values are coerced to `NULL`
  rather than injected into UUID foreign-key columns.

---

## 3. CSRF Protection

- **Double-submit token.** A separate **JS-readable** cookie `am_csrf` is paired
  with a request header `X-CSRF-Token`.
- On every `POST / PUT / PATCH / DELETE` under `/api/`, the two values are
  compared with `secrets.compare_digest` (constant-time). Mismatch →
  `csrf_failed` (403).
- **Public endpoints** exempt from auth (and thus the login/bootstrap flows):
  `/api/health`, `/api/auth/status`, `/api/auth/login`, `/api/auth/bootstrap`.

The session cookie (`am_session`) is `HttpOnly` (not JS-readable) while the CSRF
cookie (`am_csrf`) is JS-readable by design — the split is what makes
double-submit work without exposing the session token to scripts.

---

## 4. Data Protection

| Control | Detail |
| --- | --- |
| **In transit** | TLS terminates at the edge — ALB / Azure Container App ingress / Render. In gov regions, connect to Postgres with **`sslmode=require`**. |
| **At rest** | Provider storage encryption — **RDS / Azure DB storage encryption** with **KMS / Key Vault CMK**. |
| **Secrets** | AWS **Secrets Manager** / Azure **Key Vault + Container App secrets** / Render **`generateValue`**. **Never baked into the image** or committed. Only `.env.example` (placeholders) is in the repo. |
| **Container** | Runs **non-root as uid `10001`** with a healthcheck; multi-stage build. |
| **Uploads** | Reference images + STL/OBJ 3D models are stored as **data URLs in Postgres** (no object storage), so DB access controls and encryption cover them. |
| **Branding URLs** | Frontend branding logo URLs are **sanitized to `http(s)://` or `data:image/` only**. |

---

## 5. Auditability

- **Immutable, append-only `audit_log`** — columns: `seq` (bigserial), `actor`,
  `action`, `entity_type`, `entity_id`, `detail` (jsonb), `source`,
  `created_at`.
- Each audit row is written **inside the same transaction as the mutation**, so
  an action and its audit record commit or roll back together — no orphaned or
  missing audit entries.
- **Every consequential action is audited.**
- **E-signature approvals** carry a `signature_hash` and are bound to the
  authenticated signer (§2), providing non-repudiable approval records.
- Reading the trail via `GET /api/audit` requires the **`audit.read`**
  capability (approver/admin).

---

## 6. Classification & DLP

- **CUI banners** render at the **top and bottom** of the UI and are **persisted
  into print output**, meeting marking requirements on-screen and on paper.
- **Classification columns** on `programs`, `projects`, `drawings`, and `ncrs`
  carry values: `UNCLASSIFIED`, `CUI`, `CUI//SP-PROPIN`, `UNCLASS//FOUO`.
- **No external egress.** The app makes **no third-party CDN, runtime, or AI
  calls** — everything is self-hosted. This is a deliberate **DLP / air-gap
  posture**: CUI never leaves the boundary via a hidden dependency, and the app
  runs in a fully disconnected enclave (see
  [`../deployments/AIRGAPPED.md`](../deployments/AIRGAPPED.md)).
- **Input sanitization:** upload/branding URLs restricted to
  `http(s)://` / `data:image/`; `uuid_or_none()` keeps free text out of FK
  columns; server-bound identity prevents attribution spoofing.

---

## 7. FIPS Readiness

- Use **FIPS-validated endpoints** in AWS GovCloud and Azure Government (regional
  FIPS endpoints, partition `aws-us-gov`). Terminate TLS at a FIPS-validated edge.
- The application relies on the **host Python / OpenSSL** for cryptographic
  primitives (password hashing, token signing, TLS to the DB). Ensuring the
  runtime uses a **FIPS-validated OpenSSL module** is an **operator
  responsibility** — build/run on a FIPS-enabled base image or host and confirm
  the OpenSSL provider is in FIPS mode.
- Enforce **`sslmode=require`** (or stronger, e.g. `verify-full` with the RDS/
  Azure CA) to the database.

---

## 8. Operator Responsibilities

- **Set a strong `AEROMARKUP_SECRET`** (`≥ 32` random chars), store it in a
  secrets manager, and use the **same value across all replicas**.
- **Set `TRUSTED_PROXY_HOPS` correctly** for your edge topology — too high lets
  clients spoof source IP; `0` if the app is directly exposed / behind an
  untrusted hop.
- **Add gateway/WAF rate limiting** for multi-replica deployments (the built-in
  throttle is per-process).
- **Rotate secrets** on schedule and on suspected compromise (§9).
- **Restrict DB network access** (security groups / private endpoints) and keep
  `sslmode=require`.
- Keep the container running **non-root (uid 10001)** and pull images from a
  trusted/registry-scanned source.
- Run **DR restore drills** (see [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)).

---

## 9. Secrets Rotation

| Secret | How to rotate | Impact |
| --- | --- | --- |
| `AEROMARKUP_SECRET` | Replace the value in Secrets Manager / Key Vault / Render and redeploy all replicas. | **Invalidates all active sessions** (tokens signed with the old secret no longer verify) — users must re-authenticate. Roll out the same new value to every replica simultaneously. |
| Database credentials | Rotate via **AWS Secrets Manager** rotation / **Azure Key Vault**, then update `DATABASE_URL` and redeploy. | Brief reconnect; no data impact. Keep `sslmode=require`. |

---

## 10. Reporting / Vulnerability Disclosure

- **Contact:** `<security-contact@your-org.example>` *(replace with the program's
  security POC / disclosure inbox).*
- **Acknowledgement SLA:** `<e.g. 2 business days>`; **triage/initial
  assessment:** `<e.g. 5 business days>`; remediation timeline by severity per
  program policy.
- Please include affected version/commit, environment, reproduction steps, and
  impact. Handle any CUI in reports per its marking.

---

## Appendix — Error / Status Code Reference (security-relevant)

Errors are JSON `{"error":"<code>", ...}`; authorization failures also include
`"need": "<action>"`.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `no_database` | 503 | No database configured/reachable. |
| `unauthorized` | 401 | No/invalid session token. |
| `forbidden` (+ `need`) | 403 | Authenticated but lacks the required capability. |
| `csrf_failed` | 403 | Missing/mismatched CSRF token on a state-changing request. |
| `too_many_attempts` | 429 | Login throttle tripped (`Retry-After` header set). |
| `invalid_credentials` | 401 | Bad username/password. |
| `weak_credentials` / `weak_password` | 400 | Username `<3` / password `<8`. |
| `missing_credentials` | 400 | Username or password absent. |
| `already_initialized` | 403 | `/api/auth/bootstrap` after an admin exists. |
| `invalid_role` | 400 | Unknown role in user management. |
| `invalid_action` | 400 | Unknown capability/action requested. |
| `not_found` | 404 | Entity does not exist. |
