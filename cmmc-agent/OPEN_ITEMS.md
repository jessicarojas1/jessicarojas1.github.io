# OPEN_ITEMS — CMMC 2.0 Level 2 Compliance Agent

Honest production-readiness register for the `cmmc-agent/` service. Items are grouped
by theme. Each row lists current state, impact, and a suggested action. This file is
part of the standard doc set and must be kept current as the app changes.

Legend: ✅ done · 🔶 partial · ❌ outstanding

---

## 1. Authentication & Authorization

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Endpoint authentication | ❌ | All routes (`/`, `/api/chat`, `/api/dashboard`, `/api/mark`, `/api/settings`) are unauthenticated. Anyone who can reach the port can query the agent (spending Anthropic tokens) and mutate `status.json`/`settings.json`. | Front the app with an authenticating reverse proxy / SSO (OAuth2 Proxy, Entra ID, Cognito) before any network exposure. Local-first use only until then. |
| Authorization / RBAC | ❌ | No roles or per-user scoping; single shared state file. | Add app-level auth + per-tenant state files, or keep single-tenant behind SSO. |
| CSRF protection | ❌ | `POST /api/chat`, `/api/mark`, `/api/settings` accept JSON with no CSRF token or origin check. | Add an origin/referer check or token once auth is introduced; low risk while local-only. |

## 2. Secrets & Identity

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| `ANTHROPIC_API_KEY` handling | 🔶 | Read from env / `.env` (gitignored). No committed secret. | In cloud, source from Secrets Manager / Key Vault via IAM role / managed identity (see `deployments/AWS.md`, `deployments/AZURE.md`). |
| Key rotation | ❌ | No documented rotation cadence in-app. | Rotate in the secret store + redeploy/restart; see `docs/SECURITY.md`. |

## 3. Transport & Serving

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| WSGI server | ❌ | Runs the Flask **development server** (`app.run`). Not intended for production load. | Add `gunicorn` to `requirements.txt` and serve `server:app` behind a reverse proxy; keep `python server.py` for local/dev. |
| TLS | ❌ | App serves plain HTTP; TLS must be terminated externally. | Terminate TLS at nginx / ALB / Container Apps ingress (see deployment guides). |

## 4. State & Data

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| File-based state | 🔶 | `status.json` + `settings.json` live on local FS. Works, but horizontal replicas diverge without a shared volume. | Run single-instance, or mount a shared RWX volume (EFS / Azure Files / RWX PVC). Consider a DB backend for multi-user. |
| Backups | ❌ | No automated backup of the two JSON files. | Snapshot the state volume or copy the JSON files on a schedule; see `docs/DISASTER_RECOVERY.md`. |
| No database / migrations | ✅ (by design) | There is no DB and no migration system — nothing to run. | Documented explicitly; revisit if multi-user is required. |

## 5. Observability

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Health endpoint | ✅ | `GET /api/dashboard` returns JSON without needing the API key; used by Dockerfile HEALTHCHECK and `render.yaml`. | — |
| Structured logging | ❌ | Only default Flask stdout logs. | Add structured (JSON) request logging; ship to the platform log sink. |
| Metrics / tracing | ❌ | No metrics or traces. | Add Prometheus metrics / OpenTelemetry if operated at scale. |
| Audit log | ❌ | Control status changes (`/api/mark`) are not audit-logged beyond the `updated` date in `status.json`. | Add an append-only audit trail of who/what/when for compliance evidence. |

## 6. AI Backend

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Hosted Anthropic egress | 🔶 | Chat content is sent to `api.anthropic.com`. Fine for non-CUI; not suitable for air-gapped/CUI networks. | For CUI/offline, use self-hosted Ollama (see `deployments/AIRGAPPED.md`) — requires a small code change to repoint the client. |
| Hardcoded model | 🔶 | Model `claude-opus-4-5` is hardcoded in `server.py` and `agent.py`. | Make the model configurable via env var for portability / Ollama. |
| Token/cost controls | ❌ | No rate limiting or per-user token budgeting. | Add rate limiting and (with auth) per-user quotas. |

## 7. Documentation & Deployment Set

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| `deployments/` ×6 | ✅ | LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED present. | Keep current with code changes. |
| `docs/` ×4 | ✅ | ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY present. | Keep current. |
| `Dockerfile` | ✅ | Multi-stage, non-root, healthcheck on `/api/dashboard`. | — |
| `render.yaml` | ✅ | Valid Blueprint; healthCheckPath set; docker runtime alternative noted. | — |
| `README.md` / `OPEN_ITEMS.md` / `CLAUDE.md` | ✅ | Present and cross-linked. | Keep current. |

---

_Last reviewed: 2026-07-01._
