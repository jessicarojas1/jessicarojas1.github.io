# NIST SP 800-171 Mapping (CUI Protection)

This document maps **NIST SP 800-171** control families to the Sentinel QMS platform implementation. The
mapping covers **all 14 control families** with representative controls and notes CUI handling.
References use Rev 2 identifiers (`3.x.y`); equivalent **Rev 3** requirements (`03.xx.yy`) are noted where
the structure differs. Sentinel QMS provides the **technical** safeguards; organizational policy,
procedures, the System Security Plan (SSP), and POA&M remain the operating organization's responsibility.

> **CUI handling.** When populated with program data, Sentinel QMS stores and processes CUI and
> export-controlled technical data. The frontend displays a persistent **CUI banner**; deployments are
> pinned to AWS GovCloud / Azure Government for data residency; CUI never leaves the validated boundary.

---

## 3.1 Access Control (AC)

| Control | Requirement (abbrev.) | Implementation |
|---------|-----------------------|----------------|
| 3.1.1 | Limit system access to authorized users | JWT auth + RBAC; federation via OIDC/SAML/CAC-PIV; deny-by-default |
| 3.1.2 | Limit access to permitted transactions/functions | `<domain>:<action>` permissions enforced server-side (`require_permission`) |
| 3.1.3 | Control flow of CUI | Tenant scoping + PostgreSQL RLS; private subnets; no CUI egress beyond region |
| 3.1.4 | Separation of duties | Distinct roles; signature-bearing actions separated from authoring |
| 3.1.5 | Least privilege | Role permissions minimal; Admin separate from Quality Manager (no `user:manage` for QM) |
| 3.1.6 | Use non-privileged accounts for non-privileged tasks | Admin role reserved for user/role management only |
| 3.1.7 | Prevent non-privileged users from executing privileged functions; audit | Privileged actions gated by role + audited |
| 3.1.8 | Limit unsuccessful logon attempts | Lockout/throttling at auth layer + WAF rate limiting |
| 3.1.9 | Privacy/security notices (CUI banner) | Persistent CUI banner in SPA |
| 3.1.10 | Session lock | Short access-token lifetime; SPA idle lock |
| 3.1.11 | Session termination | Token expiry + logout `jti` revocation |
| 3.1.12 | Monitor/control remote access | All access via TLS through monitored ingress; logged to SIEM |
| 3.1.13 | Cryptographic protection of remote sessions | TLS 1.2+/FIPS endpoints |
| 3.1.20 | Control connections to external systems | Egress only via NAT; storage via private endpoints; CORS allowlist |
| 3.1.22 | Control CUI on publicly accessible systems | No public data tier; private subnets; banner + access control |

## 3.2 Awareness and Training (AT)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.2.1 | Security awareness | **Supports** — Training & Competency module can hold security-awareness courses with recurrence/expiry |
| 3.2.2 | Role-based training | **Supports** — Courses mapped to roles; completion evidence retained |
| 3.2.3 | Insider-threat awareness | **Supports** — Training records + audit trail |

## 3.3 Audit and Accountability (AU)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.3.1 | Create/retain audit records | Append-only `audit_log` for every mutating action; SIEM export with retention |
| 3.3.2 | Trace actions to individual users | Actor id, session, source IP recorded; e-signatures bind identity |
| 3.3.3 | Review/update audited events | Audited event set defined centrally; reviewable via SIEM |
| 3.3.4 | Alert on audit-process failure | SIEM/monitoring alerts on log pipeline failure |
| 3.3.5 | Correlate audit records | `request_id`/`jti`/session correlation across app and infra logs |
| 3.3.8 | Protect audit information | Append-only table (trigger + grant restriction); SIEM immutability; KMS encryption |
| 3.3.9 | Limit audit management to privileged users | Audit-log read scoped; tamper protection prevents modification |

## 3.4 Configuration Management (CM)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.4.1 | Baseline configurations | Terraform (IaC) + Helm define reproducible baselines |
| 3.4.2 | Enforce security configuration settings | Hardened images, security headers, settings validated by Pydantic |
| 3.4.3 | Track/review/approve changes | Change control via Git + PR review + CI gates; app Change Management module for product/process changes |
| 3.4.5 | Access restrictions for change | Branch protection, signed images, deploy approvals |
| 3.4.6 | Least functionality | Minimal container images; only required ports; docs disabled in prod |
| 3.4.7 | Restrict nonessential programs/ports | Network policy/NSG deny-by-default |
| 3.4.8 | Application allowlisting (deny-by-default exec) | Signed-image-only deploys; read-only containers where feasible |
| 3.4.9 | Control user-installed software | Managed runtime; no end-user software install path |

## 3.5 Identification and Authentication (IA)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.5.1 | Identify users/processes | Unique user accounts; workload identities for services |
| 3.5.2 | Authenticate identities | bcrypt local auth + federated OIDC/SAML/CAC-PIV |
| 3.5.3 | MFA for network/privileged access | **Supports** — Enforced at IdP (Entra ID Gov/Okta) or via CAC/PIV |
| 3.5.4 | Replay-resistant authentication | Nonce/`jti`, short-lived tokens, TLS |
| 3.5.7–3.5.11 | Password complexity/reuse/storage; obscure feedback | bcrypt hashing; complexity policy at IdP/local; masked input |
| 3.5.10 | Store/transmit only cryptographically-protected passwords | Passwords never stored in clear; bcrypt only |

## 3.6 Incident Response (IR)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.6.1 | Incident-handling capability | **Supports** — Operations runbook IR procedures; SIEM detections |
| 3.6.2 | Track/report incidents | **Supports** — DFARS 72-hour reporting workflow (see DFARS mapping) |
| 3.6.3 | Test IR capability | **Supports** — Tabletop/DR exercises documented in operations runbook |

## 3.7 Maintenance (MA)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.7.1–3.7.2 | Perform/control maintenance & tools | Managed cloud services; patch cadence in operations runbook |
| 3.7.4 | Check media for malicious code | Container/dependency scanning in CI |
| 3.7.5 | MFA for nonlocal maintenance | Admin access via MFA-protected IdP |

## 3.8 Media Protection (MP)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.8.1 | Protect media containing CUI | Object storage encrypted (SSE-KMS/CMK), private |
| 3.8.3 | Sanitize media before disposal | KMS key destruction / crypto-shredding; cloud media handling |
| 3.8.4 | Mark media with CUI markings | CUI banner; exports carry markings per org policy |
| 3.8.6 | Cryptographic protection of CUI on portable media | Encryption at rest; controlled export |
| 3.8.9 | Protect backup CUI | Encrypted, access-controlled backups (operations runbook) |

## 3.9 Personnel Security (PS)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.9.1 | Screen personnel | **Supports** — organizational; account lifecycle tied to onboarding |
| 3.9.2 | Protect CUI on termination/transfer | Admin account deactivation; immediate token revocation; role removal |

## 3.10 Physical Protection (PE)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.10.1–3.10.6 | Limit/escort physical access; protect facilities | **Inherited** from AWS GovCloud / Azure Government data centers (FedRAMP-authorized) |

## 3.11 Risk Assessment (RA)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.11.1 | Assess risk to operations/assets | Risk Management module; platform threat model |
| 3.11.2 | Scan for vulnerabilities | CI SAST/SCA/container scanning; cloud vuln management (GuardDuty/Defender) |
| 3.11.3 | Remediate vulnerabilities | Patch SLA + POA&M process (operations runbook) |

## 3.12 Security Assessment (CA)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.12.1 | Periodically assess controls | This documentation set + audit-readiness checklist |
| 3.12.2 | Develop/implement POA&M | **Supports** — organizational POA&M; platform supplies evidence |
| 3.12.3 | Monitor controls continuously | SIEM continuous monitoring; CI gates |
| 3.12.4 | System Security Plan | **Supports** — these docs feed the SSP |

## 3.13 System and Communications Protection (SC)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.13.1 | Monitor/control communications at boundaries | WAF + LB + ingress; segmented subnets |
| 3.13.2 | Architectural security design | C4 architecture; defense-in-depth |
| 3.13.5 | Public-access subnetworks separated | Public/app/data subnet tiers |
| 3.13.6 | Deny-by-default network traffic | Security groups/NSGs deny-all baseline |
| 3.13.8 | Cryptographic protection of CUI in transit | TLS 1.2+/FIPS |
| 3.13.10 | Manage cryptographic keys | AWS KMS / Azure Key Vault, rotation, scoped policies |
| 3.13.11 | FIPS-validated cryptography | KMS/Key Vault FIPS-validated HSMs; FIPS endpoints |
| 3.13.16 | Protect CUI at rest | DB + storage encryption (CMK) |

## 3.14 System and Information Integrity (SI)

| Control | Requirement | Implementation |
|---------|-------------|----------------|
| 3.14.1 | Identify/correct flaws timely | Patch cadence; dependency scanning; CVE response |
| 3.14.2 | Malicious-code protection | Image scanning; managed AV/EDR (GuardDuty/Defender) |
| 3.14.3 | Monitor security alerts/advisories | SIEM + provider security advisories |
| 3.14.4–3.14.5 | Update protection; periodic/real-time scans | Automated scans in CI and runtime |
| 3.14.6 | Monitor systems for attacks | SIEM detections, WAF, GuardDuty/Defender |
| 3.14.7 | Identify unauthorized use | Audit log + anomaly detection |

---

## CUI Data-Flow Controls (Summary)

| Stage | Control |
|-------|---------|
| Ingress | TLS 1.2+/FIPS, WAF, banner |
| Processing | RBAC least privilege; validation; tenant scoping + RLS |
| Storage | CMK encryption (DB + object store), private |
| Audit | Append-only trail → SIEM |
| Egress | No data tier internet route; controlled exports; region pinning |

For the **DFARS 252.204-7012** flow-down (which requires 800-171 implementation and 72-hour incident
reporting) see [dfars-252204-7012.md](dfars-252204-7012.md). For the **CMMC L2** alignment see
[cmmc-l2-mapping.md](cmmc-l2-mapping.md).
