# 21 CFR Part 11 — Electronic Records & Electronic Signatures

Sentinel QMS implements electronic-records and electronic-signature controls modeled on **21 CFR Part 11**
(FDA's rule for trustworthy electronic records and signatures). While aerospace/DoD quality work is not
FDA-regulated, Part 11 is a widely recognized benchmark for record integrity and non-repudiation, and
many primes flow down equivalent expectations. This document maps Part 11 subparts to the platform.

> Applicability: where the operating organization adopts Part 11 as a benchmark, the controls below
> provide conformance. Procedural controls (SOPs, signature meaning policy, training) are organizational.

---

## Subpart B — Electronic Records

### §11.10 Controls for closed systems

| Requirement | Implementation |
|-------------|----------------|
| (a) Validation of systems (accuracy, reliability, consistent performance) | Versioned releases; automated tests (pytest), CI gates; change control; this documentation as part of validation evidence |
| (b) Generate accurate, complete copies for inspection | Records exportable (PDF/JSON) with full content and signature manifests |
| (c) Protection of records (retrieval throughout retention) | PostgreSQL system of record + encrypted backups; retention policy; object versioning |
| (d) Limit system access to authorized individuals | JWT auth + RBAC + federation/MFA |
| (e) **Secure, computer-generated, time-stamped audit trails** (independent of operators; do not obscure prior values) | Append-only `audit_log` with before/after hashes, UTC timestamps, actor; insert-only (trigger + grants); prior values preserved |
| (f) Operational system checks (enforce sequencing) | Workflow state machines (NCR disposition, CAPA 8D, change approval) enforce permitted sequencing |
| (g) Authority checks | RBAC permission checks on every action; signature authority enforced |
| (h) Device checks | API validates source/session; ingress controls |
| (i) Education/training/experience of personnel | **Supports** — Training & Competency module records qualification |
| (j) Written policies holding individuals accountable | **Supports** — policy as controlled document; audit trail enforces accountability |
| (k) Controls over documentation (distribution, change control of system docs) | Document Control + Git/PR change control for system documentation |

### §11.30 Controls for open systems
If accessed across an open network, additional measures (encryption, digital signatures) apply. Sentinel
QMS uses **TLS 1.2+/FIPS** in transit and CMK encryption at rest, and binds signatures with record hashes,
meeting the intent even when accessed over wide-area networks.

### §11.50 Signature manifestations

| Requirement | Implementation |
|-------------|----------------|
| Signed records show printed name of signer | E-signature manifest stores and displays signer identity |
| Date and time of signature | UTC timestamp captured |
| Meaning of the signature (e.g., approval, review, authorship) | `meaning` field (approved/reviewed/authored) captured and displayed |
| Subject to same controls as records; included in copies/printouts | Manifest included in exports and printed reports |

### §11.70 Signature/record linking
Electronic signatures must be **linked to their records** to prevent excision, copying, or transfer to
falsify.

| Implementation |
|----------------|
| Each signature persists a SHA-256 **record hash** binding it to the exact record state at signing; signatures cannot be transplanted to a different record without detection. Audit-trail before/after hashes corroborate. |

---

## Subpart C — Electronic Signatures

### §11.100 General requirements

| Requirement | Implementation |
|-------------|----------------|
| (a) Unique to one individual, not reused/reassigned | Signatures tied to unique user accounts; identifiers not reassigned |
| (b) Identity verification before assigning | **Supports** — organizational identity proofing; account provisioning via Admin/IdP |
| (c) Certification to FDA that e-signatures are legally binding | **Supports** — organizational certification (procedural) |

### §11.200 Electronic signature components and controls

| Requirement | Implementation |
|-------------|----------------|
| (a)(1) Two distinct identification components (e.g., ID + password) for non-biometric | Signing requires authenticated session **plus** re-authentication (password or CAC-PIN) at the moment of signing |
| (a)(1)(i) First signing in a session uses all components | Re-auth required on each signature event |
| (a)(1)(ii) Subsequent signings use at least one component | Active authenticated session + re-auth component |
| (a)(2) Used only by genuine owners | Re-auth secret known only to the signer; bcrypt-verified / CAC-PIN |
| (a)(3) Administration deters falsification by another | RBAC, audit trail, account controls |
| (b) Biometric signatures used only by genuine owner | **Supports** — if CAC/PIV (PKI) used as the component |

### §11.300 Controls for identification codes/passwords

| Requirement | Implementation |
|-------------|----------------|
| (a) Uniqueness of each combined ID/password | Unique accounts; password policy at IdP/local |
| (b) Periodic checks/recalls/revisions | Password rotation policy; account review |
| (c) Loss management (deauthorize lost tokens) | Admin deactivation; immediate token (`jti`) revocation; CAC revocation via IdP |
| (d) Transaction safeguards to prevent unauthorized use; detect/report attempts | Lockout/throttling; SIEM alerting on failed auth/signature attempts |
| (e) Testing of devices/tokens | **Supports** — IdP/CAC token lifecycle managed organizationally |

---

## Implementation Notes

- **Where signatures apply:** document approval, NCR/MRB disposition, CAPA closure, change (ECN/ECO)
  approval, and FAI sign-off. Each is a state-machine-gated, signature-bearing action.
- **Manifest contents:** signer, meaning, UTC timestamp, re-auth method, and SHA-256 record hash, stored
  in the `esignatures` table and surfaced in exports.
- **Audit linkage:** the audit log row for a signing action references the signature and carries
  before/after record hashes, providing an independent, tamper-evident corroboration.
- **Non-repudiation:** because the signature binds identity + meaning + record hash, a signer cannot
  plausibly deny a recorded action, and the record cannot be silently altered post-signature.

---

## Conformance Summary

| Part 11 area | Status |
|--------------|--------|
| §11.10 audit trail (e) | Conformant — append-only, time-stamped, value-preserving |
| §11.10 access/authority (d)(g) | Conformant — RBAC + federation |
| §11.50 manifestations | Conformant — name, date/time, meaning in record & copies |
| §11.70 linking | Conformant — record-hash binding |
| §11.200 two components | Conformant — session + re-auth at signing |
| §11.300 ID/password controls | Conformant (technical) — policy items organizational |
| §11.100(b)(c) identity proofing/certification | Organizational responsibility |
