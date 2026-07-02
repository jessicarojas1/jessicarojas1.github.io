# PALADIN — Single Linux Server Deployment

Operator guide for running PALADIN on one Linux VM, suitable for small teams,
pilots, or air-gapped-adjacent on-prem installs. Covers **Docker Compose** on the
host (recommended) with **nginx TLS termination**, plus notes for a **native
systemd** install. Backups, cron jobs, and rotation are included.

Related: [KUBERNETES.md](KUBERNETES.md) · [AWS.md](AWS.md) · [AZURE.md](AZURE.md)
· [AIRGAPPED.md](AIRGAPPED.md) · [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md)

---

## 1. Deployment architecture

- **nginx** (host) terminates TLS on `:443`, proxies to the PALADIN container on
  `127.0.0.1:8080` (Apache in-container plain HTTP — TLS is terminated upstream,
  as documented in `scripts/startup.sh`).
- **PALADIN app** — the `paladin` image (built from repo `Dockerfile`) via Docker
  Compose. `startup.sh` runs `install.php` (schema + migrations + first-run seed)
  then Apache.
- **PostgreSQL 16** — either the `db` Compose service (single-box) or a separate
  managed/host PostgreSQL (recommended for prod).
- **Storage** — `local` driver on a mounted disk (`uploads/`), or `s3` pointing
  at an S3-compatible endpoint (MinIO/on-prem).
- **Cron** — host crontab runs `cli/send_digests.php` and
  `cli/send_review_reminders.php` inside the app container. In-request
  `Scheduler` handles scheduled-publish / auto-expire.

## 2. Topology

```
            Internet / intranet
                   │ 443 (TLS)
            ┌──────▼───────┐
            │    nginx     │  (host, certbot/ACME certs)
            └──────┬───────┘
                   │ proxy_pass http://127.0.0.1:8080
        ┌──────────▼───────────┐
        │  paladin (Apache)    │  container :80 → host :8080
        │  install→migrate→run │
        └──────┬───────┬───────┘
               │ PDO    │ writes
          ┌────▼───┐  ┌─▼──────────────┐
          │postgres│  │ uploads/ (disk)│
          │  :5432 │  │ or S3 endpoint │
          └────────┘  └────────────────┘
   host cron ─► docker exec app php cli/send_digests.php daily
             ─► docker exec app php cli/send_review_reminders.php 14 7
```

## 3. Prerequisites

| Item | Requirement |
|---|---|
| OS | RHEL/Rocky 9, Ubuntu 22.04+, or equivalent |
| CPU / RAM | 2 vCPU / 2 GB minimum (4 GB recommended) |
| Disk | OS + ≥20 GB for `uploads/` and DB (size to content) |
| Docker | Engine 24+ with Compose v2 |
| nginx | 1.22+ (or Caddy/HAProxy) |
| certbot | For Let's Encrypt, or bring your own cert |
| Ports | 443 inbound; 8080/5432 bound to `127.0.0.1` only |

## 4. Identity & credentials

No cloud identity on a single box. Provide secrets via an **`.env` file with
`600` permissions owned by the deploy user**, or the host secret store
(`systemd` `LoadCredential`, HashiCorp Vault agent). Minimum secrets:

- `JWT_SECRET` (≥64 hex), `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `DB_PASS`.
- If `STORAGE_DRIVER=s3`: S3 credentials are entered in **Admin → Settings** and
  stored **AES-256-GCM encrypted at rest** (`Security::encryptSetting`) in the
  `settings` table — not in `.env`.

```bash
sudo install -d -m 750 -o deploy -g deploy /opt/paladin
sudo -u deploy cp .env.example /opt/paladin/.env
sudo chmod 600 /opt/paladin/.env   # then edit secrets
```

## 5. Environment variables

| Variable | Example | Purpose |
|---|---|---|
| `APP_ENV` | `production` | Hide errors, enable OPcache timestamp freeze |
| `APP_URL` | `https://paladin.example.gov` | Absolute base URL (TLS host) |
| `APP_NAME` | `PALADIN` | Brand/name |
| `JWT_SECRET` | `…64 hex…` | Token signing (**required**) |
| `DATABASE_URL` | `postgres://paladin:pw@db:5432/paladin` | DB connection |
| `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` | — | Discrete DB config (if not using URL) |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | — | First-run admin (fresh DB only) |
| `TRUSTED_PROXY_IPS` | `127.0.0.1` | nginx IP so `X-Forwarded-Proto`/`X-Real-IP` are trusted |
| `STORAGE_DRIVER` | `local` | `local` mounted disk or `s3` |
| `MAIL_TRANSPORT` | `smtp` | `smtp` to deliver, `queued` for outbox only |
| `HTTP_PORT` | `8080` | Host port (bind to `127.0.0.1`) |
| `PORT` | `80` | Apache in-container listen port |

## 6. Configuration references

| Setting (source) | Example | Purpose |
|---|---|---|
| `client_max_body_size` (nginx) | `40m` | Must be ≥ `post_max_size` (40M) for attachment uploads |
| `upload_max_filesize` (php.ini in image) | `32M` | Per-file cap (baked into Dockerfile) |
| `smtp_host`/`smtp_port`/`smtp_user`/`smtp_pass` (settings) | — | Encrypted-at-rest SMTP config |
| `s3_bucket`/`s3_region`/`s3_endpoint` (settings) | — | S3 target when `storage_driver=s3` |
| `retention_rules` (table) | — | Automated retention/purge policies |

### nginx server block (reference)

```nginx
server {
    listen 443 ssl http2;
    server_name paladin.example.gov;
    ssl_certificate     /etc/letsencrypt/live/paladin.example.gov/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/paladin.example.gov/privkey.pem;
    client_max_body_size 40m;
    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
server { listen 80; server_name paladin.example.gov; return 301 https://$host$request_uri; }
```

Bind the app to loopback in `docker-compose.yml`: `ports: ["127.0.0.1:8080:80"]`.

## 7. Verification

```bash
# Health via nginx/TLS
curl -fsS https://paladin.example.gov/health     # {"status":"healthy",...}
curl -fsS https://paladin.example.gov/healthz     # {"status":"ok"}

# Migrations applied
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT count(*) FROM schema_migrations;"

# Login (SSO or form) — browse https://paladin.example.gov/login
# Secrets resolved: admin user present
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT email FROM users WHERE role='admin';"

# Upload accepted + stored: attach a file in the UI, then
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT original_name, stored_path, is_current
   FROM attachments ORDER BY id DESC LIMIT 1;"
# local driver — file on disk:
docker compose exec app ls -la uploads/attachments | tail
# s3 driver — object in bucket:
aws s3 ls s3://$S3_BUCKET/uploads/attachments/ --endpoint-url "$S3_ENDPOINT" | tail

# Audit row hash-chained
docker compose exec db psql -U paladin -d paladin -c \
  "SET search_path TO paladin; SELECT action, log_hash IS NOT NULL AS chained
   FROM activity_log ORDER BY id DESC LIMIT 1;"
```

## 8. Day-2 operations

**Upgrade**

```bash
cd /opt/paladin && git pull      # or load a new image tar (see AIRGAPPED.md)
docker compose up --build -d     # startup.sh reapplies pending migrations
```

**Cron jobs** (host crontab, run inside the container):

```cron
0 6 * * *  cd /opt/paladin && docker compose exec -T app php cli/send_review_reminders.php 14 7
0 7 * * *  cd /opt/paladin && docker compose exec -T app php cli/send_digests.php daily
0 7 * * 1  cd /opt/paladin && docker compose exec -T app php cli/send_digests.php weekly
```

**Backups**

```bash
# Database (schema-scoped)
docker compose exec -T db pg_dump -U paladin -n paladin -Fc paladin > /backup/paladin-$(date +%F).dump
# Uploads (local driver)
tar czf /backup/uploads-$(date +%F).tgz -C /opt/paladin/uploads .
```

Encrypt backups at rest and ship off-box. Retention: keep ≥30 daily + 12 monthly.
See [../docs/DISASTER_RECOVERY.md](../docs/DISASTER_RECOVERY.md).

**Rotation**

- **TLS**: `certbot renew` (timer) → `nginx -s reload`.
- **`JWT_SECRET`**: rotate → active sessions/API tokens invalidate; users
  re-authenticate. Do during a maintenance window.
- **DB / SMTP / S3 secrets**: rotate credential, update `.env` (DB) or
  **Admin → Settings** (SMTP/S3, re-encrypted at rest), restart app.

**Scaling** — single box scales vertically; add DB read replica or move to
[KUBERNETES.md](KUBERNETES.md) / [AWS.md](AWS.md) for horizontal scale (requires
`STORAGE_DRIVER=s3` so uploads are shared).

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| 413 on upload | nginx body limit low | Set `client_max_body_size 40m;`, reload |
| 502 from nginx | App container down / wrong port | `docker compose ps`; confirm app on `127.0.0.1:8080` |
| Login shows HTTP link / cookie not set | Proxy proto not trusted | Set `TRUSTED_PROXY_IPS` to nginx IP; send `X-Forwarded-Proto` |
| `/health` 503 | DB down/unreachable | Check `db` / external PostgreSQL, credentials |
| Migrations not applied after upgrade | App not restarted | `docker compose up -d` to re-run `startup.sh` |
| Cron mail never sent | `MAIL_TRANSPORT=queued` | Set `smtp` + SMTP settings; inspect `/admin/outbox` |
| S3 uploads 403 | Bad/expired keys or region | Re-enter S3 creds in Admin → Settings; verify `s3_region`/`s3_endpoint` |
