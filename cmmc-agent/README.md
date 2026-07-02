# CMMC 2.0 Level 2 Compliance Agent

A Claude-powered agent (web GUI + CLI) that helps you assess, track, and close gaps across all 110 NIST 800-171 practices required for CMMC Level 2 certification.

## Build status

![runtime](https://img.shields.io/badge/python-3.11.9-blue)
![framework](https://img.shields.io/badge/flask-3.x-000000)
![llm](https://img.shields.io/badge/Claude-claude--opus--4--5-6b46c1)
![container](https://img.shields.io/badge/docker-ready-2496ed)
![render](https://img.shields.io/badge/render-blueprint-46e3b7)

> No CI pipeline is configured yet; badges reflect the target stack. See [`OPEN_ITEMS.md`](OPEN_ITEMS.md).

## Why it exists

CMMC 2.0 Level 2 requires implementing and evidencing all 110 NIST SP 800-171
practices. Tracking status, computing an assessment score, and drafting POA&Ms by
hand is slow and error-prone. This app puts an AI agent in front of a complete,
built-in control database and a set of deterministic tools so you can query your
posture, record implementation status, and generate structured POA&M entries
conversationally — while the numbers come from real, auditable tool calls rather
than model guesswork.

## Technology

| Layer | Choice |
|-------|--------|
| Language | Python **3.11.9** (`.python-version`) |
| Web framework | **Flask 3.x** |
| LLM | Anthropic API — model **`claude-opus-4-5`**, agentic tool-use loop |
| Front-end | Single embedded HTML template, **Bootstrap 5.3.3** + bootstrap-icons (jsDelivr CDN, SRI-pinned) |
| State | Local JSON files (`status.json`, `settings.json`) — **no database** |
| Packaging | `Dockerfile` (multi-stage, non-root, healthcheck), `render.yaml` |

## Features

- **Full control database** — all 110 NIST 800-171 practices with requirement text
- **Gap analysis** — per-domain or full-program gap reports
- **Compliance scoring** — overall % and per-domain breakdowns
- **POA&M generation** — structured Plan of Action & Milestones entries
- **Status tracking** — mark controls as implemented/partial/not implemented, saved locally
- **Keyword search** — find controls by topic (encryption, MFA, audit, CUI, etc.)
- **Agentic tool use** — Claude calls tools automatically based on your questions

## Prerequisites

- **Python 3.11.9** (see `.python-version`) and `pip`.
- An **Anthropic API key** (`sk-ant-...`) with available quota — this is the AI backend.
- Optional: **Docker** (to build/run the container image).

## Setup

```bash
cd cmmc-agent

# Create and activate a virtual environment
python3 -m venv .venv
source .venv/bin/activate        # Windows: .venv\Scripts\activate

# Install dependencies
pip install -r requirements.txt

# Add your Anthropic API key
cp .env.example .env
# Edit .env and set ANTHROPIC_API_KEY=sk-ant-...
```

## Running

### Web GUI (recommended)
```bash
python server.py
# Open http://localhost:5050
```
Features: chat interface, live compliance score ring, per-domain progress bars,
POA&M display, and a **?** help button with full usage instructions.

### CLI
```bash
python agent.py
```

## Example Prompts

```
score my program
what are my gaps in the IA domain
check control 3.5.3
search controls for encryption
mark 3.5.3 as implemented — using Okta MFA with FIDO2 hardware tokens
generate a POA&M for 3.13.8 — CUI is transmitted over unencrypted internal links
what controls cover CUI at rest
list all domains
show me all gaps across every domain
```

## How Status Is Saved

Implementation status is stored in `status.json` (gitignored). Each entry records:
- `status`: `implemented` | `partial` | `not_implemented` | `not_assessed`
- `notes`: your implementation details or evidence location
- `updated`: date last changed

## Scoring

| Score  | Meaning                        |
|--------|--------------------------------|
| 90–100 | Assessment-ready               |
| 70–89  | Moderate gaps, manageable POA&Ms |
| 50–69  | Significant remediation needed |
| < 50   | High risk, major gaps          |

Partial implementations count as 50% toward the score.

## CMMC Domains Covered

| Code | Domain                          | Controls |
|------|---------------------------------|----------|
| AC   | Access Control                  | 22       |
| AT   | Awareness & Training            | 3        |
| AU   | Audit & Accountability          | 9        |
| CM   | Configuration Management        | 9        |
| IA   | Identification & Authentication | 11       |
| IR   | Incident Response               | 3        |
| MA   | Maintenance                     | 6        |
| MP   | Media Protection                | 9        |
| PS   | Personnel Security              | 2        |
| PE   | Physical Protection             | 6        |
| RA   | Risk Assessment                 | 3        |
| CA   | Security Assessment             | 4        |
| SC   | System & Comms Protection       | 16       |
| SI   | System & Information Integrity  | 7        |

## HTTP API

The web GUI is backed by a small JSON API:

| Method + path | Purpose |
|---------------|---------|
| `GET /` | Embedded HTML UI |
| `POST /api/chat` | Agentic chat — body `{"history":[{role,content}...]}` → `{"reply","tool_log"}` (HTTP 500 if `ANTHROPIC_API_KEY` unset) |
| `GET /api/dashboard` | Program score JSON `{"overall_score_pct","domains":{...}}` — **no API key required; health probe** |
| `POST /api/mark` | Record status `{control_id,impl_status,notes}` → `{"message":...}` |
| `GET`/`POST /api/settings` | Branding (`appName`/`logoUrl`/`accent`), persisted to `settings.json` |

## Repository layout

```
cmmc-agent/
├── agent.py            # 110-control database + 7 agent tools + CLI REPL
├── server.py           # Flask app, HTTP routes, embedded UI, branding/settings
├── requirements.txt    # anthropic, python-dotenv, rich, flask
├── .env.example        # ANTHROPIC_API_KEY placeholder
├── .python-version     # 3.11.9
├── Dockerfile          # multi-stage, non-root, healthcheck on /api/dashboard
├── render.yaml         # Render Blueprint (python runtime)
├── deployments/        # operator guides (6 targets)
├── docs/               # architecture, deployment, DR, security
├── README.md           # this file
├── OPEN_ITEMS.md       # production-readiness register
└── CLAUDE.md           # project guidance
```

## Common commands

```bash
# Run the web GUI (default http://localhost:5050)
python server.py

# Run the CLI agent
python agent.py

# Build & run the container
docker build -t cmmc-agent .
docker run -p 5050:5050 -e ANTHROPIC_API_KEY=sk-ant-... cmmc-agent

# Smoke test (no API key needed)
curl -fsS http://localhost:5050/api/dashboard

# Change the port
PORT=8080 python server.py
```

## Dependencies

Python packages (`requirements.txt`):

| Package | Version | Purpose |
|---------|---------|---------|
| `anthropic` | `>=0.40.0` | Anthropic SDK — the AI backend (`claude-opus-4-5`) |
| `python-dotenv` | `>=1.0.0` | Load `ANTHROPIC_API_KEY` / `PORT` from `.env` |
| `rich` | `>=13.0.0` | CLI rendering for `agent.py` |
| `flask` | `>=3.0.0` | Web server + JSON API (`server.py`) |

Front-end assets (Bootstrap 5.3.3 + bootstrap-icons 1.11.3) load from the jsDelivr
CDN with SRI integrity hashes — no build step. No external scanner binaries or
database extensions are required.

> For production, add a WSGI server (e.g. `gunicorn`) and a reverse proxy — the
> app currently runs the Flask development server. See [`OPEN_ITEMS.md`](OPEN_ITEMS.md)
> and [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Supported deployment models

| Model | Guide |
|-------|-------|
| Managed PaaS (Render) | [`render.yaml`](render.yaml) + [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) |
| Local development | [`deployments/LOCAL_DEVELOPMENT.md`](deployments/LOCAL_DEVELOPMENT.md) |
| Single Linux server | [`deployments/SINGLE_LINUX_SERVER.md`](deployments/SINGLE_LINUX_SERVER.md) |
| Kubernetes | [`deployments/KUBERNETES.md`](deployments/KUBERNETES.md) |
| AWS (Commercial + GovCloud) | [`deployments/AWS.md`](deployments/AWS.md) |
| Azure (Commercial + Government) | [`deployments/AZURE.md`](deployments/AZURE.md) |
| Air-gapped / on-prem (Ollama) | [`deployments/AIRGAPPED.md`](deployments/AIRGAPPED.md) |

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — platform, components, request/error contract, security & observability model
- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) — deployment models, secrets, Ollama configuration, GPU acceleration, production checklist
- [`docs/DISASTER_RECOVERY.md`](docs/DISASTER_RECOVERY.md) — state, RPO/RTO, backups, restore runbook, HA
- [`docs/SECURITY.md`](docs/SECURITY.md) — authentication, data protection, CUI handling, FIPS readiness, secrets rotation
- [`OPEN_ITEMS.md`](OPEN_ITEMS.md) — production-readiness open items
- [`CLAUDE.md`](CLAUDE.md) — project guidance and conventions
