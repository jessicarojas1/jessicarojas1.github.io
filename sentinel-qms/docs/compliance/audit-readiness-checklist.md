# Audit Readiness Checklist

This checklist prepares a Sentinel QMS deployment for an **AS9100D certification audit** (Stage 1/Stage 2)
and a **CMMC 2.0 Level 2 assessment** (C3PAO). Use it as a self-assessment before engaging a registrar or
assessor. Each item notes the **evidence source** within the platform or organization.

> Status key: ☐ not started · ◐ in progress · ☑ complete

---

## Part A — AS9100D / ISO 9001:2015 Certification Readiness

### A1. QMS Foundation (Clauses 4–5)
- ☐ Quality Manual / QMS scope is a controlled, approved document — *Document Control*
- ☐ Quality policy released and acknowledged by personnel — *Document Control / acknowledgements*
- ☐ Roles, responsibilities, authorities defined — *RBAC role assignments*
- ☐ Process interactions documented (NCR→CAPA, Audit→Finding→CAPA) — *module linkages*

### A2. Planning (Clause 6)
- ☐ Risk register populated with assessments (RPN, controls, residual) — *Risk Management*
- ☐ Quality objectives defined and measurable — *Dashboard/KPIs*
- ☐ Change planning process in use — *Change Management (ECN/ECO)*

### A3. Support (Clause 7)
- ☐ Document control: versioning, approval e-signatures, retention configured — *Document Control*
- ☐ Calibration: M&TE register complete, intervals set, NIST-traceable certs attached, no overdue items — *Calibration*
- ☐ Training: required courses mapped to roles; no expired critical competencies — *Training*
- ☐ Awareness acknowledgements on released documents — *Document acknowledgements*

### A4. Operation (Clause 8)
- ☐ Supplier control: ASL current, supplier statuses accurate, SCARs tracked, ratings calculated — *Supplier Quality*
- ☐ Nonconformance: NCRs dispositioned with justification; MRB records for use-as-is/repair — *Nonconformance/MRB*
- ☐ FAI: AS9102 Forms 1/2/3 complete for applicable parts; characteristic accountability done — *Inspection & FAI*
- ☐ Inspection/release records with sign-off — *Inspection*
- ☐ Traceability: part number + lot/serial captured throughout — *all modules*

### A5. Performance Evaluation (Clause 9)
- ☐ Internal audit program executed; findings classified (major/minor/observation/OFI) — *Audit Management*
- ☐ Open findings linked to CAPAs — *Audit → CAPA linkage*
- ☐ Customer satisfaction data captured — *Complaints/RMA, KPIs*
- ☐ Management review held with all required inputs and output actions — *Management Review*

### A6. Improvement (Clause 10)
- ☐ CAPA (8D) records show containment, root cause, corrective/preventive actions, effectiveness verification — *CAPA*
- ☐ CAPA on-time closure KPI trending — *Dashboard*
- ☐ Continual-improvement actions from management review tracked — *Management Review actions*

### A7. Audit Logistics
- ☐ Read-only auditor account provisioned (Auditor role) — *RBAC*
- ☐ Record exports (PDF) tested for documents, NCRs, CAPAs, FAIs, audits — *export feature*
- ☐ Objective evidence readily retrievable by record number — *search/numbering*

---

## Part B — CMMC 2.0 Level 2 Assessment Readiness

### B1. Scoping & Documentation
- ☐ CUI boundary documented (DB, object storage, backups, SIEM; in-region) — *deployment docs*
- ☐ Asset categorization complete (CUI / Security Protection / CRMA / Specialized) — *org*
- ☐ System Security Plan (SSP) current, covers all 110 practices — *org (this doc set as input)*
- ☐ POA&M maintained for any not-yet-implemented practices — *org*
- ☐ SPRS score submitted — *org*

### B2. Access Control (AC)
- ☐ RBAC least privilege verified against role matrix — *security-architecture.md §3*
- ☐ MFA enforced for all users (IdP / CAC-PIV) — *IdP config*
- ☐ Session lock/termination and lockout configured — *auth config*
- ☐ CUI banner displayed — *SPA*

### B3. Audit & Accountability (AU)
- ☐ Audit logging on for all mutating actions; exported to SIEM — *audit_log + SIEM*
- ☐ Audit-log immutability verified (UPDATE/DELETE blocked) — *DB trigger/grants test*
- ☐ Retention policy meets requirement — *operations runbook*

### B4. Identification & Authentication (IA)
- ☐ Unique accounts; no shared credentials — *user management*
- ☐ Password storage = bcrypt; complexity at IdP — *security config*
- ☐ Federation (OIDC/SAML/CAC-PIV) configured and tested — *IdP*

### B5. Configuration Management (CM)
- ☐ IaC baselines under version control; drift monitored — *Terraform/Helm*
- ☐ Signed-image-only deploys enforced; SBOM produced — *CI/CD*
- ☐ Change control with PR review + approvals — *Git/CI*

### B6. System & Communications Protection (SC)
- ☐ TLS 1.2+/FIPS endpoints verified — *ingress/LB*
- ☐ CMK encryption at rest (DB + storage) verified — *KMS/Key Vault*
- ☐ Network segmentation (public/app/data) verified; data tier has no internet route — *Terraform network*
- ☐ Deny-by-default security groups/NSGs — *IaC*

### B7. System & Information Integrity (SI)
- ☐ Vulnerability scanning (CI + runtime) active; findings remediated within SLA — *CI + GuardDuty/Defender*
- ☐ Malware protection / EDR enabled — *cloud provider*
- ☐ Input validation enforced (Pydantic) — *API*

### B8. Incident Response (IR)
- ☐ IR plan documented; 72-hour DFARS reporting workflow rehearsed — *operations runbook*
- ☐ SIEM detections and alerting validated — *SIEM*
- ☐ Media preservation (≥90 days) capability verified — *backups/snapshots*

### B9. Risk & Assessment (RA/CA)
- ☐ Risk assessment current — *Risk module + threat model*
- ☐ Continuous monitoring evidence available — *SIEM + CI*
- ☐ Annual affirmation prepared — *org*

### B10. Inherited Controls
- ☐ Cloud provider Customer Responsibility Matrix obtained (GovCloud / Azure Gov) — *org*
- ☐ Physical/environmental controls documented as inherited — *provider FedRAMP package*

---

## Part C — Cross-Cutting Evidence Pack

- ☐ RBAC matrix export — *security-architecture.md §3.2*
- ☐ Audit-log sample (mutating action with before/after hash) — *audit_log*
- ☐ E-signature manifest sample — *esignatures*
- ☐ Encryption/key configuration screenshots — *KMS/Key Vault*
- ☐ Network diagram — *architecture-diagram.md*
- ☐ Backup/restore test record + RPO/RTO — *operations runbook*
- ☐ CI run showing SAST/SCA/secret/container scans + image signing — *GitHub Actions*
- ☐ Control mapping documents — *this directory*

---

## Sign-off

| Role | Name | Date | Result |
|------|------|------|--------|
| Quality Manager (AS9100) | | | |
| ISSO / Security Lead (CMMC) | | | |
| System Owner | | | |
