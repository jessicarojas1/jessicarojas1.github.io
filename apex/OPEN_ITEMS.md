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
| JWT revocation / logout server-side | ✅ | — | Done: each JWT carries a random `jti`; `POST /api/auth/logout` adds it to the `revoked_tokens` denylist and `verifyJWT()` rejects denylisted jtis until `exp` (then purged). See `src/Auth.php`, `public/api/auth.php`. Verified `php -l`. |
| MFA / step-up for admin actions | ❌ | Single-factor (PIN) for admin operations | Add WebAuthn/FIDO2 step-up for admin-scoped mutations |
| Login rate limiting / lockout | ✅ | — | Done: DB-backed throttle on `auth_events`; blocks an identity or client IP (HTTP 429) after `APEX_LOGIN_MAX_ATTEMPTS` (default 5) failures within `APEX_LOGIN_WINDOW_MIN` (default 15) min. Durable/cross-replica. See `Auth::loginBlocked()` wired in `public/api/auth.php`. Verified `php -l`. |

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
| Auth-event audit (login/logout/PIN change) | ✅ | — | Done: append-only `auth_events` audit sink records login_success/login_failed/logout/pin_change/locked_out with identity, resolved `user_id`, IP, user agent. Wired via `Auth::recordAuthEvent()` in `public/api/auth.php` + `users.php`. Centralize to SIEM remains an operator step. Verified `php -l`. |
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
| `sslmode=verify-full` to DB | ✅ | — | Done: `Database` now defaults to `sslmode=require` when `APP_ENV=production` and none is specified (was `prefer`); explicit `?sslmode=` still wins. Operators should set `verify-full` + CA for MITM protection. See `src/Database.php`. |
| FIPS-validated crypto module | ⚠️ | HMAC-SHA-256 is FIPS-capable; bcrypt is not FIPS-approved | Enable FIPS OpenSSL base image; consider PBKDF2 for PINs |
| Secrets rotation automation | ⚠️ | Manual rotation documented | Use managed rotating credentials (Secrets Manager/Key Vault) |

---

## Reliability & operations

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Idempotent boot migration (won't wipe populated DB) | ✅ | — | — |
| Stateless web tier (scales horizontally) | ✅ | — | Run ≥2 replicas behind the LB |
| Incremental migration framework | ⚠️ | Single `schema.sql` for the core tables. `scripts/migrate.php` now also runs a forward-only, idempotent "ensure" step on every boot (`CREATE TABLE/INDEX IF NOT EXISTS` for `auth_events`, `revoked_tokens`) so additive changes reach a populated DB without a drop/reseed. | Generalize the ensure step into numbered, forward-only migration files with a `schema_migrations` version table. |
| Automated backups + tested restore | ⚠️ | Documented in DISASTER_RECOVERY; not wired by default | Enable managed backups/PITR; schedule quarterly restore drills |
| Multi-AZ / HA Postgres | ❌ (free tier) | Single DB instance is a SPOF | Use managed multi-AZ / replica + failover in prod |

---

## Deployment & docs

| Item | Status | Impact | Suggested action |
|------|--------|--------|------------------|
| Dockerfile (multi-step, non-root, digest-pinned) | ✅ | — | Bump base digests on CVE patch |
| Render Blueprint (`render.yaml`, `rootDir: apex`) | ✅ | — | — |
| Docker healthcheck directive in image | ✅ | — | Done: `HEALTHCHECK` added to `Dockerfile` (curl `http://localhost:8080/api/health`, 30s interval, 20s start-period); `curl` added to the apt install. |
| `docs/` set (Architecture, Deployment, DR, Security) | ✅ | — | Keep current with code changes |
| `deployments/` set (×6) | ⚠️ | Owned/maintained separately | Ensure all six target guides stay accurate |
| README stale references (`nexus` naming) | ✅ | — | Done: renamed residual `nexus` references — `schema.sql` header + `NEXUS_ALLOW_DEFAULT_PINS` comment, `README.md` cookie name (`apex_token`), `.env.example` DSN. `grep -ri nexus apex/` now only matches this register row. |
