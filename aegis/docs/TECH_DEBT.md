# AEGIS GRC — Technical Debt, Limitations & Future Enhancements

This document is the engineering team's working register of **technical debt**,
**known limitations**, and **prioritized future enhancements** for the AEGIS GRC
platform. It is grounded in the actual code — every item cites a file (and line
numbers where useful).

It is a **companion to [`MODERNIZATION.md`](./MODERNIZATION.md)**, the durable
record of the 12-agent modernization audit (180 findings, fixes shipped in PR
#345 / #346 / #347). Where MODERNIZATION.md already adjudicated an item — as a
shipped fix (its §3), a verified false-positive / by-design decision (its §4), or
a backlog entry (its §5) — this document **references that decision rather than
re-litigating it**. New material here is the debt and rough edges that the
modernization pass either deferred, left implicit, or did not enumerate as a
register.

> **How to read priority.** P1 = address soon (correctness, scale, or
> operational risk that will bite under growth). P2 = address opportunistically
> (maintainability, consistency, mild risk). P3 = cosmetic / nice-to-have.
> None of the items below are security-critical — that surface was swept clean in
> MODERNIZATION.md §6a (zero SQLi, clean CSRF/auth/upload/redirect coverage).

---

## 1. Codebase debt-marker scan

A direct scan for the conventional debt markers (`TODO`, `FIXME`, `HACK`, `XXX`,
`@deprecated`, `BUG:`) across `controllers/`, `src/`, `views/`, and `scripts/`
returns **no code-level debt comments**. The only `XXXX-XXXX` hits are the MFA
backup-code *format* string (`controllers/AuthController.php:442,487`,
`views/auth/mfa_verify.php:81`), not debt markers.

This is a genuinely positive signal: the team does not leave loose `TODO`s in the
tree. The consequence is that the debt in this codebase is **architectural and
implicit**, not annotated — which is exactly why this register exists. The items
below were found by reading the code, not by grepping for markers.

The `legacy` / `fallback` mentions that *do* appear in `src/` are deliberate
backward-compatibility code paths (key derivation fallbacks, API-key hash
upgrades), documented in §2 and §3 below — they are managed compatibility, not
unmanaged debt.

---

## 2. Technical Debt Register

| # | Item | Location | Impact | Suggested remediation | Priority |
|---|------|----------|--------|----------------------|----------|
| TD-1 | **Runtime schema migrations run on every HTTP request.** A ~540-line `try { … }` block executes DDL-guard queries against `information_schema` and conditional `ALTER TABLE` / `CREATE TABLE` on *every* request before routing. | `index.php` lines **164–~700** (begins at the "Runtime schema migrations" comment, ends just before the health block ~694). 20+ `information_schema` probes (lines 168, 186, 210, 221, 249, 268, 289, 308, 319, 338, 349, 360, 371, 396, 414, 441, 452, 467, 523, 541, …). | Per-request latency (dozens of catalog lookups before any useful work); the front controller is bloated and hard to reason about; schema state is defined in *two* places (here **and** `database/schema.sql` / `database/migrations/`), so they can drift. | Move these guards into the authoritative installer (`install.php`) and the numbered migrations under `database/migrations/`. Gate the runtime block behind a one-time "schema version" check (a `schema_migrations` table) so it becomes a true no-op after first boot, or remove it entirely once `install.php` is the single source of truth. | **P1** |
| TD-2 | **Per-request `active_sessions` UPSERT** on every authenticated request. | `index.php` lines **1190–1202** (`INSERT … ON CONFLICT (id) DO UPDATE SET last_seen_at=NOW()`). | A write to Postgres on every authenticated page load. Combines with TD-1 to make the steady-state request do meaningful DB work before dispatch. Under load this is write amplification on a hot table. | Throttle the write (only update `last_seen_at` if older than N seconds, e.g. compare against a session-stored timestamp), or move session-activity tracking to the session store itself (`PgSessionHandler`). | **P2** |
| TD-3 | **Linear dynamic-route matching.** Dynamic routes are matched by iterating every pattern and running `preg_match` until one hits. | `index.php` lines **1211–1218** (`foreach ($dynamicRoutes[$method] ?? [] as $pattern => …) { if (preg_match(...)) … }`). | O(n) regex evaluations per unmatched/late-matched request across a 407-route app. Currently fine at this scale; degrades as routes grow and inflates 404 cost (every miss runs the full loop). | Acceptable for now. If route count keeps growing, bucket dynamic routes by first path segment, or compile a single combined regex with named groups. Document as a deliberate simplicity tradeoff until then. | **P3** |
| TD-4 | **Schema defined in three places** that must be hand-kept in sync. | `database/schema.sql`, `database/migrations/` (32 files, latest `032_remove_modules.sql`), **and** the runtime block in `index.php` (TD-1). | CLAUDE.md rule #3 mandates `schema.sql` always reflect the combined migrations; with a third runtime source the invariant is harder to hold and drift is silent until a fresh deploy diverges from a long-running one. | Collapse to two sources (migrations as the record of change; `schema.sql` as the generated/maintained snapshot). Eliminating TD-1 also resolves the third source here. | **P1** (tracks with TD-1) |
| TD-5 | **No server-side pagination anywhere; list views `fetchAll` unbounded.** ~326 `fetchAll` calls across `controllers/`; only `AdminController` references `OFFSET`/`LIMIT` at all, and even `AdminController::users()` loads every row. | e.g. `AdminController::users()` (`controllers/AdminController.php:28` — `SELECT * FROM users ORDER BY created_at DESC`, no limit); pattern repeats across list controllers. | Memory and render time grow linearly with row count on every list page. The admin user list is deliberately unpaginated (see §3, KL-1), but most *other* list pages share the unbounded pattern without that design justification. | Introduce a shared keyset/offset pagination helper and apply it to high-cardinality list views (risks, controls, incidents, evidence, activity log). Keep the IAM user list client-side by design (KL-1) but cap or virtualize at scale. | **P2** |
| TD-6 | **Query/result caching — PARTIALLY ADDRESSED.** A `src/Cache.php` TTL cache now exists (APCu-backed, tenant-namespaced, graceful pass-through when APCu is absent) and is applied to the heaviest org-wide read-only aggregates: the main dashboard stat counts / risk-distribution / compliance-by-package, and every custom-dashboard widget. Short TTL (`Cache::DEFAULT_TTL = 30s`) is used in place of explicit write-invalidation, so staleness is bounded with no busting logic. Per-user dashboard queries (pending approvals/questionnaires, alerts) are deliberately left uncached to avoid cross-user leakage under a tenant-only key. | `src/Cache.php`; `controllers/DashboardController.php`, `controllers/CustomDashboardController.php`. Remaining uncached: report aggregations, compliance %, and other list/aggregate controllers. | Most-visited aggregate pages now reuse results within the TTL window; the rest still recompute every load. | (1) Extend `Cache::remember()` to the report and compliance-aggregate paths. (2) For multi-node scale, add a shared backend (Redis/Postgres) behind the same `Cache` facade — APCu is single-node only. (3) Where freshness matters more than the 30s window, add targeted `Cache::forget()` on the relevant writes. | **P3** (was P2 — core paths cached) |
| TD-7 | **Database-backed rate limiting.** Counters live in the `rate_limits` table, queried/updated on rate-limited paths. | `src/Security.php` `checkRateLimit()` (from line ~291, `SELECT … FROM rate_limits …`). | Under sustained high request volume this is DB contention on a hot row per identifier — acknowledged in `README.md:909`. | Move counters to an in-memory store (APCu single-node, Redis multi-node) when scaling beyond a single small instance. Keep the DB implementation as the zero-dependency default. | **P2** |
| TD-8 | **Documentation drift: README says SSO is "not yet live," but the OIDC flow is fully implemented and wired.** | `README.md:894` ("SSO (SAML2/OIDC) not yet live … placeholder controller … not implemented") vs. `src/SSO.php` (full OIDC client: `config()`, `discovery()`, `authorizationUrl()`, `handleCallback()`, `provisionUser()`) and `controllers/SSOController.php` (live `login()`/`callback()` with state validation, MFA hand-off, open-redirect-guarded post-login redirect, settings CRUD). | A new engineer reading the README will wrongly believe SSO is a stub and may rebuild or distrust working code. Note also: the implementation is **OIDC/OAuth2**, not SAML2 — the README conflates the two. | Update `README.md:894` to reflect that OIDC SSO is implemented and operational (provisioning, role mapping, MFA hand-off), and clarify that **SAML2 specifically** is not implemented (only OIDC is). | **P1** (cheap, high-confusion) |
| TD-9 | **Email sends are fire-and-forget; failures are logged and dropped (no queue/retry).** | `src/Mailer.php:140–141` (`catch (Exception $e) { error_log("[Mailer] Send failed: …"); }`); earlier failure paths at lines 64, 77, 86, 98 also `error_log` and return. Confirmed by `README.md:893`. | Review reminders, attestation campaigns, and notifications can be silently lost on a transient SMTP failure. Asymmetric with webhooks, which *do* retry (see KL-4). | Introduce an outbound-email queue table with the same exponential-backoff retry pattern already proven in `scripts/dispatch_webhooks.php`, drained by a cron script. | **P2** |
| TD-10 | **Background work depends on external cron that is not provisioned in the repo's deploy manifest.** Cron scripts exist (`scripts/send_notifications.php`, `dispatch_webhooks.php`, `send_scheduled_reports.php`, `run_workflows.php`, `capture_metrics_snapshot.php`) and self-document a crontab line, but `render.yaml` defines only a `web` service and the `aegis-db` Postgres — **no cron/worker service**. | `render.yaml` (only `type: web` + `aegis-db`); `scripts/send_notifications.php:8` documents a manual crontab entry; `README.md:892` ("No background job runner … missed triggers are not retried"). | On the documented Render deployment, scheduled jobs (reminders, SLA alerts, metric snapshots, webhook dispatch, scheduled reports) **do not run unless someone wires external cron**. Easy to ship a deploy where time-based features silently never fire. | Add a Render `cron` service (or scheduled job) per script in `render.yaml`, or document the required external scheduler explicitly in the deploy runbook. Without this, TD-9's queue and KL-4's webhook retry never drain. | **P1** |
| TD-11 | **Category/status colors hardcoded as literal hex in views, not tokenized.** Threat (and treatment) views define `color`/`bg` arrays with literal hex and helper functions returning hex. | `views/threat/index.php:11–12,19,31–37,146–147` (e.g. `'#ea580c'`, `'#fff7ed'`, `if ($score <= 16) return '#ea580c';`). 84 view files contain at least one `#RRGGBB` literal. | Brand-accent / dark-mode theming can't reach these category colors via CSS custom properties; they're maintained by hand and can drift from the design tokens. MODERNIZATION.md §5 judged them "vivid mid-tone hues that remain legible in dark mode … cosmetic only." | Map each category/status to a `--cat-*` CSS custom property (one mapping decision, then mechanical replacement). Cosmetic, so low priority — but it closes the last gap in the dark-mode/token rule from CLAUDE.md §2. | **P3** |
| TD-12 | **Backward-compat key-derivation and hash fallbacks** (managed debt, not unmanaged). Settings-encryption falls back to a `JWT_SECRET`-derived legacy key for decrypt; API keys silently upgrade from plain SHA-256 to HMAC on first use. | `src/Security.php`: `decryptSetting()` legacy-key fallback (lines 222–228, 245–248); `validateApiKey()` legacy-hash path + in-place upgrade (lines 272–286). | These paths are correct and intentional, but they're permanent compatibility surface that must be carried until all legacy ciphertexts/keys are confirmed rotated. They add branches to security-sensitive code. | Once a deployment confirms all settings are re-encrypted under `APP_ENCRYPTION_KEY` and all API keys have been used at least once (upgraded to HMAC), schedule removal of the legacy branches. Track with a migration checklist; do not remove blindly. | **P3** |
| TD-13 | **One remaining CDN asset: the Bootstrap stylesheet from jsdelivr.** The unused Bootstrap *JS* bundle was removed and `script-src` locked to `'self' 'nonce-…'` (no external origin); the Bootstrap *CSS* is the last externally-loaded asset. It is SRI-pinned, and `style-src` is the only directive that still allows `cdn.jsdelivr.net`. | `views/layout.php:8` (`<link … bootstrap@5.3.3/dist/css/bootstrap.min.css … integrity=…>`); `src/Security.php:348-352` (`$cdn` used only in `style-src`). | For air-gapped / IL5+ deployments the frontend cannot reach jsdelivr, so styles silently fail to load. The app's own `app.css` defines the grid primitives it actually uses (`.row`, `.col-*`, `.d-flex`, spacing utilities), so Bootstrap CSS provides only a partial fallback — removal needs verification, not a blind delete. | Vendor `bootstrap.min.css` under `public/vendor/bootstrap/` (matching the already-vendored Chart.js and bootstrap-icons), point `layout.php:8` at the local copy with its SRI hash, and drop `cdn.jsdelivr.net` from `style-src` — fully removing the jsdelivr origin from the CSP. Audit which Bootstrap classes are still relied upon first so the local copy can eventually be trimmed to a minimal subset. | **P2** |

---

## 3. Known Limitations

These are **intentional design boundaries**, not bugs. They are documented so the
new team treats them as decisions, not omissions. Several are also recorded in
`README.md` "Known Limitations" and adjudicated in MODERNIZATION.md §4/§5; this
section consolidates and cross-references them.

### KL-1 — Admin user list has no server-side pagination *by design*
`AdminController::users()` (`controllers/AdminController.php:28`) loads all users
in one query. This is **deliberate**: the mandated two-pane IAM layout
(CLAUDE.md "User & Permission Management UI Standard") uses a scrollable list with
live **client-side** search, which needs the full set in the DOM. Server-side
pagination would regress that UX. (MODERNIZATION.md §5, "Deferred.") *Revisit only
at multi-thousand-user scale.* The broader unbounded-list pattern on **other**
pages is separate and is TD-5 above.

### KL-2 — Single-tenant deployment; RLS is the isolation mechanism, not WHERE clauses
AEGIS runs **single-tenant per deployment** (`README.md:895`). Tenant isolation is
enforced at the database layer by **Postgres Row-Level Security** (migration
`028_tenancy_rls.sql`) bound per-request via `setTenant()` GUC (`index.php:1170–
1187`), **not** by explicit `WHERE tenant_id` in queries — MODERNIZATION.md §4
verified that adding such clauses is redundant. In single-tenant mode the RLS
policy is permissive while the GUC matches the default tenant (`tenant_id`
DEFAULT 1), and the binding is guarded so a pre-migration DB cannot take down a
request. Multi-organization mode requires separate deployments.

### KL-3 — Audit-chain HMAC key has a single derived fallback *by design*
`Security::auditKey()` (`src/Security.php:256–262`) uses a dedicated
`AUDIT_HMAC_KEY` when set, otherwise derives from `JWT_SECRET` via a
**domain-separated** prefix (`aegis_audit_v1:`). The fallback is intentional and
documented: even unconfigured, the audit chain is **keyed (HMAC), not a forgeable
unkeyed hash**, and the derivation namespace separates it from JWT signing.
(MODERNIZATION.md §4 confirmed real key separation.) **Limitation:** without a
dedicated key in a secret store the DB role cannot read, true integrity
separation from `JWT_SECRET` is not achieved — set `AUDIT_HMAC_KEY` for the
strongest posture. The same single-derived-fallback shape exists for
settings-encryption (`_settingsKey()` → `APP_ENCRYPTION_KEY` else
`JWT_SECRET`-derived).

### KL-4 — Webhooks retry; email does not
Webhook delivery **does** implement durable retry — exponential back-off (2^attempts
minutes), giving up after 5 attempts (`scripts/dispatch_webhooks.php:6,84,112–119`).
Email (KL-3 of README / TD-9 here) does **not**. So the blanket README statement
that "missed triggers are not retried" (`README.md:892`) is true for email and
metric snapshots but **not** for webhooks. Both, however, depend on the external
cron of TD-10 actually being provisioned.

### KL-5 — Ephemeral local file storage; no horizontal scaling out of the box
Uploaded evidence is stored on the container's local filesystem; a redeploy wipes
it unless a persistent disk is mounted and `UPLOAD_PATH` points at it
(`README.md:882`). PHP file-based sessions + local file storage mean the app
**cannot run behind a load balancer with multiple replicas** without
session-sharing/shared-storage infrastructure (`README.md:883`). Note: a
`PgSessionHandler` exists (migration `030_php_sessions.sql`), which is the lever
to make sessions shareable — see FE-3.

### KL-6 — PostgreSQL only
The data layer uses Postgres-specific SQL (schemas, `SERIAL`, `ON CONFLICT`,
RLS). MySQL/MariaDB/SQLite are unsupported (`README.md:885`). This is foundational
and not intended to change.

### KL-7 — SSO is OIDC/OAuth2 only (not SAML2); AI Advisor and SMTP are config-gated
Despite the "SAML2/OIDC" label in the README, the implemented flow is **OIDC**
(`src/SSO.php`, `controllers/SSOController.php` — see TD-8). **SAML2 is not
implemented.** Separately, the AI Advisor requires an Anthropic API key
(`README.md:896`) and all email requires working SMTP config (`README.md:893`) —
without each, the respective feature is inactive.

### KL-8 — Export format and PDF-import constraints
XLSX export uses the **Excel 2003 SpreadsheetML (`.xls`)** format to avoid a
ZipArchive dependency — no modern OOXML tables/charts (`README.md:902`). PDF
*import* depends on the `pdftotext` binary and the PDF's embedded text layer;
scanned PDFs without OCR extract poorly and fall back to a single placeholder
control (`README.md:903,981`).

### KL-9 — No pre-loaded compliance frameworks
The platform ships with **no** standards; all compliance content is imported
(JSON/CSV/XLSX) or entered manually (`README.md:891`).

### KL-10 — CSP keeps `style-src 'unsafe-inline'`
Intentional — the app relies on inline styles throughout; removing it is a large
out-of-scope refactor (MODERNIZATION.md §4). All other CSP/XSS/CSRF rules from
CLAUDE.md §1 are enforced and verified clean (MODERNIZATION.md §6a).

---

## 4. Future Enhancement Recommendations (prioritized)

Ordered by recommended sequencing. Each is phrased as an outcome, with the debt/
limitation items it resolves.

### Priority 1 — operational correctness & scale foundations

- **FE-1 · Provision background scheduling in the deploy manifest.** Add cron/
  worker services to `render.yaml` for each `scripts/*` job (notifications,
  webhook dispatch, scheduled reports, workflows, metric snapshots). *Resolves
  TD-10; unblocks the email queue (TD-9) and webhook retry (KL-4) from actually
  draining.* This is the single highest-leverage operational fix — without it,
  time-based GRC features silently don't run.

- **FE-2 · Retire the per-request runtime migration block.** Make `install.php` +
  numbered migrations the single schema source; gate or delete the `index.php`
  runtime DDL behind a `schema_migrations` version check. *Resolves TD-1 and
  TD-4; removes the largest per-request overhead and a class of schema drift.*

- **FE-3 · Make the app horizontally scalable.** Switch sessions to the existing
  `PgSessionHandler` (migration 030) as the default and move evidence storage to
  the S3-compatible `Storage` layer that already exists (`src/Storage.php`).
  *Resolves KL-5; enables multi-replica deploys behind a load balancer.*

- **FE-4 · Fix the SSO/SAML documentation drift.** Update `README.md:894` to state
  OIDC SSO is live and that SAML2 is the unimplemented piece. *Resolves TD-8;*
  trivial effort, prevents real engineer confusion. (If SAML2 is actually
  desired, scope it as a genuine future feature — it is currently absent.)

### Priority 2 — performance & resilience

- **FE-5 · Introduce a caching abstraction.** Small TTL cache (APCu single-node /
  Redis or Postgres multi-node) behind an interface; cache expensive read-only
  aggregates (compliance %, risk counts, custom-dashboard widgets) with explicit
  write-invalidation. *Resolves TD-6; also the natural home to move rate-limit
  counters off the DB — TD-7.*

- **FE-6 · Durable, retried outbound email.** Add an email-queue table draining via
  cron with the proven webhook back-off pattern. *Resolves TD-9; makes review
  reminders / attestation campaigns reliable.* Depends on FE-1.

- **FE-7 · Shared server-side pagination for high-cardinality lists.** A reusable
  keyset/offset helper applied to risks, controls, incidents, evidence, and the
  activity log. *Resolves TD-5* while explicitly preserving the client-side IAM
  user list (KL-1).

- **FE-8 · Throttle session-activity writes.** Update `active_sessions.last_seen_at`
  at most once per N seconds per session. *Resolves TD-2.*

### Priority 3 — maintainability & polish

- **FE-9 · Tokenize category/status colors.** Map category/status hex to `--cat-*`
  CSS custom properties across threat/treatment (and the 84 hex-bearing views) so
  branding and dark mode reach them. *Resolves TD-11.*

- **FE-10 · Scale dynamic routing if route count keeps growing.** Bucket dynamic
  routes by first path segment or compile a combined regex. *Resolves TD-3;* defer
  until the linear loop measurably hurts.

- **FE-11 · Plan removal of legacy crypto-compat branches.** After confirming all
  settings re-encrypted under `APP_ENCRYPTION_KEY` and all API keys upgraded to
  HMAC, remove the legacy fallbacks in `Security::decryptSetting()` /
  `validateApiKey()`. *Resolves TD-12; reduces branches in security code.*

- **FE-12 · SAML2 support and richer XLSX (OOXML) export** — genuine net-new
  features (KL-7, KL-8), to be scoped on demand, not debt.

### Cross-cutting (carried from MODERNIZATION.md, not duplicated here)

- **Device/responsive QA sweep** across every page on real devices — explicitly
  *not* performed in the modernization pass (MODERNIZATION.md §7). Recommended
  before any major release.
- **Deeper SSRF posture for infra endpoints** — an optional config to block all
  private ranges for SMTP/S3 when a deployment never uses internal relays
  (MODERNIZATION.md §5, "Needs a deployment decision"). Left as the safe default.

---

## 5. Summary

| Category | Count | Highest priority |
|---|---|---|
| Technical Debt Register items (§2) | 12 (TD-1…TD-12) | 4 × P1 (TD-1, TD-4, TD-8, TD-10) |
| Known Limitations (§3) | 10 (KL-1…KL-10) | n/a — intentional boundaries |
| Future Enhancements (§4) | 12 (FE-1…FE-12) | 4 × P1 (FE-1…FE-4) |

**Headline.** AEGIS is a well-built, security-clean application (MODERNIZATION.md
baseline 6.5–7.4; zero loose `TODO`s in-tree). Its debt is **architectural and
operational**, not sloppiness: the per-request runtime migration block (TD-1), the
un-provisioned background scheduler (TD-10), the absent caching/pagination layers
(TD-5/TD-6), and a handful of documentation-drift fixes (TD-8). The four P1 items
in FE-1…FE-4 are the right first sprint — they are correctness/operational
foundations, each cheap-to-moderate, and they unblock most of the P2 work.
