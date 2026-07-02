# OPEN_ITEMS.md — Business Insight Dashboard

Honest production-readiness register. This app is intentionally lightweight
(zero-config, privacy-first, near-stateless), which makes some "enterprise"
concerns non-applicable and others explicitly outstanding. Items are grouped by
theme with **impact** and a **suggested action**.

Legend: ✅ done · 🟡 partial · ⛔ outstanding · ➖ not applicable (by design)

---

## 1. Authentication & Authorization

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⛔ | No built-in authentication | Anyone who can reach the URL can use it and upload/view data | Front the app with a reverse proxy providing auth: oauth2-proxy, SSO, ALB OIDC / Cognito, or Entra ID Easy Auth. See `docs/SECURITY.md`. Do **not** expose to the public internet with sensitive data until this is in place. |
| ⛔ | No authorization / roles | Cannot restrict features per user | All-or-nothing access at the proxy today. Add RBAC only if a multi-tenant/SaaS variant is built. |
| ➖ | Multi-user saved views / accounts | — | Listed as a future SaaS enhancement; not implemented. |

## 2. Data handling & persistence

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ | Uploaded data is ephemeral (in-memory only) | Strong privacy property — CSVs are never written to disk or transmitted | Keep it this way; document it (done in README/SECURITY). |
| ✅ | `branding.json` storage path configurable via env | On multi-replica or read-only-FS deployments it can now point at a shared writable volume | **Done.** `modules/branding.py` reads `BRANDING_FILE` (`_branding_file()`), used by both `load_branding()`/`save_branding()`; `save_branding` also `mkdir -p`s the parent. Defaults to `branding.json` next to `app.py`. Verified: with `BRANDING_FILE=<tmp>/sub/branding.json`, save creates the dir + file and load round-trips. Still mount a shared volume (EFS / Azure Files / PVC) for multi-replica. |
| ✅ | Upload size / DoS bounding | Bounds memory from large uploads | **Done.** `STREAMLIT_SERVER_MAX_UPLOAD_SIZE=50` (MB) set in `Dockerfile` + `render.yaml`; `modules/loader.py` reads at most `MAX_UPLOAD_ROWS`+1 rows (`pd.read_csv(nrows=...)`) and truncates to the cap, and rejects files wider than `MAX_UPLOAD_COLS`. Add container memory limits at deploy time. Verified functionally (see below). |
| ✅ | Malformed / oversized-CSV dimension limits on load | Pathological files can't blow up the parser/memory unbounded | **Done.** `load_and_detect()` enforces the row cap (truncate + `df.attrs["row_cap_applied"]`, surfaced as a `st.warning` in `app.py`) and hard-rejects the column cap with a `ValueError` (already caught → `st.error` + `st.stop`). Verified: 25-row file capped to 10 with the flag set; 9-col file with `MAX_UPLOAD_COLS=5` raised `ValueError`. |

## 3. Transport & exposure

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⛔ | TLS is not terminated by the app | Plaintext if exposed directly | Terminate TLS at the reverse proxy / load balancer / platform. |
| 🟡 | WebSocket / session affinity | Streamlit uses WebSockets; without sticky sessions multi-replica deployments show blank pages / disconnects | Enable session affinity and forward WS upgrade headers on every LB/ingress. See the `deployments/` guides. |
| ✅ | XSRF protection | CSRF on the Streamlit server | Keep `STREAMLIT_SERVER_ENABLE_XSRF_PROTECTION=true` (set in Dockerfile). |

## 4. Security hardening

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ | Branding input sanitization | Prevents XSS via logo/name/accent | Enforced in `modules/branding.py` (URL allowlist, hex validation, HTML-escape). Keep for any new user-supplied markup. |
| ✅ | Non-root container | Reduces blast radius | Dockerfile runs as uid 10001. |
| 🟡 | Read-only root filesystem | Not yet enforced | Run read-only with a writable mount only for the branding path — now practical: point `BRANDING_FILE` at the single writable mount so the rest of the FS can be read-only. |
| 🟡 | External asset fetches | `styles.py` imports Google Fonts; Plotly may fetch CDN assets | For airgapped/high-assurance, self-host fonts and Plotly assets (see `deployments/AIRGAPPED.md`). |
| ✅ | Usage telemetry disabled | No data to Streamlit | `STREAMLIT_BROWSER_GATHER_USAGE_STATS=false` set. |

## 5. Auditability & observability

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ⛔ | No application audit log | Cannot trace who did what in-app | Rely on reverse-proxy / platform access logs; add app-level audit only if auth is introduced. |
| ✅ | Health endpoint | Enables probes/monitoring | `GET /_stcore/health`. Wire to platform liveness/readiness probes. |
| 🟡 | Metrics / tracing | Limited app metrics | Expose LB/ingress metrics; add a sidecar exporter if needed. |

## 6. Resilience & operations

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ✅ | Stateless compute (except branding) | Easy horizontal scaling and fast recovery | Redeploy = recover. See `docs/DISASTER_RECOVERY.md`. |
| 🟡 | Branding backup | Losing the volume loses branding | Snapshot/back up `branding.json` or commit it as config. |
| ➖ | Database backups / migrations | — | No database; not applicable. |
| ➖ | Worker / queue / cron | — | No background process; all compute is synchronous. |

## 7. Features / roadmap (forward-looking, not blockers)

| Status | Item | Impact | Suggested action |
|--------|------|--------|------------------|
| ➖ | AI narrative generation (LLM) | Currently insights are 100% rule-based | If added, support self-hosted **Ollama** for airgapped use; document GPU/CPU modes (see `docs/DEPLOYMENT.md`). |
| ➖ | PDF/PNG report export | Nice-to-have | Listed in README future enhancements. |
| ➖ | Multi-file comparison, forecasting, goal lines | Nice-to-have | Listed in README future enhancements. |
| ➖ | Live data integrations (Sheets/Salesforce/Stripe) | Nice-to-have | Listed in README future enhancements. |

---

## Definition of "production-ready" for a sensitive deployment

- [ ] Authentication in front of the app (proxy/SSO) — **required**
- [ ] TLS terminated at the proxy/LB — **required**
- [ ] Session affinity + WebSocket upgrade headers on the LB/ingress
- [ ] Container run non-root, memory-limited, read-only FS + writable branding mount
- [x] `STREAMLIT_SERVER_MAX_UPLOAD_SIZE` bounded (=50 MB in image + Render); usage stats disabled — plus `MAX_UPLOAD_ROWS`/`MAX_UPLOAD_COLS` caps in `loader.py`
- [ ] Shared/backed-up storage for `branding.json` (if branding matters) — mount it and set `BRANDING_FILE` to the mount path
- [ ] Health probes wired to `/_stcore/health`
- [ ] Access logging via the proxy/platform retained per policy

---

_Last reviewed: 2026-07-02. Remediation pass: added env-configurable ingestion
caps (`MAX_UPLOAD_ROWS`/`MAX_UPLOAD_COLS`, enforced in `modules/loader.py`
`load_and_detect` and surfaced in `app.py`), set `STREAMLIT_SERVER_MAX_UPLOAD_SIZE=50`
in the Dockerfile + `render.yaml`, and made the branding storage path configurable
via `BRANDING_FILE` (`modules/branding.py`). All wired into the running app and
verified (`py_compile`; functional loader/branding tests)._
