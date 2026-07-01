# Sentinel QMS — Security Guide

Operator-grade security reference for Sentinel QMS. The root
[`../SECURITY.md`](../SECURITY.md) is the short public policy + vulnerability
contact; this document is the full control-level guide. Deep internals:
[`architecture/security-architecture.md`](architecture/security-architecture.md);
control mappings under [`compliance/`](compliance/).

Sentinel QMS handles **Controlled Unclassified Information (CUI)** for aerospace,
manufacturing, and DoD supply-chain work. Security is a first-class requirement.

Related: [`ARCHITECTURE.md`](ARCHITECTURE.md) · [`DEPLOYMENT.md`](DEPLOYMENT.md) ·
[`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)

---

## 1. Supported deployment baselines

| Target | Baseline |
|--------|----------|
| AWS GovCloud (US) | FedRAMP Moderate / NIST SP 800-53, FIPS 140-2/3 endpoints |
| Azure Government | FedRAMP Moderate / NIST SP 800-53, FIPS endpoints |
| Contractor on-prem | NIST SP 800-171 Rev 2/3, CMMC 2.0 Level 2 |

---

## 2. Identity & authentication

- **Local password** — hashed with **bcrypt** via passlib (bcrypt pinned
  `4.0.x` for passlib compatibility). Issues a short-lived **access JWT**
  (`ACCESS_TOKEN_EXPIRE_MINUTES`, default 30) + a **refresh token** that is
  server-tracked, rotated on every use, and revocable (logout revokes the whole
  set; reuse of a rotated token burns the chain — `app/services/refresh_tokens.py`).
- **OIDC** — authorization-code browser flow; ID tokens verified against the
  issuer's JWKS (RS256, audience/issuer/expiry enforced). Enabled only when
  `OIDC_ISSUER` is set; otherwise **fails closed** (`app/services/oidc.py`).
- **SAML 2.0** — SP-initiated Web Browser SSO; the IdP's signed Response/Assertion
  is verified with `signxml`, trusting **only the verified subtree** (mitigating
  XML Signature Wrapping), with audience + validity-window + issuer checks
  (`app/services/saml.py`).
- **CAC/PIV** — mutual-TLS sign-in terminated at a **trusted reverse proxy** that
  forwards the verification status + client cert. Trusted **only** when both
  `CLIENT_CERT_PROXY_AUTH` and `TRUST_PROXY_HEADERS` are set, so headers cannot be
  spoofed on direct connections (`app/services/cac.py`). The app trusts the proxy's
  verdict; it does not itself validate the cert chain — configure the trust chain
  at the proxy (`ssl_client_certificate` / `ssl_verify_client`).
- **MFA** — optional TOTP (`app/services/mfa.py`).
- **Personal Access Tokens** — revocable-instantly tokens for programmatic access
  (`app/services/api_tokens.py`), unlike stateless access JWTs.
- **Login throttling** — audit-log-based: failed logins counted in a rolling
  window (`LOGIN_MAX_FAILURES` / `LOGIN_FAILURE_WINDOW_MINUTES`), keyed by email or
  IP. Reads shared DB state, so it works **across replicas**.

Federation shares one policy: email-domain allowlist (`OIDC_ALLOWED_DOMAINS`),
group→role mapping (`OIDC_GROUP_ROLE_MAP`), default role, and just-in-time
provisioning.

---

## 3. Authorization

- **RBAC** — role-based access enforced on every state-changing endpoint
  (`core/rbac.py`, `core/permissions.py`, `core/iam.py`). Roles carry defaults;
  explicit per-user grants override.
- **Granular module × action permissions** — not just read/write; specific actions
  per module (e.g. disposition, review, publish, close). See
  [`architecture/PERMISSIONS_MODEL.md`](architecture/PERMISSIONS_MODEL.md).
- **Per-record access** — record-level sharing and entity access checks
  (`core/entity_access.py`, `models/record_share.py`) beyond coarse role checks.
- **Deny by default** — an unauthorized request returns `403 permission_denied`;
  federation/authn gaps fail closed.

---

## 4. Data protection

| Concern | Control |
|---------|---------|
| **In transit** | TLS everywhere; HSTS in production (`SecurityHeadersMiddleware`); FIPS-validated endpoints in Gov regions |
| **At rest** | SSE-KMS (S3) / CMK (Azure Blob); encrypted managed PostgreSQL + encrypted snapshots |
| **Key management** | AWS KMS / Azure Key Vault CMKs; FIPS-validated KMS in GovCloud |
| **Secrets** | AWS Secrets Manager / Azure Key Vault; injected via workload identity; never committed (only `.env.example` placeholders ship) |
| **Uploads** | Size-capped (`MAX_UPLOAD_BYTES`); stored via the pluggable storage service; `local` is dev/demo only, never durable for prod |
| **Network** | Databases in private subnets; default-deny NetworkPolicies; WAF at the edge |
| **SSRF guard** | `core/net_guard.py` constrains outbound requests (e.g. webhook targets) |

---

## 5. Auditability

- **Immutable audit log** — append-only record of every mutation (who / what /
  when / before / after), including user agent, via `app/core/audit.py`; exposed to
  admins at `/api/v1/audit-logs` and CSV-exportable.
- **Soft-delete only** — controlled records are never hard-deleted, preserving the
  chain of custody for audits.
- **Electronic signatures** — 21 CFR Part 11-style signing (meaning, signer,
  timestamp, re-authentication) on dispositions/approvals
  (`app/services/signatures.py`; see
  [`compliance/ELECTRONIC_SIGNATURES.md`](compliance/ELECTRONIC_SIGNATURES.md) and
  [`compliance/21-cfr-part-11.md`](compliance/21-cfr-part-11.md)).
- **Request correlation** — every request/response carries `X-Request-ID` for log
  tracing.

---

## 6. Classification & DLP (CUI)

- Sentinel QMS is engineered to store/process/transmit CUI **only inside an
  authorized Gov cloud boundary** (AWS GovCloud / Azure Government).
- The SPA renders a persistent **CUI banner**; do **not** load real ITAR/EAR or
  CUI data into demo/dev environments.
- Export-control handling: [`compliance/itar-ear-export-control.md`](compliance/itar-ear-export-control.md);
  DFARS 252.204-7012 cyber-incident handling:
  [`compliance/dfars-252204-7012.md`](compliance/dfars-252204-7012.md).
- **DLP recommendation** — enforce egress controls at the boundary (deny public
  egress; block backups/exports leaving the partition). Keep any AI inference
  in-boundary via Ollama (see [`DEPLOYMENT.md` §6](DEPLOYMENT.md#6-ollama-optional-self-hosted-llm)).

---

## 7. FIPS readiness

- Deploy in **AWS GovCloud** / **Azure Government** and select **FIPS-validated
  endpoints** for S3, KMS, STS (partition `aws-us-gov`, FIPS regional endpoints).
- Use FIPS-validated KMS/Key Vault CMKs for at-rest encryption.
- Terminate TLS with a FIPS-approved cipher suite at the LB/ingress.
- Password hashing uses bcrypt (KDF), not a FIPS-restricted primitive for storage;
  where a FIPS-140 crypto module is contractually required end-to-end, run on a
  FIPS-enabled host OS/OpenSSL and validate the full stack.

---

## 8. Operator responsibilities

- Set `ENVIRONMENT=production` (activates HSTS + the JWT-secret boot guard).
- Provide a strong unique `JWT_SECRET` (32+ chars) from a secrets manager.
- Keep `ADMIN_AUTO_CREATE=false`; create accounts explicitly and rotate the
  bootstrap admin.
- Set `TRUST_PROXY_HEADERS` / `TRUSTED_PROXY_COUNT` correctly for the proxy chain
  (wrong values enable IP/identity spoofing).
- Use `STORAGE_BACKEND=s3`/`azure_blob` (never `local`) in production.
- Enforce rate limits at the WAF/gateway and set `REDIS_URL` for a shared limiter
  in multi-replica deployments.
- Keep dependency/container/IaC scanning gates green before promoting.
- Configure audit-log retention and centralized log shipping.

---

## 9. Secrets rotation

| Secret | Rotation approach | Impact |
|--------|-------------------|--------|
| `JWT_SECRET` | Rotate in Secrets Manager/Key Vault + restart | Invalidates all live access tokens (emergency use) |
| DB credentials | Managed rotation (Secrets Manager rotation / Key Vault) + rolling restart | Brief reconnect |
| Storage keys (S3/Azure) | Prefer IAM roles / Managed Identity (no static keys to rotate) | None when using workload identity |
| SMTP / webhook secrets | Rotate at source + update secret | Delivery paused until updated |
| Refresh tokens | Rotated automatically on every use; revoked on logout | Transparent |
| Bootstrap admin | Change password after first login; rotate periodically | None |

Prefer **workload identity** (IRSA / Managed Identity) over static credentials so
there is nothing to rotate for cloud service access.

---

## 10. Reporting a vulnerability

Report suspected vulnerabilities **privately** to the maintainer — do not open a
public issue. Include the affected version, reproduction steps, and impact.
Acknowledgement target: **3 business days**. See the root
[`../SECURITY.md`](../SECURITY.md). For a DFARS 252.204-7012 reportable cyber
incident, follow the incident procedure in
[`compliance/dfars-252204-7012.md`](compliance/dfars-252204-7012.md) (72-hour DoD
reporting obligation).

---

## Supply-chain security (CI)

Every change is gated by dependency, container, and IaC scanning: Trivy,
Checkov/tfsec, gitleaks, pip-audit, npm audit, and CodeQL — with OIDC-gated deploys
(no long-lived cloud keys in CI).
