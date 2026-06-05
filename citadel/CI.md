# CITADEL — CLI & CI Integration

CITADEL is a source-code & executable security/compliance scanner. This document
covers the **command-line runner** (`citadel/server/cli.js`) and the **GitHub
Action** (`citadel/action.yml`) that wraps it for CI, including how SARIF results
feed GitHub code scanning.

---

## 1. The CLI runner

`citadel/server/cli.js` scans a local path using the exact same toolchain as the
CITADEL server: it runs the real open-source scanners (Semgrep, Bandit, Gitleaks,
Trivy, Syft, Grype, ClamAV) via `lib/scanners.runAll()`, merges their findings
with the heuristic engine via `lib/engine.analyzeDir()`, and produces one unified
report. It then emits **SARIF 2.1.0** (for GitHub code scanning) or the **full
CITADEL JSON report**, and sets an exit code suitable for gating CI.

Scanners that are not installed degrade gracefully (they are skipped) — so the CLI
runs anywhere, but you get the most coverage inside the CITADEL Docker image,
which bundles every scanner.

### Usage

```
node cli.js <path> [--format sarif|json] [--output <file>] [--fail-on critical|high|medium|low|none]
```

It can be run directly via:

```bash
node citadel/server/cli.js <path>
```

> **`bin` note:** `cli.js` carries a `#!/usr/bin/env node` shebang, so it is
> directly executable (`chmod +x citadel/server/cli.js && ./citadel/server/cli.js .`).
> `citadel/server/package.json` is owned by another process and is intentionally
> **not** modified here, but if you want a `citadel` command you can add a
> `"bin": { "citadel": "cli.js" }` entry to it yourself. For now the supported,
> documented entry point is `node citadel/server/cli.js`.

### Flags

| Flag | Values | Default | Description |
|------|--------|---------|-------------|
| `<path>` | a directory or file | _(required)_ | The path to scan. |
| `--format` | `sarif`, `json` | `sarif` | Output format. `sarif` is for GitHub code scanning; `json` is the full CITADEL report (`meta`, `languages`, `findings`, `sbom`, `binaries`, `quality`, `deployment`, `scoring`, `posture`). |
| `--output` | a file path | _(stdout)_ | Where to write the report. If omitted, the report is written to **stdout**. |
| `--fail-on` | `critical`, `high`, `medium`, `low`, `none` | `high` | Severity threshold that fails the build. `none` never fails. |
| `-h`, `--help` | — | — | Show help and exit. |

The human-readable summary (grade, security/overall score, counts by severity,
active/missing scanners, top 5 findings) is always printed to **stderr**, so
**stdout stays machine-readable** — you can pipe it straight to a file or tool.

### Examples

```bash
# SARIF to stdout (summary on stderr), fail the build on high/critical:
node citadel/server/cli.js .

# SARIF to a file for upload to code scanning:
node citadel/server/cli.js ./src --format sarif --output citadel-results.sarif

# Full JSON report, only fail on critical findings:
node citadel/server/cli.js . --format json --output report.json --fail-on critical

# Report only — never fail the build (useful for an initial baseline):
node citadel/server/cli.js . --fail-on none
```

### Exit-code semantics (CI gating)

| Exit code | Meaning |
|-----------|---------|
| `0` | Success — no finding at or above `--fail-on` (or `--fail-on none`). |
| `1` | One or more findings at or above the `--fail-on` severity. CI should treat this as a failed build. |
| `2` | Usage error (bad flags / missing path) or a fatal runtime error. |

Severity ordering, most severe first: `critical > high > medium > low > info`.
`--fail-on high` therefore fails on any **critical or high** finding; `--fail-on
medium` adds medium; and so on. `info` is never a gating severity unless you
extend the threshold yourself.

### SARIF fallback

SARIF is built by `citadel/js/sarif.js` (`CITADEL.sarif.fromReport(report)`),
loaded the same way the engine loads its other browser modules (read + eval with
a `window`/global shim). If that builder is unavailable for any reason, the CLI
prints a warning to stderr and **falls back to JSON output** so the run still
produces a usable report (the exit-code gating still applies).

---

## 2. Running locally

The CLI needs the backend dependencies and, for full coverage, the scanners.

**Option A — native (host has Node + the scanners):**

```bash
cd citadel/server
npm ci                 # install backend deps (express/multer/adm-zip etc.)
cd ../..
node citadel/server/cli.js .
```

Without the scanner binaries installed (Semgrep, Bandit, Gitleaks, Trivy, Syft,
Grype, ClamAV) the CLI still runs — those scanners are skipped and you get
heuristic-engine findings only. The summary lists which scanners were active vs.
missing.

**Option B — Docker (recommended; all scanners bundled & pinned):**

The Dockerfile's build context is the **repository root**, and it copies
`citadel/server/` to `/app/`, so `cli.js` lands at `/app/cli.js`.

```bash
# Build the image (from the repo root — note the trailing "."):
docker build -f citadel/server/Dockerfile -t citadel-scan .

# Scan the current directory; report is written back into the mounted workspace:
docker run --rm -v "$PWD:/scan" -w /scan citadel-scan \
  node /app/cli.js /scan --format sarif --output /scan/citadel-results.sarif --fail-on high
```

---

## 3. The GitHub Action

`citadel/action.yml` is a **composite action** ("CITADEL Security & Compliance
Scan"). It:

1. **Builds the CITADEL image** from `citadel/server/Dockerfile` (build context =
   repo root), so every scanner is present and version-pinned. Because the
   Dockerfile copies `citadel/server/` to `/app/`, the CLI is available at
   `/app/cli.js`.
2. **Runs the scan inside the image**, mounting the workspace at `/scan`:

   ```bash
   docker run --rm -v "$PWD:/scan" -w /scan citadel-scan \
     node /app/cli.js /scan --format <format> --output /scan/<output> --fail-on <fail-on>
   ```

   The CLI exit code is captured (not allowed to abort the job yet) so the SARIF
   upload can still run.
3. **Uploads SARIF** to GitHub code scanning via
   `github/codeql-action/upload-sarif@v3` (only when `format: sarif` and the file
   exists), using `category: citadel`. This runs with `always()` so results are
   visible even when the gate fails.
4. **Enforces the gate** last: if the CLI exited non-zero (findings reached
   `--fail-on`), the action fails the job — after the results have been uploaded.

### Inputs

| Input | Default | Description |
|-------|---------|-------------|
| `path` | `.` | Path within the workspace to scan. |
| `format` | `sarif` | `sarif` or `json`. |
| `output` | `citadel-results.sarif` | Report filename written into the workspace. |
| `fail-on` | `high` | `critical` / `high` / `medium` / `low` / `none`. |

### Outputs

| Output | Description |
|--------|-------------|
| `results-file` | Path to the generated report file (within the workspace). |

---

## 4. Example workflow

Copy `citadel/.github-workflow-example.yml` to **`.github/workflows/citadel.yml`**
at the root of your repository. Key points:

- It runs on `push` (main), `pull_request`, and manual `workflow_dispatch`.
- It grants **`security-events: write`** — **required** for the SARIF upload — plus
  `contents: read` for checkout.
- If CITADEL lives in the same repo, it uses the local action via `uses: ./citadel`.
  If you consume CITADEL from another repo, replace that with
  `your-org/citadel@v1`.

```yaml
name: CITADEL Security & Compliance Scan

on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch:

permissions:
  contents: read
  security-events: write

jobs:
  citadel:
    name: Scan with CITADEL
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4
      - name: Run CITADEL
        uses: ./citadel
        with:
          path: .
          format: sarif
          output: citadel-results.sarif
          fail-on: high
```

---

## 5. How SARIF feeds GitHub code scanning

SARIF (Static Analysis Results Interchange Format) 2.1.0 is GitHub's native
format for security findings. When the action uploads the SARIF file via
`github/codeql-action/upload-sarif`:

- Findings appear under **Security → Code scanning alerts** in the repository.
- On pull requests, findings are annotated **inline on the changed lines**, so
  reviewers see them in the diff.
- Each CITADEL finding maps to a SARIF *result* with its rule (`ruleId`),
  severity, CWE, location (`file`:`line`), message, and remediation guidance.
- The `category: citadel` keeps CITADEL's alerts distinct from other analysis
  tools you may run (e.g. CodeQL), so uploads don't overwrite each other.

> The workflow MUST have `security-events: write` or the upload step will be
> rejected by GitHub with a permissions error.

For pull requests from forks, GitHub restricts the `security-events` permission;
SARIF upload from fork PRs may not surface alerts. For full coverage on forks,
run the scan on `push` to your default branch (as the example workflow does).
