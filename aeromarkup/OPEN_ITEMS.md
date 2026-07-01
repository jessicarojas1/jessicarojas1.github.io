# AeroMarkup — Open Items & Production-Readiness Register

An honest, current view of what is **done** versus **outstanding** for
production DoD deployment. Grouped by theme; each open item lists its **impact**
and a **suggested action**. Keep this file current as the app changes (see
[`CLAUDE.md`](CLAUDE.md)).

Legend: ✅ done · ⚠️ partial / caveated · ❌ not yet.

---

## 1. Authentication & session

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Local username/password auth (werkzeug hashing) | ✅ | — | — |
| No shipped default credential; first-run `bootstrap` admin | ✅ | — | — |
| Stateless signed session in HttpOnly cookie (`am_session`) | ✅ | — | — |
| Double-submit CSRF (`am_csrf` + `X-CSRF-Token`) on writes | ✅ | — | — |
| Strong-secret gate + secure cookies in production | ✅ | — | — |
| **SSO / Entra ID / CAC-PIV federation** | ❌ | DoD sites typically mandate PKI/CAC or Entra ID; local passwords alone may not meet policy. | Add OIDC/SAML (Entra ID) and CAC-PIV client-cert auth; keep local auth as break-glass. |
| **Session revocation / logout-everywhere** | ⚠️ | Sessions are stateless signed tokens; a stolen token is valid until TTL expiry (12h default). Logout only clears the local cookie. | Add a server-side token/jti denylist or short TTL + refresh; document `AEROMARKUP_SECRET` rotation as the blunt revoke-all lever. |
| **Password reset / rotation / lockout policy** | ❌ | No self-service reset or forced-rotation policy. | Add admin-driven reset + optional password-age policy. |
| **MFA / TOTP** | ❌ | Single-factor local login. | Add TOTP or defer to SSO MFA. |

---

## 2. Authorization & multi-tenancy

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| RBAC capability matrix (viewer/engineer/inspector/approver/admin) | ✅ | — | — |
| Server-bound e-signature / NCR / inspector identity (not client-supplied) | ✅ | — | — |
| `@requires()` coverage on state-changing routes | ✅ | — | — |
| **Row-level / program-level access control** | ❌ | Any authenticated user can list all projects/drawings/NCRs regardless of program. Not suitable for need-to-know compartmentalization. | Add ownership/ACL filtering by program/project; enforce in queries. |
| **Per-classification access enforcement** | ⚠️ | Classification is stored and banner-displayed but not access-enforced. | Gate read/write by user clearance vs. record classification. |

---

## 3. Data protection & storage

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Parameterized SQL throughout (no string concatenation of input) | ✅ | — | — |
| Dedicated `aeromarkup` schema (shared-DB safe) | ✅ | — | — |
| TLS to DB via `sslmode=require` (documented) | ✅ | — | — |
| Encryption at rest via provider KMS/CMK (documented) | ✅ | — | — |
| **Uploads stored as data URLs in Postgres, not object storage** | ⚠️ | Reference images + STL/OBJ models inflate rows/backups and have no size/MIME enforcement server-side. | Optionally move large blobs to S3/Blob with server-side MIME allowlist + size caps + randomized keys; keep data-URL path for air-gap. |
| **Server-side upload validation (MIME/size)** | ❌ | Large or unexpected payloads accepted into DB columns. | Add size limits + MIME/extension allowlist at the API. |
| **Field-level encryption for CUI blobs** | ❌ | Relies on storage-level encryption only. | Consider app-layer encryption for sensitive attachments. |

---

## 4. Rate limiting & abuse

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Login brute-force throttle (sliding window, per IP+user) | ✅ | — | — |
| ProxyFix client-IP resolution via `TRUSTED_PROXY_HOPS` | ✅ | — | — |
| **Throttle is per-process (per gunicorn worker / replica)** | ⚠️ | With multiple workers/replicas the effective limit is multiplied; not durable across restarts. | Enforce rate limiting at the gateway/WAF, or back the limiter with Redis. |
| **General API rate limiting** | ❌ | Only login is throttled; other endpoints are unbounded. | Add gateway/WAF rate limits for all `/api/*`. |

---

## 5. Observability

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Health/readiness endpoint (`/api/health` reports DB status) | ✅ | — | — |
| Structured stdout logs (gunicorn/Flask) to platform log sink | ⚠️ | Logs are plain, not structured JSON; no request IDs. | Emit structured JSON logs with correlation IDs. |
| **Metrics (Prometheus/OTel) & tracing** | ❌ | No `/metrics` endpoint or traces; limited SLO visibility. | Add Prometheus metrics + OpenTelemetry traces. |
| **Alerting** | ❌ | No built-in alert rules. | Wire platform alerts on health, error rate, DB saturation. |

---

## 6. Resilience & operations

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Stateless container; horizontal scaling | ✅ | — | — |
| Idempotent schema; `AUTO_MIGRATE` or manual `psql` | ✅ | — | — |
| Offline-first: field devices keep working during outages, re-sync | ✅ | — | — |
| **No schema version table / migration history** | ⚠️ | Single combined `schema.sql` is the source of truth; harder to audit incremental change. | Acceptable for now; if churn grows, adopt a migration tool (e.g. Alembic) with a version table. |
| **Automated backup + restore drills** | ⚠️ | Runbook exists ([DISASTER_RECOVERY.md](docs/DISASTER_RECOVERY.md)); drills not automated/scheduled. | Schedule quarterly restore drills; automate snapshot verification. |
| **HA defaults** | ⚠️ | Multi-AZ DB + multi-replica documented but not enforced by blueprint (Render free = single instance). | Use paid/managed multi-AZ DB and ≥2 replicas in production. |

---

## 7. AI / air-gap

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| No third-party CDN/runtime/AI egress (air-gap safe) | ✅ | — | — |
| Self-contained WebGL 3D viewer + parsers (no external libs) | ✅ | — | — |
| **Optional self-hosted LLM (Ollama) for future AI-assist** | ❌ (not required today) | No AI features shipped; documented as optional. | If AI-assist is added, use Ollama per [DEPLOYMENT.md](docs/DEPLOYMENT.md); never a hosted API. |

---

## 8. Compliance & documentation

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| CUI banners (persisted, in print output) + classification columns | ✅ | — | — |
| Immutable audit log (transactional, append-only) | ✅ | — | — |
| Standard doc set (`docs/` ×4, `deployments/` ×6, README, this file, CLAUDE.md) | ✅ | — | Keep current with every change. |
| **FIPS 140-2/3 validated crypto end-to-end** | ⚠️ | TLS/at-rest can use FIPS endpoints; Python/OpenSSL FIPS mode is an operator responsibility, not enforced by the app. | Run on a FIPS-validated OpenSSL/host; document module boundary in an SSP. |
| **Formal ATO artifacts (SSP, POA&M, STIG checklist)** | ❌ | Needed for DoD ATO. | Produce SSP/POA&M; run container/OS STIG hardening and record results. |
| **Vulnerability disclosure SLA / security contact** | ⚠️ | Placeholder in [SECURITY.md](docs/SECURITY.md). | Fill in a real security contact + response SLA. |

---

## Priority shortlist (do first for production DoD use)

1. **SSO / CAC-PIV or Entra ID auth** (§1) — likely a policy blocker for local-only passwords.
2. **Row/program-level authorization + classification enforcement** (§2) — need-to-know compartmentalization.
3. **Gateway/WAF rate limiting + durable login throttle** (§4) — the in-process limiter is insufficient at scale.
4. **Server-side upload MIME/size validation** (§3) — untrusted payloads into the DB.
5. **Metrics + structured logs + alerting** (§5) — operational visibility.
6. **Automated backups + scheduled restore drills + HA defaults** (§6).
7. **FIPS mode + ATO artifacts (SSP/POA&M/STIG)** (§8).
