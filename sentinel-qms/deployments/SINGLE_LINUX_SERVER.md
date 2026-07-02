# Single Linux Server Deployment — Sentinel QMS

> **Audience:** operators standing up Sentinel QMS on one VM.
> **CUI notice:** a single self-managed VM can host CUI **only** inside an
> authorized boundary (e.g. an approved GovCloud/Azure Gov tenant with the
> required NIST 800-171 / DFARS 7012 controls, incident response, and monitoring).
> For most CUI/production workloads prefer the managed cloud guides
> ([`AWS.md`](AWS.md) GovCloud, [`AZURE.md`](AZURE.md) Azure Gov) or
> [`KUBERNETES.md`](KUBERNETES.md). This guide is suited to demos, pilots, and
> small single-tenant deployments.

Sibling guides: [`LOCAL_DEVELOPMENT.md`](LOCAL_DEVELOPMENT.md) ·
[`KUBERNETES.md`](KUBERNETES.md) · [`AWS.md`](AWS.md) · [`AZURE.md`](AZURE.md) ·
[`AIRGAPPED.md`](AIRGAPPED.md)

---

## 1. Deployment architecture

One VM runs the whole stack. Two supported shapes:

- **Docker-Compose shape (recommended):** the single-service Sentinel image
  (FastAPI serves API + SPA on `:8000`) + PostgreSQL 16, fronted by host **nginx**
  terminating TLS. Uploads go to `local` disk or an external S3/Blob bucket.
- **Systemd (native) shape:** gunicorn runs the FastAPI app as a systemd unit,
  Postgres is a system package, nginx serves the built SPA and reverse-proxies
  `/api/v1` and `/health`.

The container entrypoint applies `alembic upgrade head` and seeds on boot
(gated by `AUTO_MIGRATE` / `AUTO_SEED`). In production set `ENVIRONMENT=production`
so the JWT secret guard and HSTS are active, and `ADMIN_AUTO_CREATE=false`.

---

## 2. Topology

```
   Internet
      │ 443 (TLS)
      ▼
 ┌──────────────────────────────────────────────┐   VM (Linux)
 │ nginx (host)  ── TLS termination, HSTS, WAF   │
 │   proxy_pass ──► 127.0.0.1:8000               │
 │ ┌──────────────────────────────────────────┐ │
 │ │ sentinel-qms container  :8000             │ │
 │ │  FastAPI + SPA (SERVE_FRONTEND=1)         │ │
 │ │  gunicorn + uvicorn workers               │ │
 │ └───────┬───────────────────────┬──────────┘ │
 │   5432  │                       │ uploads     │
 │         ▼                       ▼             │
 │  ┌────────────────┐   local disk  OR  S3/Blob │
 │  │ postgresql 16  │   /var/lib/sentinel/uploads│
 │  │ sentinel_qms   │   (or external bucket)     │
 │  └────────────────┘                           │
 └──────────────────────────────────────────────┘
```

Firewall: allow 443 (and 80 → redirect) inbound; keep 8000/5432 bound to
`127.0.0.1`.

---

## 3. Prerequisites

| Item | Version / spec | Notes |
|------|----------------|-------|
| OS | Ubuntu 22.04 LTS / RHEL 9 (or STIG-hardened equivalent) | FIPS-mode kernel for CUI. |
| vCPU / RAM | ≥ 2 vCPU / 4 GB (8 GB recommended) | gunicorn `WEB_CONCURRENCY` scales with cores. |
| Disk | ≥ 40 GB SSD | DB + local uploads + logs. |
| Docker Engine | 24+ (Compose v2) | for the container shape. |
| Postgres | 16 | managed externally or on-box. |
| nginx | 1.22+ | TLS + reverse proxy. |
| certbot / ACME or org CA cert | — | server certificate. |
| DNS record | `qms.example.gov` → VM IP | — |

---

## 4. Identity & credentials

Even on a single VM, **avoid static cloud keys**:

- If uploads use **S3/Blob**, attach an **instance role / managed identity** to
  the VM (EC2 instance profile in GovCloud, or a system-assigned managed identity
  on an Azure Gov VM) so boto3 / the Azure SDK obtain credentials from the
  metadata service — set no `AWS_ACCESS_KEY_ID`/connection-string secret.
- Store `JWT_SECRET`, `DATABASE_URL`, and any OIDC client secret in a
  root-owned `/etc/sentinel/sentinel.env` (`chmod 600`), sourced by systemd
  (`EnvironmentFile=`) or Compose `env_file`. Never commit it.
- Postgres: create a least-privilege app role (own the `sentinel_qms` schema
  only), require TLS (`sslmode=verify-full` in the DSN).

Generate a strong JWT secret: `openssl rand -base64 48`.

---

## 5. Environment variables

`/etc/sentinel/sentinel.env` (mode 600):

| Variable | Example | Purpose |
|----------|---------|---------|
| `ENVIRONMENT` | `production` | Hardens: JWT guard + HSTS on. |
| `LOG_LEVEL` | `INFO` | App/gunicorn log level. |
| `DATABASE_URL` | `postgresql+psycopg://sentinel:***@127.0.0.1:5432/sentinel_qms?sslmode=verify-full` | DB DSN. |
| `DB_SCHEMA` | `sentinel_qms` | Dedicated schema. |
| `JWT_SECRET` | *(from `openssl rand`)* | HS256 secret (≥ 32 chars). Refuses to boot if weak in production. |
| `WEB_CONCURRENCY` | `4` | gunicorn workers (≈ cores). |
| `CORS_ORIGINS` | `https://qms.example.gov` | Same-origin single-service can leave default. |
| `TRUST_PROXY_HEADERS` | `true` | Trust `X-Forwarded-For` from the host nginx. |
| `TRUSTED_PROXY_COUNT` | `1` | Number of proxies in front (host nginx = 1). |
| `APP_BASE_URL` | `https://qms.example.gov` | Deep links in notifications/digests. |
| `AUTO_MIGRATE` | `1` | Apply migrations on boot (or run as a separate step — §8). |
| `AUTO_SEED` | `1` | Seed roles/reference data (idempotent). |
| `ADMIN_AUTO_CREATE` | `false` | **Keep false in production**; create the first admin explicitly. |
| `RUN_SCHEDULER` | `true` | In-process SLA sweep + report digest. |

### Storage — local OR external bucket

| Variable | Example (local) | Example (S3 GovCloud) | Purpose |
|----------|-----------------|-----------------------|---------|
| `STORAGE_BACKEND` | `local` | `s3` | Upload backend. |
| `LOCAL_STORAGE_DIR` | `/var/lib/sentinel/uploads` | — | On-disk upload path (must persist + be backed up). |
| `S3_BUCKET` | — | `sentinel-qms-prod-uploads` | Bucket. |
| `S3_REGION` | — | `us-gov-west-1` | Region (GovCloud partition `aws-us-gov`). |

For Azure Blob use `STORAGE_BACKEND=azure_blob` +
`AZURE_STORAGE_CONNECTION_STRING` + `AZURE_STORAGE_CONTAINER=sentinel-qms`.

---

## 6. Configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `MAX_UPLOAD_BYTES` | `52428800` | Upload cap (50 MB). |
| `ACCESS_TOKEN_EXPIRE_MINUTES` | `30` | Access-token TTL. |
| `LOGIN_MAX_FAILURES` / `LOGIN_FAILURE_WINDOW_MINUTES` | `10` / `15` | Login brute-force throttle. |
| `RATE_LIMIT_PER_MINUTE` | `300` | Per-principal request budget. |
| `REDIS_URL` | *(blank)* | Set for cross-worker rate limiting if scaling workers. |
| `GUNICORN_TIMEOUT` | `60` | Worker timeout (seconds). |
| OIDC/SAML/CAC vars | *(blank)* | Federated SSO / CAC-PIV (needs `TRUST_PROXY_HEADERS` + nginx mTLS for CAC). |

### nginx reverse proxy (TLS + HSTS)

```nginx
server {
    listen 443 ssl http2;
    server_name qms.example.gov;

    ssl_certificate     /etc/sentinel/tls/fullchain.pem;
    ssl_certificate_key /etc/sentinel/tls/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    client_max_body_size 55m;                 # ≥ MAX_UPLOAD_BYTES

    location / {
        proxy_pass         http://127.0.0.1:8000;
        proxy_set_header   Host $host;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
server { listen 80; server_name qms.example.gov; return 301 https://$host$request_uri; }
```

### Docker-Compose unit (single-service image + Postgres)

```yaml
# /opt/sentinel/compose.yaml
services:
  db:
    image: postgres:16-alpine
    environment: { POSTGRES_USER: sentinel, POSTGRES_DB: sentinel_qms }
    env_file: [/etc/sentinel/db.env]   # POSTGRES_PASSWORD
    volumes: [pgdata:/var/lib/postgresql/data]
    restart: unless-stopped
  app:
    image: ghcr.io/<org>/sentinel-qms:1.0.0   # or your registry
    env_file: [/etc/sentinel/sentinel.env]
    depends_on: [db]
    ports: ["127.0.0.1:8000:8000"]
    volumes: ["/var/lib/sentinel/uploads:/app/var/uploads"]
    restart: unless-stopped
volumes: { pgdata: {} }
```

### systemd (native gunicorn) unit — alternative to Compose

```ini
# /etc/systemd/system/sentinel-qms.service
[Service]
User=sentinel
WorkingDirectory=/opt/sentinel/backend
EnvironmentFile=/etc/sentinel/sentinel.env
ExecStartPre=/opt/sentinel/venv/bin/alembic upgrade head
ExecStart=/opt/sentinel/venv/bin/gunicorn app.main:app -c gunicorn.conf.py
Restart=always
[Install]
WantedBy=multi-user.target
```

---

## 7. Verification

```bash
# 7.1 Health (through nginx)
curl -fsS https://qms.example.gov/health           # {"status":"ok"} 200

# 7.2 Login (secrets resolved) — create the first admin first:
docker compose -f /opt/sentinel/compose.yaml exec app python -m app.reset_admin
TOKEN=$(curl -fsS -X POST https://qms.example.gov/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin@your-org.gov","password":"<pw>"}' \
  | python3 -c 'import sys,json;print(json.load(sys.stdin)["access_token"])')

# 7.3 Upload accepted + scanned + object written
printf '%%PDF-1.4\n%%EOF\n' > /tmp/t.pdf
curl -fsS -X POST https://qms.example.gov/api/v1/attachments \
  -H "Authorization: Bearer $TOKEN" \
  -F entity_type=document -F entity_id=1 \
  -F 'file=@/tmp/t.pdf;type=application/pdf'        # 201, stored_key=<uuid>.pdf
```

Confirm the DB rows (attachment + immutable audit trail):

```bash
docker compose -f /opt/sentinel/compose.yaml exec db \
  psql -U sentinel -d sentinel_qms -c \
  "SET search_path TO sentinel_qms; \
   SELECT id, stored_key, storage_backend FROM attachments ORDER BY id DESC LIMIT 1; \
   SELECT action, actor_email, created_at FROM audit_logs WHERE action='upload' ORDER BY id DESC LIMIT 1;"
```

Confirm the object on disk (local) or in the bucket (S3):

```bash
ls -l /var/lib/sentinel/uploads              # local backend
# or:  aws s3 ls s3://sentinel-qms-prod-uploads/ --region us-gov-west-1
```

---

## 8. Day-2 operations

| Task | How |
|------|-----|
| Upgrade app | Pull new image tag, `docker compose up -d app` (entrypoint runs migrations); or `git pull` + `pip install` + restart unit. |
| Zero-surprise migrations | Set `AUTO_MIGRATE=0`, run `alembic upgrade head` as a one-off before flipping traffic. |
| Scale throughput | Raise `WEB_CONCURRENCY`; if you add workers, set `REDIS_URL` for shared rate limiting. |
| DB backup | Cron `pg_dump -Fc sentinel_qms > /backups/qms_$(date +%F).dump`; encrypt at rest; off-box copy. |
| Uploads backup | Back up `LOCAL_STORAGE_DIR` (or rely on bucket versioning if using S3/Blob). |
| Restore | `pg_restore -d sentinel_qms qms_YYYY-MM-DD.dump` then restore uploads dir. |
| TLS cert rotation | Renew via certbot/ACME or org CA; `nginx -s reload`. |
| Secret rotation | Rotate `JWT_SECRET` (invalidates live access tokens), DB password, OIDC secret in `/etc/sentinel/*.env`; restart app. |
| Logs | `journalctl -u sentinel-qms` (systemd) or `docker compose logs -f app`; ship to your SIEM. |
| Reset admin | `python -m app.reset_admin` (native) / `... exec app python -m app.reset_admin`. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| App container restart-loops, `MIGRATION FAILED` | DB unreachable / wrong DSN / TLS | Verify `DATABASE_URL`, `sslmode`, and DB reachability from the VM. |
| `refusing to start ... insecure default` | Weak/short `JWT_SECRET` with `ENVIRONMENT=production` | Set a ≥ 32-char secret (`openssl rand -base64 48`). |
| 502 from nginx | App not listening on `127.0.0.1:8000` | Check the container/unit is up; confirm `proxy_pass` port. |
| Uploads 413 | `client_max_body_size` < `MAX_UPLOAD_BYTES` | Raise nginx `client_max_body_size` to ≥ 55m. |
| Login always 401 after fresh deploy | No admin created | Run `python -m app.reset_admin`; keep `ADMIN_AUTO_CREATE=false` in prod. |
| Correct IP not logged / rate limit bypass | Proxy headers untrusted | Set `TRUST_PROXY_HEADERS=true` and `TRUSTED_PROXY_COUNT` to the real hop count. |
| Uploads lost after redeploy | Local dir not a persistent volume | Mount `/var/lib/sentinel/uploads` as a host volume, or use S3/Blob. |
| CAC/PIV login fails | mTLS not forwarded | nginx must terminate mTLS and forward `X-SSL-Client-*`; set `CLIENT_CERT_PROXY_AUTH=true` + `TRUST_PROXY_HEADERS=true`. |
