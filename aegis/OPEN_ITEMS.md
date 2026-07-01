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
| TD-4 (P1) | Schema is expressed in **three** places: `database/schema.sql`, `database/migrations/*` (36 files), and the TD-1 runtime block | Drift risk; harder to satisfy the "schema.sql reflects all migrations" rule | Treat migrations as canonical; regenerate/validate `schema.full.sql` in CI; shrink `schema.sql` to a pointer or keep it validated |

---

## 2. Documentation Drift

| ID | Item | Impact | Suggested action |
|---|---|---|---|
| TD-8 (P1) | Legacy README/feature copy under-states SSO: **OIDC is fully implemented and wired** (`src/SSO.php`, `SSOController`); **SAML2 is not** implemented | Engineers may distrust/rebuild working OIDC, or expect SAML2 | Ensure all docs say "OIDC SSO implemented; SAML2 not supported" (this README/doc set does) |
| — (P3) | `render.yaml` header comment says "five cron services" but defines **six** | Minor internal doc drift | Fix the comment to say six |

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
