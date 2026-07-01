# Single Linux Server — Compliance Copilot

Operator guide for running **Compliance Copilot** on one Linux VM (Ubuntu 22.04+/RHEL 9+)
behind nginx + TLS, using either **systemd + native Node** or **docker-compose**. Compliance
Copilot is a **Next.js 14/16** app backed by **Supabase** (PostgreSQL + Storage + Auth) with an
optional **Anthropic Claude** relay.

> Cross-links: [LOCAL_DEVELOPMENT.md](./LOCAL_DEVELOPMENT.md) · [KUBERNETES.md](./KUBERNETES.md)
> · [AZURE.md](./AZURE.md) · [AWS.md](./AWS.md) · [AIRGAPPED.md](./AIRGAPPED.md)

---

## 1. Deployment architecture

A single VM runs the compiled Next.js server (`next start`) as a long-lived process (systemd
unit or Docker container) listening on `127.0.0.1:3000`. **nginx** terminates TLS on
443 and reverse-proxies to the app. **Supabase** (hosted SaaS or self-hosted on the same/another
host) provides Postgres, Storage (`evidence-files` bucket), and Auth. The **Anthropic API** is
reached only from the server-side AI relay.

| Component | Runs as | Port |
|---|---|---|
| nginx (TLS) | systemd service | 80/443 |
| Compliance Copilot | `next start` via systemd **or** docker-compose | 3000 (loopback) |
| Supabase | External SaaS **or** self-hosted (`supabase`/Docker) | 5432 / 8000 |
| AI relay egress | outbound HTTPS to `api.anthropic.com` | 443 |

---

## 2. Topology

```
                          Internet
                             │  443 (TLS)
                             ▼
                     ┌──────────────┐
                     │    nginx     │  (Let's Encrypt / org CA)
                     └──────┬───────┘
                            │ proxy_pass http://127.0.0.1:3000
                            ▼
                   ┌───────────────────┐         outbound 443
                   │  next start :3000 │ ───────────────────────►  api.anthropic.com
                   │  (systemd/docker) │
                   └─────────┬─────────┘
                             │ HTTPS (anon + service role)
                             ▼
                 ┌──────────────────────────────┐
                 │  Supabase (SaaS or self-host) │
                 │  Postgres + Storage + Auth    │
                 └──────────────────────────────┘
```

---

## 3. Prerequisites

| Item | Version / detail |
|---|---|
| VM | 2 vCPU / 2 GB RAM minimum (4 GB recommended for build) |
| OS | Ubuntu 22.04+ / RHEL 9+ / Debian 12+ |
| Node.js | 20 LTS+ (for native/systemd path) |
| Docker + Compose | latest (for container path) |
| nginx | 1.18+ |
| certbot | for Let's Encrypt TLS (or bring an org cert) |
| Supabase | hosted project **or** self-hosted stack reachable from the VM |
| DNS | A/AAAA record → VM public IP |
| Firewall | 80/443 inbound; 443 outbound to Supabase + Anthropic |

---

## 4. Identity & credentials

No cloud IAM on a bare VM — credentials are static and stored in a root-owned env file with
`chmod 600`. Prefer a secrets file over exporting in the unit.

```bash
sudo install -m 600 /dev/null /etc/compliance-copilot/env
sudoedit /etc/compliance-copilot/env      # paste the variables from §5
```

Least-privilege guidance:
- Run the app as a dedicated non-root user (`ccopilot`).
- Only the app process reads `/etc/compliance-copilot/env`.
- The **service role key** stays server-side; never place it in any `NEXT_PUBLIC_*` var.
- Set `AI_PROXY_TOKEN`, `APP_SESSION_SECRET`, `APP_AUTH_*`, and `BRANDING_ADMIN_TOKEN` for a
  shared deployment (login gate + relay auth + branding write protection).

---

## 5. Environment variables

`/etc/compliance-copilot/env`:

| Variable | Example | Purpose |
|---|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | `https://abcd.supabase.co` | Supabase project URL |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | `eyJhbGci...` | Public anon key (RLS-scoped) |
| `SUPABASE_SERVICE_ROLE_KEY` | `eyJhbGci...` | Service role key (server-only) |
| `ANTHROPIC_API_KEY` | `sk-ant-...` | AI Copilot upstream key (server-only) |
| `AI_PROXY_TOKEN` | `<hex 32>` | **Required in prod** to gate `/api/ai/generate` for token callers |
| `APP_SESSION_SECRET` | `<48+ random>` | HMAC signs `cc_session`; ≥16 chars |
| `APP_AUTH_USERNAME` | `issoadmin` | Login username |
| `APP_AUTH_PASSWORD` | `<strong>` | Login password |
| `NEXT_PUBLIC_EVIDENCE_BUCKET` | `evidence-files` | Storage bucket name |
| `BRANDING_ADMIN_TOKEN` | `<hex 32>` | Gates branding write on shared deploys |
| `NODE_ENV` | `production` | Enables secure cookies + fail-closed relay |
| `PORT` | `3000` | App listen port (loopback) |

---

## 6. Configuration references

| Variable | Example | Purpose |
|---|---|---|
| `HOSTNAME` | `127.0.0.1` | Bind loopback so only nginx reaches the app (`next start -H 127.0.0.1`) |
| nginx `proxy_pass` | `http://127.0.0.1:3000` | Reverse proxy target |
| nginx `X-Forwarded-For` | set | Feeds the app's per-IP rate limiter |
| `next.config.js` `remotePatterns` | `*.supabase.co` | Allows Supabase-hosted images |
| Session TTL | 8h (code constant) | Cookie lifetime |

---

## 7. Verification

### Build & install (native/systemd path)

```bash
sudo useradd --system --home /opt/compliance-copilot --shell /usr/sbin/nologin ccopilot
sudo -u ccopilot git clone <repo> /opt/compliance-copilot
cd /opt/compliance-copilot
sudo -u ccopilot npm ci
sudo -u ccopilot npm run build
```

systemd unit `/etc/systemd/system/compliance-copilot.service`:

```ini
[Unit]
Description=Compliance Copilot
After=network-online.target
Wants=network-online.target

[Service]
User=ccopilot
WorkingDirectory=/opt/compliance-copilot
EnvironmentFile=/etc/compliance-copilot/env
Environment=NODE_ENV=production PORT=3000 HOSTNAME=127.0.0.1
ExecStart=/usr/bin/npm run start
Restart=on-failure
NoNewPrivileges=true
ProtectSystem=strict
ReadWritePaths=/opt/compliance-copilot/.next

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now compliance-copilot
```

### Database + bucket (one-time)

```bash
psql "$SUPABASE_DB_URL" -f supabase/schema.sql   # idempotent
# Create bucket 'evidence-files' in Supabase Studio → Storage
```

### Checks

```bash
# Health / homepage (dashboard is the health surface)
curl -sI https://grc.example.com/ | head -1                 # HTTP/2 200

# Login works
curl -s https://grc.example.com/api/auth/login              # {"configured":true,...}
curl -s -X POST https://grc.example.com/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"issoadmin","password":"<pw>"}'            # {"ok":true}

# Secrets resolved (AI relay authorized via token)
curl -s -X POST https://grc.example.com/api/ai/generate \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $AI_PROXY_TOKEN" \
  -d '{"prompt":"Identify gaps for 3.5.3"}'                  # {"text":"..."}

# DB row present
psql "$SUPABASE_DB_URL" -c "select count(*) from controls;"

# Storage object written: upload via Evidence page, then confirm
psql "$SUPABASE_DB_URL" -c "select file_name,file_url from evidence order by created_at desc limit 1;"
```

---

## 8. Day-2 operations

| Task | How |
|---|---|
| Upgrade app | `git pull && npm ci && npm run build && sudo systemctl restart compliance-copilot` |
| DB migration | Re-run `supabase/schema.sql` (idempotent) or apply new migration SQL via `psql` |
| Scale up | Vertical only on one VM; move to [KUBERNETES.md](./KUBERNETES.md) for horizontal |
| Backups | Supabase automated backups (SaaS) or `pg_dump` on self-hosted; snapshot storage bucket |
| TLS cert rotation | `certbot renew` (auto-timer); reload nginx |
| Secret rotation | Edit `/etc/compliance-copilot/env`, restart service; rotating `APP_SESSION_SECRET` logs everyone out |
| Logs | `journalctl -u compliance-copilot -f`; nginx `/var/log/nginx/` |

### docker-compose alternative

If you prefer containers, run the image (built by the repo `Dockerfile`, added separately) with
the same env file:

```yaml
services:
  app:
    image: compliance-copilot:latest
    env_file: /etc/compliance-copilot/env
    environment: { NODE_ENV: production, PORT: "3000", HOSTNAME: 0.0.0.0 }
    ports: ["127.0.0.1:3000:3000"]
    restart: unless-stopped
```

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| 502 from nginx | App not listening / crashed | `systemctl status compliance-copilot`; check `journalctl` |
| App exits on start | Missing required env | Verify `NEXT_PUBLIC_SUPABASE_*` present in env file |
| `/api/ai/generate` 503 | Prod + no `AI_PROXY_TOKEN` + no session | Set `AI_PROXY_TOKEN` or use session login |
| Rate limiter too loose behind proxy | `X-Forwarded-For` not passed | Add `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` |
| Cookie not set / login loops | `NODE_ENV` not `production` over HTTPS, or clock skew | Ensure `NODE_ENV=production` (secure cookie) and correct system time |
| Evidence upload fails | Bucket missing / wrong name | Create `evidence-files`; align env var |
| TLS renewal failed | port 80 blocked | Open 80 for HTTP-01 or use DNS-01 |
