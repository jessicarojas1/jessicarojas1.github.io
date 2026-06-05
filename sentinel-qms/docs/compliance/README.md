# Compliance Program Overview

Sentinel QMS is engineered to operate inside regulated defense and aerospace boundaries and to serve as
**objective evidence** during external assessments. This document summarizes the compliance program and
how the platform supports each standard. Detailed control-by-control mappings are in the companion
documents in this directory.

> **Scope note.** Sentinel QMS is a *software platform*. Achieving and maintaining certification or
> authorization is a **shared responsibility** between the platform (technical controls implemented here)
> and the operating organization (policies, procedures, personnel, training, physical security, and
> assessment execution). The mappings below identify what the platform provides and where organizational
> action is required.

---

## 1. Standards & Frameworks Addressed

| Standard / Framework | Domain | Platform's role | Mapping document |
|----------------------|--------|-----------------|------------------|
| **AS9100D** (with **ISO 9001:2015** / **ISO 9000**) | Aerospace QMS | Implements the quality processes (clauses 4–10) as modules with records, approvals, and metrics | [as9100d-clause-mapping.md](as9100d-clause-mapping.md) |
| **NIST SP 800-171 Rev 2 / Rev 3** | CUI protection | Technical safeguards across all 14 control families | [nist-800-171-mapping.md](nist-800-171-mapping.md) |
| **CMMC 2.0 Level 2** | DIB cybersecurity certification | Implements the 14 domains aligned to 800-171; assessment-readiness evidence | [cmmc-l2-mapping.md](cmmc-l2-mapping.md) |
| **NIST SP 800-53** (FedRAMP **Moderate**) | Cloud authorization | Control families (AC, AU, IA, SC, SI, CM, CP, IR, RA, …) via app + IaC | [nist-800-53-control-summary.md](nist-800-53-control-summary.md) |
| **ITAR / EAR** | Export control | Data residency, access segregation, logging for controlled technical data | [itar-ear-export-control.md](itar-ear-export-control.md) |
| **DFARS 252.204-7012** | CUI safeguarding clause | 800-171 implementation, incident reporting support, cloud requirements | [dfars-252204-7012.md](dfars-252204-7012.md) |
| **FAR** | Federal acquisition | Recordkeeping, traceability, supplier/quality controls supporting flow-downs | (covered across mappings) |
| **21 CFR Part 11** | Electronic records & signatures | Immutable audit trail + bound e-signatures | [21-cfr-part-11.md](21-cfr-part-11.md) |
| **AS9102** | First Article Inspection | FAI module with Forms 1/2/3 and characteristic accountability | [as9100d-clause-mapping.md](as9100d-clause-mapping.md) (cl. 8.5.1) |
| **AS9101** | Aerospace audit | Audit Management module supporting QMS audits | [as9100d-clause-mapping.md](as9100d-clause-mapping.md) (cl. 9.2) |

---

## 2. How the Platform Supports Compliance

### 2.1 Quality (AS9100D / ISO 9001:2015)
Every clause-4-through-10 process is represented by a module that produces controlled, auditable records:
document control, nonconformance and MRB, CAPA (8D), internal/supplier/certification audits, supplier
quality (ASL/SCAR/ratings), calibration of M&TE, training & competency, change management, risk-based
thinking, inspection and First Article (AS9102), management review, and customer complaints/RMA. The
Dashboard/KPI module supplies the performance data management review and continual improvement require.

### 2.2 Cybersecurity (800-171 / CMMC L2 / 800-53)
The platform implements technical safeguards: RBAC least privilege, MFA-capable federation (OIDC/SAML/
CAC-PIV), encryption in transit and at rest with KMS/Key Vault, immutable audit logging exported to a
SIEM, network segmentation in private subnets, secrets management, FIPS-validated cryptography, and a
hardened CI/CD pipeline (SAST, SCA, secret/container scanning, signed images, SBOM).

### 2.3 Export control (ITAR / EAR / DFARS)
Deployments are pinned to **AWS GovCloud (US)** / **Azure Government** for data residency, with U.S.-person
access controls, tenant/data segregation, and comprehensive access logging for ITAR-controlled technical
data.

### 2.4 Electronic records & signatures (21 CFR Part 11)
Records are protected by an append-only audit trail and signature manifests that bind signer identity,
meaning, timestamp, and a record hash to each controlled action, supporting non-repudiation.

---

## 3. Shared-Responsibility Summary

| Layer | Provided by Sentinel QMS | Provided by the operating organization |
|-------|--------------------------|----------------------------------------|
| Application controls | RBAC, validation, audit log, e-signatures, workflows | Role assignment governance, segregation of duties policy |
| Data protection | Encryption, key usage, private networking (IaC) | Key-policy approval, data classification, CUI marking |
| Identity | Federation hooks, JWT, session controls | IdP, MFA enforcement, account lifecycle, CAC/PIV issuance |
| Operations | Backups/DR tooling, monitoring hooks, runbooks | DR exercises, monitoring response, patch authorization |
| Governance | Records and evidence generation | Quality manual, procedures, training, audits, SSP/POA&M |

---

## 4. Evidence Catalog (for assessors)

| Evidence | Where it lives |
|----------|----------------|
| QMS process records (NCR, CAPA, audits, FAI, etc.) | Respective modules + exports |
| Access-control configuration | RBAC matrix ([../architecture/security-architecture.md](../architecture/security-architecture.md)) |
| Audit trail | `audit_log` + SIEM exports |
| Electronic signatures | `esignatures` + printed manifests |
| Encryption & key management | KMS/Key Vault config + IaC |
| Network design | Terraform `network` module + deployment runbooks |
| CI/CD assurance | GitHub Actions logs, SBOMs, signatures |
| Backups & DR | Operations runbook + snapshot inventory |
| Control mappings | This directory |
| Readiness checklist | [audit-readiness-checklist.md](audit-readiness-checklist.md) |
