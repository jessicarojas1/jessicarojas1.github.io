# AEGIS GRC — Single Linux Server Deployment

Audience: operators deploying AEGIS to **one Linux VM** (bare metal or an IaaS
instance) for a small team, pilot, or on-prem install. Two supported models are
documented: **Docker Compose** (recommended — reproduces the production image) and a
**native systemd** install. Both include TLS termination, backups, and secret
hygiene.

> Sibling guides: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) ·
> [KUBERNETES.md](KUBERNETES.md) · [AZURE.md](AZURE.md) · [AWS.md](AWS.md) ·
> [AIRGAPPED.md](AIRGAPPED.md)

---

## 1. Deployment architecture

AEGIS is PHP 8.3 + Apache in one container (`./Dockerfile`), backed by PostgreSQL 16.
On a single server you run four cooperating processes:

| Process | Role |
|---------|------|
| `app` | PHP 8.3 / Apache on **:8080** (non-root `www-data`); serves UI + `/api/*` + `/healthz`,`/readyz` |
| `db` | PostgreSQL 16 (container volume or a system Postgres package) |
| `nginx` | TLS termination + security-header perimeter, proxies to `app:8080`, blocks sensitive paths |
| `cron` | Background worker loop + the scheduled jobs (via systemd timers or the compose loop) |

TLS options: terminate at the bundled **nginx** (mount a real cert), or put the box
behind a managed load balancer / Cloudflare and let the app trust
`X-Forwarded-Proto` (HSTS is emitted by `src/Security.php` on the forwarded scheme).

## 2. Topology

```
        Internet (443/TLS)
              │
   ┌──────────▼───────────┐   host firewall: allow 443 (+22 admin), deny 8080/5432
   │  nginx (TLS, headers)│   /etc/letsencrypt or agency PKI cert
   │  :443 ──► app:8080   │
   └──────────┬───────────┘
              │ proxy_pass http://app:8080
   ┌──────────▼───────────┐        ┌──────────────────────┐
   │  app  PHP8.3/Apache  │◄──────►│  PostgreSQL 16        │
   │  :8080 non-root      │ :5432  │  (local, not exposed) │
   └──────────┬───────────┘        └──────────────────────┘
              │ uploads (local driver) → /var/lib/aegis/uploads
   ┌──────────▼───────────┐
   │  cron worker         │  run_workflows / dispatch_webhooks (60s loop)
   │  + systemd timers    │  notifications / reports / metrics / email queue
   └──────────────────────┘
```

## 3. Prerequisites

| Item | Recommended | Notes |
|------|-------------|-------|
| OS | Ubuntu 22.04/24.04 LTS or RHEL 9 | any modern systemd Linux |
| CPU / RAM | 2 vCPU / 4 GB min, 4 vCPU / 8 GB comfortable | matches compose mem limits (app 512M, db 1G) |
| Disk | 20 GB+ SSD | DB + uploads + logs grow with evidence volume |
| Docker + Compose v2 | 24+ | for the Docker model |
| PHP 8.3 (`pdo pdo_pgsql gd opcache`), Apache (`mod_rewrite`,`mod_headers`), Postgres 16, `poppler-utils` | — | for the native model |
| DNS A/AAAA record + TLS cert | — | `certbot` (public) or agency CA (private) |
| Ports | 443 in; 22 in (restricted); 8080/5432 **not** public | |

## 4. Identity & credentials

A single VM has no cloud workload identity, so secrets are **files on disk with tight
permissions**, ideally sourced from a secret manager at deploy time.

- Store secrets in a root-owned, `0600` env file (`/etc/aegis/aegis.env`) read only by
  the service; never world-readable, never in the repo.
- Prefer the **`*_FILE` indirection** so the value is a mounted file rather than a
  process-environment string:
  ```
  JWT_SECRET_FILE=/etc/aegis/secrets/jwt_secret
  AUDIT_HMAC_KEY_FILE=/etc/aegis/secrets/audit_hmac_key
  APP_ENCRYPTION_KEY_FILE=/etc/aegis/secrets/app_encryption_key
  DB_PASS_FILE=/etc/aegis/secrets/db_password
  ```
  Files under `/etc/aegis/secrets/` should be `0400 root:root` (or the `www-data`
  group with `0440`).
- Generate each: `openssl rand -hex 32`. Use **distinct** values for `JWT_SECRET`,
  `AUDIT_HMAC_KEY`, and `APP_ENCRYPTION_KEY` (key separation, NIST SC-12).
- Optional envelope encryption: keep `APP_ENCRYPTION_KEY` wrapped in a KMS/HSM and set
  `KMS_PROVIDER=vault|exec` + `APP_ENCRYPTION_KEY_CIPHERTEXT` so the plaintext never
  rests on disk (see `KMS.md`).

## 5. Environment variables

Full server env set (`/etc/aegis/aegis.env`):

| Variable | Example | Purpose |
|----------|---------|---------|
| `DB_HOST` | `db` (compose) / `127.0.0.1` (native) | Postgres host |
| `DB_PORT` | `5432` | Postgres port |
| `DB_NAME` | `aegis` | Database name |
| `DB_USER` | `aegis` | Runtime DB role (least privilege — see `database/roles.sql`) |
| `DB_PASS` / `DB_PASS_FILE` | `<secret>` | DB password (required) |
| `JWT_SECRET` / `JWT_SECRET_FILE` | `<64 hex>` | Signs auth tokens (required) |
| `AUDIT_HMAC_KEY` / `_FILE` | `<64 hex>` | Signs the audit hash chain (set distinct in prod) |
| `APP_ENCRYPTION_KEY` / `_FILE` | `<64 hex>` | Encrypts sensitive settings at rest |
| `APP_ENV` | `production` | Production error handling + HSTS |
| `APP_URL` | `https://grc.example.com` | Canonical URL (links, emails, redirect allowlist) |
| `ADMIN_EMAIL` | `admin@example.com` | Seeds first admin on install |
| `ADMIN_PASSWORD` | `<strong>` | Seeds first admin password (12+ char policy) |
| `TRUSTED_PROXY_IPS` | `127.0.0.1` | Reverse-proxy IPs trusted for `X-Forwarded-*` |
| `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASS` `SMTP_FROM` | `smtp.example.com` `587` … | Outbound mail (notifications/reports). Leave blank to disable |
| `SESSION_DRIVER` | `files` or `pg` | `pg` only needed if you later run multiple app instances |
| `HTTP_PORT` / `HTTPS_PORT` | `80` / `443` | nginx published ports (compose) |

Storage stays on the **local** driver here (uploads on a persistent volume/dir). Only
switch to S3 (configured in **Admin → Storage**, not env) if you add object storage.

## 6. Configuration references

| Setting | Where | Default | Purpose |
|---------|-------|---------|---------|
| Session / CSRF lifetime | `config/app.php` | 8h / 2h | tune per policy |
| Login lockout | `config/app.php` | 5 attempts / 5 min → 15 min lock | brute-force defense |
| Password policy | `config/app.php` | 12 chars, upper+number+special | enforced on all users |
| Upload size cap | `docker/nginx.conf` `client_max_body_size` / `.htaccess` | 55M | raise for large evidence bundles |
| Security headers / CSP | `docker/nginx.conf` + `src/Security.php` (per-request nonce CSP) | strict | do not weaken |
| Branding (logo/name/accent) | `settings` table via **Admin → Settings → Branding** | app defaults | per-org branding |

## 7. Deploy — Model A: Docker Compose (recommended)

```bash
sudo mkdir -p /opt/aegis && cd /opt/aegis
git clone <repo> . && cd aegis
sudo install -d -m 750 /etc/aegis /etc/aegis/secrets /var/lib/aegis/uploads

# Secrets
for s in jwt_secret audit_hmac_key app_encryption_key db_password; do
  openssl rand -hex 32 | sudo tee /etc/aegis/secrets/$s >/dev/null
done
sudo chmod 0440 /etc/aegis/secrets/*

# .env for compose (references the secret files; sets ADMIN_* on first boot)
sudo tee /opt/aegis/aegis/.env >/dev/null <<'EOF'
APP_ENV=production
APP_URL=https://grc.example.com
DB_NAME=aegis
DB_USER=aegis
DB_PASS=__set_from_secret__      # or bind DB_PASS_FILE in the compose override
JWT_SECRET=__set_from_secret__
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=Change-me-strong-123!
HTTP_PORT=80
HTTPS_PORT=443
EOF

docker compose up -d --build
```

For TLS at nginx, drop your cert/key at `docker/ssl/fullchain.pem` +
`docker/ssl/privkey.pem`, uncomment the 443 server block in `docker/nginx.conf`, and
restart nginx. For TLS upstream (LB/Cloudflare), keep nginx on :80 and set
`TRUSTED_PROXY_IPS` to the proxy.

## 7b. Deploy — Model B: native systemd

1. Install PHP 8.3 + extensions, Apache (`mod_php` or php-fpm), PostgreSQL 16,
   `poppler-utils`. Deploy the repo to `/var/www/aegis`.
2. Create the DB + least-privilege role:
   ```bash
   sudo -u postgres createdb aegis
   sudo -u postgres psql -d aegis -f /var/www/aegis/database/roles.sql
   sudo -u postgres psql -d aegis -c "CREATE SCHEMA IF NOT EXISTS aegis;"
   ```
3. Apply schema + migrations, then seed admin:
   ```bash
   cd /var/www/aegis
   PGOPTIONS="--search_path=aegis,public" psql -d aegis -f database/schema.sql
   for f in database/migrations/*.sql; do PGOPTIONS="--search_path=aegis,public" psql -d aegis -f "$f"; done
   sudo -E ADMIN_EMAIL=admin@example.com ADMIN_PASSWORD='Change-me-123!' php install.php
   ```
4. Point Apache's docroot at the repo (`.htaccess` handles routing) or run behind
   nginx as a reverse proxy. Ensure `AllowOverride All`.
5. Create a systemd unit for the worker and timers for the scheduled jobs:
   ```ini
   # /etc/systemd/system/aegis-worker.service
   [Service]
   User=www-data
   EnvironmentFile=/etc/aegis/aegis.env
   WorkingDirectory=/var/www/aegis
   ExecStart=/bin/sh -c 'while true; do php scripts/run_workflows.php; php scripts/dispatch_webhooks.php; sleep 60; done'
   Restart=always
   ```
   ```ini
   # /etc/systemd/system/aegis-notifications.timer  → OnCalendar=hourly → runs send_notifications.php
   # aegis-reports.timer (hourly) · aegis-metrics.timer (daily) · aegis-email.timer (*:0/5) · aegis-workflows handled by the worker loop
   ```
   `systemctl enable --now aegis-worker.service aegis-*.timer`.

## 8. Verification

```bash
# 1. Liveness
curl -fsS https://grc.example.com/healthz        # {"status":"ok",...}
# 2. Readiness (DB reachable)
curl -fsS https://grc.example.com/readyz         # {"status":"ready","checks":{"database":"ok"}}
# 3. Secrets resolved — audit key active + chain intact
docker compose exec app php scripts/verify_audit_log.php    # exit 0
docker compose exec app php scripts/verify_migrations.php   # all migrations present
# 4. Login (CSRF-protected form)
JAR=$(mktemp); B=https://grc.example.com
CSRF=$(curl -sc "$JAR" $B/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sb "$JAR" -c "$JAR" -i -X POST $B/login \
  --data-urlencode "csrf_token=$CSRF" --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASSWORD" | head -n1     # HTTP 302 = success
# 5. Upload accepted + indexed + object written (after attaching evidence in UI)
docker compose exec db psql -U aegis -d aegis -c \
 "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash FROM evidence_files ORDER BY id DESC LIMIT 1;"
docker compose exec app ls -l uploads/evidence | tail       # object on disk (local driver)
```
Confirm TLS: `curl -sI https://grc.example.com | grep -i strict-transport-security`.

## 9. Day-2 operations

- **Upgrade:** `git pull && docker compose up -d --build` (or redeploy the code and
  `systemctl restart` for native). `install.php` re-applies any new idempotent
  migrations on boot; verify with `verify_migrations.php`.
- **Scaling:** a single server scales vertically only. To add app instances behind a
  load balancer, set `SESSION_DRIVER=pg` (shared sessions) and optionally `REDIS_URL`
  (shared cache + rate-limit counters) — then move to [KUBERNETES.md](KUBERNETES.md)
  or a cloud target.
- **Backups (nightly, encrypted, off-box):**
  ```bash
  pg_dump -U aegis -Fc aegis | gpg -c > /backup/aegis-$(date +%F).dump.gpg
  tar czf - -C /var/lib/aegis uploads | gpg -c > /backup/uploads-$(date +%F).tgz.gpg
  # ship both to off-server storage; retain per your records schedule
  ```
- **Restore:** `gpg -d aegis-DATE.dump.gpg | pg_restore -U aegis -d aegis --clean`.
- **Cert rotation:** `certbot renew` (nginx model) or reimport the agency cert; reload
  nginx. Automate with the certbot deploy hook.
- **Secret rotation:** write a new value into `/etc/aegis/secrets/<name>`, restart the
  app + worker. Rotating `AUDIT_HMAC_KEY` breaks verification of pre-rotation audit
  rows — keep the old key archived to verify history.
- **Logs:** `docker compose logs` or `journalctl -u aegis-worker`; app/cron logs are
  on the `logs_data` volume / `/var/www/aegis/logs`.
- **Log retention & audit:** ship `activity_log` and app logs to your SIEM; run
  `verify_audit_log.php` on a schedule and alert on non-zero exit.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| 502 from nginx | app container down / not on `frontend_net` | `docker compose ps`; check app logs; confirm `proxy_pass http://app:8080` resolves |
| `/readyz` 503 | Postgres unreachable | check DB service, `DB_*`, `pg_hba.conf`, firewall |
| HSTS / secure-cookie missing behind LB | app doesn't trust the proxy scheme | set `TRUSTED_PROXY_IPS` to the LB IP; ensure `X-Forwarded-Proto` is passed |
| Login always fails | wrong creds / expired CSRF / lockout (5 in 5 min) | re-scrape CSRF; wait 15 min; verify `ADMIN_PASSWORD` meets policy |
| Uploads fail with permission error | `uploads/` not writable by `www-data` | `chown -R www-data /var/lib/aegis/uploads` (or fix the volume) |
| Emails never arrive | `SMTP_*` unset or blocked egress | set SMTP vars; open 587 egress; check `drain_email_queue.php` logs |
| Secrets appear in `ps`/env dumps | using inline values instead of `*_FILE` | switch to `*_FILE` mounts with `0440` perms |
| Scheduled jobs not running (reminders/reports silent) | worker/timers not enabled | `systemctl enable --now aegis-*.timer`; or confirm the compose `cron` service is up |
