# CLAUDE.md — CMMC 2.0 Level 2 Compliance Agent

Project guidance for the `cmmc-agent/` service. Read this before making changes.
This project also inherits the repo-root `../CLAUDE.md` rules (the standard doc set,
security/UI audits, branding standard, "header logo links home", etc.).

## What this is

A Claude-powered agent that helps assess, track, and close gaps across all **110
NIST 800-171 practices** required for **CMMC 2.0 Level 2** certification. It ships
two entrypoints:

- **`server.py`** — a Flask web GUI (chat + live compliance score ring + per-domain
  progress bars + POA&M display + help & settings/branding modals).
- **`agent.py`** — a CLI REPL (uses `rich`) plus the shared control database and the
  7 agent tools.

## Stack

| Layer | Choice |
|-------|--------|
| Language | Python **3.11.9** (`.python-version`) |
| Web framework | **Flask 3.x** (dev server via `app.run`) |
| LLM | Anthropic API, model **`claude-opus-4-5`**, agentic tool-use loop |
| Front-end | Single embedded HTML template, **Bootstrap 5.3.3** + bootstrap-icons via jsDelivr CDN (SRI-pinned) |
| Deps | `anthropic`, `python-dotenv`, `rich`, `flask` (see `requirements.txt`) |
| State | Local JSON files: `status.json` (control status), `settings.json` (branding). **No database.** |
| Container | `Dockerfile` (python:3.11.9-slim, multi-stage, non-root `appuser`, healthcheck) |
| PaaS | `render.yaml` (python runtime, `healthCheckPath: /api/dashboard`) |

## Where things live

```
cmmc-agent/
├── agent.py            # CONTROLS (110 practices) + DOMAIN_NAMES + 7 tools + CLI loop
├── server.py           # Flask app, HTTP routes, embedded UI, branding/settings
├── requirements.txt    # anthropic, python-dotenv, rich, flask
├── .env.example        # ANTHROPIC_API_KEY placeholder (copy to .env)
├── .python-version     # 3.11.9
├── Dockerfile          # container build (non-root, healthcheck on /api/dashboard)
├── render.yaml         # Render Blueprint
├── deployments/        # LOCAL_DEVELOPMENT, SINGLE_LINUX_SERVER, KUBERNETES, AZURE, AWS, AIRGAPPED
├── docs/               # ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY, SECURITY
├── README.md
├── OPEN_ITEMS.md
└── CLAUDE.md           # this file
```

## HTTP endpoints

| Method + path | Purpose | Notes |
|---------------|---------|-------|
| `GET /` | Embedded HTML UI | dark theme, chat + score sidebar |
| `POST /api/chat` | Agentic chat; body `{"history":[{role,content}...]}` → `{"reply","tool_log"}` | HTTP 500 `{"error":"ANTHROPIC_API_KEY not set"}` if key missing |
| `GET /api/dashboard` | Program score JSON `{"overall_score_pct","domains":{...}}` | **No API key required — health probe** |
| `POST /api/mark` | Record status `{control_id,impl_status,notes}` → `{"message":...}` | writes `status.json` |
| `GET/POST /api/settings` | Branding (`appName`/`logoUrl`/`accent`) | writes `settings.json`; logoUrl sanitized to `http(s)://`/`data:image/` |

## Agent tools (local Python, no external calls)

`check_control`, `list_gaps`, `score_program`, `generate_poam`, `mark_control`,
`search_controls`, `list_domains`. Control data is the in-memory `CONTROLS` dict in
`agent.py` (14 domains: AC, AT, AU, CM, IA, IR, MA, MP, PS, PE, RA, CA, SC, SI).

## Scoring

Per domain: `(implemented + 0.5*partial) / total * 100`. Overall: same numerator
summed over all 110 controls. Status values: `implemented | partial |
not_implemented | not_assessed`.

## Config

| Variable | Example | Purpose |
|----------|---------|---------|
| `ANTHROPIC_API_KEY` | `sk-ant-...` | **Required.** The AI backend key. Never commit; use `.env` locally, a secret manager in cloud. |
| `PORT` | `5050` | Web server port. Default `5050`; Render uses `10000`. |

## Build / run / test

```bash
# Local (native)
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env          # set ANTHROPIC_API_KEY
python server.py              # http://localhost:5050
python agent.py               # CLI REPL

# Docker
docker build -t cmmc-agent .
docker run -p 5050:5050 -e ANTHROPIC_API_KEY=sk-ant-... cmmc-agent

# Smoke test (no key needed)
curl -fsS http://localhost:5050/api/dashboard
```

There is **no automated test suite** and **no database/migrations** — verify by
running the app and hitting `/api/dashboard` (and `/api/chat` with a key set).

## Conventions & rules that apply

- **No inline event handlers** in the UI — the embedded JS wires everything via
  `addEventListener` (CSP-friendly). Keep it that way.
- **Branding standard**: Settings → Branding lets the user set display name, logo
  (URL or uploaded `data:` URL), and accent color; persisted to `settings.json` +
  `localStorage`; logo URLs sanitized to `http(s)://` / `data:image/` on both
  client and server. The header logo links home (`/`).
- **Never commit `.env`** — only `.env.example` with placeholders.
- **Secrets**: `ANTHROPIC_API_KEY` is the sole secret; prefer IAM role / managed
  identity + secret manager in cloud (see `deployments/`).
- **Air-gapped / CUI**: hosted-Anthropic egress is not allowed on CUI networks;
  use self-hosted Ollama per `deployments/AIRGAPPED.md` (a small code change to
  repoint the client — not a pure env swap).

## Standing rule — keep the doc set current

This app must always carry, and keep accurate to the code, the standard set:
`deployments/` (×6), `docs/` (×4: ARCHITECTURE, DEPLOYMENT, DISASTER_RECOVERY,
SECURITY), `README.md`, `OPEN_ITEMS.md`, `CLAUDE.md`, `Dockerfile`, `render.yaml`.
Whenever a feature, endpoint, env var, or config changes, update the affected docs
in the same change. Do not invent commands, env vars, ports, or paths — verify
against the real code.
