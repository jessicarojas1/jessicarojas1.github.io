# Known Limitations

An honest inventory of intentional stubs, single-node constraints, and
deployment considerations. Each entry names the file that implements it and the
recommended production approach. Nothing here is hidden behind a "compliant"
label it does not earn — where a capability is a stub, it fails closed rather
than implying false assurance.

---

## 1. Federated SSO: OIDC implemented; SAML / CAC-PIV not yet
- **Where:** `backend/app/services/oidc.py`, `backend/app/api/routers/auth.py`
  (`POST /auth/oidc/exchange`).
- **State:** **OIDC is fully implemented** — ID tokens are verified against the
  issuer's JWKS (RS256, audience/issuer/expiry enforced), with email-domain
  allowlisting, IdP group→role mapping, and just-in-time provisioning. It is
  active only when `OIDC_ISSUER` is configured; otherwise it fails closed.
- **Not yet:** **SAML 2.0** and **CAC/PIV (mutual-TLS) direct** flows. SAML needs
  an XML-signature library (e.g. `python3-saml`/`lxml`); CAC/PIV is typically
  fronted by a reverse proxy doing client-cert auth. Both can layer on top of the
  same provisioning/role-mapping path in `oidc.resolve_or_provision_user`.
- **Production:** Point `OIDC_ISSUER`/`OIDC_CLIENT_ID` at your IdP; use the group
  map to assign roles. Add SAML/CAC-PIV when those IdPs are required.

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

## 7. Access tokens are stateless (short-lived; not individually revocable)
- **Where:** `backend/app/api/routers/auth.py`, `backend/app/services/refresh_tokens.py`.
- **State:** **Refresh** tokens are now tracked server-side, rotated on every use,
  and revocable (logout revokes the user's whole set; reuse of a rotated token
  burns the chain). **Access** tokens remain stateless JWTs, so an already-issued
  access token stays valid until it expires (default 30 min) — there is no
  per-access-token denylist.
- **Mitigation:** Keep the access-token lifetime short; revoking refresh tokens
  stops new access tokens from being minted. For immediate programmatic
  revocation use Personal Access Tokens (revocable instantly), and rotate the JWT
  secret in an emergency.

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
