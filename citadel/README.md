# CITADEL

![build](https://img.shields.io/badge/build-passing-brightgreen)
![tests](https://img.shields.io/badge/tests-86%20cases-blue)
![accuracy gate](https://img.shields.io/badge/accuracy-recall%20%E2%89%A5%200.90%20%C2%B7%20precision%20%E2%89%A5%200.90-blueviolet)
![node](https://img.shields.io/badge/node-%E2%89%A518-339933?logo=node.js&logoColor=white)
![license](https://img.shields.io/badge/analysis-heuristic%20%2B%20real%20scanners-orange)

> Badges are indicative; CI (`.github/workflows/ci.yml`, job `node-citadel`)
> runs `node --check`, ESLint, `npm audit`, the 86-case suite, SARIF validation,
> and the accuracy benchmark gate.

**Code Inspection, Threat Analysis & Deployment Evaluation Lab** — an enterprise
source-code, executable & script **security & compliance review platform** covering
**100+ languages & formats** (see **[CAPABILITIES.md](CAPABILITIES.md)** / **[LANGUAGES.md](LANGUAGES.md)**
or the in-app [capabilities page](docs/capabilities.html)).

## Why it exists

Security and compliance signal for a codebase is normally scattered across a SAST
platform, an SBOM tool, a secret scanner, a CVE feed, and a pile of framework
spreadsheets. CITADEL **consolidates** them into one framework-aware report: in a
single pass it answers *how secure* the code is, *how well-written* it is, *what
languages* it uses, *how it's deployed*, and *which compliance obligations* it
touches — mapping every finding to the exact controls of 20+ standards, and
producing the audit artifacts (SARIF, SBOM, POA&M, SSP appendix) an ATO package
needs.

## Supported deployment models

| Model | What runs | Persistence |
|---|---|---|
| **SPA-only (static)** | `index.html` + `js/` on any static host | Browser `localStorage` |
| **Full backend (container)** | `server/Dockerfile` image (SPA + all scanners) | In-memory/file, or Postgres |
| **Kubernetes** | Same image + ingress/HPA/PDB | Postgres + Redis + tmpfs |
| **Cloud** | ECS/Fargate · AKS/App Service · Cloud Run + managed PG/secrets | Managed Postgres |
| **Air-gapped** | Bundled image, no egress, self-hosted LLM | In-enclave Postgres |

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** and the per-target runbooks under
**[deploy/](deploy/)** ([compose](deploy/compose/), [aws](deploy/aws/),
[aws-gov](deploy/aws-gov/), [azure-gov](deploy/azure-gov/), [gcp](deploy/gcp/),
[kubernetes](deploy/kubernetes/)).

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

## Prerequisites

| Model | Requirements |
|---|---|
| **SPA-only** | Any static host or `python3 -m http.server`. **No build step.** |
| **Backend** | Docker (or Node **≥ 18**); **≥ 2 GB RAM** (ClamAV loads a ~1.4 GB signature DB); ~4 GB disk for the image + scanner DBs; outbound HTTPS for first-run CVE DBs (unless air-gapped). |
| **CI gate** | Node 20 (see the [GitHub Action](CI.md) / `cli/`). |

## Dependencies

**Backend Node deps** (`server/package.json`): `express`, `multer`, `adm-zip`,
`jsonwebtoken`, `pg`, `ioredis`, `@anthropic-ai/sdk`; optional OpenTelemetry
(`@opentelemetry/*`); dev: `eslint`. **SPA** vendors Bootstrap 5.3, Chart.js, and
**JSZip** (no npm install for the front-end).

**External scanner binaries** (bundled in `server/Dockerfile`; each degrades
gracefully if absent):

| Binary | Provides |
|---|---|
| **Semgrep** | Multi-language SAST (core signal) |
| **Bandit** | Python SAST |
| **Trivy** | Dependency CVEs + secrets + IaC misconfig |
| **Syft** | CycloneDX/SPDX SBOM |
| **Grype** | Vulnerability matching (CVEs) |
| **Gitleaks** | Secrets / credential detection |
| **ClamAV** | Malware signatures |
| **Checkov** | IaC misconfiguration |
| **OSV-Scanner** | Lockfile/manifest vulns |
| **Hadolint** | Dockerfile lint |
| **CodeQL** *(opt-in)* | Deep dataflow SAST (`--build-arg CITADEL_WITH_CODEQL=1` + `CITADEL_ENABLE_CODEQL=1`) |

Configuration is entirely environment variables — see **[docs/ENV.md](docs/ENV.md)**.

## Common commands

```bash
# SPA (no build)
python3 -m http.server 8000              # http://localhost:8000/citadel/

# Backend: install, test, lint
cd citadel/server && npm ci && npm test && npm run lint

# Accuracy benchmark (CI-gated)
node citadel/benchmark/run.js

# Build + run the deep-scan image (build context = REPO ROOT)
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run -p 8080:8080 citadel-server   # http://localhost:8080/

# Release-readiness gate for CI
node citadel/cli/citadel-gate.js . --fail-on=rejected
```

## Documentation

- **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** — platform, design principles, component
  overview, request & error contract, security model, observability, deploy topology + scan pipeline.
- **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** — deployment models, config & secrets, scanner-DB
  updates, Ollama/GPU for AI & air-gapped, verification, and the production checklist.
- **[docs/SECURITY.md](docs/SECURITY.md)** — auth (JWT/MFA/OIDC), RBAC, data protection, audit,
  CUI/DLP, FIPS, secrets rotation, and reporting.
- **[docs/DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)** — state, RPO/RTO, backups, restore
  runbook, drills, HA (and the free-tier ephemeral-store caveat).
- **[OPEN_ITEMS.md](OPEN_ITEMS.md)** — honest production-readiness checklist (done vs outstanding).
- **[docs/index.html](docs/index.html)** — interactive architecture & module-interaction map.
- **[FRAMEWORKS.md](FRAMEWORKS.md)** — the full catalog of standards and the weakness-to-control cross-walk.
- **[docs/RELEASE-READINESS.md](docs/RELEASE-READINESS.md)** — the readiness score + security gate.
- **[docs/RBAC.md](docs/RBAC.md)** — roles, page permissions, backend enforcement, SSO mapping.
- **[docs/UPLOAD-SECURITY.md](docs/UPLOAD-SECURITY.md)** — zip-slip / decompression-bomb caps, isolation, limits.
- **[docs/TESTING.md](docs/TESTING.md)** — how to run the suites, coverage, and CI gates.
- **[docs/ENV.md](docs/ENV.md)** — full environment-variable reference.
- **[deploy/README.md](deploy/README.md)** — choosing and using a deployment target (incl. Gov clouds).

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
