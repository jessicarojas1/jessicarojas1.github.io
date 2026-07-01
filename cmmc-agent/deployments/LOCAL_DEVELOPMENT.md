# Local Development — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent** on a laptop
or developer workstation. The app is a Flask web GUI plus a Claude-powered
agentic CLI that assesses, tracks, and helps close gaps across all **110 NIST
800-171 practices** for CMMC Level 2.

This guide covers two paths: a **native Python venv** and a **Docker**
container. Both reach the same UI at a local port.

Sibling guides: [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
[KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Platform guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

---

## 1. Deployment architecture

A single synchronous **Flask** process (`server.py`) serves both the embedded
HTML UI and a small JSON API. There is **no database, no object store, no
background worker, no queue, and no login/auth**. All persistent state lives in
two local JSON files in the app directory:

| File            | Purpose                                                        |
|-----------------|----------------------------------------------------------------|
| `status.json`   | Implementation status + notes per NIST 800-171 control          |
| `settings.json` | Branding (app name, logo URL, accent color)                     |

The app calls the hosted **Anthropic API** (`api.anthropic.com`) for the agentic
tool loop. The model is hardcoded in code as `claude-opus-4-5`. The only secret
is `ANTHROPIC_API_KEY`. A companion CLI REPL (`agent.py`) shares the same tools
and state files.

- Web GUI: `python server.py` → binds `0.0.0.0:5050` locally (default `PORT=5050`).
- CLI REPL: `python agent.py`.

---

## 2. Topology

```
┌────────────────────────────────────────────────────────────────────┐
│  Developer workstation                                             │
│                                                                    │
│   Browser ──HTTP──▶  Flask (server.py)  ──HTTPS──▶  api.anthropic.com
│   localhost:5050        │  :5050                     (claude-opus-4-5)
│                         │                                          │
│                         ├─▶ status.json    (control status)        │
│                         └─▶ settings.json  (branding)              │
│                                                                    │
│   Terminal ──────▶  agent.py (CLI REPL) ──shares──▶ same JSON files │
└────────────────────────────────────────────────────────────────────┘
```

Data flow: browser → Flask → Anthropic API (for `/api/chat`); local JSON files
for all state. `/api/dashboard` is served entirely from `status.json` and never
calls Anthropic.

---

## 3. Prerequisites

| Requirement            | Version / detail                                              |
|------------------------|--------------------------------------------------------------|
| Python                 | **3.11.9** (see `.python-version`)                            |
| pip / venv             | Bundled with Python 3.11                                      |
| Docker (path b only)   | Any recent Docker Engine / Docker Desktop                     |
| Anthropic account      | An API key (`sk-ant-...`) with sufficient quota for Opus-class calls |
| Network egress         | Outbound HTTPS to `api.anthropic.com` (only needed for chat) |

Python dependencies (`requirements.txt`): `anthropic>=0.40.0`,
`python-dotenv>=1.0.0`, `rich>=13.0.0`, `flask>=3.0.0`.

Get an API key from the [Anthropic Console](https://console.anthropic.com/).
Chat features will not work without it; the dashboard/scoring UI works without it.

---

## 4. Identity & credentials

Local development uses a **static API key** — this is the documented and expected
mechanism for a laptop (there is no cloud IAM/managed identity on a workstation).

Protect the key:

- Store it in `.env` (which is **gitignored**) — never commit it. Only
  `.env.example` (placeholder `ANTHROPIC_API_KEY=sk-ant-your-key-here`) is
  tracked.
- Restrict file permissions: `chmod 600 .env`.
- Do not paste the key into shell history where avoidable; prefer `.env`.
- Rotate the key in the Anthropic Console if it leaks; update `.env` and restart.

The key is loaded at runtime via **python-dotenv** from `.env`, or from an
environment variable passed to the process/container.

---

## 5. Environment variables

| Variable            | Example                    | Purpose                                                        |
|---------------------|----------------------------|----------------------------------------------------------------|
| `ANTHROPIC_API_KEY` | `sk-ant-abc123...`         | **Required** for chat. AI backend credential. Missing → `/api/chat` returns HTTP 500 `{"error":"ANTHROPIC_API_KEY not set"}`. |
| `PORT`              | `5050`                     | Optional. TCP port the Flask server binds. Default `5050`.     |

Loaded from `.env` (via python-dotenv) or the shell/container environment.

---

## 6. Configuration references

| Variable            | Example        | Purpose                                                              |
|---------------------|----------------|---------------------------------------------------------------------|
| `PORT`              | `5050`         | Bind port; `host` is fixed to `0.0.0.0` in `server.py`.             |
| `ANTHROPIC_API_KEY` | `sk-ant-...`   | Anthropic API credential used by the tool loop.                     |
| Model (in code)     | `claude-opus-4-5` | Hardcoded in `server.py`/`agent.py`. Not an env var — changing the model or provider requires a code edit. |

State files (`status.json`, `settings.json`) are created automatically in the
app directory on first write. No config file is required to start.

---

## 7. Verification

### Path (a) — native venv

```bash
cd cmmc-agent
python3 -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env
# edit .env and set ANTHROPIC_API_KEY=sk-ant-...
python server.py                   # serves http://localhost:5050
```

Open <http://localhost:5050> in a browser for the UI.

### Path (b) — Docker

```bash
cd cmmc-agent
docker build -t cmmc-agent .
docker run -p 5050:5050 -e ANTHROPIC_API_KEY=sk-ant-... cmmc-agent
```

### CLI REPL (optional)

```bash
cd cmmc-agent
source .venv/bin/activate
python agent.py                    # interactive; shares status.json / settings.json
```

### Concrete checks (both paths)

1. **Dashboard / liveness** (no API key needed):

   ```bash
   curl http://localhost:5050/api/dashboard
   # expect JSON: {"overall_score_pct": <N>, "domains": {...}}
   ```

2. **Chat / confirm the key is resolved**:

   ```bash
   curl -X POST http://localhost:5050/api/chat \
     -H 'Content-Type: application/json' \
     -d '{"history":[{"role":"user","content":"score my program"}]}'
   # expect JSON: {"reply": "...", "tool_log": [...]}
   # If you get 500 {"error":"ANTHROPIC_API_KEY not set"}, the key is not resolved.
   ```

3. **Confirm state files are written** — mark a control, then re-check the score:

   ```bash
   curl -X POST http://localhost:5050/api/mark \
     -H 'Content-Type: application/json' \
     -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"verified locally"}'
   # expect {"message": "..."}; status.json now exists/updates and /api/dashboard reflects the change.
   ```

There is **no database or object store to verify** — persistence is confirmed
purely by the presence and content of `status.json` / `settings.json` in the app
directory.

### docker-compose (single service)

```yaml
# cmmc-agent/docker-compose.yml
services:
  cmmc-agent:
    build: .
    image: cmmc-agent
    ports:
      - "5050:5050"
    env_file:
      - .env                # provides ANTHROPIC_API_KEY (and optional PORT)
    volumes:
      - cmmc-state:/app     # persists status.json / settings.json across restarts
    restart: unless-stopped

volumes:
  cmmc-state:
```

```bash
docker compose up --build
```

> Note: mounting a named volume at `/app` persists state but overlays the image's
> app directory. For dev, this is fine because the image already contains
> `server.py`/`agent.py`; the volume simply preserves the runtime-written JSON.
> If you prefer, mount only the state files instead (e.g. bind-mount the two
> JSON files) to avoid shadowing code.

---

## 8. Day-2 operations

- **Upgrades**: `git pull`, then `pip install -r requirements.txt` (venv) or
  `docker build`/`docker compose build` again. Restart the process.
- **Scaling**: not applicable locally — a single dev process is expected. State
  is local JSON, so multiple instances would not share state.
- **Backups**: copy `status.json` and `settings.json` somewhere safe. That is
  the entire application state.
  ```bash
  cp status.json settings.json ~/backups/cmmc-$(date +%F)/
  ```
- **Secret rotation**: generate a new key in the Anthropic Console, update
  `.env`, restart. Revoke the old key.
- **Migrations**: **none exist** — there is no database, so there is nothing to
  migrate. Upgrades are code-only.
- **Logs**: Flask logs to stdout/stderr (the terminal, or `docker logs`).

---

## 9. Troubleshooting

| Symptom                                             | Cause                                              | Fix                                                                 |
|-----------------------------------------------------|----------------------------------------------------|---------------------------------------------------------------------|
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set`  | Key missing from environment / `.env` not loaded   | Set `ANTHROPIC_API_KEY` in `.env`, or pass `-e` to `docker run`; restart. |
| `Address already in use` on start                   | Port 5050 already bound                             | Stop the other process, or set `PORT=5051` (and adjust `-p`).       |
| Anthropic 401 / authentication error in `tool_log`  | Invalid or revoked API key                          | Verify the key in the Anthropic Console; paste the correct value.   |
| `/api/dashboard` returns `overall_score_pct: 0`     | Fresh `status.json` — no controls marked yet        | Expected on first run; mark controls via UI or `POST /api/mark`.    |
| UI loads but chat hangs / network error             | No outbound HTTPS to `api.anthropic.com`            | Check egress/proxy/firewall; the dashboard still works offline.     |
| `ModuleNotFoundError` (flask/anthropic)             | Deps not installed / wrong interpreter              | Activate the venv and `pip install -r requirements.txt`.            |
| Docker healthcheck unhealthy                         | Container port/`PORT` mismatch                      | Ensure container `PORT` matches the exposed port (default 5050).    |
