# NIST SP 800-53 Control Summary (FedRAMP Moderate)

This document summarizes how Sentinel QMS and its Infrastructure-as-Code (IaC) address the relevant
**NIST SP 800-53 Rev 5** control families at the **FedRAMP Moderate** baseline. It is organized by family
and identifies controls satisfied by the application, by the IaC/cloud platform, or **inherited** from the
underlying FedRAMP-authorized cloud (AWS GovCloud / Azure Government). This summary supports a System
Security Plan (SSP) and is not itself an Authorization to Operate (ATO).

> **Inheritance.** AWS GovCloud and Azure Government carry FedRAMP authorizations. Many physical (PE),
> environmental, and infrastructure controls are **inherited**; obtain the provider Customer
> Responsibility Matrix (CRM) and combine it with the application/IaC controls below.

---

## AC — Access Control

| Control | Title | How addressed |
|---------|-------|---------------|
| AC-2 | Account Management | Admin-managed accounts; lifecycle (create/disable/delete); workload identities for services |
| AC-3 | Access Enforcement | RBAC enforced server-side (`require_permission`/`require_roles`) |
| AC-4 | Information Flow Enforcement | Tenant scoping + PostgreSQL RLS; segmented subnets; deny-by-default NSGs |
| AC-5 | Separation of Duties | Distinct roles; Admin (user mgmt) separated from Quality Manager |
| AC-6 | Least Privilege | Minimal role permission sets; workload identities scoped to needed keys |
| AC-7 | Unsuccessful Logon Attempts | Lockout/throttling + WAF |
| AC-11/AC-12 | Session Lock / Termination | Short token TTL, idle lock, logout revocation |
| AC-17 | Remote Access | TLS-only access via monitored ingress; logged |
| AC-19/AC-20 | Mobile Devices / External Systems | CORS allowlist; private endpoints; org MDM policy |

## AU — Audit and Accountability

| Control | Title | How addressed |
|---------|-------|---------------|
| AU-2 | Event Logging | Defined auditable events for all mutating actions |
| AU-3 | Content of Audit Records | Actor, action, entity, before/after hash, IP, session, UTC time |
| AU-4 | Audit Storage Capacity | SIEM with scaling + retention policy |
| AU-6 | Audit Review/Analysis/Reporting | SIEM dashboards/alerts (Security Hub / Sentinel) |
| AU-8 | Time Stamps | UTC, NTP-synced platform clocks |
| AU-9 | Protection of Audit Information | Append-only table (trigger + grant restriction); SIEM immutability; KMS encryption |
| AU-11 | Audit Record Retention | Retention per policy (operations runbook) |
| AU-12 | Audit Generation | Generated at app + infra layers |

## IA — Identification and Authentication

| Control | Title | How addressed |
|---------|-------|---------------|
| IA-2 | Identification & Authentication (org users) | Federated OIDC/SAML/CAC-PIV; unique accounts |
| IA-2(1)(2) | MFA | Enforced at IdP / via CAC-PIV |
| IA-4 | Identifier Management | UUID user IDs; email identifiers |
| IA-5 | Authenticator Management | bcrypt storage; complexity at IdP/local; rotation |
| IA-7 | Cryptographic Module Authentication | FIPS-validated crypto for auth/TLS |
| IA-8 | Non-organizational Users | Scoped supplier portal identities |

## SC — System and Communications Protection

| Control | Title | How addressed |
|---------|-------|---------------|
| SC-7 | Boundary Protection | WAF + LB + ingress; public/app/data subnet tiers |
| SC-8 | Transmission Confidentiality/Integrity | TLS 1.2+/FIPS in transit |
| SC-12/SC-13 | Key Establishment / Cryptographic Protection | AWS KMS / Azure Key Vault; FIPS-validated |
| SC-23 | Session Authenticity | Signed JWT, `jti`, TLS |
| SC-28 | Protection of Information at Rest | DB + object storage CMK encryption |
| SC-5 | Denial of Service Protection | WAF/Shield, rate limiting, autoscaling |

## SI — System and Information Integrity

| Control | Title | How addressed |
|---------|-------|---------------|
| SI-2 | Flaw Remediation | Patch cadence; dependency scanning; CVE response |
| SI-3 | Malicious Code Protection | Image scanning; GuardDuty/Defender |
| SI-4 | System Monitoring | SIEM detections, WAF, anomaly alerts |
| SI-5 | Security Alerts/Advisories | Provider advisories + SCA feeds |
| SI-7 | Software/Information Integrity | Signed images (cosign), SBOM, record hashes, append-only audit |
| SI-10 | Information Input Validation | Pydantic validation on all inputs |

## CM — Configuration Management

| Control | Title | How addressed |
|---------|-------|---------------|
| CM-2 | Baseline Configuration | Terraform + Helm |
| CM-3 | Configuration Change Control | Git PR review + CI gates + deploy approvals |
| CM-5 | Access Restrictions for Change | Branch protection; signed-image-only deploy |
| CM-6 | Configuration Settings | Hardened images; validated settings; security headers |
| CM-7 | Least Functionality | Minimal images; ports restricted; docs off in prod |
| CM-8 | System Component Inventory | IaC state + SBOM |

## CP — Contingency Planning

| Control | Title | How addressed |
|---------|-------|---------------|
| CP-9 | System Backup | Automated DB snapshots + object versioning, encrypted |
| CP-10 | System Recovery & Reconstitution | DR procedure, RPO/RTO targets (operations runbook) |
| CP-2 | Contingency Plan | Documented DR/BCP in operations runbook |

## IR — Incident Response

| Control | Title | How addressed |
|---------|-------|---------------|
| IR-4 | Incident Handling | Runbook + SIEM playbooks |
| IR-6 | Incident Reporting | DFARS 72-hr reporting workflow |
| IR-8 | Incident Response Plan | Documented IRP (operations runbook) |

## RA — Risk Assessment

| Control | Title | How addressed |
|---------|-------|---------------|
| RA-3 | Risk Assessment | Threat model + Risk module |
| RA-5 | Vulnerability Monitoring/Scanning | CI SAST/SCA/container + cloud vuln mgmt |

## CA — Assessment, Authorization, Monitoring

| Control | Title | How addressed |
|---------|-------|---------------|
| CA-2 | Control Assessments | This doc set + readiness checklist |
| CA-5 | Plan of Action & Milestones | Org POA&M (platform supplies evidence) |
| CA-7 | Continuous Monitoring | SIEM + CI gates + patch cadence |

## AT, MP, PE, PS, MA (Summary)

| Family | Coverage |
|--------|----------|
| AT (Awareness & Training) | Training & Competency module hosts security courses; org owns the program |
| MP (Media Protection) | Encrypted, private object storage; crypto-shredding via KMS key destruction |
| PE (Physical & Environmental) | **Inherited** from FedRAMP-authorized GovCloud / Azure Gov data centers |
| PS (Personnel Security) | Account lifecycle & revocation; org screening |
| MA (Maintenance) | Managed services; patch cadence; scanned tooling |

---

## Responsibility Model

| Layer | Owner |
|-------|-------|
| Application controls (AC, AU app-side, IA, SI-10, SC-23) | Sentinel QMS |
| IaC/cloud controls (SC-7, SC-28, CM, CP) | Sentinel QMS IaC + org |
| Inherited controls (PE, infra MA/MP) | Cloud provider (CRM) |
| Governance (CA, RA-3 program, PS, AT program) | Operating organization |

For the CUI-specific 800-171 control text see [nist-800-171-mapping.md](nist-800-171-mapping.md).
