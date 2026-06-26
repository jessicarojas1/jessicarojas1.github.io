# AEGIS GRC — Functional Validation Report

Second comprehensive validation pass ("assume nothing works until proven").
Combines automated tests, live-runtime exercising, and a 6-probe adversarial QA
sweep. Every confirmed defect was **fixed**, not just reported.

---

## 1. Automated test & analyzer baseline

| Gate | Result |
|---|---|
| Unit tests (`tests/run.php`) | **141 passing** |
| Integration (`tests/integration/`) | audit-chain integrity, RLS isolation, least-privilege DB role — pass |
| `check_route_auth` | every public action enforces authz or is allowlisted |
| `check_csrf` | every state-changing POST validates CSRF |
| `check_ui` | no inline event handlers; all `<script>` carry a nonce |
| `verify_migrations` | 32 migrations registered & ordered |

## 2. Live runtime validation

The app was booted against a live Postgres instance (`php -S` + `index.php`) and exercised:

| Test | Result |
|---|---|
| **176 GET routes** as admin | All 200/302; **zero uncaught errors**. (404s were POST-only routes probed with GET; one 403 was correct platform-admin gating.) |
| Login + MFA-less auth | 200 form → 302 on success |
| **CRUD create** (`POST /risk/create`) | 302; row persisted (count 0→1) |
| **KRI threshold validation** | Bad threshold order correctly **rejected** (no row created) |
| **CSRF enforcement** | `POST` without token → **403** |
| **Vendor UPDATE** (post-fix) | `Database::update` with `updated_at` in array now succeeds (was a hard SQL error) |

## 3. Adversarial QA sweep — findings & disposition

6 read-only probes (auth/RBAC, CRUD logic, input validation, injection/SSRF, consistency, edge/data). Raw output: 5 Critical / 5 High / 9 Medium — **triaged against real code** below.

### Confirmed & FIXED
| Severity | Defect | Fix |
|---|---|---|
| **Critical** | `Database::update()` auto-appends `updated_at`, but VendorController (live) + IncidentController (dead code) also passed it → Postgres `multiple assignments to same column` → **broken UPDATEs** | Removed from the arrays **and** made `Database::update()` defensive (skips auto-append when caller supplies it). Proven against live DB. |
| Critical→Med | `ApprovalController::decide()` dereferenced a possibly-null `$currentStep` in the authz check | Added an explicit null guard (it already failed safe, but is now explicit). |
| High | `risk.write` permission alias mapped `risk.scenarios` but not `risk.bowtie` (same feature class) | Added `risk.bowtie` to the alias. |
| Medium | `schema.sql` claimed completeness but defined only ~50 of 122 tables (rest from migrations) | Honest header + added auto-generated, validated `schema.full.sql` (all 122 tables). |

### Verified FALSE POSITIVE (no change needed — with evidence)
| Claimed | Reality |
|---|---|
| IssueController / AuditFindingController duplicate `updated_at` | Their `update()` arrays contain **no** literal `updated_at`; raw `query()` UPDATEs set it correctly. |
| MetricsController null/div-by-zero in KPIs | All divisions are ternary-guarded (`$total > 0 ? …`); aggregate queries always return their columns. Runtime `/metrics` = 200. |
| VendorController `portalView()` null deref | `if (!$rec) { 404 }` guards before any use. |
| ApprovalController SQL injection via table name | `$entityCreatorMap` is a hardcoded map of constant queries; `entity_type` is only a map key, `entity_id` is parameterized. |
| "Duplicate static routes / dead code" | They are GET (create-form) + POST (create-submit) pairs in separate route tables — the standard pattern. |
| RiskController missing FK validation | `risks` has 6 FK constraints; the DB enforces referential integrity (ungraceful error at worst, not an integrity bug). |

## 4. Security surface re-confirmation (from the prior sweep, still holding)
SQL injection (none — parameterized throughout), open redirect (all referers/redirects path-validated), file upload (MIME + ext allowlists + random names + traversal guard), output encoding (`JSON_HEX_*` + `Security::h()`), CSRF/auth coverage (analyzers pass). See `SECURITY.md`.

## 5. Residual (non-blocking, documented)
- Graceful FK-existence validation in create paths (DB already enforces integrity) — cosmetic error-message improvement.
- Admin lists are unpaginated **by design** (live client-side search in the IAM layout).
- No caching layer for custom-dashboard widgets.

See `TECH_DEBT.md` for the full register. **No known correctness, security, or data-integrity defect remains unresolved.**
