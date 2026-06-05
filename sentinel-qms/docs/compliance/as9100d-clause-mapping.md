# AS9100D / ISO 9001:2015 Clause Mapping

This document maps the requirements of **AS9100D** (which incorporates **ISO 9001:2015** and adds
aerospace-specific requirements) to the Sentinel QMS modules and features that satisfy them. AS9100D and
ISO 9001:2015 share the same clause structure (4–10); aerospace additions are noted. Where a requirement
is organizational rather than software-satisfiable, the platform's supporting role is identified.

> **Legend** — *Platform satisfies*: the module/feature directly implements the requirement.
> *Platform supports*: the platform provides records/workflow/evidence; the organization owns the process.

---

## Clause 4 — Context of the Organization

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 4.1 | Understanding the organization and its context | **Supports** — Risk Management module captures internal/external issues feeding risk-based thinking |
| 4.2 | Needs and expectations of interested parties | **Supports** — Customer Complaints/RMA and Supplier Quality capture interested-party requirements/feedback |
| 4.3 | Determining the scope of the QMS | **Supports** — Document Control holds the Quality Manual / scope statement as a controlled document |
| 4.4 | QMS and its processes | **Satisfies** — Each clause-4–10 process is a module producing controlled records; process interactions traceable (NCR→CAPA, Audit→Finding→CAPA) |

## Clause 5 — Leadership

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 5.1 | Leadership and commitment (incl. product safety, 5.1.1 aero) | **Supports** — Management Review module records leadership engagement; Dashboard/KPIs surface performance |
| 5.2 | Quality policy | **Satisfies** — Quality policy maintained as a controlled, version-approved document with acknowledgements |
| 5.3 | Roles, responsibilities and authorities | **Satisfies** — RBAC roles (Admin, Quality Manager, Quality Engineer, Auditor, Supplier Quality, Operator, Read-Only) assign authority; signature authority enforced per action |

## Clause 6 — Planning

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 6.1 | Actions to address risks and opportunities | **Satisfies** — Risk Management module with likelihood × severity (RPN), controls, and residual risk |
| 6.2 | Quality objectives and planning | **Satisfies** — Dashboard/KPI module defines and tracks measurable objectives; Management Review reviews them |
| 6.3 | Planning of changes | **Satisfies** — Change Management (ECN/ECO) with impact assessment, approval, implementation, and verification |

## Clause 7 — Support

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 7.1.1–7.1.4 | Resources, people, infrastructure, environment | **Supports** — Equipment register; Training & Competency; records of resource provision |
| 7.1.5 | Monitoring and measuring resources (incl. 7.1.5.2 measurement traceability) | **Satisfies** — Calibration & Equipment module: M&TE register, NIST-traceable certificates, intervals, recall on out-of-tolerance |
| 7.1.6 | Organizational knowledge | **Supports** — Document Control retains lessons-learned, CAPA knowledge base |
| 7.2 | Competence | **Satisfies** — Training & Competency module ties qualifications to roles with recurrence and expiry |
| 7.3 | Awareness | **Satisfies** — Document acknowledgements (read-and-understood) on released documents |
| 7.4 | Communication | **Supports** — Workflow notifications, escalations |
| 7.5 | Documented information (control of documents/records) | **Satisfies** — Document & Records Control: versioning, approval e-signatures, status, retention, controlled distribution |

## Clause 8 — Operation

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 8.1 | Operational planning and control (incl. 8.1.1 project mgmt, 8.1.2 risk, 8.1.3 config mgmt, 8.1.4 prevention of counterfeit parts) | **Supports** — Change/Configuration Management, Risk, Inspection, and Supplier Quality controls |
| 8.2 | Requirements for products and services | **Supports** — Customer Complaints/RMA captures requirements and feedback; document control of requirements |
| 8.3 | Design and development | **Supports** — Document Control + Change Management hold design records, reviews, verification/validation evidence |
| 8.4 | Control of externally provided processes, products, services | **Satisfies** — Supplier Quality: ASL, supplier approval/status, SCARs, ratings/scorecards, source/receiving inspection |
| 8.5.1 | Control of production and service provision (incl. **FAI / AS9102**, 8.5.1.3) | **Satisfies** — Inspection & First Article module implements AS9102 Forms 1/2/3 and characteristic accountability |
| 8.5.2 | Identification and traceability | **Satisfies** — Part number, lot/serial, controlled record numbers throughout |
| 8.5.3 | Property belonging to customers/external providers | **Supports** — Records and attachments tracking customer-furnished property |
| 8.5.4 | Preservation | **Supports** — Records of handling/storage where captured |
| 8.6 | Release of products and services | **Satisfies** — Inspection results + e-signature release gating; FAI sign-off |
| 8.7 | **Control of nonconforming outputs** | **Satisfies** — Nonconformance (NCR) module with disposition state machine (use-as-is, rework, repair, scrap, RTV) and **MRB** records |

## Clause 9 — Performance Evaluation

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 9.1 | Monitoring, measurement, analysis, evaluation (incl. 9.1.2 customer satisfaction) | **Satisfies** — Dashboard/KPIs (NCR aging, CAPA on-time, supplier scorecards, calibration/training status); Complaints/RMA for customer satisfaction |
| 9.2 | Internal audit (aligned with **AS9101**) | **Satisfies** — Audit Management: plans, events, findings (major/minor/observation/OFI), clause references, CAPA linkage |
| 9.3 | Management review | **Satisfies** — Management Review module with standard inputs (audit results, customer feedback, KPI performance, CAPA status, risks) and output actions |

## Clause 10 — Improvement

| Clause | Requirement | Sentinel QMS support |
|--------|-------------|----------------------|
| 10.1 | General | **Supports** — Improvement opportunities tracked via OFIs, risks, and KPIs |
| 10.2 | Nonconformity and corrective action | **Satisfies** — CAPA (8D) module: containment, root-cause, corrective/preventive actions, effectiveness verification, closure e-signature; sourced from NCR, audit findings, complaints |
| 10.3 | Continual improvement | **Satisfies** — Trended KPIs + management review actions drive continual improvement |

---

## Aerospace-Specific Highlights

| Topic | AS9100D emphasis | Sentinel QMS |
|-------|------------------|--------------|
| First Article Inspection | 8.5.1.3 / AS9102 | FAI module with Forms 1/2/3, characteristic accountability, sign-off |
| Counterfeit parts prevention | 8.1.4 | Supplier approval/ASL, receiving inspection, traceability records |
| Configuration management | 8.1.3 | Change Management (ECN/ECO) with affected-item linkage and verification |
| Product safety | 5.1.1 | Risk Management + nonconformance escalation; management review |
| Special processes / key characteristics | 8.5.1 | Inspection characteristics, ASL special-process approvals, FAI tooling capture |
| Risk management | 8.1.1 / 6.1 | Risk register with RPN, controls, residual risk |
| Audit | 9.2 / AS9101 | Audit Management with finding classification and CAPA flow |

---

## Records-to-Clause Quick Index

| Record (module) | Primary clause(s) |
|-----------------|-------------------|
| Controlled Document / Revision | 7.5, 5.2, 8.3 |
| Nonconformance / MRB | 8.7 |
| CAPA (8D) | 10.2 |
| Audit Plan / Event / Finding | 9.2 |
| Supplier / ASL / SCAR / Rating | 8.4 |
| Equipment / Calibration | 7.1.5 |
| Training Record | 7.2, 7.3 |
| Change Request (ECN/ECO) | 6.3, 8.1.3 |
| Risk / Assessment | 6.1, 8.1.1 |
| Inspection / FAI | 8.5.1, 8.6 |
| Management Review | 9.3 |
| Complaint / RMA | 9.1.2, 10.2 |
