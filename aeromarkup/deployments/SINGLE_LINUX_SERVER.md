# AeroMarkup — Single Linux Server

Operator guide for running AeroMarkup on **one Linux VM** in production. Two
supported deployments: **(a) docker-compose** (app + Postgres in containers) and
**(b) native systemd** (gunicorn under a non-root service user against a local or
managed Postgres). Both sit behind **nginx** terminating TLS.

Sibling guides: [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) ·
[KUBERNETES](KUBERNETES.md) · [AZURE](AZURE.md) · [AWS](AWS.md) ·
[AIRGAPPED](AIRGAPPED.md). See also [docs/DEPLOYMENT](../docs/DEPLOYMENT.md),
[docs/SECURITY](../docs/SECURITY.md) and
[docs/DISASTER_RECOVERY](../docs/DISASTER_RECOVERY.md).

---

## 1. Deployment architecture

A single VM runs three roles: **nginx** (TLS termination + reverse proxy),
the **AeroMarkup app** (stateless Flask/gunicorn on `127.0.0.1:8080`), and
**PostgreSQL 13+**. All persistent state is in Postgres — including uploaded
reference images and STL/OBJ 3D models, which are stored as `data:` URLs in
table columns (`drawings.background_data`, `drawings.model_data`,
`attachments.data`). There is **no object storage / S3** to provision.

The app lives in the dedicated `aeromarkup` schema (`search_path=aeromarkup,
public`), so it can coexist with other databases on the same server. With
`AUTO_MIGRATE=1` (default) it applies `db/schema.sql` at boot.

Because nginx sits in front, set `TRUSTED_PROXY_HOPS=1` so the login throttle
keys on the real client IP from `X-Forwarded-For` rather than the proxy.

`ENVIRONMENT=production` (the default) enforces secure cookies and a strong
`AEROMARKUP_SECRET` — required whenever `DATABASE_URL` is set.

| Option | App runtime | Postgres | Best for |
|--------|-------------|----------|----------|
| (a) Compose | container (Dockerfile) | container (postgres:16-alpine) | quick, self-contained |
| (b) Native systemd | gunicorn as service user | local package or managed (RDS/Azure DB) | tighter host integration, managed DB |

---

## 2. Topology

```
                 Internet (443/tcp)
                      │  TLS (Let's Encrypt)
                      ▼
            ┌───────────────────────┐
            │  nginx  (443 → )      │  proxy_pass http://127.0.0.1:8080
            │  sets X-Forwarded-For │
            └───────────┬───────────┘
                        │ 127.0.0.1:8080
                        ▼
            ┌───────────────────────┐
            │  AeroMarkup app       │  gunicorn server:app --workers 2
            │  ENVIRONMENT=production│  TRUSTED_PROXY_HOPS=1
            │  AUTO_MIGRATE=1        │  AEROMARKUP_SECRET (≥32) from EnvironmentFile
            └───────────┬───────────┘
                        │ psycopg (search_path=aeromarkup,public)
                        ▼
            ┌───────────────────────┐
            │  PostgreSQL 13+       │  schema: aeromarkup
            │  local :5432 (loopback)│  images/models = data: URLs in columns
            │  or managed (RDS/Azure)│
            └───────────────────────┘

  Same VM, all on loopback except nginx :443.  pg_dump cron → backups/
```

---

## 3. Prerequisites

| Tool | Version | Needed for |
|------|---------|-----------|
| Linux VM | any current LTS | host |
| nginx | 1.18+ | reverse proxy / TLS |
| certbot | any | Let's Encrypt certs |
| Docker Engine + Compose v2 | 24+ | Option (a) |
| Python | 3.12 | Option (b) |
| PostgreSQL | 13+ (16 recommended) | Option (b) local DB (or a managed DB) |
| `psql` / `pg_dump` client | matches server | migrate + backups |
| `curl` | any | verification |

Open only `443/tcp` (and `22` for admin) at the firewall. Keep Postgres and the
app bound to `127.0.0.1`.

---

## 4. Identity & credentials

Least privilege throughout:

- **Service user (native)**: create a dedicated non-login user (e.g. `aeromarkup`)
  that owns the app files and runs gunicorn. Do **not** run as root.
- **Postgres role**: a dedicated role owning the `aeromarkup` schema. Grant only
  that schema; superuser is not required. Bind Postgres to loopback.
- **`AEROMARKUP_SECRET`**: ≥32 chars, **required** in production with a DB. Generate:
  ```bash
  python3 -c "import secrets;print(secrets.token_urlsafe(48))"
  ```
- **Secrets on disk**: store `AEROMARKUP_SECRET` and `DATABASE_URL` in a systemd
  **`EnvironmentFile`** at `/etc/aeromarkup/aeromarkup.env`, mode `600`, owned by
  the service user. Never commit secrets; never put them on the command line.
- **No shipped default login**: create the first admin via
  `POST /api/auth/bootstrap` after startup (see Verification). App roles:
  `viewer`, `engineer`, `inspector`, `approver`, `admin`.

```bash
sudo useradd --system --home /opt/aeromarkup --shell /usr/sbin/nologin aeromarkup
sudo mkdir -p /etc/aeromarkup && sudo chown aeromarkup:aeromarkup /etc/aeromarkup
sudo install -m 600 -o aeromarkup -g aeromarkup /dev/null /etc/aeromarkup/aeromarkup.env
```

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgres://aeromarkup:pw@127.0.0.1:5432/aeromarkup` | Postgres DSN. Empty ⇒ offline-only (`no_database`); do not run production without it. |
| `PORT` | `8080` | App listen port (bind loopback; nginx proxies to it). |
| `AUTO_MIGRATE` | `1` | Apply `db/schema.sql` at boot (default 1). |
| `ENVIRONMENT` | `production` | Default. Enforces secure cookies + strong secret. |
| `AEROMARKUP_SECRET` | `k3f…` (≥32 chars) | Signs `am_session`. **Required** in production with a DB. |
| `SESSION_TTL_SECONDS` | `43200` | Session lifetime (default 12h). |
| `LOGIN_MAX_ATTEMPTS` | `5` | Failed logins before throttle. |
| `LOGIN_WINDOW_SECONDS` | `300` | Throttle window (seconds). |
| `LOGIN_MAX_TRACKED` | `8192` | Max throttle keys held in memory. |
| `TRUSTED_PROXY_HOPS` | `1` | **Set to 1** behind a single nginx hop so throttle keys on the real client IP. |
| `POSTGRES_USER` | `aeromarkup` | Compose (a) only. |
| `POSTGRES_PASSWORD` | (from `.env`) | Compose (a) only — required, no default. |
| `POSTGRES_DB` | `aeromarkup` | Compose (a) only. |

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| EnvironmentFile | `/etc/aeromarkup/aeromarkup.env` | systemd env file, mode 600, owned by service user. |
| Schema file | `db/schema.sql` | Idempotent DDL; auto-applied or `psql "$DATABASE_URL" -f db/schema.sql`. |
| Seed file | `db/seed.sql` | Optional demo data. |
| nginx site | `/etc/nginx/sites-available/aeromarkup` | Reverse-proxy server block (below). |
| systemd unit | `/etc/systemd/system/aeromarkup.service` | Native app service (below). |
| TLS certs | `/etc/letsencrypt/live/<host>/` | fullchain.pem + privkey.pem (certbot). |
| Session cookie | `am_session` | HttpOnly, signed; Secure in production. |
| CSRF cookie | `am_csrf` | Double-submit; echo in header `X-CSRF-Token`. |
| Schema search path | `aeromarkup,public` | Pinned per connection — shared-DB safe. |

---

## Option (a) — docker-compose

```bash
cd /opt/aeromarkup
cp .env.example .env
# set a strong POSTGRES_PASSWORD in .env (required, never committed)
docker compose up --build -d          # app on 127.0.0.1:8080 via 8080:8080
```
Then add the nginx block below and point `proxy_pass` at `127.0.0.1:8080`. Set
`TRUSTED_PROXY_HOPS=1` for the app service (add it under the `app` service
`environment:` in `docker-compose.yml`).

## Option (b) — native systemd

```bash
sudo git clone <repo> /opt/aeromarkup && cd /opt/aeromarkup
sudo chown -R aeromarkup:aeromarkup /opt/aeromarkup
sudo -u aeromarkup python3 -m venv /opt/aeromarkup/.venv
sudo -u aeromarkup /opt/aeromarkup/.venv/bin/pip install -r requirements.txt
```

`/etc/aeromarkup/aeromarkup.env` (mode 600, owned by `aeromarkup`):
```ini
ENVIRONMENT=production
DATABASE_URL=postgres://aeromarkup:STRONGPW@127.0.0.1:5432/aeromarkup
AEROMARKUP_SECRET=<python3 -c "import secrets;print(secrets.token_urlsafe(48))">
PORT=8080
AUTO_MIGRATE=1
TRUSTED_PROXY_HOPS=1
SESSION_TTL_SECONDS=43200
```

`/etc/systemd/system/aeromarkup.service`:
```ini
[Unit]
Description=AeroMarkup (Flask/gunicorn)
After=network.target postgresql.service
Wants=postgresql.service

[Service]
User=aeromarkup
Group=aeromarkup
WorkingDirectory=/opt/aeromarkup
EnvironmentFile=/etc/aeromarkup/aeromarkup.env
ExecStart=/opt/aeromarkup/.venv/bin/gunicorn server:app \
  --bind 127.0.0.1:8080 --workers 2 --timeout 120
Restart=on-failure
RestartSec=3
# Hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/aeromarkup

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now aeromarkup
sudo systemctl status aeromarkup
```

## nginx reverse proxy (both options)

`/etc/nginx/sites-available/aeromarkup`:
```nginx
server {
    listen 80;
    server_name aeromarkup.example.gov;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name aeromarkup.example.gov;

    ssl_certificate     /etc/letsencrypt/live/aeromarkup.example.gov/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/aeromarkup.example.gov/privkey.pem;

    # data: URLs for images/3D models can be large — allow big request bodies
    client_max_body_size 64m;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/aeromarkup /etc/nginx/sites-enabled/
sudo certbot --nginx -d aeromarkup.example.gov
sudo nginx -t && sudo systemctl reload nginx
```

> Because nginx is the single proxy hop, `TRUSTED_PROXY_HOPS=1` tells the app to
> trust exactly one `X-Forwarded-For` entry — the real client IP. Leaving it at
> `0` would throttle by the proxy's own IP.

---

## 7. Verification

Run from the VM (bypass TLS) or over the public hostname.

1) **Health** (loopback, bypassing nginx):
```bash
curl -s localhost:8080/api/health
# {"status":"ok","database":"connected","mode":"online"}
```
Through nginx: `curl -s https://aeromarkup.example.gov/api/health`.

2) **Bootstrap the first admin** (first run only, empty DB):
```bash
curl -s -X POST https://aeromarkup.example.gov/api/auth/bootstrap \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme123","display_name":"Admin"}'
```

3) **Log in and capture session + CSRF**:
```bash
curl -s -c cookies.txt -X POST https://aeromarkup.example.gov/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"changeme123"}'
CSRF=$(awk '/am_csrf/{print $7}' cookies.txt)
```

4) **Create a project** (session cookie + `X-CSRF-Token` required):
```bash
curl -s -b cookies.txt -X POST https://aeromarkup.example.gov/api/projects \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"name":"Tail Section NDT","category":"aerospace"}'
```

5) **Confirm the row in Postgres** (`aeromarkup` schema):
```bash
psql "$DATABASE_URL" -c "SELECT count(*) FROM aeromarkup.projects;"
#  count
# -------
#      1
```

6) **Service health**: `sudo systemctl is-active aeromarkup` → `active`;
   `sudo systemctl is-active nginx` → `active`.

---

## 8. Day-2 operations

- **Upgrade (native)**: `git pull` in `/opt/aeromarkup`, reinstall deps if
  changed, then `sudo systemctl restart aeromarkup`. With `AUTO_MIGRATE=1` the
  restart applies any new schema.
- **Upgrade (Compose)**: `git pull && docker compose up --build -d`.
- **Re-apply schema manually** (idempotent): `psql "$DATABASE_URL" -f db/schema.sql`.
- **Backups** — pg_dump cron for the `aeromarkup` schema (captures the data-URL
  image/model columns too):
  ```bash
  # /etc/cron.d/aeromarkup-backup  (runs 02:15 daily as aeromarkup)
  15 2 * * * aeromarkup pg_dump "$DATABASE_URL" -n aeromarkup -Fc \
    -f /var/backups/aeromarkup/am-$(date +\%F).dump
  ```
  Restore: `pg_restore -d "$DATABASE_URL" --clean am-YYYY-MM-DD.dump`. Rotate old
  dumps and copy them off-box. See [docs/DISASTER_RECOVERY](../docs/DISASTER_RECOVERY.md).
- **Logs**: native → `journalctl -u aeromarkup -f`; nginx →
  `/var/log/nginx/{access,error}.log`; Compose → `docker compose logs -f app db`.
- **Secret rotation**: edit `AEROMARKUP_SECRET` in
  `/etc/aeromarkup/aeromarkup.env`, then `sudo systemctl restart aeromarkup`.
  All existing `am_session` cookies invalidate — users must log in again.
- **TLS renewal**: certbot installs a renew timer; verify with
  `sudo certbot renew --dry-run`.

---

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| `/api/health` shows `"database":"offline"`; API returns `503 {"error":"no_database"}` | `DATABASE_URL` unset/empty in the EnvironmentFile | Set a valid `DATABASE_URL`; `systemctl restart aeromarkup`. |
| Service won't start; log: `AEROMARKUP_SECRET is missing or too weak (need >= 32 chars)` | Production (default) + DB set, but no/weak secret | Put a ≥32-char `AEROMARKUP_SECRET` in the EnvironmentFile; restart. |
| `403 {"error":"csrf_failed"}` on POST | Missing/stale `X-CSRF-Token`; cookies dropped by proxy | Log in first, send `am_csrf` value as `X-CSRF-Token`; ensure nginx forwards cookies (default `location /` does). |
| `429 {"error":"too_many_attempts"}` | Login throttle tripped | Wait `LOGIN_WINDOW_SECONDS`, or restart the app to clear in-memory counters. |
| Throttle blocks many users at once / one IP | `TRUSTED_PROXY_HOPS=0` behind nginx → keys on proxy IP | Set `TRUSTED_PROXY_HOPS=1` and confirm nginx sends `X-Forwarded-For`; restart. |
| `401 unauthorized` on API | No/expired session | Log in again; send the session cookie (`-b cookies.txt`). |
| Login/session fails only via HTTPS-terminated proxy | Secure cookie can't be set over perceived HTTP | Ensure nginx sets `X-Forwarded-Proto https` (block above) and site is served over TLS. |
| `502 Bad Gateway` from nginx | App not listening on `127.0.0.1:8080` | Check `systemctl status aeromarkup` / `docker compose ps`; confirm `PORT=8080`. |
| Large image/model upload rejected (`413`) | nginx body size limit | Raise `client_max_body_size` (block sets 64m); reload nginx. |
| `bootstrap` returns `403 already_initialized` | Admin already created | Use `/api/auth/login`; bootstrap is first-run only. |
| `bootstrap` returns `400 weak_credentials` | username <3 or password <8 | Use username ≥3, password ≥8. |
