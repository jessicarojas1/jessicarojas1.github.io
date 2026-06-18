# Known Limitations

An honest inventory of intentional stubs, single-node constraints, and
deployment considerations. Each entry names the file that implements it and the
recommended production approach. Nothing here is hidden behind a "compliant"
label it does not earn — where a capability is a stub, it fails closed rather
than implying false assurance.

---

## 1. Federated SSO (OIDC / SAML / CAC-PIV) is a stub
- **Where:** `backend/app/core/security.py` (`verify_oidc_token`).
- **State:** The local HS256 JWT login path is fully functional. Federated SSO is
  wired but stubbed: `verify_oidc_token` raises `AuthenticationError` until
  `OIDC_ISSUER` is configured, and even then returns a not-enabled error.
- **Why:** A stub that fails closed avoids implying security that is not present.
- **Production:** Complete the stub against your IdP's JWKS (validate
  issuer/audience/signature, map claims to a local user) before relying on SSO.

## 2. Rate limiting is in-process (single node)
- **Where:** `backend/app/core/middleware.py` (`RateLimitMiddleware`).
- **State:** A per-source-IP fixed-window limiter, thread-safe within one
  process. Counters are **not shared** across horizontally scaled replicas, so
  the effective limit is per-replica. `X-Forwarded-For` is trusted only when
  `TRUST_PROXY_HEADERS=true`.
- **Production:** Treat this as defense-in-depth and also enforce limits at the
  gateway/WAF/load balancer in multi-replica deployments.

## 3. Login throttling is audit-log based
- **Where:** `backend/app/api/routers/auth.py` (`_too_many_failed_logins`).
- **State:** Failed logins are counted from the immutable audit log within a
  rolling window (`LOGIN_MAX_FAILURES`, `LOGIN_FAILURE_WINDOW_MINUTES`), keyed by
  email or IP. Because it reads shared DB state, this throttle **does** work
  across replicas.
- **Note:** It blunts brute force; it is not a substitute for SSO/MFA.

## 4. Notification delivery is best-effort (no retry queue)
- **Where:** `backend/app/services/delivery.py`.
- **State:** Email (SMTP), Microsoft Teams, and Slack are delivered on a
  background daemon thread with a short per-call timeout. Failures are logged,
  never raised, so a misconfigured channel can never break the originating
  request. In-app notifications are always written regardless.
- **Limitation:** No retry/queue — transient delivery failures are logged only.
- **Production:** For guaranteed delivery, front SMTP/webhooks with a durable
  queue or a managed notification service.

## 5. File storage: durability depends on backend
- **Where:** `backend/app/services/storage.py` (`STORAGE_BACKEND`).
- **`local`:** Files on local disk — **ephemeral** on container platforms; not
  durable across restarts/redeploys; no presigned URLs (served via the API).
- **`s3`:** AWS (incl. GovCloud) with SSE-KMS; issues short-lived presigned GETs.
- **`azure_blob`:** Azure (incl. Gov); downloads proxied through the API (no SAS
  URLs issued).
- **Production:** Use `s3` or `azure_blob`; `local` is for development/demos only.

## 6. Background scheduler is in-process
- **Where:** `backend/app/services/scheduler.py`.
- **State:** A daemon thread runs the SLA-escalation sweep and the scheduled
  report digest on an interval (`SCHEDULER_INTERVAL_SECONDS`). Jobs claim their
  work atomically in the DB, so running it in every web worker is safe (no
  duplicate sends). Disabled in tests (`RUN_SCHEDULER=false`).
- **Limitation:** No external job queue; if all workers are down, jobs simply
  wait until one is running again.

## 7. JWT logout is stateless (no server-side revocation list)
- **Where:** `backend/app/api/routers/auth.py`, `backend/app/core/security.py`.
- **State:** Logout is audited and the client discards its tokens; there is no
  server-side denylist, so an already-issued access token remains valid until it
  expires (default 30 min). Tokens already carry a `jti` claim to support a
  future denylist.
- **Mitigation today:** Keep access-token lifetime short; for immediate
  programmatic revocation, use Personal Access Tokens (revocable instantly) and
  rotate the JWT secret in an emergency.

## 8. Database engine
- **Where:** `backend/IMPLEMENTATION_NOTES.md`, models across `backend/app/models`.
- **State:** Models use PostgreSQL-friendly types (native enums, JSONB). The test
  suite runs on SQLite, where these degrade gracefully via SQLAlchemy variants.
- **Production:** Run against **PostgreSQL 16+**; apply schema with Alembic
  (`alembic upgrade head`). SQLite is for local development and tests only.

---

### Reporting
Found something not listed here? Open an issue with the affected file path and
expected behavior. This document is updated as stubs are completed or constraints
change.
