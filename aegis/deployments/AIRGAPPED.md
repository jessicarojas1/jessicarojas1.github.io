# AEGIS GRC — Air-Gapped / Offline Deployment

Audience: operators installing AEGIS in a **disconnected enclave** (no outbound
internet) — classified networks, IL5/IL6, isolated OT/ICS environments, or any
boundary where egress is denied. The core app is fully self-contained (no Composer,
no CDN, no runtime package pulls). The only feature that reaches the internet is the
optional **AI Advisor** (hosted Claude/OpenAI); this guide replaces it with a
**self-hosted Ollama** model or disables it.

> Sibling guides: [KUBERNETES.md](KUBERNETES.md) · [SINGLE_LINUX_SERVER.md](SINGLE_LINUX_SERVER.md) ·
> [AWS.md](AWS.md) · [AZURE.md](AZURE.md) · [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md)

---

## 1. Deployment architecture

Everything runs inside the enclave; nothing egresses. Images, migrations, and secrets
are carried in on approved media and served from an in-enclave registry/mirror.

| Component | Air-gapped provisioning |
|-----------|-------------------------|
| `aegis` app (PHP 8.3/Apache :8080) | image built outside, exported as a tarball, imported to the internal registry |
| PostgreSQL 16 | `postgres:16-alpine` image mirrored internally; schema + migrations applied from the bundle |
| nginx / ingress | mirrored `nginx` image; internal CA certs |
| Cron worker | same `aegis` image; scheduled scripts run via systemd timers / K8s CronJobs |
| **Ollama** (optional) | self-hosted LLM inference container, replaces the hosted AI Advisor endpoint |
| Secret store | offline: file-based `*_FILE` mounts, sealed secrets, or an in-enclave Vault/HSM |

**No-internet facts about AEGIS that make this feasible:**
- No Composer / package manager at runtime — all PHP is vendored in the repo.
- Front-end assets are served locally from `public/` (no CDN).
- The DB installer (`docker/initdb.sh` / `install.php`) applies bundled SQL only.
- The **only** outbound calls are: (a) the AI Advisor to `api.anthropic.com` /
  `api.openai.com` (hardcoded in `src/AIAdvisor.php`), and (b) outbound SMTP and
  webhooks you explicitly configure. All are optional and must be pointed at
  in-enclave endpoints or disabled.

## 2. Topology

```
   Approved transfer media (one-way / reviewed)
        │  aegis.tar, postgres.tar, nginx.tar, ollama.tar, model blobs, db bundle, CVE feed
        ▼
 ┌──────────────────────────────────────────────────────────────────────┐
 │  ENCLAVE (no egress)                                                  │
 │                                                                       │
 │   Internal registry (Harbor/registry:2)  ◄── docker load / skopeo copy│
 │        │ pull                                                         │
 │   ┌────▼─────┐   ┌─────────────┐   ┌──────────────────────────────┐   │
 │   │ aegis app│──►│ PostgreSQL16│   │ Ollama (LLM inference)       │   │
 │   │ :8080    │   │ schema=aegis│◄──│ /v1/chat/completions (OpenAI │   │
 │   └────┬─────┘   └─────────────┘   │  compatible) — CPU or GPU    │   │
 │   ingress/nginx (internal CA TLS)  └──────────────────────────────┘   │
 │   secrets: *_FILE mounts / sealed secrets / in-enclave Vault          │
 │   updates: offline bundles applied on a schedule; CVE feed mirrored   │
 └──────────────────────────────────────────────────────────────────────┘
```

## 3. Prerequisites

- A **connected build/staging host** (outside the enclave) to assemble the bundle.
- An **in-enclave container registry** (Harbor, `registry:2`, or `podman`/`docker load`
  on each node) and orchestrator (Docker Compose, systemd, or Kubernetes per
  [KUBERNETES.md](KUBERNETES.md) with the hardened image).
- Approved **transfer media** + your data-transfer review process.
- Internal **CA / PKI** for TLS (no public ACM/Let's Encrypt).
- (Optional AI) A host for **Ollama**; GPU strongly recommended for latency (see §7).
- An **offline CVE / package feed** mirror (e.g. Trivy DB, Grype DB, distro repos) for
  ongoing image scanning without egress.

## 4. Identity & credentials (offline)

No cloud IAM inside the enclave. Use file-based secrets with the `*_FILE` convention
that `Secrets::hydrate()` supports:

```
JWT_SECRET_FILE=/run/secrets/aegis/jwt_secret
AUDIT_HMAC_KEY_FILE=/run/secrets/aegis/audit_hmac_key
APP_ENCRYPTION_KEY_FILE=/run/secrets/aegis/app_encryption_key
DB_PASS_FILE=/run/secrets/aegis/db_password
```

- Generate secrets **inside** the enclave (`openssl rand -hex 32`) so they never
  transit external systems. Use distinct values for JWT / audit / encryption keys.
- Options: Kubernetes `Secret` + Sealed Secrets/SOPS (key held in-enclave); an
  in-enclave HashiCorp Vault with `KMS_PROVIDER=vault` for envelope-encrypting
  `APP_ENCRYPTION_KEY`; or plain `0400` files on a hardened host.
- End-user auth: local accounts + TOTP MFA (`src/TOTP.php`) work fully offline. If you
  federate, point `src/SSO.php` at an **in-enclave** IdP (ADFS/Keycloak) — never an
  internet OIDC endpoint.

## 5. Build the offline bundle (connected host)

```bash
# App image (default or hardened)
docker build -f docker/Dockerfile.hardened -t aegis:OFFLINE_TAG .
docker save aegis:OFFLINE_TAG            -o aegis.tar
docker pull  postgres:16-alpine && docker save postgres:16-alpine -o postgres.tar
docker pull  nginx:1.27-alpine   && docker save nginx:1.27-alpine  -o nginx.tar
docker pull  ollama/ollama:latest && docker save ollama/ollama:latest -o ollama.tar   # optional AI

# Ollama model blob (optional) — pull once on the connected host, copy its store
ollama pull llama3.1:8b        # or a model your ATO permits
tar czf ollama-models.tgz -C ~/.ollama models

# Database bundle: the whole database/ tree is already self-contained
tar czf aegis-db-bundle.tgz database/

# Offline scanner DBs (examples)
trivy image --download-db-only && tar czf trivy-db.tgz ~/.cache/trivy
```

Transfer `*.tar`, `*.tgz`, and the repo to the enclave via approved media.

## 6. Install inside the enclave

```bash
# Load images into the internal registry / local daemon
for t in aegis postgres nginx ollama; do docker load -i $t.tar; done
# (or: skopeo copy docker-archive:aegis.tar docker://registry.enclave/aegis:OFFLINE_TAG)

# Provide secrets as files
sudo install -d -m 750 /run/secrets/aegis
for s in jwt_secret audit_hmac_key app_encryption_key db_password; do
  openssl rand -hex 32 | sudo tee /run/secrets/aegis/$s >/dev/null; done
sudo chmod 0440 /run/secrets/aegis/*

# Bring up app + DB (compose or K8s). First boot applies schema + all migrations via
# docker/initdb.sh; or run install.php as a one-shot migration task with the DB owner
# role and ADMIN_EMAIL / ADMIN_PASSWORD set. install.php is idempotent.
docker compose up -d
```

Point image references at your internal registry (e.g. `registry.enclave/aegis`,
`registry.enclave/postgres:16-alpine`) and pin by `@sha256`. Storage stays on the
**local** driver (uploads on a persistent volume) unless you run in-enclave S3-compatible
object storage (MinIO) — in which case set the S3 settings in **Admin → Storage**.

## 7. AI Advisor: self-hosted Ollama (or disable)

`src/AIAdvisor.php` calls **hardcoded** hosted endpoints (`https://api.anthropic.com/v1/messages`
and `https://api.openai.com/v1/chat/completions`). In an air-gapped enclave these are
unreachable, so choose one of:

**Option A — Disable the AI Advisor (simplest, fully supported).**
Set the `ai_enabled` setting off (Admin → AI, or a settings row `ai_enabled=off`).
`AIAdvisor::globallyEnabled()` returns false and all AI features no-op cleanly — the
rest of AEGIS is unaffected. The human-review disclaimer already states AI output is
advisory only, so nothing depends on it.

**Option B — Redirect OpenAI-compatible calls to in-enclave Ollama.**
Ollama exposes an **OpenAI-compatible** API at `http://<ollama-host>:11434/v1/chat/completions`.
Because the endpoint URL is not yet a setting, use one of:

1. **Egress-proxy / DNS override (no code change):** on the app hosts, resolve
   `api.openai.com` to the Ollama host and terminate TLS with an internal CA the app
   trusts, mapping `/v1/chat/completions` to Ollama. Set the AI provider to `openai`
   and the model name to a pulled Ollama model in the `settings` table; put any
   placeholder value in the api key (Ollama ignores it). This keeps the code untouched.
2. **Minimal code change (cleaner):** change the `curl_init(...)` URL in
   `AIAdvisor::callOpenAI()` to your Ollama endpoint and the model in the payload to a
   local model (e.g. `llama3.1:8b`). Rebuild the offline image. Track this as a local
   patch in your OPEN_ITEMS.

Bring up Ollama and load the model:
```bash
docker load -i ollama.tar
docker run -d --name ollama -p 11434:11434 \
  -v ollama:/root/.ollama ollama/ollama:latest
tar xzf ollama-models.tgz -C /var/lib/ollama    # seed the pre-pulled model store
docker exec ollama ollama list                  # confirm the model is present
curl -s http://localhost:11434/v1/chat/completions \
  -d '{"model":"llama3.1:8b","messages":[{"role":"user","content":"ping"}]}' | head
```

**GPU acceleration (optional).** For acceptable latency on the 3–5 sentence narratives
and 10-item gap lists AEGIS requests, run Ollama on a GPU: install the NVIDIA driver +
`nvidia-container-toolkit` and run with `--gpus all`; on Kubernetes use the NVIDIA
device plugin and request `nvidia.com/gpu: 1`. Ollama **degrades to CPU** automatically
if no GPU is present (slower, still functional). The AEGIS AI paths tolerate slow/failed
inference — on error they log and return empty, so a busy or unavailable model never
blocks the UI.

## 8. Environment / configuration references

| Variable | Example | Purpose |
|----------|---------|---------|
| `APP_ENV` | `production` | prod hardening |
| `APP_URL` | `https://grc.enclave.local` | canonical URL (internal DNS) |
| `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER` | `db`/`5432`/`aegis`/`aegis_app` | connection |
| `DB_PASS_FILE`,`JWT_SECRET_FILE`,`AUDIT_HMAC_KEY_FILE`,`APP_ENCRYPTION_KEY_FILE` | `/run/secrets/aegis/*` | offline secrets |
| `ADMIN_EMAIL`/`ADMIN_PASSWORD` | migration task only | first admin seed |
| `TRUSTED_PROXY_IPS` | ingress IP | trust `X-Forwarded-*` |
| `SMTP_*` | in-enclave relay | offline mail (or leave blank to disable) |
| `KMS_PROVIDER` | `vault` (optional) | envelope-encrypt `APP_ENCRYPTION_KEY` via in-enclave Vault |

Settings-table keys (Admin UI): `ai_enabled` (off, or on with Ollama), `ai_provider` +
`ai_api_key`, and — if using MinIO — `storage_driver=s3` + `s3_endpoint` pointing at the
internal object store (the SSRF guard permits private hosts but blocks loopback/metadata).

## 9. Verification

```bash
B=https://grc.enclave.local
curl -fsSk $B/healthz          # {"status":"ok",...}     (liveness)
curl -fsSk $B/readyz           # {"status":"ready",...}  (DB reachable)
docker compose exec app php scripts/verify_migrations.php   # all bundled migrations applied
docker compose exec app php scripts/verify_audit_log.php    # exit 0 = audit chain intact (secrets resolved)
# Login (CSRF form)
JAR=$(mktemp); CSRF=$(curl -sck "$JAR" $B/login | grep -oP 'name="csrf_token" value="\K[^"]+')
curl -sbk "$JAR" -i -X POST $B/login --data-urlencode "csrf_token=$CSRF" \
  --data-urlencode "email=$ADMIN_EMAIL" --data-urlencode "password=$ADMIN_PASSWORD" | head -n1  # 302
# Upload accepted + indexed + object written (attach evidence in UI, then:)
docker compose exec db psql -U aegis -d aegis -c \
  "SET search_path=aegis; SELECT id, original_name, stored_name, file_hash FROM evidence_files ORDER BY id DESC LIMIT 1;"
docker compose exec app ls -l uploads/evidence | tail    # object on disk (local driver)
# AI (if enabled): confirm inference is logged and stays in-enclave
docker compose exec db psql -U aegis -d aegis -c \
  "SET search_path=aegis; SELECT provider, model, success FROM ai_inference_log ORDER BY id DESC LIMIT 1;"
# and confirm NO egress: outbound to api.anthropic.com / api.openai.com must fail/blocked
```
Also verify egress is truly denied (host firewall / K8s NetworkPolicy default-deny):
attempts to reach `api.anthropic.com`/`api.openai.com` should time out or be blocked.

## 10. Day-2 operations (offline)

- **Update bundles:** assemble a new `aegis.tar` + any new migration SQL on the
  connected host, transfer via approved media, `docker load`, run the migration task,
  roll the deployment. Keep a signed manifest (sha256) of every bundle for the ATO
  audit trail.
- **CVE / image scanning without egress:** mirror the Trivy/Grype DB in each bundle;
  scan `aegis.tar` and base images on the connected host **and** re-scan in-enclave
  against the mirrored feed. Track findings in AEGIS itself.
- **Backups:** `pg_dump` (encrypted) + uploads archive to in-enclave backup storage;
  safeguard `APP_ENCRYPTION_KEY`/`AUDIT_HMAC_KEY` offline — loss makes encrypted
  settings unrecoverable and breaks historical audit verification.
- **Secret rotation:** regenerate the `*_FILE` values in-enclave and restart; retain old
  `AUDIT_HMAC_KEY` to verify pre-rotation audit rows.
- **Model updates (Ollama):** pull the new model on the connected host, carry the model
  blob in, `ollama create`/seed the store, update the model name in settings.
- **Audit assurance:** schedule `verify_audit_log.php`; ship `activity_log` to an
  in-enclave SIEM.

## 11. Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Image pull fails in enclave | not loaded / wrong internal ref | `docker load -i *.tar`; repoint image to `registry.enclave/...` and pin `@sha256` |
| AI features hang or error | app trying to reach blocked internet endpoints | disable via `ai_enabled=off`, or redirect to Ollama (§7) |
| Ollama very slow | CPU-only inference | attach a GPU (`--gpus all` / device plugin); use a smaller model |
| TLS errors on internal URLs | app doesn't trust internal CA | add the enclave CA to the image trust store; use `-k` only for testing |
| Migrations missing on fresh DB | volume predates a bundle update | reinitialize (dev) or apply new migration SQL from the bundle manually |
| Emails/webhooks fail | pointed at internet endpoints | repoint SMTP/webhooks to in-enclave relays or disable |
| Secret files empty | `*_FILE` path/permission wrong | confirm `/run/secrets/aegis/*` mounted `0440`, readable by `www-data` |
| Egress test unexpectedly succeeds | firewall/NetworkPolicy gap | tighten default-deny egress; only allow in-enclave DB/SMTP/Ollama |
