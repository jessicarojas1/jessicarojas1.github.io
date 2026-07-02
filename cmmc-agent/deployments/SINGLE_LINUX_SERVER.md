# Single Linux Server — CMMC 2.0 Level 2 Compliance Agent

Operator guide for running the **CMMC 2.0 Level 2 Compliance Agent** on one
Linux VM behind an nginx TLS reverse proxy. The app is a Flask web GUI plus a
Claude-powered agentic CLI covering all **110 NIST 800-171 practices** for CMMC
Level 2.

Two deploy styles are documented: a **systemd unit** running `python server.py`
under a venv, or **docker-compose**. In both cases nginx terminates TLS and
proxies to the app on `127.0.0.1:5050`.

Sibling guides: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
[KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
[AIRGAPPED.md](AIRGAPPED.md). Platform guide: [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

---

## 1. Deployment architecture

A single synchronous **Flask** process serves the embedded HTML UI and a small
JSON API. **No database, no object store, no background worker, no queue, no
login/auth.** All state is two local JSON files in the app directory:

| File            | Purpose                                            |
|-----------------|----------------------------------------------------|
| `status.json`   | Implementation status + notes per NIST 800-171 control |
| `settings.json` | Branding (app name, logo URL, accent color)         |

The app calls the hosted **Anthropic API** (`api.anthropic.com`, model
`claude-opus-4-5`, hardcoded). Only secret: `ANTHROPIC_API_KEY`.

Topology on the VM: nginx (public :443, TLS) → Flask (`127.0.0.1:5050`, loopback
only) → Anthropic API (outbound HTTPS). The app should **not** be exposed
directly on a public interface; bind it to loopback and let nginx front it.

---

## 2. Topology

```
                    Internet
                       │  HTTPS :443
                       ▼
        ┌────────────────────────────────┐
        │  Linux VM                       │
        │                                 │
        │   nginx (TLS, reverse proxy)    │
        │       │  proxy_pass             │
        │       ▼  http://127.0.0.1:5050  │
        │   Flask (server.py)             │──HTTPS──▶ api.anthropic.com
        │       │                         │          (claude-opus-4-5)
        │       ├─▶ status.json           │
        │       └─▶ settings.json         │
        │                                 │
        │   systemd  OR  docker-compose   │
        └────────────────────────────────┘
```

---

## 3. Prerequisites

| Requirement       | Version / detail                                                     |
|-------------------|---------------------------------------------------------------------|
| Linux VM          | Any modern distro with systemd (Ubuntu 22.04+, RHEL 9, Debian 12…)  |
| Python            | **3.11.9** (systemd path) — via distro packages or pyenv            |
| Docker + Compose  | (docker-compose path) recent Docker Engine + `docker compose`       |
| nginx             | Recent stable                                                       |
| certbot           | For Let's Encrypt TLS certificates                                  |
| DNS               | An A/AAAA record pointing to the VM for the TLS hostname            |
| Anthropic key     | `sk-ant-...` with quota for Opus-class calls                        |
| Egress            | Outbound HTTPS to `api.anthropic.com`                               |

---

## 4. Identity & credentials

There is no cloud IAM on a plain VM, so the documented mechanism is a **static
`ANTHROPIC_API_KEY` delivered via a root-owned, `0600` EnvironmentFile** — never
inline in the unit file, never in the repo.

- Create a dedicated non-root system user to run the app (`cmmc`).
- Store the key in `/etc/cmmc-agent/env`, owned `root:cmmc`, mode `0600`.
- If the VM is a cloud instance (AWS/Azure/GCP), **prefer** pulling the key from
  the cloud secret store at boot (AWS Secrets Manager via instance role, Azure
  Key Vault via managed identity) and writing it into the EnvironmentFile with a
  small provisioning step — see [AWS.md](AWS.md) / [AZURE.md](AZURE.md). The
  static file is the portable fallback.
- Rotate by editing `/etc/cmmc-agent/env` and restarting the service; revoke the
  old key in the Anthropic Console.

```bash
sudo useradd --system --create-home --shell /usr/sbin/nologin cmmc
sudo mkdir -p /etc/cmmc-agent
printf 'ANTHROPIC_API_KEY=sk-ant-...\n' | sudo tee /etc/cmmc-agent/env >/dev/null
sudo chown root:cmmc /etc/cmmc-agent/env
sudo chmod 0600 /etc/cmmc-agent/env
```

---

## 5. Environment variables

| Variable            | Example              | Purpose                                                                 |
|---------------------|----------------------|-------------------------------------------------------------------------|
| `ANTHROPIC_API_KEY` | `sk-ant-abc123...`   | **Required** for chat. Missing → `/api/chat` returns 500 `{"error":"ANTHROPIC_API_KEY not set"}`. Supply via EnvironmentFile. |
| `PORT`              | `5050`               | Port Flask binds. Set `Environment=PORT=5050` in the unit or in compose. |

---

## 6. Configuration references

| Variable            | Example           | Purpose                                                        |
|---------------------|-------------------|----------------------------------------------------------------|
| `PORT`              | `5050`            | Bind port. `host` is fixed to `0.0.0.0` in `server.py`; nginx restricts real exposure to loopback via `proxy_pass`. |
| `ANTHROPIC_API_KEY` | `sk-ant-...`      | Anthropic credential used by the agentic tool loop.            |
| Model (in code)     | `claude-opus-4-5` | Hardcoded in `server.py`/`agent.py`; changing provider/model needs a code edit. |

State files `status.json` / `settings.json` are created at first write in the
app working directory (below, `/opt/cmmc-agent`).

---

### Style A — systemd + venv

```bash
sudo mkdir -p /opt/cmmc-agent
sudo chown cmmc:cmmc /opt/cmmc-agent
# copy the cmmc-agent/ app contents into /opt/cmmc-agent (server.py, agent.py, requirements.txt, .python-version)
sudo -u cmmc python3.11 -m venv /opt/cmmc-agent/.venv
sudo -u cmmc /opt/cmmc-agent/.venv/bin/pip install -r /opt/cmmc-agent/requirements.txt
```

`/etc/systemd/system/cmmc-agent.service`:

```ini
[Unit]
Description=CMMC 2.0 Level 2 Compliance Agent (Flask)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=cmmc
Group=cmmc
WorkingDirectory=/opt/cmmc-agent
EnvironmentFile=/etc/cmmc-agent/env
Environment=PORT=5050
ExecStart=/opt/cmmc-agent/.venv/bin/python /opt/cmmc-agent/server.py
Restart=on-failure
RestartSec=3
# Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
# App must write status.json / settings.json in its WorkingDirectory:
ReadWritePaths=/opt/cmmc-agent

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cmmc-agent
sudo systemctl status cmmc-agent
```

### Style B — docker-compose

`/opt/cmmc-agent/docker-compose.yml`:

```yaml
services:
  cmmc-agent:
    build: .                     # or image: your-registry/cmmc-agent:tag
    ports:
      - "127.0.0.1:5050:5050"    # loopback only; nginx fronts it
    environment:
      - PORT=5050
    env_file:
      - /etc/cmmc-agent/env      # ANTHROPIC_API_KEY
    volumes:
      - cmmc-state:/app          # persists status.json / settings.json
    restart: unless-stopped

volumes:
  cmmc-state:
```

```bash
cd /opt/cmmc-agent && docker compose up -d --build
```

### nginx reverse proxy + TLS

`/etc/nginx/sites-available/cmmc-agent.conf`:

```nginx
server {
    listen 80;
    server_name cmmc.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name cmmc.example.com;

    ssl_certificate     /etc/letsencrypt/live/cmmc.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/cmmc.example.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:5050;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        # /api/chat can be slow (LLM round-trips); raise read timeout.
        proxy_read_timeout 300s;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/cmmc-agent.conf /etc/nginx/sites-enabled/
sudo certbot --nginx -d cmmc.example.com          # issues + wires TLS
sudo nginx -t && sudo systemctl reload nginx
```

---

## 7. Verification

```bash
# 1. App is up locally (no API key needed):
curl http://127.0.0.1:5050/api/dashboard
# expect JSON: {"overall_score_pct": <N>, "domains": {...}}

# 2. Through nginx over TLS:
curl https://cmmc.example.com/api/dashboard

# 3. Chat — confirm ANTHROPIC_API_KEY resolved end-to-end:
curl -X POST https://cmmc.example.com/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"history":[{"role":"user","content":"score my program"}]}'
# expect {"reply": "...", "tool_log": [...]}
# 500 {"error":"ANTHROPIC_API_KEY not set"} => EnvironmentFile not loaded / restart needed.

# 4. Confirm state files persist — mark a control, re-check the score:
curl -X POST https://cmmc.example.com/api/mark \
  -H 'Content-Type: application/json' \
  -d '{"control_id":"AC.L2-3.1.1","impl_status":"implemented","notes":"vm verify"}'
curl https://cmmc.example.com/api/dashboard   # count/score reflects the mark
```

Confirm the files exist on disk:

```bash
# systemd path:
sudo ls -l /opt/cmmc-agent/status.json /opt/cmmc-agent/settings.json
# compose path (named volume):
docker compose exec cmmc-agent ls -l /app/status.json /app/settings.json
```

There is **no database or object store to verify** — persistence is entirely the
two JSON files.

---

## 8. Day-2 operations

- **Upgrades (systemd)**: deploy new code to `/opt/cmmc-agent`,
  `sudo -u cmmc /opt/cmmc-agent/.venv/bin/pip install -r requirements.txt`,
  `sudo systemctl restart cmmc-agent`.
- **Upgrades (compose)**: `docker compose pull` / `docker compose build`, then
  `docker compose up -d`.
- **Scaling**: this is a single-VM, single-process design. State is local JSON,
  so you cannot simply run multiple app instances against the same host without
  a shared, single-writer state directory. For higher availability use
  [KUBERNETES.md](KUBERNETES.md) with a single replica + PVC, or keep this VM
  single-instance and scale the VM vertically.
- **Backups**: copy the two JSON files on a schedule (this is all the state):
  ```bash
  sudo install -d -m 700 /var/backups/cmmc
  sudo cp /opt/cmmc-agent/status.json /opt/cmmc-agent/settings.json \
      /var/backups/cmmc/cmmc-$(date +%F).d/ 2>/dev/null || true
  ```
  Add to cron/systemd-timer and ship offsite.
- **Secret rotation**: edit `/etc/cmmc-agent/env`, `sudo systemctl restart
  cmmc-agent` (or `docker compose up -d`), revoke the old key in the console.
- **Certificate rotation**: certbot installs a renewal timer; verify with
  `sudo certbot renew --dry-run`. Renewals reload nginx automatically.
- **Migrations**: **none** — no database exists; upgrades are code-only.
- **Logs**: `journalctl -u cmmc-agent -f` (systemd) or `docker compose logs -f`
  (compose); nginx access/error logs under `/var/log/nginx/`.

---

## 9. Troubleshooting

| Symptom                                            | Cause                                            | Fix                                                                    |
|----------------------------------------------------|--------------------------------------------------|------------------------------------------------------------------------|
| `POST /api/chat` → 500 `ANTHROPIC_API_KEY not set` | EnvironmentFile not loaded / empty               | Check `/etc/cmmc-agent/env` perms + content; `systemctl restart`.      |
| 502 Bad Gateway from nginx                         | Flask not running / wrong upstream port          | `systemctl status cmmc-agent`; confirm it listens on 127.0.0.1:5050.   |
| `Address already in use`                           | Port 5050 already bound                          | Stop the conflicting process, or change `PORT` (and nginx `proxy_pass`).|
| Anthropic 401 in `tool_log`                        | Bad / revoked API key                            | Update the key in the EnvironmentFile; restart.                        |
| `/api/dashboard` → `overall_score_pct: 0`          | Fresh `status.json`, nothing marked yet          | Expected; mark controls via UI or `POST /api/mark`.                    |
| TLS cert expired / browser warning                 | certbot renewal not running                      | `sudo certbot renew`; check the systemd renew timer.                   |
| Chat times out through nginx                        | Default proxy read timeout too low for LLM calls | Set `proxy_read_timeout 300s;` (as shown) and reload nginx.           |
| App can't write `status.json` (500 on `/api/mark`) | WorkingDirectory not writable by `cmmc`          | Ensure `/opt/cmmc-agent` is owned by `cmmc` and in `ReadWritePaths`.   |
