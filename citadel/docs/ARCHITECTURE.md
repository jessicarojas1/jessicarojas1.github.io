# CITADEL — Architecture

> Operator- and engineer-grade architecture reference. An interactive version of
> the module map lives at [`index.html`](index.html). This document is the
> canonical, folded-in successor to the former root `ARCHITECTURE.md` (which now
> links here).

**CITADEL** — *Code Inspection, Threat Analysis & Deployment Evaluation Lab* —
is a source-code, executable & script **security & compliance review platform**.
It ingests code / archives / binaries and reports how secure the code is, how
well-written it is, what languages it uses, how it is deployed, and which
compliance obligations it touches — mapping every finding to the controls of
20+ frameworks.

Related docs: [DEPLOYMENT.md](DEPLOYMENT.md) · [SECURITY.md](SECURITY.md) ·
[DISASTER_RECOVERY.md](DISASTER_RECOVERY.md) · [ENV.md](ENV.md) ·
[RBAC.md](RBAC.md) · [UPLOAD-SECURITY.md](UPLOAD-SECURITY.md) ·
[RELEASE-READINESS.md](RELEASE-READINESS.md) · [TESTING.md](TESTING.md) ·
deployment targets under [`../deploy/`](../deploy/).

---

## 1. Platform

CITADEL ships as **three cooperating surfaces built on the same analysis engine**:

| Surface | Runtime | Built on | Role |
|---|---|---|---|
| **SPA** (`index.html`, `js/`) | Any browser, any static host | Vanilla JS + Bootstrap 5.3 + Chart.js + **JSZip** | Client-side heuristic engine — ingest, classify, SAST, secrets, SBOM/CVE, binary, quality, deploy detect, scoring, compliance mapping, reporting/exports. Nothing is uploaded. |
| **Deep-scan backend** (`server/`) | Node.js ≥ 18 (image: Node 20) + Express 4 | Real OSS scanners + the same engine loaded server-side | Runs **Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV** (plus Checkov, OSV-Scanner, Hadolint; opt-in CodeQL) over an upload, normalizes + merges their output with the heuristic engine, and returns the **same report JSON** the SPA renders. Adds auth/RBAC, history, AI remediation. |
| **CLI + GitHub Action** (`cli/`, `action.yml`) | Node 20 in CI | The pure engine modules | Headless **release-readiness gate** and a scan Action that emits SARIF and fails the build on a configurable severity/decision. |

The engine modules are **pure and DOM-free**, so the exact same analysis runs in
the browser, in a scan Web Worker, on the server, and in the CLI. A finding is
identical regardless of where it was produced.

---

## 2. Design principles

- **Layered pipeline of independent modules** attached to a global `CITADEL`
  namespace. Modules never call each other directly — they communicate through
  two plain data contracts: the **entry** (a normalized file) and the **finding**
  (a normalized result). Adding one rule or analyzer ripples through scoring,
  compliance mapping, charts, and exports with no other changes.
- **One report shape everywhere.** The server cannot tell a browser finding from
  a scanner finding; the SPA cannot tell a local scan from a deep scan.
- **Pure, defensive analyzers.** `analyze()` functions do no network and no DOM
  access and never throw — a reviewer failure can never break the underlying
  scan (`try/catch` + graceful skip).
- **Secure by default.** With nothing configured the backend runs single-admin,
  in-memory, network-restricted; a loud warning fires on a production-looking
  deploy with enforcement off.
- **Untrusted input boundary.** Uploaded code is only ever **read** — never
  executed, imported, built, or run — inside a bounded, per-scan workdir.
- **No false compliance certainty.** Mappings point an assessor at the relevant
  control; they are never a pass/fail verdict.

### The two contracts

**Entry** (produced by the Ingest Engine, consumed by every analyzer):

```js
entry = { path, size, isBinary, lang, content /* text */ | bytes /* binary */, archive? }
```

**Finding** (produced by every analyzer, consumed by scoring, mapping, reporting):

```js
finding = {
  ruleId, name, category, severity,   // what & how bad
  cwe, confidence,                    // weakness id & certainty
  file, line, snippet,               // where
  remediation, source                 // how to fix + origin (heuristic|semgrep|…)
}
```

Reviewers add enterprise extras (`module`, `impact`, `likelihood`,
`remediationEffort`, `references`, `complianceMappings`) — see
[RELEASE-READINESS.md §3](RELEASE-READINESS.md).

---

## 3. Component overview

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
                                          │
                                          ▼
             reviews (logging · testing · threatmodel · architecture ·
                      container · operations)  →  review-readiness.js (gate)
```

| Module | File(s) | Role |
|---|---|---|
| Ingest Engine | `js/ingest.js` | Expand archives (JSZip), skip build/vendor dirs, sniff text vs. binary, normalize to entries. |
| Language Classifier | `js/languages.js` | Map extensions/filenames to 100+ languages/formats; mark code-bearing langs; chart colors. |
| SAST Rules Engine | `js/rules*.js` + `js/scanner.js` | Language-aware rules for injection, XSS, crypto, deserialization, SSRF, traversal, XXE, config, IaC, CI/CD, API, PII, mobile, Java taint. |
| Secrets Scanner | `js/secrets.js` | Shannon-entropy + keyword heuristics for hardcoded credentials/keys/tokens; Luhn PAN masking. |
| SBOM & Dependency Analyzer | `js/sbom.js`, `js/depreview*.js` | Parse npm/PyPI/Maven/Go/Gem/Composer/Cargo/NuGet manifests; flag unpinned/pre-release deps; emit CycloneDX 1.5. |
| CVE / advisory | `js/osv.js`, `js/advisories.js`, `js/exploit.js`, `js/reachability.js` | Cross-check deps against **OSV.dev** (client-side, keyless) for real CVEs; EPSS/KEV/reachability enrichment. |
| Binary Analyzer | `js/binary.js` | Detect PE/ELF/Mach-O/WASM/DEX/class; entropy (packing); string extraction; suspicious-capability indicators. |
| Quality / Deploy detect | `js/scanner.js` | LOC, comment ratio, oversized files, maintainability index; Docker/K8s/Helm/Terraform/Bicep/CI-CD/PaaS detection. |
| Scoring & Grading | `js/scanner.js` | Severity-weighted, volume-normalized security score + A–F grade. |
| Compliance Mapping | `js/frameworks.js`, `js/controls-*.js` | Cross-walk finding categories to control IDs across 20+ frameworks; compute posture. |
| Readiness reviewers | `js/review-*.js` | Logging, testing, threat-model (STRIDE), architecture, container, operations; roll into a 0–100 readiness score + gate decision. |
| Report & Export | `js/report.js`, `js/sarif.js`, `js/spdx.js` | Scorecard, charts, finding cards, compliance posture; export JSON / **SARIF 2.1.0** / CycloneDX / POA&M (CSV) / SSP appendix (MD) / JUnit XML / PR-comment MD / Markdown / PDF. |
| History / triage | `js/history.js`, `js/projects.js`, `js/portfolio.js` | Local scan history, run-to-run compare, risk acceptance, portfolio view. |
| AI remediation | `js/remediate.js` + backend `/api/explain` | Per-finding "Explain & fix" (backend + `ANTHROPIC_API_KEY`) + copy-paste fix prompt. |
| Worker | `js/worker.js` | Runs the analysis pipeline off the main thread (`self.window = self`). |

### Backend library (`server/lib/`)

`scanners.js` (adapters) · `normalize.js` (severity + CWE→category) ·
`engine.js` (reuses the browser engine server-side, merges results) ·
`scanWorker.js` (worker-thread isolation) · `ai.js` (Anthropic remediation) ·
`users.js`, `jwt.js`, `sessions.js`, `totp.js`, `oidc.js` (identity) ·
`ratelimit.js`, `audit.js`, `validate.js` (defense) · `db.js` (Postgres, canonical
`SCHEMA`) · `scans.js`, `projects.js`, `dispositions.js`, `depapprovals.js`,
`threatmodel.js`, `readiness.js` (persistence) · `secretbox.js` (AES-256-GCM seal) ·
`fips.js`, `tenancy.js`, `notify.js`, `metrics.js`, `tracing.js`, `log.js`.

---

## 4. Monorepo placement & internal layout

CITADEL is one project in the `jessicarojas1.github.io` monorepo. Its own tree:

```
citadel/
├── index.html / admin.html / 404.html   # SPA + admin console
├── css/                                  # Bootstrap 5.3 + theme
├── js/                                   # the analysis engine + UI (see §3)
├── cli/                                  # citadel-gate.js + composite action.yml
├── action.yml                            # top-level GitHub Action (scan → SARIF)
├── benchmark/                            # accuracy corpus + OWASP runner (CI-gated)
├── database/schema.sql                   # manual-setup Postgres reference (mirrors db.js)
├── server/                               # deep-scan backend
│   ├── server.js                         # Express API + serves the SPA
│   ├── cli.js                            # server-side CLI
│   ├── lib/                              # scanners + adapters + identity + persistence
│   ├── Dockerfile                        # image with all scanners (build from repo root)
│   ├── docker-compose.yml                # local backend
│   ├── openapi.yaml                      # OpenAPI 3.0 contract
│   └── test/                             # lib/api/cli/smoke suites
├── deploy/                               # target IaC + runbooks
│   ├── compose/  aws/  aws-gov/  azure-gov/  gcp/  kubernetes/  ci/
├── docs/                                 # THIS doc set + ENV/RBAC/UPLOAD-SECURITY/…
├── render.yaml                           # standalone Render Blueprint
├── README.md / CLAUDE.md / OPEN_ITEMS.md
└── ARCHITECTURE.md / CAPABILITIES.md / FRAMEWORKS.md / HOW_IT_WORKS.md / …
```

---

## 5. Configuration model

All backend configuration is **environment variables** — full reference in
[ENV.md](ENV.md). The service is secure-by-default: unset variables yield a
single-admin, in-memory, network-restricted instance. Highlights:

| Concern | Key variables |
|---|---|
| Core | `PORT` (8080), `NODE_ENV`, `CITADEL_APP_DIR`, `CITADEL_TMP`, `CITADEL_DATA_DIR`, `LOG_LEVEL` |
| Sessions | `CITADEL_JWT_SECRET` (HS256), `CITADEL_ADMIN_EMAIL/PASSWORD`, `*_TTL`, `CITADEL_DATA_KEY` (seal secrets at rest), `TRUST_PROXY_HOPS` |
| SSO | `OIDC_ISSUER/CLIENT_ID/CLIENT_SECRET/REDIRECT_URI`, `OIDC_ADMIN_EMAILS`, `OIDC_ALLOWED_DOMAINS` |
| Persistence | `DATABASE_URL` (Postgres), `PGSSL*`, `REDIS_URL` (shared rate-limit/lockout) |
| Scanning caps | `MAX_UPLOAD_BYTES`, `CITADEL_MAX_UNZIP_BYTES/ENTRIES`, `SCAN_CONCURRENCY`, `SCAN_TIMEOUT_MS`, `CITADEL_SCAN_ISOLATION` |
| AI / air-gap | `ANTHROPIC_API_KEY`, `CITADEL_AI_MODEL`, `CITADEL_AIRGAP` / `CITADEL_NO_EGRESS` |
| Observability | `CITADEL_METRICS_TOKEN`, `CITADEL_TRACING`, `OTEL_EXPORTER_OTLP_ENDPOINT`, `CITADEL_AUDIT_SINK_URL` |
| Compliance mode | `CITADEL_FIPS` (forces PBKDF2-HMAC-SHA256), `CITADEL_MULTITENANT` |

Front-end-only branding (logo/name/accent) persists to the backend settings when
present, else `localStorage`; the backend value wins.

---

## 6. Request & error contract

### Routing

The Express app serves the SPA statically and exposes a JSON `/api/*` surface
(OpenAPI 3.0 in [`server/openapi.yaml`](../server/openapi.yaml), also at
`GET /api/openapi.yaml`). Selected routes:

| Method + path | Auth | Purpose |
|---|---|---|
| `GET /api/health` | none | Liveness/readiness; scanner availability; auth mode. |
| `POST /api/scan` | `analyze` | Multipart `files` (zip or many) → report JSON. |
| `POST /api/scan-url` | `deepscan` | Scan a public repo by URL (SSRF-guarded). |
| `POST /api/explain` | `tab-aifix` | AI "Explain & fix" for one finding. |
| `POST /api/auth/login` · `/mfa/verify` · `/refresh` · `/logout` | rate-limited | Session lifecycle (JWT + httpOnly refresh cookie). |
| `GET /api/auth/me` · `/mfa` · `POST /password` | bearer | Self-service account. |
| `GET/POST/PATCH/DELETE /api/users`, `/sessions`, `/audit`, `/branding`, `/auth/settings` | `requireAdmin` | Admin console. |
| `/api/scans`, `/api/projects`, `/api/dispositions`, `/api/dep-approvals`, `/api/threatmodel` | per-`requirePerm` + `ownsProject` | History, triage, ownership-scoped. |
| `GET /metrics` | token/loopback | Prometheus scrape. |

### Response & error shape

Successful calls return domain JSON. Errors return a consistent envelope:

```json
{ "error": "human-readable message" }
```

with occasional extra fields (`retryAfter` on `429`/`503`, `mustChange` on the
password-gate `403`). Status codes used: `400` bad/oversized input (no stack
leak), `401` unauthenticated / bad token / revoked session, `403` no permission
or must-change-password, `404` unknown tenant / hidden route, `413`/`400` upload
rejected (bomb/zip-slip), `429` rate-limited, `503` limiter/heavy-route fail-closed.

### `GET /api/health` body

```json
{ "ok": true, "version": "1.0", "engine": "deep", "ai": false, "airgap": false,
  "fips": { "active": false }, "auth": { "enforce": false, "sso": false },
  "store": { "users": "memory", "durable": false },
  "scanners": [ { "tool": "semgrep", "available": true }, … ] }
```

Tool versions and internal store details are **admin-only** (recon hardening).

---

## 7. Security model

- **Identity:** HS256 JWT access tokens (short-lived) + long-lived refresh token
  bound to an **httpOnly, Secure, SameSite=Strict** cookie scoped to `/api/auth`
  (XSS cannot read it). Optional **TOTP MFA** with one-time backup codes; optional
  **OIDC/PKCE** SSO with JIT provisioning. JWT is **alg-pinned** (no `alg:none`).
- **Authorization:** backend-enforced **RBAC** (`requireAuth` / `requirePerm(page)`
  / `requireAdmin`) plus per-resource **ownership** (`ownsProject`, no IDOR). The
  SPA gate is UX only. See [RBAC.md](RBAC.md).
- **Upload boundary:** bounded extraction (zip-slip block, decompression-bomb
  caps), read-only handling, per-scan workdir removed in `finally`, optional
  ClamAV. See [UPLOAD-SECURITY.md](UPLOAD-SECURITY.md).
- **Secrets at rest:** `CITADEL_DATA_KEY` AES-256-GCM-seals the JWT secret and
  TOTP seeds; passwords hashed with scrypt (or PBKDF2-HMAC-SHA256 in FIPS mode),
  compared timing-safe.
- **Egress control:** `CITADEL_AIRGAP` / `CITADEL_NO_EGRESS` hard-disable AI and
  outbound enrichment so CUI/ITAR source never leaves the boundary. SSRF guard on
  `scan-url`. Rate-limit + lockout (Redis-shared when clustered).
- **Auditability:** hash-chained, tamper-evident audit log; optional SIEM
  forwarding (`CITADEL_AUDIT_SINK_URL`). Source, secrets, tokens are **never**
  logged; PANs masked to last four. Full detail in [SECURITY.md](SECURITY.md).

---

## 8. Observability

| Signal | Where |
|---|---|
| **Health** | `GET /api/health` (also the container `HEALTHCHECK` and orchestrator probes). |
| **Metrics** | Prometheus `GET /metrics` (Bearer `CITADEL_METRICS_TOKEN` or loopback): active sessions, uptime, RSS, HTTP middleware counters. |
| **Logs** | Structured JSON to stdout (`log.js`, `CITADEL_SERVICE_NAME`, `LOG_LEVEL`); metadata only, never source/secrets. |
| **Traces** | Optional OpenTelemetry (`CITADEL_TRACING` / `OTEL_EXPORTER_OTLP_ENDPOINT`); tracing deps are opt-in in the image. |
| **Audit** | Hash-chained event log with `GET /api/audit/verify` and optional SIEM sink. |
| **Notify** | Slack-compatible scan summary webhook when severity ≥ threshold. |

---

## 9. Deployment topology & the multi-engine scan pipeline

**Static-only:** the SPA on any static host (GitHub Pages, Render static) — quick
scans run entirely client-side; no backend, no persistence.

**Full backend (reference):** the `server/Dockerfile` image (SPA + all scanners)
behind a TLS terminator, `/api/health` probed, scratch space as non-persistent
tmpfs, container read-only + cap-dropped + `no-new-privileges`. Optional Postgres
(shared users/history/audit) and Redis (shared rate-limit). Targets: Render,
single Linux server, Kubernetes, AWS (Commercial + GovCloud), Azure (Commercial +
Government), GCP, and air-gapped — IaC/runbooks under [`../deploy/`](../deploy/)
and [DEPLOYMENT.md](DEPLOYMENT.md).

The deep-scan pipeline inside `server.js`:

```
POST /api/scan (multipart "files")
  1. write upload to a per-request dir under $CITADEL_TMP
  2. extract archives (zip/jar/apk) via safeJoin (zip-slip + bomb guarded)
  3. fan out scanners IN PARALLEL (SCAN_CONCURRENCY) over the extracted tree:
        Semgrep · Bandit · Trivy · Syft → Grype · Gitleaks · ClamAV
        (+ Checkov · OSV-Scanner · Hadolint; opt-in CodeQL)
  4. adapters (lib/scanners.js, lib/normalize.js) normalize each tool's native
     output → CITADEL's finding shape
  5. MERGE with the heuristic engine (lib/engine.js) — dedup/fingerprint
  6. score, grade, map to the 20+ compliance frameworks, run readiness reviewers
  7. delete the scratch dir (finally)
  ▶ Report JSON — the SPA renders it exactly as a local scan
```

Missing scanners degrade gracefully: the server runs whatever is installed and
omits the rest, so the API never hard-fails because one tool is absent.
