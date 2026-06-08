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
| crypto | 100% | 23% | 83% | **77** |
| hash | 69% | 0% | 100% | **69** |
| xpathi | 87% | 45% | 59% | **42** |
| xss | 61% | 34% | 68% | **27** |
| weakrand | 11% | 0% | 100% | 11 |
| pathtraver | 56% | 47% | 54% | 9 |
| cmdi | 74% | 76% | 49% | −2 |
| sqli | 89% | 89% | 54% | ~0 |
| ldapi | 100% | 100% | 46% | 0 |
| trustbound | 0% | 0% | — | 0 |
| **Overall** | **62.0%** | **38.0%** | **63.6%** | **24.0** (F1 0.628) |

Movement vs. the pre-pack baseline (recall 42.8% / precision 62.7% / FPR 27.2% /
F1 0.508 / Youden 15.6): **recall +19.2 pts, F1 +0.12, Youden +8.4, precision
held flat.** The overall FPR rose ~11 pts — the honest cost of detecting four vuln
classes the engine was previously blind to without full data-flow.

## How to read this honestly

The heuristic engine is **pattern/regex-based with *intra-file* taint gating** —
not full inter-procedural data-flow. OWASP Benchmark is designed to punish that:

- **`securecookie` is now a clean 100/0** — it's a deterministic config flag
  (`setSecure(false)`), so a regex nails it with zero false positives.
- **Taint gating works** where the source and sink share a statement-local flow:
  `xss` (61/34) and `xpathi` (87/45) became genuine discriminators because the
  rules drop sinks whose argument is a literal/sanitized constant.
- **`pathtraver` stays modest (56/47)** — Benchmark's safe variants use a
  dead-branch ternary (`bar = cond ? "constant" : param`) that intra-file taint
  can't constant-fold, so some safe cases still flag. Better than the 0 it was.
- **Still high-FP on `cmdi`/`sqli`/`ldapi`**: the dangerous sink is found but a
  sanitized input can't be distinguished from an unsanitized one across helper
  calls. Expected for a non-inter-procedural engine.
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
2. Reduce remaining injection false positives by recognizing common sanitizers /
   encoders (ESAPI, OWASP Encoder, `PreparedStatement`) on the data-flow path —
   the next lever for `cmdi`/`sqli`/`pathtraver`.
3. Add Java `weakrand` (`new java.util.Random()`) and tighten `ldapi`.
4. For authoritative results on data-flow-dependent categories, rely on the
   backend's Semgrep/CodeQL pass.
