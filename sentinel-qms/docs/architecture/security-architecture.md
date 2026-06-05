# Security Architecture

This document describes the security architecture of Sentinel QMS: authentication and authorization, the
RBAC permission matrix, encryption and key management, the immutable audit log, electronic signatures,
network segmentation, secrets management, and FIPS posture. It is intended both as engineering reference
and as assessment evidence for **CMMC 2.0 Level 2 / NIST SP 800-171** and **FedRAMP Moderate / NIST SP
800-53**. Control-level mappings live under [../compliance/](../compliance/).

---

## 1. Security Objectives

| Objective | Approach |
|-----------|----------|
| Confidentiality of CUI / export-controlled data | Encryption at rest + in transit; least-privilege RBAC; private networking; data residency in GovCloud/Azure Gov |
| Integrity of quality records | Transactional writes; append-only audit log; signed records; SHA-256 record hashes |
| Availability | Multi-AZ database, horizontally scalable stateless API, backups + DR |
| Accountability / non-repudiation | Immutable audit trail; 21 CFR Part 11 e-signatures bound to record hashes |
| Assurance | IaC, signed images, SBOM, automated SAST/dependency/secret/container scanning in CI |

---

## 2. Authentication (AuthN)

Sentinel QMS uses **internal JWTs** issued after a successful primary authentication, decoupling the
application from any single identity provider.

### 2.1 Local authentication
- Passwords are hashed with **bcrypt** via `passlib` (`app/core/security.py`). Verification is
  constant-time and tolerant of malformed hashes.
- On login, the API issues a short-lived **access token** (`ACCESS_TOKEN_EXPIRE_MINUTES`, default 30) and
  a longer-lived **refresh token** (`REFRESH_TOKEN_EXPIRE_DAYS`, default 7). Tokens are signed with
  `JWT_SECRET` using `JWT_ALGORITHM` (HS256 by default; RS256/ES256 with a KMS-held key recommended in
  production).
- Every token carries `sub`, `type` (access/refresh), `iat`, `exp`, and a unique `jti` for revocation and
  correlation. Roles are embedded in access tokens as the `roles` claim.

### 2.2 Federated authentication (pluggable)
- **OIDC / SAML** integration with enterprise IdPs (Entra ID Gov, Okta, ADFS) and **DoD ICAM**.
- **CAC / PIV** smartcard authentication via mutual-TLS client certificates / PIV-auth certificates.
- The federation adapter (`verify_oidc_token`) validates issuer/audience and the IdP JWKS, then maps
  external subject claims to a local `users` record and issues an internal JWT. Federation is enabled by
  configuring `OIDC_ISSUER`, `OIDC_CLIENT_ID`, and `OIDC_CLIENT_SECRET`; absent configuration the path
  fails closed (no false security).
- `auth_source` on the user record records the authentication origin (local, oidc, saml, cac_piv) for
  audit and policy decisions.

### 2.3 Session & token hygiene
- Tokens are bearer credentials transmitted only over TLS; the SPA stores them in memory and never logs
  them. The CUI banner and short access-token lifetime limit exposure.
- Refresh rotation and `jti`-based denylist support immediate revocation on logout or compromise.

---

## 3. Authorization (AuthZ) — RBAC

Authorization is enforced server-side by FastAPI dependencies (`require_permission`, `require_roles`) in
`app/core/rbac.py`. Permissions follow a `<domain>:<action>` convention; roles are bundles of
permissions. **The frontend never makes authorization decisions** — it only adapts the UI; every request
is independently authorized at the API.

### 3.1 Roles
Admin · Quality Manager · Quality Engineer · Auditor · Supplier Quality · Operator · Read-Only.

### 3.2 RBAC Permission Matrix

Legend: **R** = read, **W** = create/edit, **A** = approve/disposition/close (signature-bearing), — = no
access.

| Module / Capability | Admin | Quality Mgr | Quality Eng | Auditor | Supplier Quality | Operator | Read-Only |
|---------------------|:-----:|:-----------:|:-----------:|:-------:|:----------------:|:--------:|:---------:|
| User & Role Management | W | — | — | — | — | — | — |
| Document & Records Control | R/W/A | R/W/A | R/W/A | R | R | R | R |
| Nonconformance (NCR) | R/W/A | R/W/A | R/W/A | R | R/W | R/W | R |
| MRB Disposition | A | A | A | R | — | — | R |
| CAPA (8D) | R/W/A | R/W/A | R/W/A | R | R/W | R | R |
| Audit Management | R/W | R/W | R | R/W | R | R | R |
| Supplier Quality (ASL/SCAR/Ratings) | R/W | R/W | R | R | R/W | R | R |
| Calibration & Equipment | R/W | R/W | R/W | R | R | R | R |
| Training & Competency | R/W | R/W | R | R | R | R | R |
| Change Management (ECN/ECO) | R/W | R/W | R/W | R | R | R | R |
| Risk Management | R/W | R/W | R/W | R | R | R | R |
| Inspection & FAI | R/W | R/W | R/W | R | R | R/W | R |
| Management Review | R/W | R/W | R | R | — | — | R |
| Customer Complaints / RMA | R/W | R/W | R/W | R | R | R | R |
| Dashboard / KPIs | R | R | R | R | R | R | R |

> This matrix is the human-readable rendering of `ROLE_PERMISSIONS` in `app/core/rbac.py`. The Admin role
> holds all permissions; Quality Manager holds all except `user:manage`. **Supplier Quality** is further
> scoped at the data layer to the supplier(s) a user represents.

### 3.3 Tenant & data scoping
Beyond role permissions, every query is filtered by the authenticated user's `organization_id` (injected
by a dependency, never client-supplied) and reinforced by **PostgreSQL Row-Level Security**. Supplier
Quality (external) users are additionally constrained to their own supplier records via row-level policy.

---

## 4. Encryption

### 4.1 In transit
- All external traffic uses **TLS 1.2+** (TLS 1.3 preferred), terminated at the cloud load balancer /
  ingress using **FIPS-validated endpoints** in GovCloud and TLS 1.2+ enforced in Azure Gov.
- Intra-cluster traffic between ingress, API, and worker pods stays within private subnets; mTLS is
  available via the service mesh / ingress where required.
- Database connections require TLS (`sslmode=require`/`verify-full` in production).

### 4.2 At rest
- **Database:** RDS for PostgreSQL (GovCloud) / Azure Database for PostgreSQL (Azure Gov) with storage
  encryption using **AWS KMS** / **Azure Key Vault** customer-managed keys (CMK).
- **Object storage:** S3 **SSE-KMS** / Azure Blob CMK encryption; buckets/containers are private with no
  anonymous access and TLS-only bucket policies.
- **Backups & snapshots** inherit KMS/Key Vault encryption.

---

## 5. Key Management

| Concern | GovCloud | Azure Gov |
|---------|----------|-----------|
| Key store | AWS KMS (FIPS 140-2/3 validated HSM) | Azure Key Vault (FIPS 140-2 validated HSM) |
| Key types | Customer-managed CMKs per environment; optional per-tenant keys | CMKs per environment; optional per-tenant keys |
| Rotation | Automatic annual rotation enabled | Rotation policy enabled |
| Access | Key policies grant decrypt only to the API/worker workload identities (IRSA / Workload Identity) | RBAC + Key Vault access policies scoped to managed identities |
| Signing keys (JWT, prod) | KMS asymmetric key for RS256/ES256 (recommended over HS256 shared secret) | Key Vault key for asymmetric JWT signing |

KMS/Key Vault audit logs are forwarded to the SIEM. No key material ever leaves the HSM boundary.

---

## 6. Immutable Audit Logging

- A single `audit_log` table records actor, action, entity type/id, before/after SHA-256 hashes, source
  IP, session id, and UTC timestamp for **every mutating operation**.
- The table is **append-only**: a `BEFORE UPDATE OR DELETE` trigger raises, and the application DB role
  holds only `INSERT, SELECT`. Administrative deletion requires a separate break-glass role with its own
  logging.
- Business write + audit insert share one transaction (atomic, non-divergent).
- Logs are exported to the SIEM (CloudWatch + Security Hub / Azure Monitor + Sentinel) with retention per
  policy (see [../deployment/operations-runbook.md](../deployment/operations-runbook.md)). This satisfies
  NIST 800-53 **AU** and 800-171 **3.3.x** audit-and-accountability requirements.

---

## 7. Electronic Signatures (21 CFR Part 11 style)

- Signature-bearing actions (document approval, NCR/MRB disposition, CAPA closure, change approval, FAI
  sign-off) require **re-authentication** at the moment of signing (password or CAC-PIN).
- Each signature persists a manifest in `esignatures`: signer identity, **meaning** of the signature
  (approved/reviewed/authored), UTC timestamp, re-auth method, and a SHA-256 **record hash** binding the
  signature to the exact record state.
- Signatures are linked inseparably to their records and appear in printed/exported manifests, satisfying
  21 CFR Part 11 §11.50 (signature manifestations) and §11.70 (record/signature linking). Full mapping in
  [../compliance/21-cfr-part-11.md](../compliance/21-cfr-part-11.md).

---

## 8. Network Segmentation

```
Internet ─► WAF/Shield ─► Load Balancer (public subnet, TLS/FIPS)
                              │
                       Private/App subnet  ── API pods, Worker pods, SPA pods (EKS/AKS)
                              │
                       Isolated Data subnet ── PostgreSQL (no route to internet)
```

- **Three subnet tiers** per AZ/zone: public (ingress + NAT only), private/app (compute), isolated data
  (database). VPC/VNet CIDR `10.40.0.0/16` (see Terraform `network` module).
- Egress to the internet is only via NAT gateways from the app tier; the data tier has **no** internet
  route.
- Object storage is reached over **VPC gateway endpoints** (S3) / **private endpoints** (Blob), keeping
  traffic off the public internet.
- Security groups / NSGs implement least-privilege: LB→API on the app port, API→DB on 5432, deny-all
  otherwise. WAF protects against common web attacks.

---

## 9. Secrets Management

- No secrets in source control. `.env` files are git-ignored; only `.env.example` with placeholders is
  committed.
- Production secrets (DB credentials, JWT signing key/secret, OIDC client secret, storage credentials)
  are stored in **AWS Secrets Manager** / **Azure Key Vault** and injected at runtime via the External
  Secrets Operator / CSI driver — never baked into images.
- Workload identity (IRSA on EKS / Workload Identity on AKS) replaces static cloud credentials wherever
  possible.
- Secret rotation is supported; rotation events are audited via the cloud provider's CloudTrail / Activity
  Log forwarded to the SIEM.

---

## 10. Application-Layer Hardening

| Control | Implementation |
|---------|----------------|
| SQL injection | SQLAlchemy 2.0 parameterized queries only; no string-concatenated SQL |
| Input validation | Pydantic v2 schemas validate and type-coerce every request body/query |
| Output handling | JSON responses; SPA renders via React (auto-escaping); no server-rendered HTML injection surface |
| CSRF | Token-based auth (bearer JWT) rather than ambient cookies; SameSite settings where cookies used |
| CORS | Explicit allowlist via `CORS_ORIGINS`; deny-by-default |
| Rate limiting | At ingress/WAF and per-principal at the API gateway layer |
| File uploads | MIME validation, extension allowlist, size cap (`MAX_UPLOAD_BYTES`), randomized stored filenames, SHA-256 recorded |
| Security headers | HSTS, X-Content-Type-Options, X-Frame-Options/CSP, Referrer-Policy at nginx ingress |
| Error handling | Centralized exception handlers (`app/core/exceptions.py`) avoid leaking stack traces in production |

---

## 11. FIPS Posture

- **GovCloud:** all AWS service calls use FIPS endpoints (`*.us-gov-west-1.amazonaws.com` FIPS variants);
  KMS uses FIPS 140-2/3 validated HSMs; TLS terminates on FIPS-validated load balancers.
- **Azure Gov:** Key Vault uses FIPS 140-2 validated HSMs; platform crypto is FIPS-validated; TLS 1.2+
  enforced.
- Container base images select FIPS-capable crypto where the workload performs cryptographic operations;
  the heavy cryptography (TLS, KMS, storage encryption) is delegated to validated cloud services.

---

## 12. Vulnerability & Supply-Chain Management

- CI runs **SAST**, **dependency/SCA**, **secret scanning**, and **container image scanning** on every PR;
  builds fail on policy violations.
- Images are **signed (cosign)** and ship with an **SBOM**; only signed images deploy.
- Patch cadence and CVE response are defined in
  [../deployment/operations-runbook.md](../deployment/operations-runbook.md).

---

## 13. Mapping to Compliance

| Area | See |
|------|-----|
| NIST SP 800-171 control families | [../compliance/nist-800-171-mapping.md](../compliance/nist-800-171-mapping.md) |
| CMMC 2.0 Level 2 domains | [../compliance/cmmc-l2-mapping.md](../compliance/cmmc-l2-mapping.md) |
| NIST SP 800-53 / FedRAMP Moderate | [../compliance/nist-800-53-control-summary.md](../compliance/nist-800-53-control-summary.md) |
| DFARS 252.204-7012 | [../compliance/dfars-252204-7012.md](../compliance/dfars-252204-7012.md) |
| ITAR / EAR | [../compliance/itar-ear-export-control.md](../compliance/itar-ear-export-control.md) |
| 21 CFR Part 11 | [../compliance/21-cfr-part-11.md](../compliance/21-cfr-part-11.md) |
