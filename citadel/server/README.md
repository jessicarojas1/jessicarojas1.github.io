# CITADEL — Deep-Scan Backend

A **Node.js 20 / Express** API service that runs **real open-source security
scanners** over uploaded code and returns the same report JSON the CITADEL SPA
already renders. This is the **production tier** referenced by
[`../README.md`](../README.md) and [`../ARCHITECTURE.md`](../ARCHITECTURE.md):
where the public demo analyzes files **entirely in the browser** with heuristic,
pattern-based engines, this backend shells out to industrial scanners for depth
suitable for an authorization decision.

> ⚠️ **Untrusted input.** Uploaded code is treated as hostile. The scanners only
> **read** files — nothing uploaded is ever executed, imported, built, or run.
> The container runs non-root with a read-only root filesystem and a single
> writable scratch directory.

---

## Demo vs. deep-scan: what's different

| | Client-side demo (`citadel/`) | Deep-scan backend (`citadel/server/`) |
|---|---|---|
| Where it runs | 100% in the browser (JSZip) | Node/Express container |
| Engines | Heuristic regex/entropy/AST-lite | Real scanners (below) + the heuristic engine |
| Data | Nothing leaves the browser | Files uploaded to the server's temp dir, scanned, discarded |
| Use | Triage, education, demos | Pre-ATO depth, CI gates, CUI-bearing review |

The deep-scan backend **does not replace** the heuristic engine — it **augments**
it. Heuristic findings and real-scanner findings are normalized to the same
finding shape and **merged** into one report.

### The real scanners and what each contributes

| Scanner | Type | Contribution to the report |
|---|---|---|
| **Semgrep** | Multi-language SAST | Data-flow-aware static analysis across many languages; the core SAST signal. |
| **Bandit** | Python SAST | Python-specific AST security checks (eval, weak crypto, shell injection, etc.). |
| **Trivy** | FS vuln + secret + misconfig | Dependency CVEs, hardcoded secrets, and IaC/Dockerfile misconfiguration. |
| **Syft** | SBOM | Generates the CycloneDX Software Bill of Materials from the uploaded tree. |
| **Grype** | Vulnerability matching | Matches the Syft SBOM / directory against vulnerability databases (CVEs). |
| **Gitleaks** | Secrets | Git-aware and content-based credential/secret detection. |
| **ClamAV** | Malware | Signature-based malware scan of every uploaded file. |

Missing scanners **degrade gracefully** — `server.js` runs whatever is installed
and omits the rest, so the API never hard-fails because one tool is absent.

---

## Architecture

```
  Browser SPA (citadel/index.html)
        │  multipart POST /api/scan  (zip or files, field "files")
        ▼
  Express server.js
        │  1. write upload to a per-request dir under $CITADEL_TMP (/tmp/citadel)
        │  2. extract archives (zip/jar/apk) into that scratch dir
        │  3. fan out scanners IN PARALLEL over the extracted tree:
        │        Semgrep · Bandit · Trivy · Syft → Grype · Gitleaks · ClamAV
        │  4. adapters (server/lib/) normalize each tool's native output into
        │     CITADEL's finding shape (ruleId, name, category, severity, cwe,
        │     confidence, file, line, snippet, remediation)
        │  5. MERGE with the heuristic engine's findings
        │  6. score, grade, and map to the 20+ compliance frameworks
        │  7. delete the scratch dir
        ▼
  Report JSON  ──►  the SPA renders it exactly as it renders a local scan
```

The **finding contract** is the one defined in
[`../ARCHITECTURE.md`](../ARCHITECTURE.md): each adapter emits
`{ ruleId, name, category, severity, cwe, confidence, file, line, snippet, remediation }`,
which means scoring, compliance mapping, charts, and exports work unchanged —
the SPA cannot tell a server finding from a browser finding.

> The Express app, `package.json`, and the adapter library under `server/lib/`
> are maintained separately. This document and the container files
> (`Dockerfile`, `.dockerignore`, `docker-compose.yml`) are the build &
> deployment runbook for that service.

---

## API reference

### `GET /api/health`

Liveness/readiness probe. Returns `200` with a small JSON body. Used by the
container `HEALTHCHECK` and by Render / Container Apps / ECS health checks.

```bash
curl -fsS http://localhost:8080/api/health
# {"status":"ok", ...}
```

### `POST /api/scan`

Multipart upload of code to scan. Field name: **`files`** (one archive or
multiple files). Returns the report JSON the SPA renders.

```bash
# Scan a zipped project
curl -sS -X POST http://localhost:8080/api/scan \
  -F "files=@./my-project.zip" \
  -o report.json

# Scan several loose files in one request
curl -sS -X POST http://localhost:8080/api/scan \
  -F "files=@./app.py" \
  -F "files=@./package.json" \
  -o report.json
```

The response is the same `report{}` shape produced by the client-side
orchestrator (`meta`, `languages`, `findings`, `sbom`, `binaries`, `quality`,
`deployment`, `scoring`, `posture`) — see `citadel/js/scanner.js`.

### Static SPA

The server also serves the CITADEL front-end from the bundled `citadel/`
directory, so opening **http://localhost:8080/** gives the full UI, which then
calls `/api/scan` instead of analyzing locally.

---

## Build & run locally

Everything is built from the **repository root** because the image copies both
the SPA (`citadel/`) and the backend (`citadel/server/`).

### With Docker directly

```bash
# from the repo root
docker build -f citadel/server/Dockerfile -t citadel-server .
docker run --rm -p 8080:8080 \
  --read-only \
  --tmpfs /tmp/citadel:size=2g \
  --cap-drop ALL \
  --security-opt no-new-privileges:true \
  citadel-server
```

### With Docker Compose (recommended)

The compose file already wires up the read-only FS, tmpfs scratch, capability
drop, healthcheck, and the persistent ClamAV DB volume.

```bash
# from the repo root
docker compose -f citadel/server/docker-compose.yml up --build
```

Then open **http://localhost:8080/** and upload a project, or hit the API with
the `curl` examples above.

First run note: Trivy and Grype download their vulnerability databases on first
use, and ClamAV may refresh signatures — the first scan can be slow. See
**Operational notes**.

---

## Deploy to Render.com

CITADEL's deep-scan backend deploys as a **Docker Web Service** on Render.

**Render service settings**

| Setting | Value |
|---|---|
| Environment | Docker |
| Dockerfile path | `citadel/server/Dockerfile` |
| Docker build context | repository root (`.`) |
| Port | `8080` (Render injects `$PORT`; the server already honours it) |
| Health check path | `/api/health` |
| Instance type | **Standard or larger (≥ 1 GB RAM, ideally 2 GB+)** |

> ⚠️ **The free tier is almost certainly too small.** The bundled toolchain
> (Semgrep + Trivy + Grype + ClamAV) is CPU- and memory-heavy; ClamAV alone
> wants ~1 GB resident. Use a **paid instance** with at least 1 GB RAM (2 GB+
> recommended) or scans will OOM / time out.

Add a `render.yaml` blueprint at the repo root (or merge into the existing one):

```yaml
services:
  - type: web
    name: citadel-deep-scan
    runtime: docker
    dockerfilePath: citadel/server/Dockerfile
    dockerContext: .
    plan: standard          # NOT free — needs ≥1GB RAM (2GB+ recommended)
    healthCheckPath: /api/health
    envVars:
      - key: NODE_ENV
        value: production
      - key: PORT
        value: "8080"
```

Render terminates TLS for you and routes to port 8080. The container's own
healthcheck and Render's `/api/health` probe are complementary.

---

## Government cloud

The **same image** is what the FedRAMP-High / IL4–IL5 Infrastructure-as-Code
under [`../deploy/`](../deploy/) deploys — there is no separate build:

- **Azure Government** → Azure Container Apps (Bicep, `../deploy/azure-gov/`)
- **AWS GovCloud (US)** → ECS Fargate (Terraform, `../deploy/aws-gov/`)

In those environments give the task **more CPU and RAM** than the local
defaults (the scanners are heavier under real workloads), and **uploads are
quarantined** in immutable, KMS-encrypted object storage per that IaC rather
than living only in the container's tmpfs. The shared hardening posture
(non-root, read-only root FS, dropped capabilities, vault-sourced secrets,
WAF-only ingress, image scan-on-push) is documented in
[`../deploy/README.md`](../deploy/README.md) and cross-walked to NIST SP 800-53
Rev 5 / CMMC 2.0.

---

## Operational notes

- **ClamAV signature DB.** The image seeds the DB at build time, but signatures
  age fast. Run `freshclam` on a schedule (cron/sidecar) or at startup, and keep
  the `/var/lib/clamav` volume persistent (compose already does this) so updates
  survive restarts. A stale or missing DB degrades gracefully — ClamAV is simply
  skipped.
- **Trivy / Grype vuln DBs.** Both download on first use. For air-gapped Gov
  deploys, pre-pull at deploy time (`trivy --download-db-only`, `grype db
  update`) and mount the DB cache, or mirror it in-boundary.
- **Scan timeouts.** Each scanner is run with a bounded timeout so a pathological
  input can't hang a request; a tool that exceeds its budget is dropped from the
  merged report rather than failing the whole scan.
- **Max upload size.** Uploads are capped (multipart limit) to bound disk/CPU.
  Tune it to your largest expected project; oversized uploads are rejected.
- **Untrusted code is never executed.** Scanners read source/artifacts only —
  no install, build, or run step touches uploaded code. Combined with non-root +
  read-only root FS + dropped capabilities + `no-new-privileges`, a malicious
  upload cannot escalate.
- **Scaling / workers.** Scans are CPU/RAM bound and bursty. Prefer horizontal
  scaling (more replicas) over one huge instance, and consider a job/worker
  queue so a long scan doesn't block the request thread. Set resource limits
  (see `docker-compose.yml`) so one scan can't OOM its neighbours.
- **Cost.** Because each instance must carry the full toolchain and ≥1 GB RAM,
  this tier costs meaningfully more than the static demo. Run the **free,
  client-side demo** for casual triage; reserve the deep-scan backend for work
  that needs real-scanner depth.

---

## Limitations & security considerations

- **Results depend on installed tools and DB freshness.** A skipped scanner or a
  stale CVE/signature DB means lower coverage — monitor that `freshclam` and the
  Trivy/Grype DB updates are succeeding.
- **No tool is complete.** SAST/secret/SBOM scanners produce false positives and
  false negatives; treat findings as triage input, not verdicts. For an ATO,
  have results reviewed by a qualified assessor.
- **Defense in depth on uploads.** Even though code is never executed, treat the
  scratch dir as a blast zone: keep it on `tmpfs`, never on a shared/persistent
  mount, and quarantine retained uploads (the Gov IaC does this with immutable,
  encrypted storage).
- **Resource exhaustion is the main DoS vector.** Enforce upload-size caps, scan
  timeouts, and per-container resource limits; rate-limit `/api/scan` at the
  edge/WAF in production.
- **Supply chain.** Pin scanner versions (the Dockerfile does) and pin the base
  image by digest in production; scan the resulting image on push (`RA-5`,
  `SI-2`) as the Gov IaC requires.

_Built by Jessica Rojas. Real scanners assist — verify findings before acting._
