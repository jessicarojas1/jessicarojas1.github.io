# CITADEL

**Code Inspection, Threat Analysis & Deployment Evaluation Lab** — an enterprise
source-code, executable & script **security & compliance review platform** covering
**117 languages & formats** (see **[CAPABILITIES.md](CAPABILITIES.md)** / **[LANGUAGES.md](LANGUAGES.md)**
or the in-app [capabilities page](docs/capabilities.html)).

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
- **Optional deep-scan backend.** A Node API ([`server/`](server/)) runs **real open-source
  scanners** — Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV — and merges their
  results with the heuristic engine into the *same* report the SPA renders.
- **Live CVEs.** Quick scans cross-check dependencies against the **OSV.dev** advisory database
  (client-side, keyless) for real CVEs with fixed versions.
- **Exports for the whole pipeline.** JSON, **SARIF 2.1.0** (GitHub Code Scanning), CycloneDX
  SBOM, **POA&M** (CSV), an **SSP control appendix** (Markdown), summary Markdown, and printable PDF.
- **Trend & triage.** Local **scan history** with run-to-run comparison, plus **risk acceptance**
  (suppress findings) and **license detection** (flags copyleft).
- **AI remediation.** With the backend + an `ANTHROPIC_API_KEY`, each finding gets an
  "Explain & fix" powered by Claude (`claude-opus-4-8`).
- **CI/CD.** A [GitHub Action](CI.md) + CLI (`server/cli.js`) emit SARIF and fail the build on a
  configurable severity; scan a **public repo by URL** from the UI.
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
├── server/               # Deep-scan backend (Express API + real scanners)
│   ├── server.js         # API: GET /api/health, POST /api/scan; serves the SPA
│   ├── lib/scanners.js   # adapters: Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV
│   ├── lib/normalize.js  # severity + CWE→category normalization
│   ├── lib/engine.js     # reuses the browser engine server-side; merges results
│   ├── Dockerfile        # image with all scanners installed (build from repo root)
│   └── README.md         # backend runbook + Render / Gov-cloud deploy
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

## Deep scan (real scanners)

The browser engine is heuristic. For depth, run the backend in [`server/`](server/), which
shells out to real open-source scanners and merges their findings with the heuristic engine —
the SPA automatically shows a **Deep scan** toggle when it's served by the backend.

```bash
# build from the REPO ROOT (the image bundles the SPA + all scanners)
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run -p 8080:8080 citadel-server      # open http://localhost:8080/
# or: cd citadel/server && docker compose up
```

Deploy that container to **Render.com** (Docker web service, port 8080) or to the Azure Gov /
AWS GovCloud IaC under [`deploy/`](deploy/). See [`server/README.md`](server/README.md).

## Scope & limitations

The **browser engine** is a heuristic, pattern-based analyzer for fast triage, education, and
demonstration. The **deep-scan backend** adds Semgrep/Bandit (SAST), Trivy/Grype (CVEs),
Gitleaks/Trivy (secrets), Syft (SBOM), and ClamAV (malware) for real depth. Even so, for an
Authorization to Operate (ATO), pair it with a credentialed vulnerability assessment and have
results reviewed by a qualified assessor.

_Built by Jessica Rojas. Heuristic analysis — verify findings before acting on them._
