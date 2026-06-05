# CITADEL

**Code Inspection, Threat Analysis & Deployment Evaluation Lab** — a source-code and
executable **security & compliance review platform**.

Upload source code, archives (`.zip`/`.jar`/`.apk`), or executables and CITADEL runs static
analysis, secrets detection, SBOM generation, and binary inspection — then maps every finding
to **CMMC 2.0, NIST SP 800-171/53, NIST SSDF, NIST CSF, ISO 27001, ISO 42001, SOC 2, OWASP
Top 10/ASVS/API, CWE Top 25, PCI DSS 4.0, HIPAA, FedRAMP, CIS Controls v8, GDPR, SLSA,
FIPS 140-3, DFARS 252.204-7012 and DISA STIG** — 20+ frameworks in total.

It reports, in one place: **how secure** the code is, **how well-written** it is, **what
languages** it uses, **how it's deployed**, and **which compliance obligations** it touches —
essentially a consolidation of the capabilities of AI code reviewers, SAST platforms, SBOM
tools, and secret scanners into a single, framework-aware report.

## Highlights

- **100% client-side demo.** Files are parsed in your browser with `JSZip`; nothing is uploaded.
- **Multi-engine pipeline.** Ingest → language classification → SAST rules → secrets/entropy →
  SBOM & dependency risk → binary/executable analysis → quality → deployment detection →
  scoring → compliance mapping.
- **A–F security grade** with severity-weighted scoring and a maintainability index.
- **CycloneDX 1.5 SBOM**, plus JSON / Markdown / printable-PDF exports.
- **Government-ready.** Hardened, FIPS-friendly container with Infrastructure-as-Code for
  **Azure Government** and **AWS GovCloud (US)** under [`deploy/`](deploy/).

## Run it

It's a static site — no build step.

```bash
# from the repo root
python3 -m http.server 8000
# then open http://localhost:8000/citadel/
```

Click **Load demo project** to scan a synthetic vulnerable app, or drop your own code.

## Layout

```
citadel/
├── index.html            # the analyzer SPA
├── css/citadel.css       # styles (Bootstrap 5.3 + theme.css)
├── js/
│   ├── ingest.js         # Ingest Engine (archive expansion, text/binary sniff)
│   ├── languages.js      # Language Classifier
│   ├── rules.js          # SAST rule library
│   ├── secrets.js        # entropy-based Secrets Scanner
│   ├── sbom.js           # SBOM & Dependency Analyzer (CycloneDX)
│   ├── binary.js         # Binary / Executable Analyzer
│   ├── frameworks.js     # Compliance Mapping Engine (the 20+ standards)
│   ├── scanner.js        # Scan Orchestrator + scoring + quality + deployment
│   ├── report.js         # Report & Export Engine (Chart.js, exporters)
│   ├── demo.js           # synthetic demo project
│   └── app.js            # UI controller
├── docs/index.html       # Architecture & module-interaction documentation
├── deploy/
│   ├── azure-gov/        # Azure Government IaC (Bicep) + runbook
│   └── aws-gov/          # AWS GovCloud IaC (Terraform) + runbook
└── ARCHITECTURE.md / FRAMEWORKS.md
```

## Documentation

- **[docs/index.html](docs/index.html)** — interactive architecture, data flow, and a
  module-by-module breakdown of how the engines interact.
- **[ARCHITECTURE.md](ARCHITECTURE.md)** — the same content in Markdown.
- **[FRAMEWORKS.md](FRAMEWORKS.md)** — the full catalog of standards and the weakness-to-control
  cross-walk.
- **[deploy/README.md](deploy/README.md)** — choosing and using a government deployment target.

## Scope & limitations

The browser engine is a **heuristic, pattern-based** analyzer built for fast triage, education,
and demonstration — not a substitute for full data-flow SAST/DAST or a live CVE feed. For an
Authorization to Operate (ATO), run the production tier's integrated open-source scanners
(Semgrep, Trivy, Syft/Grype, Gitleaks, ClamAV, Bandit) and have results reviewed by a qualified
assessor.

_Built by Jessica Rojas. Heuristic analysis — verify findings before acting on them._
