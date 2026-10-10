# VERITY — Single Linux Server

Operator guide for running Verity on one hardened Linux host — the
recommended production pattern for this build until a container
orchestrator is in place.

> **Why not just run the shipped Dockerfile's `CMD` in production?** It runs
> PHP's own built-in web server (`php -S`). php.net documents that server as
> single-threaded and explicitly **not recommended for production use** — one
> slow request blocks every other request on that process. This guide fronts
> the app with **PHP-FPM + nginx** instead, which is the hardened pattern for
> a single box. (`SINGLE_LINUX_SERVER` is also a reasonable target for
> `KUBERNETES.md`/cloud deployments once you need horizontal scale.)

## 1. Deployment architecture

nginx terminates TLS and proxies to PHP-FPM over a Unix socket; PHP-FPM runs
the same application code as the Docker image (`public/index.php` front
controller) under multiple worker processes instead of `php -S`'s single
thread. PostgreSQL runs on the same host or a separate managed/self-hosted
instance reachable over the network.

## 2. Topology

```
                      Single Linux host
  ┌─────────────────────────────────────────────────────────┐
  │ [nginx :443 TLS] ──unix socket──> [PHP-FPM workers]      │
  │        │                                 │                │
  │   [firewall/ufw]                   [PostgreSQL 14+]       │
  └─────────────────────────────────────────────────────────┘
```

## 3. Prerequisites

- A Linux host (Debian/Ubuntu/RHEL family), PHP 8.2+ with `php-fpm` and the
  `pdo_pgsql` extension, nginx (or Caddy/Apache — nginx shown here).
- PostgreSQL 14+, either on the same host or reachable over the network with
  TLS (`sslmode=require` in `DATABASE_URL`).
- A TLS certificate (Let's Encrypt via certbot, or an internally issued cert).

## 4. Identity & credentials

Local email+password only (see `LOCAL_DEVELOPMENT.md` §4) — there is no
external IdP to provision for this target. Store `DATABASE_URL` (which
contains the database password) in a root-owned, mode-600 environment file
outside the web root, loaded by systemd's `EnvironmentFile=` directive —
never in a world-readable `.env` under the document root, and never in the
nginx config.

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | Disables PHP error display; blocks `database/seed.php`. |
| `DATABASE_URL` | `postgresql://verity_app:***@127.0.0.1:5432/verity?sslmode=require` | PDO Postgres DSN. Use a dedicated, least-privilege database role — not a superuser. |
| `PASSWORD_BREACH_CHECK_ENABLED` | `true` (default) | Checks new passwords against the Have I Been Pwned range API (k-anonymity; only a 5-char hash prefix leaves this server). Fails open on any network error. Requires outbound HTTPS to `api.pwnedpasswords.com`; set to `false` if egress is firewalled. |

Leave the `ENTRA_*`/`GRAPH_BASE_URL`/`ENTRA_AUTHORITY_HOST`/`AZURE_PORTAL_URL`
variables unset unless/until the GCC High connector (Phase 4) is implemented —
setting them today has no effect on sign-in.

## 6. Configuration references

- Routing + security headers live in `public/index.php` — nginx must proxy
  **every** path to PHP (including `/health`); do not try to serve static
  files for routes that don't physically exist under `public/`.
- `fastcgi_param HTTPS on;` (or forward `X-Forwarded-Proto: https`) so the
  app's HSTS header logic in `public/index.php` fires correctly.
- Example nginx `server` block:

```nginx
server {
    listen 443 ssl;
    server_name verity.example.org;
    root /var/www/verity/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }
    location ~ /\.(env|git) { deny all; }
}
```

## 7. Hardening

- Run PHP-FPM workers as a dedicated, non-root `verity` system user
  (`user = verity; group = verity;` in the FPM pool config); the deployed
  files should be owned by that user with `640`/`750` permissions, not
  world-writable.
- `ufw`/`firewalld`: allow only `80` (redirect to 443), `443`, and SSH;
  block direct access to the PostgreSQL port from outside `localhost`/the
  app's subnet.
- Keep `.env` (if used instead of systemd `EnvironmentFile=`) outside
  `public/` and mode `600` — it is excluded from the Docker image's
  `.dockerignore` for the same reason.
- Apply OS security patches on a schedule; keep PHP and PostgreSQL on
  supported minor versions.
- **WAF: ModSecurity + OWASP Core Rule Set (CRS) in front of nginx.**
  Install `libmodsecurity3` + the nginx ModSecurity connector module, load
  CRS, and start in `SecRuleEngine DetectionOnly` before switching to
  `On` — this app's own inputs include things a default CRS profile can
  false-positive on (Dynamic Fields' free-text values, the Matrix CSV
  export's query-string filters, JSON POST bodies from `iam.js`/
  `accounts.js`/`settings.js`), so a burn-in/detection period before
  blocking is not optional here. CRS complements, but does not replace,
  this app's own controls (parameterized SQL, CSRF tokens, CSP, login
  rate limiting via `Auth::isLoginThrottled()`) — it is a second layer,
  not the primary defense.

## 8. Log location

- nginx: `/var/log/nginx/access.log`, `/var/log/nginx/error.log`.
- PHP-FPM: `/var/log/php8.2-fpm.log` plus each pool's configured
  `php_admin_value[error_log]`. The app itself calls PHP's standard
  `error_log()` path (`log_errors=1`, set in `app/bootstrap.php`) — no
  separate app-level log file exists today.
- PostgreSQL: distribution default, typically `/var/log/postgresql/`.

## 9. Verification

```bash
curl -fsS https://verity.example.org/health
# {"status":"ok","app":"verity","db":true}
```
- Sign in at `/auth/login` with a seeded or real account; confirm dashboard
  KPIs render (proves PHP-FPM can reach PostgreSQL).
- `openssl s_client -connect verity.example.org:443` — confirm the expected
  certificate chain.
- Confirm `.env`/`database/schema.sql` are not web-accessible
  (`curl -I https://verity.example.org/.env` should 403/404).

## 10. Day-2 / troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| 502 from nginx | PHP-FPM not running, or wrong socket path | `systemctl status php8.2-fpm`; check `fastcgi_pass` matches the pool's `listen` directive |
| `/health` returns `"db":false` | `DATABASE_URL` not visible to the FPM pool | Confirm the systemd unit's `EnvironmentFile=`/FPM pool `env[]` actually exports it — FPM does not inherit the shell's exported vars by default |
| HSTS header missing | nginx not forwarding HTTPS signal | Add `fastcgi_param HTTPS on;` or `X-Forwarded-Proto: https` |
| Slow/unresponsive under load | Running the Dockerfile's `php -S` directly in production | Switch to this PHP-FPM + nginx pattern (see the warning at the top of this file) |
| Seed script run accidentally against prod data | `APP_ENV` not set to `production` | Always set `APP_ENV=production` on this host; `seed.php` checks this before wiping/seeding tables |
