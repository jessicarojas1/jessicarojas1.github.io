# CITADEL accuracy benchmark

Measures the **heuristic analysis engine's** detection accuracy against a labeled
corpus, so changes to the rules can be evaluated with real numbers instead of
vibes. This is the engine that runs in the browser and as the server's first
pass — it does **not** include the optional external scanners (Semgrep, CodeQL,
Trivy, …), which add depth on top.

## Run

```bash
node benchmark/run.js
# CI gate:
FAIL_UNDER_RECALL=0.85 FAIL_UNDER_PRECISION=0.75 node benchmark/run.js
# Point at a larger labeled suite (same labels.json schema):
CORPUS_DIR=/path/to/corpus node benchmark/run.js
```

## Corpus & method

`corpus/` holds small, self-contained files split into `vuln/` (one real
vulnerability each, labeled with its standard CWE) and `safe/` (clean code, much
of it **deliberately resembling** the vulnerable cases — parameterized queries,
escaped output, env-var secrets, `crypto/rand`, identifiers containing `eval`,
vulnerable SQL inside a comment, …). The safe set is what measures false
positives, which is where heuristic SAST usually fails.

Metrics are **file-level** (the honest unit):

- **Recall (CWE-strict)** — a vulnerable file counts as detected only if the
  engine reports a finding in it whose CWE matches the label.
- **Recall (any finding)** — detected if it reports *anything* in the file
  (separates raw detection from CWE-taxonomy differences).
- **Precision / Specificity** — any finding on a *safe* file is a false positive.
- **F1** — harmonic mean of precision and CWE-strict recall.

## Limitations (read this)

This is a **curated micro-benchmark** for fast regression signal, not a
substitute for **OWASP Benchmark** or **NIST Juliet/SARD**. It is small, so each
case moves the percentages a lot, and it only covers the categories CITADEL
claims. For an authoritative number, point `CORPUS_DIR` at one of those suites
(the harness is corpus-agnostic). Treat these figures as a *floor for regressions*,
not a marketing claim.

## Baseline results

See `results.json` (regenerated on every run). The first committed baseline
exposed concrete, fixable issues — which is exactly the point of having this:

- **Recall 89.5% (CWE-strict) / 94.7% (any finding)** — good detection; the one
  hard miss was a privileged-container YAML, and the Dockerfile `curl | sh` case
  was detected but under CWE-494 (more accurate than the label's CWE-78).
- **Precision 73.9%, Specificity 71.4%** — the benchmark caught **6 false
  positives on safe code**, e.g. a parameterized query flagged as format-string
  SQLi, `crypto/rand` flagged as weak randomness, and vulnerable SQL **inside a
  comment** flagged. Each is a real rule-quality bug worth fixing; the benchmark
  is how we'll verify the fixes don't regress detection.
