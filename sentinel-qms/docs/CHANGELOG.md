# Changelog

All notable changes to Sentinel QMS are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/), and the project adheres to
[Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-06-05

Initial general-availability release of **Sentinel QMS — Enterprise Quality Management System**, a
standalone QMS for aerospace, manufacturing, and U.S. DoD work, deployable to **AWS GovCloud (US)** and
**Microsoft Azure Government**.

### Added — Platform & Architecture
- Python 3.12 **FastAPI** REST API at `/api/v1` with auto-generated OpenAPI 3.
- **PostgreSQL 16** system of record with SQLAlchemy 2.0 + Alembic migrations.
- **React + TypeScript** SPA served by nginx, with a persistent **CUI banner**.
- Stateless, horizontally scalable API; background worker for scheduled jobs (calibration-due scans,
  training-expiry checks, KPI rollups, notifications, retention sweeps).
- Single-tenant and logical multi-tenant deployment models; PostgreSQL Row-Level Security for tenant
  isolation.

### Added — Security
- **JWT** access/refresh authentication with pluggable **OIDC / SAML / CAC-PIV** federation.
- **RBAC** with seven roles (Admin, Quality Manager, Quality Engineer, Auditor, Supplier Quality,
  Operator, Read-Only) and `<domain>:<action>` permissions enforced server-side.
- **Immutable, append-only audit log** (actor, action, entity, before/after hashes, IP, session, UTC),
  committed atomically with each business write.
- **21 CFR Part 11-style electronic signatures** with re-authentication and record-hash binding.
- Encryption in transit (TLS 1.2+/FIPS) and at rest (AWS KMS / Azure Key Vault CMKs); private networking;
  managed secrets; secure file uploads (MIME/extension allowlist, size cap, randomized filenames,
  SHA-256).

### Added — Quality Modules
- **Document & Records Control** — versioned controlled documents, approval e-signatures, acknowledgements.
- **Nonconformance (NCR / MRB)** — disposition state machine and Material Review Board records.
- **CAPA (8D)** — containment, root cause, corrective/preventive actions, effectiveness verification.
- **Audit Management** — plans, events, findings (major/minor/observation/OFI) with CAPA linkage.
- **Supplier Quality** — Approved Supplier List, SCARs, and rating/scorecard engine; scoped supplier portal.
- **Calibration & Equipment** — M&TE register with NIST-traceable certificates and due tracking.
- **Training & Competency** — role-mapped courses with recurrence and expiry.
- **Change Management (ECN/ECO)** — impact assessment, approval, implementation, and verification.
- **Risk Management** — risk register with likelihood × severity (RPN) and residual-risk tracking.
- **Inspection & First Article (FAI / AS9102)** — inspections and AS9102 Forms 1/2/3 with characteristic
  accountability.
- **Management Review** — standard inputs and output actions (ISO 9001 cl. 9.3).
- **Customer Complaints / RMA** — complaint intake, RMA lifecycle, CAPA triggering.
- **Dashboard / KPIs** — NCR aging, CAPA on-time closure, supplier scorecards, calibration/training
  status, audit findings.

### Added — Compliance Alignment
- **AS9100D / ISO 9001:2015 / ISO 9000** clause-to-module mapping (clauses 4–10).
- **NIST SP 800-171 Rev 2/Rev 3** implementation across all 14 control families; CUI handling.
- **CMMC 2.0 Level 2** domain mapping and assessment-readiness guidance.
- **NIST SP 800-53 (FedRAMP Moderate)** control-family coverage via application + IaC.
- **ITAR / EAR** export-control handling: GovCloud/Azure Gov residency, U.S.-person access gating,
  segregation, logging.
- **DFARS 252.204-7012** safeguarding and 72-hour cyber-incident reporting support.
- **21 CFR Part 11** electronic records & signatures conformance.
- **AS9102** First Article Inspection and **AS9101** audit support.
- Audit-readiness checklist for AS9100 certification and CMMC Level 2 assessment.

### Added — Deployment & Operations
- **Terraform** cloud-selectable network module (3-tier VPC/VNet) and stacks for AWS GovCloud and Azure
  Gov; **Kubernetes/Helm** application packaging.
- Local full-stack **docker-compose** (PostgreSQL, MinIO, backend, frontend).
- **GitHub Actions** CI/CD with lint, tests/coverage, SAST, SCA, secret and container scanning, image
  signing (cosign), and SBOM generation.
- Runbooks for AWS GovCloud, Azure Government, and operations (backups, DR/RPO-RTO, monitoring, scaling,
  patching, incident response, log retention); full environment-variable configuration reference.

### Added — Documentation
- Architecture (overview, diagrams, data model, security architecture, API reference).
- Compliance program overview and mappings.
- Deployment and operations guides and runbooks.
- Role-based user guide and administrator guide.
- Contribution guidelines with a mandatory security review gate.

### Security Notes
- FIPS-validated cryptography via cloud KMS/Key Vault and FIPS service endpoints.
- Append-only audit protection enforced at the database (trigger + restricted grants).
- Deny-by-default networking; isolated data tier with no internet route.

[1.0.0]: https://example.gov/sentinel-qms/releases/tag/v1.0.0
