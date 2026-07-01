# APEX — Open Items (Production-Readiness Register)

Honest status of what is done vs. outstanding, grouped by theme. Each open item
lists **impact** and a **suggested action**. Keep this current as the app changes.

Legend: ✅ done · ⚠️ partial · ❌ not started

---

## Authentication & identity

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| bcrypt PIN verification, uniform error | ✅ | — | — |
| HS256 JWT, 8h TTL, fail-closed secret in prod | ✅ | — | — |
| HttpOnly + SameSite=Lax + Secure(prod) cookie | ✅ | — | — |
| Default seed PINs disabled in prod | ✅ | — | Confirm `APEX_ALLOW_DEFAULT_PINS=0` and rotate seed PINs at first login |
| Real CAC/PIV (client-cert / PKI) integration | ❌ | Login is a *simulation*; not a real smart-card auth | Terminate mutual-TLS/PKI at the proxy and map cert → user before ATO |
| JWT revocation / logout server-side | ⚠️ | Logout only clears the cookie; a leaked bearer token stays valid until `exp` | Add a short TTL + refresh, or a token denylist keyed on `jti` |
| MFA / step-up for admin actions | ❌ | Single-factor (PIN) for admin operations | Add WebAuthn/FIDO2 step-up for admin-scoped mutations |
| Login rate limiting / lockout | ❌ | PIN brute-force possible at the API | Add per-identity attempt throttling + lockout/backoff |

---

## Authorization

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Role hierarchy (`viewer<member<admin`) | ✅ | — | — |
| Project membership enforced on reads | ✅ | — | — |
| Clearance-based content gating | ❌ | `clearance` is displayed, not enforced on ticket/comment content | Add row-level clearance checks or keep data within the accreditation boundary |

---

## Auditability & observability

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Ticket mutation audit (`history`) | ✅ | — | — |
| Notifications to assignee/watchers | ✅ | — | — |
| Health endpoint | ✅ | — | Wire to platform liveness/readiness probes |
| Auth-event audit (login/logout/PIN change) | ❌ | Auth events not persisted to `history` | Add an auth-audit sink; centralize to SIEM |
| Metrics / distributed tracing | ❌ | No app metrics or OTLP traces | Front with a sidecar/proxy emitting OTLP; scrape `pg_stat_*` |
| Centralized, tamper-evident log retention | ⚠️ | Logs go to stdout/stderr but shipping/retention is operator-supplied | Ship to CloudWatch/Log Analytics/SIEM with retention + alerts |

---

## Data protection & hardening

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Strict CSP + HSTS + security headers | ✅ | — | — |
| Parameterized SQL, emulate-prepares off | ✅ | — | — |
| Forced HTTPS redirect (trusts XFP) | ✅ | — | Ensure the proxy actually sets `X-Forwarded-Proto` |
| Non-root, unprivileged-port, read-only-rootfs-ready image | ✅ | — | Enforce read-only rootfs + drop-ALL caps in the runtime manifest |
| `sslmode=verify-full` to DB | ⚠️ | DSN honors `sslmode` but default is `prefer` | Set `?sslmode=require`/`verify-full` in prod `DATABASE_URL` |
| FIPS-validated crypto module | ⚠️ | HMAC-SHA-256 is FIPS-capable; bcrypt is not FIPS-approved | Enable FIPS OpenSSL base image; consider PBKDF2 for PINs |
| Secrets rotation automation | ⚠️ | Manual rotation documented | Use managed rotating credentials (Secrets Manager/Key Vault) |

---

## Reliability & operations

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Idempotent boot migration (won't wipe populated DB) | ✅ | — | — |
| Stateless web tier (scales horizontally) | ✅ | — | Run ≥2 replicas behind the LB |
| Incremental migration framework | ❌ | Single `schema.sql`; schema changes to a populated prod DB must be hand-applied | Introduce numbered, forward-only migrations |
| Automated backups + tested restore | ⚠️ | Documented in DISASTER_RECOVERY; not wired by default | Enable managed backups/PITR; schedule quarterly restore drills |
| Multi-AZ / HA Postgres | ❌ (free tier) | Single DB instance is a SPOF | Use managed multi-AZ / replica + failover in prod |

---

## Deployment & docs

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Dockerfile (multi-step, non-root, digest-pinned) | ✅ | — | Bump base digests on CVE patch |
| Render Blueprint (`render.yaml`, `rootDir: apex`) | ✅ | — | — |
| Docker healthcheck directive in image | ❌ | No `HEALTHCHECK` in Dockerfile (platform probes cover it) | Add `HEALTHCHECK` hitting `/api/health` for Docker-native deploys |
| `docs/` set (Architecture, Deployment, DR, Security) | ✅ | — | Keep current with code changes |
| `deployments/` set (×6) | ⚠️ | Owned/maintained separately | Ensure all six target guides stay accurate |
| README stale references (`nexus` naming) | ⚠️ | Some legacy `nexus` names remain in docs prose/layout | Continue renaming residual `nexus` references to `apex` |
