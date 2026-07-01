# APEX — Single Linux Server Deployment

Operator guide for running **APEX** on one Linux VM (e.g. a hardened RHEL/Ubuntu
host) fronted by nginx with TLS. APEX is PHP 8.2 + Apache (container) serving a
vanilla-JS SPA and `/api/*` REST API against PostgreSQL 16, with CAC/PIV-simulated
auth (bcrypt PINs + HS256 JWT).

This guide uses **Docker Compose on the VM** as the primary path (matching the
shipped image), with nginx as the TLS-terminating reverse proxy. A pure-systemd
native option is noted at the end.

Related: [LOCAL_DEVELOPMENT](LOCAL_DEVELOPMENT.md) · [KUBERNETES](KUBERNETES.md) ·
[AWS](AWS.md) · [AZURE](AZURE.md) · [AIRGAPPED](AIRGAPPED.md)

---

## 1. Deployment architecture

| Component | Runs as | Role |
|-----------|---------|------|
| nginx | host package (or container) | Terminates TLS (443), forces HTTPS, proxies to the app on `127.0.0.1:8080`. |
| `app` | Docker container (`apex/Dockerfile`) | Non-root `www-data`, listens on 8080. Runs `bin/start.sh` → `scripts/migrate.php` → `apache2-foreground`. |
| `db` | Docker container `postgres:16-alpine` (or host Postgres) | All persistent state. Bound to loopback + named volume. |
| systemd | host | Supervises the compose stack (`docker compose up`) and nginx. |

TLS terminates at nginx; the app trusts `X-Forwarded-Proto` (its `.htaccess`
force-HTTPS rule respects it). The app sets a Secure HttpOnly `apex_token`
cookie because `APP_ENV=production`.

---

## 2. Topology

```
            Internet
               │  :443 (TLS)
               ▼
        ┌──────────────┐   host firewall: 22,443 open; 8080,5432 loopback-only
        │    nginx     │  TLS termination, HSTS, proxy_set_header
        │  (host/svc)  │  X-Forwarded-Proto https
        └──────┬───────┘
               │ http://127.0.0.1:8080
               ▼
        ┌──────────────┐        ┌─────────────────────┐
        │  app (8080)  │───────▶│ db postgres:16       │
        │ php8.2-apache│  DATABASE_URL   127.0.0.1:5432│
        │  www-data    │        │ vol: /srv/apex/pgdata│
        └──────────────┘        └─────────────────────┘
          Docker bridge network "apex"; only nginx is internet-facing.
```

---

## 3. Prerequisites

| Item | Version / detail |
|------|------------------|
| Linux VM | 2 vCPU / 2 GB RAM minimum; RHEL 9 / Ubuntu 22.04+ |
| Docker Engine + Compose v2 | 24+ |
| nginx | 1.24+ |
| certbot (or org CA cert) | For Let's Encrypt, or install DoD/org PKI cert |
| psql client | 16 (verification) |
| Open inbound ports | 443 (and 22 for admin); **not** 8080/5432 |
| DNS | A/AAAA record → VM public IP |

---

## 4. Identity & credentials

No cloud IAM on a standalone VM. Manage secrets as root-owned files, not in
shell history or committed env files.

| Secret | Storage | Rotation |
|--------|---------|----------|
| `JWT_SECRET` | `/srv/apex/secrets.env` (`chmod 600`, root:root) — 32+ random chars: `openssl rand -hex 32` | Rotate by editing file + `docker compose up -d` (invalidates live tokens). |
| `DB_PASS` / Postgres password | Same secrets file | Rotate DB role password, then update file + restart. |
| TLS private key | `/etc/nginx/tls/apex.key` (`chmod 600`) | Renew via certbot timer or org PKI process. |

Least-privilege OS: run compose under a dedicated non-login `apex` service
account in the `docker` group; the container already runs as `www-data`
(non-root) internally.

Seed identities and PIN behavior are identical to
[LOCAL_DEVELOPMENT §4](LOCAL_DEVELOPMENT.md#4-identity--credentials). In
production **`APEX_ALLOW_DEFAULT_PINS=0`** is mandatory — the plaintext-PIN
fallback is force-disabled whenever `APP_ENV=production`, but keep it `0`
regardless.

---

## 5. Environment variables

Place in `/srv/apex/secrets.env` and reference via `env_file:` in a
production compose override.

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:S3cr3t@db:5432/apex?sslmode=prefer` | PDO PgSQL connection. Add `?sslmode=require` if DB is remote/TLS. |
| `JWT_SECRET` | `9f2c…` (≥32 chars) | HS256 signing key. App refuses to start in production if <32 chars. |
| `APP_ENV` | `production` | Enables Secure cookie, suppresses error traces, fails closed on weak secrets. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | Must be `0` in production. |
| `DB_PASS` | `S3cr3t` | Compose-only: `POSTGRES_PASSWORD` + interpolated into `DATABASE_URL`. |

Single-server deployments are cloud-partition-agnostic; AWS Commercial vs
GovCloud / Azure Commercial vs Government endpoint differences do not apply
(see [AWS.md](AWS.md), [AZURE.md](AZURE.md)).

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| App upstream | `127.0.0.1:8080` | nginx `proxy_pass` target. |
| nginx `proxy_set_header X-Forwarded-Proto` | `https` | Required so the app's force-HTTPS rule doesn't loop-redirect. |
| Postgres data dir | `/srv/apex/pgdata` | Bind or named volume for durability. |
| HSTS | `max-age=31536000; includeSubDomains; preload` | Also set by the app `.htaccess`; keep consistent at nginx. |
| Cookie | `apex_token` HttpOnly SameSite=Lax Secure | Secure flag active because `APP_ENV=production`. |

Example nginx server block:

```nginx
server {
  listen 443 ssl http2;
  server_name apex.example.mil;
  ssl_certificate     /etc/nginx/tls/apex.crt;
  ssl_certificate_key /etc/nginx/tls/apex.key;
  add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

  location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
  }
}
server { listen 80; server_name apex.example.mil; return 301 https://$host$request_uri; }
```

---

## 7. Verification

```bash
# Health (through TLS)
curl -s https://apex.example.mil/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"..."}}

# Login (secrets resolved + bcrypt verify)
TOKEN=$(curl -s -X POST https://apex.example.mil/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] && echo "login OK"

# Write a DB row (ticket) — proves API→PDO→Postgres path
curl -s -X POST https://apex.example.mil/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"prod smoke test","type":"task"}'

# Confirm persistence
docker compose -f /srv/apex/docker-compose.yml exec db \
  psql -U apex -d apex -c "SELECT id,title FROM tickets ORDER BY created_at DESC LIMIT 1;"

# TLS + HSTS headers present
curl -sI https://apex.example.mil/ | grep -i strict-transport-security
```

Checklist: health `ok:true` ✓ · login returns token (JWT_SECRET resolved) ✓ ·
new `tickets` row persisted ✓ · Secure cookie + HSTS present ✓.

---

## 8. Day-2 operations

| Task | Procedure |
|------|-----------|
| Upgrade app | `git pull` in the repo → `docker compose build app && docker compose up -d app`. Migration runs on boot (idempotent). |
| Apply new migration | Migration only auto-applies to a fresh DB. For schema changes to an existing DB, run the new SQL manually via `psql` (see `docs/DEPLOYMENT.md`). |
| Scale | Vertical only on a single VM; move to [KUBERNETES](KUBERNETES.md) / [AWS](AWS.md) for horizontal scaling. |
| Backups | `pg_dump`: `docker compose exec db pg_dump -U apex apex \| gzip > /srv/apex/backups/apex-$(date +%F).sql.gz`. Cron nightly; ship offsite encrypted. |
| Restore | `gunzip -c backup.sql.gz \| docker compose exec -T db psql -U apex -d apex`. |
| TLS renewal | `certbot renew` timer, then `nginx -s reload`. |
| Rotate JWT_SECRET | Edit secrets file → `docker compose up -d app` (logs out all sessions). |
| Logs | `docker compose logs -f app`; nginx `/var/log/nginx/*.log`. Ship to journald/SIEM. |

systemd supervision (`/etc/systemd/system/apex.service`):

```ini
[Unit]
Description=APEX compose stack
Requires=docker.service
After=docker.service
[Service]
WorkingDirectory=/srv/apex
ExecStart=/usr/bin/docker compose up
ExecStop=/usr/bin/docker compose down
Restart=always
[Install]
WantedBy=multi-user.target
```

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Redirect loop / infinite 301 | nginx not sending `X-Forwarded-Proto https` | Add the header; the app's `.htaccess` force-HTTPS checks it. |
| App won't start; log `JWT_SECRET is missing or too short` | `<32` char key with `APP_ENV=production` | Set a 32+ char `JWT_SECRET` (`openssl rand -hex 32`). |
| 502 from nginx | App container down / not on 8080 | `docker compose ps`; check `logs app`; confirm proxy_pass port. |
| Login `Invalid credentials` | Rotated/hashed PINs, defaults off | Use real PIN; regenerate hashes if needed. Defaults are off in prod by design. |
| Cookie not sent back by browser | Missing Secure/HTTPS or clock skew | Ensure real TLS; check server time (JWT `exp` is 8h). |
| DB connection failed | Wrong `DATABASE_URL` / db not ready | Verify creds/host; `depends_on` doesn't wait for readiness — retry or add a healthcheck. |
| Schema never seeds | `users` table already exists | Migration skips intentionally; drop/recreate DB only if you intend a reset. |
| Disk fills | Postgres WAL / logs unbounded | Rotate logs; monitor `/srv/apex/pgdata`; prune old backups. |
