# CITADEL — Production-Readiness Open Items

An honest checklist of what is done versus outstanding for a production /
authorization-grade deployment. Mined from
[`docs/RELEASE-READINESS.md`](docs/RELEASE-READINESS.md),
[`docs/TESTING.md`](docs/TESTING.md), and the code. Grouped by theme; each item
notes **impact** and a **suggested action**. Keep this file current as the app
changes.

Legend: ✅ done · 🟡 partial / caveated · ⬜ outstanding.

---

## Persistence & durability

- 🟡 **Ephemeral store in no-DB mode.** Without `DATABASE_URL` (or a persisted
  `CITADEL_DATA_DIR`), users / sessions / scan history / triage / audit are
  in-memory and reset on restart. **Impact:** data loss on redeploy;
  free-tier/Render is demo-grade only. **Action:** provision Postgres for any
  environment where users, history, or audit must survive; back it up (see
  [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md)).
- ✅ **App-managed schema.** `server/lib/db.js` runs the idempotent canonical
  `SCHEMA` on boot; `database/schema.sql` mirrors it for manual DBA setup.
- ⬜ **Cross-region / partition DR is manual.** No automated standby. **Impact:**
  higher RTO on region loss. **Action:** keep IaC + DB backups replicated per DR
  guide; script the standby stand-up.

## Identity & access

- ✅ JWT (alg-pinned), httpOnly refresh cookie, TOTP MFA + backup codes, OIDC/PKCE
  SSO, RBAC with ownership (no IDOR), rate-limit + lockout — all unit/API tested.
- 🟡 **Secure-by-default is "open".** A fresh instance runs with enforcement off
  (warns on prod-looking deploys). **Impact:** an operator who ignores the
  warning ships an open instance. **Action:** turn `enforce` **on** in prod;
  treat `CITADEL_ALLOW_OPEN=1` as deliberate only.
- ✅ **Password-complexity / breach-list policy enforced.** `server/lib/users.js`
  now validates every password set/change/create via `checkPasswordPolicy()`:
  min-length floor (8, raise with `CITADEL_PW_MIN_LENGTH`), optional
  upper/lower/digit/symbol classes (`CITADEL_PW_REQUIRE_*`), and a common/breached
  password denylist (`CITADEL_PW_BLOCK_COMMON`, on by default). SSO/JIT users are
  exempt (random secret, IdP auth). **Verified:** 3 new unit tests in
  `server/test/lib.test.js` (policy floor/denylist, env char-class rules,
  setPassword/add enforcement); documented in `docs/SECURITY.md` §1 + `docs/ENV.md`.

## Secrets & crypto

- ✅ AES-256-GCM sealing of JWT secret + TOTP seeds (`CITADEL_DATA_KEY`); scrypt /
  FIPS PBKDF2 password hashing; secrets from env/secret manager.
- 🟡 **`CITADEL_DATA_KEY` rotation is disruptive.** A changed key cannot unseal
  existing material. **Impact:** MFA lockout if mishandled. **Action:** document +
  rehearse the re-seal window (see [`docs/SECURITY.md`](docs/SECURITY.md) §8).
- 🟡 **FIPS mode depends on the OpenSSL build.** `CITADEL_FIPS=1` is a no-op +
  warning if the build lacks FIPS. **Action:** use a FIPS-validated base image /
  host and verify at boot for CUI workloads.

## Scanning depth & accuracy

- ✅ Real scanners (Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV, +
  Checkov/OSV-Scanner/Hadolint) merged with the heuristic engine; graceful
  degradation when a tool is absent.
- 🟡 **Browser engine is heuristic.** Pattern/entropy-based; some reviewers
  (architecture, threat model, logging/test inferences) are intentionally
  Low/Medium confidence. **Impact:** false positives/negatives. **Action:** treat
  Low/Medium findings as "review", not verdicts; run the backend for depth.
- 🟡 **Scanner signature DBs age.** ClamAV/Trivy/Grype DBs must be refreshed.
  **Impact:** stale CVE/malware detection. **Action:** schedule
  `freshclam` / `trivy --download-db-only` / `grype db update` (or air-gap
  bundle) per [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) §4.
- 🟡 **CodeQL is opt-in and not in the default image** (multi-GB). **Action:**
  build with `--build-arg CITADEL_WITH_CODEQL=1` + `CITADEL_ENABLE_CODEQL=1` when
  deep dataflow SAST is required.

## Testing & CI

- ✅ 98-case suite (lib/api/cli/smoke), ESLint, `npm audit` (prod deps), SARIF
  validation, a line-coverage floor gate, and an accuracy benchmark gate
  (recall ≥ 0.90, precision ≥ 0.90).
- ✅ **Line-coverage threshold gate added.** New `npm run test:coverage:gate`
  (`server/package.json`) fails below lines≥80 / funcs≥70 / branches≥60 (current
  85.31 / 77.11 / 67.43, so headroom without flakiness). Wired into the reference
  CI workflow `deploy/ci/backend-ci.yml`. **Verified:** `npm run test:coverage:gate`
  exits 0 locally.
- ✅ **OWASP Benchmark wired as a non-blocking scheduled CI job.** The
  `owasp-benchmark` job in `deploy/ci/backend-ci.yml` runs nightly (+ manual),
  `continue-on-error`, clones `OWASP-Benchmark/BenchmarkJava`, runs
  `benchmark/owasp/run.js`, and uploads results as an artifact. **Verified:**
  workflow YAML parses; env var (`OWASP_BENCH_DIR`) matches the harness. *(Actual
  scheduled execution runs once the workflow is copied into `.github/workflows/`
  — operator step, per the reference-workflow convention.)*
- ✅ **Dedicated unit tests for SBOM manifest parsers** — 4 new tests in
  `server/test/lib.test.js` cover `sbom.manifestType`, `sbom.parse` (npm + pypi +
  malformed→[]), `sbom.cyclonedx` (CycloneDX 1.5 + purl), and `spdx.document`
  (SPDX-2.3 + purl/cpe externalRefs). **Verified:** all pass (98/98). *(Note:
  `js/report.js` exporters remain DOM-bound and are still exercised only via the
  corpus/smoke path — a jsdom harness for those is left as a follow-up.)*

## AI / air-gap

- ✅ AI "Explain & fix" is opt-in and egress-gated; `CITADEL_AIRGAP` hard-disables
  all egress for CUI/ITAR review.
- 🟡 **Self-hosted LLM (Ollama) path is via `ANTHROPIC_BASE_URL`** and not yet a
  first-class, tested config. **Action:** validate + document an in-enclave
  gateway per deployment guide; add a smoke check.

## Observability & operations

- ✅ `/api/health`, Prometheus `/metrics` (token-guarded), JSON logs, hash-chained
  audit with SIEM forwarding, optional OpenTelemetry tracing.
- ✅ **Reference dashboards + alerts shipped.** `deploy/observability/` adds an
  importable Grafana dashboard (`grafana-dashboard.json`) and Prometheus alert
  rules (`prometheus-alerts.yml`) built against the real `/metrics` series
  (instance-down/crash-loop, high 5xx ratio, elevated scan-error ratio, high
  memory / OOM risk, active-session spike). **Verified:** dashboard JSON + alert
  YAML parse cleanly; metric/label names checked against `server/lib/metrics.js`
  and `server/server.js`. Per-scan latency alert deferred (no
  `citadel_scan_duration_seconds` histogram yet — noted in the README).
- 🟡 **Tracing deps are opt-in** in the image (`CITADEL_WITH_TRACING=1`).
  **Action:** enable in environments that require distributed tracing.

## Hardening

- ✅ Non-root (uid 10001), read-only-root friendly, cap-drop-ready, `HEALTHCHECK`,
  bounded uploads (zip-slip + bomb caps), SSRF guard, no version disclosure to
  anonymous callers.
- ✅ **CSP / security headers confirmed and gap closed.** `deploy/aws-gov` and
  `deploy/azure-gov` nginx already set a full CSP (incl. `frame-ancestors 'none'`)
  + HSTS + `X-Content-Type-Options`. The `deploy/compose` proxy and the app's own
  header middleware previously relied on `X-Frame-Options` only (a `<meta>` CSP
  cannot express `frame-ancestors`); both now also send a framing-only
  `Content-Security-Policy: frame-ancestors 'none'` header that composes with the
  SPA `<meta>` CSP and the OIDC route's stricter nonce CSP without weakening them.
  **Verified:** `node --check server.js`; no test asserts on these headers (98/98
  still pass); nginx directive added under `deploy/compose/nginx/citadel.conf`.

## Documentation

- ✅ Architecture, deployment, security, DR, RBAC, upload-security, env, testing,
  release-readiness, frameworks, capabilities, CI, and per-target runbooks.
- ⬜ Keep this doc set (`deployments-equivalent deploy/`, `docs/`, `README`,
  `OPEN_ITEMS`) updated as the app changes — standing rule in
  [`CLAUDE.md`](CLAUDE.md).
