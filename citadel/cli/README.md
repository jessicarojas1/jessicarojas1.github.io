# CITADEL Release Readiness Gate — CLI / CI action

Run CITADEL's release-readiness gate **headlessly** and **fail a build** when a
project isn't production-ready. The CLI runs the exact same analysis engine the
browser app uses (the engine modules are pure and DOM-free), so the gate
decision is consistent everywhere — it just becomes *enforceable* in CI instead
of advisory.

## CLI

```bash
node citadel/cli/citadel-gate.js [dir] [options]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `dir` | `.` | Directory to scan |
| `--fail-on=<level>` | `manual` | Exit non-zero when the decision is this level **or worse**: `approved` < `conditional` < `manual` < `rejected` |
| `--json` | off | Print the full readiness JSON (for tooling) |
| `--quiet` | off | Print only the decision line |
| `--max-files=N` | `20000` | Cap files ingested |
| `--policy=FILE` | — | JSON merged into `CITADEL.readinessPolicy` before scoring (custom weights/thresholds) |

**Exit codes:** `0` gate passed · `1` gate failed · `2` error (bad args / no files / scan error).

```text
$ node citadel/cli/citadel-gate.js ./my-app --fail-on=rejected

  CITADEL — Release Readiness Gate
  ----------------------------------------------
  Scanned : 2 files in /…/my-app
  Findings: 16  (critical 2, high 4, medium 5)
  Overall readiness     [#######---]  66/100
    Secrets             [##--------]  17/100  FAIL
  Blockers:
   - Exposed/hardcoded secret(s)
   - 2 Critical (high-confidence) finding(s)

  DECISION: Rejected  (66/100)  ✗ GATE FAILED
$ echo $?
1
```

> The CLI runs the **offline** engine (SAST, secrets, SBOM, dependency review,
> container/IaC/ops/logging/test/architecture reviews, compliance posture, and
> the readiness gate). The live OSV / EPSS / KEV / registry enrichments are
> browser-only post-scan steps and are not part of the headless gate.

## GitHub Actions

A composite action ships at `citadel/cli/action.yml`:

```yaml
# .github/workflows/readiness.yml
name: Readiness gate
on: [pull_request]
jobs:
  gate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - name: CITADEL readiness gate
        uses: jessicarojas1/jessicarojas1.github.io/citadel/cli@main
        with:
          path: .
          fail-on: rejected   # block merges only on a hard Rejected
```

Or call the script directly (no external action):

```yaml
      - run: node path/to/citadel/cli/citadel-gate.js . --fail-on=manual
```

## Custom policy

`--policy=policy.json` is merged into `CITADEL.readinessPolicy` (see
`docs/RELEASE-READINESS.md`). Example — make the gate stricter and re-weight:

```json
{ "name": "strict", "multipleHighThreshold": 1, "warnAt": 90, "failAt": 70 }
```

## Notes / limitations

- Skips `node_modules`, `.git`, virtualenvs, and files > 15 MB; symlinks are not
  followed (no traversal out of the tree).
- Exit `2` (not `1`) on operational errors so a misconfigured step is
  distinguishable from a genuine gate failure.
