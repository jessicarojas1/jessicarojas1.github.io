# Sentinel QMS — Production-Readiness Open Items

An honest checklist of what is done versus outstanding for a hardened, CUI-bearing
production deployment. Grouped by theme; each item lists **impact** and a
**suggested action**. This mirrors and extends
[`docs/KNOWN_LIMITATIONS.md`](docs/KNOWN_LIMITATIONS.md) (which names the exact
implementing files).

Legend: ✅ done · 🟡 partial / config-dependent · ⬜ outstanding

---

## 1. Identity & access

- ✅ Local auth (bcrypt), short-lived access JWTs, rotating revocable refresh tokens.
- ✅ OIDC + SAML 2.0 + CAC/PIV federation implemented, fail-closed when unconfigured.
- ✅ Optional TOTP MFA; revocable Personal Access Tokens.
- 🟡 **Access tokens are stateless** (not individually revocable until expiry).
  - *Impact:* an issued access token stays valid up to `ACCESS_TOKEN_EXPIRE_MINUTES`
    (30) after a revocation event.
  - *Action:* keep the lifetime short; use PATs for revocable programmatic access;
    rotate `JWT_SECRET` in an emergency. (KNOWN_LIMITATIONS #7.)
- 🟡 **CAC/PIV trust is delegated to the proxy** — the app does not validate the
  cert chain itself.
  - *Action:* configure `ssl_client_certificate` / `ssl_verify_client` at the proxy;
    set `CLIENT_CERT_PROXY_AUTH=true` + `TRUST_PROXY_HEADERS=true`. (KNOWN #1.)

## 2. Rate limiting & abuse

- 🟡 **In-process fixed-window limiter**; per-replica unless a shared store is set.
  - *Impact:* effective limit is N× the config across N replicas without Redis;
    a fixed window can admit ~2× the budget across a boundary.
  - *Action:* set `REDIS_URL` for a shared limiter **and** enforce hard caps at the
    WAF/gateway. (KNOWN #2.)
- ✅ Login throttling is audit-log based and works across replicas.

## 3. Background processing & delivery

- ✅ In-process scheduler (SLA sweep + report digest); jobs claim work atomically,
  safe across replicas.
- 🟡 **Notification delivery is best-effort — no retry queue** (email/Teams/Slack).
  - *Impact:* transient delivery failures are logged only; in-app notifications
    always persist.
  - *Action:* front SMTP/webhooks with a durable queue or managed notification
    service for guaranteed delivery. (KNOWN #4.)
- ⬜ **No external job queue.** If all workers are down, scheduled jobs wait.
  - *Action:* optionally dedicate a `RUN_SCHEDULER=true` worker replica; consider a
    managed scheduler for strict SLAs. (KNOWN #6.)

## 4. Storage & data durability

- 🟡 **`local` storage backend is ephemeral** — not durable on container platforms.
  - *Action:* use `STORAGE_BACKEND=s3` (SSE-KMS) or `azure_blob` (CMK) in production;
    `local` is dev/demo only. (KNOWN #5.)
- ✅ Postgres-first models (JSONB, native enums); SQLite only for tests.
  - *Action:* always run production on **PostgreSQL 16+** via Alembic. (KNOWN #8.)

## 5. Observability

- ✅ Structured JSON logs; `/health` with DB probe; `X-Request-ID` correlation;
  queryable immutable audit log.
- ✅ **Built-in Prometheus `/metrics` endpoint.** Dependency-free text exposition
  (`app/core/metrics.py`), gated by `METRICS_ENABLED` (off by default), optionally
  protected by `METRICS_TOKEN`, and served outside `/api` (exempt from the rate
  limiter). Series: `sentinel_build_info`, `sentinel_http_requests_total{method,status}`,
  `sentinel_http_requests_in_progress`, and a `sentinel_http_request_duration_seconds`
  histogram. *Verified:* new `tests/test_metrics.py` (5 tests — gating, exposition
  format/content-type, counter increment, scrape not self-counted, token auth) pass;
  full backend suite green (see note below).
  - *Remaining (deferred):* OpenTelemetry **traces** are still out of the box — add an
    OTel exporter if distributed tracing SLOs require it.

## 6. AI features (optional)

- ⬜ **Self-hosted LLM (Ollama) wiring is documented but off by default.**
  - *Action:* when enabling AI-assisted features, run Ollama in-boundary and keep
    `AI_FEATURES_ENABLED=false` until validated; block egress to public model APIs.
    (See [`docs/DEPLOYMENT.md` §6–7](docs/DEPLOYMENT.md#6-ollama-optional-self-hosted-llm).)

## 7. Hardening not-yet-applied (deployment-dependent)

- ✅ Non-root container image with `HEALTHCHECK`; production JWT-secret boot guard;
  security headers + HSTS.
- 🟡 **FIPS endpoints / read-only root FS / WAF** are deployment responsibilities.
  - *Action:* follow the production checklist in
    [`docs/DEPLOYMENT.md` §9](docs/DEPLOYMENT.md#9-production-checklist) and the
    security guide [`docs/SECURITY.md`](docs/SECURITY.md).

## 8. Disaster recovery

- ⬜ **Restore drills are a standing operational task, not a one-time setup.**
  - *Action:* run the quarterly restore drill and validate RPO/RTO per
    [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md).

---

## 9. Recent hardening (dependency robustness)

- ✅ **Password hashing no longer depends on the unmaintained passlib wrapper.**
  `app/core/security.py` now calls `bcrypt` directly (72-byte truncation matching
  passlib's historical behavior; `$2b$` hashes remain format-compatible, so
  verification of existing hashes is unchanged). This removes a hard break with
  modern `bcrypt` (≥ 4.1 / 5.x), whose version probe passlib 1.7.4 could not read.
  The `bcrypt` pin in `pyproject.toml` was corrected accordingly.
- ✅ **Portable `TypedDict` import.** `app/core/pages.py` and `app/core/iam.py` now
  import `TypedDict` from `typing_extensions`, which pydantic requires on Python
  < 3.12 (the runtime falls back gracefully across 3.11/3.12).
- *Verification:* on the CI Python 3.11 image the clean tree could not even collect
  the suite (pydantic `TypedDict` error). After these two robustness fixes plus the
  `/metrics` feature, the **full backend suite is green: `325 passed, 1 skipped`**
  (0 failed, 0 errors) via `python -m pytest`.

---

### Reporting
Found a gap not listed here or in `docs/KNOWN_LIMITATIONS.md`? Open an issue with
the affected file path and expected behavior. Keep this file and the doc set
(`deployments/`, `docs/`, `README.md`) current as the app changes.
