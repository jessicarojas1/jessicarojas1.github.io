# Architecture — CMMC 2.0 Level 2 Compliance Agent

The **CMMC 2.0 Level 2 Compliance Agent** (`cmmc-agent/`) is a Flask web GUI plus a
Claude-powered agentic CLI that helps teams assess, track, and close gaps across all
**110 NIST SP 800-171 practices** (14 domains) required for CMMC Level 2. It is
deliberately small, local-first, and file-backed — no database, no login, no
background workers.

---

## 1. Platform

| Layer | Technology | Notes |
|-------|-----------|-------|
| Language | **Python 3.11.9** | pinned in `.python-version` |
| Web framework | **Flask 3.x** | `app.run(debug=False, host, port)` — dev server |
| LLM backend | **Anthropic SDK** (`anthropic>=0.40.0`) | model `claude-opus-4-5` |
| CLI UX | **rich** (`rich>=13.0.0`) | REPL rendering for `agent.py` |
| Config | **python-dotenv** (`>=1.0.0`) | loads `.env` |
| Front-end | **Bootstrap 5.3.3 + bootstrap-icons** | loaded from the jsDelivr CDN with SRI integrity hashes; single embedded HTML template served by `server.py` |

There are **no other runtime dependencies** (`requirements.txt` = anthropic,
python-dotenv, rich, flask). The UI is a single dark-theme HTML document embedded in
`server.py` — no build step, no bundler, no framework SPA.

---

## 2. Design Principles

- **Local-first / single process.** One synchronous Flask process serves both the UI
  and the JSON API. No clustering assumptions baked into the code.
- **Agentic tool-use.** The model reasons over compliance state by calling **7 local
  Python tools** (no external calls) in a loop until it produces a final answer.
- **No database.** All persistent state is two small JSON files on the local
  filesystem: `status.json` and `settings.json`.
- **File-based state.** State is created on first write; the app runs read-only until
  a control is marked or settings are saved.
- **CDN front-end.** Bootstrap/icons are pulled from jsDelivr with SRI hashes — no
  vendored assets, no asset pipeline.
- **Stateless-ish per request.** Each HTTP request is independent; the only shared
  mutable state is the two JSON files, making the process trivially re-creatable from
  the container image.

---

## 3. Component Overview

```
+----------------------------------------------------------+
|                        cmmc-agent                         |
|                                                           |
|  server.py            agent.py                            |
|  ----------           ----------                          |
|  Flask HTTP + UI      CONTROLS dict (110 practices)       |
|  5 JSON endpoints     DOMAIN_NAMES (14 domains)           |
|  embedded HTML UI     7 agent TOOLS (local functions)     |
|                       CLI REPL (rich)                     |
|        \                    /                             |
|         \                  /                              |
|          v                v                               |
|            Anthropic API (claude-opus-4-5)                |
|            tool-use loop: call -> tool_use ->             |
|            dispatch tool locally -> tool_result ->        |
|            loop until end_turn                            |
|                                                           |
|  State (local FS / mounted volume):                       |
|    status.json     -> control implementation status       |
|    settings.json   -> branding (appName/logoUrl/accent)   |
+----------------------------------------------------------+
```

| Component | Responsibility |
|-----------|----------------|
| **`server.py`** | Flask HTTP layer + embedded UI (chat, score ring, per-domain bars, help modal, settings/branding modal). Hosts the 5 endpoints. |
| **`agent.py`** | The `CONTROLS` data (110 NIST 800-171 practices across 14 domains), `DOMAIN_NAMES`, the 7 tools, the agentic loop, and a `rich`-based CLI REPL. |
| **Anthropic API** | LLM reasoning. Called via `anthropic.Anthropic(api_key=...)` → `client.messages.create(model="claude-opus-4-5", max_tokens=4096, system=SYSTEM_PROMPT, tools=TOOLS, messages=...)`. |
| **`status.json`** | Persistent control status (the important business data). |
| **`settings.json`** | Persistent branding (appName, logoUrl, accent). |

### The 7 agent tools (local Python, no external calls)

`check_control`, `list_gaps`, `score_program`, `generate_poam`, `mark_control`,
`search_controls`, `list_domains`.

The loop: the app calls the model; if `stop_reason == "tool_use"` it dispatches the
named tool locally, feeds the `tool_result` back into the conversation, and loops
until the model returns `end_turn`.

### Scoring model

- Per-domain score = `(implemented + 0.5 * partial) / total * 100`
- Overall score = `(sum implemented + 0.5 * sum partial) / 110 * 100`
- Status values: `implemented | partial | not_implemented | not_assessed`

---

## 4. Monorepo Placement & Internal Layout

`cmmc-agent/` is one project in a monorepo of static + service projects under
`/home/user/jessicarojas1.github.io/`.

```
jessicarojas1.github.io/
+-- cmmc-agent/                 <-- this project
    +-- agent.py                CONTROLS data + 7 tools + CLI REPL
    +-- server.py               Flask HTTP + embedded UI
    +-- requirements.txt        anthropic, python-dotenv, rich, flask
    +-- .env.example            ANTHROPIC_API_KEY only
    +-- .python-version         3.11.9
    +-- Dockerfile              python:3.11.9-slim, non-root, HEALTHCHECK
    +-- render.yaml             Render Blueprint (python runtime)
    +-- deployments/            operator guides (see below)
    |   +-- LOCAL_DEVELOPMENT.md
    |   +-- SINGLE_LINUX_SERVER.md
    |   +-- KUBERNETES.md
    |   +-- AZURE.md
    |   +-- AWS.md
    |   +-- AIRGAPPED.md
    +-- docs/
        +-- ARCHITECTURE.md     (this file)
        +-- DEPLOYMENT.md
        +-- DISASTER_RECOVERY.md
        +-- SECURITY.md
```

---

## 5. Configuration Model

Configuration is entirely **environment variables**, loaded from a local `.env` via
python-dotenv at startup.

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `ANTHROPIC_API_KEY` | **Yes** | — | The AI backend key (`sk-ant-...`). Sole secret. |
| `PORT` | No | `5050` | HTTP bind port. Render uses `10000`. |

- Web bind: `host=0.0.0.0`, `port=int(os.environ.get("PORT", 5050))`.
- `.env` is gitignored; only `.env.example` (containing just `ANTHROPIC_API_KEY`) is
  committed.
- No config files beyond `.env`; runtime state (`status.json`, `settings.json`) is
  written by the app, not read as config.

---

## 6. Request & Error Contract

Five endpoints, all JSON except `GET /`:

| Method & Path | Auth | Request | Response |
|---------------|------|---------|----------|
| `GET /` | none | — | Embedded HTML UI (dark theme) |
| `POST /api/chat` | none | `{"history":[{role,content},...]}` | `{"reply","tool_log"}` |
| `GET /api/dashboard` | none | — | Score JSON (see below) |
| `POST /api/mark` | none | `{control_id,impl_status,notes}` | `{"message":...}` |
| `GET/POST /api/settings` | none | branding fields | branding JSON |

### Error shape

When the AI key is missing, `POST /api/chat` returns **HTTP 500**:

```json
{ "error": "ANTHROPIC_API_KEY not set" }
```

### `tool_log` shape

`POST /api/chat` returns, alongside `reply`, a `tool_log` recording which of the 7
tools the agent invoked during its loop, so the UI can surface the agent's reasoning
trail.

### Dashboard JSON shape

```json
{
  "overall_score_pct": 42,
  "domains": {
    "AC": { "domain": "Access Control", "implemented": 5, "partial": 3, "total": 22, "score_pct": 29 }
  }
}
```

`GET /api/dashboard` requires **no API key** — it computes scores purely from
`status.json`. This makes it the ideal health/readiness probe.

### Settings / branding

`GET/POST /api/settings` reads/writes `settings.json` with `appName`, `logoUrl`, and
`accent`. On write: `logoUrl` is sanitized to `http(s)://` or `data:image/` only, and
`accent` is length/charset validated. The logo may be uploaded client-side and stored
as a `data:` URL inside `settings.json`.

---

## 7. Security Model

- **Unauthenticated, local-first.** No endpoint has authentication or CSRF tokens.
  This is a deliberate boundary for local/single-user use — **front the app with an
  authenticating reverse proxy if exposed**. See [`SECURITY.md`](SECURITY.md).
- **Key as sole secret.** `ANTHROPIC_API_KEY` is the only secret in the system.
- **Input sanitization.** The front-end escapes user strings via `textContent` /
  `escHtml`; the logo URL is sanitized both server- and client-side; `accent` is
  validated.
- **No RBAC, no audit log** today — documented gaps, not features. See
  [`SECURITY.md`](SECURITY.md).

---

## 8. Observability

| Signal | Today | Gap |
|--------|-------|-----|
| Logs | Flask stdout (request logs) | No structured/JSON logs |
| Health | `GET /api/dashboard` (no key required) | — |
| Metrics | none | No Prometheus/metrics endpoint |
| Tracing | none | No OpenTelemetry |

`GET /api/dashboard` is the canonical health probe (used by the Dockerfile
`HEALTHCHECK` and `render.yaml` `healthCheckPath`). Metrics and tracing are **not
implemented** and are called out as observability gaps.

---

## 9. Deployment Topology

```
   Browser  --HTTP-->  Flask (server.py)  --HTTPS-->  Anthropic API
                             |
                             v
                  Local FS / mounted volume
                  status.json  +  settings.json
```

- The browser talks to Flask; Flask talks to the Anthropic API for reasoning.
- State lives on the local filesystem or a mounted volume.
- A single process serves everything. Horizontal scaling requires a **shared RWX
  volume** for the two JSON files, or replicas diverge — see
  [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md) and
  [`DEPLOYMENT.md`](DEPLOYMENT.md).

---

## See Also

- [`DEPLOYMENT.md`](DEPLOYMENT.md)
- [`DISASTER_RECOVERY.md`](DISASTER_RECOVERY.md)
- [`SECURITY.md`](SECURITY.md)
- Deployment guides: [`../deployments/`](../deployments/)
