# CITADEL — Single Linux Server Deployment

**Audience:** operators deploying the CITADEL deep-scan backend to one VM (on-prem or a cloud
instance) with an nginx TLS reverse proxy, bundled or managed Postgres, and backups. This guide
aligns with the hardened Compose stack under [`../deploy/compose/`](../deploy/compose/) and its
[runbook](../deploy/compose/README.md).

CITADEL is a **Node 20 / Express** service on **:8080**, health-checking `GET /api/health`,
running **non-root UID 10001** with a **read-only root filesystem**. It shells out to real
scanners (Semgrep, Bandit, Trivy, Syft, Grype, Gitleaks, ClamAV, …) and needs **≥ 2 GB RAM**
(ClamAV loads a ~1.4 GB signature DB).

Related: [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) · [KUBERNETES.md](KUBERNETES.md) ·
[AWS.md](AWS.md) · [AZURE.md](AZURE.md) · [AIRGAPPED.md](AIRGAPPED.md). Env:
[`../docs/ENV.md`](../docs/ENV.md).

---

## 1. Deployment architecture

The recommended pattern is **Docker Compose** on a single host (`deploy/compose/`), which runs
three services on two isolated networks:

| Service | Image | Role | Networks |
|---|---|---|---|
| `proxy` | `nginx:1.27-alpine` (pinned digest) | TLS termination (80→443), security headers, reverse proxy to the app | `frontend` (published :80/:443) |
| `citadel` | `citadel-server` (built from `citadel/server/Dockerfile`) | The API + SPA on :8080 | `frontend` + `backend` |
| `postgres` | `postgres:16-alpine` (pinned digest, SCRAM-SHA-256) | Durable state | `backend` (`internal: true`, no egress) |

Only `proxy` publishes ports; `citadel` and `postgres` are never exposed to the host network.
The `backend` network is `internal: true` — Postgres has **no outbound internet route**.

Alternatives: run the container under **systemd** with `docker run`/`podman`, or run Node
natively behind nginx. Compose is the supported turnkey path.

## 2. Topology

```
        Internet
           │  :443 (TLS 1.2+, HSTS)  /  :80 → :443 redirect
           ▼
   ┌───────────────────────┐   frontend network
   │ nginx proxy (1.27)     │   certs: /etc/nginx/certs/{fullchain,privkey}.pem
   │  proxy_pass            │   client_max_body_size 256m; read/send timeout 300s (deep scans)
   │  http://citadel_app    │
   └──────────┬─────────────┘
              │ :8080
   ┌──────────▼─────────────┐   citadel: user 10001 · read_only · cap_drop ALL
   │ citadel-server (Node)  │   no-new-privileges · tmpfs /tmp/citadel(2g), /var/lib/clamav(1g)
   │  /api/health  /api/scan│   limits cpu 2.0 / mem 2g · reserve cpu .5 / mem 1g
   └──────────┬─────────────┘
              │ backend network (internal: true — no internet)
   ┌──────────▼─────────────┐
   │ postgres:16-alpine     │   DATABASE_URL=postgres://…@postgres:5432/citadel
   │  volume: pgdata        │   PGDATA=/var/lib/postgresql/data/pgdata
   └────────────────────────┘
```

## 3. Prerequisites

| Requirement | Version / note |
|---|---|
| Linux host | 2+ vCPU, **≥ 4 GB RAM** (pod caps at 2 GB; leave host headroom), 30+ GB disk |
| Docker Engine + Compose v2 | 24+ / v2 |
| Repository checkout | The image builds from the **repo root** (copies `citadel/` + `citadel/server/`) |
| TLS certificate | `fullchain.pem` + `privkey.pem` (Let's Encrypt / internal CA) placed in `deploy/compose/certs/` |
| DNS | A/AAAA record for your hostname → the host's public IP |
| Egress (non-airgapped) | HTTPS for Trivy/Grype/ClamAV DB updates + optional OSV/Anthropic. For offline see [AIRGAPPED.md](AIRGAPPED.md) |

## 4. Identity & credentials

Single-host has no cloud IAM; use OS + secret hygiene:

- **App secrets live in a git-ignored `.env`** loaded by Compose. Never commit it. Generate
  strong values: `openssl rand -base64 48` (JWT/superadmin), `openssl rand -base64 32` (metrics).
- **First-boot admin** is seeded from `CITADEL_ADMIN_EMAIL` / `CITADEL_ADMIN_PASSWORD`
  (must-change on first login). Change it immediately.
- **Postgres auth** is SCRAM-SHA-256; `POSTGRES_PASSWORD` is **required** (the stack refuses to
  start without it).
- Restrict `.env` perms: `chmod 600 deploy/compose/.env`. Prefer a host secret store or
  `*_FILE` (Docker secrets) for hardening.
- Run Docker rootless / restrict the docker group; keep the host patched; front with a firewall
  allowing only 80/443.

## 5. Environment variables

Put these in `deploy/compose/.env`. **Required** (no defaults):

| Variable | Example | Purpose |
|---|---|---|
| `POSTGRES_PASSWORD` | `$(openssl rand -base64 32)` | Postgres superuser password (DB won't boot without it) |
| `CITADEL_JWT_SECRET` | `$(openssl rand -base64 48)` | HS256 session signing key (stable → sessions survive restarts) |
| `CITADEL_ADMIN_EMAIL` | `admin@example.com` | First-boot admin |
| `CITADEL_ADMIN_PASSWORD` | `$(openssl rand -base64 24)` | First-boot admin password (change on first login) |

Optional / tuning:

| Variable | Example | Purpose |
|---|---|---|
| `POSTGRES_USER` / `POSTGRES_DB` | `citadel` / `citadel` | DB user/name (defaults `citadel`) |
| `PGSSLMODE` | `disable` | TLS mode to bundled Postgres (`disable` on the internal net; `verify-full` for external DB) |
| `DATABASE_URL` | `postgres://citadel:…@postgres:5432/citadel?sslmode=disable` | Auto-composed; override to point at a managed DB |
| `CITADEL_SUPERADMIN_TOKEN` | `$(openssl rand -base64 48)` | Tenant-provisioning / operator routes |
| `CITADEL_METRICS_TOKEN` | `$(openssl rand -base64 32)` | Bearer token for `/metrics` (loopback-only if unset) |
| `SCAN_CONCURRENCY` | `2` | External scanners at once (`1` on small hosts) |
| `MAX_UPLOAD_BYTES` | `157286400` | Max single upload (proxy also caps body at 256 MB) |
| `ANTHROPIC_API_KEY` | `sk-ant-…` | Optional AI "Explain & fix"; omit / set `CITADEL_AIRGAP=1` offline |
| `CITADEL_FIPS` | `1` | FIPS 140 mode (PBKDF2 hashing) on a FIPS-validated OpenSSL build |

See [`../docs/ENV.md`](../docs/ENV.md) and [`../server/README.md`](../server/README.md) for the
full catalog (OIDC, tracing, notifications, upload caps, TTLs).

## 6. Configuration references (nginx proxy)

Set in `deploy/compose/nginx/citadel.conf`:

| Setting | Value | Purpose |
|---|---|---|
| Cert / key | `/etc/nginx/certs/fullchain.pem` · `privkey.pem` | Mounted read-only from `./certs` |
| TLS policy | TLS 1.2+, ECDHE-GCM/CHACHA20 ciphers | Modern transport |
| HSTS | `max-age=63072000; includeSubDomains; preload` | Force HTTPS |
| Headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, COOP/CORP | Hardening |
| `client_max_body_size` | `256m` | Bound upload size |
| `proxy_read_timeout` / `proxy_send_timeout` | `300s` | Tolerate long deep scans |
| Upstream | `proxy_pass http://citadel_app` → `citadel:8080` | Reverse proxy target |

## 7. Deploy

```bash
cd deploy/compose

# 1. Provide TLS certs (Let's Encrypt example or internal CA)
mkdir -p certs
cp /etc/letsencrypt/live/citadel.example.com/fullchain.pem certs/fullchain.pem
cp /etc/letsencrypt/live/citadel.example.com/privkey.pem   certs/privkey.pem
# Dev/self-signed:
# openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
#   -keyout certs/privkey.pem -out certs/fullchain.pem -subj "/CN=citadel.example.com"

# 2. Create .env (git-ignored) with the REQUIRED secrets above
cp .env.example .env 2>/dev/null || true
chmod 600 .env
# edit .env: POSTGRES_PASSWORD, CITADEL_JWT_SECRET, CITADEL_ADMIN_EMAIL, CITADEL_ADMIN_PASSWORD

# 3. Build (context = repo root) and start
docker compose up -d --build

# 4. Watch startup (first run pulls Trivy/Grype DBs + ClamAV signatures — slow)
docker compose logs -f citadel
```

The image is built from the repo root automatically by the compose `build.context`. Open
`https://citadel.example.com/` — the SPA is served by the backend and shows the **Deep scan**
toggle.

## 8. Verification

```bash
# 1. Health (through the TLS proxy)
curl -fsS https://citadel.example.com/api/health | jq
# expect {"ok":true,"engine":"deep",...} with a "scanners" array of available tools

# 2. Login works (JWT issued) + secrets resolved
TOKEN=$(curl -sS -X POST https://citadel.example.com/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"<CITADEL_ADMIN_PASSWORD>"}' | jq -r .token)
curl -fsS https://citadel.example.com/api/auth/me -H "Authorization: Bearer $TOKEN" | jq .email

# 3. Upload accepted + SCANNED (findings returned) — field name is "files"
zip -r /tmp/s.zip citadel/js >/dev/null
curl -sS -X POST https://citadel.example.com/api/scan -H "Authorization: Bearer $TOKEN" \
  -F "files=@/tmp/s.zip" -o /tmp/report.json
jq '{grade:.scoring.grade, findings:(.findings|length), scanners:.meta}' /tmp/report.json

# 4. Report written to Postgres (durable history)
curl -fsS https://citadel.example.com/api/scans -H "Authorization: Bearer $TOKEN" | jq 'length'
docker compose exec postgres psql -U citadel -d citadel -c "SELECT count(*) FROM citadel_scans;"
```

**Scanners present check:** confirm the `scanners` array in `/api/health` reports
`available:true` for semgrep, trivy, grype, syft, gitleaks, bandit, and clamav.

## 9. Day-2 operations

- **Scanner signature / DB updates (critical).** Findings are only as fresh as the DBs.
  ```bash
  docker compose exec citadel freshclam                 # ClamAV signatures
  docker compose exec citadel trivy --download-db-only  # Trivy vuln DB
  docker compose exec citadel grype db update           # Grype vuln DB
  ```
  Swap the `/var/lib/clamav` tmpfs for the persistent `clamav-db` named volume (as the
  `citadel/server/docker-compose.yml` variant does) so signatures survive restarts, and put the
  three commands on a host cron (e.g. nightly).
- **Upgrades.** `git pull && docker compose up -d --build` (rebuilds the pinned scanner
  toolchain). Bump scanner versions via the `ARG …_VERSION` values in
  [`../server/Dockerfile`](../server/Dockerfile).
- **DB migrations.** None to run — the server **creates its schema on boot**; reference:
  [`../database/schema.sql`](../database/schema.sql) (idempotent).
- **Backups.**
  ```bash
  # Nightly logical backup of the DB (users, sessions, scans, audit, settings, dispositions)
  docker compose exec -T postgres pg_dump -U citadel citadel | gzip > /backups/citadel-$(date +%F).sql.gz
  # Restore:
  gunzip -c /backups/citadel-2026-07-01.sql.gz | docker compose exec -T postgres psql -U citadel -d citadel
  ```
  Also back up `.env` (secrets) and the TLS certs securely.
- **Cert rotation.** Renew (certbot), replace files in `certs/`, then
  `docker compose exec proxy nginx -s reload` (or `docker compose restart proxy`).
- **Secret rotation.** Update `.env`, `docker compose up -d` to re-inject. Rotating
  `CITADEL_JWT_SECRET` invalidates existing sessions.
- **Logs.** JSON lines: `docker compose logs -f citadel`. Ship to your SIEM via
  `CITADEL_AUDIT_SINK_URL`; the audit log is hash-chained (tamper-evident) — verify with
  `GET /api/audit/verify` (admin).
- **Scaling.** A single host scales vertically only; scans are CPU/RAM-bound. For horizontal
  scale move to [KUBERNETES.md](KUBERNETES.md) or [AWS.md](AWS.md)/[AZURE.md](AZURE.md).

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `postgres` won't start | `POSTGRES_PASSWORD` unset | Set it in `.env`; it is required |
| `502`/OOM during a scan | 2 GB limit tight for concurrent scanners | Give host more RAM; `SCAN_CONCURRENCY=1`; `CITADEL_SCAN_ISOLATION=0` |
| First scans slow / ClamAV/CVE misses | Trivy/Grype/ClamAV DBs still downloading | Wait; pre-pull with the day-2 commands; persist `clamav-db` |
| Browser TLS error | Cert missing / wrong CN / self-signed | Place valid `fullchain.pem`+`privkey.pem`; reload proxy |
| `413 Request Entity Too Large` | Upload exceeds proxy `client_max_body_size` (256m) or `MAX_UPLOAD_BYTES` | Raise both for legitimately large projects |
| Sessions reset each restart | `CITADEL_JWT_SECRET` changed/unset | Set a stable value in `.env` |
| AI "Explain & fix" disabled | No `ANTHROPIC_API_KEY` or `CITADEL_AIRGAP=1` | Set the key; for offline use Ollama — see [AIRGAPPED.md](AIRGAPPED.md) |
| Data gone after `down -v` | `-v` removed the `pgdata` volume | Restore from `pg_dump`; avoid `-v` in prod |
| Postgres can't reach internet for extensions | `backend` network is `internal: true` by design | Expected; the DB needs no egress |
