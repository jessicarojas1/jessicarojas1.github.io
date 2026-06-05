# CITADEL — Architecture & Module Interaction

An interactive version of this document is at [`docs/index.html`](docs/index.html).

## Design principle

CITADEL is a **layered pipeline of independent modules** attached to a global `CITADEL`
namespace. Modules never call each other directly — they communicate through two plain data
contracts: the **entry** (a normalized file) and the **finding** (a normalized result). This
keeps every analyzer pluggable: adding one rule or one analyzer ripples through scoring,
compliance mapping, charts, and exports with no other changes.

## Pipeline

```
                         app.js  (presentation)
          drag/drop · pickers · tabs · progress · exports
                    │ File[]                 ▲ render(report)
                    ▼                        │
            ingest.js (Ingest Engine)   report.js (Report & Export)
       archive expansion · text/binary       DOM · Chart.js · JSON/MD/SBOM/PDF
                    │ entries[]               ▲ report{}
                    ▼                         │
        ┌────────────── scanner.js (Orchestrator) ──────────────┐
        │  fan-out to analyzers → findings[] → score → map      │
        └───┬────────┬────────┬────────┬────────┬───────────────┘
            ▼        ▼        ▼        ▼        ▼
        languages  rules   secrets   sbom    binary
            │        └────────┴────────┴────────┘ findings[]
            ▼                          ▼
      language stats         frameworks.js (Compliance Mapping)
                              category → control IDs × 20+ standards
```

## The two contracts

**Entry** (produced by the Ingest Engine, consumed by every analyzer):

```js
entry = { path, size, isBinary, lang, content /* text */ | bytes /* binary */, archive? }
```

**Finding** (produced by every analyzer, consumed by scoring, mapping, and reporting):

```js
finding = {
  ruleId, name, category, severity,   // what & how bad
  cwe, confidence,                    // weakness id & certainty
  file, line, snippet,               // where
  remediation                         // how to fix
}
```

## Modules

| Module | File | Role | Responsibility |
|---|---|---|---|
| Ingest Engine | `ingest.js` | Intake | Expand archives (JSZip), skip build/vendor dirs, sniff text vs. binary, normalize to entries. |
| Language Classifier | `languages.js` | Classify | Map 70+ extensions/filenames to languages; mark code-bearing langs; supply chart colors. |
| SAST Rules Engine | `rules.js` + `scanner.js` | Analyze | Language-aware regex rules for injection, XSS, crypto, deserialization, SSRF, traversal, config, etc. |
| Secrets Scanner | `secrets.js` | Analyze | Shannon-entropy + keyword heuristics for hardcoded credentials/keys/tokens. |
| SBOM & Dependency Analyzer | `sbom.js` | Analyze | Parse npm/PyPI/Maven/Go/Gem/Composer/Cargo/NuGet manifests; flag unpinned/pre-release deps; emit CycloneDX 1.5. |
| Binary / Executable Analyzer | `binary.js` | Analyze | Detect PE/ELF/Mach-O; entropy (packing); string extraction; suspicious-capability indicators. |
| Quality & Maintainability | `scanner.js` | Measure | LOC, comment ratio, oversized files, 0–100 maintainability index. |
| Deployment & IaC Detector | `scanner.js` | Measure | Infer Docker, K8s, Helm, Terraform, Bicep, CI/CD, and PaaS deployment models. |
| Scoring & Grading Engine | `scanner.js` | Score | Severity-weighted, volume-normalized security score + A–F grade. |
| Compliance Mapping Engine | `frameworks.js` | Map | Cross-walk finding categories to control IDs across 20+ frameworks; compute posture. |
| Report & Export Engine | `report.js` | Present | Scorecard, charts, finding cards, compliance posture; export JSON/SBOM/Markdown/PDF. |

## Why the contracts matter

- The **Scoring Engine** reads only `severity` — new analyzers automatically affect the grade.
- The **Compliance Mapping Engine** reads only `category` — one new rule lights up the right
  controls across every framework via [`FRAMEWORKS.md`](FRAMEWORKS.md)'s cross-walk.
- The **Report Engine** renders by `severity`/`category` and is otherwise analyzer-agnostic.

## Production tier

The same front-end is containerized (hardened, non-root, FIPS-friendly Nginx) and paired with an
optional API/worker that shells out to integrated open-source scanners for depth. IaC and runbooks
for **Azure Government** and **AWS GovCloud** live in [`deploy/`](deploy/).
