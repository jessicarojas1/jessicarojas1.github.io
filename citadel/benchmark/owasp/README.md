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

After adding the **taint-gated Java pack** (`js/rules-java.js`) — XSS,
path-traversal, insecure-cookie and XPath rules that only fire when the matched
sink line carries a user-tainted variable (intra-file taint in `scanner.js`):

| Category | TPR | FPR | Precision | Score |
|---|---:|---:|---:|---:|
| securecookie | 100% | 0% | 100% | **100** |
| weakrand | 100% | 0% | 100% | **100** |
| crypto | 100% | 23% | 83% | **77** |
| hash | 69% | 0% | 100% | **69** |
| xpathi | 87% | 45% | 59% | **42** |
| xss | 61% | 30% | 70% | **30** |
| pathtraver | 56% | 47% | 54% | 9 |
| cmdi | 74% | 76% | 49% | −2 |
| sqli | 89% | 89% | 54% | ~0 |
| ldapi | 100% | 100% | 46% | 0 |
| trustbound | 0% | 0% | — | 0 |
| **Overall** | **75.6%** | **37.4%** | **68.4%** | **38.3** (F1 0.718) |

A `weakrand` rule (`new java.util.Random()` / `Math.random()`, which the safe
cases avoid by using `SecureRandom`) took that category from 11→**100** at zero
false positives. Because it's the largest category (493 cases), it lifted both
overall recall **and** precision.

A second pass added **statement-local sanitizer awareness**: when a tainted value
appears only inside a recognized neutralizer (ESAPI/OWASP encoders, `escapeHtml`,
`Pattern.quote`, numeric coercion like `Integer.parseInt`, `htmlspecialchars`,
…), taint no longer propagates to the assigned variable. This lifted **xss FPR
34% → 30%** (precision 68% → 70%) with recall unchanged.

Movement vs. the pre-pack baseline (recall 42.8% / precision 62.7% / FPR 27.2% /
F1 0.508 / Youden 15.6): **recall +32.8 pts, precision +5.7 pts, F1 +0.21,
Youden +22.7.** The overall FPR rose ~10 pts vs. baseline — the honest cost of
detecting five vuln classes (xss, pathtraver, securecookie, xpathi, weakrand) the
engine was previously blind to without full data-flow; precision still went *up*
because the new detectors (securecookie/weakrand at 100/0) are high-precision.

## How to read this honestly

The heuristic engine is **pattern/regex-based with *intra-file* taint gating** —
not full inter-procedural data-flow. OWASP Benchmark is designed to punish that:

- **`securecookie` is now a clean 100/0** — it's a deterministic config flag
  (`setSecure(false)`), so a regex nails it with zero false positives.
- **Taint gating works** where the source and sink share a statement-local flow:
  `xss` (61/30) and `xpathi` (87/45) became genuine discriminators because the
  rules drop sinks whose argument is a literal/sanitized constant.
- **`pathtraver` stays modest (56/47)** — Benchmark's safe variants use a
  dead-branch ternary (`bar = cond ? "constant" : param`) that intra-file taint
  can't constant-fold, so some safe cases still flag. Better than the 0 it was.
- **`cmdi`/`sqli` FPs did *not* yield to sanitizer recognition** — we measured
  it: their safe cases almost never neutralize input statement-locally. They use
  the same control-flow obfuscation (always-true dead branches, constant-map
  `put`/`get` round-trips) the engine can't constant-fold, and the ESAPI calls
  present in those files are *logging boilerplate*, not on the data path to the
  sink. Taint-gating the broad `sql-concat`/`os-command` rules was tried and
  *reverted*: it cost more real cross-line recall than it saved in FPs. Closing
  these honestly needs inter-procedural data-flow — i.e. the backend's CodeQL.
- **`trustbound` left uncovered on purpose** — a rule for it scored 54/56 (≈coin
  flip); shipping a non-discriminating detector just to pad the aggregate would
  be dishonest, so it was dropped.

**Context that matters:** this is the *fast triage layer*. The CITADEL **deep-scan
backend** runs **Semgrep + CodeQL**, which DO perform inter-procedural data-flow
and score far higher on this benchmark — the heuristic engine is a zero-setup
first pass, not a replacement for taint analysis.

## Takeaways / improvement backlog

1. ~~Add Java rules for the zero categories~~ — **done** (xss, pathtraver,
   securecookie, xpathi via the taint-gated pack).
2. ~~Recognize statement-local sanitizers/encoders to cut injection FPs~~ —
   **done** for the encoder-on-the-path case (lifted xss FPR 34→30). Measured and
   found it does *not* help `cmdi`/`sqli` (control-flow obfuscation, not
   sanitizers — see above).
3. The remaining `cmdi`/`sqli`/`pathtraver` FPs need inter-procedural data-flow
   (dead-branch / constant-propagation). Out of scope for the heuristic layer;
   the backend's Semgrep/CodeQL pass is the authoritative answer here.
4. Add Java `weakrand` (`new java.util.Random()`) and tighten `ldapi`.
