# Architecture Diagrams

This document collects the rendered **Mermaid** diagrams for Sentinel QMS: the C4 system-context and
container views, the deployment topologies for **AWS GovCloud (US)** and **Microsoft Azure Government**,
and a representative data-flow for a signature-bearing write. Diagrams render natively on GitHub.

For the narrative description of these diagrams, see [overview.md](overview.md).

---

## 1. System Context (C4 — Level 1)

```mermaid
graph TB
    subgraph Users["Quality Organization"]
        QM["Quality Manager"]
        QE["Quality Engineer"]
        AUD["Auditor"]
        OP["Operator"]
        RO["Read-Only Stakeholder"]
        ADM["Administrator"]
    end

    subgraph External["External Parties"]
        SUP["Supplier Quality<br/>(external supplier)"]
        IDP["Enterprise IdP<br/>Entra ID Gov / Okta / ADFS / DoD ICAM"]
    end

    SENT["Sentinel QMS<br/>Enterprise Quality Management System"]

    subgraph Platform["Cloud Platform Services (GovCloud / Azure Gov)"]
        OBJ["Object Storage<br/>S3 / Azure Blob"]
        MAIL["Notification Relay<br/>SES Gov / Azure Comms"]
        SIEM["SIEM / Log Aggregation<br/>CloudWatch+Security Hub / Azure Monitor+Sentinel"]
    end

    QM & QE & AUD & OP & RO & ADM -->|HTTPS / TLS 1.2+| SENT
    SUP -->|Scoped supplier portal| SENT
    SENT -->|OIDC / SAML / CAC-PIV| IDP
    SENT -->|Store controlled docs & evidence| OBJ
    SENT -->|Workflow notifications| MAIL
    SENT -->|Audit & app logs| SIEM
```

---

## 2. Container View (C4 — Level 2)

```mermaid
graph TB
    subgraph Boundary["Trust Boundary: Cloud Account/Subscription + K8s Namespace"]
        ING["Ingress / Reverse Proxy<br/>nginx ingress + Cloud LB<br/>(TLS termination, FIPS endpoints,<br/>rate limiting, security headers)"]

        SPA["Web SPA<br/>React + TypeScript (nginx)<br/>CUI banner, no long-lived secrets"]

        API["API Service<br/>Python 3.12 + FastAPI<br/>Gunicorn/Uvicorn<br/>REST /api/v1"]

        WORK["Background Worker<br/>scheduled jobs:<br/>calibration-due, training-expiry,<br/>KPI rollups, notifications, retention"]

        DB[("PostgreSQL 16<br/>system of record +<br/>immutable audit log +<br/>e-signature manifests")]

        OBJ["Object Store<br/>S3 / Azure Blob"]
    end

    IDP["Enterprise IdP"]
    SIEM["SIEM"]

    SPA -->|REST/JSON over TLS| ING
    ING --> API
    API -->|JWT issue / federation| IDP
    API -->|SQLAlchemy 2.0<br/>parameterized SQL| DB
    API -->|put/get artifacts| OBJ
    API -->|structured JSON logs| SIEM
    WORK --> DB
    WORK --> OBJ
    WORK -->|notifications| SPA
```

---

## 3. Deployment — AWS GovCloud (US)

```mermaid
graph TB
    subgraph AWS["AWS GovCloud (US) — us-gov-west-1 (FIPS endpoints)"]
        R53["Route 53<br/>(private hosted zone)"]
        WAF["AWS WAF + Shield"]

        subgraph VPC["VPC 10.40.0.0/16"]
            subgraph PUB["Public Subnets (10.40.0.0/22, 10.40.4.0/22)"]
                ALB["Application Load Balancer<br/>TLS 1.2+ / ACM cert<br/>FIPS endpoint"]
                NAT["NAT Gateways"]
            end

            subgraph PRIV["Private/App Subnets (10.40.16.0/22, 10.40.20.0/22)"]
                EKS["Amazon EKS<br/>API pods + Worker pods + SPA pods<br/>(Helm release)"]
            end

            subgraph DATA["Isolated Data Subnets (10.40.32.0/22, 10.40.36.0/22)"]
                RDS[("Amazon RDS for<br/>PostgreSQL 16<br/>Multi-AZ, KMS-encrypted")]
            end
        end

        S3["S3 (SSE-KMS)<br/>VPC Gateway Endpoint"]
        KMS["AWS KMS<br/>(FIPS 140-2/3 validated HSM)"]
        SM["AWS Secrets Manager"]
        CW["CloudWatch Logs +<br/>Security Hub + GuardDuty"]
        ECR["Amazon ECR<br/>(signed images)"]
    end

    Users["Quality Users / Suppliers"] -->|HTTPS| R53 --> WAF --> ALB
    ALB --> EKS
    EKS --> RDS
    EKS -->|VPC endpoint| S3
    EKS -->|encrypt/decrypt| KMS
    EKS -->|fetch secrets| SM
    EKS -->|logs| CW
    EKS -.->|pull images| ECR
    RDS --> KMS
    S3 --> KMS
    PRIV -->|egress via| NAT
```

---

## 4. Deployment — Microsoft Azure Government

```mermaid
graph TB
    subgraph AZ["Azure Government — usgovvirginia"]
        FD["Azure Front Door /<br/>App Gateway + WAF<br/>TLS 1.2+"]

        subgraph VNET["VNet 10.40.0.0/16"]
            subgraph AGW["Ingress Subnet"]
                AGWN["App Gateway / nginx ingress"]
            end

            subgraph APP["App Subnet (10.40.16.0/22, 10.40.20.0/22)"]
                AKS["Azure Kubernetes Service<br/>API + Worker + SPA pods<br/>(Helm release)"]
            end

            subgraph DSUB["Data Subnet (10.40.32.0/22, 10.40.36.0/22)"]
                PG[("Azure Database for<br/>PostgreSQL 16<br/>Flexible Server, zone-redundant")]
            end
        end

        BLOB["Azure Blob Storage<br/>(CMK encryption)<br/>Private Endpoint"]
        KV["Azure Key Vault<br/>(FIPS 140-2 HSM)"]
        MON["Azure Monitor +<br/>Microsoft Sentinel +<br/>Defender for Cloud"]
        ACR["Azure Container Registry<br/>(signed images)"]
    end

    Users["Quality Users / Suppliers"] -->|HTTPS| FD --> AGWN --> AKS
    AKS --> PG
    AKS -->|Private Endpoint| BLOB
    AKS -->|secrets / keys| KV
    AKS -->|logs/metrics| MON
    AKS -.->|pull images| ACR
    PG --> KV
    BLOB --> KV
```

---

## 5. Data Flow — Signature-Bearing Write (NCR Disposition)

```mermaid
sequenceDiagram
    autonumber
    participant U as User (Browser SPA)
    participant LB as Ingress (TLS/FIPS)
    participant API as FastAPI Service
    participant AZ as RBAC Dependency
    participant SVC as NCR Service
    participant ES as E-Signature Service
    participant DB as PostgreSQL (txn)
    participant AUD as Audit Log
    participant Q as Worker Queue
    participant S as SIEM

    U->>LB: POST /api/v1/nonconformances/{id}/disposition (JWT)
    LB->>API: forward over private subnet
    API->>API: validate JWT (sig, exp, type)
    API->>AZ: require_permission(ncr:disposition)
    AZ-->>API: user authorized
    API->>SVC: validate request (Pydantic) + state machine
    SVC->>ES: capture e-signature (re-auth: password/CAC-PIN)
    ES-->>SVC: signature manifest (who/what/when/why + record hash)
    SVC->>DB: BEGIN; UPDATE ncr SET disposition...
    SVC->>AUD: INSERT audit row (actor, action, before/after hash, IP, session)
    DB-->>SVC: COMMIT
    SVC->>Q: enqueue notifications (originator, CAPA owner)
    API-->>U: 200 OK (serialized response)
    API->>S: structured JSON access/audit log line
```

---

## 6. CI/CD Pipeline (GitHub Actions)

```mermaid
graph LR
    DEV["Developer<br/>feature branch"] -->|PR| GH["GitHub"]
    GH --> LINT["ruff lint +<br/>type checks"]
    LINT --> TEST["pytest +<br/>coverage gate"]
    TEST --> SCAN["SAST + dependency scan +<br/>secret scan + container scan"]
    SCAN --> BUILD["Build images<br/>(backend, frontend)"]
    BUILD --> SIGN["Sign images<br/>(cosign) + SBOM"]
    SIGN --> PUSH["Push to ECR / ACR"]
    PUSH --> STG["Deploy to Staging<br/>(Helm)"]
    STG --> SMOKE["Smoke tests"]
    SMOKE --> APPROVE{"Manual<br/>approval"}
    APPROVE -->|approved| PROD["Deploy to Production<br/>(GovCloud / Azure Gov)"]
```
