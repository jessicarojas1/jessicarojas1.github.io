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
| CSRF protection | 🔶 | Optional same-origin guard now shipped. When `ALLOWED_ORIGINS` is set (comma-separated), state-changing `POST/PUT/PATCH/DELETE` requests whose `Origin`/`Referer` do not match are rejected `403`. Off by default (local-first). | **Done (in-repo, env-gated).** Verified: `server.py` `_request_pre()` before_request checks `_allowed_origins()`; test — cross-origin `POST /api/mark` → `403 {"error":"cross-origin request rejected"}`, same-origin → `200`. Enable in production alongside the auth proxy. |

## 2. Secrets & Identity

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| `ANTHROPIC_API_KEY` handling | 🔶 | Read from env / `.env` (gitignored). No committed secret. | In cloud, source from Secrets Manager / Key Vault via IAM role / managed identity (see `deployments/AWS.md`, `deployments/AZURE.md`). |
| Key rotation | ❌ | No documented rotation cadence in-app. | Rotate in the secret store + redeploy/restart; see `docs/SECURITY.md`. |

## 3. Transport & Serving

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| WSGI server | ✅ | `gunicorn>=21.2.0` added to `requirements.txt`; the container and `render.yaml` now start `gunicorn ... server:app` (single worker via `WEB_CONCURRENCY`, one JSON-state writer). `python server.py` kept for local/dev. | **Done.** Verified: `gunicorn --bind 127.0.0.1:5099 server:app` boots and `GET /healthz` → `{"status":"ok","service":"cmmc-agent"}`. Terminate TLS at a reverse proxy (below). |
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
| Health endpoint | ✅ | Dedicated `GET /healthz` → `{"status":"ok","service":"cmmc-agent"}` (no scoring compute, no API key) now the Dockerfile/`render.yaml` probe; `GET /api/dashboard` still works as a scoring probe too. | **Done.** Verified: route registered on the real app; `curl /healthz` → `ok` under gunicorn. |
| Structured logging | ✅ | JSON-per-line logging (`_JsonLogFormatter`/`configure_logging` in `agent.py`) emitted to stdout; Flask `after_request` logs `http_request` (method, path, status, duration_ms, remote_addr); startup logs provider/model; `LOG_LEVEL` env. | **Done.** Verified: requests emit `{"event":"http_request",...}` lines; used by both `server.py` (web) and `agent.py` (CLI). |
| Metrics / tracing | ❌ | No metrics or traces. | Add Prometheus metrics / OpenTelemetry if operated at scale. |
| Audit log | ✅ | Append-only JSONL audit trail (`audit_log()` in `agent.py`) records every control-status mutation (`actor`, `control_id`, `previous_status`, `new_status`, `notes`, UTC `ts`) to `AUDIT_LOG_FILE` (default `audit.log`, gitignored) and mirrors to the structured logger. Wired into `tool_mark_control` so CLI, `/api/mark`, and chat `mark_control` are all covered. | **Done.** Verified: `POST /api/mark` writes a JSONL line and a `{"event":"audit",...}` log line with `actor="web:<ip>"`. |

## 6. AI Backend

| Item | State | Impact | Suggested action |
|------|-------|--------|------------------|
| Hosted Anthropic egress | 🔶 | Client construction is now env-driven (`create_client()` in `agent.py`): `AI_PROVIDER=anthropic\|ollama`, optional `ANTHROPIC_BASE_URL`, and `OLLAMA_BASE_URL`/`OLLAMA_MODEL` (air-gap). Setting `AI_PROVIDER=ollama` + `OLLAMA_BASE_URL` repoints the SDK at an Anthropic-Messages-compatible gateway in front of a self-hosted Ollama model — **no further code change**. | **Improved (in-repo).** The app still speaks the Anthropic Messages API, so the Ollama gateway must be Anthropic-compatible (e.g. LiteLLM); a native OpenAI-`/v1` Ollama path remains approach (b) in `deployments/AIRGAPPED.md`. Verified: `AI_PROVIDER=ollama OLLAMA_BASE_URL=… ` builds a client with that base_url. |
| Hardcoded model | ✅ | Model resolved by `get_model()` from `CMMC_MODEL`/`ANTHROPIC_MODEL` (anthropic, default `claude-opus-4-5`) or `OLLAMA_MODEL` (ollama, default `llama3.1:8b`). No hardcoded model string remains in `server.py`/`agent.py`. | **Done.** Verified: `grep` shows both `client.messages.create(model=model/…)`; `CMMC_MODEL=claude-sonnet-4-5` → `get_model()` returns it. |
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

_Last reviewed: 2026-07-02. Remediation pass: added gunicorn (production WSGI),
`/healthz` liveness endpoint, structured JSON logging, append-only audit trail
for control-status changes, env-driven AI provider/model selection
(`AI_PROVIDER`/`CMMC_MODEL`/`ANTHROPIC_BASE_URL`/`OLLAMA_BASE_URL`/`OLLAMA_MODEL`),
and an optional `ALLOWED_ORIGINS` same-origin guard — all wired into the running
app and verified (`py_compile`, `import server`, Flask test client, gunicorn boot)._
