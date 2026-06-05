# Security Policy — Sentinel QMS

Sentinel QMS is designed to handle **Controlled Unclassified Information (CUI)**
for aerospace, manufacturing, and U.S. Department of Defense supply‑chain work.
Security is a first‑class requirement, not an afterthought.

## Supported deployment baselines

| Target              | Baseline                                             |
|---------------------|------------------------------------------------------|
| AWS GovCloud (US)   | FedRAMP Moderate / NIST SP 800‑53, FIPS 140‑2/3 endpoints |
| Azure Government    | FedRAMP Moderate / NIST SP 800‑53, FIPS endpoints    |
| Contractor on‑prem  | NIST SP 800‑171 Rev 2/3, CMMC 2.0 Level 2            |

## Built‑in security controls

- **Identity & access** — JWT sessions with refresh rotation; pluggable
  OIDC / SAML and CAC/PIV; role‑based access control (RBAC) enforced on every
  state‑changing endpoint.
- **Auditability** — append‑only audit log (who / what / when / before / after)
  for every record mutation; immutable controlled records (soft‑delete only).
- **Electronic signatures** — 21 CFR Part 11–style signing (meaning, signer,
  timestamp, re‑authentication) on dispositions and approvals.
- **Cryptography** — TLS in transit; KMS / Key Vault‑managed encryption at rest;
  FIPS‑validated endpoints in government regions.
- **Network** — databases in private subnets; default‑deny network policies;
  WAF at the edge.
- **Secrets** — sourced from AWS Secrets Manager / Azure Key Vault; never
  committed to source control.
- **Supply chain** — dependency, container, and IaC scanning (Trivy, Checkov/
  tfsec, gitleaks, pip‑audit, npm audit, CodeQL) in CI.

See `docs/compliance/` for the full control‑mapping package.

## Reporting a vulnerability

Please report suspected vulnerabilities privately to the maintainer rather than
opening a public issue. Provide affected version, reproduction steps, and impact.
You will receive an acknowledgement within 3 business days.

## Handling CUI / export‑controlled data

Do **not** load real ITAR/EAR or CUI data into demo or development environments.
Production deployments must run in an authorized government cloud boundary
(AWS GovCloud or Azure Government) with the access controls described in
`docs/compliance/itar-ear-export-control.md` and
`docs/compliance/dfars-252204-7012.md`.
