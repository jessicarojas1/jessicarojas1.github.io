# CITADEL — How It Works (Work Instructions)

The interactive version is at **[docs/how-it-works.html](docs/how-it-works.html)**.

## 1. What it is
An enterprise source-code, executable & script review platform. Give it code or a binary; it
reports how secure it is, how well-written, what languages it uses, how it's deployed, and which
compliance obligations it touches — mapped to the exact controls of 20+ frameworks. The public app
runs 100% in your browser; an optional backend adds real scanners (Semgrep, Trivy, Grype, Syft,
Gitleaks, Bandit, ClamAV) and AI fixes.

## 2. The process
```
Ingest → Classify → Analyze (SAST · Secrets · SBOM/CVE · Binary · Quality · Deploy) → Score → Map to controls → Report
```
1. **Ingest** — files/folders/ZIP/executables normalized; build & vendor dirs skipped; text/binary sniffed.
2. **Classify** — each file mapped to one of 117 languages/formats.
3. **SAST** — 100+ language-aware rules (injection, XSS, crypto, deserialization, SSRF, traversal, XXE, misconfig).
4. **Secrets** — entropy + patterns for keys/tokens/passwords.
5. **SBOM & CVEs** — CycloneDX SBOM from 8 ecosystems, checked against OSV.dev for real CVEs.
6. **Binary & quality** — executable/bytecode inspection; maintainability, licenses, deployment detection.
7. **Score** — severity-weighted security score + A–F grade.
8. **Map & report** — every finding cross-walked to framework controls; rendered into the report.

## 3. Running a scan
Open the analyzer and either **Load demo project**, **Select files/folder**, drag-drop a `.zip`/file/executable,
or (deep scan) **Scan a public repo by URL**. Watch the progress bar; the **Report** tab opens with results.
Quick scans then enrich dependencies with live OSV CVEs.

## 4. Quick vs deep scan
Quick = browser-only (heuristics + OSV). Deep = the Docker backend adds Semgrep/Bandit (SAST),
Gitleaks/Trivy (secrets), Syft/Grype/Trivy (SBOM+CVE), ClamAV (malware), and inline AI fixes. The
deep-scan toggle and repo-URL box appear automatically when served by the backend.

## 5. Reading the report
Tabs: **Report** (executive summary + risk hotspots + download), **Overview** (charts), **Findings**
(filter/expand/explain), **Compliance** (controls implicated / total, view-all-controls), **SBOM**,
**Binaries**, **Quality**, **Deployment**, **AI Fix Prompt**, **History** (trend + compare), **Export**.

## 6. Fixing issues with AI
Open **AI Fix Prompt** → **Copy prompt** → paste into Claude/any assistant with your code → apply the
diffs → re-scan. With a backend `ANTHROPIC_API_KEY`, each finding also has an inline **Explain & fix**.

## 7. Triage
Prioritize by severity + risk hotspots; **Accept risk** to suppress; use **History → Compare** to track
the security delta between runs.

## 8. Compliance mapping
Each finding's category maps to specific controls in every framework (one SQLi finding flags OWASP A03,
CWE-89, 800-53 SI-10, 800-171 3.14.1, CMMC SI.L1-3.14.1, ISO A.8.28, PCI 6.2.4…). Export **POA&M** (CSV)
and **SSP appendix** (Markdown) from this mapping.

## 9. Exports & CI/CD
JSON, **SARIF** (code scanning), CycloneDX SBOM, POA&M, SSP appendix, **JUnit XML**, **PR-comment Markdown**,
Markdown, PDF. Run in CI via the **GitHub Action** (`action.yml`) or the **CLI** (`server/cli.js … --fail-on high`).
See [CI.md](CI.md).

## 10. Deploying
Static host for quick scan; Docker image (`server/Dockerfile`) for the backend → Render / **Azure Government** /
**AWS GovCloud** (IaC in `deploy/`). `scripts/deploy.sh aws|azure` orchestrates; `scripts/init.sh` hydrates
scanner databases inside the container.

## 11. Roles
- **Developer** — scan a branch, fix critical/high, re-scan.
- **Security** — deep scan, triage, export SARIF.
- **Compliance/GRC** — Compliance tab + POA&M/SSP for ATO packages.
- **CI/CD** — Action/CLI on every PR, gate with `--fail-on`, post the PR comment.

## 12. Scope & limitations
The browser engine is heuristic (fast triage across all 117 languages); the backend adds data-flow-grade
scanners. Neither replaces a credentialed pentest or assessor review. Verify findings before acting.
