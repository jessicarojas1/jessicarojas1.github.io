<div align="center">

# 🛡️ Sentinel QMS

### Enterprise Quality Management System for Aerospace, Manufacturing & Defense

**Fully deployable to AWS GovCloud (US) and Azure Government**

[![CI](https://img.shields.io/badge/CI-GitHub_Actions-2088FF?logo=githubactions&logoColor=white)](.github/workflows/ci.yml)
[![Backend](https://img.shields.io/badge/API-FastAPI_%2B_PostgreSQL-009688?logo=fastapi&logoColor=white)](backend/)
[![Frontend](https://img.shields.io/badge/UI-React_%2B_TypeScript-61DAFB?logo=react&logoColor=black)](frontend/)
[![IaC](https://img.shields.io/badge/IaC-Terraform_%2B_Kubernetes-7B42BC?logo=terraform&logoColor=white)](infra/)
[![Compliance](https://img.shields.io/badge/Compliance-AS9100D_·_CMMC_L2_·_NIST_800--171-1f6feb)](docs/compliance/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

</div>

---

> **CUI // Controlled Unclassified Information** — Sentinel QMS is engineered to
> store, process, and transmit CUI in a U.S. government cloud boundary. Do not
> load real ITAR/EAR or CUI data into non‑authorized environments.

Sentinel QMS is a **standalone, enterprise‑grade Quality Management System (QMS)**
built for organizations operating under **AS9100D / ISO 9001:2015**, **CMMC 2.0
Level 2**, and **DFARS 252.204‑7012 / NIST SP 800‑171**. It digitizes the full
quality lifecycle — from controlled documents and nonconformances through
corrective action, audits, supplier quality, calibration, and management review —
on an architecture that deploys cleanly into **AWS GovCloud** or **Azure
Government** with infrastructure as code.

This is not a static demo. It is a real three‑tier application: a typed REST API,
a relational data model with an immutable audit trail, a single‑page React
frontend, and production‑grade Terraform / Kubernetes deployment assets.

---

## ✈️ Why it exists

Aerospace and defense suppliers live and die by their quality system. An AS9100
nonconformance or a CMMC assessment finding can ground a program. Most shops run
this on a patchwork of spreadsheets, shared drives, and paper travelers that
can't survive an audit or a DFARS cyber‑incident review. Sentinel QMS replaces
that patchwork with a single, auditable, access‑controlled system of record that
is *built for the government cloud from day one.*

---

## 🧩 Modules

| Module | Standard alignment | What it does |
|--------|--------------------|--------------|
| **Document & Records Control** | AS9100D 7.5, ISO 9001 7.5 | Controlled docs, revision history, approval workflow, effectivity, e‑signatures |
| **Nonconformance (NCR / MRB)** | AS9100D 8.7 | Capture, segregate, disposition (use‑as‑is, rework, repair, scrap, RTV), Material Review Board |
| **Corrective & Preventive Action (CAPA)** | AS9100D 10.2 | 8D methodology, root‑cause, containment, effectiveness verification |
| **Audit Management** | AS9100D 9.2, AS9101 | Internal/external audits, checklists, findings linked to CAPA, AS9100 clause tagging |
| **Supplier Quality** | AS9100D 8.4 | Approved Supplier List, ratings, Supplier Corrective Action Requests (SCAR) |
| **Calibration & Equipment** | AS9100D 7.1.5 | Gage/equipment register, due/overdue tracking, certificates |
| **Training & Competency** | AS9100D 7.2 | Personnel records, competency matrix, training assignment |
| **Change Management (ECN/ECO)** | AS9100D 8.5.6 | Engineering change orders with controlled approval |
| **Risk Management** | AS9100D 6.1, 8.1.1 | Risk register, severity × likelihood RPN, treatment |
| **Inspection & First Article (FAI)** | AS9102 | First Article Inspection reports, characteristic/balloon records |
| **Management Review** | AS9100D 9.3 | Review inputs, outputs, and tracked action items |
| **Customer Complaints / RMA** | AS9100D 8.2, 9.1.2 | Complaint intake and returns, linked to NCR/CAPA |
| **Dashboard & KPIs** | AS9100D 9.1 | Open NCRs, CAPA aging, calibration due, supplier performance, audit findings |

---

## 🏗️ Architecture

```
                       ┌──────────────────────────────────────────┐
        Browser  ──►   │  React + TypeScript SPA (nginx :8080)     │
                       │  CUI banner · RBAC nav · e‑sign · charts  │
                       └───────────────────┬──────────────────────┘
                                           │  HTTPS  /api/v1
                       ┌───────────────────▼──────────────────────┐
                       │  FastAPI (Python 3.12) — REST API :8000   │
                       │  JWT/OIDC/CAC‑PIV · RBAC · audit log ·     │
                       │  e‑signatures · record numbering          │
                       └───────────────────┬──────────────────────┘
                                           │  SQLAlchemy (parameterized)
                       ┌───────────────────▼──────────────────────┐
                       │  PostgreSQL 16  ·  Object storage (S3 /   │
                       │  Azure Blob)    ·  Secrets Mgr / Key Vault │
                       └──────────────────────────────────────────┘
```

| Layer | Technology |
|-------|------------|
| **Frontend** | React 18, TypeScript, Vite, TanStack Query, react‑hook‑form + zod, Recharts |
| **Backend** | Python 3.12, FastAPI, SQLAlchemy 2.0, Pydantic v2, Alembic |
| **Database** | PostgreSQL 16 |
| **Object storage** | AWS S3 (SSE‑KMS) / Azure Blob (CMK) — pluggable backend |
| **Identity** | JWT + refresh; pluggable OIDC / SAML / CAC‑PIV; RBAC |
| **Infra (IaC)** | Terraform (AWS GovCloud + Azure Gov), Kubernetes + Helm/Kustomize |
| **CI/CD** | GitHub Actions — lint, test, Trivy, Checkov/tfsec, gitleaks, CodeQL, OIDC‑gated deploys |

See **[`docs/architecture/`](docs/architecture/)** for C4 diagrams and the data model.

---

## 🚀 Quick start (local)

```bash
cd sentinel-qms/infra
cp .env.example .env          # fill placeholders (dev only)
docker compose up --build     # postgres + backend + frontend + minio (local S3)
```

| Service | URL |
|---------|-----|
| Frontend | http://localhost:8080 |
| API docs (OpenAPI) | http://localhost:8000/docs |
| Health | http://localhost:8000/health |

The backend applies Alembic migrations and seeds reference data (roles + demo
records) on first boot. See **[`docs/deployment/deployment-guide.md`](docs/deployment/deployment-guide.md)**.

---

## ☁️ Deploy to government cloud

```bash
# AWS GovCloud (us-gov-west-1, FIPS endpoints)
cd sentinel-qms/infra/terraform/aws-govcloud
terraform init && terraform apply

# Azure Government
cd sentinel-qms/infra/terraform/azure-gov
terraform init && terraform apply
```

Both stacks provision private networking, a managed encrypted PostgreSQL,
object storage, secrets management, a WAF‑fronted load balancer, and centralized
logging/alerting. Container images deploy to ECS/EKS (AWS) or Container Apps/AKS
(Azure). Full runbooks live in **[`docs/deployment/`](docs/deployment/)**.

---

## 🔐 Compliance & security

Sentinel QMS ships with a complete control‑mapping package supporting
certification and assessment readiness:

- **[AS9100D / ISO 9001 clause mapping](docs/compliance/as9100d-clause-mapping.md)**
- **[NIST SP 800‑171 mapping](docs/compliance/nist-800-171-mapping.md)** (all 14 families)
- **[CMMC 2.0 Level 2 mapping](docs/compliance/cmmc-l2-mapping.md)**
- **[NIST SP 800‑53 / FedRAMP Moderate summary](docs/compliance/nist-800-53-control-summary.md)**
- **[ITAR / EAR export control](docs/compliance/itar-ear-export-control.md)** · **[DFARS 252.204‑7012](docs/compliance/dfars-252204-7012.md)** · **[21 CFR Part 11](docs/compliance/21-cfr-part-11.md)**

See also **[`SECURITY.md`](SECURITY.md)**.

---

## 📁 Repository layout

```
sentinel-qms/
├── backend/      FastAPI REST API, SQLAlchemy models, Alembic migrations, tests
├── frontend/     React + TypeScript single‑page application
├── infra/        Terraform (AWS GovCloud + Azure Gov), Kubernetes/Helm, docker-compose
├── docs/         Architecture, compliance mappings, deployment & user guides
├── scripts/      Helper scripts
└── .github/      CI/CD and security‑scanning workflows
```

---

## 📜 License

MIT — see [`LICENSE`](LICENSE). Built as a portfolio demonstration of an
enterprise, government‑cloud‑ready quality management platform.
