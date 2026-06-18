# Sentinel QMS — Documentation

**Sentinel QMS** is a standalone, enterprise-grade Quality Management System (QMS) engineered for
aerospace, manufacturing, and U.S. Department of Defense (DoD) supply-chain organizations. It is
designed to be deployed into **AWS GovCloud (US)** and **Microsoft Azure Government**, and to operate
inside boundaries that handle **Controlled Unclassified Information (CUI)** and **ITAR/EAR**-controlled
technical data.

This documentation set supports day-to-day operation of the platform **and** serves as evidence for
external assessments, including **AS9100D / ISO 9001:2015 certification audits** and **CMMC 2.0
Level 2 assessments**.

---

## Document Index

### Architecture
| Document | Description |
|----------|-------------|
| [architecture/overview.md](architecture/overview.md) | C4 context & container view, technology stack, request lifecycle, multi-tenant model |
| [architecture/architecture-diagram.md](architecture/architecture-diagram.md) | Mermaid diagrams: system context, containers, AWS GovCloud & Azure Gov deployment, data flow |
| [architecture/data-model.md](architecture/data-model.md) | Entity-relationship overview, table-by-table data dictionary, record numbering scheme |
| [architecture/security-architecture.md](architecture/security-architecture.md) | AuthN/AuthZ, RBAC matrix, encryption, key management, audit log, e-signatures, network segmentation, FIPS |
| [architecture/api-reference.md](architecture/api-reference.md) | REST resource catalog, auth, pagination/filtering conventions, example requests & responses |
| [architecture/PERMISSIONS_MODEL.md](architecture/PERMISSIONS_MODEL.md) | Roles, permission levels, page/granular/per-record layers, role defaults vs. explicit grants & denies |

### Compliance
| Document | Description |
|----------|-------------|
| [compliance/README.md](compliance/README.md) | Compliance program overview and how the platform supports each standard |
| [compliance/as9100d-clause-mapping.md](compliance/as9100d-clause-mapping.md) | AS9100D / ISO 9001:2015 clause-to-module mapping (clauses 4–10) |
| [compliance/nist-800-171-mapping.md](compliance/nist-800-171-mapping.md) | NIST SP 800-171 control-family mapping and CUI handling |
| [compliance/cmmc-l2-mapping.md](compliance/cmmc-l2-mapping.md) | CMMC 2.0 Level 2 domain mapping and assessment-readiness notes |
| [compliance/nist-800-53-control-summary.md](compliance/nist-800-53-control-summary.md) | NIST SP 800-53 / FedRAMP Moderate control-family summary |
| [compliance/itar-ear-export-control.md](compliance/itar-ear-export-control.md) | ITAR/EAR export-control handling, data residency, segregation |
| [compliance/dfars-252204-7012.md](compliance/dfars-252204-7012.md) | DFARS 252.204-7012 safeguarding & incident reporting coverage |
| [compliance/21-cfr-part-11.md](compliance/21-cfr-part-11.md) | Electronic records & electronic signatures conformance |
| [compliance/ELECTRONIC_SIGNATURES.md](compliance/ELECTRONIC_SIGNATURES.md) | How e-signatures are captured, bound to records, and re-authenticated |
| [compliance/audit-readiness-checklist.md](compliance/audit-readiness-checklist.md) | AS9100 certification + CMMC assessment readiness checklist |

### Deployment & Operations
| Document | Description |
|----------|-------------|
| [deployment/deployment-guide.md](deployment/deployment-guide.md) | End-to-end deployment: local, build, AWS GovCloud, Azure Gov, migrations, seed, smoke test, rollback |
| [deployment/aws-govcloud-runbook.md](deployment/aws-govcloud-runbook.md) | AWS GovCloud specifics: accounts, FIPS endpoints, KMS, networking, EKS, RDS, secrets |
| [deployment/azure-gov-runbook.md](deployment/azure-gov-runbook.md) | Azure Government specifics |
| [deployment/operations-runbook.md](deployment/operations-runbook.md) | Backups, DR/RPO-RTO, monitoring/alerts, scaling, patching, incident response, log retention |
| [deployment/configuration-reference.md](deployment/configuration-reference.md) | Full environment variable reference with sensitivity classification |
| [deployment/DEMO_GUIDE.md](deployment/DEMO_GUIDE.md) | Standing up a demo, what sample data is seeded, and how to sign in |

### User & Administration
| Document | Description |
|----------|-------------|
| [user-guide/user-guide.md](user-guide/user-guide.md) | Role-based usage of each module and end-to-end quality workflows |
| [user-guide/administrator-guide.md](user-guide/administrator-guide.md) | User/role management, configuration, numbering, retention, backups |

### Project
| Document | Description |
|----------|-------------|
| [CONTRIBUTING.md](CONTRIBUTING.md) | Developer workflow, branching, coding standards, security review gate |
| [KNOWN_LIMITATIONS.md](KNOWN_LIMITATIONS.md) | Intentional stubs, single-node constraints, and production recommendations |
| [CHANGELOG.md](CHANGELOG.md) | Release history starting with v1.0.0 |

---

## Platform at a Glance

| Attribute | Detail |
|-----------|--------|
| Backend | Python 3.12, FastAPI, REST API at `/api/v1` |
| Database | PostgreSQL 16 |
| Frontend | React + TypeScript SPA served via nginx, with a CUI banner |
| Authentication | JWT (access/refresh) with pluggable OIDC / SAML / CAC-PIV federation |
| Authorization | Role-Based Access Control (Admin, Quality Manager, Quality Engineer, Auditor, Supplier Quality, Operator, Read-Only) |
| Integrity controls | Immutable audit log; 21 CFR Part 11-style electronic signatures |
| Deployment | AWS GovCloud (`us-gov-west-1`, FIPS) and Azure Government via Terraform, Kubernetes/Helm |
| CI/CD | GitHub Actions |
| Data protection | Encryption at rest (AWS KMS / Azure Key Vault) and in transit (TLS 1.2+); private subnets; managed secrets |

## Quality Modules

Document & Records Control · Nonconformance (NCR/MRB) · CAPA (8D) · Audit Management ·
Supplier Quality (ASL/SCAR/Ratings) · Calibration & Equipment · Training & Competency ·
Change Management (ECN/ECO) · Risk Management · Inspection & First Article (FAI/AS9102) ·
Management Review · Customer Complaints / RMA · Dashboard & KPIs.

## Compliance Targets

AS9100D · ISO 9001:2015 · ISO 9000 · CMMC 2.0 Level 2 · NIST SP 800-171 Rev 2/Rev 3 ·
NIST SP 800-53 (FedRAMP Moderate) · ITAR / EAR · DFARS 252.204-7012 · FAR · 21 CFR Part 11 ·
AS9102 · AS9101.

---

> **Controlled Unclassified Information (CUI) Notice.** When populated with program data, deployments of
> Sentinel QMS may store and process CUI and export-controlled technical data. Handle all derived
> documents, exports, and screenshots in accordance with your organization's CUI and export-control
> policies.
