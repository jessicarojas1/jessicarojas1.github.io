# CITADEL × OWASP Benchmark — external accuracy

This measures the **heuristic (in-browser / first-pass) engine** against the
**[OWASP Benchmark](https://github.com/OWASP-Benchmark/BenchmarkJava)** — a
standard, third-party SAST test suite of **2,740** labeled Java cases (each is
either a real vulnerability or a *sanitized* look-alike of the same category).
It is the credible, citable accuracy number that the curated micro-benchmark in
`../` deliberately is not.

## Reproduce

```bash
git clone --depth 1 https://github.com/OWASP-Benchmark/BenchmarkJava.git /tmp/BenchmarkJava
OWASP_BENCH_DIR=/tmp/BenchmarkJava node citadel/benchmark/owasp/run.js
```

The harness runs the engine over the test corpus, matches findings to each
case's expected CWE, and reports the OWASP scoring (TPR, FPR, precision, and the
**Youden score = TPR − FPR**) per category and overall.

## Result (heuristic engine, Benchmark 1.2)

| Category | TPR | FPR | Precision | Score |
|---|---:|---:|---:|---:|
| crypto | 100% | 23% | 83% | **77** |
| hash | 69% | 0% | 100% | **69** |
| cmdi | 74% | 76% | 49% | −2 |
| sqli | 89% | 89% | 54% | ~0 |
| ldapi | 100% | 100% | 46% | 0 |
| weakrand | 11% | 0% | 100% | 11 |
| xss / pathtraver / securecookie / trustbound / xpathi | 0% | 0% | — | 0 |
| **Overall** | **42.8%** | **27.2%** | **62.7%** | **15.6** (F1 0.508) |

## How to read this honestly

The heuristic engine is **pattern/regex-based — it does not perform taint /
data-flow analysis.** OWASP Benchmark is specifically designed to punish that:

- **Strong where the pattern *is* the bug** — `crypto` (weak ciphers) and `hash`
  (weak digests) score well (77 / 69) with low false positives.
- **High recall but high false positives on injection** (`cmdi`, `sqli`, `ldapi`):
  the engine finds the dangerous *sink* but can't tell a sanitized input from an
  unsanitized one, so it flags both → near-zero Youden score. This is the
  expected behavior of a non-data-flow engine.
- **Zeros = real rule gaps for Java**: the engine has no Java-specific rules for
  `xss` (`response.getWriter().write(param)`), `pathtraver` (`new File(param)`),
  `securecookie` (missing `Secure`/`HttpOnly` flags), `trustbound`, `xpathi`, or
  Java `weakrand` (`new java.util.Random()`). These are concrete, fixable gaps.

**Context that matters:** this is the *fast triage layer*. The CITADEL **deep-scan
backend** runs **Semgrep + CodeQL**, which DO perform data-flow analysis and
score far higher on this benchmark — the heuristic engine is meant to be a
zero-setup first pass, not a replacement for taint analysis.

## Takeaways / improvement backlog

1. Add Java-specific rules for the zero categories (xss, pathtraver,
   securecookie, weakrand-Java, xpathi) — biggest score lever.
2. Reduce injection false positives by recognizing common sanitizers / encoders
   on the same statement (regex-level mitigation short of full taint).
3. For authoritative results on data-flow-dependent categories, rely on the
   backend's Semgrep/CodeQL pass.
