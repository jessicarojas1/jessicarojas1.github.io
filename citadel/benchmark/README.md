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

## Results & what the benchmark caught

See `results.json` (regenerated on every run). The first run did its job — it
exposed concrete bugs, which were then fixed and re-measured:

| Metric | Baseline | After fixes |
|---|---|---|
| Recall (CWE-strict) | 89.5% | **100%** |
| Recall (any finding) | 94.7% | **100%** |
| Precision (file-level) | 73.9% | **95.0%** |
| Specificity | 71.4% | **95.2%** |
| F1 | 0.810 | **0.974** |

**Bugs the benchmark surfaced and we fixed (no recall regression):**
- **6 → 1 false positives.** Five real rule over-matches: a parameterized query
  flagged as format-string SQLi (`py-format-sql` matched `%s` placeholders),
  `crypto/rand` flagged as weak randomness (`go-math-rand-token` matched
  `rand.Read`), `htmlspecialchars($_GET…)` flagged as XSS, `subprocess.run(…,
  shell=False)` flagged as shell exec, and vulnerable SQL **inside a comment**
  flagged (the comment-line skip was declared but never implemented). The one
  remaining FP (`path.basename` sanitization) needs data-flow to resolve and is
  already low-confidence.
- **A major engine bug.** The server deep-scan engine loaded only `rules.js`
  (~57 rules) — the `rules-extra` and `rules-mobile` packs (Kubernetes,
  Terraform, Dockerfile, mobile, …) were missing server-side. The benchmark's
  missed privileged-container YAML exposed it; the server engine now loads the
  full **287-rule** set, matching the browser.

These are general correctness fixes, not corpus tuning — the corpus just made
them measurable.
