# APEX — Air-Gapped / Offline Deployment

Operator guide for installing **APEX** in a disconnected (air-gapped) enclave —
no internet egress, no public registries, no hosted APIs. APEX is a stateless
PHP 8.2 + Apache container (the shipped `apex/Dockerfile`) serving a vanilla-JS
SPA and `/api/*` REST API on port **8080** as non-root `www-data`, backed by
PostgreSQL 16. Auth is CAC/PIV-simulated (bcrypt PINs + HS256 JWT).

APEX has **no external runtime dependencies**: no hosted AI API, no third-party
call-outs from the server. The only outbound reference in the app is a CDN CSS
link for Bootstrap in the SPA shell, which is optional and must be internalized
(see §6). This makes APEX well-suited to offline operation.

Related: [SINGLE_LINUX_SERVER](SINGLE_LINUX_SERVER.md) · [KUBERNETES](KUBERNETES.md) ·
[AWS](AWS.md) · [AZURE](AZURE.md)

---

## 1. Deployment architecture

Everything runs inside the enclave; artifacts arrive via a one-way transfer
(sneakernet / cross-domain solution).

| Component | Source in-enclave |
|-----------|-------------------|
| `apex` app image | Loaded from a bundled tarball into a **private registry mirror** (Harbor / registry:2). |
| `postgres:16-alpine` | Same bundle → registry mirror. |
| Runtime | Docker Compose on a hardened VM, **or** the offline Kubernetes cluster ([KUBERNETES.md](KUBERNETES.md)). |
| Secrets | Offline secret store (HashiCorp Vault in the enclave, or root-owned `chmod 600` env files) — never fetched from a cloud KMS. |
| Package/CVE feeds | Mirrored offline (see §8). |
| Optional AI (Ollama) | Self-hosted LLM replacing any hosted AI API — see §6. **Not required by APEX today.** |

---

## 2. Topology

```
  ── One-way transfer (bundle) ──▶ │ Air-gapped enclave (no egress)
                                    │
   ┌────────────────────────────────────────────────────────────┐
   │  Private registry mirror (Harbor / registry:2)             │
   │    apex:<tag>, postgres:16-alpine, (optional) ollama:<tag>  │
   └───────────────┬────────────────────────────────────────────┘
                   ▼ pull
   ┌────────────────────┐     ┌─────────────────────┐
   │ app apex :8080     │────▶│ postgres:16          │
   │ (www-data, ro-fs)  │ URL │ vol: pgdata          │
   └─────────┬──────────┘     └─────────────────────┘
             │ (optional, only if AI features are added)
             ▼
   ┌────────────────────┐   Offline secret store (Vault / sealed files)
   │ Ollama :11434      │   Offline OS/pkg mirror + CVE feed
   └────────────────────┘
```

---

## 3. Prerequisites

| Item | Detail |
|------|--------|
| Build/staging host (connected) | To assemble the offline bundle |
| Transfer mechanism | Approved cross-domain / removable media process |
| Enclave runtime | Docker Engine 24+ / Compose v2, or offline Kubernetes |
| Private registry | Harbor or `registry:2`, reachable in-enclave |
| Offline secret store | Vault (enclave) or root-owned `chmod 600` files |
| (Optional) GPU + drivers | Only if running Ollama with acceleration |

---

## 4. Identity & credentials

No cloud IAM in an air-gapped enclave. Manage identity locally:

| Secret | Storage | Notes |
|--------|---------|-------|
| `JWT_SECRET` | Enclave Vault or `chmod 600` root:root file | 32+ chars (`openssl rand -hex 32`). Rotate on personnel changes. |
| `DATABASE_URL` / DB password | Same | Prefer internal TLS; set `sslmode=require` when the DB is on a separate host. |
| Registry credentials | Enclave IdP / static | Scope pull-only for the app runtime account. |
| Seed login PINs | `schema.sql` bcrypt hashes | Rotate immediately after install: run the PIN-hash procedure and set `APEX_ALLOW_DEFAULT_PINS=0`. |

`APP_ENV=production` forces the plaintext-PIN fallback off regardless of
`APEX_ALLOW_DEFAULT_PINS`, and requires a ≥32-char `JWT_SECRET` — keep production
settings in the enclave.

---

## 5. Environment variables

| Variable | Example | Purpose |
|----------|---------|---------|
| `DATABASE_URL` | `postgresql://apex:***@db:5432/apex?sslmode=require` | PDO PgSQL connection. Point host at the in-enclave DB. |
| `JWT_SECRET` | 32+ random chars | HS256 signing key. From enclave secret store. |
| `APP_ENV` | `production` | Secure cookie, no traces, fail-closed. |
| `APEX_ALLOW_DEFAULT_PINS` | `0` | Must be `0` after first login. |
| `OLLAMA_HOST` *(only if AI added)* | `http://ollama:11434` | Points any future AI feature at the self-hosted LLM (not used by APEX today). |

No cloud partitions apply offline; AWS/Azure endpoint tables are irrelevant here.

---

## 6. Configuration references

| Item | Value | Purpose |
|------|-------|---------|
| App port | `8080` | Non-privileged container listen port. |
| Health path | `/api/health` | Liveness/readiness probe. |
| Bootstrap CSS | internalize | The SPA shell references `cdn.jsdelivr.net` for Bootstrap CSS + the CSP allows it. **In an air-gap, self-host Bootstrap** under `public/app/` and update the shell + CSP `style-src`/`font-src` to `'self'` — otherwise styling degrades (functionality still works). |
| Ollama (optional) | `http://ollama:11434` | Self-hosted LLM endpoint that would replace any hosted AI API. APEX ships **no** hosted-AI dependency, so this is only for future AI features. |

### Building the offline bundle (connected staging host)

```bash
# 1. Build/pull images
docker build -t apex:<tag> apex/
docker pull postgres:16-alpine
# optional AI:
docker pull ollama/ollama:<tag>

# 2. Save to tarballs for transfer
docker save apex:<tag>            -o apex.tar
docker save postgres:16-alpine    -o postgres16.tar
docker save ollama/ollama:<tag>   -o ollama.tar   # optional

# 3. Include: schema.sql, docker-compose.yml, this repo, self-hosted Bootstrap assets
tar czf apex-airgap-bundle.tgz apex.tar postgres16.tar ollama.tar \
        apex/schema.sql apex/docker-compose.yml apex/
```

### Loading in the enclave

```bash
docker load -i apex.tar
docker load -i postgres16.tar
# Retag + push into the private registry mirror
docker tag apex:<tag> registry.enclave.local/apex:<tag>
docker push registry.enclave.local/apex:<tag>
```

### Ollama (optional self-hosted LLM)

Only needed if AI features are later added to APEX; it replaces any hosted AI
API entirely.

```bash
docker load -i ollama.tar
docker run -d --name ollama -p 11434:11434 \
  -v ollama-models:/root/.ollama ollama/ollama:<tag>
# Pre-load a model on the connected host, transfer the models volume, then:
docker exec ollama ollama list       # confirm the model is present offline
```

GPU acceleration is optional (NVIDIA runtime / k8s device plugin); Ollama
degrades to CPU when no GPU is present. See `docs/DEPLOYMENT.md`.

---

## 7. Verification

```bash
# Health (in-enclave)
curl -s http://apex.enclave.local:8080/api/health
# → {"data":{"ok":true,"service":"apex-api","time":"..."}}

# Secrets resolved (from enclave store) + login (bcrypt verify)
TOKEN=$(curl -s -X POST http://apex.enclave.local:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"userId":"rojas","pin":"654321"}' | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
[ -n "$TOKEN" ] && echo "login OK — JWT_SECRET resolved offline"

# Write a DB row (ticket) → API→PDO→Postgres, fully offline
curl -s -X POST http://apex.enclave.local:8080/api/tickets \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"projectId":"proj_sec","title":"airgap smoke test","type":"task"}'

# Confirm persistence
docker compose exec db psql -U apex -d apex \
  -c "SELECT id,title FROM tickets ORDER BY created_at DESC LIMIT 1;"

# (If Ollama installed) confirm local inference — no egress
curl -s http://ollama:11434/api/tags
```

Verify: no outbound network calls (confirm with enclave egress monitoring) ·
`/api/health` 200 ✓ · login token (offline secret resolved) ✓ · new `tickets`
row persisted ✓ · optional Ollama answers locally ✓.

---

## 8. Day-2 operations

| Task | Procedure |
|------|-----------|
| Update bundle | Rebuild images on the connected host, `docker save`, transfer, `docker load`, retag/push to the enclave registry, roll the service. |
| Migrations | Fresh DB seeds via boot migrate; for existing DBs apply new SQL via `psql`. Ship the SQL in the update bundle. |
| Offline CVE/package feeds | Mirror OS package repos and vulnerability feeds (e.g. Grype/Trivy DBs) into the enclave; scan images before promotion. |
| Backups | `pg_dump` to enclave-approved encrypted storage; test restores per `docs/DISASTER_RECOVERY.md`. |
| Secret rotation | Rotate `JWT_SECRET`/DB password in the enclave store; restart app (invalidates JWTs). |
| Model updates (Ollama) | Pull models on the connected host, transfer the models volume, `ollama list` to confirm. |
| Logs | Ship container stdout/stderr to the enclave SIEM. |

---

## 9. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Image pull fails | Not present in enclave registry | `docker load` the tarball and push to `registry.enclave.local`. |
| Unstyled UI | Bootstrap CDN blocked (no egress) | Self-host Bootstrap under `public/app/`; update the shell + CSP to `'self'`. Functionality is unaffected. |
| App won't start; `JWT_SECRET is missing or too short` | Enclave secret not injected / <32 chars | Inject a 32+ char value from Vault/env file. |
| DB connection failed | Wrong host / TLS | Point `DATABASE_URL` at the in-enclave DB; set `sslmode=require` if cross-host. |
| Login `Invalid credentials` | Real PINs required; defaults off | Use seed PIN, then rotate; defaults disabled at `APP_ENV=production`. |
| Egress alarm during install | A component tried the internet | Ensure Bootstrap is internalized and no image references a public registry at runtime. |
| Ollama slow | CPU-only inference | Add a GPU + NVIDIA runtime, or accept CPU latency; APEX doesn't require Ollama. |
| CVE scan can't update DB | Feed not mirrored | Import the offline vulnerability DB into the enclave scanner. |
