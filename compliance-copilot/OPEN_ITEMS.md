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
| Live DB reads for controls/evidence/POA&M | ⛔ | UI runs on seed data (`lib/data.ts`); assessment changes are not persisted | Wire pages + server routes to Supabase reads; migrate seed → DB |
| Evidence upload to Storage | 🟡 | Client dropzone exists; server-side upload + metadata write to `evidence` not fully wired | Add an upload route (service-role), write `evidence` row, store signed-URL refs |
| `app_settings` branding read/write | ✅ | Shared branding works | — |
| All 110 NIST 800-171 controls | ⛔ | Only 20 seeded controls | Load the full control catalog into the DB |

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
| Per-identity rate limit + prompt/output caps | 🟡 | In-memory, per-process only | Back with Redis; add WAF/gateway limits |
| Self-hosted AI (Ollama) path | ⛔ | Hosted Anthropic only; not air-gap ready | Add provider switch (`AI_PROVIDER`, `OLLAMA_*`) in the relay |
| Model pinned to `claude-opus-4-6` | 🟡 | Hardcoded in the route | Move model id to env/config |

## Observability

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Dedicated `/api/health` endpoint | ⛔ | Health probe reuses `/api/auth/login` | Add `/api/health` that also pings Supabase |
| Structured logging | ⛔ | Plain stdout logs | Add a structured logger + request ids |
| Metrics / tracing | ⛔ | No app metrics | Add platform metrics / OpenTelemetry |
| In-app audit trail (per control/POA&M) | ⛔ | No change history for a system of record | Append-only audit table + WORM/SIEM shipping |

## Deployment & operations

| Item | State | Impact | Suggested action |
|---|---|---|---|
| Multi-stage Dockerfile (standalone, non-root, healthcheck) | ✅ | Container-ready | — |
| Render blueprint (`render.yaml`) | ✅ | One-click Render deploy | — |
| `next.config.js` standalone output | ✅ | Small image | — |
| Docs set (ARCHITECTURE/DEPLOYMENT/DR/SECURITY) | ✅ | Operator guidance | Keep current with code |
| `deployments/` target guides (×6) | 🟡 | Owned by a separate change | Ensure all six present + accurate |
| Backups / PITR / restore drills | ⛔ | Not yet operationalized | Enable Supabase PITR; schedule quarterly restore drills |
| CI (lint/build/test) + build badges | ⛔ | No pipeline; README badges are placeholders | Add CI workflow; wire real badges |

## Security hardening (not yet applied)

| Item | State | Impact | Suggested action |
|---|---|---|---|
| WAF / gateway rate limiting | ⛔ | Only best-effort in-memory limits | Front with WAF; shared-store limiter |
| Evidence bucket private + signed URLs enforced | 🟡 | Depends on operator config | Default to private bucket; document signed-URL usage |
| FIPS-mode runtime | ⛔ | Uses FIPS-approved algos but not a validated module | Run on FIPS-validated OS/OpenSSL; FIPS endpoints on gov clouds |
| Dependency scanning | ⛔ | No automated `npm audit` gate | Add scheduled audit + Dependabot |

## Product roadmap (from README v2)

Assessment workflow · CMMC L3 / NIST 800-172 · evidence-expiry notifications ·
PDF report generation (readiness letter, SSP summary) · calendar view ·
Jira/ServiceNow POA&M integration — all ⛔ not started.
