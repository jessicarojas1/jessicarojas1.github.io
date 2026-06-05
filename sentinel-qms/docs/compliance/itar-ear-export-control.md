# ITAR / EAR Export-Control Handling

Sentinel QMS is designed to store and process **ITAR**-controlled (22 CFR §§120–130, USML) and
**EAR**-controlled (15 CFR §§730–774, CCL) technical data within compliant U.S. cloud boundaries. This
document describes how the platform supports an export-control compliance program: data residency, access
control to controlled data, segregation, and logging.

> **Legal note.** Export-control compliance is a legal obligation of the operating organization
> (registration, licensing, technology control plan, U.S.-person determinations, classification). Sentinel
> QMS provides **technical enforcement and evidence**; it does not make legal classification decisions.

---

## 1. Data Residency

| Control | Implementation |
|---------|----------------|
| U.S.-only regions | Deployments are **pinned** to **AWS GovCloud (US)** (`us-gov-west-1`) and **Azure Government** (`usgovvirginia`/`usgovtexas`). No resources are created outside U.S. Gov regions. |
| U.S.-person operations | GovCloud / Azure Gov restrict operational and support access to vetted **U.S. persons**, satisfying a key ITAR requirement that controlled technical data not be accessed by foreign persons. |
| No cross-region replication | Backups, snapshots, and object storage stay in-region (no global/cross-region copy of controlled data). |
| Storage endpoints | S3 VPC gateway endpoints / Azure Blob private endpoints keep data off the public internet and in-region. |
| Key material | KMS / Key Vault keys are region-resident FIPS HSMs; key material never leaves the boundary. |

---

## 2. Access Control to Controlled Data

| Control | Implementation |
|---------|----------------|
| Authentication | OIDC/SAML/CAC-PIV federation; MFA enforced at the IdP; unique accounts |
| Authorization | RBAC least privilege; controlled-data modules gated by permission |
| U.S.-person attribute | The `users` record carries a U.S.-person / export-eligibility attribute (org-populated); access to ITAR-flagged data requires it |
| Need-to-know | Tenant scoping + per-record access; Supplier Quality (external) users restricted to their own records and never to ITAR-flagged data |
| Deny-by-default | No anonymous access; CORS allowlist; private networking |

---

## 3. Segregation of Controlled Data

| Control | Implementation |
|---------|----------------|
| ITAR vs. non-ITAR tenants | Mixing ITAR-controlled and non-controlled tenants in a **single logical deployment is not permitted**. ITAR programs use **single-tenant** dedicated deployments (own account/subscription, database, object store, and KMS keys). |
| Record-level flagging | `organizations.itar_controlled` and per-record export-control flags drive access gating and marking |
| Cryptographic segregation | Per-tenant / per-program KMS keys provide cryptographic isolation |
| Database isolation | PostgreSQL Row-Level Security prevents cross-tenant/cross-program reads even on logic error |
| Object storage | Namespaced keys/containers per program; private, encrypted |

See [../architecture/overview.md](../architecture/overview.md) §5 (multi-tenancy) and
[../architecture/security-architecture.md](../architecture/security-architecture.md) §3.3.

---

## 4. Marking & Handling

| Control | Implementation |
|---------|----------------|
| Visual marking | Persistent **CUI / export-control banner** in the SPA |
| Export markings on output | Exports, prints, and attachments carry export-control / CUI markings per organizational policy |
| Attachment integrity | SHA-256 recorded; randomized storage keys; MIME/extension allowlist |
| Distribution control | Document Control governs controlled distribution and acknowledgements |

---

## 5. Logging & Auditability

| Control | Implementation |
|---------|----------------|
| Access logging | Every read/write to controlled data is logged (actor, action, entity, IP, session, UTC) |
| Immutable trail | Append-only `audit_log`; exported to SIEM with retention |
| Non-repudiation | E-signatures bind identity + meaning + record hash to controlled actions |
| Detection | SIEM alerts on anomalous access to export-flagged records |
| Reporting | Audit-log queries support export-compliance reviews and investigations |

---

## 6. Technology Control Plan (TCP) Support

Sentinel QMS supplies the technical building blocks an organization's TCP relies on:

1. **Boundary** — GovCloud / Azure Gov region pinning and U.S.-person operations.
2. **Access** — federation + MFA + RBAC + U.S.-person attribute gating.
3. **Segregation** — single-tenant ITAR deployments; RLS; per-program keys.
4. **Marking** — banner + output markings.
5. **Monitoring** — immutable audit trail + SIEM.
6. **Evidence** — exportable access reports for audits and license recordkeeping.

---

## 7. Organizational Responsibilities (not software-satisfiable)

- ITAR registration (DDTC) / EAR awareness; licensing and agreements (TAA/MLA) as applicable.
- Export classification of technical data (USML category / ECCN).
- U.S.-person determinations and personnel vetting feeding the user attribute.
- Technology Control Plan, training, and physical security.
- License/agreement recordkeeping and any required government reporting.

---

## 8. Quick Reference

| Requirement | Platform mechanism |
|-------------|--------------------|
| Keep controlled data in the U.S. | Region pinning (GovCloud / Azure Gov), in-region backups |
| Prevent foreign-person access | U.S.-person cloud operations + U.S.-person user attribute gating |
| Segregate programs | Single-tenant ITAR deployments, RLS, per-program KMS keys |
| Prove who accessed what | Immutable audit log + SIEM + e-signatures |
| Encrypt controlled data | TLS in transit + CMK at rest (FIPS HSMs) |
