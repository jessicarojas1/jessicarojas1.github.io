# Open Items — Compliance Copilot

Honest production-readiness register: what is done vs. outstanding, grouped by
theme. Each open item lists **impact** and a **suggested action**. Keep this file
current as the app changes.

Legend: ✅ done · 🟡 partial · ⛔ not started

---

## Data & persistence

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Idempotent schema (`supabase/schema.sql`) | ✅ | Repeatable DB setup | — |
| Live DB reads for controls/evidence/POA&M | ⛔ | UI runs on seed data (`lib/data.ts`); assessment changes are not persisted | Wire pages + server routes to Supabase reads; migrate seed → DB (operator-side; needs live Supabase) |
| Evidence upload to Storage | ✅ | Server-side route `app/api/evidence/upload/route.ts` (service-role) uploads to a **private** bucket with extension+MIME allowlist, 25 MB cap, randomized object name, writes an `evidence` row, and returns a 1 h signed URL | Wiring the client dropzone to call it (currently local-preview) + antivirus scan remain — operator/UI follow-up |
| `app_settings` branding read/write | ✅ | Shared branding works | — |
| All 110 NIST 800-171 controls | ✅ | Full NIST SP 800-171 Rev 2 catalog (110 practices, 14 families) seeded in `lib/data.ts` with official requirement text; verified 110 unique control ids | Migrate seed → DB when live persistence lands |

## Authentication & authorization

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Session login gate (HMAC cookie + middleware) | ✅ | App gated when configured | — |
| Constant-time credential + token checks | ✅ | Timing-attack resistant | — |
| RLS enabled, read-only for `authenticated` | ✅ | Stolen anon token can't write | — |
| Multi-user / RBAC (ISSO, assessor, read-only) | ⛔ | Single shared credential only | Adopt Supabase Auth + role claims; per-role UI/route checks |
| Multi-tenant / org isolation | ⛔ | One org per deployment | Add org scoping + RLS by `org_id` |

## AI relay

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Fail-closed relay in production | ✅ | No open AI proxy | — |
| Per-identity rate limit + prompt/output caps | 🟡 | In-memory, per-process only | Back with Redis; add WAF/gateway limits (operator-side infra) |
| Self-hosted AI (Ollama) path | ✅ | Relay now supports `AI_PROVIDER=ollama` (posts to `${OLLAMA_BASE_URL}/api/chat`, `OLLAMA_MODEL`, output cap → `num_predict`); same fail-closed auth + caps apply. Air-gap ready | — |
| Model pinned to `claude-opus-4-6` | ✅ | Model id moved to `AI_MODEL` env (default `claude-opus-4-6` kept); documented in `.env.local.example`, README, docs | — |

## Observability

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Dedicated `/api/health` endpoint | ✅ | `app/api/health/route.ts` returns `{status, version, uptime_s, supabase, req_id}`, pings Supabase (degrades gracefully); Dockerfile HEALTHCHECK + render.yaml `healthCheckPath` now point to it | — |
| Structured logging | ✅ | `lib/logger.ts` emits JSON log lines with per-request `req_id`; used in ai/generate, auth/login, evidence/upload, settings/branding, health routes; `LOG_LEVEL` env | — |
| Metrics / tracing | ⛔ | No app metrics | Add platform metrics / OpenTelemetry (operator-side) |
| In-app audit trail (per control/POA&M) | ⛔ | No change history for a system of record | Append-only audit table + WORM/SIEM shipping |

## Deployment & operations

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Multi-stage Dockerfile (standalone, non-root, healthcheck) | ✅ | Container-ready | — |
| Render blueprint (`render.yaml`) | ✅ | One-click Render deploy | — |
| `next.config.js` standalone output | ✅ | Small image | — |
| Docs set (ARCHITECTURE/DEPLOYMENT/DR/SECURITY) | ✅ | Operator guidance | Keep current with code |
| `deployments/` target guides (×6) | ✅ | All six present; updated this pass for `/api/health`, AI provider/model + Ollama + `LOG_LEVEL` env, and the private evidence bucket + signed-URL upload flow | Keep current as the app changes |
| Backups / PITR / restore drills | ⛔ | Not yet operationalized | Enable Supabase PITR; schedule quarterly restore drills |
| CI (lint/build/test) + build badges | ✅ | Monorepo `.github/workflows/ci.yml` *Compliance Copilot* job now runs `npm ci` → `tsc --noEmit` → `next lint` → `next build` → `npm audit --omit=dev --audit-level=high`; README build badge points to the CI workflow | — |

## Security hardening (not yet applied)

| Item | State | Impact | Suggested action |
|---|---|---|---|
| WAF / gateway rate limiting | ⛔ | Only best-effort in-memory limits | Front with WAF; shared-store limiter |
| Evidence bucket private + signed URLs enforced | ✅ | Upload route stores to a **private** bucket and returns signed URLs (never public objects); `.env.local.example`, README, DEPLOYMENT/SECURITY/ARCHITECTURE + deployment guides now state "create bucket PRIVATE" | Bucket privacy is still enforced by the operator at Storage-config time (documented) |
| FIPS-mode runtime | ⛔ | Uses FIPS-approved algos but not a validated module | Run on FIPS-validated OS/OpenSSL; FIPS endpoints on gov clouds (operator-side) |
| Dependency scanning | ✅ | `npm audit --omit=dev --audit-level=high` gate added to the CI job; Dependabot already tracks `/compliance-copilot` (npm) + root github-actions in `.github/dependabot.yml` | — |

## Product roadmap (from README v2)

Assessment workflow · CMMC L3 / NIST 800-172 · evidence-expiry notifications ·
PDF report generation (readiness letter, SSP summary) · calendar view ·
Jira/ServiceNow POA&M integration — all ⛔ not started.
