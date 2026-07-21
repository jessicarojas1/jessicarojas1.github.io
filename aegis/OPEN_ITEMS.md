# AEGIS GRC — Production-Readiness Open Items

> Honest status of what is done vs. outstanding, mined from `docs/TECH_DEBT.md`
> and `docs/VALIDATION_REPORT.md` plus the current code. Items are grouped by
> theme; each has **impact** and a **suggested action**. Nothing here is a known
> unresolved correctness, security, or data-integrity defect — the validation
> pass fixed those. These are hardening, scale, and operator-wiring items.

Legend: **P1** address soon · **P2** opportunistic · **P3** polish · **DONE** closed.

---

## 1. Schema Management

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-1 (P1) | Runtime `ALTER/CREATE` schema guards run in `index.php` on **every request** (idempotent, try/catch) | Per-request latency; front-controller bloat; schema logic in two places | Move to migration-only; run schema changes solely via `install.php`/owner role; keep the runtime block as an emergency no-op or remove |
| TD-1a (✅ resolved, Phase 22) | Runtime-only schema promoted into **migration 038** (`assets.created_by`, `compliance_objectives.additional_information`, `users.sessions_revoked_at`/`force_password_change`/`password_changed_at`, `incidents.phi_involved`/`breach_notification_required`/`breach_notification_sent_at`/`root_cause`, `issues.resolution`, `audit_findings.audit_id`; tables `totp_used_codes`, `ai_inference_log`, `password_history`; widened `incidents_status_check`/`issues_status_check`). Verified by `tests/integration/schema_completeness_db.php`. `change_requests.implemented_at` deliberately **excluded** — the table is dropped by migration 032 (removed module), so its runtime ALTER is dead code | Fresh-install/cron-only DBs now include these; `password_history` (backs `changePassword`) is present | Done. Runtime guards left in place as idempotent no-ops (removal tracked under TD-1) |
| TD-1b (✅ resolved, Phase 24) | A truly fresh `install.php` now builds a **warning-free, complete** schema independent of the `index.php` runtime heal. Root causes fixed: the enterprise vendor columns (incl. `risk_tier`, which `VendorController` filters/orders by — the vendor list was broken on a fresh migrate-only/cron DB until first web boot) and `user_notification_prefs` (referenced by migration 006's ALTERs, created only by 008) are folded into `database/schema.sql` (which runs before the index block and the migration files), so both the `idx_vendors_risk_tier` index and migration 006 succeed. `install.php` now also **reports a warning summary** ("Schema is complete — 0 warnings" / "⚠ Completed with N warning(s)") so a future fresh-install gap is loud, not silent. Guarded by `tests/integration/schema_completeness_db.php`. Not fatal-on-warning by design (a benign warning must never abort a deploy) | Fresh / cron-only / air-gapped installs are now complete on first `install.php` | Done. `change_requests` (dropped by 032) is left as a benign no-op — migration 038 already excludes it and the runtime ALTER is a caught no-op; fully removing the runtime block is tracked under TD-1 |
| TD-4 (P1, **guarded** — Phase 25) | Schema is expressed in **three** places: `database/schema.sql`, `database/migrations/*` (38 files), and the TD-1 runtime block. **Drift is now caught in CI**: `scripts/verify_fresh_schema.php` (run in `aegis-integration.yml` after install) fails the build if a fresh install emits any schema/migration warning or is missing a curated object. The structural collapse to two sources is still outstanding (depends on TD-1) | Drift risk is now loud, not silent, but the three sources still must be hand-kept in sync | Remaining: retire the runtime block (TD-1), then treat migrations as canonical and reduce `schema.sql` to a validated snapshot |
| TD-4a (✅ resolved, Phase 22) | `schema.full.sql` staleness fixed: `account_reviews` + `account_review_items` (migration 012), `incident_updates.update_type`, and `issue_updates.update_type` were added to the dump; it now loads standalone with 0 errors (also spot-guarded by the Phase 25 drift gate) | — | Done |

---

## 2. Documentation Drift

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-8 (P1) | Legacy README/feature copy under-states SSO: **OIDC is fully implemented and wired** (`src/SSO.php`, `SSOController`); **SAML2 is not** implemented | Engineers may distrust/rebuild working OIDC, or expect SAML2 | Ensure all docs say "OIDC SSO implemented; SAML2 not supported" (this README/doc set does) |
| — (✅ resolved, Phase 23) | `render.yaml` header comment said "five" cron jobs but defines **six** — corrected to "six". Doc-set migration counts (32/36) also refreshed to **38** across `docs/` | — | Done |

---

## 3. Performance & Scale

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-3 (P3) | Dynamic routes matched by O(n) `preg_match` loop over ~407 routes | Fine now; 404 cost + growth drag | Add a static-prefix bucket or compiled trie if route count grows materially |
| TD-5 (P3, core done) | Shared `src/Pagination.php` applied to major lists; some lower-cardinality lists still `fetchAll` | Unbounded result sets on large tenants | Extend pagination to remaining lists |
| TD-6 (P3, core done) | `src/Cache.php` (Redis→APCu→passthrough) applied to dashboards/rollups; some report aggregates uncached | Repeated heavy aggregates | Cache remaining report/list aggregates + targeted `Cache::forget()` invalidation |
| TD-7 (P3) | Rate limiting uses shared Redis when configured, else authoritative DB store | Correct; needs live-Redis runtime verification | Verify Redis-backed limiter under load in a staging env |

---

## 4. Operational Wiring (operator actions)

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-10 (P3) | Background cron now declared in `render.yaml` (6 services) | Render `cron` needs a **paid** plan (`starter`); web+DB are `free`. Per-minute webhook cron cold-starts a container each run | Accept the cost or consolidate hourly jobs; for high webhook volume use a long-running worker instead of per-minute cron |
| — (P1 operator) | Shared `aegis-secrets` env group is `sync:false` (unset by default) | Until set, app uses the `JWT_SECRET`-derived encryption/audit fallback and SMTP is off | Populate `APP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, `SMTP_*` in the dashboard |
| KL-4 | Webhooks retry; **email and metrics do not self-retry beyond their queue/cron** | Missed sends if cron isn't scheduled | Ensure all cron jobs are scheduled (email drainer covers failed sends via `email_queue`) |
| TD-9 | Failed email sends queue to `email_queue` + retried with back-off (`drain_email_queue.php`) | — | **DONE** |

---

## 5. Crypto / Compatibility Debt

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-12 (P3) | Permanent back-compat branches: legacy-key **decrypt** fallback for settings; API keys silently upgrade SHA-256 → HMAC on first use | Extra branches in security-sensitive code | After confirming all ciphertext re-encrypted and keys rotated, remove the legacy branches |
| KL-3 | Audit HMAC key has a `JWT_SECRET`-derived fallback | Weaker separation if left on fallback | Set dedicated `AUDIT_HMAC_KEY` (env/`*_FILE`) in production |

---

## 6. Deployment Hardening Not-Yet-Applied by Default

| Item | Impact | Suggested action |
|---|---|---|
| Default image runs the installer at boot and keeps `install.php` | Fine for Render; not ideal for locked-down orchestration | Use `docker/Dockerfile.hardened` (removes `install.php`, `readOnlyRootFilesystem`, drop caps) for K8s/IL |
| WORM audit log (`REVOKE UPDATE/DELETE/TRUNCATE ON activity_log`) is **commented out** in `roles.sql` | Audit log mutable by the app role by default | Enable for CUI/legal-hold; run retention as a separate audited role |
| Runtime role defaults to the migration owner unless operator applies `roles.sql` | SQLi/app compromise could alter schema | Apply `database/roles.sql`, switch `DATABASE_URL` to DML-only `aegis_app` |
| `SESSION_DRIVER` defaults to file sessions | No horizontal scaling without it | Set `SESSION_DRIVER=pg` for multi-replica |
| Local `uploads/` volume is the default storage | Single point of failure; not shareable across replicas | Use `s3` driver (versioned bucket) for production/HA |

---

## 7. AI Advisor — Ollama / Air-Gapped

| Item | Impact | Suggested action |
|---|---|---|
| AI clients are pinned to **Anthropic/OpenAI** HTTPS endpoints; **no Ollama branch exists in code** | Air-gapped sites can't use AI without egress | Add a config-driven base-URL provider branch (OpenAI-compatible) to route to Ollama; allow the internal Ollama host through the `Ssrf` infra guard. Until then, run air-gapped with AI **disabled** (`ai_enabled=0`) — degrades gracefully. See `docs/DEPLOYMENT.md` §14 |

---

## 8. Intentional Limitations (documented, not defects)

- **KL-1** Admin user list is client-side search only (IAM two-pane layout), by design.
- **KL-2** Single logical tenant model; isolation via **RLS**, not per-query `WHERE` — inert for single-tenant installs.
- **KL-5** Ephemeral local file storage without shared storage/sessions blocks horizontal scale (addressed by S3 + `SESSION_DRIVER=pg`).
- **KL-6** PostgreSQL only (no MySQL/SQLite).
- **KL-7** SSO is **OIDC-only** (no SAML2); AI Advisor + SMTP are config-gated (off until configured).
- **KL-8** XLSX export is Excel-2003 SpreadsheetML; PDF import needs `pdftotext` (poppler-utils) + a text layer.
- **KL-9** No pre-loaded compliance frameworks — import via JSON/CSV/XLSX or build custom.
- **KL-10** CSP keeps `style-src 'unsafe-inline'` (styles only; scripts are nonce-gated).

---

## 9. Validation Residuals (non-blocking)

From `docs/VALIDATION_REPORT.md` — all confirmed defects were fixed; these remain as documented residuals:

- Graceful FK-existence validation in create paths (DB already enforces integrity; cosmetic error-message improvement).
- Admin lists unpaginated **by design** (live client-side search).
- No caching layer for custom-dashboard widgets.

> **Bottom line:** no known correctness/security/data-integrity defect is
> outstanding. The open items above are prioritized hardening, scale, and
> operator-wiring tasks. Keep this file current as items land — see `CLAUDE.md`.
</content>
