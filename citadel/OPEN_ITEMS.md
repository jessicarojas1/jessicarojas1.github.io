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
- ⬜ **No password-complexity / breach-list policy** documented as enforced.
  **Action:** confirm/strengthen password policy for regulated tenants.

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

- ✅ 86-case suite (lib/api/cli/smoke), ESLint, `npm audit` (prod deps), SARIF
  validation, and an accuracy benchmark gate (recall ≥ 0.90, precision ≥ 0.90).
- ⬜ **No line-coverage threshold gate.** **Impact:** coverage can regress
  silently. **Action:** add a coverage floor to CI.
- ⬜ **OWASP Benchmark runner is manual**, not wired into CI. **Action:** wire it
  as a non-blocking scheduled job.
- 🟡 **`js/report.js` exporters + SBOM manifest parsers** have only indirect
  (corpus/smoke) coverage. **Action:** add dedicated unit tests.

## AI / air-gap

- ✅ AI "Explain & fix" is opt-in and egress-gated; `CITADEL_AIRGAP` hard-disables
  all egress for CUI/ITAR review.
- 🟡 **Self-hosted LLM (Ollama) path is via `ANTHROPIC_BASE_URL`** and not yet a
  first-class, tested config. **Action:** validate + document an in-enclave
  gateway per deployment guide; add a smoke check.

## Observability & operations

- ✅ `/api/health`, Prometheus `/metrics` (token-guarded), JSON logs, hash-chained
  audit with SIEM forwarding, optional OpenTelemetry tracing.
- ⬜ **No bundled dashboards/alerts.** **Action:** ship reference Grafana panels +
  alert rules (session spikes, RSS/OOM, 5xx, scan latency).
- 🟡 **Tracing deps are opt-in** in the image (`CITADEL_WITH_TRACING=1`).
  **Action:** enable in environments that require distributed tracing.

## Hardening

- ✅ Non-root (uid 10001), read-only-root friendly, cap-drop-ready, `HEALTHCHECK`,
  bounded uploads (zip-slip + bomb caps), SSRF guard, no version disclosure to
  anonymous callers.
- ⬜ **CSP / security headers** should be verified at the TLS-terminating proxy
  (the app is API + static). **Action:** confirm CSP, HSTS, `X-Content-Type-
  Options`, frame-ancestors in the nginx/ingress config under [`deploy/`](deploy/).

## Documentation

- ✅ Architecture, deployment, security, DR, RBAC, upload-security, env, testing,
  release-readiness, frameworks, capabilities, CI, and per-target runbooks.
- ⬜ Keep this doc set (`deployments-equivalent deploy/`, `docs/`, `README`,
  `OPEN_ITEMS`) updated as the app changes — standing rule in
  [`CLAUDE.md`](CLAUDE.md).
