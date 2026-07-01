# CLAUDE.md — CITADEL project guidance

Guidance for working on **CITADEL** (*Code Inspection, Threat Analysis &
Deployment Evaluation Lab*). This file governs this subproject; the repo-root
`CLAUDE.md` still applies.

## What it is

A source-code, executable & script **security & compliance review platform**. It
ingests code / archives / binaries and reports how secure the code is, how
well-written it is, what languages it uses, how it's deployed, and which
compliance obligations it touches — mapping every finding to the controls of
**20+ frameworks** (CMMC, NIST 800-171/53/SSDF/CSF, ISO 27001/42001, SOC 2,
OWASP, PCI, HIPAA, FedRAMP, FIPS 140-3, DFARS, DISA STIG, and more).

## Stack & surfaces

Three surfaces on **one pure, DOM-free engine**:

- **SPA** — vanilla JS + Bootstrap 5.3 + Chart.js + **JSZip**, 100% client-side
  heuristic engine; nothing is uploaded. Lives in `index.html` + `js/`.
- **Deep-scan backend** — Node ≥ 18 / Express 4 (`server/`), runs **real
  scanners** (Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV; +
  Checkov/OSV-Scanner/Hadolint; opt-in CodeQL), normalizes + **merges** them with
  the heuristic engine into the **same report JSON**. Adds JWT auth, RBAC,
  history, AI remediation. Postgres/Redis optional.
- **CLI + GitHub Action** — `cli/citadel-gate.js` + `action.yml`; headless
  release-readiness gate and SARIF-emitting scan for CI.

## Where things live

| Area | Path |
|---|---|
| Engine + UI | `js/` (ingest, languages, rules*, secrets, sbom, binary, frameworks, scanner, report, review-*, …) |
| Backend | `server/server.js`, `server/cli.js`, `server/lib/`, `server/openapi.yaml` |
| Image | `server/Dockerfile` (**build from repo root**), `server/docker-compose.yml` |
| DB | `database/schema.sql` (mirror of `server/lib/db.js` `SCHEMA`) |
| IaC / runbooks | `deploy/{compose,aws,aws-gov,azure-gov,gcp,kubernetes,ci}/` |
| Docs | `docs/` (ARCHITECTURE, DEPLOYMENT, SECURITY, DISASTER_RECOVERY, ENV, RBAC, UPLOAD-SECURITY, RELEASE-READINESS, TESTING) |
| Root deploy | `render.yaml` (standalone), repo-root `render.yaml` (`citadel` service) |

## Conventions

- **Pure, defensive analyzers.** `analyze()` does no network, no DOM, and
  **never throws** — wrap in `try/catch`, skip gracefully. New reviewers follow
  the IIFE-on-`window.CITADEL` pattern (see `docs/RELEASE-READINESS.md` §10).
- **Two contracts only:** the **entry** and the **finding**. Don't couple modules
  directly; add a rule/analyzer and let scoring/mapping/exports pick it up.
- **One report shape everywhere** — server findings must be indistinguishable
  from browser findings.
- JS style: 2-space indent, single quotes, semicolons, no unused vars; keep
  `node --check` and ESLint clean.
- **No false compliance certainty** — mappings point at controls to examine; use
  the hedged `note` phrasings, never a pass/fail verdict.

## Security & UI rules (apply here too)

- **No inline event handlers** (CSP): use delegated `data-*` listeners; script
  tags carry a nonce where a backend renders them.
- Escape all interpolated output; no hardcoded hex where an accent/CSS variable
  belongs; branding (logo URL / name / accent) via Settings, sanitized.
- Backend is authoritative for authz — the SPA gate is UX only
  (`requireAuth` / `requirePerm` / `requireAdmin` + `ownsProject`).
- Uploaded code is **untrusted**: only ever read, never executed; bounded
  extraction (zip-slip + bomb caps); per-scan tmpfs workdir removed in `finally`.
- Secrets from env / secret manager; never commit `.env` (only `.env.example`).
  `CITADEL_DATA_KEY` seals secrets at rest; `CITADEL_AIRGAP` for CUI/ITAR.

## Build / test / deploy

```bash
# SPA (no build step)
python3 -m http.server 8000        # open http://localhost:8000/citadel/

# Backend tests + lint
cd citadel/server && npm ci && npm test && npm run lint

# Accuracy benchmark (CI-gated: recall ≥ 0.90, precision ≥ 0.90)
node citadel/benchmark/run.js

# Build + run the deep-scan image (context = repo root!)
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run -p 8080:8080 citadel-server     # http://localhost:8080/

# Release-readiness gate in CI
node citadel/cli/citadel-gate.js . --fail-on=rejected
```

Deploy targets: Render (`render.yaml`), single Linux server, Kubernetes, AWS
(Commercial + GovCloud), Azure (Commercial + Government), GCP, air-gapped — see
`docs/DEPLOYMENT.md` and `deploy/`. Needs **≥ 2 GB RAM** (ClamAV DB). Health at
`/api/health`.

## Standing rule

Keep the doc set — **`docs/` (ARCHITECTURE, DEPLOYMENT, SECURITY,
DISASTER_RECOVERY, ENV, RBAC, UPLOAD-SECURITY, RELEASE-READINESS, TESTING),
`README.md`, `OPEN_ITEMS.md`, and the `deploy/` runbooks** — **updated as the app
changes**. When you add a migration, update `database/schema.sql` to match
`server/lib/db.js`. When you add a scanner/reviewer, update `CAPABILITIES.md`,
`ARCHITECTURE.md`, and `RELEASE-READINESS.md`.
