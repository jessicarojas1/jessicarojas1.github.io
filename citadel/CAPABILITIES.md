# CITADEL — Capabilities

**Enterprise source-code, executable & script compliance review.** CITADEL ingests source code, archives, executables and bytecode and runs a full analysis pipeline entirely client-side (with an optional backend that adds real open-source scanners).

## At a glance

| | |
|---|---|
| Languages & formats | **187** (170 code-bearing) |
| SAST rules | **118** (29 universal + language-specific) |
| Compliance frameworks | **22** |
| Controls catalogued | **999** |
| Dependency ecosystems (SBOM) | npm, PyPI, Maven, Go, RubyGems, Packagist, Cargo, NuGet |

## Analysis capabilities

### Static analysis (SAST)
118 heuristic rules across all code-bearing languages (injection, XSS, broken crypto, deserialization, SSRF, path traversal, XXE, insecure config, IaC misconfig and more); Semgrep + Bandit on the backend.

### Secrets & credential detection
Entropy + pattern matching for API keys, tokens, private keys, passwords and connection strings; Gitleaks + Trivy on the backend.

### SBOM & live CVEs
CycloneDX 1.5 SBOM from 8 package ecosystems, cross-checked against the OSV.dev advisory database for real CVEs; Syft/Grype/Trivy on the backend.

### Executable, bytecode & archive analysis
Format detection (PE, ELF, Mach-O, WebAssembly, Android DEX, Java class, LLVM bitcode, Python .pyc), Shannon-entropy/packing, string extraction, embedded-secret/URL/IP discovery and suspicious-capability indicators; ClamAV malware signatures on the backend.

### Compliance mapping
Every finding cross-walked to the exact controls across 22 frameworks (999 controls catalogued).

### Quality, licenses & deployment
Maintainability index, comment density, oversized-file detection, SPDX/LICENSE license detection (copyleft flags), and Docker/Kubernetes/Helm/Terraform/Bicep/CI-CD deployment detection.

### AI-assisted remediation
Per-finding "Explain & fix" via Claude (backend), plus a generated copy-paste fix prompt enumerating every finding with its location, required fix and compliance references.

### Reporting, exports & CI/CD
Consolidated Report view; downloadable HTML/JSON/SARIF/CycloneDX/POA&M/SSP/Markdown/PDF; scan history & comparison; risk-acceptance suppressions; a CLI and a GitHub Action that uploads SARIF to code scanning.

## Frameworks

- **OWASP Top 10** 2021 — The ten most critical web application security risks.
- **OWASP ASVS** 4.0.3 — Application Security Verification Standard — testable requirements.
- **OWASP API Security Top 10** 2023 — Top risks for APIs.
- **CWE Top 25** 2023 — Most dangerous software weaknesses (MITRE).
- **NIST SP 800-53** Rev 5 — Security & privacy controls for federal information systems.
- **NIST SP 800-171** Rev 2 — Protecting Controlled Unclassified Information (CUI).
- **NIST SSDF 800-218** 1.1 — Secure Software Development Framework practices.
- **NIST CSF** 2.0 — Cybersecurity Framework functions: GV, ID, PR, DE, RS, RC.
- **CMMC** 2.0 (L1–L2) — Cybersecurity Maturity Model Certification for the DIB.
- **CMMI-DEV** v2.0 — Capability Maturity Model Integration — development process maturity (practice areas).
- **ISO/IEC 27001** 2022 — Information security management system — Annex A controls.
- **ISO/IEC 42001** 2023 — AI management system requirements.
- **SOC 2 (TSC)** 2017 TSC — AICPA Trust Services Criteria (Security, Availability, Confidentiality…).
- **PCI DSS** 4.0 — Payment Card Industry Data Security Standard.
- **HIPAA Security Rule** 45 CFR 164 — Safeguards for electronic PHI.
- **FedRAMP** Rev 5 Moderate — Cloud security authorization baseline.
- **CIS Controls** v8 — 18 prioritized safeguards.
- **GDPR** 2016/679 — EU data protection — technical measures (Art. 32).
- **SLSA** v1.0 — Supply-chain Levels for Software Artifacts.
- **FIPS 140-3** 2019 — Security requirements for cryptographic modules.
- **DFARS 252.204-7012** 2016 — Safeguarding covered defense information & cyber incident reporting.
- **DISA ASD STIG** V5 — Application Security & Development Security Technical Implementation Guide.

## Deployment

Client-side demo runs anywhere static (GitHub Pages, Render static). The deep-scan backend is a hardened Docker image deployable to Render, **Azure Government** and **AWS GovCloud** (IaC under `deploy/`). `scripts/init.sh` hydrates scanner databases post-deploy; `scripts/deploy.sh` orchestrates the Gov-cloud deploy.

See **[LANGUAGES.md](LANGUAGES.md)** for the full language matrix.

_Generated from the engine modules._
